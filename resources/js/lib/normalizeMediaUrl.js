/**
 * Normaliza paths/URLs de mídia para exibição no frontend.
 * Alinha com StorageService::resolvePublicUrl no backend.
 */
export function normalizeMediaUrl(value) {
    if (value == null || typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
        return trimmed;
    }

    if (trimmed.startsWith('/storage/')) {
        return trimmed;
    }

    const path = trimmed.replace(/^\/+/, '');
    if (path.startsWith('storage/')) {
        return `/${path}`;
    }

    return `/storage/${path}`;
}

/** @param {{ thumbnail?: string|null, thumbnail_url?: string|null }|null|undefined} mod */
export function moduleCoverUrl(mod) {
    if (!mod) {
        return null;
    }

    return normalizeMediaUrl(mod.thumbnail_url || mod.thumbnail);
}
