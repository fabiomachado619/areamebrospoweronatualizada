<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    CircleDollarSign,
    ShoppingCart,
    Wallet,
    RotateCcw,
    Package,
    Repeat,
    Users,
    BarChart3,
    Puzzle,
    Cable,
    Settings,
    PanelRightOpen,
    X,
    Plug,
    Wrench,
    FileCode,
    Box,
    Mail,
    CodeXml,
} from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';
import { isNavItemActive } from '@/lib/nav';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import PwaInstallButton from '@/components/layout/PwaInstallButton.vue';
import BetaBadge from '@/components/ui/BetaBadge.vue';

const page = usePage();
const { isExpanded, isMobileOpen, toggleSidebar, isMobile } = useSidebar();

const showText = () => isExpanded.value || isMobileOpen.value;

const appSettings = () => page.props.appSettings ?? {};
const appName = () => appSettings().app_name || 'Infoprodutor';
const hasLogoFull = () => !!(appSettings().app_logo || appSettings().app_logo_dark);
const hasLogoIcon = () => !!(appSettings().app_logo_icon || appSettings().app_logo_icon_dark);

const iconMap = {
    Puzzle,
    Plug,
    Wrench,
    Settings,
    FileCode,
    Box,
    LayoutDashboard,
    Package,
    Repeat,
    Users,
    BarChart3,
    Mail,
    CodeXml,
};

const pluginNavItems = computed(() => {
    const raw = page.props.pluginNavItems ?? [];
    return raw.map((item) => ({
        name: item.name,
        href: item.href,
        icon: item.icon && iconMap[item.icon] ? iconMap[item.icon] : Puzzle,
    }));
});

const isAdmin = computed(() => page.props.auth?.user?.role === 'admin');
const perms = computed(() => page.props.auth?.permissions ?? {});
const canView = (key) => {
    // Admin/infoprodutor têm acesso total via backend; no front apenas para ocultar itens do menu em users de equipe.
    const role = page.props.auth?.user?.role;
    if (role === 'admin' || role === 'infoprodutor') return true;
    return !!perms.value?.[key];
};

const usesPartnerPanel = computed(() => !!page.props.auth?.uses_partner_panel);

const navItems = computed(() => {
    const items = [];
    const role = page.props.auth?.user?.role;
    const isProducer = role === 'admin' || role === 'infoprodutor';
    const partnerNavOnly = usesPartnerPanel.value && (role === 'coprodutor' || role === 'afiliado' || role === 'team');

    if (partnerNavOnly) {
        items.push(
            { name: 'Dashboard', href: '/parceiro', icon: LayoutDashboard },
            { name: 'Meus produtos', href: '/parceiro/produtos', icon: Package },
            { name: 'Vendas', href: '/parceiro/vendas', icon: ShoppingCart },
            { name: 'Financeiro', href: '/parceiro/financeiro', icon: Wallet, beta: true },
        );
        return items;
    }

    const groupOperacao = [];
    if (canView('dashboard.view')) groupOperacao.push({ name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard });
    if (canView('vendas.view')) groupOperacao.push({ name: 'Vendas', href: '/vendas', icon: CircleDollarSign });
    if (canView('produtos.view')) groupOperacao.push({ name: 'Produtos', href: '/produtos', icon: Package });
    if (canView('relatorios.view')) groupOperacao.push({ name: 'Relatórios', href: '/relatorios', icon: BarChart3 });
    if (canView('reembolsos.view')) groupOperacao.push({ name: 'Reembolsos', href: '/reembolsos', icon: RotateCcw });
    if (canView('financeiro.view')) {
        groupOperacao.push({ name: 'Financeiro', href: '/financeiro', icon: Wallet, beta: true });
    }

    const groupFerramentas = [];
    if (canView('integracoes.view')) groupFerramentas.push({ name: 'Integrações', href: '/integracoes', icon: Cable });
    if (isProducer && pluginNavItems.value.length) {
        groupFerramentas.push(...pluginNavItems.value);
    }
    if (canView('email_marketing.view')) {
        groupFerramentas.push({ name: 'E-mail Marketing', href: '/email-marketing', icon: Mail });
    }
    if (isAdmin.value) {
        groupFerramentas.push({ name: 'Usuários e equipe', href: '/usuarios', icon: Users });
    } else if (role === 'infoprodutor' || canView('equipe.manage')) {
        groupFerramentas.push({ name: 'Usuários e equipe', href: '/usuarios/equipe', icon: Users });
    }
    if (canView('api_pagamentos.view')) {
        groupFerramentas.push({ name: 'API Pagamentos', href: '/aplicacoes-api', icon: CodeXml });
    }

    const groupSistema = [];
    if (canView('configuracoes.view')) groupSistema.push({ name: 'Configurações', href: '/configuracoes', icon: Settings });
    if (isProducer) groupSistema.push({ name: 'Plugins', href: '/gerenciar-plugins', icon: Plug });

    for (const group of [groupOperacao, groupFerramentas, groupSistema]) {
        if (!group.length) continue;
        if (items.length) items.push({ separator: true });
        items.push(...group);
    }

    return items;
});

