<script setup>
import { computed } from 'vue';
import { MousePointerClick, AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
    fields: { type: Array, default: () => [] },
});

const enriched = computed(() => {
    const items = [...props.fields].sort((a, b) => (b.dropoff_percent ?? 0) - (a.dropoff_percent ?? 0));
    const worst = items[0] ?? null;
    return { items, worst };
});

function dropoffStyle(percent) {
    if (percent >= 40) {
        return {
            bar: 'from-red-500 to-orange-400',
            chip: 'bg-red-500/12 text-red-700 dark:text-red-300',
        };
    }
    if (percent >= 20) {
        return {
            bar: 'from-amber-500 to-yellow-400',
            chip: 'bg-amber-500/12 text-amber-800 dark:text-amber-300',
        };
    }
    return {
        bar: 'from-[var(--color-primary)]/80 to-[var(--color-primary)]',
        chip: 'bg-[var(--color-primary)]/12 text-[var(--color-primary)]',
    };
}
</script>

<template>
    <div class="panel-card-md flex h-full flex-col">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm">
                        <MousePointerClick class="h-4 w-4" />
                    </div>
                    Abandono por campo
                </h2>
                <p class="mt-1 text-xs text-zinc-500">Onde os compradores desistem no checkout</p>
            </div>
            <div
                v-if="enriched.worst && enriched.worst.dropoff_percent > 0"
                class="hidden shrink-0 rounded-xl bg-red-500/10 px-2.5 py-1.5 text-right sm:block"
            >
                <p class="text-[10px] font-semibold uppercase tracking-wide text-red-600/80 dark:text-red-400/80">Pior campo</p>
                <p class="text-sm font-bold text-red-600 dark:text-red-400">{{ enriched.worst.dropoff_percent }}%</p>
            </div>
        </div>

        <div v-if="enriched.items.length" class="mt-4 space-y-3">
            <div
                v-if="enriched.worst && enriched.worst.dropoff_percent > 0"
                class="relative overflow-hidden rounded-xl border border-red-200/50 bg-gradient-to-br from-red-50/80 to-white p-3 dark:border-red-900/30 dark:from-red-950/20 dark:to-zinc-900/40"
            >
                <div class="relative flex items-start gap-2.5">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-red-500" aria-hidden="true" />
                    <div>
                        <p class="text-xs font-semibold text-zinc-900 dark:text-white">{{ enriched.worst.label }}</p>
                        <p class="mt-0.5 text-[11px] text-zinc-500">
                            {{ enriched.worst.reached }} alcançaram · {{ enriched.worst.dropoff_percent }}% abandonaram
                        </p>
                    </div>
                </div>
            </div>

            <ul class="space-y-3">
                <li
                    v-for="field in enriched.items"
                    :key="field.field_key"
                    class="rounded-xl px-1 py-0.5 transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30"
                >
                    <div class="mb-1.5 flex items-center justify-between gap-2">
                        <span class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-200">
                            {{ field.label }}
                        </span>
                        <span
                            class="shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-semibold tabular-nums"
                            :class="dropoffStyle(field.dropoff_percent).chip"
                        >
                            {{ field.dropoff_percent }}% drop
                        </span>
                    </div>
                    <div class="mb-1 flex justify-between text-[10px] text-zinc-500">
                        <span>{{ field.reached }} alcançaram</span>
                        <span>{{ field.completed_percent }}% concluíram</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-700/80">
                        <div
                            class="h-full rounded-full bg-gradient-to-r transition-all duration-700 ease-out"
                            :class="dropoffStyle(field.dropoff_percent).bar"
                            :style="{ width: `${Math.max(field.completed_percent, field.reached ? 4 : 0)}%` }"
                        />
                    </div>
                </li>
            </ul>
        </div>

        <p v-else class="mt-8 flex flex-1 items-center justify-center text-center text-sm text-zinc-500">
            Dados serão coletados conforme novos checkouts
        </p>
    </div>
</template>
