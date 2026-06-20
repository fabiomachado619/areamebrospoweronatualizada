<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Puzzle, Power, PowerOff, ExternalLink, CreditCard, Package, Download, Trash2, FolderUp, ArrowUpRight } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const TABS = [
    { id: 'installed', label: 'Instalados', icon: Puzzle },
    { id: 'store', label: 'Loja de plugins', icon: Package },
];

const CATEGORY_LABELS = {
    gateway: 'Gateway',
    integration: 'Integração',
    marketing: 'Marketing',
    outros: 'Outros',
    other: 'Outros',
};

const props = defineProps({
    plugins: { type: Array, default: () => [] },
    /** Lista de slugs instalados (registrados no servidor). */
    installedPluginSlugs: { type: Array, default: () => [] },
    /** Lista de nomes dos plugins instalados (para comparar com a loja por nome). */
    installedPluginNames: { type: Array, default: () => [] },
    storePlugins: { type: Array, default: () => [] },
    pluginStore: { type: Object, default: () => ({ store_url: '', submit_url: '' }) },
    pluginsPath: { type: String, default: '' },
    /** Pasta persistente para instalações (ZIP/loja). */
    plugins_install_path: { type: String, default: '' },
    /** Pasta versionada com o código (ex.: example-gateway). */
    plugins_bundled_path: { type: String, default: '' },
});

const page = usePage();
/** Normaliza slug para comparação (loja pode usar _ e pasta pode usar -). */
function normalizeSlug(s) {
    if (s == null || typeof s !== 'string') return '';
    return s.toLowerCase().replace(/_/g, '-').replace(/[^a-z0-9-]/g, '');
}
/** Normaliza nome para comparação (minúsculas, sem acentos, espaços colapsados). */
function normalizeName(s) {
    if (s == null || typeof s !== 'string') return '';
    const t = s.toLowerCase().trim().replace(/\s+/g, ' ');
    return t.normalize('NFD').replace(/\p{Diacritic}/gu, '');
}
/** Sets de slugs e nomes instalados (servidor), normalizados. */
const installedSlugsSet = computed(() => {
    const slugs = Array.isArray(props.installedPluginSlugs) ? props.installedPluginSlugs : [];
    return new Set(slugs.map((slug) => normalizeSlug(slug)));
});
const installedNamesSet = computed(() => {
    const names = Array.isArray(props.installedPluginNames) ? props.installedPluginNames : [];
    return new Set(names.map((name) => normalizeName(name)));
});
/** Verifica se o plugin da loja está instalado — por slug ou por nome. */
function isStorePluginInstalled(storePlugin) {
    const slug = storePlugin?.slug ?? storePlugin;
    const name = typeof storePlugin === 'object' ? storePlugin?.name : undefined;
    if (slug && installedSlugsSet.value.has(normalizeSlug(slug))) return true;
    if (name && installedNamesSet.value.has(normalizeName(name))) return true;
    return false;
}
const currentTab = computed(() => {
    const url = page.url;
    const idx = url.indexOf('?');
    const search = idx !== -1 ? url.slice(idx) : '';
    const q = new URLSearchParams(search);
    const t = q.get('tab');
    return TABS.some((tab) => tab.id === t) ? t : 'installed';
});

const storeDetail = ref(null);
const installingSlug = ref(null);
const storeBannerFailed = ref({});
const storePluginsList = ref([]);
const storePluginsLoading = ref(false);
const storePluginsError = ref(null);
const lastInstallDownloadUrl = ref(null);
const lastInstallSlug = ref(null);
const showZipUnavailableModal = ref(false);
const showManualInstallModal = ref(false);
const manualInstallFileInput = ref(null);
const manualInstallError = ref('');
const manualInstallProcessing = ref(false);
const downloadFallbackLoading = ref(false);
const downloadFallbackError = ref('');

/** URL fixa da loja — não vem do .env. */
const PLUGIN_STORE_URL = 'https://store.getfy.cloud';

function goToPluginStore() {
    if (typeof window !== 'undefined') {
        window.open(PLUGIN_STORE_URL, '_blank', 'noopener,noreferrer');
    }
}

function setTab(tabId) {
    if (tabId === 'store') {
        goToPluginStore();
        return;
    }
    router.get('/gerenciar-plugins', { tab: tabId }, { preserveState: true });
}

watch(
    currentTab,
    (tab) => {
        if (tab !== 'store') return;
        goToPluginStore();
        router.replace('/gerenciar-plugins', { preserveState: true });
    },
    { immediate: true }
);

