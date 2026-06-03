import { ref, computed, watch, toValue } from 'vue';

const LOCALE_KEY = 'checkout_locale';
const CURRENCY_KEY = 'checkout_currency';
const SUPPORTED_LOCALES = ['pt_BR', 'en', 'es'];
const FEATURED_CURRENCY_CODES = ['BRL', 'USD', 'EUR'];

/**
 * @param {Object} options
 * @param {Record<string, Record<string, string>>} options.translations - checkout_translations
 * @param {Array<{ code: string, symbol: string, label: string, rate_to_brl: number }>} options.currencies
 * @param {string} [options.suggestedLocale] - suggested_locale from backend
 * @param {string} [options.suggestedCurrency] - suggested_currency from backend
 * @param {string} [options.storageKey] - e.g. checkout_slug for localStorage keys
 * @param {import('vue').MaybeRefOrGetter<{ enabled?: boolean, locale?: string|null, currency?: string|null }|null|undefined>} [options.checkoutForce] - forçar idioma do produto (quando sem escolha manual)
 * @param {import('vue').MaybeRefOrGetter<{ mode?: 'global'|'fixed', currency?: string|null }|null|undefined>} [options.checkoutCurrency] - modo global ou moeda única fixa
 * @param {import('vue').MaybeRefOrGetter<Record<string, number>|null|undefined>} [options.customDisplayPricesByCurrency] - preços fixos por moeda (exibição)
 * @param {import('vue').MaybeRefOrGetter<boolean>} [options.skipCustomDisplayPrices] - ex.: cupom ativo — usa só conversão por taxa
 */
