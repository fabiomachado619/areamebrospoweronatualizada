<script setup>
import { ref } from 'vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import {
    PIXEL_TABS,
    newMetaEntry,
    newTiktokEntry,
    newGoogleAdsEntry,
    newGaEntry,
    randomClientId,
} from '@/lib/conversionPixels';
import PixelIntegrationPicker from '@/components/produtos/PixelIntegrationPicker.vue';
import { Link } from '@inertiajs/vue3';

const model = defineModel({ type: Object, required: true });

const props = defineProps({
    disabled: { type: Boolean, default: false },
    availableIntegrations: { type: Object, default: () => ({}) },
});

function scriptIntegrations() {
    return props.availableIntegrations?.custom_script || [];
}

function usesScriptIntegrations() {
    return Array.isArray(model.value.custom_script_integration_ids) && model.value.custom_script_integration_ids.length > 0;
}

function toggleScriptIntegration(id, checked) {
    if (!Array.isArray(model.value.custom_script_integration_ids)) {
        model.value.custom_script_integration_ids = [];
    }
    const numId = Number(id);
    if (checked) {
        if (!model.value.custom_script_integration_ids.includes(numId)) {
            model.value.custom_script_integration_ids.push(numId);
        }
        model.value.custom_script = [];
    } else {
        model.value.custom_script_integration_ids = model.value.custom_script_integration_ids.filter((x) => x !== numId);
    }
}

function isScriptIntegrationSelected(id) {
    return (model.value.custom_script_integration_ids || []).map(Number).includes(Number(id));
}

const selectedPixelTab = ref('meta');

const inputClass =
    'w-full rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--color-primary)_25%,transparent)] dark:border-zinc-700 dark:bg-zinc-800 dark:text-white disabled:opacity-60';
</script>

