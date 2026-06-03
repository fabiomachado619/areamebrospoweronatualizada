<script setup>
import { computed } from 'vue';
import { Zap } from 'lucide-vue-next';
import { formatBRL, countryFlag, timeAgo } from '@/composables/useTrackingPanel';

const props = defineProps({
    sales: { type: Array, default: () => [] },
    valuesVisible: { type: Boolean, default: true },
});

const summary = computed(() => {
    const total = props.sales.reduce((s, sale) => s + (sale.amount ?? 0), 0);
    return { count: props.sales.length, total };
});

function displayAmount(value) {
    return props.valuesVisible ? formatBRL(value) : '••••••';
}
</script>

<template>
    <div class="panel-card-md flex h-full flex-col">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm">
                        <Zap class="h-4 w-4" />
                    </div>
                    Vendas recentes
                    <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-[var(--color-primary)]" aria-hidden="true" />
                </h2>
                <p v-if="summary.count" class="mt-1 text-xs text-zinc-500">
                    {{ summary.count }} últimas · {{ displayAmount(summary.total) }}
                </p>
            </div>
        </div>

        <ul v-if="sales.length" class="mt-4 max-h-[340px] flex-1 space-y-2 overflow-y-auto pr-0.5">
            <li
                v-for="(sale, idx) in sales"
                :key="sale.id"
                class="group relative overflow-hidden rounded-xl border border-zinc-200/60 bg-gradient-to-r from-zinc-50/90 to-white px-3 py-2.5 transition-all hover:border-[var(--color-primary)]/25 hover:shadow-sm dark:border-zinc-700/50 dark:from-zinc-800/50 dark:to-zinc-900/30"
            >
                <div class="flex items-center gap-3">
                    <div class="relative shrink-0">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-xl shadow-sm ring-1 ring-zinc-200/80 dark:bg-zinc-800 dark:ring-zinc-700">
                            {{ countryFlag(sale.country_code) }}
                        </span>
                        <span
                            class="absolute -left-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-zinc-900 text-[9px] font-bold text-white dark:bg-zinc-700"
                        >
                            {{ idx + 1 }}
                        </span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                            {{ sale.product_name }}
                        </p>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-zinc-500">
                            <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 font-medium dark:bg-zinc-800">
                                {{ sale.payment_label }}
                            </span>
                            <span>{{ timeAgo(sale.created_at) }}</span>
                        </div>
                    </div>
                    <span class="shrink-0 text-sm font-bold tabular-nums text-[var(--color-primary)]">
                        {{ displayAmount(sale.amount) }}
                    </span>
                </div>
            </li>
        </ul>

        <p v-else class="mt-8 flex flex-1 items-center justify-center text-center text-sm text-zinc-500">
            Nenhuma venda recente
        </p>
    </div>
</template>
