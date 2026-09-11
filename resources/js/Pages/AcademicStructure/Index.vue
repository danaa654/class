<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import FloatLabel from 'primevue/floatlabel';
import Checkbox from 'primevue/checkbox';
import Toast from 'primevue/toast';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    colleges: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    departments: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    majors: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    activeColleges: { type: Array, default: () => [] },
    activeDepartments: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ college_search: '', department_search: '', major_search: '' }),
    },
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

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

/* ------------------------------------------------------------------ */
/* Colleges tab                                                        */
/* ------------------------------------------------------------------ */

const collegeSearch = ref(props.filters.college_search ?? '');
const collegeLoading = ref(false);
let collegeSearchDebounce = null;

const reloadColleges = (extra = {}) => {
    collegeLoading.value = true;

    router.get(
        route('academic-structure'),
        { college_search: collegeSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['colleges'],
            onFinish: () => {
                collegeLoading.value = false;
            },
        },
    );
};

watch(collegeSearch, () => {
    clearTimeout(collegeSearchDebounce);
    collegeSearchDebounce = setTimeout(() => {
        reloadColleges({ college_page: 1 });
    }, 350);
});

const onCollegePage = (event) => {
    reloadColleges({ college_page: event.page + 1 });
};

const onRefreshColleges = () => {
    reloadColleges({ college_page: props.colleges.current_page });
};

const collegeDialogVisible = ref(false);
const collegeDialogMode = ref('add'); // 'add' | 'edit'
const editingCollege = ref(null);

const collegeForm = useForm({
    code: '',
    name: '',
    short_name: '',
    description: '',
    status: 'Active',
    counts_as_gened: false,
});

const openAddCollege = () => {
    collegeDialogMode.value = 'add';
    editingCollege.value = null;
    collegeForm.reset();
    collegeForm.clearErrors();
    collegeDialogVisible.value = true;
};

const openEditCollege = (college) => {
    collegeDialogMode.value = 'edit';
    editingCollege.value = college;
    collegeForm.clearErrors();
    collegeForm.code = college.code;
    collegeForm.name = college.name;
    collegeForm.short_name = college.short_name ?? '';
    collegeForm.description = college.description ?? '';
    collegeForm.status = college.status;
    collegeForm.counts_as_gened = !!college.counts_as_gened;
    collegeDialogVisible.value = true;
};

const closeCollegeDialog = () => {
    collegeDialogVisible.value = false;
    collegeForm.reset();
    collegeForm.clearErrors();
    editingCollege.value = null;
};

const onSaveCollege = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (collegeDialogMode.value === 'add') {
        collegeForm.post(route('colleges.store'), {
            preserveScroll: true,
            onSuccess: () => closeCollegeDialog(),
            onError,
        });
    } else {
        collegeForm.put(route('colleges.update', editingCollege.value.id), {
            preserveScroll: true,
            onSuccess: () => closeCollegeDialog(),
            onError,
        });
    }
};

