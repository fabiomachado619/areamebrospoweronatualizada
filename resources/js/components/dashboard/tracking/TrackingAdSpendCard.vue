<script setup>
import { ref, computed } from 'vue';
import { Pencil, Check, X } from 'lucide-vue-next';
import { formatBRL } from '@/composables/useTrackingPanel';

const props = defineProps({
    amount: { type: Number, default: 0 },
    adSpendMeta: { type: Object, default: () => ({}) },
    period: { type: String, default: 'hoje' },
    valuesVisible: { type: Boolean, default: true },
});

const emit = defineEmits(['save-daily', 'save-period', 'clear-period']);

const editing = ref(false);
const inputValue = ref('0');
const usePeriodOverride = ref(false);

function startEdit() {
    inputValue.value = String(props.amount ?? 0);
    usePeriodOverride.value = props.adSpendMeta?.override ?? false;
    editing.value = true;
}

function cancelEdit() {
    editing.value = false;
}

async function save() {
    const amount = parseFloat(String(inputValue.value).replace(',', '.')) || 0;
    if (usePeriodOverride.value || props.period !== 'hoje') {
        emit('save-period', amount);
    } else {
        const today = new Date().toISOString().slice(0, 10);
        emit('save-daily', { date: today, amount });
    }
    editing.value = false;
}

const displayAmount = computed(() =>
    props.valuesVisible ? formatBRL(props.amount) : '••••••'
);

const subtitle = computed(() => {
    if (props.adSpendMeta?.override) {
        return 'Valor único do período';
    }
    return props.period === 'hoje' ? 'Editável — hoje' : 'Soma diária do período';
});
</script>

<template>
    <div class="panel-card-md relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-orange-500/5 via-transparent to-transparent" aria-hidden="true" />
        <div class="relative flex items-start justify-between gap-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Gasto em anúncios</p>
                <p v-if="!editing" class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">{{ displayAmount }}</p>
                <div v-else class="mt-2 flex items-center gap-2">
                    <span class="text-lg text-zinc-500">R$</span>
                    <input
                        v-model="inputValue"
                        type="number"
                        min="0"
                        step="0.01"
                        class="w-28 rounded-lg border border-zinc-200 bg-white px-2 py-1 text-lg font-bold dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    />
                </div>
                <p class="mt-1 text-xs text-zinc-500">{{ subtitle }}</p>
            </div>
            <button
                v-if="!editing"
                type="button"
                class="flex h-9 w-9 items-center justify-center rounded-xl border border-zinc-200/80 text-zinc-500 transition hover:border-[var(--color-primary)]/40 hover:text-[var(--color-primary)] dark:border-zinc-700"
                aria-label="Editar gasto em anúncios"
                @click="startEdit"
            >
                <Pencil class="h-4 w-4" />
            </button>
            <div v-else class="flex gap-1">
                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl bg-[var(--color-primary)] text-white" @click="save">
                    <Check class="h-4 w-4" />
                </button>
                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl border border-zinc-200 text-zinc-500 dark:border-zinc-700" @click="cancelEdit">
                    <X class="h-4 w-4" />
                </button>
            </div>
        </div>
        <label v-if="editing && period !== 'hoje'" class="mt-3 flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
            <input v-model="usePeriodOverride" type="checkbox" class="rounded border-zinc-300" />
            Usar valor único para todo o período
        </label>
        <button
            v-if="adSpendMeta?.override && !editing"
            type="button"
            class="mt-2 text-xs text-[var(--color-primary)] hover:underline"
            @click="emit('clear-period')"
        >
            Voltar à soma diária
        </button>
    </div>
</template>
