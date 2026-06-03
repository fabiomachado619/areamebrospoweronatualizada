<script setup>
import { computed } from 'vue';
import CheckoutContentBlock from './CheckoutContentBlock.vue';
import { filterContentBlocks } from '@/lib/checkoutContentFormats';

const props = defineProps({
    /** @deprecated Prefer blocks prop */
    urls: { type: Array, default: () => [] },
    blocks: { type: Array, default: () => [] },
    /** hero | side | content */
    placement: { type: String, default: 'top' },
    classImg: { type: String, default: '' },
});

const resolvedBlocks = computed(() => {
    if (Array.isArray(props.blocks) && props.blocks.length) {
        if (props.placement === 'top') {
            return filterContentBlocks(props.blocks, { type: 'image', format: 'hero', placement: 'main' });
        }
        if (props.placement === 'side') {
            return filterContentBlocks(props.blocks, { type: 'image', format: 'portrait', placement: 'sidebar' });
        }
        return filterContentBlocks(props.blocks, { placement: 'main' });
    }

    const format = props.placement === 'side' ? 'portrait' : 'hero';
    const blockPlacement = props.placement === 'side' ? 'sidebar' : 'main';

    return (props.urls ?? [])
        .filter(Boolean)
        .map((url, i) => ({
            id: `legacy-${i}`,
            type: 'image',
            url,
            format,
            placement: blockPlacement,
            link: '',
            alt: '',
        }));
});

const dataCheckout = computed(() => {
    if (props.placement === 'side') return 'banners-side';
    return 'banners-top';
});
</script>

<template>
    <div
        v-if="resolvedBlocks.length"
        class="mb-6 space-y-5"
        :data-checkout="dataCheckout"
    >
        <CheckoutContentBlock
            v-for="block in resolvedBlocks"
            :key="block.id"
            :block="block"
            :data-placement="placement"
            :image-class="classImg"
        />
    </div>
</template>
