<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';

const emit = defineEmits(['saved', 'close']);

/** Payload de exemplo (estrutura típica checkout externo com `data` + `customer`). */
const EXAMPLE_PAYLOAD = {
    event: 'purchase_approved',
    data: {
        id: '67597a04-90a2-453e-b1b4-e03034a38473',
        status: 'paid',
        refId: '095YCL1',
        amount: 90,
        customer: {
            name: 'John Doe',
            email: 'john.doe@example.com',
            phone: '34999999999',
            docType: 'cpf',
            docNumber: '12345678909',
        },
        product: {
            id: 'ff3fdf61-e88f-43b5-982a-32d50f112414',
            name: 'Produto Teste',
        },
        paidAt: '2026-05-04T20:56:22.554286+00:00',
        paymentMethod: 'credit_card',
    },
};

/** Mapeamento recomendado para o payload de exemplo acima. */
const EXAMPLE_FIELD_MAP = {
    _strict: true,
    email: ['data.customer.email'],
    name: ['data.customer.name'],
    cpf: ['data.customer.docNumber'],
    phone: ['data.customer.phone'],
    external_id: ['data.id', 'data.refId'],
};

const examplePayloadJson = computed(() => JSON.stringify(EXAMPLE_PAYLOAD, null, 2));
const exampleFieldMapJson = computed(() => JSON.stringify(EXAMPLE_FIELD_MAP, null, 2));

const showHelper = ref(false);
const helperTab = ref('example');
const aiPayloadInput = ref('');

const base = '/webhook-entrada';

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const ok = ref('');

const endpoints = ref([]);
const products = ref([]);

const showForm = ref(false);
const editingId = ref(null);

const form = ref({
    name: '',
    product_id: '',
    product_offer_id: null,
    subscription_plan_id: null,
    is_active: true,
    signing_secret: '',
    field_map_json: JSON.stringify(
        {
            _strict: false,
            email: ['data.customer.email', 'customer.email', 'email'],
            name: ['data.customer.name', 'customer.name', 'name'],
            cpf: ['data.customer.docNumber', 'data.customer.cpf', 'cpf'],
            phone: ['data.customer.phone', 'phone'],
            external_id: ['data.id', 'data.refId', 'external_id'],
        },
        null,
        2
    ),
});

const selectedProduct = computed(() => products.value.find((p) => p.id === form.value.product_id) || null);

/**
 * Prompt em PT-BR para colar no ChatGPT e obter um `field_map` adaptado ao payload.
 */
function buildAiPrompt(payloadText) {
    const trimmed = payloadText.trim();
    let prettyPayload = trimmed;
    try {
        prettyPayload = JSON.stringify(JSON.parse(trimmed), null, 2);
    } catch {
        // mantém texto bruto no bloco
    }
    const referenceJson = JSON.stringify(EXAMPLE_FIELD_MAP, null, 2);

    return `Você é um assistente que mapeia webhooks de checkout (JSON) para o formato \`field_map\` do plugin **Webhook de entrada** do Power On.

## Regras do field_map
- Chaves permitidas (e só estas): \`email\`, \`name\`, \`cpf\`, \`phone\`, \`external_id\`, e opcionalmente \`_strict\` (boolean).
- Cada chave pode ser **uma string** (um caminho em dot notation, ex.: \`data.customer.email\`) ou **um array de strings** — vários caminhos pela **ordem de tentativa** até encontrar valor válido.
- \`_strict: true\` significa: usar **apenas** os caminhos que você indicar (sem sugestões automáticas do sistema). \`_strict: false\` ou omitir permite fallbacks internos depois dos seus caminhos.
- **email** é obrigatório no resultado (caminho(s) que levem a um e-mail válido no payload).
- **external_id** é opcional mas recomendado para idempotência (ex.: id do pedido na plataforma de origem).
- Use **apenas** caminhos que existam no payload de exemplo. Não invente chaves além das listadas.

## Tarefa
Analise o JSON abaixo (webhook real ou de teste da plataforma) e devolva **APENAS** um objeto JSON válido — o \`field_map\` — sem markdown, sem comentários, sem texto antes ou depois.

## Payload de exemplo da plataforma (entrada)
\`\`\`json
${prettyPayload}
\`\`\`

## Exemplo de saída válida (referência de formato — adapta os caminhos ao payload acima, não copies à cega se a estrutura for diferente)
\`\`\`json
${referenceJson}
\`\`\`
`;
}

