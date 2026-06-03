import { CURRENCY_FLAG_COUNTRY } from './currencyFlagCountry';

const FLAGCDN_BASE = 'https://flagcdn.com/w40';
const WISE_FLAG_BASE = 'https://wise.com/public-resources/assets/flags/rectangle';

function countryCodeForCurrency(code) {
    const upper = String(code || '').trim().toUpperCase();
    if (!upper) return null;

    return CURRENCY_FLAG_COUNTRY[upper] || upper.slice(0, 2).toLowerCase();
}

/**
 * Ordered flag URLs to try (flagcdn country flag, then Wise currency flag).
 *
 * @param {string|null|undefined} code
 * @returns {string[]}
 */
export function currencyFlagUrls(code) {
    const upper = String(code || '').trim().toUpperCase();
    if (!upper) return [];

    const urls = [];
    const country = countryCodeForCurrency(upper);
    if (country) {
        urls.push(`${FLAGCDN_BASE}/${country}.png`);
    }
    urls.push(`${WISE_FLAG_BASE}/${upper.toLowerCase()}.png`);

    return [...new Set(urls)];
}

/**
 * @param {string|null|undefined} code
 * @returns {string|null}
 */
export function currencyFlagUrl(code) {
    const urls = currencyFlagUrls(code);

    return urls[0] ?? null;
}
