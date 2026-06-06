<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import {
    Copy, Check, Save, Smartphone, ExternalLink, FileJson, Upload, X, Bell,
} from 'lucide-vue-next';

const props = defineProps({
    pwaSettings: { type: Object, default: () => ({}) },
    memberAreaUrl: { type: String, default: '' },
    manifestUrl: { type: String, default: '' },
    hubSlug: { type: String, default: '' },
    hubName: { type: String, default: '' },
    uploadLimits: { type: Object, default: () => ({ image_max_mb: 10 }) },
    pwaStatus: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['refresh']);

const form = ref({
    name: '',
    short_name: '',
    favicon: '',
    theme_color: '#0ea5e9',
    background_color: '#18181b',
    push_enabled: false,
});

const saving = ref(false);
const uploading = ref(false);
const copied = ref(false);
const toast = ref(null);
const fileInput = ref(null);

function syncFormFromProps() {
    const s = props.pwaSettings ?? {};
    form.value = {
        name: s.name ?? '',
        short_name: s.short_name ?? '',
        favicon: s.favicon ?? '',
        theme_color: s.theme_color ?? '#0ea5e9',
        background_color: s.background_color ?? '#18181b',
        push_enabled: !!s.push_enabled,
    };
}

watch(() => props.pwaSettings, syncFormFromProps, { deep: true, immediate: true });

const previewAppName = computed(() => {
    const name = form.value.name.trim();
    if (name) return name.toUpperCase();
    const fallback = props.hubName || 'Área de Membros';
    return fallback.toUpperCase();
});

const previewShortName = computed(() => {
    const short = form.value.short_name.trim();
    if (short) return short;
    const name = form.value.name.trim();
    if (name) return name.length > 12 ? `${name.slice(0, 12)}…` : name;
    return props.hubName || 'App';
});

const statusItems = computed(() => [
    {
        key: 'manifest',
        label: 'Manifest encontrado',
        ok: props.pwaStatus?.manifest_ok !== false,
        hint: props.pwaStatus?.manifest_ok === false ? 'Verifique se o HUB possui slug válido.' : null,
    },
    {
        key: 'sw',
        label: 'Service Worker configurado',
        ok: props.pwaStatus?.service_worker_ok !== false,
        hint: props.pwaStatus?.service_worker_ok === false ? 'Arquivo member-area-sw.js não encontrado no servidor.' : null,
    },
    {
        key: 'install',
        label: 'Install Prompt disponível',
        ok: props.pwaStatus?.install_prompt_ok !== false,
        hint: null,
    },
]);

function showToast(message, type = 'success') {
    toast.value = { message, type };
    setTimeout(() => { toast.value = null; }, 4500);
}

async function savePwa() {
    saving.value = true;
    try {
        const { data } = await axios.post('/area-membros-admin/pwa', { ...form.value });
        if (data.warning) {
            showToast(data.warning, 'error');
        } else {
            showToast('Configurações PWA salvas.');
        }
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao salvar.', 'error');
    } finally {
        saving.value = false;
    }
}

async function copyLink() {
    if (!props.memberAreaUrl) return;
    try {
        await navigator.clipboard.writeText(props.memberAreaUrl);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        showToast('Não foi possível copiar o link.', 'error');
    }
}

function openMemberArea() {
    if (props.memberAreaUrl) {
        window.open(props.memberAreaUrl, '_blank', 'noopener');
    }
}

function openManifest() {
    if (props.manifestUrl) {
        window.open(props.manifestUrl, '_blank', 'noopener');
    }
}

function pickIcon() {
    fileInput.value?.click();
}

async function onIconChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;

    uploading.value = true;
    const formData = new FormData();
    formData.append('file', file);

    try {
        const { data } = await axios.post('/area-membros-admin/pwa/upload-icon', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        form.value.favicon = data.url ?? form.value.favicon;
        showToast('Ícone atualizado.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro no upload.', 'error');
    } finally {
        uploading.value = false;
        if (fileInput.value) fileInput.value.value = '';
    }
}

async function removeIcon() {
    form.value.favicon = '';
    await savePwa();
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <!-- Preview -->
        <section class="panel-card-md">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Pré-visualização</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Como o aplicativo aparecerá quando instalado no celular ou computador.
            </p>
            <div class="mt-4 flex flex-wrap items-center gap-6">
                <div
                    class="flex h-28 w-28 flex-col items-center justify-center rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700"
                    :style="{ backgroundColor: form.background_color }"
                >
                    <img
                        v-if="form.favicon"
                        :src="form.favicon"
                        alt="Ícone"
                        class="h-14 w-14 rounded-xl object-cover"
                    />
                    <div
                        v-else
                        class="flex h-14 w-14 items-center justify-center rounded-xl text-2xl font-bold text-white"
                        :style="{ backgroundColor: form.theme_color }"
                    >
                        {{ previewShortName.charAt(0).toUpperCase() }}
                    </div>
                    <p class="mt-2 max-w-[6.5rem] truncate text-center text-[10px] font-medium text-zinc-700 dark:text-zinc-300">
                        {{ previewShortName }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Nome do aplicativo</p>
                    <p class="mt-1 text-lg font-bold tracking-wide text-zinc-900 dark:text-white">
                        {{ previewAppName }}
                    </p>
                    <p class="mt-2 text-sm text-zinc-500">
                        Nome curto: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ previewShortName }}</span>
                    </p>
                </div>
            </div>
        </section>

        <!-- Form -->
        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Aparência do aplicativo</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="text-xs font-medium text-zinc-500">Nome do aplicativo</span>
                    <input
                        v-model="form.name"
                        type="text"
                        class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        placeholder="Ex: Power On Treinamentos"
                    />
                </label>
                <label class="block sm:col-span-2">
                    <span class="text-xs font-medium text-zinc-500">Nome curto</span>
                    <input
                        v-model="form.short_name"
                        type="text"
                        maxlength="32"
                        class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        placeholder="Ex: Power On"
                    />
                    <p class="mt-1 text-xs text-zinc-500">Exibido abaixo do ícone na tela inicial.</p>
                </label>

                <div class="block sm:col-span-2">
                    <span class="text-xs font-medium text-zinc-500">Ícone do aplicativo</span>
                    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onIconChange" />
                    <div class="mt-2 flex flex-wrap items-center gap-4">
                        <div
                            v-if="form.favicon"
                            class="relative h-20 w-20 shrink-0 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-600"
                        >
                            <img :src="form.favicon" alt="Preview ícone" class="h-full w-full object-cover" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" :disabled="uploading" @click="pickIcon">
                                <Upload class="h-4 w-4" />
                                {{ uploading ? 'Enviando…' : (form.favicon ? 'Trocar ícone' : 'Enviar ícone') }}
                            </Button>
                            <Button v-if="form.favicon" variant="ghost" size="sm" class="text-red-600" :disabled="uploading || saving" @click="removeIcon">
                                <X class="h-4 w-4" />
                                Remover
                            </Button>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">
                        Use imagem quadrada. Recomendado: 512×512px. Máx. {{ uploadLimits.image_max_mb }} MB.
                    </p>
                </div>

                <label class="block">
                    <span class="text-xs font-medium text-zinc-500">Cor principal</span>
                    <div class="mt-1 flex items-center gap-2">
                        <input v-model="form.theme_color" type="color" class="h-10 w-12 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600" />
                        <input v-model="form.theme_color" type="text" maxlength="7" class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                    </div>
                    <p class="mt-1 text-xs text-zinc-500">Barra de status do PWA.</p>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-zinc-500">Cor de fundo</span>
                    <div class="mt-1 flex items-center gap-2">
                        <input v-model="form.background_color" type="color" class="h-10 w-12 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600" />
                        <input v-model="form.background_color" type="text" maxlength="7" class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                    </div>
                    <p class="mt-1 text-xs text-zinc-500">Tela de splash ao abrir o app.</p>
                </label>
            </div>

            <div class="flex justify-end">
                <Button :disabled="saving || uploading" @click="savePwa">
                    <Save class="h-4 w-4" />
                    {{ saving ? 'Salvando…' : 'Salvar PWA' }}
                </Button>
            </div>
        </section>

        <!-- Member area URL -->
        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Área de Membros</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Link usado pelos alunos e nos e-mails de acesso.
            </p>
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="text-xs font-medium uppercase text-zinc-500">Link da Área de Membros</p>
                <code class="mt-2 block break-all text-sm text-zinc-800 dark:text-zinc-200">{{ memberAreaUrl }}</code>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" @click="copyLink">
                        <Check v-if="copied" class="h-4 w-4" />
                        <Copy v-else class="h-4 w-4" />
                        Copiar Link
                    </Button>
                    <Button variant="outline" size="sm" @click="openMemberArea">
                        <ExternalLink class="h-4 w-4" />
                        Abrir Área de Membros
                    </Button>
                </div>
            </div>
        </section>

        <!-- Manifest -->
        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Manifest PWA</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Gerado automaticamente pelo sistema. Use para validar nome, ícone e cores.
            </p>
            <code class="block break-all rounded-lg bg-zinc-50 p-3 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ manifestUrl }}</code>
            <Button variant="outline" size="sm" @click="openManifest">
                <FileJson class="h-4 w-4" />
                Visualizar Manifest
            </Button>
        </section>

        <!-- Status -->
        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Status do Aplicativo</h2>
            <ul class="space-y-2">
                <li
                    v-for="item in statusItems"
                    :key="item.key"
                    class="flex flex-col gap-0.5 rounded-lg border px-3 py-2 text-sm"
                    :class="item.ok
                        ? 'border-emerald-200 bg-emerald-50/50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300'
                        : 'border-amber-200 bg-amber-50/50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300'"
                >
                    <span class="flex items-center gap-2 font-medium">
                        <Check v-if="item.ok" class="h-4 w-4 shrink-0" />
                        <X v-else class="h-4 w-4 shrink-0" />
                        {{ item.label }}
                    </span>
                    <p v-if="item.hint && !item.ok" class="ml-6 text-xs opacity-90">{{ item.hint }}</p>
                </li>
            </ul>
        </section>

        <!-- Push -->
        <section class="panel-card-md space-y-4">
            <div class="flex items-center gap-2">
                <Bell class="h-5 w-5 text-violet-500" />
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Notificações Push</h2>
            </div>
            <label class="flex items-center gap-3">
                <input v-model="form.push_enabled" type="checkbox" class="rounded border-zinc-400" />
                <span class="text-sm text-zinc-700 dark:text-zinc-300">Ativar notificações push para esta área</span>
            </label>
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase text-zinc-500">Status</dt>
                    <dd class="mt-0.5 font-medium" :class="form.push_enabled ? 'text-emerald-600' : 'text-zinc-500'">
                        {{ form.push_enabled ? 'Ativado' : 'Desativado' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase text-zinc-500">Chaves VAPID</dt>
                    <dd class="mt-0.5 font-medium" :class="pwaSettings.vapid_configured ? 'text-emerald-600' : 'text-zinc-500'">
                        {{ pwaSettings.vapid_configured ? 'Configuradas' : 'Não configuradas' }}
                    </dd>
                </div>
            </dl>
            <p class="text-xs text-zinc-500">
                As chaves VAPID são geradas automaticamente ao salvar com push ativado. O envio de notificações continua disponível no Member Builder do produto.
            </p>
        </section>

        <Teleport to="body">
            <div
                v-if="toast"
                role="alert"
                :class="[
                    'fixed bottom-4 right-4 z-[100002] max-w-sm rounded-xl border px-4 py-3 text-sm font-medium shadow-lg',
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
