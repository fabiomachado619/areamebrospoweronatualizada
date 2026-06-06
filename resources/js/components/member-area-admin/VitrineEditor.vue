<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import draggable from 'vuedraggable';
import Button from '@/components/ui/Button.vue';
import { GripVertical, Plus, Trash2, Pencil, ImagePlus, Save, X } from 'lucide-vue-next';

const props = defineProps({
    hubId: { type: String, default: null },
    sections: { type: Array, default: () => [] },
    linkedCourses: { type: Array, default: () => [] },
});

const emit = defineEmits(['refresh']);

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const jsonHeaders = () => ({
    'X-CSRF-TOKEN': csrfToken(),
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
});
const uploadHeaders = () => ({
    'X-CSRF-TOKEN': csrfToken(),
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
});

const base = computed(() => (props.hubId ? `/produtos/${props.hubId}/member-builder` : null));

const localSections = ref(cloneSections(props.sections));
const reorderSaving = ref(false);
const toast = ref(null);
const newSectionTitle = ref('');
const addingSection = ref(false);
const addCourseSectionId = ref(null);
const addCourseId = ref('');
const addCourseTitle = ref('');
const addingCourse = ref(false);
const editingSectionId = ref(null);
const editingSectionTitle = ref('');
const editingModule = ref(null);
const editForm = ref(emptyEditForm());
const savingModule = ref(false);
const coverUploading = ref(false);
const coverInputRef = ref(null);
const brokenCoverIds = ref(new Set());

watch(
    () => props.sections,
    (sections) => {
        localSections.value = cloneSections(sections);
    },
    { deep: true }
);

function emptyEditForm() {
    return {
        id: null,
        title: '',
        subtitle: '',
        external_url: '',
        thumbnail: '',
        thumbnail_url: '',
    };
}

function cloneSections(sections) {
    return JSON.parse(JSON.stringify(sections ?? []));
}

function showToast(message, type = 'success') {
    toast.value = { message, type };
    setTimeout(() => { toast.value = null; }, 4000);
}

function buildSectionsReorderPayload() {
    return localSections.value.map((section, index) => ({
        id: section.id,
        position: index + 1,
    }));
}

function buildModulesReorderPayload() {
    const modules = [];
    for (const section of localSections.value) {
        (section.modules ?? []).forEach((mod, index) => {
            modules.push({
                id: mod.id,
                section_id: section.id,
                position: index + 1,
            });
        });
    }
    return modules;
}

let dragSnapshot = null;

function captureDragSnapshot() {
    dragSnapshot = cloneSections(localSections.value);
}

async function persistSectionsReorder() {
    if (!base.value || reorderSaving.value) return;
    reorderSaving.value = true;
    const previous = dragSnapshot;
    dragSnapshot = null;
    try {
        await axios.post(`${base.value}/sections/reorder`, { sections: buildSectionsReorderPayload() }, { headers: jsonHeaders() });
        showToast('Ordem das seções salva.');
        emit('refresh');
    } catch (e) {
        if (previous) localSections.value = previous;
        showToast(e.response?.data?.message ?? 'Erro ao salvar ordem das seções.', 'error');
    } finally {
        reorderSaving.value = false;
    }
}

async function persistModulesReorder() {
    if (!base.value || reorderSaving.value) return;
    reorderSaving.value = true;
    const previous = dragSnapshot;
    dragSnapshot = null;
    try {
        await axios.post(`${base.value}/modules/reorder`, { modules: buildModulesReorderPayload() }, { headers: jsonHeaders() });
        showToast('Ordem dos cursos salva.');
        emit('refresh');
    } catch (e) {
        if (previous) localSections.value = previous;
        showToast(e.response?.data?.message ?? 'Erro ao salvar ordem dos cursos.', 'error');
    } finally {
        reorderSaving.value = false;
    }
}

async function addSection() {
    const title = newSectionTitle.value?.trim();
    if (!title || !base.value) return;
    addingSection.value = true;
    try {
        await axios.post(`${base.value}/sections`, {
            title,
            cover_mode: 'vertical',
            section_type: 'products',
        }, { headers: jsonHeaders() });
        newSectionTitle.value = '';
        showToast('Seção adicionada.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao adicionar seção.', 'error');
    } finally {
        addingSection.value = false;
    }
}

async function saveSectionTitle(section) {
    if (!base.value || !editingSectionTitle.value?.trim()) return;
    try {
        await axios.put(`${base.value}/sections/${section.id}`, {
            title: editingSectionTitle.value.trim(),
            cover_mode: section.cover_mode ?? 'vertical',
        }, { headers: jsonHeaders() });
        editingSectionId.value = null;
        showToast('Seção atualizada.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao atualizar seção.', 'error');
    }
}

