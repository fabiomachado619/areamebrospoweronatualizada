<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import { Repeat, TrendingUp, AlertTriangle, XCircle, Copy, Ban } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    stats: { type: Object, default: () => ({ ativas: 0, past_due: 0, canceladas: 0, clientes: 0, mrr: 0 }) },
    statusFilter: { type: String, default: 'all' },
    assinaturas: { type: [Array, Object], default: () => [] },
});

const assinaturasList = computed(() => props.assinaturas?.data ?? (Array.isArray(props.assinaturas) ? props.assinaturas : []));

const tabs = [
    { id: 'all', label: 'Todas' },
    { id: 'active', label: 'Ativas' },
    { id: 'past_due', label: 'Em atraso' },
    { id: 'cancelled', label: 'Canceladas' },
];

const cancellingId = ref(null);
const copiedId = ref(null);

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function displayStatus(s) {
    return s.effective_status ?? s.status;
}

function statusBadgeClass(status) {
    const map = {
        active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
        past_due: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        cancelled: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300',
    };
    return map[status] ?? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300';
}

function statusBadgeLabel(status) {
    const map = {
        active: 'Ativa',
        past_due: 'Em atraso',
        cancelled: 'Cancelada',
    };
    return map[status] ?? status ?? '–';
}

function setStatusFilter(status) {
    router.get(route('assinaturas.index'), { status }, { preserveState: true, replace: true });
}

async function copyRenewalLink(s) {
    if (!s.renewal_url) return;
    try {
        await navigator.clipboard.writeText(s.renewal_url);
        copiedId.value = s.id;
        setTimeout(() => {
            if (copiedId.value === s.id) copiedId.value = null;
        }, 2000);
    } catch {
        /* ignore */
    }
}

function cancelSubscription(s, revokeNow = false) {
    const msg = revokeNow
        ? 'Cancelar agora e revogar o acesso imediatamente?'
        : 'Cancelar assinatura? O cliente mantém acesso até o fim da carência (se configurada).';
    if (!window.confirm(msg)) return;

    cancellingId.value = s.id;
    router.post(
        route('assinaturas.cancel', s.id),
        { revoke_access_now: revokeNow },
        {
            preserveScroll: true,
            onFinish: () => {
                cancellingId.value = null;
            },
        }
    );
}
</script>