async function loadStorePlugins() {
    const baseUrl = PLUGIN_STORE_URL;
    storePluginsError.value = null;
    storePluginsLoading.value = true;
    try {
        // Busca direto na API da loja (navegador → plugins-getfy) para evitar requisição servidor→servidor que caía no vhost errado
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins';
        const r = await fetch(apiUrl);
        const json = await r.json();
        storePluginsList.value = Array.isArray(json?.data) ? json.data : [];
        if (json?.error) storePluginsError.value = json.error;
        if (!r.ok) storePluginsError.value = json?.error || `Loja retornou HTTP ${r.status}.`;
    } catch (e) {
        storePluginsList.value = [];
        storePluginsError.value = 'Não foi possível carregar a loja. Tente novamente mais tarde.';
    } finally {
        storePluginsLoading.value = false;
    }
}

function categoryLabel(category) {
    return CATEGORY_LABELS[category] ?? category ?? 'Outros';
}

async function openStoreDetail(plugin) {
    storeDetail.value = { ...plugin };
    const baseUrl = PLUGIN_STORE_URL;
    try {
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins/' + encodeURIComponent(plugin.slug);
        const r = await fetch(apiUrl);
        if (r.ok) {
            const json = await r.json();
            if (json?.data) {
                storeDetail.value = { ...storeDetail.value, ...json.data };
            }
        }
    } catch (_) {}
}

function closeStoreDetail() {
    storeDetail.value = null;
}

function setStoreBannerFailed(slug) {
    storeBannerFailed.value = { ...storeBannerFailed.value, [slug]: true };
}

const returnUrl = computed(() => {
    const base = typeof window !== 'undefined' ? window.location.origin : '';
    return base + '/gerenciar-plugins?tab=installed&install=';
});

function checkoutUrl(slug) {
    const base = PLUGIN_STORE_URL.replace(/\/$/, '');
    const targetCheckout = '/c/' + slug + '?return_url=' + encodeURIComponent(returnUrl.value + slug);
    return `${base}/login?next=${encodeURIComponent(targetCheckout)}`;
}

async function installStorePlugin(slug, purchaseToken = null) {
    const baseUrl = PLUGIN_STORE_URL;
    installingSlug.value = slug;
    storePluginsError.value = null;
    try {
        // 1) Obter link de download no navegador (evita requisição servidor→loja)
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins/' + encodeURIComponent(slug) + '/request-download';
        const body = purchaseToken ? { purchase_token: purchaseToken } : {};
        const r = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
            credentials: 'omit',
        });
        const json = await r.json().catch(() => ({}));
        const downloadUrl = json?.download_url;
        if (!r.ok || !downloadUrl) {
            storePluginsError.value = json?.message || json?.error || `Loja retornou HTTP ${r.status}.`;
            installingSlug.value = null;
            return;
        }
        // 2) Baixar o ZIP no navegador (evita que o servidor precise acessar a loja)
        const zipRes = await fetch(downloadUrl, { credentials: 'omit' });
        if (!zipRes.ok) {
            storePluginsError.value = 'Não foi possível baixar o arquivo do plugin.';
            installingSlug.value = null;
            return;
        }
        const blob = await zipRes.blob();
        const file = new File([blob], slug + '.zip', { type: 'application/zip' });
        lastInstallDownloadUrl.value = downloadUrl;
        lastInstallSlug.value = slug;
        // 3) Enviar via Inertia (CSRF e redirect tratados automaticamente)
        router.post(`/gerenciar-plugins/install/${slug}`, { plugin_zip: file }, {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => { installingSlug.value = null; },
            onError: (errors) => {
                storePluginsError.value = typeof errors === 'object' && errors?.plugin_zip
                    ? (Array.isArray(errors.plugin_zip) ? errors.plugin_zip[0] : errors.plugin_zip)
                    : 'Falha ao instalar. Tente novamente.';
            },
        });
    } catch (e) {
        storePluginsError.value = 'Não foi possível obter ou instalar o plugin. Verifique a conexão.';
        installingSlug.value = null;
    }
}

function enablePlugin(slug) {
    router.post(`/integracoes/plugins/${slug}/enable`, {}, { preserveScroll: true });
}

function disablePlugin(slug) {
    router.post(`/integracoes/plugins/${slug}/disable`, {}, { preserveScroll: true });
}

