<script setup>
import { computed, ref, watch } from 'vue';
import ImageUpload from '@/components/checkout/ImageUpload.vue';
import Button from '@/components/ui/Button.vue';
import CheckoutContentBlock from '@/components/checkout/CheckoutContentBlock.vue';
import {
    IMAGE_FORMATS,
    TEXT_ALIGNS,
    newImageBlock,
    newTextBlock,
    normalizeContentBlocksForEditor,
} from '@/lib/checkoutContentFormats';
import { ChevronDown, ChevronUp, Trash2, Type, Image as ImageIcon, GripVertical } from 'lucide-vue-next';

const model = defineModel({ type: Array, default: () => [] });

const props = defineProps({
    uploadUrl: { type: String, required: true },
});

const emit = defineEmits(['changed']);

const blocks = ref(normalizeContentBlocksForEditor(model.value));
const dragFromIndex = ref(null);
const dragOverIndex = ref(null);

watch(
    model,
    (value) => {
        blocks.value = normalizeContentBlocksForEditor(value);
    },
    { deep: true }
);

function syncBlocks(next) {
    blocks.value = next;
    model.value = next;
    emit('changed');
}

function blockLabel(block) {
    if (block.type === 'text') {
        return 'Bloco de texto';
    }

    return IMAGE_FORMATS[block.format]?.label ?? 'Imagem';
}

function addImageBlock(format, placement = 'main') {
    syncBlocks([...blocks.value, newImageBlock(format, placement)]);
}

function addTextBlock() {
    syncBlocks([...blocks.value, newTextBlock()]);
}

function removeBlock(index) {
    const next = [...blocks.value];
    next.splice(index, 1);
    syncBlocks(next);
}

function moveBlock(index, direction, peerFilter) {
    const peers = blocks.value
        .map((block, idx) => ({ block, index: idx }))
        .filter(({ block }) => peerFilter(block));
    const peerIdx = peers.findIndex(({ index: idx }) => idx === index);
    if (peerIdx < 0) {
        return;
    }
    const targetPeerIdx = peerIdx + direction;
    if (targetPeerIdx < 0 || targetPeerIdx >= peers.length) {
        return;
    }
    reorderBlock(peers[peerIdx].index, peers[targetPeerIdx].index, peerFilter);
}

function reorderBlock(fromIndex, toIndex, peerFilter) {
    if (fromIndex === toIndex || fromIndex < 0 || toIndex < 0) {
        return;
    }

    const peers = blocks.value
        .map((block, index) => ({ block, index }))
        .filter(({ block }) => peerFilter(block));

    const fromPeerIdx = peers.findIndex(({ index }) => index === fromIndex);
    const toPeerIdx = peers.findIndex(({ index }) => index === toIndex);
    if (fromPeerIdx < 0 || toPeerIdx < 0 || fromPeerIdx === toPeerIdx) {
        return;
    }

    const peerIndices = peers.map(({ index }) => index);
    const peerBlocks = peers.map(({ index }) => blocks.value[index]);
    const [item] = peerBlocks.splice(fromPeerIdx, 1);
    peerBlocks.splice(toPeerIdx, 0, item);

    const next = [...blocks.value];
    peerIndices.forEach((globalIdx, i) => {
        next[globalIdx] = peerBlocks[i];
    });
    syncBlocks(next);
}

function patchBlock(index, patch) {
    const next = [...blocks.value];
    next[index] = { ...next[index], ...patch };
    syncBlocks(next);
}

const dragPeerFilter = ref(null);

const isHeroPeer = (block) => block.type === 'image' && block.format === 'hero';
const isSalesPeer = (block) =>
    block.type === 'text'
    || (block.type === 'image' && block.placement === 'main' && block.format !== 'hero');
const isSidebarPeer = (block) => block.type === 'image' && block.placement === 'sidebar';

function onDragStart(index, event, peerFilter) {
    dragFromIndex.value = index;
    dragOverIndex.value = index;
    dragPeerFilter.value = peerFilter;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));
    }
}

function onDragOver(index, event, peerFilter) {
    if (dragPeerFilter.value !== peerFilter) {
        return;
    }
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
    dragOverIndex.value = index;
}

function onDrop(index, event, peerFilter) {
    if (dragPeerFilter.value !== peerFilter) {
        return;
    }
    event.preventDefault();
    const from = dragFromIndex.value;
    if (from != null && from !== index) {
        reorderBlock(from, index, peerFilter);
    }
    dragFromIndex.value = null;
    dragOverIndex.value = null;
    dragPeerFilter.value = null;
}

function onDragEnd() {
    dragFromIndex.value = null;
    dragOverIndex.value = null;
    dragPeerFilter.value = null;
}

