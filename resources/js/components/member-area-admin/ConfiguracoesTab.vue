<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import { Copy, Check, Save } from 'lucide-vue-next';

const props = defineProps({
    memberArea: { type: Object, required: true },
});

const emit = defineEmits(['refresh']);

const myCoursesTitle = ref(props.memberArea?.my_courses_title ?? 'Meus Cursos');
const myCoursesCoverMode = ref(props.memberArea?.my_courses_cover_mode ?? 'vertical');
const emailSubject = ref(props.memberArea?.email_subject ?? '');
const emailBodyText = ref(props.memberArea?.email_body_text ?? '');
const saving = ref(false);
const copied = ref(false);
const toast = ref(null);

watch(() => props.memberArea, (area) => {
    if (!area) return;
    myCoursesTitle.value = area.my_courses_title ?? 'Meus Cursos';
    myCoursesCoverMode.value = area.my_courses_cover_mode ?? 'vertical';
    emailSubject.value = area.email_subject ?? '';
    emailBodyText.value = area.email_body_text ?? '';
}, { deep: true });

async function saveSettings() {
    saving.value = true;
    try {
        await axios.post('/area-membros-admin/configuracoes', {
            my_courses_title: myCoursesTitle.value,
            my_courses_cover_mode: myCoursesCoverMode.value,
            email_subject: emailSubject.value,
            email_body_text: emailBodyText.value,
        });
        showToast('Configurações salvas.', 'success');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao salvar.', 'error');
    } finally {
        saving.value = false;
    }
}

async function copyLink() {
    if (!props.memberArea?.member_area_url) return;
    try {
        await navigator.clipboard.writeText(props.memberArea.member_area_url);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        showToast('Não foi possível copiar o link.', 'error');
    }
}

function showToast(message, type) {
    toast.value = { message, type };
    setTimeout(() => { toast.value = null; }, 4000);
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Área de membros</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Link de entrada unificada para os alunos acessarem cursos e vitrine.
            </p>
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="text-xs font-medium uppercase text-zinc-500">Link da área de membros</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code class="break-all text-sm text-zinc-800 dark:text-zinc-200">{{ memberArea.member_area_url }}</code>
                    <Button variant="outline" size="sm" @click="copyLink">
                        <Check v-if="copied" class="h-4 w-4" />
                        <Copy v-else class="h-4 w-4" />
                        Copiar
                    </Button>
                </div>
            </div>
        </section>

        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Seção &quot;Meus Cursos&quot;</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Título</label>
                    <input v-model="myCoursesTitle" type="text" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Layout dos cards</label>
                    <select v-model="myCoursesCoverMode" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                        <option value="vertical">Vertical</option>
                        <option value="horizontal">Horizontal</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">E-mail de acesso</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Modelo básico usado ao liberar acesso manualmente ou via integrações. Variáveis: <code>{nome_cliente}</code>, <code>{link_acesso}</code>, <code>{nome_produto}</code>
            </p>
            <div class="space-y-1">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Assunto</label>
                <input v-model="emailSubject" type="text" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Corpo (texto)</label>
                <textarea v-model="emailBodyText" rows="6" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" placeholder="Olá, {nome_cliente}! Seu acesso: {link_acesso}" />
            </div>
            <div class="flex justify-end">
                <Button variant="primary" :disabled="saving" @click="saveSettings">
                    <Save class="h-4 w-4" />
                    Salvar configurações
                </Button>
            </div>
        </section>

        <Teleport to="body">
            <div
                v-if="toast"
                role="alert"
                :class="[
                    'fixed bottom-4 right-4 z-[100002] max-w-sm rounded-xl border px-4 py-3 shadow-lg text-sm font-medium',
                    toast.type === 'error'
                        ? 'border-red-200 bg-red-50 text-red-800'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                ]"
            >
                {{ toast.message }}
            </div>
        </Teleport>
    </div>
</template>
