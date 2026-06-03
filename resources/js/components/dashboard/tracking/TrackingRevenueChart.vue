<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import { LineChart } from 'lucide-vue-next';
import { formatBRL } from '@/composables/useTrackingPanel';

const props = defineProps({
    chart: { type: Array, default: () => [] },
    period: { type: String, default: 'hoje' },
    valuesVisible: { type: Boolean, default: true },
});

const page = usePage();
const isDarkMode = ref(false);

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
});

const chartPrimaryColor = computed(() => {
    const fromSettings = page.props.appSettings?.theme_primary;
    if (typeof fromSettings === 'string' && fromSettings.trim()) return fromSettings.trim();
    return '#0ea5e9';
});

const totalRevenue = computed(() =>
    props.chart.reduce((s, d) => s + (Number(d.total) || 0), 0)
);

const chartSeries = computed(() => [{
    name: 'Receita',
    data: props.valuesVisible ? props.chart.map((d) => d.total) : props.chart.map(() => 0),
}]);

const chartOptions = computed(() => ({
    chart: { type: 'area', toolbar: { show: false }, fontFamily: 'inherit', sparkline: { enabled: false } },
    colors: [chartPrimaryColor.value],
    stroke: { curve: 'smooth', width: 2.5 },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.45,
            opacityTo: 0.04,
            stops: [0, 90, 100],
        },
    },
    xaxis: {
        categories: (props.period === 'hoje' || props.period === 'ontem')
            ? props.chart.map((d) => `${Number(d.data)}h`)
            : props.chart.map((d) => {
                const [, m, day] = (d.data || '').split('-');
                return day && m ? `${day}/${m}` : d.data;
            }),
        labels: { style: { colors: '#71717a', fontSize: '11px' } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: {
            formatter: (v) => formatBRL(v),
            style: { colors: '#71717a', fontSize: '11px' },
        },
    },
    grid: {
        borderColor: isDarkMode.value ? '#27272a' : '#e4e4e7',
        strokeDashArray: 4,
        padding: { top: 4, right: 8, bottom: 0, left: 8 },
    },
    tooltip: {
        theme: isDarkMode.value ? 'dark' : 'light',
        y: { formatter: (v) => (props.valuesVisible ? formatBRL(v) : '••••') },
    },
    dataLabels: { enabled: false },
    markers: { size: 0, hover: { size: 4 } },
}));

function displayTotal() {
    return props.valuesVisible ? formatBRL(totalRevenue.value) : '••••••';
}
</script>

<template>
    <div class="panel-card-md">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm">
                        <LineChart class="h-4 w-4" />
                    </div>
                    Receita no período
                </h2>
                <p v-if="chart.length" class="mt-1 text-xs text-zinc-500">
                    Total: <span class="font-semibold text-[var(--color-primary)]">{{ displayTotal() }}</span>
                </p>
            </div>
        </div>

        <div class="mt-4 min-h-[220px] rounded-xl border border-zinc-200/50 bg-gradient-to-b from-zinc-50/50 to-transparent p-2 dark:border-zinc-700/40 dark:from-zinc-800/30">
            <VueApexCharts
                v-if="chart.length"
                type="area"
                height="220"
                :options="chartOptions"
                :series="chartSeries"
            />
            <p v-else class="flex h-[220px] items-center justify-center text-sm text-zinc-500">
                Sem dados de receita no período
            </p>
        </div>
    </div>
</template>
