<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Password from 'primevue/password';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Divider from 'primevue/divider';
import FloatLabel from 'primevue/floatlabel';
import Checkbox from 'primevue/checkbox';
import Toast from 'primevue/toast';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Menu from 'primevue/menu';
import Swal from 'sweetalert2';
import InfoPopover from '@/Components/InfoPopover.vue';
import PasswordRequirementsChecklist from '@/Components/PasswordRequirementsChecklist.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    users: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    passwordPolicy: { type: Object, default: () => ({ minLength: 8, requireUppercase: false, requireNumber: false, requireSymbol: false }) },
});

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message
// (e.g. right after Create/Edit User actually saves).
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

const search = ref('');

// Only Administrators get the "Manage Account" tab — everyone else on
// this page (which is itself Administrator-only per the sidebar) still
// only sees the Users tab.
const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));
// Lets a notification (e.g. "Password Change Required") deep-link
// straight into the Manage Account tab via ?tab=account.
const activeTab = ref(new URLSearchParams(window.location.search).get('tab') ?? 'users');

const roleOptions = [
    { label: 'Administrator', value: 'Administrator' },
    { label: 'Registrar', value: 'Registrar' },
    { label: 'Assistant Dean', value: 'Assistant Dean' },
    { label: 'Dean', value: 'Dean' },
    { label: 'OIC', value: 'OIC' },
];

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const collegeOptions = computed(() =>
    props.colleges.map((college) => ({ label: college.name, value: college.id })),
);

// Only show departments belonging to the selected college.
const departmentOptionsFor = (collegeId) =>
    props.departments
        .filter((department) => department.college_id === collegeId)
        .map((department) => ({ label: department.name, value: department.id }));

/* ------------------------------------------------------------------ */
/* Add User modal                                                      */
/* ------------------------------------------------------------------ */

const addUserVisible = ref(false);

const departmentOptions = computed(() => departmentOptionsFor(form.college_id));

const form = useForm({
    role: null,
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    email: '',
    password: '',
    password_confirmation: '',
    college_id: null,
    department_ids: [],
    oversees_all_departments: false,
    is_gened_assistant_dean: false,
    status: 'Active',
});

// Dean oversees the whole college — only College is needed.
// OIC may cover any subset of departments — College + Department(s) needed.
const rolesRequiringCollege = ['Dean', 'OIC'];
const rolesRequiringDepartment = ['OIC'];
// Dean/OIC only — lets one of them ALSO carry GenEd/Minor authority
// across every college (on top of, not instead of, their own College
// scope). Assistant Dean is excluded since that role already has this
// reach by default.
const rolesEligibleForGenedFlag = ['Dean', 'OIC'];

const showCollege = computed(() => rolesRequiringCollege.includes(form.role));
const showDepartment = computed(
    () => rolesRequiringDepartment.includes(form.role) && !form.oversees_all_departments,
);
const showGenedFlag = computed(() => rolesEligibleForGenedFlag.includes(form.role));

// Clear department selections if the college changes so a stale
// mismatch (departments from a different college) can't be submitted.
watch(
    () => form.college_id,
    () => {
        form.department_ids = [];
    },
);

// Clear college/department entirely if role no longer requires them.
watch(
    () => form.role,
    () => {
        if (!showCollege.value) {
            form.college_id = null;
        }
        if (!rolesRequiringDepartment.includes(form.role)) {
            form.department_ids = [];
            form.oversees_all_departments = false;
        }
        if (!showGenedFlag.value) {
            form.is_gened_assistant_dean = false;
        }
    },
);

// If the admin checks "oversees all departments", clear the specific picks.
watch(
    () => form.oversees_all_departments,
    (checked) => {
        if (checked) {
            form.department_ids = [];
        }
    },
);

const openAddUser = () => {
    form.reset();
    form.clearErrors();
    addUserVisible.value = true;
};

const closeAddUser = () => {
    addUserVisible.value = false;
    form.reset();
    form.clearErrors();
};

