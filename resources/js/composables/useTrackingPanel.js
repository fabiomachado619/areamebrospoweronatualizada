import { ref, onUnmounted } from 'vue';
import axios from 'axios';

export function useTrackingPanel() {
    const isOpen = ref(false);
    const loading = ref(false);
    const error = ref(null);
    const data = ref(null);
    const period = ref('hoje');
    let pollTimer = null;

    async function fetchData(silent = false) {
        if (!silent) {
            loading.value = true;
        }
        error.value = null;
        try {
            const { data: payload } = await axios.get('/api/dashboard/tracking', {
                params: { period: period.value },
            });
            data.value = payload;
        } catch (e) {
            error.value = 'Não foi possível carregar os dados de tracking.';
        } finally {
            loading.value = false;
        }
    }

    function open() {
        isOpen.value = true;
        fetchData();
        pollTimer = setInterval(() => fetchData(true), 30000);
    }

    function close() {
        isOpen.value = false;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function toggle() {
        if (isOpen.value) {
            close();
        } else {
            open();
        }
    }

    function setPeriod(value) {
        period.value = value;
        if (isOpen.value) {
            fetchData();
        }
    }

    async function saveDailyAdSpend(date, amount) {
        await axios.put('/api/dashboard/tracking/ad-spend/daily', { date, amount });
        await fetchData(true);
    }

    async function savePeriodAdSpend(amount) {
        await axios.put('/api/dashboard/tracking/ad-spend/period', {
            period_key: period.value,
            amount,
        });
        await fetchData(true);
    }

    async function clearPeriodAdSpend() {
        await axios.delete('/api/dashboard/tracking/ad-spend/period', {
            params: { period_key: period.value },
        });
        await fetchData(true);
    }

    onUnmounted(() => {
        if (pollTimer) {
            clearInterval(pollTimer);
        }
    });

    return {
        isOpen,
        loading,
        error,
        data,
        period,
        open,
        close,
        toggle,
        setPeriod,
        fetchData,
        saveDailyAdSpend,
        savePeriodAdSpend,
        clearPeriodAdSpend,
    };
}

export function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

export function formatPercent(value) {
    if (value === null || value === undefined) {
        return '—';
    }
    return `${value}%`;
}

export function countryFlag(code) {
    if (!code || code.length !== 2) {
        return '🌍';
    }
    const base = 127397;
    return String.fromCodePoint(...[...code.toUpperCase()].map((c) => base + c.charCodeAt(0)));
}

export function timeAgo(iso) {
    if (!iso) return '';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'agora';
    if (mins < 60) return `${mins}min`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h`;
    return `${Math.floor(hours / 24)}d`;
}
