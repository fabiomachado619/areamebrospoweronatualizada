<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Minishlink\WebPush\VAPID;

class MemberAreaPwaAdminService
{
    public function __construct(
        protected MemberHubService $memberHubService,
        protected MemberAreaResolver $memberAreaResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildTabPayload(int $tenantId): array
    {
        $hub = $this->memberHubService->ensureHubForTenant($tenantId);
        $baseUrl = rtrim($this->memberAreaResolver->baseUrlForProduct($hub), '/');
        $config = $hub->member_area_config ?? [];
        $pwa = $config['pwa'] ?? [];
        $logos = $config['logos'] ?? [];
        $theme = $config['theme'] ?? [];

        return [
            'pwa_settings' => $this->mapPwaSettings($hub),
            'member_area_url' => $baseUrl,
            'manifest_url' => $baseUrl.'/manifest.json',
            'hub_slug' => $hub->checkout_slug,
            'hub_name' => $hub->name,
            'upload_limits' => [
                'image_max_mb' => (int) max(1, floor(((int) config('member_builder_uploads.image_max_kb', 10240)) / 1024)),
            ],
            'pwa_status' => $this->buildPwaStatus($hub, $baseUrl),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{pwa_settings: array<string, mixed>, warning: string|null}
     */
    public function updatePwa(int $tenantId, array $data): array
    {
        $hub = $this->memberHubService->ensureHubForTenant($tenantId);
        $config = $hub->member_area_config ?? [];
        $existingPwa = $config['pwa'] ?? [];

        $config['pwa'] = array_merge($existingPwa, [
            'name' => trim((string) ($data['name'] ?? '')),
            'short_name' => trim((string) ($data['short_name'] ?? '')),
            'theme_color' => $this->normalizeColor($data['theme_color'] ?? '#0ea5e9', '#0ea5e9'),
            'push_enabled' => (bool) ($data['push_enabled'] ?? false),
        ]);

        $config['theme'] = array_merge($config['theme'] ?? [], [
            'background' => $this->normalizeColor($data['background_color'] ?? '#18181b', '#18181b'),
        ]);

        if (array_key_exists('favicon', $data)) {
            $config['logos'] = array_merge($config['logos'] ?? [], [
                'favicon' => $this->nullableString($data['favicon']),
            ]);
        }

        $warning = $this->ensureVapidKeys($config, $existingPwa);

        $hub->member_area_config = $config;
        $hub->save();

        return [
            'pwa_settings' => $this->mapPwaSettings($hub->fresh()),
            'warning' => $warning,
        ];
    }

    /**
     * @return array{url: string, path: string}
     */
    public function uploadIcon(int $tenantId, UploadedFile $file): array
    {
        $hub = $this->memberHubService->ensureHubForTenant($tenantId);
        $storage = app(StorageService::class);
        $path = $storage->putFile('member-area/'.$hub->id, $file);
        $url = $storage->url($path);

        $config = $hub->member_area_config ?? [];
        $config['logos'] = array_merge($config['logos'] ?? [], ['favicon' => $url]);
        $hub->member_area_config = $config;
        $hub->save();

        return ['url' => $url, 'path' => $path];
    }

    /**
     * Configurações PWA efetivas para um produto da área de membros.
     * Cursos vinculados ao HUB herdam name, short_name, ícone e cores do HUB.
     *
     * @return array{pwa: array<string, mixed>, logos: array<string, mixed>, theme: array<string, mixed>, source: Product}
     */
    public function resolvePwaContextForProduct(Product $product): array
    {
        $source = $product;

        if (! $product->isMemberHub() && $product->member_hub_product_id) {
            $hub = $product->relationLoaded('memberHub')
                ? $product->memberHub
                : Product::query()->find($product->member_hub_product_id);

            if ($hub && $hub->isMemberHub()) {
                $source = $hub;
            }
        }

        $config = $source->member_area_config ?? [];

        return [
            'pwa' => is_array($config['pwa'] ?? null) ? $config['pwa'] : [],
            'logos' => is_array($config['logos'] ?? null) ? $config['logos'] : [],
            'theme' => is_array($config['theme'] ?? null) ? $config['theme'] : [],
            'source' => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPwaSettings(Product $hub): array
    {
        $config = $hub->member_area_config ?? [];
        $pwa = $config['pwa'] ?? [];
        $logos = $config['logos'] ?? [];
        $theme = $config['theme'] ?? [];

        return [
            'name' => (string) ($pwa['name'] ?? ''),
            'short_name' => (string) ($pwa['short_name'] ?? ''),
            'favicon' => (string) ($logos['favicon'] ?? ''),
            'theme_color' => (string) ($pwa['theme_color'] ?? '#0ea5e9'),
            'background_color' => (string) ($theme['background'] ?? '#18181b'),
            'push_enabled' => (bool) ($pwa['push_enabled'] ?? false),
            'vapid_configured' => ! empty($pwa['vapid_public']) && ! empty($pwa['vapid_private']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPwaStatus(Product $hub, string $baseUrl): array
    {
        $slug = trim((string) $hub->checkout_slug);

        return [
            'manifest_ok' => $slug !== '' && $baseUrl !== '',
            'service_worker_ok' => file_exists(public_path('member-area-sw.js')),
            'install_prompt_ok' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $existingPwa
     */
    private function ensureVapidKeys(array &$config, array $existingPwa): ?string
    {
        $pwa = $config['pwa'] ?? [];
        if (empty($pwa['push_enabled'])) {
            return null;
        }

        if (! empty($pwa['vapid_public']) && ! empty($pwa['vapid_private'])) {
            $config['pwa']['vapid_private'] = $existingPwa['vapid_private'] ?? $pwa['vapid_private'];

            return null;
        }

        try {
            $keys = VAPID::createVapidKeys();
            $config['pwa']['vapid_public'] = $keys['publicKey'];
            $config['pwa']['vapid_private'] = $keys['privateKey'];

            return null;
        } catch (\Throwable) {
            $config['pwa']['vapid_public'] = $existingPwa['vapid_public'] ?? null;
            $config['pwa']['vapid_private'] = $existingPwa['vapid_private'] ?? null;

            return 'Push ativado, mas não foi possível gerar chaves VAPID. Verifique a extensão OpenSSL do PHP.';
        }
    }

    private function normalizeColor(mixed $value, string $fallback): string
    {
        $color = trim((string) $value);
        if ($color === '') {
            return $fallback;
        }
        if (! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $fallback;
        }

        return $color;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
