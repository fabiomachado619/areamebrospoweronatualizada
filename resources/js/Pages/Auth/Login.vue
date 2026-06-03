<script setup>
import { ref, computed } from 'vue';
import { useForm, Link, usePage } from '@inertiajs/vue3';
import { Eye, EyeOff, Mail, Lock, ArrowRight } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';
import { retryImageOnError } from '@/lib/imageLoadRetry';

const showPassword = ref(false);
const page = usePage();
const flashError = computed(() => page.props.flash?.error ?? null);

const branding = computed(() => page.props.public_branding ?? {});
const primary = computed(() => branding.value.theme_primary || '#00cc00');
const appName = computed(() => branding.value.app_name || 'Getfy');
const logoLight = computed(() => branding.value.app_logo || branding.value.app_logo_icon || 'https://cdn.getfy.cloud/collapsed-logo.png');
const logoDark = computed(() => branding.value.app_logo_dark || branding.value.app_logo_icon_dark || logoLight.value);
const heroImage = computed(() => branding.value.login_hero_image || 'https://cdn.getfy.cloud/login.webp');

const redirectAfterLogin = computed(() => page.props.redirect ?? null);

const form = useForm({
    email: '',
    password: '',
    remember: false,
    redirect: redirectAfterLogin.value || '',
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <div class="wl-root fixed inset-0 z-0 flex overflow-hidden bg-zinc-50 dark:bg-zinc-900">
        <!-- Esquerda: painel de login -->
        <div class="relative flex min-h-0 w-full flex-col overflow-hidden lg:w-[min(42%,480px)] lg:shrink-0 lg:border-r lg:border-zinc-200/80 dark:lg:border-zinc-800">
            <!-- Ambiente decorativo -->
            <div
                class="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full opacity-[0.14] blur-3xl dark:opacity-[0.2]"
                :style="{ background: `color-mix(in srgb, ${primary} 70%, white)` }"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-16 right-0 h-56 w-56 rounded-full opacity-[0.08] blur-2xl dark:opacity-[0.12]"
                :style="{ background: `color-mix(in srgb, ${primary} 50%, transparent)` }"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute inset-y-0 right-0 hidden w-px bg-gradient-to-b from-transparent via-zinc-200 to-transparent dark:via-zinc-700 lg:block"
                aria-hidden="true"
            />

            <header class="login-fade relative z-10 flex items-center justify-between gap-4 px-6 pb-2 pt-6 sm:px-8">
                <img
                    :src="logoLight"
                    :alt="appName"
                    class="h-11 max-w-[200px] object-contain object-left dark:hidden"
                    @error="retryImageOnError"
                />
                <img
                    :src="logoDark"
                    :alt="appName"
                    class="hidden h-11 max-w-[200px] object-contain object-left dark:block"
                    @error="retryImageOnError"
                />
                <ThemeToggler />
            </header>

            <div class="relative z-10 flex min-h-0 flex-1 flex-col justify-center overflow-y-auto overscroll-contain px-6 py-6 sm:px-10 sm:py-8">
                <div class="login-fade login-fade-delay-1 mx-auto w-full max-w-[400px]">
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-[2rem]">
                        Bem-vindo de volta
                    </h1>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
                        <template v-if="redirectAfterLogin">
                            Entre com sua conta para concluir a afiliação ao programa.
                        </template>
                        <template v-else>
                            Entre com sua conta para acompanhar vendas, produtos e métricas em tempo real.
                        </template>
                    </p>

                    <p
                        v-if="flashError"
                        class="login-fade-delay-2 mt-6 rounded-2xl border border-amber-200/80 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        {{ flashError }}
                    </p>

                    <div
                        class="login-fade-delay-2 mt-8 rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-800/80 dark:bg-zinc-800/40 dark:shadow-none sm:p-7"
                    >
                        <form class="space-y-5" @submit.prevent="submit">
                            <div>
                                <label for="email" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    E-mail
                                </label>
                                <div class="relative">
                                    <Mail
                                        class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
                                        aria-hidden="true"
                                    />
                                    <input
                                        id="email"
                                        v-model="form.email"
                                        type="email"
                                        autocomplete="email"
                                        required
                                        class="wl-input block w-full rounded-xl border border-zinc-200 bg-zinc-50 py-3 pl-11 pr-4 text-sm text-zinc-900 placeholder-zinc-400 transition dark:border-zinc-600 dark:bg-zinc-900/60 dark:text-white dark:placeholder-zinc-500"
                                        placeholder="seu@email.com"
                                    />
                                </div>
                                <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.email }}
                                </p>
                            </div>

                            <div>
                                <label for="password" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Senha
                                </label>
                                <div class="relative">
                                    <Lock
                                        class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
                                        aria-hidden="true"
                                    />
                                    <input
                                        id="password"
                                        v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'"
                                        autocomplete="current-password"
                                        required
                                        class="wl-input block w-full rounded-xl border border-zinc-200 bg-zinc-50 py-3 pl-11 pr-12 text-sm text-zinc-900 placeholder-zinc-400 transition dark:border-zinc-600 dark:bg-zinc-900/60 dark:text-white dark:placeholder-zinc-500"
                                        placeholder="••••••••"
                                    />
                                    <button
                                        type="button"
                                        class="wl-focus-ring absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-200/80 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                                        :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'"
                                        @click="showPassword = !showPassword"
                                    >
                                        <Eye v-if="showPassword" class="h-4 w-4" />
                                        <EyeOff v-else class="h-4 w-4" />
                                    </button>
                                </div>
                                <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.password }}
                                </p>
                            </div>

                            <div class="flex items-center justify-between gap-3 pt-1">
                                <label
                                    for="remember"
                                    class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-zinc-600 dark:text-zinc-300"
                                >
                                    <span
                                        class="flex h-5 w-5 items-center justify-center rounded-md border border-zinc-300 bg-zinc-50 transition dark:border-zinc-600 dark:bg-zinc-900/60"
                                        :class="form.remember ? 'wl-remember-active border-transparent' : ''"
                                    >
                                        <svg
                                            v-show="form.remember"
                                            class="h-3 w-3 text-zinc-900"
                                            viewBox="0 0 12 12"
                                            fill="none"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M2 6l3 3 5-5"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            />
                                        </svg>
                                    </span>
                                    <input
                                        id="remember"
                                        v-model="form.remember"
                                        type="checkbox"
                                        class="sr-only"
                                    />
                                    Lembrar de mim
                                </label>
                                <Link
                                    href="/esqueci-senha"
                                    class="wl-link shrink-0 text-sm font-medium transition hover:underline"
                                >
                                    Esqueci a senha
                                </Link>
                            </div>

                            <Button
                                type="submit"
                                class="wl-submit group mt-2 !h-12 w-full !rounded-xl !text-base !font-semibold hover:!opacity-90"
                                :disabled="form.processing"
                            >
                                <span>{{ form.processing ? 'Entrando…' : 'Entrar na plataforma' }}</span>
                                <ArrowRight
                                    class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                                    :class="form.processing ? 'opacity-0' : ''"
                                    aria-hidden="true"
                                />
                            </Button>
                        </form>
                    </div>

                    <p class="login-fade-delay-3 mt-6 pb-2 text-center text-xs text-zinc-400 dark:text-zinc-500">
                        © {{ new Date().getFullYear() }} {{ appName }}. Todos os direitos reservados.
                    </p>
                </div>
            </div>
        </div>

        <!-- Direita: hero -->
        <div
            class="relative hidden min-h-0 overflow-hidden bg-zinc-200 dark:bg-zinc-900 lg:flex lg:flex-1"
            aria-hidden="true"
        >
            <div
                class="absolute inset-0 opacity-90"
                :style="{
                    background: `linear-gradient(135deg, color-mix(in srgb, ${primary} 18%, transparent) 0%, transparent 45%, rgba(24, 24, 27, 0.15) 100%)`,
                }"
            />
            <img :src="heroImage" alt="" class="h-full w-full object-cover" @error="retryImageOnError" />
            <div class="absolute inset-0 bg-gradient-to-t from-zinc-900/50 via-zinc-900/10 to-transparent" />
            <div class="absolute bottom-10 left-10 right-10 max-w-md">
                <p class="text-sm font-medium uppercase tracking-[0.2em] text-white/70">
                    {{ appName }}
                </p>
                <p class="mt-2 text-2xl font-semibold leading-snug text-white">
                    Sua plataforma para vender mais.
                </p>
                <p class="mt-2 text-base leading-relaxed text-white/80">
                    Feita para quem escala de verdade.
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
:global(html:has(.wl-root)),
:global(body:has(.wl-root)) {
    overflow: hidden;
    height: 100%;
}

