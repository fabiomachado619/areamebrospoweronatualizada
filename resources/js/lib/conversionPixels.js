/** UUID para chaves de lista; fallback quando `crypto.randomUUID` não existe. */
export function randomClientId() {
    const c = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined;
    if (c && typeof c.randomUUID === 'function') {
        return c.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (ch) => {
        const r = (Math.random() * 16) | 0;
        const v = ch === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

export const ENTRY_FLAGS = {
    fire_purchase_on_pix: true,
    fire_purchase_on_boleto: true,
    disable_order_bump_events: false,
};

export function newMetaEntry() {
    return { id: randomClientId(), pixel_id: '', access_token: '', ...ENTRY_FLAGS };
}

export function newTiktokEntry() {
    return { id: randomClientId(), pixel_id: '', access_token: '', ...ENTRY_FLAGS };
}

export function newGoogleAdsEntry() {
    return { id: randomClientId(), conversion_id: '', conversion_label: '', ...ENTRY_FLAGS };
}

export function newGaEntry() {
    return { id: randomClientId(), measurement_id: '', ...ENTRY_FLAGS };
}

export const DEFAULT_CONVERSION_PIXELS = {
    meta: { enabled: false, entries: [], integration_ids: [] },
    tiktok: { enabled: false, entries: [], integration_ids: [] },
    google_ads: { enabled: false, entries: [], integration_ids: [] },
    google_analytics: { enabled: false, entries: [], integration_ids: [] },
    custom_script: [],
    custom_script_integration_ids: [],
};

export const PIXEL_TABS = [
    { id: 'meta', label: 'Meta Ads', image: '/images/pixels/meta.png' },
    { id: 'tiktok', label: 'TikTok Ads', image: '/images/pixels/tiktok.png' },
    { id: 'google_ads', label: 'Google Ads', image: '/images/pixels/googleads.png' },
    { id: 'google_analytics', label: 'Google Analytics', image: '/images/pixels/google-analytics.png' },
    { id: 'custom_script', label: 'Script personalizado', image: '/images/pixels/script.png' },
];

/** Layout assimétrico do card em Integrações (sem script — só ads/analytics). */
export const PIXEL_CARD_LOGOS = [
    { id: 'tiktok', image: '/images/pixels/tiktok.png', left: 14, top: 72, rotate: -22, scale: 0.82, z: 2 },
    { id: 'google_ads', image: '/images/pixels/googleads.png', left: 34, top: 58, rotate: -11, scale: 0.9, z: 3 },
    { id: 'meta', image: '/images/pixels/meta.png', left: 58, top: 36, rotate: 4, scale: 1.14, z: 5 },
    { id: 'google_analytics', image: '/images/pixels/google-analytics.png', left: 86, top: 26, rotate: 18, scale: 0.86, z: 4 },
];

export function mergeConversionPixels(raw) {
    if (!raw || typeof raw !== 'object') return JSON.parse(JSON.stringify(DEFAULT_CONVERSION_PIXELS));
    const out = JSON.parse(JSON.stringify(DEFAULT_CONVERSION_PIXELS));

    function normalizeMetaLike(block, newEntryFn) {
        const enabled = !!block?.enabled;
        if (Array.isArray(block?.entries)) {
            return {
                enabled,
                entries: block.entries
                    .filter((e) => e && typeof e === 'object')
                    .map((e) => ({ ...newEntryFn(), ...e, id: e.id || randomClientId() })),
            };
        }
        if (block?.pixel_id != null || block?.access_token != null) {
            const pixel_id = String(block.pixel_id ?? '').trim();
            const access_token = String(block.access_token ?? '').trim();
            if (pixel_id || access_token) {
                return {
                    enabled,
                    entries: [
                        {
                            id: randomClientId(),
                            pixel_id,
                            access_token,
                            fire_purchase_on_pix: block.fire_purchase_on_pix !== false,
                            fire_purchase_on_boleto: block.fire_purchase_on_boleto !== false,
                            disable_order_bump_events: !!block.disable_order_bump_events,
                        },
                    ],
                };
            }
        }
        return { enabled, entries: [] };
    }

    function normalizeGoogleAdsBlock(block) {
        const enabled = !!block?.enabled;
        if (Array.isArray(block?.entries)) {
            return {
                enabled,
                entries: block.entries
                    .filter((e) => e && typeof e === 'object')
                    .map((e) => ({ ...newGoogleAdsEntry(), ...e, id: e.id || randomClientId() })),
            };
        }
        const conversion_id = String(block?.conversion_id ?? '').trim();
        if (conversion_id) {
            return {
                enabled,
                entries: [
                    {
                        id: randomClientId(),
                        conversion_id,
                        conversion_label: String(block.conversion_label ?? '').trim(),
                        fire_purchase_on_pix: block.fire_purchase_on_pix !== false,
                        fire_purchase_on_boleto: block.fire_purchase_on_boleto !== false,
                        disable_order_bump_events: !!block.disable_order_bump_events,
                    },
                ],
            };
        }
        return { enabled, entries: [] };
    }

    function normalizeGaBlock(block) {
        const enabled = !!block?.enabled;
        if (Array.isArray(block?.entries)) {
            return {
                enabled,
                entries: block.entries
                    .filter((e) => e && typeof e === 'object')
                    .map((e) => ({ ...newGaEntry(), ...e, id: e.id || randomClientId() })),
            };
        }
        const measurement_id = String(block?.measurement_id ?? '').trim();
        if (measurement_id) {
            return {
                enabled,
                entries: [
                    {
                        id: randomClientId(),
                        measurement_id,
                        fire_purchase_on_pix: block.fire_purchase_on_pix !== false,
                        fire_purchase_on_boleto: block.fire_purchase_on_boleto !== false,
                        disable_order_bump_events: !!block.disable_order_bump_events,
                    },
                ],
            };
        }
        return { enabled, entries: [] };
    }

    function attachPlatformExtras(block, normalized) {
        const result = { ...normalized };
        if (Array.isArray(block?.integration_ids)) {
            result.integration_ids = block.integration_ids
                .map((id) => parseInt(id, 10))
                .filter((id) => !Number.isNaN(id));
        }
        for (const key of ['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events']) {
            if (block && Object.prototype.hasOwnProperty.call(block, key)) {
                result[key] = !!block[key];
            } else if (normalized.entries?.[0] && Object.prototype.hasOwnProperty.call(normalized.entries[0], key)) {
                result[key] = !!normalized.entries[0][key];
            }
        }
        return result;
    }

    if (raw.meta && typeof raw.meta === 'object') {
        out.meta = attachPlatformExtras(raw.meta, normalizeMetaLike(raw.meta, newMetaEntry));
    }
    if (raw.tiktok && typeof raw.tiktok === 'object') {
        out.tiktok = attachPlatformExtras(raw.tiktok, normalizeMetaLike(raw.tiktok, newTiktokEntry));
    }
    if (raw.google_ads && typeof raw.google_ads === 'object') {
        out.google_ads = attachPlatformExtras(raw.google_ads, normalizeGoogleAdsBlock(raw.google_ads));
    }
    if (raw.google_analytics && typeof raw.google_analytics === 'object') {
        out.google_analytics = attachPlatformExtras(raw.google_analytics, normalizeGaBlock(raw.google_analytics));
    }
    out.custom_script = Array.isArray(raw.custom_script)
        ? raw.custom_script
              .filter((s) => s && typeof s === 'object')
              .map((s) => ({ id: s.id || randomClientId(), name: s.name || '', script: s.script || '' }))
        : [];
    out.custom_script_integration_ids = Array.isArray(raw.custom_script_integration_ids)
        ? raw.custom_script_integration_ids
              .map((id) => parseInt(id, 10))
              .filter((id) => !Number.isNaN(id))
        : [];

    return out;
}
