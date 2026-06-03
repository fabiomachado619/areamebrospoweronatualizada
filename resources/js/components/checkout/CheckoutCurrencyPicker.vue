<script setup>
import { ref, computed } from 'vue';
import { Check, ChevronDown } from 'lucide-vue-next';
import CheckoutDropdown from './CheckoutDropdown.vue';
import { currencyFlagUrls } from '@/lib/currencyFlagUrl';

const props = defineProps({
    displayCurrency: { type: String, default: 'BRL' },
    currencyList: { type: Array, default: () => [] },
    featuredCurrencies: { type: Array, default: () => [] },
    otherCurrencies: { type: Array, default: () => [] },
    primaryColor: { type: String, default: '#7427F1' },
    t: { type: Function, default: (k) => k },
});

const emit = defineEmits(['set-currency']);

const othersOpen = ref(false);
const currencySearch = ref('');
/** @type {import('vue').Ref<Record<string, number>>} */
const flagUrlIndex = ref({});

const featuredList = computed(() =>
    props.featuredCurrencies?.length
        ? props.featuredCurrencies
        : props.currencyList.filter((c) => ['BRL', 'USD', 'EUR'].includes(c.code))
);

const otherList = computed(() => {
    if (props.otherCurrencies?.length) return props.otherCurrencies;
    const featuredCodes = new Set(featuredList.value.map((c) => c.code));
    return props.currencyList.filter((c) => !featuredCodes.has(c.code));
});

const visiblePills = computed(() => {
    const all = props.currencyList || [];
    const selectedCode = props.displayCurrency;
    const selected = all.find((c) => c.code === selectedCode) || all[0];
    if (!selected) return [];

    const pool = [...featuredList.value, ...otherList.value].filter((c) => c.code !== selected.code);
    const seen = new Set([selected.code]);
    const alternates = [];
    for (const c of pool) {
        if (alternates.length >= 2) break;
        if (!seen.has(c.code)) {
            alternates.push(c);
            seen.add(c.code);
        }
    }
    return [selected, ...alternates];
});

const hiddenCurrencies = computed(() => {
    const visibleCodes = new Set(visiblePills.value.map((c) => c.code));
    return (props.currencyList || []).filter((c) => !visibleCodes.has(c.code));
});

const filteredHiddenCurrencies = computed(() => {
    const q = currencySearch.value.trim().toLowerCase();
    if (!q) return hiddenCurrencies.value;
    return hiddenCurrencies.value.filter((c) => {
        const code = String(c.code || '').toLowerCase();
        const label = String(c.label || '').toLowerCase();
        const symbol = String(c.symbol || '').toLowerCase();
        return code.includes(q) || label.includes(q) || symbol.includes(q);
    });
});

function select(code) {
    emit('set-currency', code);
    othersOpen.value = false;
    currencySearch.value = '';
}

function isSelected(code) {
    return props.displayCurrency === code;
}

function flagSrc(code) {
    const urls = currencyFlagUrls(code);
    const idx = flagUrlIndex.value[code] ?? 0;

    return urls[idx] ?? urls[0] ?? null;
}

function onFlagError(code) {
    const urls = currencyFlagUrls(code);
    const idx = (flagUrlIndex.value[code] ?? 0) + 1;
    if (idx < urls.length) {
        flagUrlIndex.value = { ...flagUrlIndex.value, [code]: idx };
    }
}

function pillClass(selected) {
    return selected
        ? 'border-gray-300 bg-gray-50 text-gray-900'
        : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50';
}
</script>

<template>
    <div
        v-if="currencyList.length > 1"
        class="flex flex-wrap items-center gap-1.5"
        data-checkout="currency-picker"
    >
        <button
            v-for="c in visiblePills"
            :key="c.code"
            type="button"
            role="option"
            :aria-pressed="isSelected(c.code)"
            :aria-label="c.label || c.code"
            :data-currency="c.code"
            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold tracking-wide transition focus:outline-none focus:ring-2 focus:ring-gray-200 focus:ring-offset-1"
            :class="pillClass(isSelected(c.code))"
            :style="isSelected(c.code) ? { borderColor: primaryColor, backgroundColor: primaryColor + '0c' } : {}"
            @click="select(c.code)"
        >
            <img
                v-if="flagSrc(c.code)"
                :key="`${c.code}-${flagUrlIndex[c.code] ?? 0}`"
                :src="flagSrc(c.code)"
                :alt="c.code"
                width="24"
                height="16"
                class="h-4 w-6 shrink-0 rounded-[2px] object-cover"
                @error="onFlagError(c.code)"
            />
            <span>{{ c.code }}</span>
        </button>

        <CheckoutDropdown
            v-if="hiddenCurrencies.length > 0"
            v-model:open="othersOpen"
            align="left"
            :teleport="true"
            :aria-label="t('checkout.outras_moedas')"
        >
            <template #trigger="{ open }">
                <button
                    type="button"
                    class="inline-flex items-center gap-0.5 rounded-lg border border-dashed border-gray-200 px-2 py-1.5 text-xs font-medium text-gray-500 transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-200 focus:ring-offset-1"
                    :class="{ 'border-gray-300 bg-gray-50 text-gray-700': open }"
                >
                    {{ t('checkout.outras_moedas') }}
                    <ChevronDown class="h-3 w-3 transition-transform" :class="{ 'rotate-180': open }" />
                </button>
            </template>
            <div class="min-w-[12rem] px-2 py-2">
                <input
                    v-model="currencySearch"
                    type="search"
                    :placeholder="t('checkout.buscar_moeda')"
                    class="mb-2 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-800 placeholder:text-gray-400 focus:border-gray-300 focus:outline-none focus:ring-1 focus:ring-gray-300"
                    @click.stop
                />
                <button
                    v-for="c in filteredHiddenCurrencies"
                    :key="c.code"
                    type="button"
                    role="option"
                    class="flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-1.5 text-left text-xs transition hover:bg-gray-50"
                    :class="isSelected(c.code) ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-700'"
                    @click="select(c.code)"
                >
                    <span class="flex min-w-0 items-center gap-2 truncate">
                        <img
                            v-if="flagSrc(c.code)"
                            :key="`${c.code}-dd-${flagUrlIndex[c.code] ?? 0}`"
                            :src="flagSrc(c.code)"
                            :alt="c.code"
                            width="24"
                            height="16"
                            class="h-4 w-6 shrink-0 rounded-[2px] object-cover"
                            @error="onFlagError(c.code)"
                        />
                        <span class="truncate font-semibold">{{ c.code }}</span>
                        <span v-if="c.label" class="truncate text-gray-400">{{ c.label }}</span>
                    </span>
                    <Check v-if="isSelected(c.code)" class="h-3.5 w-3.5 shrink-0 text-gray-500" />
                </button>
                <p v-if="filteredHiddenCurrencies.length === 0" class="px-2 py-1.5 text-center text-[11px] text-gray-500">
                    {{ t('checkout.nenhuma_moeda') }}
                </p>
            </div>
        </CheckoutDropdown>
    </div>
</template>
