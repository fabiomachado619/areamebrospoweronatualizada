<?php

namespace App\Services;

use App\Models\ConversionPixelIntegration;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Str;

class LegacyConversionPixelsMigrator
{
    public function tenantIsMigrated(?int $tenantId): bool
    {
        return Setting::get('conversion_pixels_library_migrated', '0', $tenantId) === '1';
    }

    public function markTenantMigrated(?int $tenantId): void
    {
        Setting::set('conversion_pixels_library_migrated', '1', $tenantId);
    }

    /**
     * Importa pixels inline do produto para integrações centralizadas + pivot.
     */
    public function migrateTenant(?int $tenantId): int
    {
        $migrated = 0;

        Product::forTenant($tenantId)
            ->orderBy('name')
            ->each(function (Product $product) use (&$migrated, $tenantId) {
                $migrated += $this->migrateProduct($product, $tenantId);
            });

        if ($migrated > 0 || ! $this->tenantHasLegacyInlinePixels($tenantId)) {
            $this->markTenantMigrated($tenantId);
        }

        return $migrated;
    }

    public function tenantHasLegacyInlinePixels(?int $tenantId): bool
    {
        $resolver = app(ConversionPixelsResolver::class);

        foreach (Product::forTenant($tenantId)->get(['id', 'conversion_pixels']) as $product) {
            if ($this->productHasLegacyInlinePixels($resolver->storedConversionPixels($product))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public function productHasLegacyInlinePixels(array $stored): bool
    {
        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            $block = is_array($stored[$platform] ?? null) ? $stored[$platform] : [];
            if ($this->blockHasLegacyInlineCredentials($block, $platform)) {
                return true;
            }
        }

        $scripts = is_array($stored['custom_script'] ?? null) ? $stored['custom_script'] : [];
        foreach ($scripts as $script) {
            if (is_array($script) && trim((string) ($script['script'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function migrateProduct(Product $product, ?int $tenantId): int
    {
        $resolver = app(ConversionPixelsResolver::class);
        $stored = $resolver->storedConversionPixels($product);
        if (! $this->productHasLegacyInlinePixels($stored)) {
            return 0;
        }

        $count = 0;

        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            $block = is_array($stored[$platform] ?? null) ? $stored[$platform] : [];
            $entries = $this->collectInlineEntries($block, $platform);
            foreach ($entries as $entry) {
                $integration = $this->findOrCreateIntegration($tenantId, $platform, $entry);
                if ($integration) {
                    $integration->products()->syncWithoutDetaching([(string) $product->id]);
                    $count++;
                }
            }
            if ($entries !== []) {
                $stored[$platform] = [
                    'enabled' => filter_var($block['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'entries' => [],
                    'integration_ids' => [],
                ];
            }
        }

        $scripts = is_array($stored['custom_script'] ?? null) ? $stored['custom_script'] : [];
        foreach ($scripts as $script) {
            if (! is_array($script) || trim((string) ($script['script'] ?? '')) === '') {
                continue;
            }
            $integration = $this->findOrCreateScriptIntegration(
                $tenantId,
                trim((string) ($script['name'] ?? '')) ?: 'Script migrado',
                (string) $script['script']
            );
            $integration->products()->syncWithoutDetaching([(string) $product->id]);
            $count++;
        }
        if ($scripts !== []) {
            $stored['custom_script'] = [];
            $stored['custom_script_integration_ids'] = [];
        }

        $product->update(['conversion_pixels' => $stored]);

        return $count;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function collectInlineEntries(array $block, string $platform): array
    {
        $normalized = Product::normalizeConversionPixelBlock($block, $platform);

        return $normalized['entries'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockHasLegacyInlineCredentials(array $block, string $platform): bool
    {
        if (! empty($block['integration_ids']) && is_array($block['integration_ids'])) {
            return false;
        }

        return $this->collectInlineEntries($block, $platform) !== [];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function findOrCreateIntegration(?int $tenantId, string $platform, array $entry): ?ConversionPixelIntegration
    {
        $config = $this->configFromEntry($platform, $entry);
        if ($config === null) {
            return null;
        }

        foreach (['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events'] as $flag) {
            if (array_key_exists($flag, $entry)) {
                $config[$flag] = filter_var($entry[$flag], FILTER_VALIDATE_BOOLEAN);
            } elseif (array_key_exists($flag, $config)) {
                continue;
            }
        }

        $existing = $this->findExistingIntegration($tenantId, $platform, $config);
        if ($existing) {
            $existing->update(['config' => array_merge(is_array($existing->config) ? $existing->config : [], $config)]);
            if (in_array($platform, [ConversionPixelIntegration::PLATFORM_META, ConversionPixelIntegration::PLATFORM_TIKTOK], true)) {
                $token = trim((string) ($entry['access_token'] ?? ''));
                if ($token !== '') {
                    $existing->access_token = $token;
                    $existing->save();
                }
            }

            return $existing;
        }

        $name = $this->defaultNameForPlatform($platform, $config);

        return ConversionPixelIntegration::create([
            'tenant_id' => $tenantId,
            'platform' => $platform,
            'name' => $name,
            'config' => $config,
            'access_token' => in_array($platform, [ConversionPixelIntegration::PLATFORM_META, ConversionPixelIntegration::PLATFORM_TIKTOK], true)
                ? (trim((string) ($entry['access_token'] ?? '')) ?: null)
                : null,
            'is_active' => true,
        ]);
    }

    private function findOrCreateScriptIntegration(?int $tenantId, string $name, string $script): ConversionPixelIntegration
    {
        $hash = md5($script);
        $existing = ConversionPixelIntegration::query()
            ->forTenant($tenantId)
            ->where('platform', ConversionPixelIntegration::PLATFORM_CUSTOM_SCRIPT)
            ->get()
            ->first(fn (ConversionPixelIntegration $i) => md5(trim((string) (($i->config ?? [])['script'] ?? ''))) === $hash);

        if ($existing) {
            return $existing;
        }

        return ConversionPixelIntegration::create([
            'tenant_id' => $tenantId,
            'platform' => ConversionPixelIntegration::PLATFORM_CUSTOM_SCRIPT,
            'name' => $name,
            'config' => ['script' => $script],
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function findExistingIntegration(?int $tenantId, string $platform, array $config): ?ConversionPixelIntegration
    {
        $query = ConversionPixelIntegration::query()
            ->forTenant($tenantId)
            ->where('platform', $platform);

        return match ($platform) {
            ConversionPixelIntegration::PLATFORM_META, ConversionPixelIntegration::PLATFORM_TIKTOK => $query->get()->first(
                fn (ConversionPixelIntegration $i) => trim((string) (($i->config ?? [])['pixel_id'] ?? '')) === trim((string) ($config['pixel_id'] ?? ''))
            ),
            ConversionPixelIntegration::PLATFORM_GOOGLE_ADS => $query->get()->first(
                fn (ConversionPixelIntegration $i) => trim((string) (($i->config ?? [])['conversion_id'] ?? '')) === trim((string) ($config['conversion_id'] ?? ''))
            ),
            ConversionPixelIntegration::PLATFORM_GOOGLE_ANALYTICS => $query->get()->first(
                fn (ConversionPixelIntegration $i) => trim((string) (($i->config ?? [])['measurement_id'] ?? '')) === trim((string) ($config['measurement_id'] ?? ''))
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function configFromEntry(string $platform, array $entry): ?array
    {
        $flags = Product::defaultConversionPixelEntryFlags();
        foreach (['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events'] as $flag) {
            if (array_key_exists($flag, $entry)) {
                $flags[$flag] = filter_var($entry[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return match ($platform) {
            ConversionPixelIntegration::PLATFORM_META, ConversionPixelIntegration::PLATFORM_TIKTOK => trim((string) ($entry['pixel_id'] ?? '')) !== ''
                ? array_merge(['pixel_id' => trim((string) $entry['pixel_id'])], $flags)
                : null,
            ConversionPixelIntegration::PLATFORM_GOOGLE_ADS => trim((string) ($entry['conversion_id'] ?? '')) !== ''
                ? array_merge([
                    'conversion_id' => trim((string) $entry['conversion_id']),
                    'conversion_label' => trim((string) ($entry['conversion_label'] ?? '')),
                ], $flags)
                : null,
            ConversionPixelIntegration::PLATFORM_GOOGLE_ANALYTICS => trim((string) ($entry['measurement_id'] ?? '')) !== ''
                ? array_merge(['measurement_id' => trim((string) $entry['measurement_id'])], $flags)
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function defaultNameForPlatform(string $platform, array $config): string
    {
        $label = ConversionPixelIntegration::platformLabels()[$platform] ?? $platform;
        $suffix = match ($platform) {
            ConversionPixelIntegration::PLATFORM_META, ConversionPixelIntegration::PLATFORM_TIKTOK => $config['pixel_id'] ?? '',
            ConversionPixelIntegration::PLATFORM_GOOGLE_ADS => $config['conversion_id'] ?? '',
            ConversionPixelIntegration::PLATFORM_GOOGLE_ANALYTICS => $config['measurement_id'] ?? '',
            default => Str::limit((string) ($config['script'] ?? ''), 24),
        };

        return trim($label.' — '.$suffix) ?: $label.' migrado';
    }
}
