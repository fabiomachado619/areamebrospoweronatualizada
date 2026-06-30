<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import Button from '@/components/ui/Button.vue';
import MemberAreaVideoPlayer from '@/components/MemberAreaVideoPlayer.vue';
import MemberPdfPresentationViewer from '@/components/MemberPdfPresentationViewer.vue';
import MemberPdfReader from '@/components/MemberPdfReader.vue';
import MemberAreaToast from '@/components/MemberAreaToast.vue';
import { formatLessonDescription } from '@/lib/utils';
import { useMemberLessonNavigation } from '@/composables/useMemberLessonNavigation';
import { Link as LinkIcon, CheckCircle, ChevronLeft, ChevronRight } from 'lucide-vue-next';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    slug: { type: String, required: true },
    module: { type: Object, required: true },
    lessons: { type: Array, default: () => [] },
    current_lesson: { type: Object, default: null },
    progress_percent: { type: Number, default: 0 },
    sections: { type: Array, default: () => [] },
    comments_enabled: { type: Boolean, default: false },
    comments_require_approval: { type: Boolean, default: true },
    lesson_comments: { type: Array, default: () => [] },
    base_url: { type: String, default: '' },
    course_lesson_progress: {
        type: Object,
        default: () => ({ completed: 0, total: 0 }),
    },
});

const page = usePage();
const memberAreaHomeUrl = computed(() => page.props.home_url ?? `/m/${props.slug}`);

function normalizePdfFiles(lesson, defaultName = 'Material') {
    const list = Array.isArray(lesson?.content_files) ? lesson.content_files : [];
    const normalized = list
        .map((it) => {
            if (typeof it === 'string') return { url: it, name: defaultName };
            const url = (it?.url ?? '').toString().trim();
            if (!url) return null;
            return { url, name: (it?.name ?? defaultName).toString().trim() || defaultName };
        })
        .filter(Boolean);
    if (normalized.length === 0 && lesson?.content_url) {
        normalized.push({ url: lesson.content_url, name: defaultName });
    }
    return normalized;
}

/** URLs na mesma origem (proxy Laravel) para o pdf.js evitar CORS no R2. */
function pdfPresentationViewerFiles(slug, lesson, defaultName = 'Apresentação') {
    const norm = normalizePdfFiles(lesson, defaultName);
    return norm.map((f, i) => ({
        ...f,
        url: `/m/${slug}/aula/${lesson.id}/pdf/${i}`,
    }));
}

const currentPdfFiles = computed(() => normalizePdfFiles(props.current_lesson));
const currentPresentationFiles = computed(() =>
    props.current_lesson?.type === 'pdf_presentation'
        ? pdfPresentationViewerFiles(props.slug, props.current_lesson)
        : []
);

const memberAreaBaseUrl = computed(() => {
    const u = (props.base_url || '').trim();
    if (u) return u.replace(/\/$/, '');
    return `/m/${props.slug}`;
});

/** Proxy same-origin URLs para o leitor PDF (usa base_url do backend). */
function pdfReaderViewerFiles(lesson, defaultName = 'Documento') {
    const norm = normalizePdfFiles(lesson, defaultName);
    const p = memberAreaBaseUrl.value;
    return norm.map((f, i) => ({
        ...f,
        url: `${p}/aula/${lesson.id}/pdf/${i}`,
    }));
}

const currentPdfReaderFiles = computed(() =>
    props.current_lesson?.type === 'pdf_reader'
        ? pdfReaderViewerFiles(props.current_lesson)
        : []
);

const lessonSidebarQuery = ref('');
const filteredLessons = computed(() => {
    const q = lessonSidebarQuery.value.trim().toLowerCase();
    const list = props.lessons || [];
    if (!q) return list;
    return list.filter((l) => (l.title || '').toLowerCase().includes(q));
});

const courseProgress = computed(() => props.course_lesson_progress || { completed: 0, total: 0 });

const completedLessonIds = ref(new Set());
const completed = ref(props.current_lesson?.is_completed ?? false);
const lessonToastRef = ref(null);
let autoCompleteTimer = null;

const getLessonContext = () => ({
    slug: props.slug,
    lessonId: props.current_lesson?.id,
    baseUrl: memberAreaBaseUrl.value,
});

const { navigating, goToNextLesson, handleCompletionNavigation, clearRedirectTimer } =
    useMemberLessonNavigation(getLessonContext, lessonToastRef);

