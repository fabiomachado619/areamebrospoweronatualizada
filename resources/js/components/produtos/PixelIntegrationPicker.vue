<script setup>
import { computed } from 'vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    platform: { type: String, required: true },
    block: { type: Object, required: true },
    integrations: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});

const selectedIds = computed(() => {
    const ids = props.block?.integration_ids;
    return Array.isArray(ids) ? ids.map((id) => Number(id)) : [];
});

const usesIntegrations = computed(() => selectedIds.value.length > 0);

function isSelected(id) {
    return selectedIds.value.includes(Number(id));
}

function toggleIntegration(id, checked) {
    if (!Array.isArray(props.block.integration_ids)) {
        props.block.integration_ids = [];
    }
    const numId = Number(id);
    if (checked) {
        if (!props.block.integration_ids.includes(numId)) {
            props.block.integration_ids.push(numId);
        }
        props.block.entries = [];
    } else {
        props.block.integration_ids = props.block.integration_ids.filter((x) => x !== numId);
    }
}

function ensureBlockFlags() {
    if (props.block.fire_purchase_on_pix === undefined) {
        props.block.fire_purchase_on_pix = true;
    }
    if (props.block.fire_purchase_on_boleto === undefined) {
        props.block.fire_purchase_on_boleto = true;
    }
    if (props.block.disable_order_bump_events === undefined) {
        props.block.disable_order_bump_events = false;
    }
}

function onUseIntegrationsChange(checked) {
    if (checked) {
        ensureBlockFlags();
        if (!Array.isArray(props.block.integration_ids)) {
            props.block.integration_ids = [];
        }
    } else {
        props.block.integration_ids = [];
    }
}
</script>

<template>
    <div class="space-y-3 rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-800/30">
        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Integrações cadastradas</p>
        <p v-if="integrations.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">
            Nenhuma integração nesta plataforma.
            <Link href="/integracoes" class="text-[var(--color-primary)] underline">Cadastrar em Integrações</Link>
        </p>
        <template v-else>
            <Checkbox
                :model-value="usesIntegrations"
                label="Usar integrações cadastradas (recomendado)"
                :disabled="disabled"
                @update:model-value="onUseIntegrationsChange"
            />
            <div v-if="usesIntegrations" class="space-y-2 pl-1">
                <label
                    v-for="item in integrations"
                    :key="item.id"
                    class="flex cursor-pointer items-start gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <input
                        type="checkbox"
                        class="mt-1 rounded border-zinc-300"
                        :checked="isSelected(item.id)"
                        :disabled="disabled"
                        @change="toggleIntegration(item.id, $event.target.checked)"
                    />
                    <span class="min-w-0 text-sm">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ item.name }}</span>
                        <span class="block text-xs text-zinc-500">{{ item.summary }}</span>
                    </span>
                </label>
            </div>
            <div v-if="usesIntegrations" class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                <Checkbox v-model="block.fire_purchase_on_pix" label="Disparar Purchase ao gerar PIX (não na aprovação)?" :disabled="disabled" />
                <Checkbox v-model="block.fire_purchase_on_boleto" label="Disparar Purchase ao gerar boleto (não na aprovação)?" :disabled="disabled" />
                <Checkbox v-model="block.disable_order_bump_events" label="Desativar eventos de order bumps?" :disabled="disabled" />
            </div>
        </template>
    </div>
</template>
