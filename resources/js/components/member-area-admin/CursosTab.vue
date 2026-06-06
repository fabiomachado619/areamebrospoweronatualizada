<script setup>
import Button from '@/components/ui/Button.vue';
import { ExternalLink, Layers } from 'lucide-vue-next';

defineProps({
    courses: { type: Array, default: () => [] },
});

function openMemberBuilder(course) {
    window.location.href = course.member_builder_url;
}

function publicationClass(isActive) {
    return isActive
        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
        : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Cursos criados em Produtos aparecem aqui para organização na área de membros.
            </p>
            <a href="/produtos/create" class="text-sm font-medium text-[var(--color-primary)] hover:underline">
                Criar curso em Produtos
            </a>
        </div>

        <div v-if="!courses.length" class="panel-card px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
            Nenhum curso de área de membros encontrado. Crie um produto do tipo área de membros em Produtos.
        </div>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <article
                v-for="course in courses"
                :key="course.id"
                class="panel-card-md flex flex-col gap-4"
            >
                <div class="flex gap-4">
                    <div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                        <img v-if="course.image_url" :src="course.image_url" :alt="course.name" class="h-full w-full object-cover" />
                        <div v-else class="flex h-full w-full items-center justify-center text-zinc-400">
                            <Layers class="h-6 w-6" />
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold text-zinc-900 dark:text-white">{{ course.name }}</h3>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">/{{ course.checkout_slug }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="publicationClass(course.is_active)">
                                {{ course.publication_label }}
                            </span>
                            <span
                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="course.in_vitrine ? 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-200' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                            >
                                {{ course.in_vitrine ? 'Aparece na vitrine' : 'Fora da vitrine' }}
                            </span>
                        </div>
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">Alunos com acesso</dt>
                        <dd class="font-semibold text-zinc-900 dark:text-white">{{ course.students_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">Aparece na vitrine</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ course.in_vitrine_label }}</dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2">
                    <Button variant="primary" size="sm" @click="openMemberBuilder(course)">
                        <ExternalLink class="h-4 w-4" />
                        Organizar aulas
                    </Button>
                    <a :href="course.edit_url" class="inline-flex items-center rounded-lg px-3 py-2 text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                        Editar em Produtos
                    </a>
                </div>
            </article>
        </div>
    </div>
</template>
