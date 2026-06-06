<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';

const page = usePage();
const branding = computed(() => page.props.public_branding ?? {});
const primary = computed(() => branding.value.theme_primary || '#00cc00');
const appName = computed(() => branding.value.app_name || 'Power On');
const logoLight = computed(() => branding.value.app_logo || branding.value.app_logo_icon || '/brand/logo-full.png');
const logoDark = computed(() => branding.value.app_logo_dark || branding.value.app_logo_icon_dark || logoLight.value);
</script>

<template>
    <div
        class="min-h-screen bg-zinc-50 text-zinc-900 transition-colors dark:bg-zinc-950 dark:text-zinc-100"
        :style="{ '--color-primary': primary }"
    >
        <div
            class="pointer-events-none fixed inset-x-0 top-0 h-64 opacity-30 blur-3xl dark:opacity-20"
            :style="{ background: `linear-gradient(180deg, color-mix(in srgb, ${primary} 35%, transparent), transparent)` }"
            aria-hidden="true"
        />

        <header class="relative z-10 border-b border-zinc-200/80 bg-white/80 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/80">
            <div class="mx-auto flex h-14 max-w-3xl items-center justify-between px-4 sm:px-6">
                <Link href="/" class="flex items-center gap-2">
                    <img :src="logoLight" :alt="appName" class="h-8 max-w-[140px] object-contain dark:hidden" />
                    <img :src="logoDark" :alt="appName" class="hidden h-8 max-w-[140px] object-contain dark:block" />
                </Link>
                <ThemeToggler />
            </div>
        </header>

        <main class="relative z-10 mx-auto max-w-3xl px-4 py-8 sm:px-6 sm:py-10">
            <slot />
        </main>
    </div>
</template>
