<script setup>
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AlunosPanel from '@/components/member-area-admin/AlunosPanel.vue';

defineOptions({ layout: LayoutInfoprodutor });

defineProps({
    alunos: { type: [Array, Object], default: () => [] },
    produtos: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'todos' },
    product_ids_filter: { type: Array, default: () => [] },
    q: { type: String, default: '' },
});
</script>

<template>
    <AlunosPanel
        v-bind="$props"
        show-produtos-tabs
        list-path="/produtos/alunos"
    />
</template>