<template>
    <div class="space-y-6">
        <VendasTabs />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="panel-card-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                        <Repeat class="h-5 w-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Ativas</p>
                        <p class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ stats.ativas }}</p>
                    </div>
                </div>
            </div>
            <div class="panel-card-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <AlertTriangle class="h-5 w-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Em atraso</p>
                        <p class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ stats.past_due ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="panel-card-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-zinc-500/10 text-zinc-600 dark:text-zinc-400">
                        <XCircle class="h-5 w-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Canceladas</p>
                        <p class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ stats.canceladas ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="panel-card-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                        <TrendingUp class="h-5 w-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">MRR (ativas)</p>
                        <p class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ formatBRL(stats.mrr) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel-table">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Assinaturas</h2>
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                    Lembretes diários por e-mail conforme a configuração de cada produto. Renovação via link enviado ao cliente.
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                            statusFilter === tab.id
                                ? 'bg-[var(--color-primary)] text-white'
                                : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700',
                        ]"
                        @click="setStatusFilter(tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <div v-if="assinaturasList.length > 0" class="sm:hidden p-4">
                <div class="space-y-3">
                    <div
                        v-for="s in assinaturasList"
                        :key="s.id"
                        class="panel-card-sm/60"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold leading-snug text-zinc-900 dark:text-white">
                                    {{ s.user?.name || '—' }}
                                </p>
                                <p class="mt-0.5 break-words text-xs leading-snug text-zinc-500 dark:text-zinc-400">
                                    {{ s.user?.email || '—' }}
                                </p>
                            </div>
                            <span
                                :class="[
                                    'inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium',
                                    statusBadgeClass(displayStatus(s)),
                                ]"
                            >
                                {{ statusBadgeLabel(displayStatus(s)) }}
                            </span>
                        </div>

                        <div class="mt-4 rounded-lg bg-zinc-50/60 p-3 dark:bg-zinc-900/30">
                            <div class="space-y-2 text-sm">
                                <p><span class="text-zinc-500">Produto:</span> {{ s.product?.name || '—' }}</p>
                                <p><span class="text-zinc-500">Plano:</span> {{ s.plan?.name || '—' }}</p>
                                <p><span class="text-zinc-500">Vence em:</span> {{ s.current_period_end || '—' }}</p>
                                <p v-if="s.access_until"><span class="text-zinc-500">Acesso até:</span> {{ s.access_until }}</p>
                                <p v-if="s.renewable_until"><span class="text-zinc-500">Renovável até:</span> {{ s.renewable_until }}</p>
                            </div>
                        </div>

                        <div v-if="displayStatus(s) !== 'cancelled'" class="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 dark:border-zinc-600 dark:text-zinc-300"
                                @click="copyRenewalLink(s)"
                            >
                                <Copy class="h-3.5 w-3.5" />
                                {{ copiedId === s.id ? 'Copiado' : 'Link renovação' }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-700 dark:border-red-900/50 dark:text-red-400"
                                :disabled="cancellingId === s.id"
                                @click="cancelSubscription(s, false)"
                            >
                                <Ban class="h-3.5 w-3.5" />
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="assinaturasList.length > 0" class="hidden overflow-x-auto sm:block">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50/80 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">Cliente</th>
                            <th class="px-4 py-3 font-medium">Produto / Plano</th>
                            <th class="px-4 py-3 font-medium">Período</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr v-for="s in assinaturasList" :key="s.id" class="text-zinc-700 dark:text-zinc-300">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ s.user?.name || '—' }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ s.user?.email }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ s.product?.name || '—' }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ s.plan?.name }} · {{ s.plan?.interval_label || s.plan?.interval }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p>Vence: {{ s.current_period_end || '—' }}</p>
                                <p v-if="s.access_until" class="text-xs text-zinc-500">Acesso até {{ s.access_until }}</p>
                                <p v-if="s.renewable_until" class="text-xs text-zinc-500">Renovável até {{ s.renewable_until }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    :class="[
                                        'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
                                        statusBadgeClass(displayStatus(s)),
                                    ]"
                                >
                                    {{ statusBadgeLabel(displayStatus(s)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div v-if="displayStatus(s) !== 'cancelled'" class="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-2 py-1 text-xs font-medium hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800"
                                        @click="copyRenewalLink(s)"
                                    >
                                        <Copy class="h-3.5 w-3.5" />
                                        {{ copiedId === s.id ? 'Copiado' : 'Link' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-lg border border-red-200 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/30"
                                        :disabled="cancellingId === s.id"
                                        @click="cancelSubscription(s, false)"
                                    >
                                        <Ban class="h-3.5 w-3.5" />
                                        Cancelar
                                    </button>
                                </div>
                                <span v-else class="text-xs text-zinc-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="assinaturas?.links?.length > 3"
                class="flex items-center justify-center gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700"
                aria-label="Paginação"
            >
                <a
                    v-for="link in assinaturas.links"
                    :key="link.label"
                    :href="link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-disabled="!link.url"
                    :class="[
                        'relative inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                        link.active
                            ? 'z-10 bg-[var(--color-primary)] text-white'
                            : link.url
                              ? 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                              : 'cursor-not-allowed text-zinc-400 dark:text-zinc-500',
                    ]"
                    v-html="link.label"
                    @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
                />
            </nav>
            <div v-else-if="assinaturasList.length === 0" class="p-8 text-center">
                <Repeat class="mx-auto h-14 w-14 text-zinc-300 dark:text-zinc-600" />
                <p class="mt-3 font-medium text-zinc-600 dark:text-zinc-400">Nenhuma assinatura encontrada</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-500">
                    Assinaturas de produtos recorrentes aparecem aqui após a primeira venda.
                </p>
            </div>
        </div>
    </div>
</template>
