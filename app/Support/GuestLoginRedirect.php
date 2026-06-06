<?php

namespace App\Support;

use App\Services\MemberAreaResolver;
use Illuminate\Http\Request;

class GuestLoginRedirect
{
    /**
     * Prefixos de rotas do painel administrativo (redirecionam para /admin/login quando há HUB na raiz).
     *
     * @var list<string>
     */
    private const ADMIN_PATH_PREFIXES = [
        'admin',
        'dashboard',
        'parceiro',
        'area-membros-admin',
        'produtos',
        'vendas',
        'reembolsos',
        'cupons',
        'assinaturas',
        'alunos',
        'member-area-admin',
        'relatorios',
        'settings',
        'profile',
        'integrations',
        'plugins',
        'usuarios',
        'email-marketing',
        'api-applications',
        'conquistas',
        'painel',
        'cloud',
        'checkout/builder',
        'criar-admin',
        'esqueci-senha',
        'redefinir-senha',
    ];

    public static function url(Request $request): string
    {
        if (self::shouldUseAdminLogin($request)) {
            return url('/admin/login');
        }

        $hubLogin = app(MemberAreaResolver::class)->hubMainHostLoginPath();
        if ($hubLogin !== null) {
            return url($hubLogin);
        }

        return url('/login');
    }

    public static function shouldUseAdminLogin(Request $request): bool
    {
        $resolver = app(MemberAreaResolver::class);

        if (! $resolver->hasHubOnMainHost()) {
            return false;
        }

        $path = trim($request->path(), '/');

        if ($path === '' || $path === 'login') {
            return false;
        }

        foreach (self::ADMIN_PATH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
