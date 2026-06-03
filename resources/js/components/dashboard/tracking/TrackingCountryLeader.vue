<script setup>
import { Trophy, MapPin } from 'lucide-vue-next';
import { formatBRL, countryFlag } from '@/composables/useTrackingPanel';

defineProps({
    topCountry: { type: Object, default: null },
    valuesVisible: { type: Boolean, default: true },
});
</script>

<template>
    <div class="panel-card-md flex h-full flex-col justify-center">
        <div class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
            <div class="dash-metric-icon-sm">
                <Trophy class="h-4 w-4" />
            </div>
            País líder em vendas
        </div>

        <div
            v-if="topCountry"
            class="relative mt-5 overflow-hidden rounded-2xl border border-[var(--color-primary)]/20 bg-gradient-to-br from-[var(--color-primary)]/8 via-transparent to-zinc-50/80 p-5 text-center dark:from-[var(--color-primary)]/12 dark:to-zinc-900/40"
        >
            <div class="pointer-events-none absolute -left-8 -top-8 h-32 w-32 rounded-full bg-[var(--color-primary)]/10 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-6 -right-6 h-24 w-24 rounded-full bg-[var(--color-primary)]/10 blur-2xl" />

            <div class="relative mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-4xl shadow-sm ring-1 ring-zinc-200/80 dark:bg-zinc-800 dark:ring-zinc-700">
                {{ countryFlag(topCountry.country_code) }}
            </div>

            <p class="relative mt-4 text-lg font-bold text-zinc-900 dark:text-white">
                {{ topCountry.country_name }}
            </p>

            <p class="relative mt-2 text-2xl font-bold tabular-nums text-[var(--color-primary)] sm:text-3xl">
                {{ valuesVisible ? formatBRL(topCountry.total) : '••••••' }}
            </p>

            <div class="relative mt-4 flex flex-wrap items-center justify-center gap-2">
                <span class="inline-flex items-center gap-1 rounded-full bg-[var(--color-primary)]/12 px-2.5 py-1 text-xs font-semibold text-[var(--color-primary)]">
                    {{ topCountry.percent }}% do total
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <MapPin class="h-3 w-3" aria-hidden="true" />
                    {{ topCountry.count }} {{ topCountry.count === 1 ? 'venda' : 'vendas' }}
                </span>
            </div>
        </div>

        <p v-else class="mt-8 text-center text-sm text-zinc-500">
            Nenhuma venda geolocalizada
        </p>
    </div>
</template>
