<script setup>
import { ref, onUnmounted } from 'vue';

const message = ref(null);
let hideTimer = null;

function show(msg) {
    message.value = msg;
    if (hideTimer) {
        clearTimeout(hideTimer);
    }
    hideTimer = setTimeout(() => {
        message.value = null;
        hideTimer = null;
    }, 4500);
}

function hide() {
    message.value = null;
    if (hideTimer) {
        clearTimeout(hideTimer);
        hideTimer = null;
    }
}

onUnmounted(() => {
    if (hideTimer) clearTimeout(hideTimer);
});

defineExpose({ show, hide });
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="message"
                role="status"
                aria-live="polite"
                class="fixed bottom-6 left-1/2 z-[100002] w-[min(92vw,24rem)] -translate-x-1/2 rounded-xl border border-zinc-600 bg-zinc-900 px-4 py-3 text-center text-sm font-medium text-white shadow-xl"
            >
                {{ message }}
            </div>
        </Transition>
    </Teleport>
</template>
