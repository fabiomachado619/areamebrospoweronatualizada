<?php

namespace App\Http\Controllers;

use App\Models\PanelPushSubscription;
use App\Services\MemberAreaResolver;
use App\Support\PanelPushPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelPwaController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $resolved = app(MemberAreaResolver::class)->resolve($request);
        if ($resolved && in_array($resolved['access_type'], ['subdomain', 'custom'], true)) {
            $request->attributes->set('member_area_product', $resolved['product']);
            $request->attributes->set('member_area_access_type', $resolved['access_type']);
            $request->attributes->set('member_area_slug', $resolved['slug']);

            return app()->call(\App\Http\Controllers\MemberAreaAppController::class.'@manifest', [
                'request' => $request,
                'slug' => $resolved['slug'],
            ]);
        }

        $appName = config('getfy.app_name', 'Getfy');
        $themeColor = config('getfy.pwa_theme_color');
        $themeColor = ($themeColor !== null && $themeColor !== '') ? (string) $themeColor : (string) config('getfy.theme_primary', '#0ea5e9');

        $icons = [];
        $addIconVariants = function (string $src, string $sizes) use (&$icons): void {
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'maskable'];
        };

        $resolveIconUrl = static function (?string $path): ?string {
            if (! is_string($path) || $path === '') {
                return null;
            }
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }

            return url('/'.ltrim($path, '/'));
        };

        $pwa192 = $resolveIconUrl(config('getfy.pwa_icon_192'));
        $pwa512 = $resolveIconUrl(config('getfy.pwa_icon_512'));
        $pwaSingle = $resolveIconUrl(config('getfy.pwa_icon'));

        if ($pwa192 !== null && $pwa512 !== null) {
            $addIconVariants($pwa192, '192x192');
            $addIconVariants($pwa512, '512x512');
        } elseif ($pwaSingle !== null) {
            $addIconVariants($pwaSingle, '192x192');
            $addIconVariants($pwaSingle, '512x512');
        } else {
            $iconsDir = public_path('icons');
            $defaultIcon = $iconsDir.DIRECTORY_SEPARATOR.'icon.png';
            if (is_file($defaultIcon)) {
                $iconUrl = url('/icons/icon.png');
                $addIconVariants($iconUrl, '192x192');
                $addIconVariants($iconUrl, '512x512');
            } else {
                $has192 = is_file($iconsDir.'/icon-192x192.png');
                $has512 = is_file($iconsDir.'/icon-512x512.png');
                $icon192Url = url('/icons/icon-192x192.png');
                $icon512Url = url('/icons/icon-512x512.png');

                if ($has192) {
                    $addIconVariants($icon192Url, '192x192');
                }
                if ($has512) {
                    $addIconVariants($icon512Url, '512x512');
                }
                if (empty($icons)) {
                    $fallbackIcon = (string) config('getfy.app_logo_icon', 'https://cdn.getfy.cloud/collapsed-logo.png');
                    $addIconVariants($fallbackIcon, '192x192');
                    $addIconVariants($fallbackIcon, '512x512');
                } elseif ($has512 && ! $has192) {
                    $addIconVariants($icon512Url, '192x192');
                } elseif ($has192 && ! $has512) {
                    $addIconVariants($icon192Url, '512x512');
                }
            }
        }

        $manifest = [
            'id' => '/',
            'name' => $appName,
            'short_name' => $appName,
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#18181b',
            'theme_color' => $themeColor,
            'prefer_related_applications' => false,
            'icons' => $icons,
        ];

        return response()
            ->json($manifest)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=0, must-revalidate');
    }

    public function pushSubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'renewed' => ['sometimes', 'boolean'],
            'preferences' => ['sometimes', 'array'],
            'preferences.pix' => ['sometimes', 'boolean'],
            'preferences.boleto' => ['sometimes', 'boolean'],
            'preferences.card' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $keys = $validated['keys'];
        $keys['auth'] = $this->normalizeBase64KeyForPush((string) ($keys['auth'] ?? ''));
        $keys['p256dh'] = $this->normalizeBase64KeyForPush((string) ($keys['p256dh'] ?? ''));

        $existing = PanelPushSubscription::where('endpoint', $validated['endpoint'])->first();
        $preferences = PanelPushPreferences::normalize(
            $validated['preferences'] ?? $existing?->preferences
        );

        $subscription = PanelPushSubscription::updateOrCreate(
            [
                'endpoint' => $validated['endpoint'],
            ],
            [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'keys' => $keys,
                'user_agent' => $request->userAgent(),
                'preferences' => $preferences,
                'push_fail_count' => 0,
                'last_push_failed_at' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'subscribed' => true,
            'subscription_id' => $subscription->id,
            'renewed' => (bool) ($validated['renewed'] ?? false),
            'preferences' => PanelPushPreferences::normalize($subscription->preferences),
            'updated_at' => $subscription->updated_at?->toISOString(),
        ]);
    }

    public function updatePushPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.pix' => ['sometimes', 'boolean'],
            'preferences.boleto' => ['sometimes', 'boolean'],
            'preferences.card' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $preferences = PanelPushPreferences::normalize($validated['preferences']);

        PanelPushSubscription::where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id)
            ->update(['preferences' => $preferences]);

        return response()->json([
            'success' => true,
            'preferences' => $preferences,
        ]);
    }

    private function normalizeBase64KeyForPush(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }
        if (str_contains($key, '+') || str_contains($key, '/')) {
            return strtr($key, ['+' => '-', '/' => '_']);
        }

        return $key;
    }
}
