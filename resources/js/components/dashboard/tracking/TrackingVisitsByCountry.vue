<script setup>
import { computed } from 'vue';
import { Globe } from 'lucide-vue-next';
import { countryFlag } from '@/composables/useTrackingPanel';

const props = defineProps({
    visits: { type: Array, default: () => [] },
    maxItems: { type: Number, default: 8 },
});

const sorted = computed(() =>
    [...props.visits].sort((a, b) => (b.count ?? 0) - (a.count ?? 0)).slice(0, props.maxItems)
);

const summary = computed(() => {
    const total = sorted.value.reduce((s, v) => s + (v.count ?? 0), 0);
    return {
        total,
        items: sorted.value.map((v) => ({
            ...v,
            percent: total > 0 ? Math.round(((v.count ?? 0) / total) * 1000) / 10 : 0,
        })),
    };
});

const leader = computed(() => summary.value.items[0] ?? null);
</script>

<template>
    <div class="panel-card-md flex h-full flex-col">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm">
                        <Globe class="h-4 w-4" />
                    </div>
                    Visitas por país
                </h2>
                <p v-if="summary.items.length" class="mt-1 text-xs text-zinc-500">
                    {{ summary.total }} visitas · {{ summary.items.length }} países
                </p>
            </div>
            <div
                v-if="leader"
                class="hidden shrink-0 rounded-xl bg-[var(--color-primary)]/12 px-2.5 py-1.5 text-right text-[var(--color-primary)] sm:block"
            >
                <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80">Top</p>
                <p class="text-sm font-bold">{{ leader.percent }}%</p>
            </div>
        </div>

        <div v-if="summary.items.length" class="mt-4 flex flex-1 flex-col gap-4">
            <div
                v-if="leader"
                class="relative overflow-hidden rounded-xl border border-zinc-200/60 bg-gradient-to-br from-zinc-50 to-white p-4 dark:border-zinc-700/50 dark:from-zinc-800/80 dark:to-zinc-900/40"
            >
                <div class="pointer-events-none absolute -right-4 -top-4 h-20 w-20 rounded-full bg-[var(--color-primary)]/15 blur-2xl" />
                <div class="relative flex items-center gap-3">
                    <span class="text-3xl leading-none">{{ countryFlag(leader.country_code) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ leader.country_name }}</p>
                        <p class="text-xs text-zinc-500">{{ leader.count }} visitas</p>
                    </div>
                    <p class="text-lg font-bold tabular-nums text-[var(--color-primary)]">{{ leader.percent }}%</p>
                </div>
            </div>

            <ul class="space-y-3">
                <li
                    v-for="v in summary.items"
                    :key="v.country_code ?? v.country_name"
                    class="rounded-xl px-1 py-0.5 transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30"
                >
                    <div class="mb-1.5 flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg leading-none dark:bg-zinc-800">
                            {{ countryFlag(v.country_code) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ v.country_name }}
                                </span>
                                <span class="shrink-0 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ v.percent }}%
                                </span>
                            </div>
                            <p class="mt-0.5 text-[11px] tabular-nums text-zinc-500">{{ v.count }} visitas</p>
                        </div>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-700/80">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-[var(--color-primary)]/70 to-[var(--color-primary)] transition-all duration-500 ease-out"
                            :style="{ width: `${Math.max(v.percent, v.count ? 4 : 0)}%` }"
                        />
                    </div>
                </li>
            </ul>
        </div>

        <p v-else class="mt-8 flex flex-1 items-center justify-center text-center text-sm text-zinc-500">
            Sem visitas no período
        </p>
    </div>
</template>