export function useCheckoutLocale(options = {}) {
    const {
        translations = {},
        currencies = [],
        suggestedLocale = 'pt_BR',
        suggestedCurrency = 'BRL',
        storageKey = 'default',
        checkoutForce = null,
        checkoutCurrency = null,
        customDisplayPricesByCurrency = null,
        skipCustomDisplayPrices = false,
    } = options;

    const localeStorageKey = `${LOCALE_KEY}_${storageKey}`;
    const currencyStorageKey = `${CURRENCY_KEY}_${storageKey}`;

    function getStoredLocale() {
        try {
            const v = localStorage.getItem(localeStorageKey);
            return SUPPORTED_LOCALES.includes(v) ? v : null;
        } catch {
            return null;
        }
    }

    function getStoredCurrency() {
        try {
            const v = localStorage.getItem(currencyStorageKey);
            const codes = currencies.map((c) => c.code);
            return v && codes.includes(v) ? v : null;
        } catch {
            return null;
        }
    }

    function readManualChoiceFlag() {
        try {
            if (typeof window === 'undefined') return false;
            return !!localStorage.getItem(`checkout_locale_manual_${storageKey}`);
        } catch {
            return false;
        }
    }

    const codes = (Array.isArray(currencies) ? currencies : []).map((c) => c?.code).filter(Boolean);

    function clampToAvailableCurrency(code) {
        const normalized = String(code || 'BRL').toUpperCase();
        if (codes.includes(normalized)) {
            return normalized;
        }
        if (codes.includes('BRL')) {
            return 'BRL';
        }

        return codes[0] || 'BRL';
    }

    function resolvedFixedCurrencyCode() {
        const cc = checkoutCurrency != null ? toValue(checkoutCurrency) : null;
        if (cc?.mode === 'fixed' && cc?.currency) {
            return clampToAvailableCurrency(cc.currency);
        }
        const force = checkoutForce != null ? toValue(checkoutForce) : null;
        if (force?.enabled && force?.currency) {
            return clampToAvailableCurrency(force.currency);
        }

        return null;
    }

    const manual = readManualChoiceFlag();
    const force = checkoutForce != null ? toValue(checkoutForce) : null;
    const fixedCurrencyCode = resolvedFixedCurrencyCode();
    let initialLocale = suggestedLocale || 'pt_BR';
    let initialCurrency = suggestedCurrency || 'BRL';
    if (fixedCurrencyCode && !manual) {
        initialCurrency = fixedCurrencyCode;
    } else if (manual) {
        initialLocale = getStoredLocale() || initialLocale;
        initialCurrency = getStoredCurrency() || initialCurrency;
    } else if (force && force.enabled) {
        if (force.locale && SUPPORTED_LOCALES.includes(force.locale)) {
            initialLocale = force.locale;
        }
    } else if (typeof window !== 'undefined') {
        initialLocale = getStoredLocale() || initialLocale;
        initialCurrency = getStoredCurrency() || initialCurrency;
    }

    initialCurrency = clampToAvailableCurrency(initialCurrency);

    const locale = ref(initialLocale);
    const currency = ref(initialCurrency);

    watch(
        locale,
        (v) => {
            try {
                if (v) localStorage.setItem(localeStorageKey, v);
            } catch (_) {}
        },
        { immediate: true }
    );
    watch(
        currency,
        (v) => {
            try {
                if (v) localStorage.setItem(currencyStorageKey, v);
            } catch (_) {}
        },
        { immediate: true }
    );

    function setLocale(v) {
        if (SUPPORTED_LOCALES.includes(v)) locale.value = v;
    }

    function setCurrency(v) {
        const fixed = resolvedFixedCurrencyCode();
        if (fixed) {
            currency.value = fixed;

            return;
        }
        const normalized = clampToAvailableCurrency(v);
        if (codes.includes(normalized)) {
            currency.value = normalized;
        }
    }

    function t(key) {
        const loc = locale.value || 'pt_BR';
        const byLocale = translations[loc] || translations.pt_BR || {};
        return byLocale[key] != null ? byLocale[key] : key;
    }

    const currencyList = computed(() => (Array.isArray(currencies) ? currencies : []));

    const featuredCurrencies = computed(() => {
        const list = currencyList.value;
        return FEATURED_CURRENCY_CODES.map((code) => list.find((c) => c.code === code)).filter(Boolean);
    });

    const otherCurrencies = computed(() => {
        const featuredSet = new Set(FEATURED_CURRENCY_CODES);
        return currencyList.value
            .filter((c) => c?.code && !featuredSet.has(c.code))
            .slice()
            .sort((a, b) => String(a.code).localeCompare(String(b.code)));
    });

    const sortedCurrenciesForSelector = computed(() => [...featuredCurrencies.value, ...otherCurrencies.value]);

    const currentCurrencyObj = computed(
        () => currencyList.value.find((c) => c.code === currency.value) || currencyList.value[0] || { code: 'BRL', symbol: 'R$', label: 'Real', rate_to_brl: 1 }
    );

    /** Converte preço em BRL para a moeda selecionada (price_brl * rate_to_brl), ou valor fixo do produto quando configurado. */
    function priceInCurrency(priceBrl, forCode) {
        const n = Number(priceBrl);
        if (Number.isNaN(n)) return 0;
        const code = forCode ?? currency.value;
        if (!toValue(skipCustomDisplayPrices) && code && code !== 'BRL') {
            const map = toValue(customDisplayPricesByCurrency);
            if (map && typeof map === 'object') {
                const custom = map[code] ?? map[String(code).toUpperCase()];
                const cnum = Number(custom);
                if (!Number.isNaN(cnum) && cnum > 0) {
                    return Math.round(cnum * 100) / 100;
                }
            }
        }
        const obj =
            currencyList.value.find((c) => c.code === code) ||
            currencyList.value[0] ||
            { code: 'BRL', symbol: 'R$', label: 'Real', rate_to_brl: 1 };
        const rate = Number(obj.rate_to_brl);
        if (!rate || rate <= 0 || Number.isNaN(rate)) {
            return Math.round(n * 100) / 100;
        }
        return Math.round(n * rate * 100) / 100;
    }

    function localeForCurrency(code) {
        const c = code || currency.value || 'BRL';
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).resolvedOptions().locale;
        } catch {
            return c === 'BRL' ? 'pt-BR' : 'en-US';
        }
    }

    function formatPrice(value, currencyCode) {
        const code = currencyCode || currency.value || 'BRL';
        const n = Number(value);
        if (Number.isNaN(n)) return String(value);
        try {
            return new Intl.NumberFormat(localeForCurrency(code), {
                style: 'currency',
                currency: code,
            }).format(n);
        } catch {
            return `${code} ${n.toFixed(2)}`;
        }
    }

    return {
        locale,
        setLocale,
        currency,
        setCurrency,
        t,
        currencies: currencyList,
        featuredCurrencies,
        otherCurrencies,
        sortedCurrenciesForSelector,
        currentCurrencyObj,
        priceInCurrency,
        formatPrice,
        supportedLocales: SUPPORTED_LOCALES,
    };
}
