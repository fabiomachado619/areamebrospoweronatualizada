<?php

namespace App\Support;

use App\Models\Product;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutOpenGraph
{
    /**
     * Meta tags for link previews (WhatsApp, Facebook, etc.) — must be in the initial HTML.
     *
     * @return array{title: string, description: string, image: ?string, url: string, type: string, site_name: string}
     */
    public static function forProduct(Product $product, array $checkoutConfig, Request $request): array
    {
        $seo = is_array($checkoutConfig['seo'] ?? null) ? $checkoutConfig['seo'] : [];
        $title = trim((string) ($seo['title'] ?? ''));
        if ($title === '') {
            $title = (string) $product->name;
        }

        $description = trim((string) ($seo['description'] ?? ''));
        if ($description === '') {
            $description = trim(strip_tags((string) ($product->description ?? '')));
        }
        $description = Str::limit($description, 300, '');

        $image = self::resolveImageUrl(
            $seo['og_image'] ?? null,
            $product,
            $request
        );

        $favicon = trim((string) ($seo['favicon'] ?? ''));
        if ($favicon === '') {
            $favicon = BrandFavicon::publicUrl();
        } else {
            $favicon = self::absoluteUrl($favicon, $request) ?? BrandFavicon::publicUrl();
        }

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'url' => $request->url(),
            'type' => 'website',
            'site_name' => (string) config('getfy.app_name', config('app.name', 'Power On')),
            'favicon' => $favicon,
        ];
    }

    private static function resolveImageUrl(mixed $seoOgImage, Product $product, Request $request): ?string
    {
        $candidate = trim((string) $seoOgImage);
        if ($candidate === '' && $product->image) {
            $candidate = (new StorageService($product->tenant_id))->url($product->image);
        }

        return self::absoluteUrl($candidate, $request);
    }

    public static function absoluteUrl(?string $url, Request $request): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $base = $request->getSchemeAndHttpHost();

        return $base.(str_starts_with($url, '/') ? $url : '/'.$url);
    }
}
