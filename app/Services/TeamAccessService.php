<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;

class TeamAccessService
{
    /**
     * @return array<string, bool>
     */
    public function permissionsFor(User $user): array
    {
        if ($user->isAdmin() || $user->isInfoprodutor()) {
            return $this->allPermissions();
        }

        if (! $user->isTeam()) {
            return [];
        }

        $raw = $user->teamRole?->permissions;
        if (! is_array($raw)) {
            return [];
        }

        $perms = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $perms[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $perms;
    }

    public function can(User $user, string $permission): bool
    {
        if ($user->isAdmin() || $user->isInfoprodutor()) {
            return true;
        }

        if (! $user->isTeam()) {
            return false;
        }

        $perms = $this->permissionsFor($user);

        return ! empty($perms[$permission]);
    }

    /**
     * Primeira rota do painel que o usuário pode acessar (equipe sem dashboard.view, etc.).
     */
    public function defaultPanelUrl(User $user): string
    {
        if ($user->isAdmin() || $user->isInfoprodutor()) {
            return '/dashboard';
        }

        if (app(PartnerAccessService::class)->usesPartnerPanel($user)) {
            return '/parceiro';
        }

        if ($user->isTeam()) {
            foreach ($this->defaultPanelRoutes() as $permission => $url) {
                if ($this->can($user, $permission)) {
                    return $url;
                }
            }
        }

        return '/dashboard';
    }

    /**
     * @return array<string, string>
     */
    private function defaultPanelRoutes(): array
    {
        return [
            'dashboard.view' => '/dashboard',
            'vendas.view' => '/vendas',
            'produtos.view' => '/produtos',
            'relatorios.view' => '/relatorios',
            'reembolsos.view' => '/reembolsos',
            'financeiro.view' => '/financeiro',
            'integracoes.view' => '/integracoes',
            'email_marketing.view' => '/email-marketing',
            'api_pagamentos.view' => '/aplicacoes-api',
            'configuracoes.view' => '/configuracoes',
            'equipe.manage' => '/usuarios/equipe',
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedProductIdsFor(User $user): array
    {
        if ($user->isAdmin() || $user->isInfoprodutor()) {
            $tenantId = $user->tenant_id;
            if ($tenantId === null) {
                return [];
            }
            return Product::forTenant($tenantId)->pluck('id')->all();
        }

        if (! $user->isTeam()) {
            return [];
        }

        if ($this->can($user, 'produtos.view')) {
            $tenantId = $user->tenant_id;
            if ($tenantId === null) {
                return [];
            }

            return Product::forTenant($tenantId)->pluck('id')->all();
        }

        return $user->teamRole?->products()->pluck('products.id')->all() ?? [];
    }

    /**
     * @return array<string, bool>
     */
    public function allPermissions(): array
    {
        return [
            'dashboard.view' => true,
            'vendas.view' => true,
            'reembolsos.view' => true,
            'reembolsos.manage' => true,
            'produtos.view' => true,
            'relatorios.view' => true,
            'integracoes.view' => true,
            'email_marketing.view' => true,
            'api_pagamentos.view' => true,
            'configuracoes.view' => true,
            'equipe.manage' => true,
            'afiliados.manage' => true,
            'coproducao.manage' => true,
            'financeiro.view' => true,
            'financeiro.manage' => true,
        ];
    }
}

