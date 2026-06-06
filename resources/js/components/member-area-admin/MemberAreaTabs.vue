<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, LayoutGrid, Users, Settings, Webhook, Smartphone } from 'lucide-vue-next';

const page = usePage();
const tab = computed(() => page.props.tab ?? 'cursos');

const tabs = [
    { id: 'cursos', label: 'Cursos', icon: BookOpen },
    { id: 'vitrine', label: 'Vitrine', icon: LayoutGrid },
    { id: 'alunos', label: 'Alunos', icon: Users },
    { id: 'webhooks', label: 'Webhooks', icon: Webhook },
    { id: 'pwa', label: 'PWA', icon: Smartphone },
    { id: 'configuracoes', label: 'Configurações', icon: Settings },
];

function tabHref(id) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    if (id !== 'alunos') {
        url.searchParams.delete('page');
        url.searchParams.delete('filter');
        url.searchParams.delete('q');
        url.searchParams.delete('product_ids');
    }
    return url.pathname + url.search;
}
</script>

<template>
    <nav class="inline-flex flex-wrap gap-1 rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80" aria-label="Área de membros">
        <Link
            v-for="item in tabs"
            :key="item.id"
            :href="tabHref(item.id)"
            preserve-scroll
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                tab === item.id
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <component :is="item.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ item.label }}
        </Link>
    </nav>
</template>
