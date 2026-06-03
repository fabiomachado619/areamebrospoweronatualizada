<script setup>
import { computed } from 'vue';
import { Filter, Users, FileText, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
    funnel: { type: Object, default: () => ({}) },
});

const STEP_META = [
    { key: 'visitas', label: 'Visitas', icon: Users, bar: 'from-zinc-400 to-zinc-500', chip: 'bg-zinc-500/12 text-zinc-600 dark:text-zinc-300' },
    { key: 'form_started', label: 'Formulário iniciado', icon: FileText, bar: 'from-blue-500 to-indigo-400', chip: 'bg-blue-500/12 text-blue-700 dark:text-blue-300' },
    { key: 'form_filled', label: 'Formulário preenchido', icon: FileText, bar: 'from-violet-500 to-purple-400', chip: 'bg-violet-500/12 text-violet-700 dark:text-violet-300' },
    { key: 'convertidos', label: 'Convertidos', icon: CheckCircle2, bar: 'from-[var(--color-primary)] to-[var(--color-primary)]/70', chip: 'bg-[var(--color-primary)]/12 text-[var(--color-primary)]' },
];

const steps = computed(() => {
    const f = props.funnel ?? {};
    const values = STEP_META.map((m) => f[m.key] ?? 0);
    const max = Math.max(...values, 1);
    return STEP_META.map((meta, idx) => ({
        ...meta,
        value: values[idx],
        percent: Math.round((values[idx] / max) * 1000) / 10,
        convFromPrev: idx > 0 && values[idx - 1] > 0
            ? Math.round((values[idx] / values[idx - 1]) * 1000) / 10
            : null,
    }));
});

const conversionRate = computed(() => props.funnel?.taxa_conversao ?? 0);
const abandonment = computed(() => props.funnel?.abandono ?? 0);
</script>

<template>
    <div class="panel-card-md flex h-full flex-col">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm">
                        <Filter class="h-4 w-4" />
                    </div>
                    Funil de checkout
                </h2>
                <p class="mt-1 text-xs text-zinc-500">Jornada da visita até a conversão</p>
            </div>
            <div class="flex gap-2">
                <div class="rounded-xl bg-[var(--color-primary)]/12 px-2.5 py-1.5 text-center">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--color-primary)]/80">Conversão</p>
                    <p class="text-sm font-bold text-[var(--color-primary)]">{{ conversionRate }}%</p>
                </div>
                <div class="rounded-xl bg-red-500/10 px-2.5 py-1.5 text-center">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-red-600/80 dark:text-red-400/80">Abandono</p>
                    <p class="text-sm font-bold text-red-600 dark:text-red-400">{{ abandonment }}</p>
                </div>
            </div>
        </div>

        <div class="mt-4 space-y-2.5">
            <div
                v-for="step in steps"
                :key="step.key"
                class="rounded-xl border border-zinc-200/50 px-3 py-2.5 transition-colors hover:border-zinc-300/80 dark:border-zinc-700/50 dark:hover:border-zinc-600/60"
            >
                <div class="mb-2 flex items-center gap-2.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="step.chip">
                        <component :is="step.icon" class="h-4 w-4" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-medium text-zinc-800 dark:text-zinc-200">{{ step.label }}</span>
                            <span class="shrink-0 text-sm font-bold tabular-nums text-zinc-900 dark:text-white">
                                {{ step.value }}
                            </span>
                        </div>
                        <p v-if="step.convFromPrev != null" class="mt-0.5 text-[10px] text-zinc-500">
                            {{ step.convFromPrev }}% da etapa anterior
                        </p>
                    </div>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-700/80">
                    <div
                        class="h-full rounded-full bg-gradient-to-r transition-all duration-700 ease-out"
                        :class="step.bar"
                        :style="{ width: `${Math.max(step.percent, step.value ? 8 : 0)}%` }"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