const registeringSlug = ref(null);
function registerPlugin(slug) {
    registeringSlug.value = slug;
    router.post(`/gerenciar-plugins/register-plugin/${slug}`, {}, {
        preserveScroll: true,
        onFinish: () => { registeringSlug.value = null; },
    });
}

const uninstallingSlug = ref(null);
function uninstallPlugin(plugin) {
    if (!window.confirm(`Excluir o plugin "${plugin.name}"? A pasta do plugin será removida e não será possível desfazer.`)) return;
    uninstallingSlug.value = plugin.slug;
    router.delete(`/integracoes/plugins/${plugin.slug}`, {
        preserveScroll: true,
        onFinish: () => { uninstallingSlug.value = null; },
    });
}

function goToGateways() {
    router.visit('/integracoes?tab=gateways');
}

const urlPurchaseToken = ref(null);
const urlInstallSlug = ref(null);
watch(() => page.url, () => {
    if (typeof window === 'undefined') return;
    const q = new URLSearchParams(window.location.search);
    urlPurchaseToken.value = q.get('purchase_token') || null;
    urlInstallSlug.value = q.get('install') || null;
}, { immediate: true });

watch([urlPurchaseToken, urlInstallSlug, currentTab], ([token, installSlug, tab]) => {
    if (tab !== 'installed' || !installSlug || !token) return;
    installStorePlugin(installSlug, token);
}, { immediate: true });

watch(() => page.props?.flash?.zip_unavailable, (v) => {
    if (v) showZipUnavailableModal.value = true;
});

function openZipUnavailableModal() {
    showZipUnavailableModal.value = true;
    downloadFallbackError.value = '';
}

function closeZipUnavailableModal() {
    showZipUnavailableModal.value = false;
}

async function downloadPluginFallback() {
    const slug = lastInstallSlug.value;
    const baseUrl = PLUGIN_STORE_URL;
    if (!slug) {
        if (lastInstallDownloadUrl.value) window.open(lastInstallDownloadUrl.value, '_blank');
        closeZipUnavailableModal();
        return;
    }
    downloadFallbackError.value = '';
    downloadFallbackLoading.value = true;
    try {
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins/' + encodeURIComponent(slug) + '/request-download';
        const body = urlPurchaseToken.value ? { purchase_token: urlPurchaseToken.value } : {};
        const r = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
            credentials: 'omit',
        });
        const json = await r.json().catch(() => ({}));
        const downloadUrl = json?.download_url;
        if (!r.ok || !downloadUrl) {
            downloadFallbackError.value = json?.message || json?.error || 'Não foi possível obter o link de download.';
            return;
        }
        window.open(downloadUrl, '_blank');
        closeZipUnavailableModal();
    } catch (e) {
        downloadFallbackError.value = 'Não foi possível obter o link. Tente instalar manualmente com o ZIP.';
    } finally {
        downloadFallbackLoading.value = false;
    }
}

function openManualInstallModal() {
    showManualInstallModal.value = true;
    manualInstallError.value = '';
    if (manualInstallFileInput.value) manualInstallFileInput.value.value = '';
    closeZipUnavailableModal();
}

function closeManualInstallModal() {
    showManualInstallModal.value = false;
    manualInstallError.value = '';
}

