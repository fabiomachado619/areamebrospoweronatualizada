<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PIXEL_TABS } from '@/lib/conversionPixels';

const props = defineProps({
    integrations: { type: Array, default: () => [] },
});

const grouped = computed(() => {
    const map = {};
    for (const tab of PIXEL_TABS) {
        map[tab.id] = [];
    }
    for (const item of props.integrations || []) {
        const key = item.platform || 'meta';
        if (!map[key]) map[key] = [];
        map[key].push(item);
    }
    return map;
});

const hasAny = computed(() => (props.integrations || []).length > 0);
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Os pixels são cadastrados em
            <Link href="/integracoes" class="font-medium text-[var(--color-primary)] underline">Integrações → Pixels e rastreamento</Link>.
            Marque os produtos em cada pixel (como nos webhooks).
        </p>

        <div v-if="hasAny" class="space-y-4">
            <div
                v-for="tab in PIXEL_TABS"
                :key="tab.id"
                v-show="grouped[tab.id]?.length"
                class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-800/30"
            >
                <h3 class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">{{ tab.label }}</h3>
                <ul class="space-y-2">
                    <li
                        v-for="item in grouped[tab.id]"
                        :key="item.id"
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <span class="font-medium text-zinc-900 dark:text-white">{{ item.name }}</span>
                        <span class="block text-xs text-zinc-500">{{ item.summary }}</span>
                        <span
                            class="mt-1 inline-block text-xs"
                            :class="item.is_active ? 'text-emerald-600' : 'text-zinc-400'"
                        >
                            {{ item.is_active ? 'Ativo' : 'Inativo' }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <p v-else class="rounded-xl border border-dashed border-zinc-300 px-4 py-6 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
            Nenhum pixel vinculado a este produto.
            <Link href="/integracoes" class="mt-1 block font-medium text-[var(--color-primary)] underline">
                Configurar em Integrações
            </Link>
        </p>
    </div>
</template>
