<script setup>
import { ArrowLeft, Loader2, Radar } from 'lucide-vue-next';
import DashboardPeriodFilter from '@/components/dashboard/DashboardPeriodFilter.vue';
import TrackingKpiRow from './TrackingKpiRow.vue';
import TrackingWorldMap from './TrackingWorldMap.vue';
import TrackingCountryLeader from './TrackingCountryLeader.vue';
import TrackingVisitsByCountry from './TrackingVisitsByCountry.vue';
import TrackingPaymentMethods from './TrackingPaymentMethods.vue';
import TrackingFieldDropoff from './TrackingFieldDropoff.vue';
import TrackingFunnel from './TrackingFunnel.vue';
import TrackingRecentSales from './TrackingRecentSales.vue';
import TrackingUtmSources from './TrackingUtmSources.vue';
import TrackingRevenueChart from './TrackingRevenueChart.vue';

defineProps({
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
    period: { type: String, default: 'hoje' },
    valuesVisible: { type: Boolean, default: true },
});

const emit = defineEmits([
    'close',
    'update:period',
    'save-daily',
    'save-period',
    'clear-period',
    'retry',
]);
</script>

<template>
    <div class="space-y-6">
        <div class="relative overflow-hidden rounded-2xl border border-[var(--color-primary)]/15 bg-gradient-to-r from-[var(--color-primary)]/5 via-transparent to-transparent p-3 sm:p-5 dark:from-[var(--color-primary)]/10">
            <div class="flex flex-col-reverse gap-3 lg:flex-row lg:items-end lg:justify-between lg:gap-x-4">
                <div class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <button
                        type="button"
                        aria-label="Voltar para Dashboard"
                        class="flex h-11 w-full shrink-0 items-center justify-center gap-2 rounded-2xl border border-zinc-200/80 bg-zinc-100/90 px-3.5 text-sm font-medium text-zinc-700 transition-colors hover:border-[var(--color-primary)]/30 hover:text-[var(--color-primary)] sm:w-auto dark:border-zinc-700/80 dark:bg-zinc-800/50 dark:text-zinc-200"
                        @click="emit('close')"
                    >
                        <ArrowLeft class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="whitespace-nowrap">Dashboard</span>
                    </button>
                    <div class="min-w-0 flex-1 sm:min-w-[200px]">
                        <DashboardPeriodFilter
                            :model-value="period"
                            @update:model-value="emit('update:period', $event)"
                        />
                    </div>
                </div>

                <div class="w-full shrink-0 lg:ml-auto lg:w-auto lg:text-right">
                    <div class="flex items-center gap-2 lg:justify-end">
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
                            aria-hidden="true"
                        >
                            <Radar class="h-4 w-4" />
                        </span>
                        <h1 class="text-base font-bold leading-tight text-zinc-900 dark:text-white sm:text-lg">
                            Tracking avançado
                        </h1>
                    </div>
                    <p class="mt-1 text-xs leading-snug text-zinc-500 sm:text-sm">
                        Visão completa de vendas, checkout, geo e ROI
                    </p>
                </div>
            </div>
        </div>

        <div v-if="loading && !data" class="flex items-center justify-center py-24 text-zinc-500">
            <Loader2 class="mr-2 h-6 w-6 animate-spin text-[var(--color-primary)]" />
            Carregando métricas...
        </div>

        <div v-else-if="error" class="panel-card-md text-center">
            <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
            <button
                type="button"
                class="mt-3 rounded-xl bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white"
                @click="emit('retry')"
            >
                Tentar novamente
            </button>
        </div>

        <template v-else-if="data">
            <TrackingKpiRow
                :financial="data.financial"
                :ad-spend="data.ad_spend"
                :period="period"
                :values-visible="valuesVisible"
                @save-daily="emit('save-daily', $event)"
                @save-period="emit('save-period', $event)"
                @clear-period="emit('clear-period')"
            />

            <div class="grid items-stretch gap-4 lg:grid-cols-3">
                <div class="panel-card-lg flex flex-col overflow-hidden lg:col-span-2">
                    <div class="shrink-0 px-1">
                        <h2 class="mb-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Vendas por país</h2>
                        <p class="text-xs text-zinc-500">Mapa mundial — países com vendas destacados</p>
                    </div>
                    <div class="relative -mx-6 -mb-6 mt-3 h-[220px] sm:h-[240px]">
                        <TrackingWorldMap
                            class="absolute inset-0"
                            :countries="data.sales_by_country?.length ? data.sales_by_country : data.visits_by_country"
                            :highlight-code="data.top_country?.country_code"
                        />
                    </div>
                </div>
                <TrackingCountryLeader
                    :top-country="data.top_country"
                    :values-visible="valuesVisible"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <TrackingVisitsByCountry :visits="data.visits_by_country" />
                <TrackingPaymentMethods
                    :methods="data.payment_methods"
                    :values-visible="valuesVisible"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <TrackingFieldDropoff :fields="data.field_dropoff" />
                <TrackingFunnel :funnel="data.funnel" />
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <TrackingRecentSales
                    class="lg:col-span-1"
                    :sales="data.recent_sales"
                    :values-visible="valuesVisible"
                />
                <div class="space-y-4 lg:col-span-2">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TrackingUtmSources :sources="data.utm_sources" />
                        <TrackingRevenueChart
                            :chart="data.chart_revenue"
                            :period="period"
                            :values-visible="valuesVisible"
                        />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