function blockCardClass(index) {
    return [
        'mb-4 space-y-3 rounded-lg bg-zinc-50 p-3 transition dark:bg-zinc-800/50',
        dragOverIndex.value === index && dragFromIndex.value !== index
            ? 'ring-2 ring-[var(--color-primary)] ring-offset-1 dark:ring-offset-zinc-900'
            : '',
        dragFromIndex.value === index ? 'opacity-50' : '',
    ];
}

const heroBlocks = computed(() =>
    blocks.value
        .map((block, index) => ({ block, index }))
        .filter(({ block }) => block.type === 'image' && block.format === 'hero')
);

const salesBlocks = computed(() =>
    blocks.value
        .map((block, index) => ({ block, index }))
        .filter(
            ({ block }) =>
                block.type === 'text'
                || (block.type === 'image' && block.placement === 'main' && block.format !== 'hero')
        )
);

const sidebarBlocks = computed(() =>
    blocks.value
        .map((block, index) => ({ block, index }))
        .filter(({ block }) => block.type === 'image' && block.placement === 'sidebar')
);

const inputClass =
    'w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100';
</script>

<template>
    <div class="space-y-6">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="mb-3 flex items-center justify-between gap-2">
                <div>
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Topo (Hero)</h4>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Proporção fixa 3:1 — ideal 1200×400 px</p>
                </div>
                <Button type="button" variant="secondary" size="sm" @click="addImageBlock('hero', 'main')">
                    + Hero
                </Button>
            </div>
            <div v-if="!heroBlocks.length" class="rounded-lg border border-dashed border-zinc-200 px-3 py-6 text-center text-xs text-zinc-500 dark:border-zinc-600">
                Nenhum banner no topo.
            </div>
            <div
                v-for="{ block, index } in heroBlocks"
                :key="block.id"
                :class="blockCardClass(index)"
                @dragover="onDragOver(index, $event, isHeroPeer)"
                @drop="onDrop(index, $event, isHeroPeer)"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <button
                            type="button"
                            class="cursor-grab touch-none rounded p-1 text-zinc-400 hover:bg-zinc-200 active:cursor-grabbing dark:hover:bg-zinc-700"
                            draggable="true"
                            title="Arrastar para reordenar"
                            @dragstart="onDragStart(index, $event, isHeroPeer)"
                            @dragend="onDragEnd"
                        >
                            <GripVertical class="h-4 w-4" />
                        </button>
                        <span class="truncate text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ blockLabel(block) }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30 dark:hover:bg-zinc-700" :disabled="heroBlocks.findIndex((b) => b.index === index) === 0" @click="moveBlock(index, -1, isHeroPeer)"><ChevronUp class="h-4 w-4" /></button>
                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30 dark:hover:bg-zinc-700" :disabled="heroBlocks.findIndex((b) => b.index === index) >= heroBlocks.length - 1" @click="moveBlock(index, 1, isHeroPeer)"><ChevronDown class="h-4 w-4" /></button>
                        <button type="button" class="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30" @click="removeBlock(index)"><Trash2 class="h-4 w-4" /></button>
                    </div>
                </div>
                <ImageUpload
                    :model-value="block.url"
                    :upload-url="uploadUrl"
                    label="Imagem hero"
                    aspect-format="hero"
                    soft-validate-ratio
                    @update:model-value="patchBlock(index, { url: $event })"
                />
                <CheckoutContentBlock v-if="block.url" :block="block" data-placement="preview" />
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Página de vendas</h4>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Banners e textos acima do formulário — arraste pelo ícone ≡ para reordenar</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="secondary" size="sm" @click="addImageBlock('wide', 'main')">
                        <ImageIcon class="h-3.5 w-3.5" /> Largo
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="addImageBlock('square', 'main')">
                        <ImageIcon class="h-3.5 w-3.5" /> Quadrado
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="addTextBlock">
                        <Type class="h-3.5 w-3.5" /> Texto
                    </Button>
                </div>
            </div>
            <div v-if="!salesBlocks.length" class="rounded-lg border border-dashed border-zinc-200 px-3 py-6 text-center text-xs text-zinc-500 dark:border-zinc-600">
                Adicione banners ou textos para montar sua página de vendas.
            </div>
            <div
                v-for="{ block, index } in salesBlocks"
                :key="block.id"
                :class="blockCardClass(index)"
                @dragover="onDragOver(index, $event, isSalesPeer)"
                @drop="onDrop(index, $event, isSalesPeer)"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <button
                            type="button"
                            class="cursor-grab touch-none rounded p-1 text-zinc-400 hover:bg-zinc-200 active:cursor-grabbing dark:hover:bg-zinc-700"
                            draggable="true"
                            title="Arrastar para reordenar"
                            @dragstart="onDragStart(index, $event, isSalesPeer)"
                            @dragend="onDragEnd"
                        >
                            <GripVertical class="h-4 w-4" />
                        </button>
                        <span class="truncate text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ blockLabel(block) }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30 dark:hover:bg-zinc-700" :disabled="salesBlocks.findIndex((b) => b.index === index) === 0" @click="moveBlock(index, -1, isSalesPeer)"><ChevronUp class="h-4 w-4" /></button>
                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30 dark:hover:bg-zinc-700" :disabled="salesBlocks.findIndex((b) => b.index === index) >= salesBlocks.length - 1" @click="moveBlock(index, 1, isSalesPeer)"><ChevronDown class="h-4 w-4" /></button>
                        <button type="button" class="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30" @click="removeBlock(index)"><Trash2 class="h-4 w-4" /></button>
                    </div>
                </div>
                <template v-if="block.type === 'text'">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título</label>
                        <input
                            :value="block.title"
                            type="text"
                            :class="inputClass"
                            placeholder="Título do bloco"
                            @input="patchBlock(index, { title: $event.target.value })"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Texto</label>
                        <textarea
                            :value="block.body"
                            rows="4"
                            :class="inputClass"
                            placeholder="Descrição, benefícios, garantia..."
                            @input="patchBlock(index, { body: $event.target.value })"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Alinhamento</label>
                        <select :value="block.align" :class="inputClass" @change="patchBlock(index, { align: $event.target.value })">
                            <option v-for="opt in TEXT_ALIGNS" :key="opt.id" :value="opt.id">{{ opt.label }}</option>
                        </select>
                    </div>
                </template>
                <template v-else>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Formato</label>
                        <select :value="block.format" :class="inputClass" @change="patchBlock(index, { format: $event.target.value })">
                            <option value="wide">Largo (16:9)</option>
                            <option value="square">Quadrado (1:1)</option>
                        </select>
                    </div>
                    <ImageUpload
                        :model-value="block.url"
                        :upload-url="uploadUrl"
                        :label="blockLabel(block)"
                        :aspect-format="block.format"
                        soft-validate-ratio
                        @update:model-value="patchBlock(index, { url: $event })"
                    />
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Link (opcional)</label>
                        <input :value="block.link" type="url" :class="inputClass" placeholder="https://" @input="patchBlock(index, { link: $event.target.value })" />
                    </div>
                    <p v-if="block.url" class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Pré-visualização</p>
                    <CheckoutContentBlock v-if="block.url" :block="block" data-placement="preview" />
                </template>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="mb-3 flex items-center justify-between gap-2">
                <div>
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Banners laterais</h4>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Proporção fixa 2:3 — ideal 400×600 px (desktop)</p>
                </div>
                <Button type="button" variant="secondary" size="sm" @click="addImageBlock('portrait', 'sidebar')">
                    + Lateral
                </Button>
            </div>
            <div v-if="!sidebarBlocks.length" class="rounded-lg border border-dashed border-zinc-200 px-3 py-6 text-center text-xs text-zinc-500 dark:border-zinc-600">
                Nenhum banner lateral.
            </div>
            <div
                v-for="{ block, index } in sidebarBlocks"
                :key="block.id"
                :class="[blockCardClass(index), 'max-w-xs']"
                @dragover="onDragOver(index, $event, isSidebarPeer)"
                @drop="onDrop(index, $event, isSidebarPeer)"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <button
                            type="button"
                            class="cursor-grab touch-none rounded p-1 text-zinc-400 hover:bg-zinc-200 active:cursor-grabbing dark:hover:bg-zinc-700"
                            draggable="true"
                            title="Arrastar para reordenar"
                            @dragstart="onDragStart(index, $event, isSidebarPeer)"
                            @dragend="onDragEnd"
                        >
                            <GripVertical class="h-4 w-4" />
                        </button>
                        <span class="truncate text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ blockLabel(block) }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30 dark:hover:bg-zinc-700" :disabled="sidebarBlocks.findIndex((b) => b.index === index) === 0" @click="moveBlock(index, -1, isSidebarPeer)"><ChevronUp class="h-4 w-4" /></button>
                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30 dark:hover:bg-zinc-700" :disabled="sidebarBlocks.findIndex((b) => b.index === index) >= sidebarBlocks.length - 1" @click="moveBlock(index, 1, isSidebarPeer)"><ChevronDown class="h-4 w-4" /></button>
                        <button type="button" class="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30" @click="removeBlock(index)"><Trash2 class="h-4 w-4" /></button>
                    </div>
                </div>
                <ImageUpload
                    :model-value="block.url"
                    :upload-url="uploadUrl"
                    label="Banner lateral"
                    aspect-format="portrait"
                    soft-validate-ratio
                    @update:model-value="patchBlock(index, { url: $event })"
                />
                <CheckoutContentBlock v-if="block.url" :block="block" data-placement="preview" />
            </div>
        </div>
    </div>
</template>
