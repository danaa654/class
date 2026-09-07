<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    curriculum: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    availableSubjects: { type: Array, default: () => [] },
    allSubjects: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
        }
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
        }
    },
);

/* ------------------------------------------------------------------ */
/* Static option lists (fixed display order)                           */
/* ------------------------------------------------------------------ */

const yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
const semesters = ['First Semester', 'Second Semester', 'Summer'];

const yearLevelOptions = yearLevels.map((label) => ({ label, value: label }));
const semesterOptions = semesters.map((label) => ({ label, value: label }));

/* ------------------------------------------------------------------ */
/* Grouping / sorting                                                  */
/* ------------------------------------------------------------------ */

// "Allow sorting by Year Level, Semester" — this toggles which level of
// the grouped display is the outer grouping.
const sortBy = ref('year_level'); // 'year_level' | 'semester'

const sortByOptions = [
    { label: 'Year Level', value: 'year_level' },
    { label: 'Semester', value: 'semester' },
];

const groupedSections = computed(() => {
    const outer = sortBy.value === 'year_level' ? yearLevels : semesters;
    const inner = sortBy.value === 'year_level' ? semesters : yearLevels;
    const outerField = sortBy.value === 'year_level' ? 'year_level' : 'semester';
    const innerField = sortBy.value === 'year_level' ? 'semester' : 'year_level';

    return outer.map((outerValue) => ({
        label: outerValue,
        subsections: inner.map((innerValue) => ({
            label: innerValue,
            rows: props.items
                .filter((item) => item[outerField] === outerValue && item[innerField] === innerValue)
                .sort((a, b) => (a.subject?.subject_code ?? '').localeCompare(b.subject?.subject_code ?? '')),
        })),
    }));
});

/* ------------------------------------------------------------------ */
/* Add / Edit dialog                                                   */
/* ------------------------------------------------------------------ */

const dialogVisible = ref(false);
const editingItem = ref(null); // null => Add mode, otherwise the item being edited

// Add mode places one or more Subjects at once (checkbox multi-select),
// sharing one Year Level / Semester / Remarks, via addForm. Edit mode
// still targets a single CurriculumItem with its own Subject +
// Prerequisite fields, via editForm. Two separate useForm instances keep
// their field shapes (and Inertia's per-request transforms) independent.
const addForm = useForm({
    subject_ids: [],
    year_level: null,
    semester: null,
    remarks: '',
});

const editForm = useForm({
    subject_id: null,
    year_level: null,
    semester: null,
    prerequisite_subject_id: null,
    remarks: '',
});

// `form` is a read-only alias to whichever form is active, used by the
// shared Year Level / Semester / Remarks fields and error messages in the
// template so those markup blocks don't need to branch on mode.
const form = computed(() => (editingItem.value ? editForm : addForm));

// Subject options for Add mode (checkbox multi-select): every Subject not
// yet placed in this curriculum.
const subjectOptions = computed(() =>
    props.availableSubjects.map((subject) => ({
        label: `${subject.subject_code} — ${subject.subject_title}`,
        value: subject.id,
    })),
);

// Subject options for Edit mode: same list, but keep the item's own
// current subject available since it's excluded from availableSubjects.
const editSubjectOptions = computed(() => {
    const base = props.availableSubjects;
    const options = editingItem.value && editingItem.value.subject
        ? [editingItem.value.subject, ...base]
        : base;

    return options.map((subject) => ({
        label: `${subject.subject_code} — ${subject.subject_title}`,
        value: subject.id,
    }));
});

// Prerequisite dropdown: any master subject except the one currently
// selected as the Subject (a subject can't be its own prerequisite).
const prerequisiteOptions = computed(() =>
    props.allSubjects
        .filter((subject) => subject.id !== editForm.subject_id)
        .map((subject) => ({
            label: `${subject.subject_code} — ${subject.subject_title}`,
            value: subject.id,
        })),
);

const openAdd = () => {
    editingItem.value = null;
    addForm.clearErrors();
    addForm.reset();
    dialogVisible.value = true;
};

const openEdit = (item) => {
    editingItem.value = item;
    editForm.clearErrors();
    editForm.subject_id = item.subject_id;
    editForm.year_level = item.year_level;
    editForm.semester = item.semester;
    editForm.prerequisite_subject_id = item.prerequisite_subject_id;
    editForm.remarks = item.remarks ?? '';
    dialogVisible.value = true;
};

const closeDialog = () => {
    dialogVisible.value = false;
    editingItem.value = null;
    addForm.clearErrors();
    addForm.reset();
    editForm.clearErrors();
    editForm.reset();
};