const aiGeneratedPrompt = computed(() => {
    const t = aiPayloadInput.value.trim();
    if (!t) {
        return 'Cole o JSON do payload na caixa acima. Quando for JSON válido, o prompt completo aparece aqui automaticamente — podes copiar e colar no ChatGPT.';
    }
    try {
        JSON.parse(t);

        return buildAiPrompt(t);
    } catch {
        return 'JSON inválido no payload. Corrija a sintaxe acima; quando o JSON for válido, o prompt completo aparecerá aqui.';
    }
});

function applyExampleFieldMap() {
    form.value.field_map_json = JSON.stringify(EXAMPLE_FIELD_MAP, null, 2);
    ok.value = 'Mapeamento de exemplo aplicado no campo acima.';
}

function toggleHelper() {
    showHelper.value = !showHelper.value;
    if (showHelper.value) {
        helperTab.value = 'example';
    }
}

function resetHelperState() {
    showHelper.value = false;
    helperTab.value = 'example';
    aiPayloadInput.value = '';
}

function openChatGpt() {
    window.open('https://chat.openai.com/', '_blank', 'noopener,noreferrer');
}

async function loadAll() {
    loading.value = true;
    error.value = '';
    ok.value = '';
    try {
        const [epRes, prRes] = await Promise.all([
            axios.get(`${base}/api/endpoints`),
            axios.get(`${base}/api/products`),
        ]);
        endpoints.value = epRes.data?.data || [];
        products.value = prRes.data?.data || [];
    } catch (e) {
        error.value = e.response?.data?.message || 'Não foi possível carregar os webhooks de entrada.';
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editingId.value = null;
    form.value = {
        name: '',
        product_id: products.value[0]?.id || '',
        product_offer_id: null,
        subscription_plan_id: null,
        is_active: true,
        signing_secret: '',
        field_map_json: JSON.stringify(
            {
                _strict: false,
                email: ['data.customer.email', 'customer.email', 'email'],
                name: ['data.customer.name', 'customer.name', 'name'],
                cpf: ['data.customer.docNumber', 'data.customer.cpf', 'cpf'],
                phone: ['data.customer.phone', 'phone'],
                external_id: ['data.id', 'data.refId', 'external_id'],
            },
            null,
            2
        ),
    };
    resetHelperState();
    showForm.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    form.value = {
        name: row.name,
        product_id: row.product_id,
        product_offer_id: row.product_offer_id,
        subscription_plan_id: row.subscription_plan_id,
        is_active: row.is_active,
        signing_secret: '',
        field_map_json: JSON.stringify(
            row.field_map && Object.keys(row.field_map).length
                ? row.field_map
                : {
                      _strict: false,
                      email: ['data.customer.email', 'email'],
                      name: ['data.customer.name', 'name'],
                      cpf: ['data.customer.docNumber', 'cpf'],
                      phone: ['data.customer.phone', 'phone'],
                      external_id: ['data.id', 'external_id'],
                  },
            null,
            2
        ),
    };
    resetHelperState();
    showForm.value = true;
}

function closeForm() {
    resetHelperState();
    showForm.value = false;
}

function parseFieldMap() {
    try {
        const raw = JSON.parse(form.value.field_map_json);
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
        return raw;
    } catch {
        return null;
    }
}

async function save() {
    const fieldMap = parseFieldMap();
    if (!fieldMap) {
        error.value = 'JSON de mapeamento de campos inválido.';
        return;
    }
    saving.value = true;
    error.value = '';
    ok.value = '';
    try {
        const payload = {
            name: form.value.name,
            product_id: form.value.product_id,
            product_offer_id: form.value.product_offer_id || null,
            subscription_plan_id: form.value.subscription_plan_id || null,
            is_active: form.value.is_active,
            field_map: fieldMap,
        };
        if (form.value.signing_secret?.trim()) {
            payload.signing_secret = form.value.signing_secret.trim();
        }
        if (editingId.value) {
            await axios.put(`${base}/api/endpoints/${editingId.value}`, payload);
            ok.value = 'Endpoint atualizado.';
        } else {
            await axios.post(`${base}/api/endpoints`, payload);
            ok.value = 'Endpoint criado. Copie a URL abaixo.';
        }
        showForm.value = false;
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function remove(id) {
    if (!confirm('Remover este endpoint? A URL deixará de funcionar.')) return;
    try {
        await axios.delete(`${base}/api/endpoints/${id}`);
        ok.value = 'Removido.';
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao remover.';
    }
}

async function regenerate(id) {
    if (!confirm('Gerar novo token? A URL antiga para de funcionar.')) return;
    try {
        await axios.post(`${base}/api/endpoints/${id}/regenerate-token`);
        ok.value = 'Novo token gerado.';
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao regenerar.';
    }
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        ok.value = 'Copiado.';
    } catch {
        ok.value = '';
    }
}

async function copyAiPrompt() {
    await copyText(aiGeneratedPrompt.value);
}

loadAll();
</script>

<template>
    <div class="space-y-4">
        <div>
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Webhook de entrada</h3>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Receba <code class="rounded bg-zinc-200 px-1 text-xs dark:bg-zinc-700">POST</code> JSON de um checkout externo. Um pedido
                concluído é criado, o aluno vinculado à área de membros e o e‑mail de acesso enviado (comportamento igual a venda aprovada).
            </p>
            <ul class="mt-2 list-inside list-disc text-xs text-zinc-600 dark:text-zinc-400">
                <li>
                    URL pública:
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">POST …/webhooks/inbound/&lt;token&gt;</code>
                    (token exibido após salvar; use HTTPS em produção).
                </li>
                <li>Campo obrigatório no JSON: <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">email</code> (ou caminho configurado no mapeamento).</li>
                <li>
                    Opcional:
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">external_id</code> para idempotência (mesmo valor não cria pedido duplicado).
                </li>
                <li>
                    Assinatura (opcional): cabeçalho
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">X-Webhook-Signature: sha256=&lt;hmac_hex&gt;</code> com o corpo bruto em
                    HMAC-SHA256 usando o secret configurado.
                </li>
            </ul>
        </div>

        <div v-if="loading" class="text-sm text-zinc-500 dark:text-zinc-400">Carregando…</div>

        <div v-if="error" class="rounded-lg bg-red-100 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">
            {{ error }}
        </div>
        <div v-if="ok" class="rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200">
            {{ ok }}
        </div>

        <div v-if="!loading" class="flex flex-wrap gap-2">
            <Button type="button" @click="openCreate">Novo endpoint</Button>
            <Button type="button" variant="outline" @click="loadAll">Atualizar</Button>
        </div>

        <div v-if="showForm" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ editingId ? 'Editar' : 'Criar' }} endpoint</div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome</label>
            <input
                v-model="form.name"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Ex.: Hotmart"
            />
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Produto (área de membros)</label>
            <select
                v-model="form.product_id"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
            >
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Oferta (opcional)</label>
            <select
                v-model="form.product_offer_id"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
            >
                <option :value="null">— Nenhuma —</option>
                <option v-for="o in selectedProduct?.offers || []" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Plano de assinatura (opcional)</label>
            <select
                v-model="form.subscription_plan_id"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
            >
                <option :value="null">— Nenhum —</option>
                <option v-for="s in selectedProduct?.subscription_plans || []" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <label class="flex items-center gap-2 text-sm text-zinc-800 dark:text-zinc-200">
                <input v-model="form.is_active" type="checkbox" class="rounded border-zinc-300" /> Ativo
            </label>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400"
                >Secret para assinatura HMAC (opcional; em branco = sem verificação)</label
            >
            <input
                v-model="form.signing_secret"
                type="password"
                autocomplete="new-password"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Deixe vazio ou defina ao criar/editar"
            />
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400"
                >Mapeamento JSON: para cada campo use um caminho (string) ou vários em lista (ordem de tentativa). Chaves:
                email, name, cpf, phone, external_id. Opcional: "_strict": true — só usa os caminhos que definir (sem sugestões automáticas).</label
            >
            <div class="flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="outline" @click="toggleHelper">
                    {{ showHelper ? 'Ocultar exemplo & IA' : 'Ver exemplo & IA' }}
                </Button>
                <Button type="button" size="sm" variant="outline" @click="applyExampleFieldMap">Aplicar exemplo no campo</Button>
            </div>
            <textarea
                v-model="form.field_map_json"
                rows="8"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-900"
            ></textarea>

            <div
                v-if="showHelper"
                class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20"
            >
                <div class="flex flex-wrap gap-1 border-b border-emerald-200/80 pb-2 dark:border-emerald-800/60">
                    <button
                        type="button"
                        class="rounded-t px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            helperTab === 'example'
                                ? 'bg-white text-emerald-900 shadow-sm dark:bg-zinc-900 dark:text-emerald-200'
                                : 'text-zinc-600 hover:bg-white/60 dark:text-zinc-400 dark:hover:bg-zinc-900/40'
                        "
                        @click="helperTab = 'example'"
                    >
                        Exemplo pronto
                    </button>
                    <button
                        type="button"
                        class="rounded-t px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            helperTab === 'ai'
                                ? 'bg-white text-emerald-900 shadow-sm dark:bg-zinc-900 dark:text-emerald-200'
                                : 'text-zinc-600 hover:bg-white/60 dark:text-zinc-400 dark:hover:bg-zinc-900/40'
                        "
                        @click="helperTab = 'ai'"
                    >
                        Gerar prompt para IA
                    </button>
                </div>

                <div v-show="helperTab === 'example'" class="space-y-3">
                    <p class="text-xs text-zinc-600 dark:text-zinc-400">
                        À esquerda: payload de exemplo típico. À direita: <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">field_map</code> adequado
                        a essa estrutura.
                    </p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="min-w-0">
                            <div class="mb-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">Payload de exemplo</div>
                            <pre
                                class="max-h-72 overflow-auto rounded-lg border border-zinc-200 bg-white p-2 text-[11px] leading-relaxed dark:border-zinc-600 dark:bg-zinc-900"
                                >{{ examplePayloadJson }}</pre
                            >
                            <Button type="button" size="sm" variant="outline" class="mt-2" @click="copyText(examplePayloadJson)">
                                Copiar payload
                            </Button>
                        </div>
                        <div class="min-w-0">
                            <div class="mb-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">Configuração (field_map)</div>
                            <pre
                                class="max-h-72 overflow-auto rounded-lg border border-zinc-200 bg-white p-2 text-[11px] leading-relaxed dark:border-zinc-600 dark:bg-zinc-900"
                                >{{ exampleFieldMapJson }}</pre
                            >
                            <div class="mt-2 flex flex-wrap gap-2">
                                <Button type="button" size="sm" variant="outline" @click="copyText(exampleFieldMapJson)">Copiar JSON</Button>
                                <Button type="button" size="sm" @click="applyExampleFieldMap">Aplicar este exemplo no campo</Button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-show="helperTab === 'ai'" class="space-y-3">
                    <p class="text-xs text-zinc-600 dark:text-zinc-400">
                        Cole abaixo um JSON real da sua plataforma. O prompt é gerado automaticamente quando o JSON é válido. Copie e cole no
                        <strong>ChatGPT</strong> (ou outro assistente); depois cola o <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">field_map</code> devolvido
                        no campo «Mapeamento JSON» acima.
                    </p>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Payload da sua plataforma (JSON)</label>
                    <textarea
                        v-model="aiPayloadInput"
                        rows="10"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-900"
                        placeholder='Cole aqui o corpo JSON do webhook, ex.: { "data": { "customer": { "email": "..." } } }'
                    ></textarea>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" size="sm" @click="copyAiPrompt">Copiar prompt</Button>
                        <Button type="button" size="sm" variant="outline" @click="openChatGpt">Abrir ChatGPT</Button>
                    </div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Prompt para colar no ChatGPT</label>
                    <textarea
                        readonly
                        rows="14"
                        class="w-full cursor-text rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-[11px] leading-relaxed text-zinc-800 dark:border-zinc-600 dark:bg-zinc-900/80 dark:text-zinc-200"
                        :value="aiGeneratedPrompt"
                    ></textarea>
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="button" :disabled="saving" @click="save">Salvar</Button>
                <Button type="button" variant="outline" @click="closeForm">Cancelar</Button>
            </div>
        </div>

        <div v-if="!loading && !showForm" class="space-y-3">
            <div v-if="!endpoints.length" class="text-sm text-zinc-500 dark:text-zinc-400">Nenhum endpoint ainda.</div>
            <div
                v-for="row in endpoints"
                :key="row.id"
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-white">{{ row.name }}</div>
                        <div class="mt-1 break-all font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ row.url }}</div>
                        <div class="mt-1 text-xs text-zinc-500">Token: {{ row.url_token_masked }}</div>
                        <div class="mt-1 text-xs text-zinc-500">
                            Status: {{ row.is_active ? 'Ativo' : 'Inativo' }} · Secret:
                            {{ row.signing_secret_set ? 'definido' : 'não definido' }}
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" size="sm" variant="outline" @click="copyText(row.url)">Copiar URL</Button>
                        <Button type="button" size="sm" variant="outline" @click="openEdit(row)">Editar</Button>
                        <Button type="button" size="sm" variant="outline" @click="regenerate(row.id)">Novo token</Button>
                        <Button type="button" size="sm" variant="destructive" @click="remove(row.id)">Remover</Button>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <Button type="button" variant="ghost" @click="emit('close')">Fechar</Button>
        </div>
    </div>
</template>