async function updateSectionLayout(section, coverMode) {
    if (!base.value) return;
    try {
        await axios.put(`${base.value}/sections/${section.id}`, {
            title: section.title,
            cover_mode: coverMode,
        }, { headers: jsonHeaders() });
        section.cover_mode = coverMode;
        showToast('Layout atualizado.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao atualizar layout.', 'error');
    }
}

async function deleteSection(sectionId) {
    if (!base.value || !window.confirm('Remover esta seção e todos os cursos nela?')) return;
    try {
        await axios.delete(`${base.value}/sections/${sectionId}`, { headers: jsonHeaders() });
        showToast('Seção removida.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao remover seção.', 'error');
    }
}

function openAddCourse(sectionId) {
    addCourseSectionId.value = sectionId;
    addCourseId.value = '';
    addCourseTitle.value = '';
}

async function confirmAddCourse() {
    if (!base.value || !addCourseSectionId.value || !addCourseId.value) return;
    const course = props.linkedCourses.find((c) => c.id === addCourseId.value);
    const title = addCourseTitle.value?.trim() || course?.name || 'Curso';
    addingCourse.value = true;
    try {
        await axios.post(`${base.value}/sections/${addCourseSectionId.value}/modules`, {
            title,
            related_product_id: addCourseId.value,
            access_type: 'paid',
            single_card: true,
        }, { headers: jsonHeaders() });
        addCourseSectionId.value = null;
        showToast('Curso adicionado à vitrine.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao adicionar curso.', 'error');
    } finally {
        addingCourse.value = false;
    }
}

async function deleteModule(moduleId) {
    if (!base.value || !window.confirm('Remover este curso da vitrine?')) return;
    try {
        await axios.delete(`${base.value}/modules/${moduleId}`, { headers: jsonHeaders() });
        if (editingModule.value?.id === moduleId) {
            closeEditModule();
        }
        showToast('Curso removido da vitrine.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao remover curso.', 'error');
    }
}

function startEditSection(section) {
    editingSectionId.value = section.id;
    editingSectionTitle.value = section.title;
}

function cardCover(mod) {
    if (brokenCoverIds.value.has(mod.id)) {
        return null;
    }
    return mod.thumbnail_url || mod.related_product_image_url || null;
}

function onCardCoverError(modId) {
    brokenCoverIds.value = new Set([...brokenCoverIds.value, modId]);
}

function catalogPlaceholderClass() {
    return 'flex h-full w-full items-center justify-center bg-gradient-to-br from-zinc-200 via-zinc-100 to-zinc-300 dark:from-zinc-800 dark:via-zinc-700 dark:to-zinc-900';
}

function cardLabel(mod) {
    return mod.title || mod.related_product_name || 'Curso';
}

function openEditModule(mod) {
    editingModule.value = mod;
    editForm.value = {
        id: mod.id,
        title: mod.title ?? '',
        subtitle: mod.subtitle ?? '',
        external_url: mod.external_url ?? '',
        thumbnail: mod.thumbnail ?? '',
        thumbnail_url: cardCover(mod),
    };
}

function closeEditModule() {
    editingModule.value = null;
    editForm.value = emptyEditForm();
}

async function saveEditModule() {
    if (!base.value || !editForm.value.id) return;
    savingModule.value = true;
    try {
        await axios.put(`${base.value}/modules/${editForm.value.id}`, {
            title: editForm.value.title.trim() || cardLabel(editingModule.value),
            subtitle: editForm.value.subtitle.trim() || null,
            external_url: editForm.value.external_url.trim() || null,
            thumbnail: editForm.value.thumbnail || null,
        }, { headers: jsonHeaders() });
        showToast('Card atualizado.');
        closeEditModule();
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao salvar card.', 'error');
    } finally {
        savingModule.value = false;
    }
}

async function onCoverSelected(event) {
    const file = event.target.files?.[0];
    if (!file || !base.value || !editForm.value.id) return;
    coverUploading.value = true;
    try {
        const formData = new FormData();
        formData.append('file', file);
        const up = await axios.post(`${base.value}/upload`, formData, { headers: uploadHeaders() });
        const storedPath = up.data.path ?? up.data.url;
        const previewUrl = up.data.url ?? (storedPath?.startsWith('/') ? storedPath : `/storage/${storedPath}`);
        editForm.value.thumbnail = storedPath;
        editForm.value.thumbnail_url = previewUrl;
        await axios.put(`${base.value}/modules/${editForm.value.id}`, {
            thumbnail: storedPath,
        }, { headers: jsonHeaders() });
        showToast('Capa atualizada.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao enviar capa.', 'error');
    } finally {
        coverUploading.value = false;
        if (coverInputRef.value) {
            coverInputRef.value.value = '';
        }
    }
}