const isLessonCompleted = (lesson) => lesson.is_completed || completedLessonIds.value.has(lesson.id);

function lessonUrl(lessonId) {
    return `/m/${props.slug}/modulo/${props.module.id}?aula=${lessonId}`;
}

function markCompleteAuto() {
    if (!props.current_lesson || completed.value) return;
    router.post(`/m/${props.slug}/aula/${props.current_lesson.id}/complete`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            completed.value = true;
            completedLessonIds.value.add(props.current_lesson.id);
        },
    });
}

function markCompleteManual() {
    if (!props.current_lesson || completed.value) return;
    router.post(`/m/${props.slug}/aula/${props.current_lesson.id}/complete`, {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            completed.value = true;
            completedLessonIds.value.add(props.current_lesson.id);
            handleCompletionNavigation(page.props.flash?.lesson_completion_navigation);
        },
    });
}

/** Vídeo: marcar concluído automaticamente após 80% do tempo assistido. */
function scheduleAutoComplete() {
    if (!props.current_lesson || completed.value) return;
    if (props.current_lesson.type !== 'video' || !props.current_lesson.content_url) return;
    const durationSeconds = Math.max(30, Math.floor((props.current_lesson.duration_seconds || 60) * 0.8));
    autoCompleteTimer = setTimeout(() => markCompleteAuto(), durationSeconds * 1000);
}

/** Aulas que não são vídeo (link, pdf, texto, etc.): marcar concluído ao exibir. */
function shouldAutoCompleteNonVideo() {
    if (!props.current_lesson || completed.value) return false;
    const t = props.current_lesson.type;
    if (t === 'pdf_presentation' || t === 'pdf_reader') return false;
    return t === 'link' || t === 'pdf' || t === 'text' || (t !== 'video' && (props.current_lesson.content_url || props.current_lesson.content_text));
}

onMounted(() => {
    if (props.current_lesson?.is_completed) completed.value = true;
    else if (props.current_lesson?.type === 'video') scheduleAutoComplete();
    else if (shouldAutoCompleteNonVideo()) setTimeout(() => markCompleteAuto(), 500);
});

onUnmounted(() => {
    if (autoCompleteTimer) clearTimeout(autoCompleteTimer);
    clearRedirectTimer();
});

const commentContent = ref('');
const commentSubmitting = ref(false);
function submitComment() {
    if (!props.current_lesson || !props.comments_enabled || !commentContent.value?.trim()) return;
    commentSubmitting.value = true;
    router.post(`/m/${props.slug}/aula/${props.current_lesson.id}/comments`, { content: commentContent.value.trim() }, {
        preserveScroll: true,
        onFinish: () => { commentSubmitting.value = false; commentContent.value = ''; },
    });
}
function formatCommentDate(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch (_) { return iso; }
}

// Carrossel "Outros módulos": sem scrollbar, setas só quando há overflow
const carouselRefs = ref({});
const carouselHasOverflow = ref({});

function checkCarouselOverflow(sectionId) {
    const el = carouselRefs.value[sectionId];
    if (!el || typeof el.scrollWidth !== 'number') return;
    const hasOverflow = el.scrollWidth > el.clientWidth;
    if (carouselHasOverflow.value[sectionId] === hasOverflow) return;
    carouselHasOverflow.value = { ...carouselHasOverflow.value, [sectionId]: hasOverflow };
}

function setCarouselRef(sectionId, el) {
    if (el) {
        carouselRefs.value[sectionId] = el;
        setTimeout(() => checkCarouselOverflow(sectionId), 0);
    } else {
        carouselRefs.value[sectionId] = null;
        if (carouselHasOverflow.value[sectionId] !== false) {
            carouselHasOverflow.value = { ...carouselHasOverflow.value, [sectionId]: false };
        }
    }
}

function scrollCarousel(sectionId, direction) {
    const el = carouselRefs.value[sectionId];
    if (!el) return;
    el.scrollBy({ left: 272 * direction, behavior: 'smooth' });
}

const brokenCoverIds = ref(new Set());

function moduleCardCover(mod) {
    if (brokenCoverIds.value.has(mod.id)) {
        return null;
    }
    return mod.thumbnail_url || mod.thumbnail || null;
}

function onModuleCoverError(modId) {
    brokenCoverIds.value = new Set([...brokenCoverIds.value, modId]);
}