const onSave = () => {
    if (editingItem.value) {
        editForm.put(route('curriculums.subjects.update', [props.curriculum.id, editingItem.value.id]), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
        });
    } else {
        addForm.post(route('curriculums.subjects.store', props.curriculum.id), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
        });
    }
};

const onDelete = (item) => {
    Swal.fire({
        title: 'Remove this subject?',
        text: `${item.subject?.subject_code} will be removed from this curriculum. The subject itself will not be deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, remove it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('curriculums.subjects.destroy', [props.curriculum.id, item.id]), {
                preserveScroll: true,
            });
        }
    });
};

const categorySeverity = (category) => (category === 'Major' ? 'info' : 'secondary');
</script>

<template>
    <Head title="Curriculum Subjects" />

    <AppLayout>
        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Curriculum</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Breadcrumb -->
            <div class="mb-4 flex items-center gap-2 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                <Link :href="route('curriculums')" class="hover:underline" :class="isDark ? 'hover:text-white' : 'hover:text-[#1E293B]'">Curriculum</Link>
                <i class="pi pi-angle-right text-xs"></i>
                <span class="font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
                    {{ curriculum.name }} ({{ curriculum.start_year }}–{{ curriculum.end_year }})
                </span>
                <i class="pi pi-angle-right text-xs"></i>
                <span class="font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Subjects</span>
            </div>

            <!-- Page Title -->
            <div class="neu-card neu-spotlight relative mb-8 overflow-hidden rounded-2xl">
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                    <div class="absolute inset-0" :class="isDark ? 'bg-gradient-to-br from-blue-500/10 via-transparent to-red-500/10' : 'bg-gradient-to-br from-blue-50 via-transparent to-red-50/60'"></div>
                    <div class="absolute -right-10 -top-16 h-44 w-44 rounded-full bg-gradient-to-br" :class="isDark ? 'from-blue-500/20 via-blue-500/5 to-transparent' : 'from-blue-200/80 via-blue-100/40 to-transparent'"></div>
                    <div class="absolute -bottom-20 right-16 h-40 w-40 rounded-full bg-gradient-to-tr" :class="isDark ? 'from-red-500/20 via-red-500/5 to-transparent' : 'from-red-200/70 via-red-100/30 to-transparent'"></div>
                    <div class="absolute -left-10 bottom-0 h-28 w-28 rounded-full bg-gradient-to-tr border-4" :class="isDark ? 'from-blue-500/10 to-transparent border-blue-400/10' : 'from-blue-100/60 to-transparent border-blue-100'"></div>
                    <div class="absolute left-[38%] top-0 h-3 w-3 rounded-full bg-gradient-to-br" :class="isDark ? 'from-red-400/50 to-blue-400/30' : 'from-red-400/80 to-blue-300/60'"></div>
                    <div class="absolute left-[55%] bottom-4 h-20 w-20 rounded-full bg-gradient-to-r blur-xl" :class="isDark ? 'from-blue-500/10 to-red-500/10' : 'from-blue-200/40 to-red-200/40'"></div>
                </div>
                <div class="relative flex items-start justify-between gap-4 flex-wrap rounded-2xl p-6 transition-colors duration-300">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                            {{ curriculum.name }}
                        </h1>
                        <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            {{ curriculum.major?.name || 'No Major' }} · {{ curriculum.start_year }}–{{ curriculum.end_year }} ·
                            Manage the subjects that make up this curriculum, arranged by Year Level and Semester.
                        </p>
                    </div>
                    <Button
                        label="Back to Curriculums"
                        icon="pi pi-arrow-left"
                        text
                        severity="secondary"
                        @click="router.visit(route('curriculums'))"
                    />
                </div>
            </div>

            <div class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <!-- Top Toolbar -->
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                        <template #start>
                            <div class="flex items-center gap-2 neu-form">
                                <span class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Group by</span>
                                <Select
                                    v-model="sortBy"
                                    :options="sortByOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    class="w-44"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                            </div>
                        </template>
                        <template #end>
                            <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAdd" />
                        </template>
                    </Toolbar>

                    <!-- Grouped Subject Sections -->
                    <div class="flex flex-col gap-8">
                        <div v-for="section in groupedSections" :key="section.label">
                            <h2 class="text-lg font-bold mb-3 pb-2 border-b" :class="isDark ? 'text-white border-white/10' : 'text-[#1E293B] border-slate-200'">
                                {{ section.label }}
                            </h2>

                            <div v-for="sub in section.subsections" :key="sub.label" class="mb-6 last:mb-0">
                                <h3 class="text-sm font-semibold uppercase tracking-wide mb-2 pl-1" :class="isDark ? 'text-slate-400' : 'text-slate-600'">
                                    {{ sub.label }}
                                </h3>

                                <div v-if="sub.rows.length === 0" class="text-sm italic pl-1 py-3" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                                    No subjects added yet.
                                </div>

                                <div v-else class="overflow-x-auto rounded-xl neu-inset" :class="isDark ? '' : 'border border-slate-100'" :style="isDark ? {} : { backgroundColor: '#F7F4ED' }">
                                    <table class="w-full text-sm">
                                        <thead class="text-left" :class="isDark ? 'bg-white/5 text-slate-400' : 'bg-slate-50 text-slate-500'">
                                            <tr>
                                                <th class="px-4 py-2 font-medium">Code</th>
                                                <th class="px-4 py-2 font-medium">Title</th>
                                                <th class="px-4 py-2 font-medium">Category</th>
                                                <th class="px-4 py-2 font-medium">Subject Type</th>
                                                <th class="px-4 py-2 font-medium text-center">Units</th>
                                                <th class="px-4 py-2 font-medium text-center">Lec Hrs</th>
                                                <th class="px-4 py-2 font-medium text-center">Lab Hrs</th>
                                                <th class="px-4 py-2 font-medium text-center">Required Hours</th>
                                                <th class="px-4 py-2 font-medium">Prerequisite</th>
                                                <th class="px-4 py-2 font-medium text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y" :class="isDark ? 'divide-white/10' : 'divide-slate-100'">
                                            <tr v-for="row in sub.rows" :key="row.id" :class="isDark ? 'hover:bg-white/5' : 'hover:bg-slate-50'">
                                                <td class="px-4 py-2 font-medium" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                                                    {{ row.subject?.subject_code }}
                                                </td>
                                                <td class="px-4 py-2" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ row.subject?.subject_title }}</td>
                                                <td class="px-4 py-2">
                                                    <Tag
                                                        :value="row.subject?.category"
                                                        :severity="categorySeverity(row.subject?.category)"
                                                    />
                                                </td>
                                                <td class="px-4 py-2">
                                                    <span
                                                        v-if="row.subject?.subject_type === 'practicum'"
                                                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800"
                                                    >
                                                        <i class="pi pi-map-marker text-[10px]"></i>
                                                        Practicum / OJT
                                                    </span>
                                                    <span v-else class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Regular</span>
                                                </td>
                                                <td class="px-4 py-2 text-center" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ row.subject?.units }}</td>
                                                <td class="px-4 py-2 text-center">
                                                    {{ row.subject?.subject_type === 'practicum' ? '—' : row.subject?.lecture_hours }}
                                                </td>
                                                <td class="px-4 py-2 text-center">
                                                    {{ row.subject?.subject_type === 'practicum' ? '—' : row.subject?.laboratory_hours }}
                                                </td>
                                                <td class="px-4 py-2 text-center">
                                                    {{ row.subject?.subject_type === 'practicum' ? (row.subject?.required_hours ?? '—') : '—' }}
                                                </td>
                                                <td class="px-4 py-2">
                                                    <span v-if="row.prerequisite" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
                                                        {{ row.prerequisite.subject_code }}
                                                    </span>
                                                    <span v-else :class="isDark ? 'text-slate-500' : 'text-slate-400'">—</span>
                                                </td>
                                                <td class="px-4 py-2">
                                                    <div class="flex gap-1 justify-end">
                                                        <Button
                                                            icon="pi pi-pencil"
                                                            text
                                                            rounded
                                                            severity="secondary"
                                                            size="small"
                                                            aria-label="Edit"
                                                            @click="openEdit(row)"
                                                        />
                                                        <Button
                                                            icon="pi pi-trash"
                                                            text
                                                            rounded
                                                            severity="danger"
                                                            size="small"
                                                            aria-label="Remove"
                                                            @click="onDelete(row)"
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
            </div>
        </div>

        <!-- Add / Edit Subject Dialog -->
        <Dialog
            v-model:visible="dialogVisible"
            :header="editingItem ? 'Edit Curriculum Subject' : 'Add Subject'"
            modal
            class="w-full max-w-lg"
            :style="isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeDialog"
        >
            <div class="flex flex-col gap-4 neu-form">
                <!-- Curriculum (read only) -->
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Curriculum</label>
                    <InputText
                        :modelValue="`${curriculum.name} (${curriculum.start_year}–${curriculum.end_year})`"
                        readonly
                        disabled
                        class="w-full"
                    />
                </div>

                <!-- Subject: checkbox multi-select in Add mode, single select in Edit mode -->
                <div v-if="!editingItem" class="flex flex-col gap-1">
                    <label for="subject_ids" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Subjects <span class="text-red-500">*</span>
                    </label>
                    <MultiSelect
                        id="subject_ids"
                        v-model="addForm.subject_ids"
                        :options="subjectOptions"
                        optionLabel="label"
                        optionValue="value"
                        display="chip"
                        filter
                        appendTo="self"
                        filterPlaceholder="Search subject code or title"
                        placeholder="Select one or more subjects"
                        :showToggleAll="true"
                        :invalid="!!addForm.errors.subject_ids"
                        class="w-full"
                        :pt="{
                            overlay: { class: isDark ? 'dark-scope' : '', style: { width: '100%', maxWidth: '100%' } },
                        }"
                    />
                    <small v-if="addForm.errors.subject_ids" class="text-red-500">
                        {{ addForm.errors.subject_ids }}
                    </small>
                    <small :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        Check every subject that belongs to this Year Level and Semester — they'll all be added at once. Set a prerequisite for a subject afterward using Edit.
                    </small>
                </div>

                <div v-else class="flex flex-col gap-1">
                    <label for="subject_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Subject <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="subject_id"
                        v-model="editForm.subject_id"
                        :options="editSubjectOptions"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        appendTo="self"
                        filterPlaceholder="Search subject code or title"
                        placeholder="Select a subject"
                        :invalid="!!editForm.errors.subject_id"
                        class="w-full"
                        :pt="{
                            overlay: { class: isDark ? 'dark-scope' : '', style: { width: '100%', maxWidth: '100%' } },
                        }"
                    />
                    <small v-if="editForm.errors.subject_id" class="text-red-500">
                        {{ editForm.errors.subject_id }}
                    </small>
                </div>

                <!-- Year Level / Semester -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1">
                        <label for="year_level" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                            Year Level <span class="text-red-500">*</span>
                        </label>
                        <Select
                            id="year_level"
                            v-model="form.year_level"
                            :options="yearLevelOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select year level"
                            :invalid="!!form.errors.year_level"
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <small v-if="form.errors.year_level" class="text-red-500">
                            {{ form.errors.year_level }}
                        </small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="semester" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                            Semester <span class="text-red-500">*</span>
                        </label>
                        <Select
                            id="semester"
                            v-model="form.semester"
                            :options="semesterOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select semester"
                            :invalid="!!form.errors.semester"
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <small v-if="form.errors.semester" class="text-red-500">
                            {{ form.errors.semester }}
                        </small>
                    </div>
                </div>

                <!-- Prerequisite (Edit mode only — a bulk Add covers several Subjects) -->
                <div v-if="editingItem" class="flex flex-col gap-1">
                    <label for="prerequisite_subject_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Prerequisite
                    </label>
                    <Select
                        id="prerequisite_subject_id"
                        v-model="editForm.prerequisite_subject_id"
                        :options="prerequisiteOptions"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        filterPlaceholder="Search subject code or title"
                        placeholder="None"
                        showClear
                        :invalid="!!editForm.errors.prerequisite_subject_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="editForm.errors.prerequisite_subject_id" class="text-red-500">
                        {{ editForm.errors.prerequisite_subject_id }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1">
                    <label for="remarks" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="form.remarks"
                        rows="3"
                        placeholder="Optional notes about this placement"
                        :invalid="!!form.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="form.errors.remarks" class="text-red-500">
                        {{ form.errors.remarks }}
                    </small>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="form.processing" @click="closeDialog" />
                <Button label="Save" icon="pi pi-check" :loading="form.processing" @click="onSave" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page. The Dialog is teleported to <body> by
   PrimeVue, so its rules use :global() with a compound selector
   instead of :deep() — Vue's scoped-CSS attribute doesn't reliably
   travel through the teleport boundary. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.05) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.divide-slate-100 > :not([hidden]) ~ :not([hidden])) { border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(tr.hover\:bg-slate-50:hover) { background-color: rgba(255, 255, 255, 0.06) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }

/* Add / Edit Subject modal (teleported to <body>) */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder),
:global(.dark-scope.p-dialog .p-textarea::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputtext:disabled) { background: rgba(255, 255, 255, 0.03) !important; color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder),
:global(.dark-scope.p-dialog .p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }

:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-filter),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-filter) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }
</style>