<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import ProductPartnersTable from '@/components/produtos/ProductPartnersTable.vue';
import { Mail, Users, Search, Info, Wallet, Zap } from 'lucide-vue-next';

const props = defineProps({
    productId: { type: String, required: true },
});

const loading = ref(true);
const saving = ref(false);
const savingSettings = ref(false);
const mode = ref('member');
const coproducers = ref([]);
const candidates = ref([]);
const candidateSearch = ref('');
const selectedUserId = ref(null);
const message = ref('');

const splitPayoutEnabled = ref(false);
const cajupayConnected = ref(false);

const editingCoproducer = ref(null);
const editForm = ref({
    commission_percent: 10,
    payout_method: 'internal',
    cajupay_split_id: '',
    commission_on_producer_sales: true,
    commission_on_affiliate_sales: true,
});

const defaultCommissionFields = () => ({
    commission_percent: 10,
    duration_days: null,
    commission_on_producer_sales: true,
    commission_on_affiliate_sales: true,
    settlement_days_pix: null,
    settlement_days_card: null,
    settlement_days_boleto: null,
    payout_method: 'internal',
    cajupay_split_id: '',
});

const commissionForm = ref(defaultCommissionFields());
const inviteForm = ref({ email: '', ...defaultCommissionFields() });

const canUseSplitPayout = computed(
    () => splitPayoutEnabled.value && cajupayConnected.value
);

let searchTimer = null;

function payoutPayload(form) {
    const base = { ...form };
    if (form.payout_method !== 'cajupay_split') {
        base.payout_method = 'internal';
        base.cajupay_split_id = null;
    } else {
        base.cajupay_split_id = (form.cajupay_split_id || '').trim() || null;
    }
    return base;
}

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get(`/produtos/${props.productId}/coproducers`);
        coproducers.value = data.coproducers ?? [];
        splitPayoutEnabled.value = !!data.cajupay_split_payout_enabled;
        cajupayConnected.value = !!data.cajupay_connected;
    } finally {
        loading.value = false;
    }
}

async function toggleSplitPayout() {
    if (!cajupayConnected.value && !splitPayoutEnabled.value) {
        message.value = 'Conecte o CajuPay em Integrações antes de ativar split.';
        return;
    }
    savingSettings.value = true;
    message.value = '';
    try {
        const { data } = await axios.patch(`/produtos/${props.productId}/coproduction-settings`, {
            cajupay_split_payout_enabled: !splitPayoutEnabled.value,
        });
        splitPayoutEnabled.value = !!data.cajupay_split_payout_enabled;
        message.value = splitPayoutEnabled.value
            ? 'Repasse via split ativado neste produto.'
            : 'Repasse via split desativado. Novos co-produtores usarão conta única.';
    } catch (e) {
        message.value = e.response?.data?.message || 'Erro ao salvar configuração.';
    } finally {
        savingSettings.value = false;
    }
}

async function loadCandidates() {
    try {
        const { data } = await axios.get(`/produtos/${props.productId}/coproducers/candidates`, {
            params: { q: candidateSearch.value.trim() || undefined },
        });
        candidates.value = data.candidates ?? [];
    } catch {
        candidates.value = [];
    }
}

function onCandidateSearch() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(loadCandidates, 350);
}

function selectCandidate(c) {
    selectedUserId.value = c.id;
}

const coproducerStatusLabels = {
    active: 'Ativo',
    pending: 'Convite pendente',
    revoked: 'Revogado',
    expired: 'Expirado',
};

const coproducerRows = computed(() =>
    coproducers.value.map((c) => ({
        id: c.id,
        created_at: c.created_at,
        name: c.user?.name ?? null,
        email: c.user?.email ?? c.email ?? null,
        product_name: c.product_name,
        commission_percent: c.commission_percent,
        status: c.status,
    }))
);

function openEdit(c) {
    editingCoproducer.value = c;
    editForm.value = {
        commission_percent: c.commission_percent,
        payout_method: c.payout_method || 'internal',
        cajupay_split_id: c.cajupay_split_id || '',
        commission_on_producer_sales: c.commission_on_producer_sales,
        commission_on_affiliate_sales: c.commission_on_affiliate_sales,
    };
}

