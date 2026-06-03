<script setup>
import { computed } from 'vue';
import CheckoutContentBlock from './CheckoutContentBlock.vue';

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    /** main | sidebar */
    placement: { type: String, default: 'main' },
    /** exclude hero from sales page stack */
    excludeHero: { type: Boolean, default: false },
});

const filteredBlocks = computed(() => {
    const list = Array.isArray(props.blocks) ? props.blocks : [];

    return list.filter((block) => {
        const blockPlacement = block.type === 'text' ? 'main' : (block.placement ?? 'main');
        if (blockPlacement !== props.placement) {
            return false;
        }
        if (props.excludeHero && block.type === 'image' && block.format === 'hero') {
            return false;
        }
        return true;
    });
});
</script>

<template>
    <div
        v-if="filteredBlocks.length"
        class="space-y-5"
        :data-checkout="placement === 'sidebar' ? 'banners-side' : 'sales-page'"
    >
        <CheckoutContentBlock
            v-for="block in filteredBlocks"
            :key="block.id"
            :block="block"
            :data-placement="placement"
        />
    </div>
</template>