:global(body:has(.wl-root)) {
    background-color: #fafafa;
}

:global(.dark body:has(.wl-root)) {
    background-color: #18181b;
}

.wl-root {
    --wl-primary: v-bind(primary);
}

.login-fade {
    animation: login-fade-up 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
    backface-visibility: hidden;
}

.login-fade-delay-1 {
    animation-delay: 0.06s;
}

.login-fade-delay-2 {
    animation-delay: 0.12s;
}

.login-fade-delay-3 {
    animation-delay: 0.2s;
}

@keyframes login-fade-up {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.wl-input:hover {
    border-color: color-mix(in srgb, var(--wl-primary) 40%, #e4e4e7);
}

.wl-input:focus {
    border-color: var(--wl-primary);
    outline: none;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--wl-primary) 22%, transparent);
}

.wl-focus-ring:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--wl-primary) 35%, transparent);
}

.wl-remember-active {
    background-color: var(--wl-primary) !important;
}

.wl-submit {
    background-color: var(--wl-primary) !important;
    color: #18181b !important;
}

.wl-link {
    color: var(--wl-primary);
}

.wl-link:focus-visible {
    outline: 2px solid color-mix(in srgb, var(--wl-primary) 40%, transparent);
    outline-offset: 2px;
    border-radius: 4px;
}
</style>
