<?php

namespace App\Support;

use App\Models\Product;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialPreviewOpenGraph
{
    /**
     * Meta tags for link previews (WhatsApp, Facebook, etc.) — must be in the initial HTML.
     *
     * @return array{title: string, description: string, image: ?string, url: string, type: string, site_name: string, favicon: string}
     */
    public static function forMemberArea(Product $product, Request $request): array
    {
        $config = is_array($product->member_area_config) ? $product->member_area_config : [];
        $pwa = is_array($config['pwa'] ?? null) ? $config['pwa'] : [];
        $logos = is_array($config['logos'] ?? null) ? $config['logos'] : [];

        $title = trim((string) ($pwa['name'] ?? ''));
        if ($title === '') {
            $title = (string) $product->name;
        }

        $description = trim(strip_tags((string) ($product->description ?? '')));
        $description = Str::limit($description, 300, '');

        $favicon = self::resolveMemberFavicon($logos, $pwa, $request);
        $image = self::resolveMemberPreviewImage($logos, $pwa, $product, $request) ?? $favicon;

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'url' => $request->url(),
            'type' => 'website',
            'site_name' => $title,
            'favicon' => $favicon,
        ];
    }

    /**
     * @return array{title: string, description: string, image: ?string, url: string, type: string, site_name: string, favicon: string}
     */
    public static function forPlatform(Request $request): array
    {
        $appName = (string) config('getfy.app_name', config('app.name', 'Power On'));
        $favicon = BrandFavicon::publicUrl();
        $image = self::resolvePlatformPreviewImage($request) ?? $favicon;

        return [
            'title' => $appName,
            'description' => '',
            'image' => $image,
            'url' => $request->url(),
            'type' => 'website',
            'site_name' => $appName,
            'favicon' => $favicon,
        ];
    }

    /**
     * @param  array<string, mixed>  $logos
     * @param  array<string, mixed>  $pwa
     */
    private static function resolveMemberFavicon(array $logos, array $pwa, Request $request): string
    {
        foreach ([
            $logos['favicon'] ?? null,
            $pwa['favicon'] ?? null,
        ] as $candidate) {
            $url = CheckoutOpenGraph::absoluteUrl(trim((string) $candidate), $request);
            if ($url !== null) {
                return $url;
            }
        }

        return BrandFavicon::publicUrl();
    }

    /**
     * @param  array<string, mixed>  $logos
     * @param  array<string, mixed>  $pwa
     */
    private static function resolveMemberPreviewImage(array $logos, array $pwa, Product $product, Request $request): ?string
    {
        if (isset($pwa['icons']) && is_array($pwa['icons'])) {
            $best = null;
            $bestSize = 0;
            foreach ($pwa['icons'] as $icon) {
                if (! is_array($icon)) {
                    continue;
                }
                $url = CheckoutOpenGraph::absoluteUrl(trim((string) ($icon['src'] ?? '')), $request);
                if ($url === null) {
                    continue;
                }
                $size = self::parseIconSize((string) ($icon['sizes'] ?? ''));
                if ($size >= $bestSize) {
                    $best = $url;
                    $bestSize = $size;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        foreach ([
            $logos['favicon'] ?? null,
            $pwa['favicon'] ?? null,
            $logos['logo_light'] ?? null,
            $logos['logo_dark'] ?? null,
        ] as $candidate) {
            $url = CheckoutOpenGraph::absoluteUrl(trim((string) $candidate), $request);
            if ($url !== null) {
                return $url;
            }
        }

        if ($product->image) {
            return CheckoutOpenGraph::absoluteUrl(
                (new StorageService($product->tenant_id))->url($product->image),
                $request
            );
        }

        return null;
    }

    private static function resolvePlatformPreviewImage(Request $request): ?string
    {
        foreach ([
            config('getfy.pwa_icon_512'),
            config('getfy.pwa_icon_192'),
            config('getfy.pwa_icon'),
            config('getfy.app_logo_icon'),
        ] as $path) {
            $url = self::absoluteConfiguredAsset($path, $request);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private static function absoluteConfiguredAsset(mixed $path, Request $request): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $publicPath = public_path(ltrim($path, '/'));
        if (! is_file($publicPath)) {
            return CheckoutOpenGraph::absoluteUrl($path, $request);
        }

        return CheckoutOpenGraph::absoluteUrl($path, $request);
    }

    private static function parseIconSize(string $sizes): int
    {
        if (preg_match('/(\d+)x(\d+)/', $sizes, $matches) !== 1) {
            return 0;
        }

        return max((int) $matches[1], (int) $matches[2]);
    }
}
