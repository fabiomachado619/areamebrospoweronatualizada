<?php

namespace App\Http\Controllers;

use App\Models\MemberActivityLog;
use App\Models\Product;
use App\Models\TeamAuditLog;
use App\Models\User;
use App\Support\CaseInsensitiveUserAuth;
use App\Services\MemberAreaPwaAdminService;
use App\Services\MemberAreaResolver;
use App\Services\MemberHubService;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MemberAreaLoginController extends Controller
{
    /**
     * Best-effort activity log for proof/compliance. Must never block login.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function logMemberActivity(Request $request, Product $product, User $user, string $event, array $metadata = []): void
    {
        try {
            MemberActivityLog::create([
                'tenant_id' => $product->tenant_id ?? $user->tenant_id,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'event' => $event,
                'metadata' => $metadata,
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        } catch (\Throwable) {
            // ignore (best-effort)
        }
    }

    public function showLoginForm(Request $request, string $slug): Response|RedirectResponse
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            abort(404, 'Área de membros não encontrada.');
        }
        $slug = $request->route('slug') ?? $request->attributes->get('member_area_slug') ?? $slug;
        if (Auth::check()) {
            return $this->redirectAfterMemberAreaLogin($request, $product, Auth::user());
        }
        $config = $product->member_area_config;
        $loginConfig = $config['login'] ?? [];
        $pwaContext = app(MemberAreaPwaAdminService::class)->resolvePwaContextForProduct($product);
        $pwa = $pwaContext['pwa'];
        $logos = $pwaContext['logos'];
        $pwaName = trim((string) ($pwa['name'] ?? '')) ?: $pwaContext['source']->name;
        $pwaShortName = trim((string) ($pwa['short_name'] ?? '')) ?: $pwaName;
        $pwaThemeColor = (string) ($pwa['theme_color'] ?? '#0ea5e9');
        $pwaFavicon = (string) ($logos['favicon'] ?? '');

        $accessType = $request->attributes->get('member_area_access_type');
        $usesHostLogin = in_array($accessType, ['custom', 'subdomain', 'hub_root'], true);
        $manifestUrl = $usesHostLogin
            ? rtrim($request->getSchemeAndHttpHost(), '/').'/manifest.json'
            : url('/m/'.$slug.'/manifest.json');

        return Inertia::render('MemberAreaApp/Login', [
            'slug' => $slug,
            'product' => [
                'name' => $product->name,
                'pwa_name' => $pwaName,
                'pwa_short_name' => $pwaShortName,
                'pwa_favicon' => $pwaFavicon,
                'pwa_theme_color' => $pwaThemeColor,
                'manifest_url' => $manifestUrl,
                'logo_light' => $loginConfig['logo'] ?? ($config['logos']['logo_light'] ?? ''),
                'logo_dark' => $config['logos']['logo_dark'] ?? '',
                'title' => $loginConfig['title'] ?? 'Área de Membros',
                'subtitle' => $loginConfig['subtitle'] ?? 'Entre com seu e-mail e senha',
                'background_image' => $loginConfig['background_image'] ?? '',
                'background_color' => $loginConfig['background_color'] ?? '#18181b',
                'primary_color' => $loginConfig['primary_color'] ?? '#0ea5e9',
                'login_without_password' => (bool) ($loginConfig['login_without_password'] ?? false),
                'login_without_password_url' => ! empty($loginConfig['login_without_password'])
                    ? ($request->route('slug') !== null ? url('/m/' . $slug . '/login-without-password') : url('/login-without-password'))
                    : null,
            ],
        ]);
    }

    public function login(Request $request, string $slug): RedirectResponse
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            abort(404, 'Área de membros não encontrada.');
        }
        $slug = $request->route('slug') ?? $request->attributes->get('member_area_slug') ?? $slug;
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
        if (! CaseInsensitiveUserAuth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Credenciais inválidas.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return $this->redirectAfterMemberAreaLogin($request, $product, Auth::user());
    }

    public function loginWithoutPassword(Request $request, string $slug): RedirectResponse
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            abort(404, 'Área de membros não encontrada.');
        }
        $slug = $request->route('slug') ?? $request->attributes->get('member_area_slug') ?? $slug;
        $loginConfig = $product->member_area_config['login'] ?? [];
        if (empty($loginConfig['login_without_password'])) {
            return back()->withErrors(['email' => 'Login apenas com e-mail não está habilitado para esta área.'])->onlyInput('email');
        }
        $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($request->input('email')))])->first();
        if (! $user || $user->canAccessPanel()) {
            return back()->withErrors(['email' => 'Credenciais inválidas.'])->onlyInput('email');
        }
        if (! $this->resolveMemberAreaAccessProduct($product)->hasMemberAreaAccess($user)) {
            return back()->withErrors(['email' => 'Credenciais inválidas.'])->onlyInput('email');
        }
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return $this->redirectAfterMemberAreaLogin($request, $product, $user);
    }

    public function magicAccess(Request $request, string $slug): RedirectResponse
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            abort(404, 'Área de membros não encontrada.');
        }
        $slug = $request->route('slug') ?? $request->attributes->get('member_area_slug') ?? $slug;
        $userId = (int) $request->query('u', 0);
        $user = $userId > 0 ? User::find($userId) : null;
        if (! $user || ! $product->hasMemberAreaAccess($user)) {
            return redirect()->route('member-area.login', ['slug' => $slug])->with('error', 'Link inválido ou expirado.');
        }
        Auth::login($user);
        $request->session()->regenerate();

        $this->logMemberActivity($request, $product, $user, 'member_area.magic_access', [
            'mode' => 'path',
            'path' => '/' . ltrim($request->path(), '/'),
        ]);

        return $this->redirectToMemberAreaHome($request, $product);
    }

    public function magicAccessHost(Request $request): RedirectResponse
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            abort(404, 'Área de membros não encontrada.');
        }
        $userId = (int) $request->query('u', 0);
        $user = $userId > 0 ? User::find($userId) : null;
        if (! $user || ! $product->hasMemberAreaAccess($user)) {
            $loginPath = app(MemberAreaResolver::class)->memberAreaLoginPath($request, $product);

            return redirect()->to($loginPath)->with('error', 'Link inválido ou expirado.');
        }
        Auth::login($user);
        $request->session()->regenerate();

        $this->logMemberActivity($request, $product, $user, 'member_area.magic_access', [
            'mode' => 'host',
            'path' => '/' . ltrim($request->path(), '/'),
        ]);

        return $this->redirectToMemberAreaHome($request, $product);
    }

    private function redirectToMemberAreaHome(Request $request, Product $contextProduct): RedirectResponse
    {
        $nav = app(MemberAreaResolver::class)->homeNavigationForProduct($contextProduct);

        if (str_starts_with(trim($request->path(), '/'), 'm/')) {
            return redirect()->intended($nav['home_url']);
        }

        $target = rtrim((string) ($nav['member_area_home_url'] ?? ''), '/');
        if ($target === '') {
            $target = rtrim((string) config('app.url'), '/').($nav['home_url'] ?? '/');
        }

        return redirect()->intended($target);
    }

    /**
     * Após autenticação na tela da área de membros: painel administrativo ou home do aluno.
     */
    private function redirectAfterMemberAreaLogin(Request $request, Product $product, User $user): RedirectResponse
    {
        if ($user->canAccessPanel()) {
            if ($user->tenant_id) {
                try {
                    TeamAuditLog::create([
                        'tenant_id' => $user->tenant_id,
                        'actor_user_id' => $user->id,
                        'action' => 'auth.login',
                        'metadata' => [
                            'method' => 'POST',
                            'path' => '/m/'.($request->route('slug') ?? $request->attributes->get('member_area_slug') ?? '').'/login',
                            'via' => 'member_area_login',
                        ],
                        'ip' => $request->ip(),
                        'user_agent' => (string) $request->userAgent(),
                    ]);
                } catch (\Throwable) {
                    // ignore (best-effort)
                }
            }

            $usesPartnerPanel = app(\App\Services\PartnerAccessService::class)->usesPartnerPanel($user);
            if ($usesPartnerPanel && ! $user->isAdmin() && ! $user->isInfoprodutor()) {
                return redirect()->intended('/parceiro');
            }

            $panelUrl = app(TeamAccessService::class)->defaultPanelUrl($user);

            return redirect()->intended($panelUrl);
        }

        $accessProduct = $this->resolveMemberAreaAccessProduct($product);

        if (! $accessProduct->hasMemberAreaAccess($user)) {
            if ($redirect = $this->redirectToUserTenantHubIfAccessible($user, $accessProduct)) {
                return $redirect;
            }

            if (config('app.debug')) {
                Log::debug('Member area login access denied', [
                    'email' => $user->email,
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'user_tenant_id' => $user->tenant_id,
                    'product_id' => $product->id,
                    'access_product_id' => $accessProduct->id,
                    'hub_tenant_id' => $accessProduct->tenant_id,
                    'is_member_hub' => $accessProduct->isMemberHub(),
                    'has_member_area_access' => false,
                ]);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $userHub = app(MemberHubService::class)->hubForTenant((int) $user->tenant_id);
            $hint = ($userHub instanceof Product && (string) $userHub->id !== (string) $accessProduct->id)
                ? ' Utilize: /m/'.strtolower((string) $userHub->checkout_slug).'/login'
                : '';

            return back()->withErrors(['email' => 'Você não tem acesso a esta área.'.$hint])->onlyInput('email');
        }

        return $this->redirectToMemberAreaHome($request, $accessProduct);
    }

    /**
     * Aluno autenticado no HUB errado (outro tenant) → redireciona para o HUB do seu tenant.
     */
    private function redirectToUserTenantHubIfAccessible(User $user, Product $currentAccessProduct): ?RedirectResponse
    {
        if (! $user->isAluno() || (int) $user->tenant_id <= 0) {
            return null;
        }

        $userHub = app(MemberHubService::class)->hubForTenant((int) $user->tenant_id);
        if (! $userHub instanceof Product) {
            return null;
        }

        if ((string) $userHub->id === (string) $currentAccessProduct->id) {
            return null;
        }

        if (! $userHub->hasMemberAreaAccess($user)) {
            return null;
        }

        $slug = strtolower((string) $userHub->checkout_slug);

        return redirect()->to('/m/'.$slug)
            ->with('success', 'Você foi direcionado para a sua área de membros.');
    }

    /**
     * Produto usado na validação de acesso (HUB do tenant quando aplicável).
     */
    private function resolveMemberAreaAccessProduct(Product $product): Product
    {
        if ($product->isMemberHub()) {
            return $product;
        }

        $hub = app(MemberHubService::class)->hubForTenant($product->tenant_id);
        if ($hub instanceof Product) {
            if ((string) $hub->id === (string) $product->id) {
                return $hub;
            }

            if ($product->member_hub_product_id
                && (string) $product->member_hub_product_id === (string) $hub->id) {
                return $hub;
            }

            if (strtolower((string) $hub->checkout_slug) === strtolower((string) $product->checkout_slug)) {
                return $hub;
            }
        }

        if ($product->member_hub_product_id) {
            $linkedHub = Product::query()->find($product->member_hub_product_id);
            if ($linkedHub instanceof Product && $linkedHub->isMemberHub()) {
                return $linkedHub;
            }
        }

        return $product;
    }
}
