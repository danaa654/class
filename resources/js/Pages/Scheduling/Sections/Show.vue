<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackLink from '@/Components/BackLink.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Toast from 'primevue/toast';

const props = defineProps({
    section: { type: Object, required: true },
    sectionSubjects: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ subject_search: '' }) },
    availableSubjects: { type: Array, default: () => [] },
    activeMajors: { type: Array, default: () => [] },
    allCurriculums: { type: Array, default: () => [] },
    yearLevels: { type: Array, default: () => [] },
    semesterOptions: { type: Array, default: () => [] },
    academicYears: { type: Array, default: () => [] },
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
/* Workspace tabs                                                      */
/* ------------------------------------------------------------------ */

const activeWorkspaceTab = ref('information');

/* ==================================================================== */
/* TAB 1 — Section Information                                          */
/* ==================================================================== */

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const yearLevelOptions = computed(() => props.yearLevels.map((level) => ({ label: level, value: level })));
const semesterSelectOptions = computed(() => props.semesterOptions.map((sem) => ({ label: sem, value: sem })));
const academicYearOptions = computed(() => props.academicYears.map((year) => ({ label: year, value: year })));

const infoForm = useForm({
    section_code: props.section.section_code,
    section_name: props.section.section_name,
    major_id: props.section.major_id,
    curriculum_id: props.section.curriculum_id,
    year_level: props.section.year_level,
    academic_year: props.section.academic_year,
    semester: props.section.semester,
    estimated_students: props.section.estimated_students,
    status: props.section.status,
    remarks: props.section.remarks ?? '',
});

// Only show curriculums that belong to the selected Major.
const filteredCurriculums = computed(() => {
    if (!infoForm.major_id) {
        return [];
    }

    return props.allCurriculums
        .filter((curriculum) => curriculum.major_id === infoForm.major_id)
        .map((curriculum) => ({ label: `${curriculum.code} — ${curriculum.name}`, value: curriculum.id }));
});

// If the Major changes and the currently selected Curriculum no longer
// belongs to it, clear the Curriculum selection.
watch(
    () => infoForm.major_id,
    () => {
        const stillValid = filteredCurriculums.value.some((curriculum) => curriculum.value === infoForm.curriculum_id);
        if (!stillValid) {
            infoForm.curriculum_id = null;
        }
    },
);

const onSaveInformation = () => {
    infoForm.put(route('scheduling.sections.update', props.section.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Saved', detail: 'Section information updated.', life: 3000 });
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};

/* ==================================================================== */
/* TAB 2 — Subjects                                                     */
/* ==================================================================== */

const search = ref(props.filters.subject_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reload = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.section-subjects.show', props.section.id),
        { subject_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => reload(), 350);
});

const onRefresh = () => reload();

/* --- Generate Curriculum Subjects --- */

const generating = ref(false);

const onGenerateCurriculumSubjects = () => {
    Swal.fire({
        title: 'Load From Prospectus?',
        html: `This will load every subject from <b>${props.section.curriculum?.code ?? 'the selected prospectus'}</b> for <b>${props.section.year_level}</b>, <b>${props.section.semester}</b>.<br><br>Subjects already assigned to this section will be skipped — no duplicates will be added.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#16A34A',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, generate',
    }).then((result) => {
        if (!result.isConfirmed) {
            return;
        }

        generating.value = true;
        router.post(
            route('scheduling.section-subjects.generate-curriculum', props.section.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    generating.value = false;
                },
            },
        );
    });
};

/* --- Add Subject (manual) dialog --- */

const addDialogVisible = ref(false);

const manualForm = useForm({
    subject_ids: [],
});

const manualSubjectOptions = computed(() => props.availableSubjects);

const openAddDialog = () => {
    manualForm.reset();
    manualForm.clearErrors();
    addDialogVisible.value = true;
};

const closeAddDialog = () => {
    addDialogVisible.value = false;
};

const onAddManual = () => {
    if (manualForm.subject_ids.length === 0) {
        return;
    }

    router.post(
        route('scheduling.section-subjects.store', props.section.id),
        {
            source: 'Manual',
            subject_ids: manualForm.subject_ids,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeAddDialog(),
            onError: (errors) => {
                manualForm.errors.subject_ids = Object.values(errors).flat().join(' ');
            },
        },
    );
};

/* --- Remove Subject --- */

const onRemove = (row) => {
    Swal.fire({
        title: 'Remove this subject?',
        text: `${row.subject?.subject_code} will be removed from ${props.section.section_code}. The subject itself will not be deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, remove it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.section-subjects.destroy', [props.section.id, row.subject_id]), {
                preserveScroll: true,
            });
        }
    });
};

