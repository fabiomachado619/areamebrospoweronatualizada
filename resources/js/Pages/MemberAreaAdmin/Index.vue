<script setup>
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import MemberAreaTabs from '@/components/member-area-admin/MemberAreaTabs.vue';
import CursosTab from '@/components/member-area-admin/CursosTab.vue';
import VitrineEditor from '@/components/member-area-admin/VitrineEditor.vue';
import ConfiguracoesTab from '@/components/member-area-admin/ConfiguracoesTab.vue';
import AlunosPanel from '@/components/member-area-admin/AlunosPanel.vue';
import WebhooksTab from '@/components/member-area-admin/WebhooksTab.vue';
import PwaTab from '@/components/member-area-admin/PwaTab.vue';

defineOptions({ layout: LayoutInfoprodutor });

defineProps({
    tab: { type: String, default: 'cursos' },
    member_area: { type: Object, required: true },
    member_area_id: { type: String, required: true },
    courses: { type: Array, default: () => [] },
    vitrine_sections: { type: Array, default: () => [] },
    alunos: { type: [Array, Object], default: () => [] },
    produtos: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'todos' },
    product_ids_filter: { type: Array, default: () => [] },
    q: { type: String, default: '' },
    webhooks: { type: Array, default: () => [] },
    webhook_logs: { type: Array, default: () => [] },
    webhook_url_pattern: { type: String, default: '' },
    webhook_course_options: { type: Array, default: () => [] },
    webhook_platform_options: { type: Array, default: () => [] },
    pwa_settings: { type: Object, default: () => ({}) },
    member_area_url: { type: String, default: '' },
    manifest_url: { type: String, default: '' },
    hub_slug: { type: String, default: '' },
    hub_name: { type: String, default: '' },
    upload_limits: { type: Object, default: () => ({}) },
    pwa_status: { type: Object, default: () => ({}) },
});

function refreshPage() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white sm:text-2xl">Área de Membros</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Organize cursos, vitrine e alunos em um só lugar.
            </p>
        </div>

        <MemberAreaTabs />

        <CursosTab
            v-if="tab === 'cursos'"
            :courses="courses"
        />

        <VitrineEditor
            v-else-if="tab === 'vitrine'"
            :hub-id="member_area_id"
            :sections="vitrine_sections"
            :linked-courses="courses"
            @refresh="refreshPage"
        />

        <AlunosPanel
            v-else-if="tab === 'alunos'"
            :alunos="alunos"
            :produtos="produtos"
            :stats="stats"
            :filter="filter"
            :product_ids_filter="product_ids_filter"
            :q="q"
            list-path="/area-membros-admin"
            :fixed-query="{ tab: 'alunos' }"
            product-filter-label="Cursos"
            stats-products-label="Cursos com alunos"
            table-products-label="Cursos"
            enable-resend-access-email
        />

        <WebhooksTab
            v-else-if="tab === 'webhooks'"
            :webhooks="webhooks"
            :webhook-logs="webhook_logs"
            :webhook-url-pattern="webhook_url_pattern"
            :webhook-course-options="webhook_course_options"
            :webhook-platform-options="webhook_platform_options"
            @refresh="refreshPage"
        />

        <PwaTab
            v-else-if="tab === 'pwa'"
            :pwa-settings="pwa_settings"
            :member-area-url="member_area_url"
            :manifest-url="manifest_url"
            :hub-slug="hub_slug"
            :hub-name="hub_name"
            :upload-limits="upload_limits"
            :pwa-status="pwa_status"
            @refresh="refreshPage"
        />

        <ConfiguracoesTab
            v-else-if="tab === 'configuracoes'"
            :member-area="member_area"
            @refresh="refreshPage"
        />
    </div>
</template>