function isActive(href) {
    return isNavItemActive(page.url, href);
}

/** Prefetch hover + mousedown para navegação do painel (Inertia v2). */
const panelNavPrefetch = ['hover', 'click'];

const showCollapsedTooltip = computed(() => !isMobile.value && !isExpanded.value && !isMobileOpen.value);

const flyout = ref({
    visible: false,
    label: '',
    beta: false,
    top: 0,
    left: 0,
});

function openFlyout(event, item) {
    if (!showCollapsedTooltip.value || item.separator) return;
    const el = event.currentTarget;
    const rect = el.getBoundingClientRect();
    flyout.value = {
        visible: true,
        label: item.name,
        beta: !!item.beta,
        top: rect.top + rect.height / 2,
        left: rect.right + 10,
    };
}

function closeFlyout() {
    flyout.value.visible = false;
}
</script>

<template>
    <aside
        :class="[
            'fixed left-0 top-0 z-[99999] flex h-screen flex-col rounded-r-2xl bg-zinc-100 transition-all duration-300 ease-in-out dark:bg-zinc-900',
            {
                'w-[260px] translate-x-0': isMobileOpen,
                '-translate-x-full': !isMobileOpen,
                'lg:translate-x-0': true,
                'lg:w-[260px]': isExpanded || isMobileOpen,
                'lg:w-[72px]': !isExpanded && !isMobileOpen,
            },
        ]"
    >
        <div
            :class="[
                'flex items-center px-4 py-5',
                showText() ? 'justify-between gap-2' : 'lg:justify-center',
            ]"
        >
            <!-- Expandido: logo + botão recolher -->
            <template v-if="showText()">
                <Link
                    href="/dashboard"
                    :prefetch="panelNavPrefetch"
                    class="flex min-w-0 flex-1 items-center gap-2 overflow-hidden text-zinc-900 dark:text-white"
                >
                    <template v-if="hasLogoFull()">
                        <div class="flex h-9 w-[148px] shrink-0 items-center justify-start">
                            <img
                                v-if="appSettings().app_logo"
                                :src="appSettings().app_logo"
                                :alt="appName()"
                                class="max-h-9 max-w-[148px] object-contain object-left"
                                :class="appSettings().app_logo_dark ? 'dark:hidden' : ''"
                            />
                            <img
                                v-if="appSettings().app_logo_dark"
                                :src="appSettings().app_logo_dark"
                                :alt="appName()"
                                class="hidden max-h-9 max-w-[148px] object-contain object-left dark:block"
                            />
                        </div>
                    </template>
                    <span v-else class="truncate text-lg font-semibold">{{ appName() }}</span>
                </Link>
                <button
                    type="button"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    :aria-label="isMobile ? 'Fechar menu' : 'Recolher menu'"
                    @click="toggleSidebar"
                >
                    <X v-if="isMobile" class="h-5 w-5" aria-hidden="true" />
                    <PanelRightOpen v-else class="h-5 w-5" aria-hidden="true" />
                </button>
            </template>
            <!-- Recolhido: só logo (clique abre) -->
            <button
                v-else
                type="button"
                class="flex h-10 w-10 items-center justify-center rounded-lg text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                aria-label="Expandir menu"
                @click="toggleSidebar"
            >
                <template v-if="hasLogoIcon()">
                    <div class="flex h-8 w-8 items-center justify-center">
                        <img
                            v-if="appSettings().app_logo_icon"
                            :src="appSettings().app_logo_icon"
                            :alt="appName()"
                            class="max-h-8 max-w-8 object-contain"
                            :class="appSettings().app_logo_icon_dark ? 'dark:hidden' : ''"
                        />
                        <img
                            v-if="appSettings().app_logo_icon_dark"
                            :src="appSettings().app_logo_icon_dark"
                            :alt="appName()"
                            class="hidden max-h-8 max-w-8 object-contain dark:block"
                        />
                    </div>
                </template>
                <span v-else class="flex h-8 w-8 items-center justify-center rounded-lg bg-zinc-200 text-sm font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                    {{ appName().charAt(0) }}
                </span>
            </button>
        </div>
        <hr class="mx-3 border-t border-zinc-200 dark:border-zinc-700" />
        <nav class="flex-1 overflow-y-auto no-scrollbar px-3 py-4" @mouseleave="closeFlyout">
            <ul class="flex flex-col gap-1">
                <template v-for="(item, index) in navItems" :key="item.separator ? `sep-${index}` : (item.href ?? index)">
                    <li v-if="item.separator">
                        <hr class="my-2 border-t border-zinc-200 dark:border-zinc-700" />
                    </li>
                    <li v-else>
                        <Link
                            :href="item.href"
                            :prefetch="panelNavPrefetch"
                            :aria-label="showCollapsedTooltip ? item.name : undefined"
                            :class="[
                                'menu-item',
                                showText() ? 'justify-start' : 'lg:justify-center',
                                isActive(item.href) ? 'menu-item-active' : 'menu-item-inactive',
                            ]"
                            @mouseenter="openFlyout($event, item)"
                            @focus="openFlyout($event, item)"
                            @blur="closeFlyout"
                        >
                            <span
                                :class="[
                                    'shrink-0',
                                    isActive(item.href) ? 'menu-item-icon-active' : 'menu-item-icon-inactive',
                                ]"
                            >
                                <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <span
                                v-if="showText()"
                                class="flex min-w-0 flex-1 items-center gap-1.5"
                            >
                                <span class="truncate">{{ item.name }}</span>
                                <BetaBadge v-if="item.beta" size="xs" />
                            </span>
                        </Link>
                    </li>
                </template>
            </ul>
        </nav>
        <!-- Mobile: Instalar App + Conquistas (parte inferior) -->
        <div v-if="isMobile && showText()" class="space-y-2 px-4 py-4 lg:hidden">
            <PwaInstallButton />
            <ConquistasWidget variant="sidebar" />
        </div>

        <Teleport to="body">
            <div
                v-if="flyout.visible"
                class="pointer-events-none fixed z-[100001] flex -translate-y-1/2 items-center gap-1.5 whitespace-nowrap rounded-xl border border-zinc-200/90 bg-white px-3 py-2 text-sm font-medium text-zinc-900 shadow-[0_8px_30px_rgba(0,0,0,0.12)] dark:border-zinc-600/90 dark:bg-zinc-800 dark:text-zinc-50 dark:shadow-black/40"
                :style="{ top: `${flyout.top}px`, left: `${flyout.left}px` }"
                role="tooltip"
            >
                <span
                    class="absolute right-full top-1/2 -translate-y-1/2 border-[6px] border-transparent border-r-white dark:border-r-zinc-800"
                    aria-hidden="true"
                />
                <span>{{ flyout.label }}</span>
                <BetaBadge v-if="flyout.beta" size="xs" />
            </div>
        </Teleport>
    </aside>
</template>
