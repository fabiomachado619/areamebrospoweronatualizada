<?php

namespace App\Services;

use App\Models\MemberAreaDomain;
use App\Models\Product;
use App\Support\GuestLoginRedirect;
use Illuminate\Http\Request;

class MemberAreaResolver
{
    /** @var list<string> */
    public const HOST_LOGIN_ACCESS_TYPES = ['subdomain', 'custom', 'hub_root'];

    /**
     * Resolve product and access type from request (path, subdomain, or custom domain).
     *
     * @return array{product: Product, access_type: string, slug: string|null}|null
     */
    public function resolve(Request $request): ?array
    {
        $hostRaw = strtolower(rtrim(trim($request->getHost()), '.'));
        $hostNormalized = MemberAreaDomain::normalizeCustomHost($hostRaw);
        $hosts = array_values(array_unique(array_filter([$hostRaw, $hostNormalized])));
        $path = $request->path();

        // Custom domain: host matches a stored custom domain
        $domain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_CUSTOM)
            ->whereIn('value', $hosts)
            ->with('product')
            ->first();
        if ($domain && $domain->product && $domain->product->type === Product::TYPE_AREA_MEMBROS) {
            return [
                'product' => $domain->product,
                'access_type' => 'custom',
                'slug' => $domain->product->checkout_slug,
            ];
        }

        // Subdomain: {slug}.members.xxx
        if (config('members.subdomain_enabled')) {
            $base = config('members.subdomain_base', '');
            if ($base && str_ends_with($host, $base) && $host !== $base) {
                $prefix = str_replace('.'.$base, '', $host);
                if ($prefix !== $host) {
                    $slug = $prefix;
                    $product = Product::where('checkout_slug', $slug)
                        ->where('type', Product::TYPE_AREA_MEMBROS)
                        ->first();
                    if ($product) {
                        return [
                            'product' => $product,
                            'access_type' => 'subdomain',
                            'slug' => $slug,
                        ];
                    }
                }
            }
        }