// DELETE GATE — CollegeController::destroy() blocks this (errors.code)
// when the college still has active Departments/Faculty/Subjects/
// Rooms/Users attached — see that method's docblock. Confirmation
// here is just a heads-up; the follow-up alert on rejection names
// exactly what's still attached.
const onDeleteCollege = (college) => {
    Swal.fire({
        title: 'Delete this college?',
        html: `<p>${college.name} will be archived, not permanently deleted — it can be restored later.</p>
               <p style="margin-top:8px;">If it still has departments, faculty, subjects, rooms, or users attached, deletion will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('colleges.destroy', college.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't delete this college",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

const onRestoreCollege = (college) => {
    router.put(route('colleges.restore', college.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

// PERMANENT DELETE — only ever shown for a row already in the Deleted
// state (see the row Actions column below). CollegeController::forceDelete()
// re-checks the same attachment guard as the soft delete, since something
// could have been reassigned to this college's id since it was archived.
const onForceDeleteCollege = (college) => {
    Swal.fire({
        title: 'Permanently delete this college?',
        html: `<p><strong>${college.name}</strong> will be permanently removed. This cannot be undone.</p>
               <p style="margin-top:8px;">If it still has departments, faculty, subjects, rooms, or users attached, this will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete permanently',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('colleges.force-delete', college.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't permanently delete this college",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

/* ------------------------------------------------------------------ */
/* Departments tab                                                     */
/* ------------------------------------------------------------------ */

const departmentSearch = ref(props.filters.department_search ?? '');
const departmentLoading = ref(false);
let departmentSearchDebounce = null;

const reloadDepartments = (extra = {}) => {
    departmentLoading.value = true;

    router.get(
        route('academic-structure'),
        { department_search: departmentSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['departments'],
            onFinish: () => {
                departmentLoading.value = false;
            },
        },
    );
};

watch(departmentSearch, () => {
    clearTimeout(departmentSearchDebounce);
    departmentSearchDebounce = setTimeout(() => {
        reloadDepartments({ department_page: 1 });
    }, 350);
});

const onDepartmentPage = (event) => {
    reloadDepartments({ department_page: event.page + 1 });
};

const onRefreshDepartments = () => {
    reloadDepartments({ department_page: props.departments.current_page });
};

// Active colleges only, sorted alphabetically, for the dropdown.
const collegeOptions = computed(() =>
    [...props.activeColleges]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((college) => ({ label: college.name, value: college.id })),
);

const departmentDialogVisible = ref(false);
const departmentDialogMode = ref('add'); // 'add' | 'edit'
const editingDepartment = ref(null);

const departmentForm = useForm({
    college_id: null,
    code: '',
    name: '',
    short_name: '',
    description: '',
    status: 'Active',
});

const openAddDepartment = () => {
    departmentDialogMode.value = 'add';
    editingDepartment.value = null;
    departmentForm.reset();
    departmentForm.clearErrors();
    departmentDialogVisible.value = true;
};

const openEditDepartment = (department) => {
    departmentDialogMode.value = 'edit';
    editingDepartment.value = department;
    departmentForm.clearErrors();
    departmentForm.college_id = department.college_id;
    departmentForm.code = department.code;
    departmentForm.name = department.name;
    departmentForm.short_name = department.short_name ?? '';
    departmentForm.description = department.description ?? '';
    departmentForm.status = department.status;
    departmentDialogVisible.value = true;
};

const closeDepartmentDialog = () => {
    departmentDialogVisible.value = false;
    departmentForm.reset();
    departmentForm.clearErrors();
    editingDepartment.value = null;
};

const onSaveDepartment = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (departmentDialogMode.value === 'add') {
        departmentForm.post(route('departments.store'), {
            preserveScroll: true,
            onSuccess: () => closeDepartmentDialog(),
            onError,
        });
    } else {
        departmentForm.put(route('departments.update', editingDepartment.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDepartmentDialog(),
            onError,
        });
    }
};

// DELETE GATE — DepartmentController::destroy() blocks this
// (errors.code) when the department still has active Majors/Rooms/
// Users attached — see that method's docblock.
const onDeleteDepartment = (department) => {
    Swal.fire({
        title: 'Delete this department?',
        html: `<p>${department.name} will be archived, not permanently deleted — it can be restored later.</p>
               <p style="margin-top:8px;">If it still has majors, rooms, or users attached, deletion will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('departments.destroy', department.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't delete this department",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

const onRestoreDepartment = (department) => {
    router.put(route('departments.restore', department.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

// PERMANENT DELETE — only ever shown for a row already in the Deleted
// state (see the row Actions column below). DepartmentController::forceDelete()
// re-checks the same attachment guard as the soft delete.
const onForceDeleteDepartment = (department) => {
    Swal.fire({
        title: 'Permanently delete this department?',
        html: `<p><strong>${department.name}</strong> will be permanently removed. This cannot be undone.</p>
               <p style="margin-top:8px;">If it still has majors, rooms, or users attached, this will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete permanently',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('departments.force-delete', department.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't permanently delete this department",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

/* ------------------------------------------------------------------ */
/* Majors tab                                                          */
/* ------------------------------------------------------------------ */

const majorSearch = ref(props.filters.major_search ?? '');
const majorLoading = ref(false);
let majorSearchDebounce = null;

const reloadMajors = (extra = {}) => {
    majorLoading.value = true;

    router.get(
        route('academic-structure'),
        { major_search: majorSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['majors'],
            onFinish: () => {
                majorLoading.value = false;
            },
        },
    );
};

watch(majorSearch, () => {
    clearTimeout(majorSearchDebounce);
    majorSearchDebounce = setTimeout(() => {
        reloadMajors({ major_page: 1 });
    }, 350);
});

const onMajorPage = (event) => {
    reloadMajors({ major_page: event.page + 1 });
};

const onRefreshMajors = () => {
    reloadMajors({ major_page: props.majors.current_page });
};

// Departments dropdown for the Major dialog is filtered by the selected
// College, so it's scoped to whichever college the form currently holds.
const majorDepartmentOptions = computed(() => {
    if (!majorForm.college_id) {
        return [];
    }

    return [...props.activeDepartments]
        .filter((department) => department.college_id === majorForm.college_id)
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((department) => ({ label: department.name, value: department.id }));
});

const majorDialogVisible = ref(false);
const majorDialogMode = ref('add'); // 'add' | 'edit'
const editingMajor = ref(null);

const majorForm = useForm({
    college_id: null,
    department_id: null,
    code: '',
    name: '',
    short_name: '',
    years: 4,
    description: '',
    status: 'Active',
});

// Clearing the College resets the Department, and switching College also
// clears whichever Department no longer belongs to it.
watch(
    () => majorForm.college_id,
    (newCollegeId, oldCollegeId) => {
        if (newCollegeId !== oldCollegeId) {
            majorForm.department_id = null;
        }
    },
);

const openAddMajor = () => {
    majorDialogMode.value = 'add';
    editingMajor.value = null;
    majorForm.reset();
    majorForm.clearErrors();
    majorDialogVisible.value = true;
};

const openEditMajor = (major) => {
    majorDialogMode.value = 'edit';
    editingMajor.value = major;
    majorForm.clearErrors();
    majorForm.college_id = major.department?.college_id ?? null;
    majorForm.department_id = major.department_id;
    majorForm.code = major.code;
    majorForm.name = major.name;
    majorForm.short_name = major.short_name ?? '';
    majorForm.years = major.years;
    majorForm.description = major.description ?? '';
    majorForm.status = major.status;
    majorDialogVisible.value = true;
};

const closeMajorDialog = () => {
    majorDialogVisible.value = false;
    majorForm.reset();
    majorForm.clearErrors();
    editingMajor.value = null;
};

const onSaveMajor = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    // college_id only drives the cascading dropdown on the frontend;
    // the backend only needs department_id.
    const payload = {
        department_id: majorForm.department_id,
        code: majorForm.code,
        name: majorForm.name,
        short_name: majorForm.short_name,
        years: majorForm.years,
        description: majorForm.description,
        status: majorForm.status,
    };

    if (majorDialogMode.value === 'add') {
        majorForm.transform(() => payload).post(route('majors.store'), {
            preserveScroll: true,
            onSuccess: () => closeMajorDialog(),
            onError,
        });
    } else {
        majorForm.transform(() => payload).put(route('majors.update', editingMajor.value.id), {
            preserveScroll: true,
            onSuccess: () => closeMajorDialog(),
            onError,
        });
    }
};

// DELETE GATE — MajorController::destroy() blocks this (errors.code)
// when the major still has active Sections/Curriculums/Subjects
// attached — see that method's docblock.
const onDeleteMajor = (major) => {
    Swal.fire({
        title: 'Delete this major?',
        html: `<p>${major.name} will be archived, not permanently deleted — it can be restored later.</p>
               <p style="margin-top:8px;">If it still has sections, curriculums, or subjects attached, deletion will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('majors.destroy', major.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't delete this major",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

const onRestoreMajor = (major) => {
    router.put(route('majors.restore', major.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

// PERMANENT DELETE — only ever shown for a row already in the Deleted
// state (see the row Actions column below). MajorController::forceDelete()
// re-checks the same attachment guard as the soft delete.
const onForceDeleteMajor = (major) => {
    Swal.fire({
        title: 'Permanently delete this major?',
        html: `<p><strong>${major.name}</strong> will be permanently removed. This cannot be undone.</p>
               <p style="margin-top:8px;">If it still has sections, curriculums, or subjects attached, this will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete permanently',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('majors.force-delete', major.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't permanently delete this major",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};
</script>

<template>
    <Head title="Academic Structure" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Academic Structure</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <div class="neu-card neu-spotlight relative mb-6 overflow-hidden rounded-2xl">
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
                    Academic Structure
                    <InfoPopover
                        title="Academic Structure"
                        :paragraphs="[
                            'Defines the institution\'s Colleges, Departments, and Majors — the organizational hierarchy the rest of CLASSLY is built on.',
                        ]"
                        :bullets="[
                            'Colleges group Departments; Departments group Majors.',
                            'These records are referenced throughout the system — Subjects, Sections, and Faculty are all tied back to a College/Major.',
                            'Renaming or removing an entry here can affect any Subjects, Sections, or Faculty already linked to it.',
                        ]"
                    />
                </h1>
                <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    Manage colleges, departments, and majors.
                </p>
                </div>
            </div>

            <!-- Tabs -->
            <Tabs value="colleges">
                <TabList>
                    <Tab value="colleges">Colleges</Tab>
                    <Tab value="departments">Departments</Tab>
                    <Tab value="majors">Majors</Tab>
                </TabList>

                <TabPanels>
                    <!-- Colleges -->
                    <TabPanel value="colleges">
                        <div class="neu-card rounded-2xl transition-colors duration-300 mt-4">
                        <Card
                            class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                            :pt="{ body: { class: '!bg-transparent' } }"
                        >
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                            <InputText
                                                v-uppercase
                                                v-model="collegeSearch"
                                                placeholder="Search by code, name or short name"
                                                class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                                :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                            />
                                        </span>
                                    </template>
                                    <template #end>
                                        <div class="flex items-center gap-2">
                                            <Button
                                                icon="pi pi-refresh"
                                                severity="secondary"
                                                text
                                                class="neu-icon-well !rounded-full"
                                                :loading="collegeLoading"
                                                @click="onRefreshColleges"
                                                aria-label="Refresh"
                                            />
                                            <Button label="Add College" icon="pi pi-plus" @click="openAddCollege" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <!-- Colleges Table -->
                                <DataTable
                                    :value="colleges.data"
                                    :loading="collegeLoading"
                                    dataKey="id"
                                    class="neu-inset neu-table rounded-xl overflow-hidden"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    lazy
                                    paginator
                                    :rows="colleges.per_page"
                                    :totalRecords="colleges.total"
                                    :first="(colleges.current_page - 1) * colleges.per_page"
                                    @page="onCollegePage"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No colleges found.</p>
                                            <Button
                                                label="Add College"
                                                icon="pi pi-plus"
                                                class="mt-3"
                                                @click="openAddCollege"
                                            />
                                        </div>
                                    </template>

                                    <Column field="code" header="Code" style="width: 10rem" />
                                    <Column field="name" header="College Name" />
                                    <Column field="short_name" header="Short Name" style="width: 12rem">
                                        <template #body="{ data }">
                                            {{ data.short_name || '—' }}
                                        </template>
                                    </Column>
                                    <Column field="status" header="Status" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                            <Tag
                                                v-else
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                        </template>
                                    </Column>
                                    <Column field="counts_as_gened" header="GenEd Provider" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag
                                                v-if="data.counts_as_gened"
                                                value="Yes"
                                                severity="info"
                                            />
                                            <span v-else class="text-xs opacity-60">—</span>
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 13rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <template v-if="!data.deleted_at">
                                                    <Button
                                                        icon="pi pi-pencil"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        aria-label="Edit"
                                                        @click="openEditCollege(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        aria-label="Delete"
                                                        @click="onDeleteCollege(data)"
                                                    />
                                                </template>
                                                <template v-else>
                                                    <Button
                                                        icon="pi pi-refresh"
                                                        text
                                                        rounded
                                                        severity="success"
                                                        size="small"
                                                        label="Restore"
                                                        @click="onRestoreCollege(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        label="Delete Permanently"
                                                        @click="onForceDeleteCollege(data)"
                                                    />
                                                </template>
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- Departments -->
                    <TabPanel value="departments">
                        <div class="neu-card rounded-2xl transition-colors duration-300 mt-4">
                        <Card
                            class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                            :pt="{ body: { class: '!bg-transparent' } }"
                        >
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                            <InputText
                                                v-uppercase
                                                v-model="departmentSearch"
                                                placeholder="Search by code, name, short name or college"
                                                class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                                :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                            />
                                        </span>
                                    </template>
                                    <template #end>
                                        <div class="flex items-center gap-2">
                                            <Button
                                                icon="pi pi-refresh"
                                                severity="secondary"
                                                text
                                                class="neu-icon-well !rounded-full"
                                                :loading="departmentLoading"
                                                @click="onRefreshDepartments"
                                                aria-label="Refresh"
                                            />
                                            <Button label="Add Department" icon="pi pi-plus" @click="openAddDepartment" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <!-- Departments Table -->
                                <DataTable
                                    :value="departments.data"
                                    :loading="departmentLoading"
                                    dataKey="id"
                                    class="neu-inset neu-table rounded-xl overflow-hidden"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    lazy
                                    paginator
                                    :rows="departments.per_page"
                                    :totalRecords="departments.total"
                                    :first="(departments.current_page - 1) * departments.per_page"
                                    @page="onDepartmentPage"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No departments found.</p>
                                            <Button
                                                label="Add Department"
                                                icon="pi pi-plus"
                                                class="mt-3"
                                                @click="openAddDepartment"
                                            />
                                        </div>
                                    </template>

                                    <Column field="code" header="Department Code" style="width: 10rem" />
                                    <Column field="name" header="Department Name" />
                                    <Column header="College" style="width: 14rem">
                                        <template #body="{ data }">
                                            {{ data.college?.name || '—' }}
                                        </template>
                                    </Column>
                                    <Column field="short_name" header="Short Name" style="width: 12rem">
                                        <template #body="{ data }">
                                            {{ data.short_name || '—' }}
                                        </template>
                                    </Column>
                                    <Column field="status" header="Status" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                            <Tag
                                                v-else
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 13rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <template v-if="!data.deleted_at">
                                                    <Button
                                                        icon="pi pi-pencil"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        aria-label="Edit"
                                                        @click="openEditDepartment(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        aria-label="Delete"
                                                        @click="onDeleteDepartment(data)"
                                                    />
                                                </template>
                                                <template v-else>
                                                    <Button
                                                        icon="pi pi-refresh"
                                                        text
                                                        rounded
                                                        severity="success"
                                                        size="small"
                                                        label="Restore"
                                                        @click="onRestoreDepartment(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        label="Delete Permanently"
                                                        @click="onForceDeleteDepartment(data)"
                                                    />
                                                </template>
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- Majors -->
                    <TabPanel value="majors">
                        <div class="neu-card rounded-2xl transition-colors duration-300 mt-4">
                        <Card
                            class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                            :pt="{ body: { class: '!bg-transparent' } }"
                        >
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                            <InputText
                                                v-uppercase
                                                v-model="majorSearch"
                                                placeholder="Search by code, name, department or college"
                                                class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                                :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                            />
                                        </span>
                                    </template>
                                    <template #end>
                                        <div class="flex items-center gap-2">
                                            <Button
                                                icon="pi pi-refresh"
                                                severity="secondary"
                                                text
                                                class="neu-icon-well !rounded-full"
                                                :loading="majorLoading"
                                                @click="onRefreshMajors"
                                                aria-label="Refresh"
                                            />
                                            <Button label="Add Major" icon="pi pi-plus" @click="openAddMajor" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <!-- Majors Table -->
                                <DataTable
                                    :value="majors.data"
                                    :loading="majorLoading"
                                    dataKey="id"
                                    class="neu-inset neu-table rounded-xl overflow-hidden"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    lazy
                                    paginator
                                    :rows="majors.per_page"
                                    :totalRecords="majors.total"
                                    :first="(majors.current_page - 1) * majors.per_page"
                                    @page="onMajorPage"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No majors found.</p>
                                            <Button
                                                label="Add Major"
                                                icon="pi pi-plus"
                                                class="mt-3"
                                                @click="openAddMajor"
                                            />
                                        </div>
                                    </template>

                                    <Column field="code" header="Major Code" style="width: 10rem" />
                                    <Column field="name" header="Major Name" />
                                    <Column header="College" style="width: 12rem">
                                        <template #body="{ data }">
                                            {{ data.department?.college?.name || '—' }}
                                        </template>
                                    </Column>
                                    <Column header="Department" style="width: 14rem">
                                        <template #body="{ data }">
                                            {{ data.department?.name || '—' }}
                                        </template>
                                    </Column>
                                    <Column field="years" header="Years" style="width: 7rem" />
                                    <Column field="status" header="Status" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                            <Tag
                                                v-else
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 13rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <template v-if="!data.deleted_at">
                                                    <Button
                                                        icon="pi pi-pencil"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        aria-label="Edit"
                                                        @click="openEditMajor(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        aria-label="Delete"
                                                        @click="onDeleteMajor(data)"
                                                    />
                                                </template>
                                                <template v-else>
                                                    <Button
                                                        icon="pi pi-refresh"
                                                        text
                                                        rounded
                                                        severity="success"
                                                        size="small"
                                                        label="Restore"
                                                        @click="onRestoreMajor(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        label="Delete Permanently"
                                                        @click="onForceDeleteMajor(data)"
                                                    />
                                                </template>
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>

        <!-- Add / Edit College Modal -->
        <Dialog
            v-model:visible="collegeDialogVisible"
            modal
            :style="{ width: '600px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ collegeDialogMode === 'add' ? 'Add College' : 'Edit College' }}
                </span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onSaveCollege">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="collegeCode"
                            v-model="collegeForm.code"
                            class="w-full"
                            maxlength="20"
                            autocomplete="off"
                            :invalid="!!collegeForm.errors.code"
                        />
                        <label for="collegeCode">College Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="collegeStatus"
                            v-model="collegeForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!collegeForm.errors.status"
                        />
                        <label for="collegeStatus">Status *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="collegeForm.errors.code" class="text-red-500">{{ collegeForm.errors.code }}</small>
                    <small v-if="collegeForm.errors.status" class="text-red-500">{{ collegeForm.errors.status }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="collegeName"
                            v-model="collegeForm.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            :invalid="!!collegeForm.errors.name"
                        />
                        <label for="collegeName">College Name *</label>
                    </FloatLabel>
                    <small v-if="collegeForm.errors.name" class="text-red-500 -mt-4">{{ collegeForm.errors.name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="collegeShortName"
                            v-model="collegeForm.short_name"
                            class="w-full"
                            maxlength="100"
                            autocomplete="off"
                            :invalid="!!collegeForm.errors.short_name"
                        />
                        <label for="collegeShortName">Short Name</label>
                    </FloatLabel>
                    <small v-if="collegeForm.errors.short_name" class="text-red-500 -mt-4">{{ collegeForm.errors.short_name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="collegeDescription"
                            v-model="collegeForm.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            :invalid="!!collegeForm.errors.description"
                        />
                        <label for="collegeDescription">Description</label>
                    </FloatLabel>
                    <small v-if="collegeForm.errors.description" class="text-red-500 -mt-4">{{ collegeForm.errors.description }}</small>
                </div>

                <!-- Counts as GenEd provider -->
                <div class="flex items-start gap-2 mt-5">
                    <Checkbox
                        v-model="collegeForm.counts_as_gened"
                        inputId="collegeCountsAsGened"
                        binary
                    />
                    <label for="collegeCountsAsGened" class="text-sm" :class="isDark ? 'text-slate-200' : 'text-[#1E293B]'">
                        Counts as General Education provider
                        <span class="block text-xs opacity-70">
                            Faculty in this College will also be recommended and matched for General Education / Minor subjects, in addition to their own College's subjects.
                        </span>
                    </label>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="collegeForm.processing" @click="closeCollegeDialog" />
                <Button label="Save" icon="pi pi-check" :loading="collegeForm.processing" @click="onSaveCollege" />
            </template>
        </Dialog>

        <!-- Add / Edit Department Modal -->
        <Dialog
            v-model:visible="departmentDialogVisible"
            modal
            :style="{ width: '600px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ departmentDialogMode === 'add' ? 'Add Department' : 'Edit Department' }}
                </span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onSaveDepartment">
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="departmentCollege"
                            v-model="departmentForm.college_id"
                            :options="collegeOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            class="w-full"
                            :invalid="!!departmentForm.errors.college_id"
                        />
                        <label for="departmentCollege">College *</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.college_id" class="text-red-500 -mt-4">{{ departmentForm.errors.college_id }}</small>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="departmentCode"
                            v-model="departmentForm.code"
                            class="w-full"
                            maxlength="20"
                            autocomplete="off"
                            :invalid="!!departmentForm.errors.code"
                        />
                        <label for="departmentCode">Department Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="departmentStatus"
                            v-model="departmentForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!departmentForm.errors.status"
                        />
                        <label for="departmentStatus">Status *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="departmentForm.errors.code" class="text-red-500">{{ departmentForm.errors.code }}</small>
                    <small v-if="departmentForm.errors.status" class="text-red-500">{{ departmentForm.errors.status }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="departmentName"
                            v-model="departmentForm.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            :invalid="!!departmentForm.errors.name"
                        />
                        <label for="departmentName">Department Name *</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.name" class="text-red-500 -mt-4">{{ departmentForm.errors.name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="departmentShortName"
                            v-model="departmentForm.short_name"
                            class="w-full"
                            maxlength="100"
                            autocomplete="off"
                            :invalid="!!departmentForm.errors.short_name"
                        />
                        <label for="departmentShortName">Short Name</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.short_name" class="text-red-500 -mt-4">{{ departmentForm.errors.short_name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="departmentDescription"
                            v-model="departmentForm.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            :invalid="!!departmentForm.errors.description"
                        />
                        <label for="departmentDescription">Description</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.description" class="text-red-500 -mt-4">{{ departmentForm.errors.description }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="departmentForm.processing" @click="closeDepartmentDialog" />
                <Button label="Save" icon="pi pi-check" :loading="departmentForm.processing" @click="onSaveDepartment" />
            </template>
        </Dialog>

        <!-- Add / Edit Major Modal -->
        <Dialog
            v-model:visible="majorDialogVisible"
            modal
            :style="{ width: '600px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ majorDialogMode === 'add' ? 'Add Major' : 'Edit Major' }}
                </span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onSaveMajor">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="majorCollege"
                            v-model="majorForm.college_id"
                            :options="collegeOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            class="w-full"
                        />
                        <label for="majorCollege">College *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="majorDepartment"
                            v-model="majorForm.department_id"
                            :options="majorDepartmentOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            :disabled="!majorForm.college_id"
                            class="w-full"
                            :invalid="!!majorForm.errors.department_id"
                        />
                        <label for="majorDepartment">Department *</label>
                    </FloatLabel>
                </div>
                <small v-if="majorForm.errors.department_id" class="text-red-500 block mt-1">{{ majorForm.errors.department_id }}</small>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="majorCode"
                            v-model="majorForm.code"
                            class="w-full"
                            maxlength="20"
                            autocomplete="off"
                            :invalid="!!majorForm.errors.code"
                        />
                        <label for="majorCode">Major Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="majorStatus"
                            v-model="majorForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!majorForm.errors.status"
                        />
                        <label for="majorStatus">Status *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="majorForm.errors.code" class="text-red-500">{{ majorForm.errors.code }}</small>
                    <small v-if="majorForm.errors.status" class="text-red-500">{{ majorForm.errors.status }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="majorName"
                            v-model="majorForm.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            :invalid="!!majorForm.errors.name"
                        />
                        <label for="majorName">Major Name *</label>
                    </FloatLabel>
                    <small v-if="majorForm.errors.name" class="text-red-500 -mt-4">{{ majorForm.errors.name }}</small>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="majorShortName"
                            v-model="majorForm.short_name"
                            class="w-full"
                            maxlength="100"
                            autocomplete="off"
                            :invalid="!!majorForm.errors.short_name"
                        />
                        <label for="majorShortName">Short Name</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="majorYears"
                            v-model.number="majorForm.years"
                            type="number"
                            :min="1"
                            :max="6"
                            class="w-full"
                            :invalid="!!majorForm.errors.years"
                        />
                        <label for="majorYears">Years *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="majorForm.errors.short_name" class="text-red-500">{{ majorForm.errors.short_name }}</small>
                    <small v-if="majorForm.errors.years" class="text-red-500">{{ majorForm.errors.years }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="majorDescription"
                            v-model="majorForm.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            :invalid="!!majorForm.errors.description"
                        />
                        <label for="majorDescription">Description</label>
                    </FloatLabel>
                    <small v-if="majorForm.errors.description" class="text-red-500 -mt-4">{{ majorForm.errors.description }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="majorForm.processing" @click="closeMajorDialog" />
                <Button label="Save" icon="pi pi-check" :loading="majorForm.processing" @click="onSaveMajor" />
            </template>
        </Dialog>
    </AppLayout>
</template>
<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page — including inside Dialogs, which PrimeVue
   teleports to <body> but keeps as one contiguous subtree, so these
   descendant selectors still reach them. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext::placeholder),
.dark-scope :deep(.p-select-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.pi) { color: inherit; }

:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

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
:global(.dark-scope.p-dialog .p-floatlabel label) { color: #94A3B8 !important; background: #0F1730 !important; }
:global(.dark-scope.p-dialog .p-select-overlay),
:global(.dark-scope.p-dialog .p-multiselect-overlay) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-option),
:global(.dark-scope.p-dialog .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.dark-scope.p-dialog .p-select-option:hover),
:global(.dark-scope.p-dialog .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F1F5F9 !important; padding: 0 !important; }
.dark-scope :deep(.p-tabpanel) { background: transparent !important; color: #F1F5F9 !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

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

/* Buttons: text/secondary icon buttons (edit/delete/refresh) default to
   low-contrast grays in the light theme — brighten them for dark mode. */
.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-text.p-button-success) { color: #6EE7B7 !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>