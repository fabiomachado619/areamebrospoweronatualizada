<script setup>
import { Radar } from 'lucide-vue-next';

defineProps({
    active: { type: Boolean, default: false },
});

const emit = defineEmits(['click']);
</script>

<template>
    <button
        type="button"
        :aria-label="active ? 'Painel de Tracking aberto' : 'Abrir painel de Tracking'"
        :aria-pressed="active"
        class="relative flex h-11 shrink-0 items-center justify-center gap-2 rounded-2xl border border-zinc-200/80 bg-zinc-100/90 text-[var(--color-primary)] transition-colors hover:border-[var(--color-primary)]/30 hover:bg-white lg:px-3.5 dark:border-zinc-700/80 dark:bg-zinc-800/50 dark:hover:bg-zinc-800"
        :class="[
            active ? 'border-[var(--color-primary)]/40 bg-[var(--color-primary)]/10' : '',
            'h-11 w-11 lg:w-auto',
        ]"
        @click="emit('click')"
    >
        <Radar class="h-5 w-5 shrink-0" aria-hidden="true" />
        <span class="hidden text-sm font-semibold lg:inline">Tracking</span>
        <span
            v-if="!active"
            class="absolute right-2 top-2 h-1.5 w-1.5 rounded-full bg-[var(--color-primary)] lg:right-2 lg:top-2"
            aria-hidden="true"
        />
    </button>
</template>
