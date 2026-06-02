<?php

namespace App\Support;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Services\ConversionPixelsResolver;
use Illuminate\Http\Request;

class AffiliateAttribution
{
    public static function normalizeRef(?string $ref): ?string
    {
        if ($ref === null || trim($ref) === '') {
            return null;
        }

        return mb_substr(strtolower(trim($ref)), 0, 32);
    }

    public static function refFromRequest(Request $request): ?string
    {
        return self::normalizeRef($request->query('ref') ?? $request->input('affiliate_ref'));
    }

    /**
     * @return array{affiliate_code?: string, sale_channel?: string}
     */
    public static function resolveRefForOrder(?string $fromRequest, ?CheckoutSession $session): ?string
    {
        $ref = self::normalizeRef($fromRequest);
        if ($ref !== null) {
            return $ref;
        }

        return self::affiliateCodeFromSession($session);
    }

    /**
     * Metadados de canal/afiliado para gravar no pedido no momento da criação.
     *
     * @return array{affiliate_code?: string, sale_channel: string}
     */
    public static function metadataAttributes(Product $product, ?string $affiliateCode): array
    {
        return self::resolveForProduct($product, self::normalizeRef($affiliateCode));
    }

    public static function resolveForProduct(Product $product, ?string $affiliateCode): array
    {
        if ($affiliateCode === null) {
            return ['sale_channel' => 'producer'];
        }

        $exists = self::approvedAffiliateForCode($product->id, $affiliateCode) !== null;

        if (! $exists) {
            return ['sale_channel' => 'producer'];
        }

        return [
            'affiliate_code' => $affiliateCode,
            'sale_channel' => 'affiliate',
        ];
    }

    public static function approvedAffiliateForCode(string $productId, string $affiliateCode): ?ProductAffiliate
    {
        return ProductAffiliate::query()
            ->where('product_id', $productId)
            ->whereRaw('LOWER(affiliate_code) = ?', [mb_strtolower($affiliateCode)])
            ->where('status', ProductAffiliate::STATUS_APPROVED)
            ->first();
    }

    public static function mergeIntoTrackingMetadata(array $tracking, ?string $ref): array
    {
        if ($ref !== null) {
            $tracking['affiliate_code'] = $ref;
        }

        return $tracking;
    }

    public static function persistOnOrder(Order $order, Product $product, ?string $affiliateCode): void
    {
        $attrs = self::resolveForProduct($product, $affiliateCode);
        $meta = $order->metadata ?? [];
        foreach ($attrs as $k => $v) {
            $meta[$k] = $v;
        }
        $order->update(['metadata' => $meta]);
    }

    public static function affiliateCodeFromSession(?CheckoutSession $session): ?string
    {
        if (! $session) {
            return null;
        }
        $meta = $session->tracking_metadata;
        if (! is_array($meta)) {
            return null;
        }
        $code = $meta['affiliate_code'] ?? null;

        return is_string($code) ? self::normalizeRef($code) : null;
    }

    /**
     * Pixels armazenados (produto ou afiliado), sem resolver integrações centralizadas.
     *
     * @return array<string, mixed>
     */
    public static function conversionPixelsForCheckoutRaw(Product $product, ?string $affiliateCode): array
    {
        $resolver = app(ConversionPixelsResolver::class);
        $base = $resolver->storedConversionPixels($product);

        if ($affiliateCode === null) {
            return $base;
        }

        $affiliate = self::approvedAffiliateForCode($product->id, $affiliateCode);

        if (! $affiliate || ! is_array($affiliate->affiliate_pixels) || ! self::hasConfiguredPixels($affiliate->affiliate_pixels)) {
            return $base;
        }

        return ConversionPixelsResolver::mergeStoredPixelTree(
            Product::defaultConversionPixels(),
            $affiliate->affiliate_pixels
        );
    }

    /**
     * Pixels do checkout: produto por padrão; substitui pelos do afiliado aprovado quando ref válido e configurado.
     *
     * @return array<string, mixed>
     */
    public static function conversionPixelsForCheckout(Product $product, ?string $affiliateCode): array
    {
        $raw = self::conversionPixelsForCheckoutRaw($product, $affiliateCode);

        return app(ConversionPixelsResolver::class)->resolveFromStoredArray($raw, $product->tenant_id);
    }

    public static function affiliateCodeFromOrderMetadata(?array $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }
        $code = $metadata['affiliate_code'] ?? null;

        return is_string($code) ? self::normalizeRef($code) : null;
    }

    /**
     * @param  array<string, mixed>  $pixels
     */
    public static function hasConfiguredPixels(array $pixels): bool
    {
        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            $block = $pixels[$platform] ?? null;
            if (! is_array($block) || empty($block['enabled'])) {
                continue;
            }
            $integrationIds = $block['integration_ids'] ?? [];
            if (is_array($integrationIds) && $integrationIds !== []) {
                return true;
            }
            if (self::platformBlockHasInlineCredentials($block, $platform)) {
                return true;
            }
        }

        if (! empty($pixels['custom_script_integration_ids']) && is_array($pixels['custom_script_integration_ids'])) {
            if ($pixels['custom_script_integration_ids'] !== []) {
                return true;
            }
        }

        if (! empty($pixels['custom_script']) && is_array($pixels['custom_script'])) {
            foreach ($pixels['custom_script'] as $script) {
                if (is_array($script) && trim((string) ($script['script'] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detecta pixels inline (entries ou formato legado com credenciais na raiz do bloco).
     *
     * @param  array<string, mixed>  $block
     */
    public static function platformBlockHasInlineCredentials(array $block, string $platform): bool
    {
        if (! empty($block['entries']) && is_array($block['entries'])) {
            foreach ($block['entries'] as $entry) {
                if (is_array($entry) && self::pixelEntryHasCredentials($entry, $platform)) {
                    return true;
                }
            }
        }

        return self::pixelEntryHasCredentials($block, $platform);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function pixelEntryHasCredentials(array $entry, string $platform): bool
    {
        return match ($platform) {
            'meta', 'tiktok' => trim((string) ($entry['pixel_id'] ?? '')) !== '',
            'google_ads' => trim((string) ($entry['conversion_id'] ?? '')) !== '',
            'google_analytics' => trim((string) ($entry['measurement_id'] ?? '')) !== '',
            default => false,
        };
    }
}