function moduleCoverPlaceholderClass() {
    return 'absolute inset-0 flex items-center justify-center bg-gradient-to-br from-zinc-700 via-zinc-800 to-zinc-900';
}

function onPdfReaderLastPage() {
    markCompleteAuto();
}
</script>

<template>
    <div class="space-y-8">
        <div class="flex flex-col gap-6 lg:flex-row lg:gap-8">
            <!-- Conteúdo da aula (esquerda) -->
            <main class="min-w-0 flex-1 space-y-6">
            <template v-if="current_lesson">
                <h1 class="text-2xl font-bold">{{ current_lesson.title }}</h1>

                <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">
                    <template v-if="current_lesson.type === 'video'">
                        <MemberAreaVideoPlayer
                            v-if="current_lesson.content_url"
                            :src="current_lesson.content_url"
                            :watermark-enabled="!!current_lesson.watermark_enabled"
                            :watermark-data="current_lesson.student ?? null"
                            @ended="markCompleteAuto"
                        />
                        <div
                            v-if="current_lesson.content_text"
                            class="prose prose-invert max-w-none border-t border-zinc-700 p-6"
                            v-html="formatLessonDescription(current_lesson.content_text)"
                        />
                        <div v-if="!current_lesson.content_url && !current_lesson.content_text" class="p-8 text-center text-zinc-500">
                            Conteúdo não disponível.
                        </div>
                    </template>
                    <template v-else-if="current_lesson.type === 'link' && current_lesson.content_url">
                        <div class="p-6">
                            <a :href="current_lesson.content_url" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-[var(--ma-primary)] hover:underline">
                                {{ current_lesson.link_title?.trim() || 'Abrir link externo' }}
                                <LinkIcon class="h-4 w-4" />
                            </a>
                        </div>
                    </template>
                    <div v-else-if="current_lesson.type === 'link' && current_lesson.content_text" class="prose prose-invert max-w-none border-t border-zinc-700 p-6" v-html="formatLessonDescription(current_lesson.content_text)" />
                    <template v-else-if="current_lesson.type === 'pdf_presentation' && currentPresentationFiles.length">
                        <div class="p-4">
                            <MemberPdfPresentationViewer :files="currentPresentationFiles" />
                        </div>
                        <div
                            v-if="current_lesson.content_text"
                            class="prose prose-invert max-w-none border-t border-zinc-700 p-6"
                            v-html="formatLessonDescription(current_lesson.content_text)"
                        />
                    </template>
                    <template v-else-if="current_lesson.type === 'pdf_reader' && currentPdfReaderFiles.length">
                        <div class="p-4">
                            <MemberPdfReader
                                :key="current_lesson.id"
                                :files="currentPdfReaderFiles"
                                :base-url="memberAreaBaseUrl"
                                :lesson-id="current_lesson.id"
                                :likes-count="current_lesson.likes_count ?? 0"
                                :user-liked="!!current_lesson.user_liked"
                                @last-page-reached="onPdfReaderLastPage"
                            />
                        </div>
                        <div
                            v-if="current_lesson.content_text"
                            class="prose prose-invert max-w-none border-t border-zinc-700 p-6"
                            v-html="formatLessonDescription(current_lesson.content_text)"
                        />
                    </template>
                    <template v-else-if="current_lesson.type === 'pdf' && currentPdfFiles.length">
                        <div class="p-6">
                            <div class="space-y-2">
                                <a
                                    v-for="(f, i) in currentPdfFiles"
                                    :key="`${f.url}-${i}`"
                                    :href="f.url"
                                    download
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[var(--ma-primary)] px-4 py-2.5 font-medium text-white transition hover:opacity-90"
                                >
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                    {{ f.name || 'Baixar material' }}
                                </a>
                            </div>
                        </div>
                    </template>
                    <div v-else-if="current_lesson.type === 'pdf' && current_lesson.content_text" class="prose prose-invert max-w-none border-t border-zinc-700 p-6" v-html="formatLessonDescription(current_lesson.content_text)" />
                    <template v-else-if="current_lesson.type === 'text' && current_lesson.content_text">
                        <div class="prose prose-invert max-w-none p-6" v-html="current_lesson.content_text" />
                    </template>
                    <template v-else>
                        <div class="p-8 text-center text-zinc-500">Conteúdo não disponível.</div>
                    </template>
                </div>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
                    <Link :href="memberAreaHomeUrl" class="text-sm text-zinc-400 hover:text-[var(--ma-primary)] sm:mr-auto">← Voltar ao início</Link>
                    <Button
                        variant="outline"
                        class="border-zinc-600 bg-transparent text-zinc-200 hover:bg-zinc-700/50"
                        :disabled="navigating"
                        @click="goToNextLesson"
                    >
                        Próxima aula
                    </Button>
                    <Button
                        class="bg-[var(--ma-primary)] text-white hover:opacity-90"
                        @click="markCompleteManual"
                        :disabled="completed"
                    >
                        Concluído
                    </Button>
                </div>

                <!-- Comentários da aula -->
                <section v-if="comments_enabled" class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4 space-y-4">
                    <h2 class="text-lg font-semibold">Comentários</h2>
                    <ul class="space-y-3">
                        <li v-for="c in lesson_comments" :key="c.id" class="flex gap-3 border-b border-zinc-700/50 pb-3 last:border-0 last:pb-0">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--ma-primary)]/20 text-sm font-semibold text-[var(--ma-primary)]">
                                <img v-if="c.user?.avatar_url" :src="c.user.avatar_url" :alt="c.user.name" class="h-full w-full object-cover" />
                                <span v-else>{{ (c.user?.name ?? 'A').split(/\s+/).map(n => n[0]).slice(0, 2).join('').toUpperCase() || 'A' }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-zinc-300">{{ c.user?.name ?? 'Aluno' }}</p>
                                <p class="text-sm text-zinc-400 mt-0.5">{{ c.content }}</p>
                                <p class="text-xs text-zinc-500 mt-1">{{ formatCommentDate(c.created_at) }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-if="!lesson_comments?.length" class="text-sm text-zinc-500">Nenhum comentário ainda.</p>
                    <form @submit.prevent="submitComment" class="space-y-2">
                        <textarea
                            v-model="commentContent"
                            rows="3"
                            class="w-full rounded-lg border border-zinc-600 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-[var(--ma-primary)] focus:ring-1 focus:ring-[var(--ma-primary)]"
                            placeholder="Escreva um comentário..."
                            maxlength="2000"
                        />
                        <Button type="submit" :disabled="commentSubmitting || !commentContent?.trim()">
                            {{ commentSubmitting ? 'Enviando…' : 'Enviar comentário' }}
                        </Button>
                    </form>
                    <p v-if="comments_require_approval" class="text-xs text-zinc-500">Seus comentários serão publicados após aprovação do instrutor.</p>
                </section>
            </template>
            <template v-else>
                <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-12 text-center">
                    <p class="text-zinc-500">Selecione uma aula na lista à direita.</p>
                    <Link :href="memberAreaHomeUrl" class="mt-4 inline-block text-sm text-[var(--ma-primary)] hover:underline">← Voltar ao início</Link>
                </div>
            </template>
            </main>

            <!-- Sidebar à direita: lista de aulas do módulo -->
            <aside class="w-full shrink-0 rounded-xl border border-zinc-700 bg-zinc-800/50 lg:w-72">
                <div class="border-b border-zinc-700 p-4">
                    <Link :href="memberAreaHomeUrl" class="text-sm text-zinc-400 hover:text-[var(--ma-primary)]">← Início</Link>
                    <h2 class="mt-2 text-lg font-semibold">{{ module.title }}</h2>
                    <p v-if="module.section" class="text-xs text-zinc-500">{{ module.section.title }}</p>
                    <div v-if="courseProgress.total > 0" class="mt-3 space-y-1">
                        <div class="flex justify-between text-xs text-zinc-400">
                            <span>Progresso do curso</span>
                            <span class="tabular-nums">{{ courseProgress.completed }} / {{ courseProgress.total }} aulas</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-zinc-700">
                            <div
                                class="h-full rounded-full bg-[var(--ma-primary)] transition-[width]"
                                :style="{ width: `${Math.min(100, Math.round((courseProgress.completed / courseProgress.total) * 100))}%` }"
                            />
                        </div>
                    </div>
                    <input
                        v-model="lessonSidebarQuery"
                        type="search"
                        placeholder="Buscar aula…"
                        class="mt-3 w-full rounded-lg border border-zinc-600 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-[var(--ma-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--ma-primary)]"
                        autocomplete="off"
                    />
                </div>
                <nav class="max-h-[60vh] overflow-y-auto p-2">
                    <template v-if="filteredLessons.length">
                        <template v-for="(lesson, idx) in filteredLessons" :key="lesson.id">
                            <Link
                                v-if="!lesson.is_locked"
                                :href="lessonUrl(lesson.id)"
                                class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition"
                                :class="current_lesson?.id === lesson.id
                                    ? 'bg-[var(--ma-primary)]/20 text-[var(--ma-primary)]'
                                    : 'text-zinc-300 hover:bg-zinc-700/50 hover:text-white'"
                            >
                                <CheckCircle v-if="isLessonCompleted(lesson)" class="h-4 w-4 shrink-0 text-emerald-500" />
                                <span v-else class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-zinc-500 text-xs">{{ idx + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ lesson.title || 'Sem título' }}</span>
                            </Link>
                            <div v-else class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm opacity-70">
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-zinc-600 text-xs">{{ idx + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate text-zinc-400">{{ lesson.title || 'Sem título' }}</span>
                                <span v-if="lesson.lock_message" class="shrink-0 text-[10px] text-zinc-500">{{ lesson.lock_message }}</span>
                            </div>
                        </template>
                    </template>
                    <p v-else-if="lessons.length" class="px-3 py-4 text-sm text-zinc-500">Nenhuma aula encontrada.</p>
                    <p v-else class="px-3 py-4 text-sm text-zinc-500">Nenhuma aula neste módulo.</p>
                </nav>
            </aside>
        </div>

        <!-- Outros módulos (parte de baixo) -->
        <section v-if="sections?.length" class="border-t border-zinc-700/50 pt-8">
            <h2 class="mb-4 text-xl font-semibold">Outros módulos</h2>
            <div class="space-y-6">
                <div v-for="section in sections" :key="section.id" class="space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-medium text-zinc-400">{{ section.title }}</h3>
                        <div v-if="carouselHasOverflow[section.id]" class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-700 hover:text-white"
                                aria-label="Rolar para a esquerda"
                                @click="scrollCarousel(section.id, -1)"
                            >
                                <ChevronLeft class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-700 hover:text-white"
                                aria-label="Rolar para a direita"
                                @click="scrollCarousel(section.id, 1)"
                            >
                                <ChevronRight class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                    <div
                        :ref="(el) => setCarouselRef(section.id, el)"
                        class="no-scrollbar flex gap-4 overflow-x-auto"
                    >
                        <template v-for="mod in section.modules" :key="mod.id">
                            <Link
                                v-if="!mod.is_locked"
                                :href="`/m/${slug}/modulo/${mod.id}`"
                                class="flex w-64 shrink-0 flex-col rounded-xl overflow-hidden bg-zinc-800/50 text-left transition hover:bg-zinc-800"
                                :class="{ 'ring-2 ring-[var(--ma-primary)]/50': mod.id === module.id }"
                            >
                                <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-700 flex items-center justify-center overflow-hidden']">
                                    <img
                                        v-if="moduleCardCover(mod)"
                                        :src="moduleCardCover(mod)"
                                        :alt="mod.title"
                                        class="absolute inset-0 h-full w-full object-cover"
                                        @error="onModuleCoverError(mod.id)"
                                    />
                                    <div v-else :class="moduleCoverPlaceholderClass()">
                                        <svg class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                    </div>
                                    <div v-if="mod.show_title_on_cover !== false" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent px-3 pb-3 pt-8">
                                        <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                    </div>
                                </div>
                            </Link>
                            <div
                                v-else
                                class="flex w-64 shrink-0 cursor-not-allowed flex-col rounded-xl overflow-hidden bg-zinc-800/30 text-left opacity-70"
                            >
                                <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-700 flex items-center justify-center overflow-hidden']">
                                    <img
                                        v-if="moduleCardCover(mod)"
                                        :src="moduleCardCover(mod)"
                                        :alt="mod.title"
                                        class="absolute inset-0 h-full w-full object-cover"
                                        @error="onModuleCoverError(mod.id)"
                                    />
                                    <div v-else :class="moduleCoverPlaceholderClass()">
                                        <svg class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                    </div>
                                    <div class="absolute inset-0 bg-black/50" />
                                    <div class="absolute inset-x-0 bottom-0 px-3 pb-3 pt-8">
                                        <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                        <p v-if="mod.lock_message" class="mt-1 text-xs text-white/80">{{ mod.lock_message }}</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>
        <MemberAreaToast ref="lessonToastRef" />
    </div>
</template>
