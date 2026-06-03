<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { Upload, Loader2, X } from 'lucide-vue-next';
import { retryImageOnError } from '@/lib/imageLoadRetry';
import { getImageFormat, softValidateImageRatio } from '@/lib/checkoutContentFormats';

const props = defineProps({
    modelValue: { type: String, default: '' },
    uploadUrl: { type: String, required: true },
    label: { type: String, default: 'Imagem' },
    accept: { type: String, default: 'image/*' },
    /** Tamanho ideal em texto, ex: "1200×630 px" */
    recommendedSize: { type: String, default: '' },
    /** hero | wide | portrait | square — preview com aspect-ratio do checkout */
    aspectFormat: { type: String, default: '' },
    /** Aviso soft se proporção divergir (não bloqueia upload) */
    softValidateRatio: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'uploaded']);

const uploading = ref(false);
const error = ref('');
const ratioWarning = ref('');

const previewUrl = computed(() => props.modelValue || null);

const formatMeta = computed(() => (props.aspectFormat ? getImageFormat(props.aspectFormat) : null));

const previewRecommended = computed(() => {
    if (props.recommendedSize) {
        return props.recommendedSize;
    }
    return formatMeta.value?.recommendedSize ?? '';
});

const previewAspectClass = computed(() => formatMeta.value?.aspectClass ?? '');

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch (_) {}
    }
    return '';
}

function readImageDimensions(file) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve({ width: img.naturalWidth, height: img.naturalHeight });
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        img.src = url;
    });
}

async function onFileChange(e) {
    const file = e.target?.files?.[0];
    if (!file) return;
    error.value = '';
    ratioWarning.value = '';
    uploading.value = true;
    try {
        if (props.softValidateRatio && props.aspectFormat) {
            const dims = await readImageDimensions(file);
            if (dims) {
                const warning = softValidateImageRatio(dims.width, dims.height, props.aspectFormat);
                if (warning) {
                    ratioWarning.value = warning;
                }
            }
        }

        const formData = new FormData();
        formData.append('image', file);
        const { data } = await axios.post(props.uploadUrl, formData, {
            headers: {
                'X-XSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            withCredentials: true,
        });
        emit('update:modelValue', data.url || '');
        emit('uploaded', data);
    } catch (err) {
        const msg = err.response?.data?.message || err.response?.data?.errors?.image?.[0] || 'Falha no envio. Tente outra imagem.';
        error.value = msg;
    } finally {
        uploading.value = false;
        e.target.value = '';
    }
}

function remove() {
    emit('update:modelValue', '');
    error.value = '';
    ratioWarning.value = '';
}

const inputId = computed(() => `img-upload-${Math.random().toString(36).slice(2)}`);
</script>

<template>
    <div class="space-y-2">
        <div v-if="label || previewRecommended" class="flex flex-col gap-0.5">
            <label v-if="label" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {{ label }}
            </label>
            <p v-if="previewRecommended" class="text-xs text-zinc-500 dark:text-zinc-400">
                Tamanho ideal: {{ previewRecommended }}
                <span v-if="formatMeta?.ratioLabel"> ({{ formatMeta.ratioLabel }})</span>
            </p>
        </div>
        <div
            class="flex flex-col items-stretch gap-2 rounded-xl border-2 border-dashed border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
        >
            <div v-if="previewUrl" class="relative w-full max-w-md self-start">
                <div
                    v-if="previewAspectClass"
                    class="relative w-full overflow-hidden rounded-lg bg-zinc-200 dark:bg-zinc-700"
                    :class="previewAspectClass"
                >
                    <img
                        :src="previewUrl"
                        alt="Preview"
                        class="absolute inset-0 h-full w-full object-cover"
                        @error="retryImageOnError"
                    />
                </div>
                <img
                    v-else
                    :src="previewUrl"
                    alt="Preview"
                    class="max-h-32 rounded-lg object-contain"
                    @error="retryImageOnError"
                />
                <button
                    type="button"
                    class="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-red-500 text-white shadow transition hover:bg-red-600"
                    aria-label="Remover imagem"
                    @click="remove"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    :id="inputId"
                    type="file"
                    :accept="accept"
                    class="hidden"
                    :disabled="uploading"
                    @change="onFileChange"
                />
                <label
                    :for="inputId"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:border-zinc-500 dark:hover:bg-zinc-700"
                >
                    <Loader2 v-if="uploading" class="h-4 w-4 animate-spin" />
                    <Upload v-else class="h-4 w-4" />
                    {{ uploading ? 'Enviando…' : 'Subir imagem' }}
                </label>
            </div>
            <p v-if="ratioWarning" class="text-xs font-medium text-amber-600 dark:text-amber-400">
                {{ ratioWarning }}
            </p>
            <p v-if="error" class="text-sm font-medium text-red-600 dark:text-red-400">
                {{ error }}
            </p>
        </div>
    </div>
</template>