function closeEdit() {
    editingCoproducer.value = null;
}

async function saveEdit() {
    if (!editingCoproducer.value) return;
    saving.value = true;
    message.value = '';
    try {
        await axios.patch(
            `/produtos/${props.productId}/coproducers/${editingCoproducer.value.id}`,
            payoutPayload(editForm.value)
        );
        message.value = 'Co-produtor atualizado.';
        closeEdit();
        await load();
    } catch (e) {
        const err = e.response?.data?.errors;
        message.value =
            e.response?.data?.message
            || err?.cajupay_split_id?.[0]
            || err?.payout_method?.[0]
            || 'Erro ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function assignMember() {
    if (!selectedUserId.value) {
        message.value = 'Selecione um membro da lista.';
        return;
    }
    saving.value = true;
    message.value = '';
    try {
        await axios.post(`/produtos/${props.productId}/coproducers/assign`, {
            user_id: selectedUserId.value,
            ...payoutPayload(commissionForm.value),
        });
        message.value = 'Co-produtor adicionado.';
        selectedUserId.value = null;
        candidateSearch.value = '';
        await load();
        await loadCandidates();
    } catch (e) {
        const err = e.response?.data?.errors;
        message.value =
            e.response?.data?.message
            || err?.user_id?.[0]
            || err?.cajupay_split_id?.[0]
            || 'Erro ao adicionar.';
    } finally {
        saving.value = false;
    }
}

async function sendInvite() {
    saving.value = true;
    message.value = '';
    try {
        const { data } = await axios.post(`/produtos/${props.productId}/coproducers/invite`, {
            email: inviteForm.value.email,
            ...payoutPayload(inviteForm.value),
        });
        message.value = data.warning || 'Convite enviado.';
        inviteForm.value.email = '';
        await load();
    } catch (e) {
        const err = e.response?.data?.errors;
        message.value =
            e.response?.data?.message
            || err?.cajupay_split_id?.[0]
            || 'Erro ao enviar convite.';
    } finally {
        saving.value = false;
    }
}

async function revoke(id) {
    if (!confirm('Revogar este co-produtor?')) return;
    await axios.post(`/produtos/${props.productId}/coproducers/${id}/revoke`);
    message.value = 'Co-produtor revogado.';
    await load();
    await loadCandidates();
}

async function resend(id) {
    await axios.post(`/produtos/${props.productId}/coproducers/${id}/resend`);
    message.value = 'Convite reenviado.';
}

onMounted(async () => {
    await load();
    await loadCandidates();
});
</script>

<template>
    <div class="panel-card-lg space-y-8">
        <div>
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Co-produção</h2>
            <p class="mt-1 text-sm text-zinc-500">
                Comissão sobre o valor líquido (após taxas do gateway). Adicione alguém da sua equipe ou envie convite por e-mail.
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-2">
                    <Zap class="mt-0.5 h-5 w-5 shrink-0 text-[var(--color-primary)]" />
                    <div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">
                            Repasse automático via split CajuPay
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">
                            Permite que parte da venda vá direto para a conta CajuPay do co-produtor (PIX com split).
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="splitPayoutEnabled"
                    :disabled="savingSettings || (!cajupayConnected && !splitPayoutEnabled)"
                    class="relative inline-flex h-7 w-12 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition disabled:cursor-not-allowed disabled:opacity-50"
                    :class="splitPayoutEnabled ? 'bg-[var(--color-primary)]' : 'bg-zinc-300 dark:bg-zinc-600'"
                    @click="toggleSplitPayout"
                >
                    <span
                        class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow transition"
                        :class="splitPayoutEnabled ? 'translate-x-5' : 'translate-x-0'"
                    />
                </button>
            </div>
            <p v-if="!cajupayConnected" class="mt-3 text-xs text-amber-700 dark:text-amber-400">
                Conecte o gateway CajuPay em Integrações para usar split.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                    <Wallet class="h-4 w-4 text-zinc-500" />
                    Conta única (padrão)
                </div>
                <p class="mt-2 text-xs leading-relaxed text-zinc-500">
                    Todo o pagamento cai na <strong>sua</strong> conta CajuPay. O Power On registra a comissão do co-produtor;
                    ele acompanha vendas aqui e saca pelo <strong>Financeiro do parceiro</strong> quando o saldo liberar.
                </p>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                    <Zap class="h-4 w-4 text-[var(--color-primary)]" />
                    Split direto na CajuPay
                </div>
                <p class="mt-2 text-xs leading-relaxed text-zinc-500">
                    Cada co-produtor pode ter <strong>seu próprio UUID</strong> (cadastre um por pessoa na lista abaixo).
                    Na venda PIX, a CajuPay reparte o líquido para a conta dele. O percentual do split é configurado
                    <strong>no painel CajuPay</strong> (o Power On só guarda o ID).
                </p>
            </div>
        </div>

        <div class="flex gap-2 rounded-lg border border-blue-200/80 bg-blue-50/50 px-3 py-2.5 text-xs text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200">
            <Info class="h-4 w-4 shrink-0 mt-0.5" />
            <p>
                A CajuPay aceita <strong>um split por cobrança PIX</strong>. Você pode ter vários co-produtores com split
                (cada um com seu UUID); em cada venda, o split na cobrança vai para <strong>um</strong> co-produtor
                (o de maior % naquela venda; os demais co-produtores com split recebem pelo Financeiro do parceiro).
                <strong>Venda com afiliado:</strong> o afiliado continua na conta única; o co-produtor com split
                <strong>pode</strong> receber repasse direto na CajuPay normalmente.
            </p>
        </div>

        <div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition',
                        mode === 'member'
                            ? 'bg-[var(--color-primary)] text-white'
                            : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300',
                    ]"
                    @click="mode = 'member'"
                >
                    <Users class="h-4 w-4" />
                    Equipe / conta
                </button>
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition',
                        mode === 'invite'
                            ? 'bg-[var(--color-primary)] text-white'
                            : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300',
                    ]"
                    @click="mode = 'invite'"
                >
                    <Mail class="h-4 w-4" />
                    Convite por e-mail
                </button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Comissão (%)</label>
                <input
                    v-model.number="commissionForm.commission_percent"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Duração</label>
                <select
                    v-model="commissionForm.duration_days"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option :value="null">Indeterminado</option>
                    <option :value="30">30 dias</option>
                    <option :value="60">60 dias</option>
                    <option :value="90">90 dias</option>
                    <option :value="120">120 dias</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Como o co-produtor recebe?</label>
                <select
                    v-model="commissionForm.payout_method"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    :disabled="!canUseSplitPayout && commissionForm.payout_method !== 'cajupay_split'"
                >
                    <option value="internal">Conta única (Power On / sua CajuPay)</option>
                    <option value="cajupay_split" :disabled="!canUseSplitPayout">Split direto na CajuPay</option>
                </select>
            </div>
            <div v-if="commissionForm.payout_method === 'cajupay_split'" class="md:col-span-2">
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">ID do split (UUID) — painel CajuPay</label>
                <input
                    v-model="commissionForm.cajupay_split_id"
                    type="text"
                    placeholder="550e8400-e29b-41d4-a716-446655440000"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <p class="mt-1 text-xs text-zinc-500">
                    O co-produtor cria o split na conta CajuPay dele e envia este código para você colar aqui.
                </p>
            </div>
            <div class="flex flex-col justify-end gap-2 text-sm md:col-span-2">
                <label class="flex items-center gap-2">
                    <input v-model="commissionForm.commission_on_producer_sales" type="checkbox" />
                    Vendas do produtor (checkout direto)
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="commissionForm.commission_on_affiliate_sales" type="checkbox" />
                    Vendas de afiliados
                </label>
            </div>
        </div>

        <div v-if="mode === 'member'" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="relative">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input
                    v-model="candidateSearch"
                    type="search"
                    placeholder="Buscar por nome ou e-mail…"
                    class="w-full rounded-lg border border-zinc-300 py-2 pl-10 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    @input="onCandidateSearch"
                />
            </div>
            <p class="text-xs text-zinc-500">
                Lista infoprodutores e membros da equipe desta conta que ainda não são co-produtores deste produto.
            </p>
            <ul v-if="candidates.length" class="max-h-56 divide-y overflow-y-auto rounded-lg border border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                <li
                    v-for="c in candidates"
                    :key="c.id"
                    class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                    :class="selectedUserId === c.id ? 'bg-[color-mix(in_srgb,var(--color-primary)_12%,transparent)]' : ''"
                    @click="selectCandidate(c)"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ c.name }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ c.email }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ c.role_label }}
                    </span>
                </li>
            </ul>
            <p v-else class="text-sm text-zinc-500">Nenhum membro disponível para adicionar.</p>
            <Button type="button" :disabled="saving || !selectedUserId" @click="assignMember">
                {{ saving ? 'Adicionando…' : 'Adicionar co-produtor' }}
            </Button>
        </div>

        <form v-else class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" @submit.prevent="sendInvite">
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail do convidado</label>
                <input
                    v-model="inviteForm.email"
                    type="email"
                    required
                    placeholder="pessoa@email.com"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Comissão (%)</label>
                <input
                    v-model.number="inviteForm.commission_percent"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Como o co-produtor recebe?</label>
                <select
                    v-model="inviteForm.payout_method"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="internal">Conta única (Power On)</option>
                    <option value="cajupay_split" :disabled="!canUseSplitPayout">Split direto na CajuPay</option>
                </select>
            </div>
            <div v-if="inviteForm.payout_method === 'cajupay_split'">
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">ID do split (UUID)</label>
                <input
                    v-model="inviteForm.cajupay_split_id"
                    type="text"
                    required
                    placeholder="UUID do painel CajuPay"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
            </div>
            <p class="text-xs text-zinc-500">
                A pessoa receberá um link para aceitar o convite (mesmo estilo da página de afiliados).
            </p>
            <Button type="submit" :disabled="saving">{{ saving ? 'Enviando…' : 'Enviar convite por e-mail' }}</Button>
        </form>

        <p v-if="message" class="text-sm text-zinc-600 dark:text-zinc-400">{{ message }}</p>

        <div
            v-if="editingCoproducer"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="closeEdit"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Editar repasse</h3>
                <p class="mt-1 text-sm text-zinc-500">{{ editingCoproducer.user?.name || editingCoproducer.email }}</p>
                <div class="mt-4 space-y-3">
                    <div>
                        <label class="text-sm font-medium">Comissão (%)</label>
                        <input
                            v-model.number="editForm.commission_percent"
                            type="number"
                            min="0"
                            max="100"
                            class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Forma de repasse</label>
                        <select v-model="editForm.payout_method" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                            <option value="internal">Conta única</option>
                            <option value="cajupay_split" :disabled="!canUseSplitPayout">Split CajuPay</option>
                        </select>
                    </div>
                    <div v-if="editForm.payout_method === 'cajupay_split'">
                        <label class="text-sm font-medium">ID do split (UUID)</label>
                        <input
                            v-model="editForm.cajupay_split_id"
                            type="text"
                            class="mt-1 w-full rounded-lg border px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="closeEdit">Cancelar</Button>
                    <Button type="button" :disabled="saving" @click="saveEdit">Salvar</Button>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Co-produtores</h3>
            <p v-if="loading" class="mt-2 text-sm text-zinc-500">Carregando…</p>
            <ProductPartnersTable
                v-else
                class="mt-4"
                :rows="coproducerRows"
                :status-labels="coproducerStatusLabels"
                :show-product-column="false"
                empty-label="Nenhum co-produtor ainda."
            >
                <template #menu="{ row, close }">
                    <template v-if="row">
                        <button
                            v-if="row.status === 'active'"
                            type="button"
                            class="flex w-full px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="openEdit(coproducers.find((c) => c.id === row.id)); close()"
                        >
                            Editar repasse
                        </button>
                        <button
                            v-if="row.status === 'pending'"
                            type="button"
                            class="flex w-full px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="resend(row.id); close()"
                        >
                            Reenviar convite
                        </button>
                        <button
                            v-if="row.status !== 'revoked'"
                            type="button"
                            class="flex w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/20"
                            @click="revoke(row.id); close()"
                        >
                            Revogar
                        </button>
                    </template>
                </template>
            </ProductPartnersTable>
        </div>
    </div>
</template>
