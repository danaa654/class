<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    faculties: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ faculty_search: '', faculty_category: '' }),
    },
    colleges: { type: Array, default: () => [] },
    nextFacultyId: { type: String, default: '' },

    // System-wide teaching-load ceiling (Settings > Faculty & Workload)
    // — still enforced as the Maximum Teaching Units field's upper
    // bound, just no longer via an approval queue (see
    // FacultyPolicy::changeMaxLoad()).
    hardCapUnits: { type: Number, default: 40 },
    canCreateFacultyDirectly: { type: Boolean, default: false },
});

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message.
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
/* Search / list                                                       */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.faculty_search ?? '');
const categoryFilter = ref(props.filters.faculty_category ?? '');
const loading = ref(false);
let searchDebounce = null;

// Not a stored field — General Education Faculty is simply "no College
// assigned". Kept as a quick filter option since it's still a useful
// distinction, just derived rather than tracked separately.
const facultyCategoryOptions = ['Department Faculty', 'General Education Faculty'];

const reloadFaculties = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.faculty'),
        { faculty_search: search.value, faculty_category: categoryFilter.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['faculties'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadFaculties({ faculty_page: 1 });
    }, 350);
});

watch(categoryFilter, () => {
    reloadFaculties({ faculty_page: 1 });
});

const onPage = (event) => {
    reloadFaculties({ faculty_page: event.page + 1 });
};

