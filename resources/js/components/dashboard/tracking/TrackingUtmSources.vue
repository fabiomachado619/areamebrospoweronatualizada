<script setup>
import { computed } from 'vue';
import { Link2, Megaphone } from 'lucide-vue-next';

const props = defineProps({
    sources: { type: Array, default: () => [] },
});

const summary = computed(() => {
    const total = props.sources.reduce((s, src) => s + (src.count ?? 0), 0);
    return {
        total,
        items: props.sources.map((src, idx) => ({
            ...src,
            rank: idx + 1,
            percent: total > 0 ? Math.round(((src.count ?? 0) / total) * 1000) / 10 : 0,
        })),
    };
});

const leader = computed(() => summary.value.items[0] ?? null);

const RANK_CHIP = [
    'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    'bg-zinc-400/15 text-zinc-700 dark:text-zinc-300',
    'bg-orange-600/15 text-orange-800 dark:text-orange-300',
];
</script>

<template>
    <div class="panel-card-md flex h-full flex-col">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm">
                        <Link2 class="h-4 w-4" />
                    </div>
                    Top fontes (UTM)
                </h2>
                <p v-if="summary.items.length" class="mt-1 text-xs text-zinc-500">
                    {{ summary.total }} sessões rastreadas
                </p>
            </div>
        </div>

        <div v-if="summary.items.length" class="mt-4 flex flex-1 flex-col gap-4">
            <div
                v-if="leader"
                class="relative overflow-hidden rounded-xl border border-zinc-200/60 bg-gradient-to-br from-zinc-50 to-white p-4 dark:border-zinc-700/50 dark:from-zinc-800/80 dark:to-zinc-900/40"
            >
                <div class="pointer-events-none absolute -right-4 -top-4 h-20 w-20 rounded-full bg-[var(--color-primary)]/15 blur-2xl" />
                <div class="relative flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--color-primary)]/12 text-[var(--color-primary)]">
                        <Megaphone class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ leader.source }} / {{ leader.medium }}
                        </p>
                        <p class="text-xs text-zinc-500">{{ leader.count }} sessões</p>
                    </div>
                    <p class="text-lg font-bold tabular-nums text-[var(--color-primary)]">{{ leader.percent }}%</p>
                </div>
            </div>

            <ul class="space-y-2">
                <li
                    v-for="src in summary.items"
                    :key="`${src.source}-${src.medium}`"
                    class="rounded-xl px-1 py-1 transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30"
                >
                    <div class="mb-1.5 flex items-center gap-2.5">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                            :class="RANK_CHIP[src.rank - 1] ?? 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                        >
                            {{ src.rank }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ src.source }} / {{ src.medium }}
                                </span>
                                <span class="shrink-0 text-xs font-bold tabular-nums text-zinc-900 dark:text-white">
                                    {{ src.count }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-[10px] text-zinc-500">{{ src.percent }}% do tráfego</p>
                        </div>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-700/80">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-indigo-500/80 to-[var(--color-primary)] transition-all duration-500"
                            :style="{ width: `${Math.max(src.percent, src.count ? 4 : 0)}%` }"
                        />
                    </div>
                </li>
            </ul>
        </div>

        <p v-else class="mt-8 flex flex-1 items-center justify-center text-center text-sm text-zinc-500">
            Sem dados de UTM no período
        </p>
    </div>
</template>
