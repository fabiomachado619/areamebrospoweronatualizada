/** Protocolo de preview ao vivo entre Checkout/Builder e Checkout/Show (iframe). */

export const PREVIEW_MESSAGE_TYPE = 'checkout-builder-preview-config';
export const PREVIEW_ACK_TYPE = 'checkout-builder-preview-ack';
export const PREVIEW_WINDOW_CALLBACK = '__checkoutBuilderApplyPreview';
export const PREVIEW_CHANNEL_NAME = 'checkout-builder-preview';

export function isCheckoutBuilderPreviewUrl() {
    if (typeof window === 'undefined') {
        return false;
    }

    return new URLSearchParams(window.location.search).get('preview') === '1';
}

/**
 * @param {object} payload
 */
export function broadcastPreviewPayload(payload) {
    if (typeof BroadcastChannel === 'undefined') {
        return;
    }
    try {
        const channel = new BroadcastChannel(PREVIEW_CHANNEL_NAME);
        channel.postMessage(payload);
        channel.close();
    } catch (_) {}
}

/**
 * @param {(payload: object) => void} onPayload
 * @returns {() => void}
 */
export function subscribePreviewBroadcast(onPayload) {
    if (typeof BroadcastChannel === 'undefined') {
        return () => {};
    }

    const channel = new BroadcastChannel(PREVIEW_CHANNEL_NAME);
    const handler = (event) => {
        if (event?.data?.type === PREVIEW_MESSAGE_TYPE) {
            onPayload(event.data);
        }
    };
    channel.addEventListener('message', handler);

    return () => {
        channel.removeEventListener('message', handler);
        channel.close();
    };
}
