<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import {
    Copy, Check, Plus, Pencil, RefreshCw, Webhook, Eye, X,
} from 'lucide-vue-next';

const props = defineProps({
    webhooks: { type: Array, default: () => [] },
    webhookLogs: { type: Array, default: () => [] },
    webhookUrlPattern: { type: String, default: '' },
    webhookCourseOptions: { type: Array, default: () => [] },
    webhookPlatformOptions: { type: Array, default: () => [] },
});

const emit = defineEmits(['refresh']);

const items = ref([...props.webhooks]);
const logs = ref([...props.webhookLogs]);
const showForm = ref(false);
const editing = ref(null);
const saving = ref(false);
const toast = ref(null);
const payloadModal = ref(null);
const copiedKey = ref('');

const examplePayload = `{
  "name": "Nome do Aluno",
  "email": "email@exemplo.com",
  "phone": "5599999999999",
  "document": "00000000000",
  "platform": "kiwify",
  "event": "purchase_approved",
  "transaction_id": "abc123",
  "status": "approved",
  "send_access_email": true
}`;

const emptyForm = () => ({
    name: '',
    product_id: props.webhookCourseOptions[0]?.id ?? '',
    platform: 'kiwify',
    external_product_id: '',
    is_active: true,
});

const form = ref(emptyForm());

function showToast(message, type = 'success') {
    toast.value = { message, type };
    setTimeout(() => { toast.value = null; }, 4500);
}

function openCreate() {
    editing.value = null;
    form.value = emptyForm();
    showForm.value = true;
}

function openEdit(webhook) {
    editing.value = webhook;
    form.value = {
        name: webhook.name,
        product_id: webhook.product_id,
        platform: webhook.platform ?? '',
        external_product_id: webhook.external_product_id ?? '',
        is_active: webhook.is_active,
    };
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editing.value = null;
}

async function saveWebhook() {
    saving.value = true;
    try {
        const payload = {
            ...form.value,
            platform: form.value.platform || null,
            external_product_id: form.value.external_product_id || null,
        };
        if (editing.value) {
            const { data } = await axios.put(`/area-membros-admin/webhooks/${editing.value.id}`, payload);
            const idx = items.value.findIndex((w) => w.id === editing.value.id);
            if (idx >= 0) items.value[idx] = data.webhook;
            showToast('Webhook atualizado.');
            closeForm();
        } else {
            const { data } = await axios.post('/area-membros-admin/webhooks', payload);
            items.value.unshift(data.webhook);
            showToast('Webhook criado. Copie a URL única na lista abaixo.');
            closeForm();
        }
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao salvar webhook.', 'error');
    } finally {
        saving.value = false;
    }
}

async function regenerateUrl(webhook) {
    if (!confirm(`Gerar nova URL para "${webhook.name}"? A URL atual deixará de funcionar.`)) return;
    try {
        const { data } = await axios.post(`/area-membros-admin/webhooks/${webhook.id}/regenerate-url`);
        const idx = items.value.findIndex((w) => w.id === webhook.id);
        if (idx >= 0) items.value[idx] = data.webhook;
        showToast('Nova URL gerada.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao gerar nova URL.', 'error');
    }
}

async function copyText(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => { copiedKey.value = ''; }, 2000);
    } catch {
        showToast('Não foi possível copiar.', 'error');
    }
}

async function openLogPayload(log) {
    try {
        const { data } = await axios.get(`/area-membros-admin/webhooks/logs/${log.id}`);
        payloadModal.value = data.log;
    } catch {
        showToast('Erro ao carregar payload.', 'error');
    }
}

const actionLabels = {
    enrolled: 'Matriculado',
    revoked: 'Revogado',
    duplicate: 'Duplicado',
    ignored: 'Ignorado',
    error: 'Erro',
};

const hasCourses = computed(() => props.webhookCourseOptions.length > 0);

