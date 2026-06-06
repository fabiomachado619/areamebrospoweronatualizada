<script setup>
import { computed, ref, watch } from 'vue';
import { BookOpen } from 'lucide-vue-next';
import { moduleCoverUrl } from '@/lib/normalizeMediaUrl';

const props = defineProps({
    mod: { type: Object, default: null },
    src: { type: String, default: '' },
    alt: { type: String, default: '' },
    aspectClass: { type: String, default: '' },
    containerClass: { type: String, default: '' },
    imgClass: { type: String, default: 'h-full w-full object-cover' },
    placeholderIconClass: { type: String, default: 'h-5 w-5' },
    absolute: { type: Boolean, default: false },
});

const failed = ref(false);

const resolvedSrc = computed(() => {
    if (props.mod) {
        return moduleCoverUrl(props.mod);
    }

    return moduleCoverUrl({ thumbnail: props.src, thumbnail_url: props.src });
});

const showImage = computed(() => Boolean(resolvedSrc.value) && !failed.value);

watch(
    () => [props.mod, props.src],
    () => {
        failed.value = false;
    },
);

function onError() {
    failed.value = true;
}
</script>

<template>
    <div
        :class="[
            containerClass,
            aspectClass,
            absolute ? 'absolute inset-0' : '',
            'overflow-hidden bg-zinc-200 dark:bg-zinc-700',
        ]"
    >
        <img
            v-if="showImage"
            :src="resolvedSrc"
            :alt="alt"
            :class="imgClass"
            @error="onError"
        />
        <div
            v-else
            class="flex h-full w-full items-center justify-center text-zinc-400 dark:text-zinc-500"
        >
            <BookOpen :class="placeholderIconClass" />
        </div>
    </div>
</template>
