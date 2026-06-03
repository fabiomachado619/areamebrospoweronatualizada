const DEFAULT_MAX_RETRIES = 2;
const DEFAULT_BASE_DELAY_MS = 400;

function hideImageElement(img) {
    if (img?.style) {
        img.style.display = 'none';
    }
}

/**
 * Retries loading an image on transient failure before hiding it.
 * Useful when /storage/ is served via PHP and the first request may time out on cold start.
 *
 * @param {Event} event
 * @param {{ maxRetries?: number, baseDelayMs?: number, onGiveUp?: (el: HTMLImageElement) => void }} [options]
 */
export function retryImageOnError(event, options = {}) {
    const el = event?.target;
    if (!el || el.tagName !== 'IMG') {
        return;
    }

    const maxRetries = options.maxRetries ?? DEFAULT_MAX_RETRIES;
    const baseDelayMs = options.baseDelayMs ?? DEFAULT_BASE_DELAY_MS;
    const onGiveUp = options.onGiveUp ?? hideImageElement;

    const originalSrc = el.dataset.retrySrc || el.getAttribute('src') || el.src;
    if (!originalSrc) {
        onGiveUp(el);
        return;
    }

    if (!el.dataset.retrySrc) {
        el.dataset.retrySrc = originalSrc;
    }

    const attempt = Number(el.dataset.retryAttempt || '0');
    if (attempt >= maxRetries) {
        onGiveUp(el);
        return;
    }

    el.dataset.retryAttempt = String(attempt + 1);
    el.style.removeProperty('display');

    const delayMs = baseDelayMs * (attempt + 1);
    window.setTimeout(() => {
        if (!el.isConnected) {
            return;
        }
        // Force reload of the same URL; on last retry add cache-bust for stubborn caches.
        if (attempt + 1 >= maxRetries) {
            const separator = originalSrc.includes('?') ? '&' : '?';
            el.src = `${originalSrc}${separator}_retry=${Date.now()}`;
        } else {
            el.src = '';
            el.src = originalSrc;
        }
    }, delayMs);
}

/** @deprecated Prefer retryImageOnError — kept as alias for existing imports. */
export function hideImageOnError(event) {
    retryImageOnError(event);
}