watch(() => props.webhooks, (v) => { items.value = [...v]; }, { deep: true });
watch(() => props.webhookLogs, (v) => { logs.value = [...v]; }, { deep: true });
</script>

<template>
    <div class="space-y-8">
        <div
            v-if="toast"
            class="fixed bottom-6 right-6 z-50 rounded-lg px-4 py-3 text-sm shadow-lg"
            :class="toast.type === 'error' ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white'"
        >
            {{ toast.message }}
        </div>

        <!-- Payload modal -->
        <div v-if="payloadModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="payloadModal = null">
            <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-zinc-700 bg-zinc-900 p-6 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <h3 class="text-lg font-semibold text-white">Payload recebido</h3>
                    <button type="button" class="text-zinc-400 hover:text-white" @click="payloadModal = null"><X class="h-5 w-5" /></button>
                </div>
                <pre class="mt-4 overflow-x-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-200">{{ JSON.stringify(payloadModal.payload, null, 2) }}</pre>
            </div>
        </div>

        <!-- Integração n8n -->
        <section class="panel-card-md space-y-4">
            <div class="flex items-center gap-2">
                <Webhook class="h-5 w-5 text-[var(--color-primary)]" />
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Integração n8n</h2>
            </div>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Cada webhook possui uma URL única. Copie a URL do webhook desejado e cole no n8n como destino HTTP POST — sem headers de autenticação.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <p class="text-xs font-medium uppercase text-zinc-500">Formato da URL</p>
                    <code class="mt-2 block break-all text-sm text-zinc-800 dark:text-zinc-200">POST {{ webhookUrlPattern }}</code>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <p class="text-xs font-medium uppercase text-zinc-500">Headers no n8n</p>
                    <pre class="mt-2 text-xs text-zinc-800 dark:text-zinc-200">Content-Type: application/json</pre>
                    <p class="mt-2 text-xs text-zinc-500">Não é necessário Authorization, X-Signature ou HMAC.</p>
                </div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="text-xs font-medium uppercase text-zinc-500">Exemplo de JSON para o n8n</p>
                <pre class="mt-2 overflow-x-auto text-xs text-zinc-800 dark:text-zinc-200">{{ examplePayload }}</pre>
                <p class="mt-2 text-xs text-zinc-500">
                    Como o webhook já está vinculado a um curso, o n8n não precisa enviar <code>course_id</code> (opcional).
                </p>
            </div>
        </section>

        <!-- Lista + form -->
        <section class="panel-card-md space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Webhooks configurados</h2>
                <Button :disabled="!hasCourses" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Novo webhook
                </Button>
            </div>
            <p v-if="!hasCourses" class="text-sm text-amber-600 dark:text-amber-400">
                Crie pelo menos um curso na aba Cursos antes de configurar webhooks.
            </p>

            <div v-if="showForm" class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-800/40">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                    {{ editing ? 'Editar webhook' : 'Novo webhook' }}
                </h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-medium text-zinc-500">Nome</span>
                        <input v-model="form.name" type="text" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" placeholder="Kiwify - Curso UPA" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-zinc-500">Curso liberado</span>
                        <select v-model="form.product_id" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option v-for="c in webhookCourseOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-zinc-500">Plataforma</span>
                        <select v-model="form.platform" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option v-for="p in webhookPlatformOptions" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-medium text-zinc-500">External Product ID (opcional)</span>
                        <input v-model="form.external_product_id" type="text" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" placeholder="ID do produto na plataforma externa" />
                    </label>
                    <label class="flex items-center gap-2 sm:col-span-2">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-zinc-400" />
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">Webhook ativo</span>
                    </label>
                </div>
                <div class="mt-4 flex gap-2">
                    <Button :disabled="saving || !form.name || !form.product_id" @click="saveWebhook">
                        {{ saving ? 'Salvando…' : (editing ? 'Salvar alterações' : 'Criar webhook') }}
                    </Button>
                    <Button variant="outline" @click="closeForm">Cancelar</Button>
                </div>
            </div>

            <div v-if="items.length === 0" class="rounded-lg border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600">
                Nenhum webhook configurado ainda.
            </div>

            <div v-else class="space-y-3">
                <article
                    v-for="webhook in items"
                    :key="webhook.id"
                    class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/50"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-zinc-900 dark:text-white">{{ webhook.name }}</h3>
                            <p class="mt-1 text-sm text-zinc-500">
                                {{ webhook.platform || '—' }} · {{ webhook.product_name || '—' }}
                            </p>
                        </div>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="webhook.is_active ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-zinc-500/15 text-zinc-500'"
                        >
                            {{ webhook.is_active ? 'Ativo' : 'Inativo' }}
                        </span>
                    </div>
                    <dl class="mt-3 grid gap-2 text-xs text-zinc-600 dark:text-zinc-400 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="sm:col-span-2 lg:col-span-4">
                            <dt class="text-zinc-500">URL do webhook</dt>
                            <dd class="mt-1 break-all font-mono text-[11px] text-zinc-700 dark:text-zinc-300">{{ webhook.webhook_url }}</dd>
                        </div>
                        <div><dt class="text-zinc-500">External ID</dt><dd>{{ webhook.external_product_id || '—' }}</dd></div>
                        <div><dt class="text-zinc-500">Último recebimento</dt><dd>{{ webhook.last_used_label }}</dd></div>
                        <div><dt class="text-zinc-500">Processados</dt><dd>{{ webhook.total_processed }}</dd></div>
                        <div><dt class="text-zinc-500">Erros</dt><dd>{{ webhook.total_errors }}</dd></div>
                    </dl>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" @click="copyText(webhook.webhook_url, `url-${webhook.id}`)">
                            <Check v-if="copiedKey === `url-${webhook.id}`" class="h-3.5 w-3.5" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            Copiar URL
                        </Button>
                        <Button variant="outline" size="sm" @click="openEdit(webhook)">
                            <Pencil class="h-3.5 w-3.5" />
                            Editar
                        </Button>
                        <Button variant="outline" size="sm" @click="regenerateUrl(webhook)">
                            <RefreshCw class="h-3.5 w-3.5" />
                            Gerar nova URL
                        </Button>
                    </div>
                </article>
            </div>
        </section>

        <!-- Logs -->
        <section class="panel-card-md space-y-4">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Logs recentes</h2>
            <div v-if="logs.length === 0" class="text-sm text-zinc-500">Nenhum evento registrado ainda.</div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                            <th class="px-2 py-2">Data</th>
                            <th class="px-2 py-2">Webhook</th>
                            <th class="px-2 py-2">Plataforma</th>
                            <th class="px-2 py-2">Evento</th>
                            <th class="px-2 py-2">E-mail</th>
                            <th class="px-2 py-2">Curso</th>
                            <th class="px-2 py-2">Ação</th>
                            <th class="px-2 py-2">E-mail</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs" :key="log.id" class="border-b border-zinc-100 dark:border-zinc-800">
                            <td class="px-2 py-2 whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ log.processed_at }}</td>
                            <td class="px-2 py-2">{{ log.webhook_name }}</td>
                            <td class="px-2 py-2">{{ log.platform }}</td>
                            <td class="px-2 py-2">{{ log.event }}</td>
                            <td class="px-2 py-2">{{ log.email }}</td>
                            <td class="px-2 py-2">{{ log.course_name }}</td>
                            <td class="px-2 py-2">
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="log.action === 'error' ? 'bg-red-500/15 text-red-500' : 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400'"
                                >
                                    {{ actionLabels[log.action] ?? log.action }}
                                </span>
                            </td>
                            <td class="px-2 py-2">{{ log.email_sent ? 'Sim' : 'Não' }}</td>
                            <td class="px-2 py-2">
                                <button type="button" class="text-[var(--color-primary)] hover:underline" @click="openLogPayload(log)">
                                    <Eye class="inline h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
