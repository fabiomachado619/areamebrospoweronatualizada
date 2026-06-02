<?php

namespace App\Services;

use App\Models\ConversionPixelIntegration;
use App\Models\Product;
use App\Support\AffiliateAttribution;

class ConversionPixelsResolver
{
    /**
     * Pixels efetivos para runtime (checkout, CAPI, upsell).
     *
     * @return array<string, mixed>
     */
    public function resolve(Product $product): array
    {
        $stored = $this->storedConversionPixels($product);
        $productId = (string) $product->id;

        $resolved = Product::defaultConversionPixels();

        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            $raw = isset($stored[$platform]) && is_array($stored[$platform]) ? $stored[$platform] : [];
            $resolved[$platform] = $this->resolvePlatformBlock($raw, $platform, $product->tenant_id, $productId);
        }

        $resolved['custom_script'] = $this->resolveCustomScripts(
            is_array($stored['custom_script'] ?? null) ? $stored['custom_script'] : [],
            $stored,
            $product->tenant_id,
            $productId
        );

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveForCheckout(Product $product, ?string $affiliateRef): array
    {
        $pixels = AffiliateAttribution::conversionPixelsForCheckoutRaw($product, $affiliateRef);

        return $this->resolveFromStoredArray($pixels, $product->tenant_id);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function resolveFromStoredArray(array $stored, ?int $tenantId): array
    {
        $resolved = Product::defaultConversionPixels();

        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            $raw = isset($stored[$platform]) && is_array($stored[$platform]) ? $stored[$platform] : [];
            $resolved[$platform] = $this->resolvePlatformBlock($raw, $platform, $tenantId);
        }

        $resolved['custom_script'] = $this->resolveCustomScripts(
            is_array($stored['custom_script'] ?? null) ? $stored['custom_script'] : [],
            $stored,
            $tenantId
        );

        return $resolved;
    }

    /**
     * JSON bruto do produto (sem normalização de entries legado que descarta integration_ids).
     *
     * @return array<string, mixed>
     */
    public function storedConversionPixels(Product $product): array
    {
        $value = $product->getAttributes()['conversion_pixels'] ?? null;
        $stored = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
        if (! is_array($stored)) {
            return Product::defaultConversionPixels();
        }

        return self::mergeStoredPixelTree(Product::defaultConversionPixels(), $stored);
    }