async function removeCover() {
    if (!base.value || !editForm.value.id) return;
    try {
        await axios.put(`${base.value}/modules/${editForm.value.id}`, { thumbnail: '' }, { headers: jsonHeaders() });
        editForm.value.thumbnail = '';
        editForm.value.thumbnail_url = editingModule.value?.related_product_image_url ?? '';
        showToast('Capa personalizada removida.');
        emit('refresh');
    } catch (e) {
        showToast(e.response?.data?.message ?? 'Erro ao remover capa.', 'error');
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-[200px] flex-1 space-y-1">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Nova seção da vitrine</label>
                <input
                    v-model="newSectionTitle"
                    type="text"
                    class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    placeholder="Ex.: Cursos disponíveis"
                    @keydown.enter.prevent="addSection"
                />
            </div>
            <Button variant="primary" :disabled="addingSection || !newSectionTitle.trim()" @click="addSection">
                <Plus class="h-4 w-4" />
                Adicionar seção
            </Button>
        </div>

        <div v-if="!localSections.length" class="panel-card px-6 py-10 text-center text-sm text-zinc-500">
            Nenhuma seção na vitrine. Adicione uma seção e inclua cursos disponíveis.
        </div>

        <draggable
            v-else
            v-model="localSections"
            item-key="id"
            handle=".section-drag-handle"
            class="space-y-4"
            :disabled="reorderSaving"
            @start="captureDragSnapshot"
            @end="persistSectionsReorder"
        >
            <template #item="{ element: section }">
                <article class="panel-card-md space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-1 items-start gap-2">
                            <button type="button" class="section-drag-handle mt-1 cursor-grab rounded p-1 text-zinc-400 hover:text-zinc-600" aria-label="Arrastar seção">
                                <GripVertical class="h-4 w-4" />
                            </button>
                            <div class="min-w-0 flex-1">
                                <template v-if="editingSectionId === section.id">
                                    <div class="flex flex-wrap gap-2">
                                        <input v-model="editingSectionTitle" type="text" class="min-w-0 flex-1 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" @keydown.enter="saveSectionTitle(section)" />
                                        <Button size="sm" @click="saveSectionTitle(section)">Salvar</Button>
                                        <Button size="sm" variant="ghost" @click="editingSectionId = null">Cancelar</Button>
                                    </div>
                                </template>
                                <template v-else>
                                    <h3 class="font-semibold text-zinc-900 dark:text-white">{{ section.title }}</h3>
                                </template>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                :value="section.cover_mode ?? 'vertical'"
                                class="rounded-lg border border-zinc-200 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                                @change="updateSectionLayout(section, $event.target.value)"
                            >
                                <option value="vertical">Layout vertical</option>
                                <option value="horizontal">Layout horizontal</option>
                            </select>
                            <button type="button" class="rounded p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" title="Editar título" @click="startEditSection(section)">
                                <Pencil class="h-4 w-4" />
                            </button>
                            <button type="button" class="rounded p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover seção" @click="deleteSection(section.id)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <draggable
                        v-model="section.modules"
                        item-key="id"
                        handle=".module-drag-handle"
                        class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(140px,1fr))]"
                        :disabled="reorderSaving"
                        @start="captureDragSnapshot"
                        @end="persistModulesReorder"
                    >
                        <template #item="{ element: mod }">
                            <div class="mx-auto flex w-full max-w-[180px] flex-col overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50/80 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                                <div class="relative aspect-[2/3] w-full overflow-hidden rounded-t-xl bg-zinc-200 dark:bg-zinc-700">
                                    <img
                                        v-if="cardCover(mod)"
                                        :src="cardCover(mod)"
                                        :alt="cardLabel(mod)"
                                        class="h-full w-full object-cover"
                                        @error="onCardCoverError(mod.id)"
                                    />
                                    <div v-else :class="catalogPlaceholderClass()">
                                        <ImagePlus class="h-8 w-8 text-zinc-400" />
                                    </div>
                                    <button type="button" class="module-drag-handle absolute left-2 top-2 cursor-grab rounded bg-black/40 p-1 text-white" aria-label="Arrastar card">
                                        <GripVertical class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <div class="flex flex-1 flex-col gap-2 p-3">
                                    <div>
                                        <p class="truncate font-medium text-zinc-900 dark:text-white">{{ cardLabel(mod) }}</p>
                                        <p v-if="mod.subtitle" class="mt-0.5 line-clamp-2 text-xs text-zinc-500 dark:text-zinc-400">{{ mod.subtitle }}</p>
                                        <p v-if="mod.external_url" class="mt-1 truncate text-xs text-sky-600 dark:text-sky-400">Link de venda configurado</p>
                                    </div>
                                    <div class="mt-auto flex gap-1">
                                        <Button size="sm" variant="outline" class="flex-1" @click="openEditModule(mod)">
                                            <Pencil class="h-3.5 w-3.5" />
                                            Editar
                                        </Button>
                                        <button type="button" class="rounded p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover card" @click="deleteModule(mod.id)">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </draggable>

                    <div v-if="addCourseSectionId === section.id" class="rounded-lg border border-dashed border-zinc-300 p-3 dark:border-zinc-600">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <select v-model="addCourseId" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                                <option value="">Selecione o curso</option>
                                <option v-for="c in linkedCourses" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <input v-model="addCourseTitle" type="text" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" placeholder="Título na vitrine (opcional)" />
                        </div>
                        <div class="mt-2 flex gap-2">
                            <Button size="sm" variant="primary" :disabled="addingCourse || !addCourseId" @click="confirmAddCourse">Adicionar</Button>
                            <Button size="sm" variant="ghost" @click="addCourseSectionId = null">Cancelar</Button>
                        </div>
                        <p v-if="!linkedCourses.length" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                            Crie cursos em Produtos antes de adicioná-los à vitrine.
                        </p>
                    </div>
                    <Button v-else variant="outline" size="sm" @click="openAddCourse(section.id)">
                        <Plus class="h-4 w-4" />
                        Adicionar curso à vitrine
                    </Button>
                </article>
            </template>
        </draggable>

        <div v-if="editingModule" class="fixed inset-0 z-[100001] flex items-center justify-center bg-black/50 p-4" @click.self="closeEditModule">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Editar card da vitrine</h3>
                        <p class="text-sm text-zinc-500">{{ editingModule.related_product_name }}</p>
                    </div>
                    <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="closeEditModule">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Capa do card</label>
                        <div class="relative mx-auto aspect-[2/3] w-full max-w-[220px] overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            <img
                                v-if="editForm.thumbnail_url"
                                :src="editForm.thumbnail_url"
                                alt="Capa"
                                class="h-full w-full object-cover"
                                @error="editForm.thumbnail_url = ''"
                            />
                            <div v-else :class="catalogPlaceholderClass()">
                                <ImagePlus class="h-10 w-10 text-zinc-400" />
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" :disabled="coverUploading" @click="coverInputRef?.click()">
                                <ImagePlus class="h-4 w-4" />
                                {{ coverUploading ? 'Enviando…' : 'Alterar capa' }}
                            </Button>
                            <Button v-if="editForm.thumbnail" size="sm" variant="ghost" @click="removeCover">Usar imagem do produto</Button>
                        </div>
                        <input ref="coverInputRef" type="file" accept="image/*" class="hidden" @change="onCoverSelected" />
                        <p class="text-xs text-zinc-500">
                            Use imagem vertical no formato 2:3. Tamanho recomendado: 800×1200px.
                        </p>
                        <p class="text-xs text-zinc-500">Sem capa personalizada, a imagem do produto é usada automaticamente.</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Título exibido</label>
                        <input v-model="editForm.title" type="text" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Descrição curta</label>
                        <textarea v-model="editForm.subtitle" rows="3" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" placeholder="Breve descrição exibida no card" />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Link de venda</label>
                        <input v-model="editForm.external_url" type="url" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" placeholder="https://… checkout, página de vendas ou WhatsApp" />
                        <p class="text-xs text-zinc-500">Alunos sem acesso ao curso serão direcionados para este link. Se vazio, usa o checkout do produto.</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button variant="ghost" @click="closeEditModule">Cancelar</Button>
                        <Button variant="primary" :disabled="savingModule" @click="saveEditModule">
                            <Save class="h-4 w-4" />
                            Salvar card
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="toast"
                role="alert"
                :class="[
                    'fixed bottom-4 right-4 z-[100002] max-w-sm rounded-xl border px-4 py-3 shadow-lg text-sm font-medium',
                    toast.type === 'error'
                        ? 'border-red-200 bg-red-50 text-red-800'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                ]"
            >
                {{ toast.message }}
            </div>
        </Teleport>
    </div>
</template>
