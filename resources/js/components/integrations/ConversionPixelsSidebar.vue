<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { X, Plus, Pencil, Trash2, ArrowLeft, Loader2 } from 'lucide-vue-next';
import Checkbox from '@/components/ui/Checkbox.vue';
import { PIXEL_TABS, ENTRY_FLAGS } from '@/lib/conversionPixels';

const props = defineProps({
    open: { type: Boolean, default: false },
    conversion_pixel_integrations: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const selectedTab = ref('meta');
const editingIntegration = ref(null);
const isCreating = ref(false);
const saving = ref(false);
const deleting = ref(null);
const confirmingDeleteId = ref(null);
const errorMessage = ref(null);

const inputClass =
    'w-full rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--color-primary)_25%,transparent)] dark:border-zinc-700 dark:bg-zinc-800 dark:text-white';

const showingForm = computed(() => editingIntegration.value !== null || isCreating.value);

const integrationsForTab = computed(() =>
    (props.conversion_pixel_integrations || []).filter((i) => i.platform === selectedTab.value)
);

const form = ref({
    name: '',
    is_active: true,
    config: {},
    access_token: '',
    product_ids: [],
});

const supportsBehaviorFlags = computed(() =>
    ['meta', 'tiktok', 'google_ads', 'google_analytics'].includes(selectedTab.value)
);

function emptyConfig(platform) {
    const flags = { ...ENTRY_FLAGS };
    if (platform === 'meta' || platform === 'tiktok') return { pixel_id: '', ...flags };
    if (platform === 'google_ads') return { conversion_id: '', conversion_label: '', ...flags };
    if (platform === 'google_analytics') return { measurement_id: '', ...flags };
    if (platform === 'custom_script') return { script: '' };
    return {};
}

function resetForm() {
    editingIntegration.value = null;
    isCreating.value = false;
    confirmingDeleteId.value = null;
    form.value = {
        name: '',
        is_active: true,
        config: emptyConfig(selectedTab.value),
        access_token: '',
        product_ids: [],
    };
    errorMessage.value = null;
}

function isProductSelected(id) {
    return form.value.product_ids.map(String).includes(String(id));
}

function setProductSelected(id, checked) {
    const sid = String(id);
    if (checked) {
        if (!isProductSelected(sid)) {
            form.value.product_ids = [...form.value.product_ids, sid];
        }
    } else {
        form.value.product_ids = form.value.product_ids.filter((x) => String(x) !== sid);
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) resetForm();
    }
);

watch(selectedTab, () => {
    if (!showingForm.value) return;
    resetForm();
});

function startNew() {
    editingIntegration.value = null;
    isCreating.value = true;
    form.value = {
        name: '',
        is_active: true,
        config: emptyConfig(selectedTab.value),
        access_token: '',
        product_ids: [],
    };
    errorMessage.value = null;
}

function editIntegration(integration) {
    isCreating.value = false;
    editingIntegration.value = integration;
    const cfg = { ...emptyConfig(integration.platform), ...(integration.config || {}) };
    form.value = {
        name: integration.name,
        is_active: integration.is_active ?? true,
        config: cfg,
        access_token: '',
        product_ids: [...(integration.product_ids || [])],
    };
    errorMessage.value = null;
}

async function save() {
    saving.value = true;
    errorMessage.value = null;
    const platform = selectedTab.value;
    const payload = {
        platform,
        name: form.value.name,
        is_active: form.value.is_active,
        config: form.value.config,
        product_ids: form.value.product_ids,
    };
    if (platform === 'meta' || platform === 'tiktok') {
        if (isCreating.value || form.value.access_token) {
            payload.access_token = form.value.access_token;
        }
    }

    try {
        if (editingIntegration.value) {
            await axios.put(`/integracoes/conversion-pixels/${editingIntegration.value.id}`, payload);
        } else {
            await axios.post('/integracoes/conversion-pixels', payload);
        }
        resetForm();
        emit('saved');
    } catch (e) {
        errorMessage.value = e.response?.data?.message || 'Não foi possível salvar.';
    } finally {
        saving.value = false;
    }
}

async function destroyIntegration(integration) {
    deleting.value = integration.id;
    try {
        await axios.delete(`/integracoes/conversion-pixels/${integration.id}`);
        confirmingDeleteId.value = null;
        emit('saved');
    } catch {
        errorMessage.value = 'Não foi possível excluir.';
    } finally {
        deleting.value = null;
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-[100000] flex justify-end">
                <div class="absolute inset-0 bg-black/40" aria-hidden="true" @click="emit('close')" />
                <aside
                    class="relative flex h-full w-full max-w-xl flex-col bg-white shadow-2xl dark:bg-zinc-900"
                    role="dialog"
                    aria-labelledby="conversion-pixels-sidebar-title"
                >
                    <header class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <div class="flex items-center gap-2">
                            <button
                                v-if="showingForm"
                                type="button"
                                class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                @click="resetForm"
                            >
                                <ArrowLeft class="h-5 w-5" />
                            </button>
                            <h2 id="conversion-pixels-sidebar-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                                Pixels e rastreamento
                            </h2>
                        </div>
                        <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="emit('close')">
                            <X class="h-5 w-5" />
                        </button>
                    </header>

                    <div v-if="!showingForm" class="border-b border-zinc-200 px-3 py-3 dark:border-zinc-700">
                        <div class="flex gap-2 overflow-x-auto pb-1">
                            <button
                                v-for="tab in PIXEL_TABS"
                                :key="tab.id"
                                type="button"
                                :class="[
                                    'flex shrink-0 flex-col items-center gap-1 rounded-lg border px-3 py-2 text-xs font-medium transition',
                                    selectedTab === tab.id
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)]'
                                        : 'border-zinc-200 text-zinc-600 dark:border-zinc-600 dark:text-zinc-400',
                                ]"
                                @click="selectedTab = tab.id"
                            >
                                <img :src="tab.image" :alt="tab.label" class="h-6 w-6 object-contain" />
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto p-5">
                        <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                            {{ errorMessage }}
                        </p>

                        <template v-if="!showingForm">
                            <div class="mb-4 flex items-center justify-between">
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    Cadastre pixels uma vez e reutilize nos produtos.
                                </p>
                                <Button type="button" size="sm" @click="startNew">
                                    <Plus class="mr-1 h-4 w-4" /> Novo
                                </Button>
                            </div>
                            <ul v-if="integrationsForTab.length" class="space-y-2">
                                <li
                                    v-for="item in integrationsForTab"
                                    :key="item.id"
                                    class="panel-card-sm flex items-center justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ item.name }}</p>
                                        <p class="truncate text-xs text-zinc-500">{{ item.summary }}</p>
                                        <p class="mt-1 text-xs" :class="item.is_active ? 'text-emerald-600' : 'text-zinc-400'">
                                            {{ item.is_active ? 'Ativo' : 'Inativo' }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 gap-1">
                                        <button
                                            type="button"
                                            class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                            @click="editIntegration(item)"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                                            @click="confirmingDeleteId = item.id"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-zinc-500 dark:text-zinc-400">
                                Nenhuma integração nesta plataforma. Clique em «Novo» para cadastrar.
                            </p>
                        </template>

                        <form v-else class="space-y-4" @submit.prevent="save">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome</label>
                                <input v-model="form.name" type="text" required :class="inputClass" placeholder="Ex: Meta — Loja principal" />
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ativo</span>
                                <Toggle v-model="form.is_active" />
                            </div>

                            <template v-if="selectedTab === 'meta' || selectedTab === 'tiktok'">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Pixel ID</label>
                                    <input v-model="form.config.pixel_id" type="text" required :class="inputClass" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        Access Token (CAPI)
                                        <span v-if="editingIntegration?.has_access_token" class="font-normal text-zinc-500">
                                            — deixe em branco para manter o atual
                                        </span>
                                    </label>
                                    <input
                                        v-model="form.access_token"
                                        type="password"
                                        :required="isCreating"
                                        :class="inputClass"
                                        autocomplete="off"
                                    />
                                </div>
                            </template>

                            <template v-else-if="selectedTab === 'google_ads'">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Conversion ID</label>
                                    <input v-model="form.config.conversion_id" type="text" required :class="inputClass" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Conversion Label</label>
                                    <input v-model="form.config.conversion_label" type="text" :class="inputClass" />
                                </div>
                            </template>

                            <template v-else-if="selectedTab === 'google_analytics'">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Measurement ID</label>
                                    <input v-model="form.config.measurement_id" type="text" required :class="inputClass" placeholder="G-XXXXXXXXXX" />
                                </div>
                            </template>

                            <template v-else-if="selectedTab === 'custom_script'">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Script</label>
                                    <textarea
                                        v-model="form.config.script"
                                        rows="8"
                                        required
                                        :class="inputClass + ' font-mono text-sm'"
                                        placeholder="&lt;script&gt;...&lt;/script&gt;"
                                    />
                                </div>
                            </template>

                            <div v-if="supportsBehaviorFlags" class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Comportamento no checkout</p>
                                <Checkbox
                                    v-model="form.config.fire_purchase_on_pix"
                                    label="Disparar Purchase ao gerar PIX (não na aprovação)?"
                                />
                                <Checkbox
                                    v-model="form.config.fire_purchase_on_boleto"
                                    label="Disparar Purchase ao gerar boleto (não na aprovação)?"
                                />
                                <Checkbox
                                    v-model="form.config.disable_order_bump_events"
                                    label="Desativar eventos de order bumps?"
                                />
                            </div>

                            <div>
                                <span class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Produtos atribuídos
                                </span>
                                <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    Marque os produtos que devem usar este pixel. Sem seleção, o pixel não é aplicado em nenhum checkout.
                                </p>
                                <div
                                    v-if="products.length"
                                    class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 bg-white p-3 text-left dark:border-zinc-600 dark:bg-zinc-800"
                                >
                                    <label
                                        v-for="p in products"
                                        :key="p.id"
                                        class="flex cursor-pointer items-start justify-start gap-2 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                                    >
                                        <span class="shrink-0 pt-0.5">
                                            <Checkbox
                                                :model-value="isProductSelected(p.id)"
                                                class="!w-auto shrink-0"
                                                @update:model-value="(v) => setProductSelected(p.id, v)"
                                            />
                                        </span>
                                        <span class="min-w-0 flex-1 text-left text-sm leading-snug text-zinc-900 dark:text-white">
                                            {{ p.name }}
                                        </span>
                                    </label>
                                </div>
                                <p v-else class="text-xs text-zinc-500">Nenhum produto cadastrado.</p>
                            </div>

                            <Button type="submit" class="w-full" :disabled="saving">
                                <Loader2 v-if="saving" class="mr-2 h-4 w-4 animate-spin" />
                                {{ editingIntegration ? 'Salvar alterações' : 'Criar integração' }}
                            </Button>
                        </form>
                    </div>

                    <div
                        v-if="confirmingDeleteId"
                        class="absolute inset-0 z-10 flex items-center justify-center bg-black/50 p-4"
                    >
                        <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl dark:bg-zinc-800">
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">Excluir esta integração? Produtos que a usam deixarão de disparar este pixel.</p>
                            <div class="mt-4 flex gap-2">
                                <Button variant="outline" class="flex-1" @click="confirmingDeleteId = null">Cancelar</Button>
                                <Button
                                    class="flex-1 bg-red-600 hover:bg-red-700"
                                    :disabled="deleting === confirmingDeleteId"
                                    @click="destroyIntegration(integrationsForTab.find((i) => i.id === confirmingDeleteId))"
                                >
                                    Excluir
                                </Button>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </Transition>
    </Teleport>
</template>