function submitManualInstall() {
    const file = manualInstallFileInput.value?.files?.[0];
    if (!file || !file.name.toLowerCase().endsWith('.zip')) {
        manualInstallError.value = 'Selecione um arquivo .zip do plugin.';
        return;
    }
    manualInstallError.value = '';
    manualInstallProcessing.value = true;
    router.post('/gerenciar-plugins/install-from-zip', { plugin_zip: file }, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => { manualInstallProcessing.value = false; },
        onSuccess: () => { closeManualInstallModal(); },
        onError: (errors) => {
            manualInstallError.value = typeof errors?.plugin_zip === 'string'
                ? errors.plugin_zip
                : (errors?.plugin_zip?.[0] ?? 'Falha ao instalar. Tente novamente.');
        },
    });
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    Plugins
                </h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Gerencie extensões instaladas na sua conta.
                </p>
            </div>
        </div>

        <nav
            class="inline-flex flex-wrap gap-1 rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
            aria-label="Abas de plugins"
        >
            <template v-for="tab in TABS" :key="tab.id">
                <a
                    v-if="tab.id === 'store'"
                    :href="PLUGIN_STORE_URL"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 transition-all duration-200 hover:bg-white/60 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-700/50 dark:hover:text-white"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ tab.label }}
                    <ArrowUpRight class="h-3.5 w-3.5 opacity-60" aria-hidden="true" />
                </a>
                <button
                    v-else
                    type="button"
                    :class="[
                        'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                        currentTab === tab.id
                            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                    ]"
                    @click="setTab(tab.id)"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ tab.label }}
                </button>
            </template>
        </nav>

        <!-- Aba Instalados -->
        <template v-if="currentTab === 'installed'">
            <section class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                        Ative ou desative plugins. Envie um ZIP com
                        <code class="rounded bg-zinc-200 px-1 text-xs dark:bg-zinc-700">plugin.json</code>
                        na pasta raiz para instalar manualmente.
                    </p>
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700"
                        @click="openManualInstallModal"
                    >
                        <FolderUp class="h-4 w-4" />
                        Instalar ZIP
                    </button>
                </div>

                <div
                    v-if="plugins.length === 0"
                    class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/80 px-6 py-12 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/30 dark:text-zinc-400"
                >
                    Nenhum plugin na pasta
                    <code class="rounded bg-zinc-200 px-1.5 py-0.5 text-xs dark:bg-zinc-700">plugins/</code>.
                </div>

                <div
                    v-else
                    class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40"
                >
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800" role="list">
                        <li
                            v-for="plugin in plugins"
                            :key="plugin.slug"
                            class="flex flex-col gap-2 px-4 py-2.5 transition hover:bg-zinc-50/80 sm:flex-row sm:items-center sm:gap-4 dark:hover:bg-zinc-800/40"
                        >
                            <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <span class="truncate font-medium text-zinc-900 dark:text-white">
                                            {{ plugin.name }}
                                        </span>
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                            v{{ plugin.version }}
                                        </span>
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300"
                                        >
                                            {{ categoryLabel(plugin.category) }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="plugin.description"
                                        class="mt-0.5 line-clamp-1 text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{ plugin.description }}
                                    </p>
                                    <div class="mt-1 flex flex-wrap items-center gap-3">
                                        <a
                                            v-if="plugin.settings_url"
                                            :href="plugin.settings_url"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-[var(--color-primary)] hover:underline"
                                        >
                                            <ExternalLink class="h-3 w-3" />
                                            Configurar
                                        </a>
                                        <button
                                            v-if="plugin.type === 'gateway' && plugin.is_enabled"
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                                            @click="goToGateways"
                                        >
                                            <CreditCard class="h-3 w-3" />
                                            Gateways
                                        </button>
                                    </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                                <span
                                    v-if="!plugin.is_registered && !plugin.is_core_bundled"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-500/15 text-amber-800 dark:text-amber-300"
                                >
                                    Pendente
                                </span>
                                <span
                                    v-else-if="plugin.is_core_bundled"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-sky-500/15 text-sky-700 dark:text-sky-300"
                                >
                                    Nativo
                                </span>
                                <span
                                    v-else-if="plugin.is_enabled"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-emerald-500/15 text-emerald-700 dark:text-emerald-300"
                                >
                                    Ativo
                                </span>
                                <span
                                    v-else
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400"
                                >
                                    Inativo
                                </span>

                                <template v-if="plugin.is_registered && !plugin.is_core_bundled">
                                    <Button
                                        v-if="plugin.is_enabled"
                                        variant="outline"
                                        size="sm"
                                        @click="disablePlugin(plugin.slug)"
                                    >
                                        <PowerOff class="h-3.5 w-3.5 sm:mr-1" />
                                        <span class="hidden sm:inline">Desativar</span>
                                    </Button>
                                    <Button
                                        v-else
                                        size="sm"
                                        @click="enablePlugin(plugin.slug)"
                                    >
                                        <Power class="h-3.5 w-3.5 sm:mr-1" />
                                        <span class="hidden sm:inline">Ativar</span>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-900/20"
                                        :disabled="uninstallingSlug === plugin.slug"
                                        :title="uninstallingSlug === plugin.slug ? 'Excluindo...' : 'Excluir plugin'"
                                        @click="uninstallPlugin(plugin)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </Button>
                                </template>
                                <template v-else-if="!plugin.is_core_bundled">
                                    <Button
                                        size="sm"
                                        :disabled="registeringSlug === plugin.slug"
                                        @click="registerPlugin(plugin.slug)"
                                    >
                                        <Download v-if="registeringSlug !== plugin.slug" class="h-3.5 w-3.5 sm:mr-1" />
                                        <span v-else class="mr-1 inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        {{ registeringSlug === plugin.slug ? 'Instalando...' : 'Instalar' }}
                                    </Button>
                                </template>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>
        </template>

        <!-- Modal: extensão Zip não disponível (fallback) -->
        <Teleport to="body">
            <div
                v-if="showZipUnavailableModal"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
                @click.self="closeZipUnavailableModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="zip-unavailable-title"
                >
                    <h3 id="zip-unavailable-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Extensão PHP Zip não disponível
                    </h3>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        A instalação automática precisa da extensão Zip no PHP. Use uma das opções abaixo.
                    </p>

                    <div class="mt-4 panel-card-sm">
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            Extrair manualmente no servidor
                        </p>
                        <ol class="mt-2 list-decimal space-y-1 pl-4 text-sm text-zinc-600 dark:text-zinc-400">
                            <li>Obtenha o ZIP do plugin (use o botão abaixo para gerar o link).</li>
                            <li>No painel da sua hospedagem (gerenciador de arquivos, FTP etc.), acesse a pasta de plugins do projeto.</li>
                            <li>Envie o ZIP para essa pasta ou baixe o arquivo direto do link para o servidor (muitos painéis têm “Baixar de URL”).</li>
                            <li>Extraia o ZIP nessa mesma pasta. O resultado deve ser uma pasta que contém o arquivo <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">plugin.json</code>.</li>
                            <li>
                                Instalações via painel ou ZIP — pasta persistente (não é apagada ao atualizar o código a partir do Git):
                                <code class="mt-1 block break-all rounded bg-zinc-200 px-2 py-1 text-xs dark:bg-zinc-700">{{ plugins_install_path || pluginsPath || '.docker/plugins-installed/ (Docker) ou storage/app/plugins-installed/' }}</code>
                            </li>
                            <li>
                                Plugins incluídos no repositório (exemplo) ficam em:
                                <code class="mt-1 block break-all rounded bg-zinc-200 px-2 py-1 text-xs dark:bg-zinc-700">{{ plugins_bundled_path || 'plugins/' }}</code>
                            </li>
                            <li>Atualize esta página para o plugin aparecer.</li>
                        </ol>
                    </div>

                    <p v-if="downloadFallbackError" class="mt-2 text-sm text-red-600 dark:text-red-400">
                        {{ downloadFallbackError }}
                    </p>
                    <div class="mt-6 flex flex-wrap gap-2">
                        <Button
                            v-if="lastInstallSlug"
                            size="sm"
                            :disabled="downloadFallbackLoading"
                            @click="downloadPluginFallback"
                        >
                            <Download v-if="!downloadFallbackLoading" class="mr-1 h-4 w-4" />
                            <span v-else class="mr-1 inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            {{ downloadFallbackLoading ? 'Gerando link...' : 'Baixar plugin (ZIP)' }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="downloadFallbackLoading"
                            @click="openManualInstallModal"
                        >
                            <FolderUp class="mr-1 h-4 w-4" />
                            Instalar manualmente (enviar ZIP)
                        </Button>
                        <Button variant="outline" size="sm" @click="closeZipUnavailableModal">
                            Fechar
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal: instalar plugin manualmente (upload ZIP) -->
        <Teleport to="body">
            <div
                v-if="showManualInstallModal"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
                @click.self="closeManualInstallModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="manual-install-title"
                >
                    <h3 id="manual-install-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Instalar plugin manualmente
                    </h3>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Envie o arquivo .zip do plugin. O nome da pasta do plugin será detectado automaticamente (pasta raiz dentro do ZIP).
                    </p>
                    <form @submit.prevent="submitManualInstall" class="mt-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Arquivo ZIP</label>
                            <input
                                ref="manualInstallFileInput"
                                type="file"
                                accept=".zip"
                                class="mt-1 w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-900 dark:text-white file:mr-2 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:text-zinc-700 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                                @change="manualInstallError = ''"
                            />
                        </div>
                        <p v-if="manualInstallError" class="text-sm text-red-600 dark:text-red-400">
                            {{ manualInstallError }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button type="submit" size="sm" :disabled="manualInstallProcessing">
                                {{ manualInstallProcessing ? 'Instalando...' : 'Instalar' }}
                            </Button>
                            <Button type="button" variant="outline" size="sm" @click="closeManualInstallModal">
                                Cancelar
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