        // Path: /m/{slug} — use route parameter when available (reliable with subdirs), else parse path
        $pathSlug = $request->route()?->parameter('slug');
        if ($pathSlug !== null && $pathSlug !== '') {
            $slugNormalized = strtolower((string) $pathSlug);
            $product = Product::where('checkout_slug', $slugNormalized)
                ->where('type', Product::TYPE_AREA_MEMBROS)
                ->first();
            if ($product) {
                return [
                    'product' => $product,
                    'access_type' => 'path',
                    'slug' => $slugNormalized,
                ];
            }
            $pathDomain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_PATH)
                ->where('value', $slugNormalized)
                ->with('product')
                ->first();
            if ($pathDomain && $pathDomain->product && $pathDomain->product->type === Product::TYPE_AREA_MEMBROS) {
                return [
                    'product' => $pathDomain->product,
                    'access_type' => 'path',
                    'slug' => $slugNormalized,
                ];
            }
        }

        $path = $request->path();
        if (str_starts_with($path, 'm/')) {
            $segments = explode('/', trim($path, '/'));
            $slug = $segments[1] ?? null;
            if ($slug !== null && $slug !== '') {
                $slugNormalized = strtolower($slug);
                $product = Product::where('checkout_slug', $slugNormalized)
                    ->where('type', Product::TYPE_AREA_MEMBROS)
                    ->first();
                if ($product) {
                    return [
                        'product' => $product,
                        'access_type' => 'path',
                        'slug' => $slugNormalized,
                    ];
                }
                $pathDomain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_PATH)
                    ->where('value', $slugNormalized)
                    ->with('product')
                    ->first();
                if ($pathDomain && $pathDomain->product && $pathDomain->product->type === Product::TYPE_AREA_MEMBROS) {
                    return [
                        'product' => $pathDomain->product,
                        'access_type' => 'path',
                        'slug' => $slugNormalized,
                    ];
                }
            }
        }

        if ($this->isMainAppHost($request) && $this->isHubRootMemberPath($request)) {
            $hub = $this->resolveHubForMainHost();
            if ($hub !== null && $this->hubIsServedOnMainHost($hub)) {
                return [
                    'product' => $hub,
                    'access_type' => 'hub_root',
                    'slug' => $hub->checkout_slug,
                ];
            }
        }

        return null;
    }

    /**
     * Rotas do domínio principal que devem ser tratadas como área de membros (HUB na raiz).
     */
    private function isHubRootMemberPath(Request $request): bool
    {
        $path = trim($request->path(), '/');

        if ($path === '') {
            return true;
        }

        if (str_starts_with($path, 'm/')) {
            return false;
        }

        if (in_array($path, ['logout'], true)) {
            return false;
        }

        if (GuestLoginRedirect::shouldUseAdminLogin($request)) {
            return false;
        }

        $platformPrefixes = [
            'checkout', 'c/', 'api', 'webhooks', 'storage', 'install', 'docker-setup',
            'cron', 'brand', 'afiliar', 'convite', 'renovar', 'register', 'up',
            'favicon.ico', 'painel-sw.js',
        ];

        foreach ($platformPrefixes as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    public function usesHostLoginPath(?string $accessType): bool
    {
        return in_array($accessType, self::HOST_LOGIN_ACCESS_TYPES, true);
    }

    public function isMainAppHost(Request $request): bool
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (! is_string($appHost) || $appHost === '') {
            return false;
        }

        $requestHost = strtolower(rtrim(trim($request->getHost()), '.'));
        if (strtolower($appHost) !== $requestHost) {
            return false;
        }

        $appPort = parse_url($appUrl, PHP_URL_PORT);
        if ($appPort !== null && (int) $request->getPort() !== (int) $appPort) {
            return false;
        }

        return true;
    }

    public function hasHubOnMainHost(): bool
    {
        return $this->resolveHubForMainHost() !== null;
    }

    public function resolveHubForMainHost(): ?Product
    {
        $hubs = Product::query()
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->where('is_member_hub', true)
            ->where('is_active', true)
            ->with('memberAreaDomain')
            ->orderBy('tenant_id')
            ->get();

        foreach ($hubs as $hub) {
            if ($this->hubIsServedOnMainHost($hub)) {
                return $hub;
            }
        }

        return null;
    }

    private function hubIsServedOnMainHost(Product $hub): bool
    {
        $domain = $hub->memberAreaDomain;
        if ($domain === null) {
            return true;
        }

        if ($domain->type === MemberAreaDomain::TYPE_CUSTOM && $domain->value) {
            return false;
        }

        if ($domain->type === MemberAreaDomain::TYPE_SUBDOMAIN && config('members.subdomain_enabled')) {
            return false;
        }

        return true;
    }

    /**
     * Get the base URL for a product's member area (for links, PWA manifest, etc).
     */
    public function baseUrlForProduct(Product $product): string
    {
        $domain = $product->memberAreaDomain;
        $appUrl = rtrim(config('app.url'), '/');
        $protocol = str_starts_with($appUrl, 'https') ? 'https' : 'http';

        if ($domain) {
            if ($domain->type === MemberAreaDomain::TYPE_CUSTOM && $domain->value) {
                return $protocol.'://'.$domain->value;
            }
            if ($domain->type === MemberAreaDomain::TYPE_SUBDOMAIN && config('members.subdomain_enabled')) {
                $base = config('members.subdomain_base');
                $slug = $domain->value ?: $product->checkout_slug;

                return $protocol.'://'.$slug.'.'.$base;
            }
            if ($domain->type === MemberAreaDomain::TYPE_PATH && $domain->value !== null && $domain->value !== '') {
                return $appUrl.'/m/'.$domain->value;
            }
        }

        return $appUrl.'/m/'.$product->checkout_slug;
    }

    /**
     * Home navigation for member area "Início" links (HUB vs standalone course).
     *
     * @return array{home_url: string, member_area_home_url: string, hub_slug: string|null}
     */
    public function homeNavigationForProduct(Product $product): array
    {
        $homeProduct = $product;
        $hubSlug = null;

        if ($product->isMemberHub()) {
            $hubSlug = $product->checkout_slug;
        } elseif ($product->member_hub_product_id) {
            $hub = $product->relationLoaded('memberHub')
                ? $product->memberHub
                : Product::query()->find($product->member_hub_product_id);

            if ($hub && $hub->isMemberHub()) {
                $homeProduct = $hub;
                $hubSlug = $hub->checkout_slug;
            }
        }

        return [
            'home_url' => '/m/'.$homeProduct->checkout_slug,
            'member_area_home_url' => $this->baseUrlForProduct($homeProduct),
            'hub_slug' => $hubSlug,
        ];
    }

    public function hubMainHostLoginPath(?Product $hub = null): ?string
    {
        $hub = $hub ?? $this->resolveHubForMainHost();
        if ($hub === null) {
            return null;
        }

        return $this->officialMemberAreaLoginPath($hub->tenant_id);
    }

    /**
     * Path oficial de login da área de membros (path/local: /m/{slug}/login; domínio próprio: /login).
     */
    public function officialMemberAreaLoginPath(?int $tenantId = null): ?string
    {
        $hub = $tenantId !== null ? $this->hubForTenant($tenantId) : $this->resolveHubForMainHost();
        if ($hub === null || ! $hub->isMemberHub()) {
            return null;
        }

        if ($this->hubUsesDedicatedHost($hub)) {
            return '/login';
        }

        return '/m/'.strtolower((string) $hub->checkout_slug).'/login';
    }

    /**
     * URL absoluta oficial de login da área de membros (e-mails, redirects).
     */
    public function officialMemberAreaLoginUrl(?int $tenantId = null): ?string
    {
        $hub = $tenantId !== null ? $this->hubForTenant($tenantId) : $this->resolveHubForMainHost();
        $path = $this->officialMemberAreaLoginPath($tenantId);
        if ($hub === null || $path === null) {
            return null;
        }

        if ($this->hubUsesDedicatedHost($hub)) {
            return rtrim($this->baseUrlForProduct($hub), '/').$path;
        }

        return rtrim(config('app.url'), '/').$path;
    }

    public function officialMemberAreaHomePath(?int $tenantId = null): ?string
    {
        $hub = $tenantId !== null ? $this->hubForTenant($tenantId) : $this->resolveHubForMainHost();
        if ($hub === null || ! $hub->isMemberHub()) {
            return null;
        }

        if ($this->hubUsesDedicatedHost($hub)) {
            return '/';
        }

        return '/m/'.strtolower((string) $hub->checkout_slug);
    }

    private function hubForTenant(?int $tenantId): ?Product
    {
        if ($tenantId === null) {
            return null;
        }

        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->where('is_member_hub', true)
            ->first();
    }

    private function hubUsesDedicatedHost(Product $hub): bool
    {
        $domain = $hub->memberAreaDomain;
        if ($domain === null) {
            return false;
        }

        if ($domain->type === MemberAreaDomain::TYPE_CUSTOM && $domain->value) {
            return true;
        }

        return $domain->type === MemberAreaDomain::TYPE_SUBDOMAIN
            && config('members.subdomain_enabled');
    }

    /**
     * Path relativo da tela de login da área de membros (mesmo host da requisição).
     */
    public function memberAreaLoginPath(Request $request, Product $product): string
    {
        if ($product->isMemberHub() && $this->hubIsServedOnMainHost($product)) {
            return $this->officialMemberAreaLoginPath($product->tenant_id)
                ?? '/m/'.strtolower((string) $product->checkout_slug).'/login';
        }

        $resolved = $this->resolve($request);
        $accessType = $request->attributes->get('member_area_access_type')
            ?? ($resolved['access_type'] ?? null);

        if ($accessType === 'hub_root') {
            return $this->officialMemberAreaLoginPath($product->tenant_id)
                ?? '/m/'.strtolower((string) ($resolved['slug'] ?? $product->checkout_slug)).'/login';
        }

        if ($this->usesHostLoginPath($accessType)) {
            return '/login';
        }

        $slug = $resolved['slug'] ?? $product->checkout_slug;
        if ($slug === null || $slug === '') {
            $slug = $product->checkout_slug;
        }

        return '/m/'.strtolower((string) $slug).'/login';
    }

    /**
     * URL absoluta da tela de login (útil para links em e-mail ou redirects cross-host).
     */
    public function memberAreaLoginUrl(Request $request, Product $product): string
    {
        $path = $this->memberAreaLoginPath($request, $product);
        $resolved = $this->resolve($request);
        $accessType = $request->attributes->get('member_area_access_type')
            ?? ($resolved['access_type'] ?? null);

        if ($this->usesHostLoginPath($accessType)) {
            return rtrim($request->getSchemeAndHttpHost(), '/').$path;
        }

        $domain = $product->memberAreaDomain;
        if ($domain && $domain->type === MemberAreaDomain::TYPE_CUSTOM && $domain->value) {
            $appUrl = rtrim(config('app.url'), '/');
            $protocol = str_starts_with($appUrl, 'https') ? 'https' : 'http';

            return $protocol.'://'.$domain->value.$path;
        }

        return rtrim(config('app.url'), '/').$path;
    }

    /**
     * Tenta extrair /m/{slug}/login a partir do Referer (fallback no logout).
     */
    public function memberAreaLoginPathFromReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/m/')) {
            return null;
        }

        if (! preg_match('#^/m/([a-zA-Z0-9\-]{3,64})(?:/|$)#', $path, $matches)) {
            return null;
        }

        return '/m/'.strtolower($matches[1]).'/login';
    }
}