    /**
     * Mescla árvore de pixels sem injetar `entries: []` do default sobre bloco legado na raiz.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    public static function mergeStoredPixelTree(array $base, array $overlay): array
    {
        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            if (isset($overlay[$platform]) && is_array($overlay[$platform])) {
                $base[$platform] = $overlay[$platform];
            }
        }

        if (isset($overlay['custom_script']) && is_array($overlay['custom_script'])) {
            $base['custom_script'] = $overlay['custom_script'];
        }

        if (isset($overlay['custom_script_integration_ids']) && is_array($overlay['custom_script_integration_ids'])) {
            $base['custom_script_integration_ids'] = $overlay['custom_script_integration_ids'];
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{enabled: bool, entries: list<array<string, mixed>>}
     */
    private function resolvePlatformBlock(array $block, string $platform, ?int $tenantId, ?string $productId = null): array
    {
        $enabled = filter_var($block['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $integrationIds = $this->normalizeIntegrationIds($block['integration_ids'] ?? []);
        $blockFlags = $this->blockLevelFlags($block);

        if ($productId !== null && $tenantId !== null) {
            $linkedEntries = $this->entriesFromProductLinkedIntegrations($productId, $platform, $tenantId);
            if ($linkedEntries !== []) {
                return [
                    'enabled' => true,
                    'entries' => $linkedEntries,
                ];
            }
        }

        if ($enabled && $integrationIds !== []) {
            $entries = $this->entriesFromIntegrations($integrationIds, $platform, $tenantId, $blockFlags);
            if ($entries !== []) {
                return [
                    'enabled' => $enabled,
                    'entries' => $entries,
                ];
            }
        }

        return Product::normalizeConversionPixelBlock($block, $platform);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<array<string, mixed>>
     */
    private function resolveCustomScripts(array $inlineScripts, array $stored, ?int $tenantId, ?string $productId = null): array
    {
        if ($productId !== null && $tenantId !== null) {
            $linked = $this->scriptsFromProductLinkedIntegrations($productId, $tenantId);
            if ($linked !== []) {
                return $linked;
            }
        }

        $integrationIds = [];
        if (isset($stored['custom_script_integration_ids']) && is_array($stored['custom_script_integration_ids'])) {
            $integrationIds = $this->normalizeIntegrationIds($stored['custom_script_integration_ids']);
        }

        if ($integrationIds === []) {
            return array_values(array_filter($inlineScripts, fn ($item) => is_array($item) && trim((string) ($item['script'] ?? '')) !== ''));
        }

        $scripts = [];
        $integrations = ConversionPixelIntegration::query()
            ->forTenant($tenantId)
            ->active()
            ->where('platform', ConversionPixelIntegration::PLATFORM_CUSTOM_SCRIPT)
            ->whereIn('id', $integrationIds)
            ->get()
            ->keyBy('id');

        foreach ($integrationIds as $integrationId) {
            $integration = $integrations->get($integrationId);
            if (! $integration) {
                continue;
            }
            $entry = $integration->toPixelEntry();
            if ($entry !== null) {
                $scripts[] = $entry;
            }
        }

        return $scripts;
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return list<int>
     */
    private function normalizeIntegrationIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($id) => is_numeric($id) ? (int) $id : null,
            $ids
        ))));
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{fire_purchase_on_pix: bool, fire_purchase_on_boleto: bool, disable_order_bump_events: bool}
     */
    private function blockLevelFlags(array $block): array
    {
        $flags = Product::defaultConversionPixelEntryFlags();
        foreach (['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events'] as $key) {
            if (array_key_exists($key, $block)) {
                $flags[$key] = filter_var($block[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $flags;
    }

    /**
     * @param  list<int>  $integrationIds
     * @param  array{fire_purchase_on_pix: bool, fire_purchase_on_boleto: bool, disable_order_bump_events: bool}  $blockFlags
     * @return list<array<string, mixed>>
     */
    private function entriesFromIntegrations(array $integrationIds, string $platform, ?int $tenantId, array $blockFlags): array
    {
        $integrations = ConversionPixelIntegration::query()
            ->forTenant($tenantId)
            ->active()
            ->where('platform', $platform)
            ->whereIn('id', $integrationIds)
            ->get()
            ->keyBy('id');

        $entries = [];
        foreach ($integrationIds as $integrationId) {
            $integration = $integrations->get($integrationId);
            if (! $integration) {
                continue;
            }
            $entry = $integration->toPixelEntry();
            if ($entry === null) {
                continue;
            }
            $entries[] = array_merge($entry, $blockFlags);
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entriesFromProductLinkedIntegrations(string $productId, string $platform, int $tenantId): array
    {
        $integrations = ConversionPixelIntegration::query()
            ->forTenant($tenantId)
            ->active()
            ->where('platform', $platform)
            ->with('products:id')
            ->orderBy('name')
            ->get()
            ->filter(fn (ConversionPixelIntegration $integration) => $integration->appliesToProduct($productId));

        $entries = [];
        foreach ($integrations as $integration) {
            $entry = $integration->toPixelEntry();
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scriptsFromProductLinkedIntegrations(string $productId, int $tenantId): array
    {
        $integrations = ConversionPixelIntegration::query()
            ->forTenant($tenantId)
            ->active()
            ->where('platform', ConversionPixelIntegration::PLATFORM_CUSTOM_SCRIPT)
            ->with('products:id')
            ->orderBy('name')
            ->get()
            ->filter(fn (ConversionPixelIntegration $integration) => $integration->appliesToProduct($productId));

        $scripts = [];
        foreach ($integrations as $integration) {
            $entry = $integration->toPixelEntry();
            if ($entry !== null) {
                $scripts[] = $entry;
            }
        }

        return $scripts;
    }
}
