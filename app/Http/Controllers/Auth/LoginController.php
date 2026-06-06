<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\TeamAuditLog;
use App\Services\MemberAreaResolver;
use App\Services\TeamAccessService;
use App\Support\CaseInsensitiveUserAuth;
use App\Support\DockerSetupState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __construct(
        protected MemberAreaResolver $memberAreaResolver,
    ) {}

    /**
     * Exibe o login da plataforma ou, se o host for de área de membros (subdomínio/domínio próprio/HUB na raiz),
     * delega para o login da área de membros do produto.
     */
    public function showLoginForm(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectToOfficialHubLoginWhenConfigured()) {
            return $redirect;
        }

        if ($delegated = $this->delegateMemberAreaLoginForm($request)) {
            return $delegated;
        }

        return $this->renderPlatformLoginForm($request, '/login');
    }

    public function showAdminLoginForm(Request $request): Response|RedirectResponse
    {
        if (Auth::check() && Auth::user()->canAccessPanel()) {
            $user = Auth::user();
            $usesPartnerPanel = app(\App\Services\PartnerAccessService::class)->usesPartnerPanel($user);
            if ($usesPartnerPanel && ! $user->isAdmin() && ! $user->isInfoprodutor()) {
                return redirect()->intended('/parceiro');
            }

            return redirect()->intended('/dashboard');
        }

        return $this->renderPlatformLoginForm($request, '/admin/login');
    }

    public function login(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectToOfficialHubLoginWhenConfigured(307)) {
            return $redirect;
        }

        if ($delegated = $this->delegateMemberAreaLogin($request)) {
            return $delegated;
        }

        return $this->attemptPlatformLogin($request, '/login');
    }

    public function adminLogin(Request $request): RedirectResponse
    {
        return $this->attemptPlatformLogin($request, '/admin/login');
    }

    public function logout(Request $request): RedirectResponse
    {
        $resolved = $this->memberAreaResolver->resolve($request);
        $memberAreaLoginPath = null;
        if ($resolved) {
            $memberAreaLoginPath = $this->memberAreaResolver->memberAreaLoginPath($request, $resolved['product']);
        }

        $user = Auth::user();
        if ($user && $user->tenant_id && $user->canAccessPanel()) {
            TeamAuditLog::create([
                'tenant_id' => $user->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'auth.logout',
                'metadata' => [
                    'method' => $request->method(),
                    'path' => '/logout',
                ],
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $to = $request->query('redirect');
        if (is_string($to) && $this->isSafeMemberAreaLoginRedirect($to)) {
            return redirect($to);
        }

        if ($memberAreaLoginPath !== null) {
            return redirect($memberAreaLoginPath);
        }

        $fromReferer = $this->memberAreaResolver->memberAreaLoginPathFromReferer($request);
        if ($fromReferer !== null && $this->isSafeMemberAreaLoginRedirect($fromReferer)) {
            return redirect($fromReferer);
        }

        return redirect('/');
    }

    private function redirectToOfficialHubLoginWhenConfigured(int $status = 302): ?RedirectResponse
    {
        if (! $this->memberAreaResolver->hasHubOnMainHost()) {
            return null;
        }

        $path = $this->memberAreaResolver->officialMemberAreaLoginPath(null);
        if ($path === null || ! str_starts_with($path, '/m/')) {
            return null;
        }

        return redirect()->to($path, $status);
    }

    private function delegateMemberAreaLoginForm(Request $request): Response|RedirectResponse|null
    {
        $resolved = $this->memberAreaResolver->resolve($request);
        if (! $resolved || ! $this->memberAreaResolver->usesHostLoginPath($resolved['access_type'])) {
            return null;
        }

        if ($resolved['access_type'] === 'hub_root') {
            $loginPath = $this->memberAreaResolver->hubMainHostLoginPath($resolved['product']);
            if ($loginPath !== null) {
                return redirect()->to($loginPath);
            }
        }

        $request->attributes->set('member_area_product', $resolved['product']);
        $request->attributes->set('member_area_access_type', $resolved['access_type']);
        $request->attributes->set('member_area_slug', $resolved['slug']);

        return app()->call(\App\Http\Controllers\MemberAreaLoginController::class.'@showLoginForm', [
            'request' => $request,
            'slug' => $resolved['slug'],
        ]);
    }

    private function delegateMemberAreaLogin(Request $request): ?RedirectResponse
    {
        $resolved = $this->memberAreaResolver->resolve($request);
        if (! $resolved || ! $this->memberAreaResolver->usesHostLoginPath($resolved['access_type'])) {
            return null;
        }

        if ($resolved['access_type'] === 'hub_root') {
            $loginPath = $this->memberAreaResolver->hubMainHostLoginPath($resolved['product']);
            if ($loginPath !== null) {
                return redirect()->to($loginPath, 307);
            }
        }

        $request->attributes->set('member_area_product', $resolved['product']);
        $request->attributes->set('member_area_access_type', $resolved['access_type']);
        $request->attributes->set('member_area_slug', $resolved['slug']);

        return app()->call(\App\Http\Controllers\MemberAreaLoginController::class.'@login', [
            'request' => $request,
            'slug' => $resolved['slug'],
        ]);
    }

    private function renderPlatformLoginForm(Request $request, string $loginPath): Response|RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        if (User::count() === 0) {
            return redirect()->route('criar-admin');
        }

        $redirect = $request->query('redirect');
        $safeRedirect = is_string($redirect) && $this->isSafeAffiliateEnrollRedirect($redirect)
            ? $redirect
            : null;

        return Inertia::render('Auth/Login', [
            'redirect' => $safeRedirect,
            'loginUrl' => $loginPath,
        ]);
    }

    private function attemptPlatformLogin(Request $request, string $loginPath): RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (CaseInsensitiveUserAuth::attempt($credentials, (bool) $request->boolean('remember'))) {
            $request->session()->regenerate();
            $user = Auth::user();
            if ($user && $user->tenant_id && $user->canAccessPanel()) {
                TeamAuditLog::create([
                    'tenant_id' => $user->tenant_id,
                    'actor_user_id' => $user->id,
                    'action' => 'auth.login',
                    'metadata' => [
                        'method' => 'POST',
                        'path' => $loginPath,
                    ],
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
            }
            $affiliateRedirect = $request->input('redirect') ?? $request->query('redirect');
            if (is_string($affiliateRedirect) && $this->isSafeAffiliateEnrollRedirect($affiliateRedirect)) {
                return redirect($affiliateRedirect);
            }

            if ($user->canAccessPanel()) {
                $usesPartnerPanel = app(\App\Services\PartnerAccessService::class)->usesPartnerPanel($user);
                if ($usesPartnerPanel && ! $user->isAdmin() && ! $user->isInfoprodutor()) {
                    return redirect()->intended('/parceiro');
                }

                return redirect()->intended(app(TeamAccessService::class)->defaultPanelUrl($user));
            }

            return redirect()->intended('/area-membros');
        }

        return back()->withErrors([
            'email' => 'Credenciais inválidas.',
        ])->onlyInput('email');
    }

    /**
     * Evita open redirect: só paths de login da área de membros (/m/{slug}/login ou /login em host dedicado).
     */
    private function isSafeMemberAreaLoginRedirect(string $path): bool
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        if (str_contains($path, '..')) {
            return false;
        }

        return (bool) preg_match('#^/m/[a-zA-Z0-9\-]{3,64}/login$#', $path)
            || $path === '/login';
    }

    private function isSafeAffiliateEnrollRedirect(string $path): bool
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        if (str_contains($path, '..')) {
            return false;
        }

        return (bool) preg_match('#^/afiliar/[a-z0-9\-]+/cadastro$#', $path)
            || (bool) preg_match('#^/convite/co-producao/[a-zA-Z0-9]+/cadastro$#', $path);
    }
}
