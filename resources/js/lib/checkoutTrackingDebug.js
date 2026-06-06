/**
 * Diagnóstico leve de tracking no checkout (?tracking_debug=1 ou localStorage).
 */

const STORAGE_KEY = 'getfy_tracking_debug';

export function isTrackingDebugEnabled() {
    if (typeof window === 'undefined') {
        return false;
    }
    try {
        if (window.location.search.includes('tracking_debug=1')) {
            return true;
        }
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export function logTrackingDebug(label, detail) {
    if (!isTrackingDebugEnabled()) {
        return;
    }
    const msg = detail !== undefined ? detail : '';
    console.info(`[Power On Tracking] ${label}`, msg);
}

export function logTrackingApiError(endpoint, error) {
    if (!isTrackingDebugEnabled() && !import.meta.env.DEV) {
        return;
    }
    const status = error?.response?.status;
    const message = error?.response?.data?.message || error?.message || 'erro desconhecido';
    console.warn(`[Power On Tracking] Falha ${endpoint}${status ? ` (${status})` : ''}: ${message}`);
}
