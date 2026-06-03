<script setup>
import { computed } from 'vue';
import {
    CreditCard,
    QrCode,
    Barcode,
    Smartphone,
    Wallet,
} from 'lucide-vue-next';
import { formatBRL } from '@/composables/useTrackingPanel';

const props = defineProps({
    methods: { type: Array, default: () => [] },
    valuesVisible: { type: Boolean, default: true },
});

const METHOD_STYLES = {
    pix: {
        icon: QrCode,
        bar: 'from-teal-500 to-emerald-400',
        chip: 'bg-teal-500/12 text-teal-700 dark:text-teal-300',
        ring: 'ring-teal-500/20',
    },
    pix_auto: {
        icon: QrCode,
        bar: 'from-teal-500 to-emerald-400',
        chip: 'bg-teal-500/12 text-teal-700 dark:text-teal-300',
        ring: 'ring-teal-500/20',
    },
    card: {
        icon: CreditCard,
        bar: 'from-blue-500 to-indigo-400',
        chip: 'bg-blue-500/12 text-blue-700 dark:text-blue-300',
        ring: 'ring-blue-500/20',
    },
    boleto: {
        icon: Barcode,
        bar: 'from-amber-500 to-orange-400',
        chip: 'bg-amber-500/12 text-amber-800 dark:text-amber-300',
        ring: 'ring-amber-500/20',
    },
    apple_pay: {
        icon: Smartphone,
        bar: 'from-zinc-600 to-zinc-800',
        chip: 'bg-zinc-500/12 text-zinc-700 dark:text-zinc-300',
        ring: 'ring-zinc-500/20',
    },
    google_pay: {
        icon: Smartphone,
        bar: 'from-violet-500 to-purple-400',
        chip: 'bg-violet-500/12 text-violet-700 dark:text-violet-300',
        ring: 'ring-violet-500/20',
    },
};

const defaultStyle = {
    icon: Wallet,
    bar: 'from-[var(--color-primary)] to-[var(--color-primary)]/70',
    chip: 'bg-[var(--color-primary)]/12 text-[var(--color-primary)]',
    ring: 'ring-[var(--color-primary)]/20',
};

const summary = computed(() => {
    const items = props.methods.map((m) => {
        const style = METHOD_STYLES[m.metodo] ?? defaultStyle;
        return { ...m, style };
    });
    const totalAmount = items.reduce((s, m) => s + (m.total ?? 0), 0);
    const totalQty = items.reduce((s, m) => s + (m.quantidade ?? 0), 0);

    return {
        totalAmount,
        totalQty,
        items: items.map((m) => ({
            ...m,
            percent: totalAmount > 0 ? Math.round((m.total / totalAmount) * 1000) / 10 : 0,
        })),
    };
});

const leader = computed(() => summary.value.items[0] ?? null);

function displayAmount(value) {
    return props.valuesVisible ? formatBRL(value) : '••••••';
}

function displayCount(value) {
    return props.valuesVisible ? String(value) : '—';
}
</script>

<template>
    <div class="panel-card-md flex h-full flex-col">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm">
                        <CreditCard class="h-4 w-4" />
                    </div>
                    Métodos de pagamento
                </h2>
                <p v-if="summary.items.length" class="mt-1 text-xs text-zinc-500">
                    {{ displayCount(summary.totalQty) }} vendas · {{ displayAmount(summary.totalAmount) }}
                </p>
            </div>
            <div
                v-if="leader"
                class="hidden shrink-0 rounded-xl px-2.5 py-1.5 text-right sm:block"
                :class="leader.style.chip"
            >
                <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80">Líder</p>
                <p class="text-sm font-bold">{{ leader.percent }}%</p>
            </div>
        </div>

        <div v-if="summary.items.length" class="mt-4 flex flex-1 flex-col gap-4">
            <div
                v-if="leader"
                class="relative overflow-hidden rounded-xl border border-zinc-200/60 bg-gradient-to-br from-zinc-50 to-white p-4 dark:border-zinc-700/50 dark:from-zinc-800/80 dark:to-zinc-900/40"
            >
                <div
                    class="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full opacity-20 blur-2xl"
                    :class="`bg-gradient-to-br ${leader.style.bar}`"
                />
                <div class="relative flex items-center gap-3">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1"
                        :class="[leader.style.chip, leader.style.ring]"
                    >
                        <component :is="leader.style.icon" class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ leader.label }}</p>
                        <p class="text-xs text-zinc-500">
                            {{ displayCount(leader.quantidade) }}
                            {{ leader.quantidade === 1 ? 'venda' : 'vendas' }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-bold tabular-nums text-zinc-900 dark:text-white">
                            {{ displayAmount(leader.total) }}
                        </p>
                        <p class="text-xs font-medium text-[var(--color-primary)]">{{ leader.percent }}%</p>
                    </div>
                </div>
            </div>

            <ul class="space-y-3">
                <li
                    v-for="m in summary.items"
                    :key="m.metodo"
                    class="rounded-xl border border-transparent px-1 py-0.5 transition-colors hover:border-zinc-200/80 hover:bg-zinc-50/80 dark:hover:border-zinc-700/50 dark:hover:bg-zinc-800/30"
                >
                    <div class="mb-1.5 flex items-center gap-2.5">
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                            :class="m.style.chip"
                        >
                            <component :is="m.style.icon" class="h-4 w-4" aria-hidden="true" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ m.label }}
                                </span>
                                <span
                                    class="shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-semibold tabular-nums"
                                    :class="m.style.chip"
                                >
                                    {{ m.percent }}%
                                </span>
                            </div>
                            <div class="mt-0.5 flex items-center justify-between gap-2 text-[11px] text-zinc-500">
                                <span>{{ displayCount(m.quantidade) }} vendas</span>
                                <span class="font-medium tabular-nums text-zinc-700 dark:text-zinc-300">
                                    {{ displayAmount(m.total) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-700/80">
                        <div
                            class="h-full rounded-full bg-gradient-to-r transition-all duration-500 ease-out"
                            :class="m.style.bar"
                            :style="{ width: `${Math.max(m.percent, m.total > 0 ? 4 : 0)}%` }"
                        />
                    </div>
                </li>
            </ul>
        </div>

        <p v-else class="mt-8 flex flex-1 items-center justify-center text-center text-sm text-zinc-500">
            Nenhum pagamento no período
        </p>
    </div>
</template>
