<script setup>
import { PIXEL_CARD_LOGOS } from '@/lib/conversionPixels';

defineProps({
    app: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['click']);

function baseStyle(logo) {
    return {
        left: `${logo.left}%`,
        top: `${logo.top}%`,
        zIndex: logo.z,
        '--rot': `${logo.rotate}deg`,
        '--scale': String(logo.scale),
    };
}
</script>

<template>
    <button
        type="button"
        class="pixel-apps-card group flex w-full flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white text-left shadow-sm transition-all duration-300 hover:border-[color-mix(in_srgb,var(--color-primary)_35%,transparent)] hover:shadow-lg dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-[color-mix(in_srgb,var(--color-primary)_40%,transparent)]"
        @click="emit('click')"
    >
        <div
            class="relative h-[7.5rem] shrink-0 overflow-hidden border-b border-zinc-200/80 dark:border-zinc-700"
        >
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_90%_80%_at_70%_20%,color-mix(in_srgb,var(--color-primary)_14%,transparent),transparent_55%),linear-gradient(135deg,#f4f4f5_0%,#fff_45%,color-mix(in_srgb,var(--color-primary)_8%,white)_100%)] dark:bg-[radial-gradient(ellipse_90%_80%_at_70%_20%,color-mix(in_srgb,var(--color-primary)_22%,transparent),transparent_55%),linear-gradient(135deg,#27272a_0%,#18181b_50%,color-mix(in_srgb,var(--color-primary)_12%,#18181b)_100%)]"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute left-[18%] top-[78%] h-16 w-24 -translate-x-1/2 -translate-y-1/2 rounded-full bg-zinc-400/15 blur-2xl dark:bg-zinc-500/10"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute right-[8%] top-[12%] h-14 w-20 rounded-full bg-[var(--color-primary)]/15 blur-2xl transition-all duration-500 group-hover:bg-[var(--color-primary)]/25 dark:bg-[var(--color-primary)]/25"
                aria-hidden="true"
            />

            <div class="relative h-full w-full">
                <div
                    v-for="logo in PIXEL_CARD_LOGOS"
                    :key="logo.id"
                    class="logo-chip absolute transition-transform duration-500 ease-[cubic-bezier(0.34,1.56,0.64,1)] will-change-transform"
                    :data-logo="logo.id"
                    :style="baseStyle(logo)"
                >
                    <img
                        :src="logo.image"
                        :alt="logo.id"
                        class="object-contain drop-shadow-sm transition-[filter] duration-300 group-hover:drop-shadow-md"
                        :class="logo.id === 'meta' ? 'h-11 w-11 sm:h-12 sm:w-12' : 'h-8 w-8 sm:h-9 sm:w-9'"
                        loading="lazy"
                        @error="($e) => ($e.target.style.opacity = '0.35')"
                    />
                </div>
            </div>

            <span
                class="absolute bottom-2 right-3 rounded-md bg-white/70 px-1.5 py-0.5 text-[9px] font-medium text-zinc-500 backdrop-blur-sm dark:bg-zinc-900/60 dark:text-zinc-400"
            >
                + scripts
            </span>
        </div>

        <div class="flex flex-1 flex-col gap-2 p-4">
            <div class="font-semibold text-zinc-900 dark:text-white">
                {{ app.name }}
            </div>
            <p
                v-if="app.description"
                class="line-clamp-2 text-sm text-zinc-600 dark:text-zinc-400"
            >
                {{ app.description }}
            </p>
            <span
                v-if="app.status"
                class="inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="
                    app.status === 'active'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                        : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400'
                "
            >
                {{ app.status === 'active' ? 'Ativo' : app.status }}
            </span>
        </div>
    </button>
</template>

<style scoped>
.logo-chip {
    transform: translate(-50%, -50%) rotate(var(--rot)) scale(var(--scale));
}

/* Hover: cada logo sai numa direção diferente (assimétrico) */
.pixel-apps-card:hover .logo-chip[data-logo='tiktok'] {
    transform: translate(calc(-50% - 10px), calc(-50% + 4px)) rotate(-28deg) scale(calc(var(--scale) * 1.06));
}

.pixel-apps-card:hover .logo-chip[data-logo='google_ads'] {
    transform: translate(calc(-50% - 14px), calc(-50% - 6px)) rotate(-16deg) scale(calc(var(--scale) * 1.05));
}

.pixel-apps-card:hover .logo-chip[data-logo='meta'] {
    transform: translate(calc(-50% + 6px), calc(-50% - 12px)) rotate(2deg) scale(calc(var(--scale) * 1.12));
}

.pixel-apps-card:hover .logo-chip[data-logo='google_analytics'] {
    transform: translate(calc(-50% + 12px), calc(-50% - 8px)) rotate(24deg) scale(calc(var(--scale) * 1.08));
}
</style>