const onCreateUser = () => {
    form.post(route('users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeAddUser();
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

/* ------------------------------------------------------------------ */
/* Edit User modal                                                     */
/* ------------------------------------------------------------------ */

const editUserVisible = ref(false);
const editingUserId = ref(null);

const editForm = useForm({
    role: null,
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    email: '',
    password: '',
    password_confirmation: '',
    college_id: null,
    department_ids: [],
    oversees_all_departments: false,
    is_gened_assistant_dean: false,
    status: 'Active',
});

const editDepartmentOptions = computed(() => departmentOptionsFor(editForm.college_id));
const editShowCollege = computed(() => rolesRequiringCollege.includes(editForm.role));
const editShowDepartment = computed(
    () => rolesRequiringDepartment.includes(editForm.role) && !editForm.oversees_all_departments,
);
const editShowGenedFlag = computed(() => rolesEligibleForGenedFlag.includes(editForm.role));

// Clear the flag if the role is edited to one no longer eligible for it.
watch(
    () => editForm.role,
    () => {
        if (!editShowGenedFlag.value) {
            editForm.is_gened_assistant_dean = false;
        }
    },
);

const openEditUser = (user) => {
    editingUserId.value = user.id;
    editForm.reset();
    editForm.clearErrors();
    editForm.role = user.role;
    editForm.first_name = user.firstName ?? '';
    editForm.middle_name = user.middleName ?? '';
    editForm.last_name = user.lastName ?? '';
    editForm.suffix = user.suffix ?? '';
    editForm.email = user.email;
    editForm.password = '';
    editForm.password_confirmation = '';
    editForm.college_id = user.collegeId ?? null;
    editForm.department_ids = user.departmentIds ?? [];
    editForm.oversees_all_departments =
        user.role === 'OIC' && user.department === 'All Departments';
    editForm.is_gened_assistant_dean = user.isGenedAssistantDean ?? false;
    editForm.status = user.status;
    editUserVisible.value = true;
};

const closeEditUser = () => {
    editUserVisible.value = false;
    editingUserId.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const onUpdateUser = () => {
    if (!editingUserId.value) return;

    editForm.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('users.update', editingUserId.value), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditUser();
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

/* ------------------------------------------------------------------ */
/* Row actions menu — Deactivate/Activate, Delete                       */
/* ------------------------------------------------------------------ */

const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

const rowMenuRefs = ref({});
const setRowMenuRef = (userId) => (el) => {
    rowMenuRefs.value[userId] = el;
};
const toggleRowMenu = (userId, event) => rowMenuRefs.value[userId]?.toggle(event);

const rowMenuItemsFor = (user) => {
    const isSelf = user.id === currentUserId.value;

    return [
        {
            label: user.status === 'Active' ? 'Deactivate' : 'Activate',
            icon: user.status === 'Active' ? 'pi pi-ban' : 'pi pi-check-circle',
            disabled: isSelf,
            command: () => onToggleStatus(user),
        },
        {
            label: user.mustChangePassword ? 'Cancel required password change' : 'Require password change on next login',
            icon: 'pi pi-key',
            disabled: isSelf,
            command: () => onToggleMustChangePassword(user),
        },
        {
            separator: true,
        },
        {
            label: 'Delete',
            icon: 'pi pi-trash',
            class: 'text-red-500',
            disabled: isSelf,
            command: () => onDeleteUser(user),
        },
    ];
};

const mustChangePasswordForm = useForm({});
const onToggleMustChangePassword = async (user) => {
    const requiring = !user.mustChangePassword;

    const result = await Swal.fire({
        title: requiring ? 'Require a password change?' : 'Cancel the required password change?',
        text: requiring
            ? `${user.fullName} will be prompted to set a new password the next time they log in. Their access is not affected until then.`
            : `${user.fullName} will no longer be prompted to change their password on next login.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: requiring ? 'Require change' : 'Cancel requirement',
        confirmButtonColor: requiring ? '#D97706' : '#64748B',
        cancelButtonText: 'Close',
    });

    if (!result.isConfirmed) return;

    mustChangePasswordForm.patch(route('users.must-change-password', user.id), { preserveScroll: true });
};

const statusForm = useForm({});
const onToggleStatus = async (user) => {
    const activating = user.status !== 'Active';

    const result = await Swal.fire({
        title: activating ? 'Activate this account?' : 'Deactivate this account?',
        text: activating
            ? `${user.fullName} will regain access to the system.`
            : `${user.fullName} will no longer be able to log in.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: activating ? 'Activate' : 'Deactivate',
        confirmButtonColor: activating ? '#16A34A' : '#DC2626',
        cancelButtonText: 'Cancel',
    });

    if (!result.isConfirmed) return;

    statusForm.patch(route('users.status', user.id), { preserveScroll: true });
};

const deleteUserForm = useForm({});
const onDeleteUser = async (user) => {
    const result = await Swal.fire({
        title: 'Delete this account?',
        text: `${user.fullName} will be permanently removed. This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#DC2626',
        cancelButtonText: 'Cancel',
    });

    if (!result.isConfirmed) return;

    deleteUserForm.delete(route('users.destroy', user.id), { preserveScroll: true });
};

/* ------------------------------------------------------------------ */
/* Manage Account tab (Administrator only)                             */
/* ------------------------------------------------------------------ */

const accountForm = useForm({
    first_name: page.props.auth?.user?.first_name ?? '',
    middle_name: page.props.auth?.user?.middle_name ?? '',
    last_name: page.props.auth?.user?.last_name ?? '',
    suffix: page.props.auth?.user?.suffix ?? '',
    email: page.props.auth?.user?.email ?? '',
    photo: null,
    remove_photo: false,
    password: '',
    password_confirmation: '',
});

// Local preview URL for a newly-picked (not yet saved) photo file —
// separate from the saved auth.user.profile_photo_url so the avatar
// updates instantly on selection without waiting on a round trip.
const photoPreviewUrl = ref(null);
const photoInput = ref(null);
const onPhotoSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    accountForm.photo = file;
    accountForm.remove_photo = false;
    photoPreviewUrl.value = URL.createObjectURL(file);
};
const onRemovePhoto = () => {
    accountForm.photo = null;
    accountForm.remove_photo = true;
    photoPreviewUrl.value = null;
    if (photoInput.value) photoInput.value.value = '';
};
const accountAvatarUrl = computed(() => (accountForm.remove_photo ? null : (photoPreviewUrl.value ?? page.props.auth?.user?.profile_photo_url ?? null)));
const accountInitials = computed(() => `${accountForm.first_name?.charAt(0) ?? ''}${accountForm.last_name?.charAt(0) ?? ''}`.toUpperCase());

const onUpdateAccount = () => {
    accountForm.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('account.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            accountForm.password = '';
            accountForm.password_confirmation = '';
            accountForm.photo = null;
            accountForm.remove_photo = false;
            photoPreviewUrl.value = null;
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
</script>

<template>
    <Head title="User Management" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">User Management</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <div class="neu-card neu-spotlight relative mb-8 overflow-hidden rounded-2xl">
                <!-- subtle blue/red gradient wash + decorative shapes -->
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                    <div
                        class="absolute inset-0"
                        :class="isDark
                            ? 'bg-gradient-to-br from-blue-500/10 via-transparent to-red-500/10'
                            : 'bg-gradient-to-br from-blue-50 via-transparent to-red-50/60'"
                    ></div>
                    <div
                        class="absolute -right-10 -top-16 h-44 w-44 rounded-full bg-gradient-to-br"
                        :class="isDark ? 'from-blue-500/20 via-blue-500/5 to-transparent' : 'from-blue-200/80 via-blue-100/40 to-transparent'"
                    ></div>
                    <div
                        class="absolute -bottom-20 right-16 h-40 w-40 rounded-full bg-gradient-to-tr"
                        :class="isDark ? 'from-red-500/20 via-red-500/5 to-transparent' : 'from-red-200/70 via-red-100/30 to-transparent'"
                    ></div>
                    <div
                        class="absolute -left-10 bottom-0 h-28 w-28 rounded-full bg-gradient-to-tr border-4"
                        :class="isDark ? 'from-blue-500/10 to-transparent border-blue-400/10' : 'from-blue-100/60 to-transparent border-blue-100'"
                    ></div>
                    <div
                        class="absolute left-[38%] top-0 h-3 w-3 rounded-full bg-gradient-to-br"
                        :class="isDark ? 'from-red-400/50 to-blue-400/30' : 'from-red-400/80 to-blue-300/60'"
                    ></div>
                    <div
                        class="absolute left-[55%] bottom-4 h-20 w-20 rounded-full bg-gradient-to-r blur-xl"
                        :class="isDark ? 'from-blue-500/10 to-red-500/10' : 'from-blue-200/40 to-red-200/40'"
                    ></div>
                </div>
                <div class="relative rounded-2xl p-6 transition-colors duration-300">
                    <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                        User Management
                        <InfoPopover
                            title="User Management"
                            :paragraphs="[
                                'Manages system accounts and role assignments — who can log in to CLASSLY and what they\'re allowed to do.',
                            ]"
                            :bullets="[
                                'A user\'s role (Admin, Registrar, Dean/OIC, Faculty, etc.) determines which pages and actions they can access.',
                                'Deactivating an account blocks login without deleting their records or history.',
                                'College-scoped roles (e.g. Dean) only see and manage data for their assigned college.',
                            ]"
                        />
                    </h1>
                    <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                        Manage system accounts and role assignments.
                    </p>
                </div>
            </div>

            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab value="users">Users</Tab>
                    <Tab v-if="isAdministrator" value="account">Manage Account</Tab>
                </TabList>

                <TabPanels>
                    <!-- Users Tab -->
                    <TabPanel value="users">
                        <div class="neu-card rounded-2xl transition-colors duration-300">
                        <Card
                            class="!rounded-2xl !bg-transparent !border-0 !shadow-none max-w-full"
                            :pt="{ body: { class: '!bg-transparent' } }"
                        >
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                            <InputText
                                                v-model="search"
                                                placeholder="Search by name or email"
                                                class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                                :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                            />
                                        </span>
                                    </template>
                                    <template #end>
                                        <Button label="Add User" icon="pi pi-plus" @click="openAddUser" />
                                    </template>
                                </Toolbar>

                                <!-- Users Table -->
                                <!-- PERFORMANCE — paginate large lists. This
                                     table previously rendered every user row
                                     into the DOM at once; :paginator below
                                     caps rendering to one page at a time
                                     (client-side, since filtering here is
                                     already client-side via globalFilterFields). -->
                                <DataTable
                                    :value="users"
                                    class="neu-inset neu-table rounded-xl overflow-hidden"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    :globalFilterFields="['fullName', 'email']"
                                    :filters="{ global: { value: search, matchMode: 'contains' } }"
                                    paginator
                                    :rows="20"
                                    :rowsPerPageOptions="[10, 20, 50, 100]"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No users found.</p>
                                            <p class="text-sm text-slate-400 mt-1">Create your first account.</p>
                                        </div>
                                    </template>

                                    <Column field="fullName" header="Full Name" />
                                    <Column field="email" header="Email" />
                                    <Column field="role" header="Role" />
                                    <Column field="college" header="College" />
                                    <Column field="department" header="Department" />
                                    <Column field="status" header="Status">
                                        <template #body="{ data }">
                                            <Tag
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                            <Tag
                                                v-if="data.mustChangePassword"
                                                value="Password change required"
                                                severity="warn"
                                                class="ml-1"
                                            />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 9rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <Button
                                                    icon="pi pi-pencil"
                                                    text
                                                    rounded
                                                    severity="secondary"
                                                    size="small"
                                                    @click="openEditUser(data)"
                                                />
                                                <Button
                                                    icon="pi pi-ellipsis-v"
                                                    text
                                                    rounded
                                                    severity="secondary"
                                                    size="small"
                                                    @click="toggleRowMenu(data.id, $event)"
                                                />
                                                <Menu :ref="setRowMenuRef(data.id)" :model="rowMenuItemsFor(data)" :popup="true" />
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- Manage Account Tab (Administrator only) -->
                    <TabPanel v-if="isAdministrator" value="account">
                        <div class="neu-card rounded-2xl transition-colors duration-300">
                        <Card
                            class="!rounded-2xl !bg-transparent !border-0 !shadow-none max-w-2xl"
                            :pt="{ body: { class: '!bg-transparent' } }"
                        >
                            <template #content>
                                <h2 class="text-lg font-bold mb-1" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Manage Account</h2>
                                <p class="text-sm mb-5" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                    Update your own Administrator profile and password.
                                </p>

                                <form class="pt-1 neu-form" autocomplete="off" @submit.prevent="onUpdateAccount">
                                    <div class="flex items-center gap-4 mb-6">
                                        <div class="relative shrink-0">
                                            <img v-if="accountAvatarUrl" :src="accountAvatarUrl" loading="lazy" decoding="async" class="h-16 w-16 rounded-full object-cover border border-slate-200" alt="Profile photo" />
                                            <span v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-200 text-lg font-bold text-slate-600">{{ accountInitials }}</span>
                                        </div>
                                        <div>
                                            <input ref="photoInput" type="file" accept="image/*" class="hidden" @change="onPhotoSelected" />
                                            <div class="flex gap-2">
                                                <Button type="button" label="Upload Photo" icon="pi pi-camera" size="small" outlined @click="photoInput?.click()" />
                                                <Button v-if="accountAvatarUrl" type="button" label="Remove" icon="pi pi-trash" size="small" text severity="danger" @click="onRemovePhoto" />
                                            </div>
                                            <p class="text-xs mt-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">JPG or PNG, up to 2MB.</p>
                                            <small v-if="accountForm.errors.photo" class="text-red-500 block">{{ accountForm.errors.photo }}</small>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accFirstName"
                                                v-model="accountForm.first_name"
                                                class="w-full"
                                                autocomplete="off"
                                                :invalid="!!accountForm.errors.first_name"
                                            />
                                            <label for="accFirstName">First Name *</label>
                                        </FloatLabel>

                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accMiddleName"
                                                v-model="accountForm.middle_name"
                                                class="w-full"
                                                autocomplete="off"
                                            />
                                            <label for="accMiddleName">Middle Name</label>
                                        </FloatLabel>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accLastName"
                                                v-model="accountForm.last_name"
                                                class="w-full"
                                                autocomplete="off"
                                                :invalid="!!accountForm.errors.last_name"
                                            />
                                            <label for="accLastName">Last Name *</label>
                                        </FloatLabel>

                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accSuffix"
                                                v-model="accountForm.suffix"
                                                class="w-full"
                                                autocomplete="off"
                                            />
                                            <label for="accSuffix">Suffix</label>
                                        </FloatLabel>
                                    </div>

                                    <Divider class="!my-5" />

                                    <div class="grid grid-cols-1 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accEmail"
                                                v-model="accountForm.email"
                                                type="email"
                                                class="w-full"
                                                autocomplete="off"
                                                :invalid="!!accountForm.errors.email"
                                            />
                                            <label for="accEmail">Email *</label>
                                        </FloatLabel>
                                        <small v-if="accountForm.errors.email" class="text-red-500 -mt-4">{{ accountForm.errors.email }}</small>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <Password
                                                id="accPassword"
                                                v-model="accountForm.password"
                                                toggleMask
                                                :feedback="true"
                                                inputClass="w-full"
                                                class="w-full"
                                                autocomplete="new-password"
                                                :invalid="!!accountForm.errors.password"
                                            />
                                            <label for="accPassword">New Password</label>
                                        </FloatLabel>

                                        <FloatLabel variant="on">
                                            <Password
                                                id="accPasswordConfirm"
                                                v-model="accountForm.password_confirmation"
                                                toggleMask
                                                :feedback="false"
                                                inputClass="w-full"
                                                class="w-full"
                                                autocomplete="new-password"
                                                :invalid="!!accountForm.errors.password"
                                            />
                                            <label for="accPasswordConfirm">Confirm New Password</label>
                                        </FloatLabel>
                                    </div>
                                    <PasswordRequirementsChecklist
                                        :password="accountForm.password"
                                        :policy="passwordPolicy"
                                        :is-dark="isDark"
                                    />
                                    <small v-if="accountForm.errors.password" class="text-red-500">{{ accountForm.errors.password }}</small>
                                    <p class="text-xs text-slate-400 mt-1">Leave blank to keep your current password.</p>

                                    <div class="flex justify-end mt-6">
                                        <Button
                                            type="submit"
                                            label="Save Changes"
                                            icon="pi pi-check"
                                            :loading="accountForm.processing"
                                        />
                                    </div>
                                </form>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>

        <!-- Add User Modal -->
        <Dialog
            v-model:visible="addUserVisible"
            modal
            :style="{ width: '800px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
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
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Create System User</span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onCreateUser">
                <!-- Role -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="role"
                            v-model="form.role"
                            :options="roleOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.role"
                        />
                        <label for="role">Role *</label>
                    </FloatLabel>
                </div>
                <small v-if="form.errors.role" class="text-red-500">{{ form.errors.role }}</small>

                <Divider class="!my-5" />

                <!-- First Name / Middle Name -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="firstName"
                            v-model="form.first_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.first_name"
                        />
                        <label for="firstName">First Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="middleName"
                            v-model="form.middle_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="middleName">Middle Name</label>
                    </FloatLabel>
                </div>

                <!-- Last Name / Suffix -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="lastName"
                            v-model="form.last_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.last_name"
                        />
                        <label for="lastName">Last Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="suffix"
                            v-model="form.suffix"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="suffix">Suffix</label>
                    </FloatLabel>
                </div>

                <Divider class="!my-5" />

                <!-- Email -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.email"
                        />
                        <label for="email">Email *</label>
                    </FloatLabel>
                    <small v-if="form.errors.email" class="text-red-500 -mt-4">{{ form.errors.email }}</small>
                </div>

                <!-- Password / Confirm Password -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Password
                            id="password"
                            v-model="form.password"
                            toggleMask
                            :feedback="true"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!form.errors.password"
                        />
                        <label for="password">Password *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Password
                            id="confirmPassword"
                            v-model="form.password_confirmation"
                            toggleMask
                            :feedback="false"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!form.errors.password"
                        />
                        <label for="confirmPassword">Confirm Password *</label>
                    </FloatLabel>
                </div>
                <small v-if="form.errors.password" class="text-red-500">{{ form.errors.password }}</small>

                <template v-if="showCollege">
                    <Divider class="!my-5" />

                    <!-- College (Dean / OIC) / Department (OIC only) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <FloatLabel variant="on">
                            <Select
                                id="college"
                                v-model="form.college_id"
                                :options="collegeOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                                :invalid="!!form.errors.college_id"
                            />
                            <label for="college">College *</label>
                        </FloatLabel>

                        <FloatLabel v-if="showDepartment" variant="on">
                            <MultiSelect
                                id="department"
                                v-model="form.department_ids"
                                :options="departmentOptions"
                                optionLabel="label"
                                optionValue="value"
                                display="chip"
                                class="w-full"
                                :disabled="!form.college_id"
                                :invalid="!!form.errors.department_ids"
                            />
                            <label for="department">Department(s) *</label>
                        </FloatLabel>
                    </div>
                    <small v-if="form.errors.department_ids" class="text-red-500">{{ form.errors.department_ids }}</small>

                    <!-- OIC scope: whole college vs a specific subset of departments -->
                    <div v-if="rolesRequiringDepartment.includes(form.role)" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="oversees_all_departments"
                            v-model="form.oversees_all_departments"
                            binary
                            :disabled="!form.college_id"
                        />
                        <label for="oversees_all_departments" class="text-sm text-slate-600">
                            Oversees all departments in this college
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="showDepartment">
                        Select one or more departments this OIC will cover.
                    </p>

                    <!-- Additive GenEd/Minor authority (Dean/OIC only) -->
                    <div v-if="showGenedFlag" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="is_gened_assistant_dean"
                            v-model="form.is_gened_assistant_dean"
                            binary
                            :disabled="!form.college_id"
                        />
                        <label for="is_gened_assistant_dean" class="text-sm text-slate-600">
                            Also manage GenEd/Minor subjects across all colleges
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="showGenedFlag">
                        Keeps this user's own College scope and additionally grants Assistant
                        Dean authority over GenEd/Minor subjects institution-wide.
                    </p>
                </template>

                <Divider class="!my-5" />

                <!-- Status -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="status"
                            v-model="form.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.status"
                        />
                        <label for="status">Status *</label>
                    </FloatLabel>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="form.processing" @click="closeAddUser" />
                <Button
                    label="Create User"
                    icon="pi pi-check"
                    :loading="form.processing"
                    @click="onCreateUser"
                />
            </template>
        </Dialog>

        <!-- Edit User Modal -->
        <Dialog
            v-model:visible="editUserVisible"
            modal
            :style="{ width: '800px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
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
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Edit System User</span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onUpdateUser">
                <!-- Role -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="editRole"
                            v-model="editForm.role"
                            :options="roleOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!editForm.errors.role"
                        />
                        <label for="editRole">Role *</label>
                    </FloatLabel>
                </div>
                <small v-if="editForm.errors.role" class="text-red-500">{{ editForm.errors.role }}</small>

                <Divider class="!my-5" />

                <!-- First Name / Middle Name -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="editFirstName"
                            v-model="editForm.first_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!editForm.errors.first_name"
                        />
                        <label for="editFirstName">First Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="editMiddleName"
                            v-model="editForm.middle_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="editMiddleName">Middle Name</label>
                    </FloatLabel>
                </div>

                <!-- Last Name / Suffix -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="editLastName"
                            v-model="editForm.last_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!editForm.errors.last_name"
                        />
                        <label for="editLastName">Last Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="editSuffix"
                            v-model="editForm.suffix"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="editSuffix">Suffix</label>
                    </FloatLabel>
                </div>

                <Divider class="!my-5" />

                <!-- Email -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="editEmail"
                            v-model="editForm.email"
                            type="email"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!editForm.errors.email"
                        />
                        <label for="editEmail">Email *</label>
                    </FloatLabel>
                    <small v-if="editForm.errors.email" class="text-red-500 -mt-4">{{ editForm.errors.email }}</small>
                </div>

                <!-- Password / Confirm Password (optional on edit) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Password
                            id="editPassword"
                            v-model="editForm.password"
                            toggleMask
                            :feedback="true"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!editForm.errors.password"
                        />
                        <label for="editPassword">New Password</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Password
                            id="editConfirmPassword"
                            v-model="editForm.password_confirmation"
                            toggleMask
                            :feedback="false"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!editForm.errors.password"
                        />
                        <label for="editConfirmPassword">Confirm New Password</label>
                    </FloatLabel>
                </div>
                <small v-if="editForm.errors.password" class="text-red-500">{{ editForm.errors.password }}</small>
                <p class="text-xs text-slate-400 mt-1">Leave blank to keep the current password.</p>

                <template v-if="editShowCollege">
                    <Divider class="!my-5" />

                    <!-- College (Dean / OIC) / Department (OIC only) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <FloatLabel variant="on">
                            <Select
                                id="editCollege"
                                v-model="editForm.college_id"
                                :options="collegeOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                                :invalid="!!editForm.errors.college_id"
                            />
                            <label for="editCollege">College *</label>
                        </FloatLabel>

                        <FloatLabel v-if="editShowDepartment" variant="on">
                            <MultiSelect
                                id="editDepartment"
                                v-model="editForm.department_ids"
                                :options="editDepartmentOptions"
                                optionLabel="label"
                                optionValue="value"
                                display="chip"
                                class="w-full"
                                :disabled="!editForm.college_id"
                                :invalid="!!editForm.errors.department_ids"
                            />
                            <label for="editDepartment">Department(s) *</label>
                        </FloatLabel>
                    </div>
                    <small v-if="editForm.errors.department_ids" class="text-red-500">{{ editForm.errors.department_ids }}</small>

                    <!-- OIC scope: whole college vs a specific subset of departments -->
                    <div v-if="rolesRequiringDepartment.includes(editForm.role)" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="editOverseesAllDepartments"
                            v-model="editForm.oversees_all_departments"
                            binary
                            :disabled="!editForm.college_id"
                        />
                        <label for="editOverseesAllDepartments" class="text-sm text-slate-600">
                            Oversees all departments in this college
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="editShowDepartment">
                        Select one or more departments this OIC will cover.
                    </p>

                    <!-- Additive GenEd/Minor authority (Dean/OIC only) -->
                    <div v-if="editShowGenedFlag" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="editIsGenedAssistantDean"
                            v-model="editForm.is_gened_assistant_dean"
                            binary
                            :disabled="!editForm.college_id"
                        />
                        <label for="editIsGenedAssistantDean" class="text-sm text-slate-600">
                            Also manage GenEd/Minor subjects across all colleges
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="editShowGenedFlag">
                        Keeps this user's own College scope and additionally grants Assistant
                        Dean authority over GenEd/Minor subjects institution-wide.
                    </p>
                </template>

                <Divider class="!my-5" />

                <!-- Status -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="editStatus"
                            v-model="editForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!editForm.errors.status"
                        />
                        <label for="editStatus">Status *</label>
                    </FloatLabel>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="editForm.processing" @click="closeEditUser" />
                <Button
                    label="Save Changes"
                    icon="pi pi-check"
                    :loading="editForm.processing"
                    @click="onUpdateUser"
                />
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

/* ------------------------------------------------------------------ */
/* Glassmorphism — Card                                                */
/* Plain CSS (no Tailwind arbitrary-value classes) so this reliably    */
/* beats PrimeVue's own injected component styles regardless of        */
/* cascade-layer/build quirks. app-glass-card sits on the Card's own   */
/* root element (it IS .p-card), so plain class selectors below target */
/* it directly — no :deep() needed for the root itself.                */
/* ------------------------------------------------------------------ */
.app-glass-card {
    border-radius: 1rem !important;
    border-width: 1px !important;
    border-style: solid !important;
    margin-top: 1.25rem;
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
.app-glass-card.is-light {
    background: rgba(255, 255, 255, 0.6) !important;
    border-color: rgba(255, 255, 255, 0.7) !important;
    box-shadow: 0 8px 32px rgba(30, 41, 59, 0.08);
}
.app-glass-card.is-dark {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
}
/* The Card body/content wrapper inside it must stay transparent so the
   translucency above actually shows through. */
:deep(.app-glass-card .p-card-body),
:deep(.app-glass-card .p-card-content) {
    background: transparent !important;
}

/* ------------------------------------------------------------------ */
/* Glassmorphism — Dialogs (Add/Edit User)                             */
/* Dialog is teleported through PrimeVue's internal Portal/Transition   */
/* wrappers, several component layers removed from this file's own     */
/* template. Vue's scoped "data-v-xxxx" attribute isn't guaranteed to   */
/* propagate that deep, so scoped selectors (even :deep()) can silently */
/* match nothing. :global() sidesteps that entirely — these rules key   */
/* off the app-glass-dialog/is-dark/is-light classes we pass in via pt, */
/* with no dependency on Vue's scope attribute being present.           */
/* ------------------------------------------------------------------ */
:global(.app-glass-dialog) {
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border-width: 1px !important;
    border-style: solid !important;
    border-radius: 1rem !important;
}
:global(.app-glass-dialog.is-light) {
    background: rgba(255, 255, 255, 0.8) !important;
    border-color: rgba(255, 255, 255, 0.6) !important;
    box-shadow: 0 8px 32px rgba(30, 41, 59, 0.18);
}
:global(.app-glass-dialog.is-dark) {
    background: rgba(15, 23, 48, 0.8) !important;
    border-color: rgba(255, 255, 255, 0.12) !important;
    box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
    color: #F8FAFC !important;
}
:global(.app-glass-dialog .p-dialog-header),
:global(.app-glass-dialog .p-dialog-content),
:global(.app-glass-dialog .p-dialog-footer) {
    background: transparent !important;
}
:global(.app-glass-dialog.is-light .p-dialog-header) { border-bottom: 1px solid rgba(30, 41, 59, 0.08) !important; }
:global(.app-glass-dialog.is-light .p-dialog-footer) { border-top: 1px solid rgba(30, 41, 59, 0.08) !important; }
:global(.app-glass-dialog.is-dark .p-dialog-header) { border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.app-glass-dialog.is-dark .p-dialog-content) { color: #F8FAFC !important; }
:global(.app-glass-dialog.is-dark .p-dialog-footer) { border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

/* Inputs inside the dialog get a frosted look matching each mode. */
:global(.app-glass-dialog.is-light .p-inputtext),
:global(.app-glass-dialog.is-light .p-password-input),
:global(.app-glass-dialog.is-light .p-select),
:global(.app-glass-dialog.is-light .p-multiselect) {
    background: rgba(255, 255, 255, 0.6) !important;
    border-color: rgba(30, 41, 59, 0.12) !important;
}
:global(.app-glass-dialog.is-dark .p-inputtext),
:global(.app-glass-dialog.is-dark .p-password-input),
:global(.app-glass-dialog.is-dark .p-select),
:global(.app-glass-dialog.is-dark .p-multiselect),
:global(.app-glass-dialog.is-dark .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
:global(.app-glass-dialog.is-dark .p-select-label),
:global(.app-glass-dialog.is-dark .p-multiselect-label) { color: #F8FAFC !important; }
:global(.app-glass-dialog.is-dark .p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

/* The TabPanels wrapper paints its own opaque white background + padding
   by default — that was hiding the glass Card sitting inside it. Strip
   it out in both modes so the Card's own translucent surface is what
   the user actually sees. */
:deep(.p-tabpanels) {
    background: transparent !important;
    padding: 0 !important;
}
:deep(.p-tabpanel) {
    background: transparent !important;
}

.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.04) !important; color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #E2E8F0 !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.03) !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #CBD5E1 !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>