const onRefresh = () => {
    reloadFaculties({ faculty_page: props.faculties.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Faculty                                                   */
/* ------------------------------------------------------------------ */

const employmentTypeOptions = ['Full-time', 'Part-time', 'Contractual'];
const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const addFacultyVisible = ref(false);
const editingFaculty = ref(null); // null => Add mode, otherwise the Faculty being edited

/* ------------------------------------------------------------------ */
/* Load ceiling permission — see FacultyPolicy::changeMaxLoad()        */
/* ------------------------------------------------------------------ */

// Every Scheduling-side role (Admin, Registrar, Dean/OIC, Assistant
// Dean) may set this directly now — no approval queue. This just
// mirrors the server-side gate for the UI (disabled state), the
// server independently enforces it in FacultyController::update()/store().
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

const facultyForm = useForm({
    faculty_id: '',
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    employment_type: null,
    college_id: null,
    max_teaching_units: 21,
    status: 'Active',
    email: '',
    contact_number: '',
    remarks: '',
});

// Faculty ID, name, suffix, contact number, and remarks are always
// stored/displayed in caps (matches the table); Email is left as
// typed since addresses are case-sensitive-looking to users.
const UPPERCASE_FIELDS = ['faculty_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'contact_number', 'remarks'];
UPPERCASE_FIELDS.forEach((field) => {
    watch(
        () => facultyForm[field],
        (value) => {
            if (typeof value === 'string' && value !== value.toUpperCase()) {
                facultyForm[field] = value.toUpperCase();
            }
        },
    );
});

const openAdd = () => {
    editingFaculty.value = null;
    facultyForm.reset();
    facultyForm.clearErrors();
    facultyForm.faculty_id = props.nextFacultyId;
    addFacultyVisible.value = true;
};

const openEdit = (faculty) => {
    editingFaculty.value = faculty;
    facultyForm.clearErrors();
    facultyForm.faculty_id = faculty.faculty_id;
    facultyForm.first_name = faculty.first_name;
    facultyForm.middle_name = faculty.middle_name ?? '';
    facultyForm.last_name = faculty.last_name;
    facultyForm.suffix = faculty.suffix ?? '';
    facultyForm.employment_type = faculty.employment_type;
    facultyForm.college_id = faculty.college_id;
    facultyForm.max_teaching_units = faculty.max_teaching_units;
    facultyForm.status = faculty.status;
    facultyForm.email = faculty.email ?? '';
    facultyForm.contact_number = faculty.contact_number ?? '';
    facultyForm.remarks = faculty.remarks ?? '';
    addFacultyVisible.value = true;
};

const closeAddFaculty = () => {
    addFacultyVisible.value = false;
    editingFaculty.value = null;
    facultyForm.reset();
    facultyForm.clearErrors();
};

const onSaveFaculty = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            const wasEditing = !!editingFaculty.value;
            closeAddFaculty();
            Swal.fire({
                title: wasEditing ? 'Faculty updated' : 'Faculty saved',
                text: wasEditing
                    ? 'The faculty record was updated successfully.'
                    : 'The faculty member was added successfully.',
                icon: 'success',
                confirmButtonColor: '#16A34A',
            });
            onRefresh();
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    };

    if (editingFaculty.value) {
        facultyForm.put(route('scheduling.faculty.update', editingFaculty.value.id), options);
    } else {
        facultyForm.post(route('scheduling.faculty.store'), options);
    }
};

// Admin/Registrar direct delete — for faculty that don't belong on
// the roster at all (added by mistake, never actually with the
// school, etc). Always double-confirmed, regardless of whether the
// faculty has assignments, so an accidental click can never remove a
// row outright. If the faculty ALSO has an active (non-finalized)
// assigned subject, the second step additionally warns about that —
// the backend re-derives the impact itself and is the authoritative
// gate; this first request (confirmed=false, the default) just asks
// it what the impact is so the dialog can show real numbers,
// matching FacultyController::destroy()'s contract.
//
// Simple manual deactivation (faculty just isn't teaching this term,
// but still belongs on the roster) is no longer done from this
// button — use Edit → Status → Inactive instead, which keeps the row.
const pendingDeleteFaculty = ref(null);

const onDeleteFaculty = (faculty) => {
    Swal.fire({
        title: 'Delete this faculty member?',
        html: `<strong>${faculty.faculty_id} — ${faculty.first_name} ${faculty.last_name}</strong><br/><br/>
            This permanently removes them from the Faculty Master. If they simply aren't teaching this term but still
            belong on the roster, cancel this and use <strong>Edit → Status → Inactive</strong> instead.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        confirmButtonText: 'Continue',
    }).then((confirmStep) => {
        if (!confirmStep.isConfirmed) return;

        pendingDeleteFaculty.value = faculty;
        router.delete(route('scheduling.faculty.destroy', faculty.id), {
            preserveScroll: true,
            onSuccess: () => onRefresh(),
            onError: () => {},
            // If the backend has no assigned subject to warn about,
            // it deletes immediately here and the watcher below never
            // fires — this Swal chain is skipped entirely.
        });
    });
};

// Real double-confirmation flow, using the flashed impact summary the
// backend returns when it declines an unconfirmed delete because the
// faculty has an assigned subject already scheduled
// (`facultyDeletionImpact` — see FacultyController::destroy()).
watch(
    () => page.props.flash?.facultyDeletionImpact,
    (impact) => {
        if (!impact || !pendingDeleteFaculty.value) return;
        const faculty = pendingDeleteFaculty.value;
        pendingDeleteFaculty.value = null;

        Swal.fire({
            title: '⚠ Faculty Has an Assigned Subject',
            html: `<strong>${faculty.faculty_id} — ${faculty.first_name} ${faculty.last_name}</strong><br/>
                Current active assignments:<br/>
                ${impact.subject_count} subject(s), ${impact.section_count} section(s), ${impact.weekly_load} ${impact.load_unit}/week<br/><br/>
                <span class="text-amber-600">Deleting this faculty will leave those scheduled subjects without an assigned faculty.</span><br/><br/>
                Affected subjects: ${impact.subject_codes.join(', ') || '—'}<br/>
                Affected sections: ${impact.section_codes.join(', ') || '—'}`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#DC2626',
            confirmButtonText: 'Delete Faculty',
        }).then((second) => {
            if (!second.isConfirmed) return;
            router.delete(route('scheduling.faculty.destroy', faculty.id), {
                data: { confirmed: true },
                preserveScroll: true,
                onSuccess: () => onRefresh(),
            });
        });
    },
);

const fullName = (faculty) => {
    const middleInitial = faculty.middle_name ? ` ${faculty.middle_name.charAt(0)}.` : '';
    const suffix = faculty.suffix ? ` ${faculty.suffix}` : '';
    return `${faculty.last_name}, ${faculty.first_name}${middleInitial}${suffix}`;
};
</script>

<template>
    <Head title="Faculty Master" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Faculty Master</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
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
                <div class="relative rounded-2xl p-6 transition-colors duration-300">
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    Faculty Master
                    <InfoPopover
                        title="Faculty Master"
                        :paragraphs="[
                            'The master roster of faculty members available for teaching assignments across the institution.',
                        ]"
                        :bullets="[
                            'Max Teaching Units caps how many units a faculty member can be assigned per term; Teaching Load shows current usage against that cap.',
                            'Faculty with no College are treated as General Education Faculty and can be assigned across colleges.',
                            'Inactive faculty stay in the system for history but are not offered when assigning new teaching loads.',
                            'Use the view icon to manage a faculty member\'s teaching qualifications, availability, and workload details.',
                        ]"
                    />
                </h1>
                <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    Manage faculty members available for teaching assignments.
                </p>
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
                            <div class="flex flex-wrap items-center gap-3 w-full neu-form">
                                <span class="relative w-full sm:w-80">
                                    <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                    <InputText
                                        v-uppercase
                                        v-model="search"
                                        placeholder="Search by ID, name, or college"
                                        class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                        :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                    />
                                </span>
                                <Select
                                    v-model="categoryFilter"
                                    :options="facultyCategoryOptions"
                                    placeholder="Filter by Department / General Education"
                                    showClear
                                    class="w-full sm:w-64"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                            </div>
                        </template>
                        <template #end>
                            <div class="flex items-center gap-2">
                                <Button
                                    icon="pi pi-refresh"
                                    severity="secondary"
                                    text
                                    class="neu-icon-well !rounded-full"
                                    :loading="loading"
                                    @click="onRefresh"
                                    aria-label="Refresh"
                                />
                                <Button
                                    v-if="canCreateFacultyDirectly"
                                    label="Add Faculty"
                                    icon="pi pi-plus"
                                    severity="success"
                                    @click="openAdd"
                                />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Faculty Table -->
                    <p class="text-xs mb-2 flex items-center gap-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        <i class="pi pi-eye" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                        Click a faculty member's name to manage their teaching qualifications and workload.
                    </p>
                    <DataTable
                        :value="faculties.data"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden"
                        :class="isDark ? 'neu-table-dark' : ''"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="faculties.per_page"
                        :totalRecords="faculties.total"
                        :first="(faculties.current_page - 1) * faculties.per_page"
                        @page="onPage"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-500'">No faculty members found.</p>
                                <p class="text-sm mt-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                                    Click "Add Faculty" to create your first faculty record.
                                </p>
                                <Button
                                    label="Add Faculty"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="faculty_id" header="Faculty ID" style="width: 9rem" />
                        <Column header="Faculty Name" style="min-width: 14rem">
                            <template #body="{ data }">
                                <span
                                    class="cursor-pointer hover:underline"
                                    :class="isDark ? 'text-emerald-400' : 'text-emerald-700'"
                                    @click="router.visit(route('scheduling.faculty.show', data.id))"
                                >
                                    {{ fullName(data) }}
                                </span>
                            </template>
                        </Column>
                        <Column header="Employment Status" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.employment_type }}
                            </template>
                        </Column>
                        <Column header="College" style="width: 16rem">
                            <template #body="{ data }">
                                <span v-if="data.college?.name">{{ data.college.name }}</span>
                                <Tag v-else value="General Education" severity="warning" />
                            </template>
                        </Column>
                        <Column header="Max Teaching Units" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.max_teaching_units }}
                            </template>
                        </Column>
                        <Column style="width: 11rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Teaching Load
                                    <InfoPopover
                                        title="Teaching Load"
                                        :paragraphs="[
                                            'How many units this faculty member is currently assigned against their Max Teaching Units cap.',
                                        ]"
                                        :bullets="[
                                            '🟢 Green — comfortably under the cap.',
                                            '🟡 Yellow — nearing the cap.',
                                            '🔴 Red — at or over the cap; new assignments may be blocked or require override.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <div v-if="data.workload" class="flex items-center gap-1.5">
                                    <span>
                                        {{ data.workload.status === 'overloaded' ? '🔴' : data.workload.status === 'high' ? '🟡' : '🟢' }}
                                    </span>
                                    <span class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                                        {{ data.workload.current }} / {{ data.workload.max }} {{ data.workload.unit_label }}
                                    </span>
                                </div>
                            </template>
                        </Column>
                        <Column style="width: 9rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Status
                                    <InfoPopover
                                        title="Faculty Status"
                                        :bullets="[
                                            'Active — available for new teaching assignments.',
                                            'Inactive — kept for historical records; not offered for new assignments.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <Tag
                                    :value="data.status"
                                    :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column style="width: 11rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Actions
                                    <InfoPopover
                                        title="Actions"
                                        :bullets="[
                                            '👁 View — details, teaching qualifications & workload.',
                                            '✏️ Edit — update this faculty member\'s details.',
                                            canCreateFacultyDirectly ? '🗑 Delete — permanently remove this faculty member.' : null,
                                        ].filter(Boolean)"
                                        width="w-72"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        v-tooltip.top="'View details, teaching qualifications & workload'"
                                        icon="pi pi-eye"
                                        text
                                        rounded
                                        severity="info"
                                        size="small"
                                        aria-label="View"
                                        @click="router.visit(route('scheduling.faculty.show', data.id))"
                                    />
                                    <Button
                                        icon="pi pi-pencil"
                                        text
                                        rounded
                                        severity="secondary"
                                        size="small"
                                        aria-label="Edit"
                                        @click="openEdit(data)"
                                    />
                                    <Button
                                        v-if="canCreateFacultyDirectly"
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        aria-label="Delete"
                                        v-tooltip.top="'Delete Faculty'"
                                        @click="onDeleteFaculty(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
            </div>
        </div>

        <!-- Add Faculty Dialog -->
        <Dialog
            v-model:visible="addFacultyVisible"
            modal
            :header="editingFaculty ? 'Edit Faculty' : 'Add Faculty'"
            :style="{ width: '760px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeAddFaculty"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveFaculty">
                <!-- Faculty ID -->
                <div class="flex flex-col gap-1">
                    <label for="faculty_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Faculty ID <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
                        id="faculty_id"
                        v-model="facultyForm.faculty_id"
                        placeholder="e.g. F-2026-001"
                        :invalid="!!facultyForm.errors.faculty_id"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.faculty_id" class="text-red-500">
                        {{ facultyForm.errors.faculty_id }}
                    </small>
                    <p v-else-if="!editingFaculty" class="text-xs text-slate-400">
                        Suggested next ID — feel free to change it.
                    </p>
                </div>

                <!-- Employment Type -->
                <div class="flex flex-col gap-1">
                    <label for="employment_type" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Employment Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="employment_type"
                        v-model="facultyForm.employment_type"
                        :options="employmentTypeOptions"
                        placeholder="Select employment type"
                        :invalid="!!facultyForm.errors.employment_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.employment_type" class="text-red-500">
                        {{ facultyForm.errors.employment_type }}
                    </small>
                </div>

                <!-- First Name -->
                <div class="flex flex-col gap-1">
                    <label for="first_name" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        First Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
                        id="first_name"
                        v-model="facultyForm.first_name"
                        placeholder="e.g. Juan"
                        :invalid="!!facultyForm.errors.first_name"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.first_name" class="text-red-500">
                        {{ facultyForm.errors.first_name }}
                    </small>
                </div>

                <!-- Middle Name -->
                <div class="flex flex-col gap-1">
                    <label for="middle_name" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Middle Name</label>
                    <InputText
                        v-uppercase
                        id="middle_name"
                        v-model="facultyForm.middle_name"
                        placeholder="e.g. Santos"
                        :invalid="!!facultyForm.errors.middle_name"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.middle_name" class="text-red-500">
                        {{ facultyForm.errors.middle_name }}
                    </small>
                </div>

                <!-- Last Name -->
                <div class="flex flex-col gap-1">
                    <label for="last_name" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Last Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
                        id="last_name"
                        v-model="facultyForm.last_name"
                        placeholder="e.g. Dela Cruz"
                        :invalid="!!facultyForm.errors.last_name"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.last_name" class="text-red-500">
                        {{ facultyForm.errors.last_name }}
                    </small>
                </div>

                <!-- Suffix -->
                <div class="flex flex-col gap-1">
                    <label for="suffix" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Suffix</label>
                    <InputText
                        v-uppercase
                        id="suffix"
                        v-model="facultyForm.suffix"
                        placeholder="e.g. Jr., III"
                        :invalid="!!facultyForm.errors.suffix"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.suffix" class="text-red-500">
                        {{ facultyForm.errors.suffix }}
                    </small>
                </div>

                <!-- College -->
                <div class="flex flex-col gap-1">
                    <label for="college_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">College</label>
                    <Select
                        id="college_id"
                        v-model="facultyForm.college_id"
                        :options="colleges"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Leave blank for General Education"
                        showClear
                        :invalid="!!facultyForm.errors.college_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.college_id" class="text-red-500">
                        {{ facultyForm.errors.college_id }}
                    </small>
                    <p v-else class="text-xs text-slate-400">
                        Leave blank if this faculty member is General Education (no department).
                    </p>
                </div>

                <!-- Maximum Teaching Units -->
                <div class="flex flex-col gap-1">
                    <label for="max_teaching_units" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Maximum Teaching Units <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="max_teaching_units"
                        v-model="facultyForm.max_teaching_units"
                        :min="0"
                        :max="hardCapUnits"
                        :disabled="!canChangeMaxLoad"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_teaching_units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_teaching_units" class="text-red-500">
                        {{ facultyForm.errors.max_teaching_units }}
                    </small>
                    <!-- Only Admin/Registrar may raise this ceiling directly (spec: scheduling-integrity concern raised re: any role editing it freely). -->
                    <p v-if="!canChangeMaxLoad" class="text-xs text-slate-400">
                        Only Admin/Registrar can change this directly.
                    </p>
                    <p v-else class="text-xs text-slate-400">Current teaching load ceiling: {{ hardCapUnits }} units.</p>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="status"
                        v-model="facultyForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!facultyForm.errors.status"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.status" class="text-red-500">
                        {{ facultyForm.errors.status }}
                    </small>
                </div>

                <!-- Email -->
                <div class="flex flex-col gap-1">
                    <label for="email" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Email</label>
                    <InputText
                        id="email"
                        v-model="facultyForm.email"
                        type="email"
                        placeholder="Optional"
                        :invalid="!!facultyForm.errors.email"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.email" class="text-red-500">
                        {{ facultyForm.errors.email }}
                    </small>
                </div>

                <!-- Contact Number -->
                <div class="flex flex-col gap-1">
                    <label for="contact_number" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Contact Number</label>
                    <InputText
                        v-uppercase
                        id="contact_number"
                        v-model="facultyForm.contact_number"
                        placeholder="Optional"
                        :invalid="!!facultyForm.errors.contact_number"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.contact_number" class="text-red-500">
                        {{ facultyForm.errors.contact_number }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="remarks" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="facultyForm.remarks"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this faculty member"
                        :invalid="!!facultyForm.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.remarks" class="text-red-500">
                        {{ facultyForm.errors.remarks }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddFaculty" />
                <Button
                    :label="editingFaculty ? 'Update Faculty' : 'Save Faculty'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="facultyForm.processing"
                    @click="onSaveFaculty"
                />
            </template>
        </Dialog>
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
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }

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

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; font-weight: 600; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.035) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.07) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-paginator .p-paginator-page),
.dark-scope :deep(.p-paginator .p-paginator-prev),
.dark-scope :deep(.p-paginator .p-paginator-next),
.dark-scope :deep(.p-paginator .p-paginator-first),
.dark-scope :deep(.p-paginator .p-paginator-last) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator .p-paginator-page.p-paginator-page-selected) { background: rgba(37, 99, 235, 0.9) !important; color: #fff !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-text.p-button-info) { color: #7DD3FC !important; }
.dark-scope :deep(.p-button-text.p-button-info:hover) { background: rgba(56, 189, 248, 0.12) !important; color: #BAE6FD !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* Add / Edit Faculty modal (teleported to <body>) */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect),
:global(.dark-scope.p-dialog .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder),
:global(.dark-scope.p-dialog .p-textarea::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder),
:global(.dark-scope.p-dialog .p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.18) !important; color: #F8FAFC !important; }

:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
</style>