<template>
    <div class="space-y-6" :class="{ 'pointer-events-none opacity-60': disabled }">
        <div class="flex gap-3 overflow-x-auto pb-2 scroll-smooth" style="scrollbar-width: thin;">
            <button
                v-for="tab in PIXEL_TABS"
                :key="tab.id"
                type="button"
                :disabled="disabled"
                :class="[
                    'flex h-24 w-28 shrink-0 flex-col items-center justify-center gap-1.5 rounded-xl border-2 p-4 transition-all duration-200',
                    selectedPixelTab === tab.id
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 dark:bg-[var(--color-primary)]/20'
                        : 'border-zinc-200 bg-zinc-50 hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500 dark:hover:bg-zinc-700',
                ]"
                @click="selectedPixelTab = tab.id"
            >
                <img
                    :src="tab.image"
                    :alt="tab.label"
                    class="h-8 w-8 object-contain"
                    @error="($e) => $e.target && ($e.target.style.display = 'none')"
                />
                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ tab.label }}</span>
            </button>
        </div>

        <div v-if="selectedPixelTab === 'meta'" class="panel-card-md space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Meta Ads (Facebook)</h3>
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="disabled || !model.meta.enabled"
                        @click="model.meta.entries.push(newMetaEntry())"
                    >
                        <Plus class="mr-1 h-4 w-4" /> Adicionar pixel
                    </Button>
                    <Toggle v-model="model.meta.enabled" :disabled="disabled" />
                </div>
            </div>
            <template v-if="model.meta.enabled">
                <PixelIntegrationPicker
                    platform="meta"
                    :block="model.meta"
                    :integrations="availableIntegrations?.meta || []"
                    :disabled="disabled"
                />
                <details v-if="!(model.meta.integration_ids?.length)" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        Configuração manual (avançado)
                    </summary>
                    <div class="space-y-3 border-t border-zinc-200 p-4 dark:border-zinc-700">
                <div v-for="(item, idx) in model.meta.entries" :key="item.id" class="panel-card-sm space-y-3 dark:bg-zinc-800">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Pixel {{ idx + 1 }}</span>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                            :disabled="disabled"
                            @click="model.meta.entries.splice(idx, 1)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Pixel ID</label>
                        <input v-model="item.pixel_id" type="text" placeholder="Ex: 123456789" :class="inputClass" :disabled="disabled" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Access Token (CAPI)</label>
                        <input
                            v-model="item.access_token"
                            type="password"
                            placeholder="Token para Conversions API"
                            :class="inputClass"
                            autocomplete="off"
                            :disabled="disabled"
                        />
                    </div>
                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar Purchase ao gerar PIX (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar Purchase ao gerar boleto (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" :disabled="disabled" />
                    </div>
                </div>
                <p v-if="model.meta.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhum pixel. Clique em «Adicionar pixel» ou desative a integração.
                </p>
                    </div>
                </details>
            </template>
        </div>

        <div v-if="selectedPixelTab === 'tiktok'" class="panel-card-md space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">TikTok Ads</h3>
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="disabled || !model.tiktok.enabled"
                        @click="model.tiktok.entries.push(newTiktokEntry())"
                    >
                        <Plus class="mr-1 h-4 w-4" /> Adicionar pixel
                    </Button>
                    <Toggle v-model="model.tiktok.enabled" :disabled="disabled" />
                </div>
            </div>
            <template v-if="model.tiktok.enabled">
                <PixelIntegrationPicker
                    platform="tiktok"
                    :block="model.tiktok"
                    :integrations="availableIntegrations?.tiktok || []"
                    :disabled="disabled"
                />
                <details v-if="!(model.tiktok.integration_ids?.length)" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        Configuração manual (avançado)
                    </summary>
                    <div class="space-y-3 border-t border-zinc-200 p-4 dark:border-zinc-700">
                <div v-for="(item, idx) in model.tiktok.entries" :key="item.id" class="panel-card-sm space-y-3 dark:bg-zinc-800">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Pixel {{ idx + 1 }}</span>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                            :disabled="disabled"
                            @click="model.tiktok.entries.splice(idx, 1)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Pixel ID</label>
                        <input v-model="item.pixel_id" type="text" placeholder="Ex: C1X2Y3Z4..." :class="inputClass" :disabled="disabled" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Access Token</label>
                        <input
                            v-model="item.access_token"
                            type="password"
                            placeholder="Token do TikTok Events API"
                            :class="inputClass"
                            autocomplete="off"
                            :disabled="disabled"
                        />
                    </div>
                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar Purchase ao gerar PIX (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar Purchase ao gerar boleto (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" :disabled="disabled" />
                    </div>
                </div>
                <p v-if="model.tiktok.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhum pixel. Clique em «Adicionar pixel» ou desative a integração.
                </p>
                    </div>
                </details>
            </template>
        </div>

        <div v-if="selectedPixelTab === 'google_ads'" class="panel-card-md space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Google Ads</h3>
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="disabled || !model.google_ads.enabled"
                        @click="model.google_ads.entries.push(newGoogleAdsEntry())"
                    >
                        <Plus class="mr-1 h-4 w-4" /> Adicionar conversão
                    </Button>
                    <Toggle v-model="model.google_ads.enabled" :disabled="disabled" />
                </div>
            </div>
            <template v-if="model.google_ads.enabled">
                <PixelIntegrationPicker
                    platform="google_ads"
                    :block="model.google_ads"
                    :integrations="availableIntegrations?.google_ads || []"
                    :disabled="disabled"
                />
                <details v-if="!(model.google_ads.integration_ids?.length)" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        Configuração manual (avançado)
                    </summary>
                    <div class="space-y-3 border-t border-zinc-200 p-4 dark:border-zinc-700">
                <div v-for="(item, idx) in model.google_ads.entries" :key="item.id" class="panel-card-sm space-y-3 dark:bg-zinc-800">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Conversão {{ idx + 1 }}</span>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                            :disabled="disabled"
                            @click="model.google_ads.entries.splice(idx, 1)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Conversion ID</label>
                        <input v-model="item.conversion_id" type="text" placeholder="Ex: AW-123456789" :class="inputClass" :disabled="disabled" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Conversion Label</label>
                        <input v-model="item.conversion_label" type="text" placeholder="Ex: AbCdEfGhIjKlMn" :class="inputClass" :disabled="disabled" />
                    </div>
                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar Purchase ao gerar PIX (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar Purchase ao gerar boleto (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" :disabled="disabled" />
                    </div>
                </div>
                <p v-if="model.google_ads.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhuma conversão. Clique em «Adicionar conversão» ou desative a integração.
                </p>
                    </div>
                </details>
            </template>
        </div>

        <div v-if="selectedPixelTab === 'google_analytics'" class="panel-card-md space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Google Analytics (GA4)</h3>
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="disabled || !model.google_analytics.enabled"
                        @click="model.google_analytics.entries.push(newGaEntry())"
                    >
                        <Plus class="mr-1 h-4 w-4" /> Adicionar propriedade
                    </Button>
                    <Toggle v-model="model.google_analytics.enabled" :disabled="disabled" />
                </div>
            </div>
            <template v-if="model.google_analytics.enabled">
                <PixelIntegrationPicker
                    platform="google_analytics"
                    :block="model.google_analytics"
                    :integrations="availableIntegrations?.google_analytics || []"
                    :disabled="disabled"
                />
                <details v-if="!(model.google_analytics.integration_ids?.length)" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        Configuração manual (avançado)
                    </summary>
                    <div class="space-y-3 border-t border-zinc-200 p-4 dark:border-zinc-700">
                <div
                    v-for="(item, idx) in model.google_analytics.entries"
                    :key="item.id"
                    class="panel-card-sm space-y-3 dark:bg-zinc-800"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">GA4 {{ idx + 1 }}</span>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                            :disabled="disabled"
                            @click="model.google_analytics.entries.splice(idx, 1)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Measurement ID</label>
                        <input v-model="item.measurement_id" type="text" placeholder="Ex: G-XXXXXXXXXX" :class="inputClass" :disabled="disabled" />
                    </div>
                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar Purchase ao gerar PIX (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar Purchase ao gerar boleto (não na aprovação)?" :disabled="disabled" />
                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" :disabled="disabled" />
                    </div>
                </div>
                <p v-if="model.google_analytics.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhuma propriedade. Clique em «Adicionar propriedade» ou desative a integração.
                </p>
                    </div>
                </details>
            </template>
        </div>

        <div v-if="selectedPixelTab === 'custom_script'" class="panel-card-md space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Scripts personalizados</h3>
                <Button
                    v-if="!usesScriptIntegrations()"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="disabled"
                    @click="model.custom_script.push({ id: randomClientId(), name: '', script: '' })"
                >
                    <Plus class="mr-1 h-4 w-4" /> Adicionar script manual
                </Button>
            </div>
            <div class="space-y-3 rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-800/30">
                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Integrações cadastradas</p>
                <p v-if="scriptIntegrations().length === 0" class="text-sm text-zinc-500">
                    <Link href="/integracoes" class="text-[var(--color-primary)] underline">Cadastrar em Integrações</Link>
                </p>
                <div v-else class="space-y-2">
                    <label
                        v-for="item in scriptIntegrations()"
                        :key="item.id"
                        class="flex cursor-pointer items-start gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <input
                            type="checkbox"
                            class="mt-1 rounded border-zinc-300"
                            :checked="isScriptIntegrationSelected(item.id)"
                            :disabled="disabled"
                            @change="toggleScriptIntegration(item.id, $event.target.checked)"
                        />
                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ item.name }}</span>
                    </label>
                </div>
            </div>
            <details v-if="!usesScriptIntegrations()" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                    Scripts manuais (avançado)
                </summary>
                <div class="space-y-3 border-t border-zinc-200 p-4 dark:border-zinc-700">
            <div v-for="(item, idx) in model.custom_script" :key="item.id" class="panel-card-sm space-y-3 dark:bg-zinc-800">
                <div class="flex items-center gap-2">
                    <input v-model="item.name" type="text" placeholder="Nome (opcional)" :class="inputClass + ' flex-1'" :disabled="disabled" />
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                        :disabled="disabled"
                        @click="model.custom_script.splice(idx, 1)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </button>
                </div>
                <textarea
                    v-model="item.script"
                    rows="4"
                    :class="inputClass + ' font-mono text-sm'"
                    placeholder="Cole o código do pixel aqui (ex: &lt;script&gt;...&lt;/script&gt;)"
                    :disabled="disabled"
                />
            </div>
            <p v-if="model.custom_script.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">
                Nenhum script adicionado.
            </p>
                </div>
            </details>
        </div>
    </div>
</template>