const sourceSeverity = (source) => (source === 'Curriculum' ? 'info' : 'secondary');
const categorySeverity = (category) => (category === 'Major' ? 'info' : 'secondary');

/* ==================================================================== */
/* TAB 3 — Schedule                                                     */
/* ==================================================================== */

// Read-only preview built from the Section's assigned subjects. No
// scheduling logic, faculty assignment, room assignment, or conflict
// checking happens here — every row is simply "Pending" until a later
// module fills it in.
const scheduleRows = computed(() => props.sectionSubjects);
</script>

<template>
    <Head :title="`Edit Section — ${section.section_code}`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Edit Section</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Back link -->
            <div class="mb-4">
                <BackLink :href="route('scheduling.sections')" label="Back to Sections" />
            </div>

            <!-- Page Title -->
            <div class="neu-card neu-spotlight relative mb-6 overflow-hidden rounded-2xl">
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-50 via-transparent to-red-50/60"></div>
                    <div class="absolute -right-10 -top-16 h-44 w-44 rounded-full bg-gradient-to-br from-blue-200/80 via-blue-100/40 to-transparent"></div>
                    <div class="absolute -bottom-20 right-16 h-40 w-40 rounded-full bg-gradient-to-tr from-red-200/70 via-red-100/30 to-transparent"></div>
                    <div class="absolute -left-10 bottom-0 h-28 w-28 rounded-full bg-gradient-to-tr border-4 from-blue-100/60 to-transparent border-blue-100"></div>
                    <div class="absolute left-[38%] top-0 h-3 w-3 rounded-full bg-gradient-to-br from-red-400/80 to-blue-300/60"></div>
                    <div class="absolute left-[55%] bottom-4 h-20 w-20 rounded-full bg-gradient-to-r blur-xl from-blue-200/40 to-red-200/40"></div>
                </div>
                <div class="relative rounded-2xl p-6 transition-colors duration-300">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">
                    {{ section.section_code }} — {{ section.section_name }}
                </h1>
                <p class="mt-1 text-slate-500">
                    Manage this section's information, subjects, and schedule in one place.
                </p>
                </div>
            </div>

            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <Tabs v-model:value="activeWorkspaceTab">
                        <TabList>
                            <Tab value="information">Section Information</Tab>
                            <Tab value="subjects">Subjects</Tab>
                            <Tab value="schedule">Schedule</Tab>
                        </TabList>

                        <TabPanels>
                            <!-- ============================================================ -->
                            <!-- TAB 1: Section Information                                     -->
                            <!-- ============================================================ -->
                            <TabPanel value="information">
                                <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 pt-2" @submit.prevent="onSaveInformation">
                                    <!-- Section Code -->
                                    <div class="flex flex-col gap-1">
                                        <label for="section_code" class="text-sm font-medium text-slate-700">
                                            Section Code <span class="text-red-500">*</span>
                                        </label>
                                        <InputText
                                            id="section_code"
                                            v-model="infoForm.section_code"
                                            placeholder="e.g. BSIT-1A, BSIT-2B"
                                            :invalid="!!infoForm.errors.section_code"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.section_code" class="text-red-500">
                                            {{ infoForm.errors.section_code }}
                                        </small>
                                    </div>

                                    <!-- Section Name -->
                                    <div class="flex flex-col gap-1">
                                        <label for="section_name" class="text-sm font-medium text-slate-700">
                                            Section Name <span class="text-red-500">*</span>
                                        </label>
                                        <InputText
                                            id="section_name"
                                            v-model="infoForm.section_name"
                                            placeholder="e.g. Section A"
                                            :invalid="!!infoForm.errors.section_name"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.section_name" class="text-red-500">
                                            {{ infoForm.errors.section_name }}
                                        </small>
                                    </div>

                                    <!-- Major -->
                                    <div class="flex flex-col gap-1">
                                        <label for="major_id" class="text-sm font-medium text-slate-700">
                                            College / Program <span class="text-red-500">*</span>
                                        </label>
                                        <Select
                                            id="major_id"
                                            v-model="infoForm.major_id"
                                            :options="activeMajors"
                                            optionLabel="name"
                                            optionValue="id"
                                            filter
                                            placeholder="Select a major"
                                            :invalid="!!infoForm.errors.major_id"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.major_id" class="text-red-500">
                                            {{ infoForm.errors.major_id }}
                                        </small>
                                    </div>

                                    <!-- Prospectus -->
                                    <div class="flex flex-col gap-1">
                                        <label for="curriculum_id" class="text-sm font-medium text-slate-700">
                                            Prospectus <span class="text-red-500">*</span>
                                        </label>
                                        <Select
                                            id="curriculum_id"
                                            v-model="infoForm.curriculum_id"
                                            :options="filteredCurriculums"
                                            optionLabel="label"
                                            optionValue="value"
                                            filter
                                            :disabled="!infoForm.major_id"
                                            :placeholder="infoForm.major_id ? 'Select a prospectus' : 'Select a program first'"
                                            :invalid="!!infoForm.errors.curriculum_id"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.curriculum_id" class="text-red-500">
                                            {{ infoForm.errors.curriculum_id }}
                                        </small>
                                    </div>

                                    <!-- Academic Year -->
                                    <div class="flex flex-col gap-1">
                                        <label for="academic_year" class="text-sm font-medium text-slate-700">
                                            Academic Year <span class="text-red-500">*</span>
                                        </label>
                                        <Select
                                            id="academic_year"
                                            v-model="infoForm.academic_year"
                                            :options="academicYearOptions"
                                            optionLabel="label"
                                            optionValue="value"
                                            placeholder="Select academic year"
                                            :invalid="!!infoForm.errors.academic_year"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.academic_year" class="text-red-500">
                                            {{ infoForm.errors.academic_year }}
                                        </small>
                                    </div>

                                    <!-- Semester -->
                                    <div class="flex flex-col gap-1">
                                        <label for="semester" class="text-sm font-medium text-slate-700">
                                            Semester <span class="text-red-500">*</span>
                                        </label>
                                        <Select
                                            id="semester"
                                            v-model="infoForm.semester"
                                            :options="semesterSelectOptions"
                                            optionLabel="label"
                                            optionValue="value"
                                            placeholder="Select semester"
                                            :invalid="!!infoForm.errors.semester"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.semester" class="text-red-500">
                                            {{ infoForm.errors.semester }}
                                        </small>
                                    </div>

                                    <!-- Year Level -->
                                    <div class="flex flex-col gap-1">
                                        <label for="year_level" class="text-sm font-medium text-slate-700">
                                            Year Level <span class="text-red-500">*</span>
                                        </label>
                                        <Select
                                            id="year_level"
                                            v-model="infoForm.year_level"
                                            :options="yearLevelOptions"
                                            optionLabel="label"
                                            optionValue="value"
                                            placeholder="Select year level"
                                            :invalid="!!infoForm.errors.year_level"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.year_level" class="text-red-500">
                                            {{ infoForm.errors.year_level }}
                                        </small>
                                    </div>

                                    <!-- Estimated Students -->
                                    <div class="flex flex-col gap-1">
                                        <label for="estimated_students" class="text-sm font-medium text-slate-700">
                                            Estimated Number of Students <span class="text-red-500">*</span>
                                        </label>
                                        <InputNumber
                                            id="estimated_students"
                                            v-model="infoForm.estimated_students"
                                            :min="1"
                                            showButtons
                                            buttonLayout="horizontal"
                                            :invalid="!!infoForm.errors.estimated_students"
                                            class="w-full"
                                            inputClass="w-full"
                                        />
                                        <small v-if="infoForm.errors.estimated_students" class="text-red-500">
                                            {{ infoForm.errors.estimated_students }}
                                        </small>
                                    </div>

                                    <!-- Status -->
                                    <div class="flex flex-col gap-1">
                                        <label for="status" class="text-sm font-medium text-slate-700">
                                            Status <span class="text-red-500">*</span>
                                        </label>
                                        <Select
                                            id="status"
                                            v-model="infoForm.status"
                                            :options="statusOptions"
                                            optionLabel="label"
                                            optionValue="value"
                                            placeholder="Select status"
                                            :invalid="!!infoForm.errors.status"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.status" class="text-red-500">
                                            {{ infoForm.errors.status }}
                                        </small>
                                    </div>

                                    <!-- Remarks -->
                                    <div class="flex flex-col gap-1 sm:col-span-2">
                                        <label for="remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                                        <Textarea
                                            id="remarks"
                                            v-model="infoForm.remarks"
                                            autoResize
                                            rows="3"
                                            placeholder="Optional notes about this section"
                                            :invalid="!!infoForm.errors.remarks"
                                            class="w-full"
                                        />
                                        <small v-if="infoForm.errors.remarks" class="text-red-500">
                                            {{ infoForm.errors.remarks }}
                                        </small>
                                    </div>

                                    <div class="sm:col-span-2 flex justify-end pt-2">
                                        <Button
                                            type="submit"
                                            label="Save Changes"
                                            icon="pi pi-check"
                                            severity="success"
                                            :loading="infoForm.processing"
                                        />
                                    </div>
                                </form>
                            </TabPanel>

                            <!-- ============================================================ -->
                            <!-- TAB 2: Subjects                                                -->
                            <!-- ============================================================ -->
                            <TabPanel value="subjects">
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-2 !pb-4 flex-wrap gap-3">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                            <InputText
                                                v-model="search"
                                                placeholder="Search by code, title or category"
                                                class="w-full !pl-9"
                                            />
                                        </span>
                                    </template>
                                    <template #end>
                                        <div class="flex items-center gap-2">
                                            <Button
                                                icon="pi pi-refresh"
                                                severity="secondary"
                                                outlined
                                                :loading="loading"
                                                @click="onRefresh"
                                                aria-label="Refresh"
                                            />
                                            <Button
                                                label="Load From Prospectus"
                                                icon="pi pi-sync"
                                                severity="secondary"
                                                :loading="generating"
                                                @click="onGenerateCurriculumSubjects"
                                            />
                                            <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAddDialog" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <DataTable
                                    :value="sectionSubjects"
                                    :loading="loading"
                                    dataKey="id"
                                    class="rounded-xl overflow-hidden"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    paginator
                                    :rows="10"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No subjects assigned yet.</p>
                                            <p class="text-slate-400 text-sm mt-1">
                                                Use "Load From Prospectus" to load this section's prospectus, or "Add Subject" to add one manually.
                                            </p>
                                            <div class="flex items-center justify-center gap-2 mt-3">
                                                <Button
                                                    label="Load From Prospectus"
                                                    icon="pi pi-sync"
                                                    severity="secondary"
                                                    @click="onGenerateCurriculumSubjects"
                                                />
                                                <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAddDialog" />
                                            </div>
                                        </div>
                                    </template>

                                    <Column header="Subject Code" style="width: 10rem">
                                        <template #body="{ data }">
                                            {{ data.subject?.subject_code }}
                                        </template>
                                    </Column>
                                    <Column header="Subject Title">
                                        <template #body="{ data }">
                                            {{ data.subject?.subject_title }}
                                        </template>
                                    </Column>
                                    <Column header="Category" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag :value="data.subject?.category" :severity="categorySeverity(data.subject?.category)" />
                                        </template>
                                    </Column>
                                    <Column header="Units" style="width: 6rem">
                                        <template #body="{ data }">
                                            {{ data.subject?.units }}
                                        </template>
                                    </Column>
                                    <Column header="Source" style="width: 9rem">
                                        <template #body="{ data }">
                                            <Tag :value="data.source" :severity="sourceSeverity(data.source)" />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 7rem">
                                        <template #body="{ data }">
                                            <Button
                                                icon="pi pi-trash"
                                                text
                                                rounded
                                                severity="danger"
                                                size="small"
                                                aria-label="Remove"
                                                @click="onRemove(data)"
                                            />
                                        </template>
                                    </Column>
                                </DataTable>
                            </TabPanel>

                            <!-- ============================================================ -->
                            <!-- TAB 3: Schedule                                                -->
                            <!-- ============================================================ -->
                            <TabPanel value="schedule">
                                <div class="pt-2 mb-4">
                                    <p class="text-sm text-slate-500">
                                        Faculty, room, day, and time slot assignment will be handled by the scheduling
                                        engine. For now, every subject sits here as <b>Pending</b> until it's scheduled.
                                    </p>
                                </div>

                                <DataTable
                                    :value="scheduleRows"
                                    dataKey="id"
                                    class="rounded-xl overflow-hidden"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    paginator
                                    :rows="10"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No subjects to schedule yet.</p>
                                            <p class="text-slate-400 text-sm mt-1">
                                                Add subjects to this section from the Subjects tab first.
                                            </p>
                                        </div>
                                    </template>

                                    <Column header="Subject" style="min-width: 16rem">
                                        <template #body="{ data }">
                                            <div class="font-medium text-slate-700">{{ data.subject?.subject_code }}</div>
                                            <div class="text-xs text-slate-400">{{ data.subject?.subject_title }}</div>
                                        </template>
                                    </Column>
                                    <Column header="Faculty" style="width: 10rem">
                                        <template #body>
                                            <span class="text-slate-400 italic">Not Assigned</span>
                                        </template>
                                    </Column>
                                    <Column header="Room" style="width: 9rem">
                                        <template #body>
                                            <span class="text-slate-400 italic">Not Assigned</span>
                                        </template>
                                    </Column>
                                    <Column header="Day" style="width: 8rem">
                                        <template #body>
                                            <span class="text-slate-400 italic">Not Assigned</span>
                                        </template>
                                    </Column>
                                    <Column header="Time Slot" style="width: 9rem">
                                        <template #body>
                                            <span class="text-slate-400 italic">Not Assigned</span>
                                        </template>
                                    </Column>
                                    <Column header="Status" style="width: 8rem">
                                        <template #body>
                                            <Tag value="Pending" severity="warn" />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 7rem">
                                        <template #body>
                                            <Button
                                                icon="pi pi-cog"
                                                text
                                                rounded
                                                severity="secondary"
                                                size="small"
                                                disabled
                                                title="Scheduling is not available yet"
                                                aria-label="Configure (coming soon)"
                                            />
                                        </template>
                                    </Column>
                                </DataTable>
                            </TabPanel>
                        </TabPanels>
                    </Tabs>
                </template>
            </Card>
        </div>

        <!-- Add Subject Dialog (manual selection only) -->
        <Dialog
            v-model:visible="addDialogVisible"
            modal
            header="Add Subject"
            :style="{ width: '600px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            @hide="closeAddDialog"
        >
            <p class="text-sm text-slate-500 mb-4">
                Manually add a subject from the master Subject list — useful for irregular students,
                bridging subjects, replacement subjects, or cross-enrolled subjects.
            </p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-slate-700">Search Subject</label>
                <MultiSelect
                    v-model="manualForm.subject_ids"
                    :options="manualSubjectOptions"
                    optionLabel="subject_code"
                    optionValue="id"
                    filter
                    filterPlaceholder="Search subject code or title"
                    display="chip"
                    placeholder="Select one or multiple subjects"
                    :invalid="!!manualForm.errors.subject_ids"
                    class="w-full"
                >
                    <template #option="{ option }">
                        <span class="font-medium">{{ option.subject_code }}</span>
                        <span class="text-slate-400"> — {{ option.subject_title }}</span>
                    </template>
                </MultiSelect>
                <small v-if="manualForm.errors.subject_ids" class="text-red-500">
                    {{ manualForm.errors.subject_ids }}
                </small>
            </div>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="closeAddDialog" />
                <Button
                    label="Add"
                    icon="pi pi-plus"
                    severity="success"
                    :loading="manualForm.processing"
                    :disabled="manualForm.subject_ids.length === 0"
                    @click="onAddManual"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>