<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Password from 'primevue/password';
import Button from 'primevue/button';
import Divider from 'primevue/divider';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import ToggleSwitch from 'primevue/toggleswitch';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import DatePicker from 'primevue/datepicker';
import InfoPopover from '@/Components/InfoPopover.vue';
import PasswordRequirementsChecklist from '@/Components/PasswordRequirementsChecklist.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    visibleGroups: { type: Array, default: () => [] },
    editableGroups: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    schoolYear: { type: Object, default: null },
    system: { type: Object, default: null },
    passwordPolicy: { type: Object, default: null },
    activeSessions: { type: Array, default: () => [] },
    activityLog: { type: Object, default: () => ({}) },
});

const toast = useToast();
const page = usePage();

watch(
    () => page.props.flash?.success,
    (message) => { if (message) toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 }); },
);
watch(
    () => page.props.flash?.error,
    (message) => { if (message) toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 }); },
);

const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));

// Lets a notification (e.g. "Password Change Required") deep-link
// straight into the Manage Account tab via ?tab=account instead of
// always landing on whatever the first visible group is.
const requestedTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(requestedTab ?? (props.visibleGroups[0] ?? 'general'));

const canEdit = (group) => props.editableGroups.includes(group);
const has = (group) => props.visibleGroups.includes(group);

// "19:00" -> "7:00 PM" — the Academic tab's Class Hours summary reads
// straight off the Active School Year record (raw 24-hour "H:i"), so it
// needs its own display formatter rather than showing that value as-is.
const formatTime12 = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${hour12}:${String(minute).padStart(2, '0')} ${period}`;
};

const onError = () => {
    toast.add({ severity: 'warn', summary: 'Missing information', detail: 'Please check the highlighted fields and try again.', life: 3000 });
};

/* ------------------------------------------------------------------ */
/* General                                                             */
/* ------------------------------------------------------------------ */
const generalForm = useForm({
    school_name: props.settings['general.school_name'] ?? '',
    school_short_name: props.settings['general.school_short_name'] ?? '',
    school_address: props.settings['general.school_address'] ?? '',
    school_contact: props.settings['general.school_contact'] ?? '',
    school_email: props.settings['general.school_email'] ?? '',
});
const saveGeneral = () => {
    generalForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.general.update'), {
        preserveScroll: true,
        onError,
    });
};

/* ------------------------------------------------------------------ */
/* Faculty & workload                                                  */
/* ------------------------------------------------------------------ */
const workloadForm = useForm({
    max_teaching_load: props.settings['workload.max_teaching_load'] ?? 24,
    warning_threshold: props.settings['workload.warning_threshold'] ?? 85,
    overloaded_threshold: props.settings['workload.overloaded_threshold'] ?? 100,
    allow_admin_override: props.settings['workload.allow_admin_override'] ?? true,
    max_daily_teaching_hours: props.settings['workload.max_daily_teaching_hours'] ?? 8,
});
const saveWorkload = () => {
    workloadForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.workload.update'), { preserveScroll: true, onError });
};


/* ------------------------------------------------------------------ */
/* System / Maintenance                                                */
/* ------------------------------------------------------------------ */
const refreshingCache = ref(false);
const refreshCache = () => {
    refreshingCache.value = true;
    router.post(route('settings.system.refresh-cache'), {}, {
        preserveScroll: true,
        onFinish: () => { refreshingCache.value = false; },
    });
};

/* ------------------------------------------------------------------ */
/* Active Sessions                                                     */
/* ------------------------------------------------------------------ */
function refreshSessions() {
    router.reload({ only: ['activeSessions'] });
}

function forceLogout(session) {
    if (! confirm('Log this device out now? The user will be signed out immediately.')) {
        return;
    }

    router.delete(route('settings.active-sessions.destroy', session.id), {
        preserveScroll: true,
        onSuccess: () => refreshSessions(),
    });
}

/* ------------------------------------------------------------------ */
/* Activity Log                                                        */
/* ------------------------------------------------------------------ */
const activityLogFilters = ref({
    log_action: props.activityLog?.filters?.action ?? null,
    log_user_id: props.activityLog?.filters?.user_id ?? null,
    log_date_from: props.activityLog?.filters?.date_from ?? null,
    log_date_to: props.activityLog?.filters?.date_to ?? null,
});

function reloadActivityLog(extra = {}) {
    router.reload({
        only: ['activityLog'],
        data: { ...activityLogFilters.value, ...extra },
        preserveScroll: true,
        preserveState: true,
    });
}

function applyActivityLogFilters() {
    reloadActivityLog({ log_page: 1 });
}

function clearActivityLogFilters() {
    activityLogFilters.value = {
        log_action: null,
        log_user_id: null,
        log_date_from: null,
        log_date_to: null,
    };
    reloadActivityLog({ log_page: 1 });
}

function goToActivityLogPage(page) {
    reloadActivityLog({ log_page: page });
}

/* ------------------------------------------------------------------ */
/* Manage Account (Registrar / Dean / OIC / Assistant Dean)            */
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
    accountForm.transform((data) => ({ ...data, _method: 'put' })).post(route('account.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            accountForm.password = '';
            accountForm.password_confirmation = '';
            accountForm.photo = null;
            accountForm.remove_photo = false;
            photoPreviewUrl.value = null;
        },
        onError,
    });
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <Toast />

        <div class="max-w-6xl mx-auto w-full neu-form" :class="isDark ? 'dark-scope' : ''">
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
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                    Settings
                    <InfoPopover
                        title="Settings"
                        :paragraphs="[
                            'System-wide configuration that controls how scheduling behaves — not the data itself (Faculty, Rooms, Subjects, Sections, and Curriculum each have their own pages).',
                        ]"
                        :bullets="[
                            'Academic — a read-only summary of the daily class window and available scheduling days; managed on the Term Setup page.',
                            'Changing these settings affects future scheduling; it does not retroactively change schedules already saved.',
                        ]"
                    />
                </h1>
                <p class="mt-1 text-slate-500 max-w-3xl">
                    System-wide configuration. Faculty, Rooms, Subjects, Sections, Programs, Colleges and the
                    Curriculum keep their own dedicated pages — Settings only controls how the system behaves.
                </p>
                </div>
            </div>

            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab v-if="has('general')" value="general">General</Tab>
                    <Tab v-if="has('academic')" value="academic">Academic</Tab>
                    <Tab v-if="has('workload')" value="workload">Faculty &amp; Workload</Tab>
                    <Tab v-if="has('system')" value="activeSessions">Active Sessions</Tab>
                    <Tab v-if="has('system')" value="activityLog">Activity Log</Tab>
                    <Tab v-if="has('system')" value="system">System</Tab>
                    <Tab v-if="!isAdministrator" value="account">Manage Account</Tab>
                </TabList>

                <TabPanels>
                    <!-- ============================== GENERAL ============================== -->
                    <TabPanel v-if="has('general')" value="general">
                        <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                        <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">General</h2>
                                <p class="text-sm text-slate-500 mb-6">School identity shown across the system.</p>

                                <fieldset :disabled="!canEdit('general')" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-7">
                                    <FloatLabel variant="on">
                                        <InputText v-uppercase id="schoolName" size="large" v-model="generalForm.school_name" class="w-full" :invalid="!!generalForm.errors.school_name" />
                                        <label for="schoolName">School Name *</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputText v-uppercase id="schoolShortName" size="large" v-model="generalForm.school_short_name" class="w-full" />
                                        <label for="schoolShortName">Short Name</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputText v-uppercase id="schoolContact" size="large" v-model="generalForm.school_contact" class="w-full" />
                                        <label for="schoolContact">Contact Number</label>
                                    </FloatLabel>

                                    <FloatLabel variant="on">
                                        <InputText id="schoolEmail" size="large" v-model="generalForm.school_email" type="email" class="w-full" :invalid="!!generalForm.errors.school_email" />
                                        <label for="schoolEmail">School Email</label>
                                    </FloatLabel>

                                    <FloatLabel variant="on" class="sm:col-span-2 lg:col-span-3">
                                        <Textarea id="schoolAddress" v-model="generalForm.school_address" class="w-full" rows="1" autoResize />
                                        <label for="schoolAddress">Address</label>
                                    </FloatLabel>
                                </fieldset>

                                <div v-if="canEdit('general')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="generalForm.processing" @click="saveGeneral" />
                                </div>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- ============================== ACADEMIC ============================== -->
                    <TabPanel v-if="has('academic')" value="academic">
                        <div class="neu-card rounded-2xl p-6 transition-colors duration-300" v-if="schoolYear">
                        <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                            <template #content>
                                <div class="flex items-center justify-between mb-1">
                                    <h2 class="text-lg font-bold text-[#1E293B]">Scheduling Window &amp; Calendar</h2>
                                    <Tag severity="info" value="Managed in Term Setup" />
                                </div>
                                <p class="text-sm text-slate-500 mb-4">
                                    Class Start/End Time, Time Interval, and Working Days already live on the
                                    Active School Year so the Auto Schedule engine has one source of truth. Change them from the
                                    Term Setup page — Settings only shows the current values here.
                                </p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                                    <div><span class="text-slate-400 block">Active School Year</span>{{ schoolYear.name }}</div>
                                    <div><span class="text-slate-400 block">Class Hours</span>{{ formatTime12(schoolYear.class_start_time) }} – {{ formatTime12(schoolYear.class_end_time) }}</div>
                                    <div><span class="text-slate-400 block">Time Interval</span>{{ schoolYear.time_interval }} minutes</div>
                                    <div class="sm:col-span-2"><span class="text-slate-400 block">Working Days</span>{{ schoolYear.available_days.join(', ') }}</div>
                                </div>
                                <Button as="a" :href="route('academic-calendar')" label="Open Term Setup" icon="pi pi-external-link" text class="!mt-4 !px-0" />
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- ============================== FACULTY & WORKLOAD ============================== -->
                    <TabPanel v-if="has('workload')" value="workload">
                        <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                        <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Faculty &amp; Workload</h2>
                                <p class="text-sm text-slate-500 mb-5">
                                    Auto Schedule prefers faculty with lower current workload and never auto-assigns past
                                    the maximum load. Individual faculty-specific limits (if set on a Faculty record)
                                    still take precedence over this system default. Max Daily Teaching Hours is a
                                    separate, per-day ceiling — a faculty member can be well under their weekly Max
                                    Teaching Load and still get crammed into too many hours on one single day.
                                </p>

                                <fieldset :disabled="!canEdit('workload')" class="grid grid-cols-1 sm:grid-cols-4 gap-5">
                                    <FloatLabel variant="on">
                                        <!-- Capped at 42 to match FacultyLoadRequest::HARD_CAP_UNITS
                                             (the institution-wide hard ceiling enforced server-side on
                                             every overload request) — never raise this without also
                                             raising HARD_CAP_UNITS, or the form will accept a value the
                                             backend will just reject/clamp anyway. -->
                                        <InputNumber id="maxLoad" size="large" v-model="workloadForm.max_teaching_load" class="w-full" :min="1" :max="42" />
                                        <label for="maxLoad">Max Teaching Load (units/hrs)</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="maxDailyHours" size="large" v-model="workloadForm.max_daily_teaching_hours" class="w-full" :min="0" :max="16" suffix=" hrs" />
                                        <label for="maxDailyHours">Max Daily Teaching Hours</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="warnThreshold" size="large" v-model="workloadForm.warning_threshold" class="w-full" :min="0" :max="100" suffix="%" />
                                        <label for="warnThreshold">Warning Threshold</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="overloadThreshold" size="large" v-model="workloadForm.overloaded_threshold" class="w-full" :min="0" :max="200" suffix="%" />
                                        <label for="overloadThreshold">Overloaded Threshold</label>
                                    </FloatLabel>
                                </fieldset>
                                <small v-if="workloadForm.errors.max_daily_teaching_hours" class="text-red-500 block mt-2">{{ workloadForm.errors.max_daily_teaching_hours }}</small>
                                <small v-if="workloadForm.errors.warning_threshold" class="text-red-500 block mt-2">{{ workloadForm.errors.warning_threshold }}</small>

                                <div class="flex items-center gap-3 mt-5">
                                    <ToggleSwitch v-model="workloadForm.allow_admin_override" :disabled="!canEdit('workload')" />
                                    <span>Allow Administrator override above the maximum load</span>
                                    <Tag severity="danger" value="HARD CONSTRAINT unless overridden" class="!text-[10px]" />
                                </div>

                                <div v-if="canEdit('workload')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="workloadForm.processing" @click="saveWorkload" />
                                </div>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- ============================== ACTIVE SESSIONS ============================== -->
                    <TabPanel v-if="has('system')" value="activeSessions">
                        <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                        <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                            <template #content>
                                <div class="flex items-start justify-between gap-4 mb-5">
                                    <div>
                                        <h2 class="text-lg font-bold text-[#1E293B] mb-1">Active Sessions</h2>
                                        <p class="text-sm text-slate-500 max-w-2xl">
                                            Everyone currently signed in to Classly. A user can appear more than once if
                                            they're signed in on more than one device. Visible to Administrators only.
                                        </p>
                                    </div>
                                    <Button icon="pi pi-refresh" label="Refresh" text @click="refreshSessions" />
                                </div>

                                <div v-if="!activeSessions || activeSessions.length === 0" class="neu-inset rounded-2xl p-8 text-center text-slate-500">
                                    No one else is currently signed in.
                                </div>

                                <div v-else class="space-y-4">
                                    <div v-for="entry in activeSessions" :key="entry.user_id" class="neu-inset rounded-2xl p-5">
                                        <div class="flex items-center justify-between mb-3">
                                            <div>
                                                <span class="font-semibold text-[#1E293B]">{{ entry.name }}</span>
                                                <Tag v-if="entry.role" :value="entry.role" severity="secondary" class="!text-[10px] ml-2 align-middle" />
                                            </div>
                                            <span class="text-xs text-slate-400">
                                                {{ entry.sessions.length }} device{{ entry.sessions.length === 1 ? '' : 's' }}
                                            </span>
                                        </div>

                                        <ul class="space-y-2">
                                            <li
                                                v-for="session in entry.sessions"
                                                :key="session.id"
                                                class="neu-card flex items-center justify-between rounded-xl px-3 py-2"
                                            >
                                                <div class="flex flex-col">
                                                    <span class="text-sm text-[#1E293B]">
                                                        {{ session.device }}
                                                        <Tag v-if="session.is_current" value="This device" severity="success" class="!text-[10px] ml-1 align-middle" />
                                                    </span>
                                                    <span class="text-xs text-slate-400">
                                                        {{ session.ip_address ?? 'Unknown IP' }} · Last active {{ session.last_active }}
                                                    </span>
                                                </div>
                                                <Button
                                                    v-if="!session.is_current"
                                                    label="Log out"
                                                    icon="pi pi-sign-out"
                                                    size="small"
                                                    severity="danger"
                                                    text
                                                    @click="forceLogout(session)"
                                                />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- ============================== ACTIVITY LOG ============================== -->
                    <TabPanel v-if="has('system')" value="activityLog">
                        <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                        <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                            <template #content>
                                <div class="flex items-start justify-between gap-4 mb-5">
                                    <div>
                                        <h2 class="text-lg font-bold text-[#1E293B] mb-1">Activity Log</h2>
                                        <p class="text-sm text-slate-500 max-w-2xl">
                                            A record of important actions across Classly — who did what, and when.
                                            Visible to Administrators only.
                                        </p>
                                    </div>
                                    <Button icon="pi pi-refresh" label="Refresh" text @click="reloadActivityLog()" />
                                </div>

                                <!-- Filters -->
                                <div class="neu-inset rounded-2xl p-4 mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">Action</label>
                                        <Select
                                            v-model="activityLogFilters.log_action"
                                            :options="activityLog.action_options ?? []"
                                            placeholder="All actions"
                                            showClear
                                            class="w-full"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">User</label>
                                        <Select
                                            v-model="activityLogFilters.log_user_id"
                                            :options="activityLog.user_options ?? []"
                                            optionLabel="name"
                                            optionValue="id"
                                            placeholder="All users"
                                            showClear
                                            class="w-full"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">From</label>
                                        <DatePicker v-model="activityLogFilters.log_date_from" dateFormat="yy-mm-dd" showIcon class="w-full" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">To</label>
                                        <DatePicker v-model="activityLogFilters.log_date_to" dateFormat="yy-mm-dd" showIcon class="w-full" />
                                    </div>
                                    <div class="flex gap-2">
                                        <Button label="Apply" size="small" @click="applyActivityLogFilters" />
                                        <Button label="Clear" size="small" text @click="clearActivityLogFilters" />
                                    </div>
                                </div>

                                <div v-if="!activityLog.data || activityLog.data.length === 0" class="neu-inset rounded-2xl p-8 text-center text-slate-500">
                                    No activity recorded yet for these filters.
                                </div>

                                <div v-else class="space-y-2">
                                    <div
                                        v-for="entry in activityLog.data"
                                        :key="entry.id"
                                        class="neu-inset rounded-2xl px-4 py-3 flex items-start justify-between gap-4"
                                    >
                                        <div class="flex flex-col gap-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="font-semibold text-[#1E293B] text-sm">{{ entry.actor }}</span>
                                                <Tag v-if="entry.role" :value="entry.role" severity="secondary" class="!text-[10px]" />
                                                <Tag :value="entry.action" severity="info" class="!text-[10px]" />
                                            </div>
                                            <span class="text-sm text-slate-600">{{ entry.description }}</span>
                                        </div>
                                        <span class="text-xs text-slate-400 whitespace-nowrap">
                                            {{ new Date(entry.created_at).toLocaleString() }}
                                        </span>
                                    </div>

                                    <!-- Pagination -->
                                    <div v-if="activityLog.last_page > 1" class="flex items-center justify-between pt-3">
                                        <span class="text-xs text-slate-400">
                                            Page {{ activityLog.current_page }} of {{ activityLog.last_page }} · {{ activityLog.total }} total
                                        </span>
                                        <div class="flex gap-2">
                                            <Button
                                                icon="pi pi-chevron-left"
                                                text
                                                size="small"
                                                :disabled="activityLog.current_page <= 1"
                                                @click="goToActivityLogPage(activityLog.current_page - 1)"
                                            />
                                            <Button
                                                icon="pi pi-chevron-right"
                                                text
                                                size="small"
                                                :disabled="activityLog.current_page >= activityLog.last_page"
                                                @click="goToActivityLogPage(activityLog.current_page + 1)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- ============================== SYSTEM ============================== -->
                    <TabPanel v-if="has('system') && system" value="system">
                        <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                        <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">System / Maintenance</h2>
                                <p class="text-sm text-slate-500 mb-5">Visible to Administrators only.</p>

                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm mb-6">
                                    <div><span class="text-slate-400 block">Application Version</span>{{ system.app_version }}</div>
                                    <div><span class="text-slate-400 block">Laravel Version</span>{{ system.laravel_version }}</div>
                                    <div>
                                        <span class="text-slate-400 block">Database Status</span>
                                        <Tag :severity="system.database_status === 'Connected' ? 'success' : 'danger'" :value="system.database_status" />
                                    </div>
                                    <div><span class="text-slate-400 block">Last Configuration Update</span>{{ system.last_configuration_update ?? '—' }}</div>
                                </div>

                                <Button label="Refresh Configuration Cache" icon="pi pi-refresh" severity="secondary" outlined :loading="refreshingCache" @click="refreshCache" />
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <TabPanel v-if="!isAdministrator" value="account">
                        <div class="neu-card rounded-2xl p-6 transition-colors duration-300 max-w-2xl">
                        <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Manage Account</h2>
                                <p class="text-sm text-slate-500 mb-5">Update your own profile and change your password.</p>

                                <form class="pt-1" autocomplete="off" @submit.prevent="onUpdateAccount">
                                    <div class="flex items-center gap-4 mb-6">
                                        <div class="relative shrink-0">
                                            <img v-if="accountAvatarUrl" :src="accountAvatarUrl" class="h-16 w-16 rounded-full object-cover border border-slate-200" alt="Profile photo" />
                                            <span v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-200 text-lg font-bold text-slate-600">{{ accountInitials }}</span>
                                        </div>
                                        <div>
                                            <input ref="photoInput" type="file" accept="image/*" class="hidden" @change="onPhotoSelected" />
                                            <div class="flex gap-2">
                                                <Button type="button" label="Upload Photo" icon="pi pi-camera" size="small" outlined @click="photoInput?.click()" />
                                                <Button v-if="accountAvatarUrl" type="button" label="Remove" icon="pi pi-trash" size="small" text severity="danger" @click="onRemovePhoto" />
                                            </div>
                                            <p class="text-xs text-slate-400 mt-1">JPG or PNG, up to 2MB.</p>
                                            <small v-if="accountForm.errors.photo" class="text-red-500 block">{{ accountForm.errors.photo }}</small>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText v-uppercase id="accFirstName" size="large" v-model="accountForm.first_name" class="w-full" autocomplete="off" :invalid="!!accountForm.errors.first_name" />
                                            <label for="accFirstName">First Name *</label>
                                        </FloatLabel>
                                        <FloatLabel variant="on">
                                            <InputText v-uppercase id="accMiddleName" size="large" v-model="accountForm.middle_name" class="w-full" autocomplete="off" />
                                            <label for="accMiddleName">Middle Name</label>
                                        </FloatLabel>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <InputText v-uppercase id="accLastName" size="large" v-model="accountForm.last_name" class="w-full" autocomplete="off" :invalid="!!accountForm.errors.last_name" />
                                            <label for="accLastName">Last Name *</label>
                                        </FloatLabel>
                                        <FloatLabel variant="on">
                                            <InputText v-uppercase id="accSuffix" size="large" v-model="accountForm.suffix" class="w-full" autocomplete="off" />
                                            <label for="accSuffix">Suffix</label>
                                        </FloatLabel>
                                    </div>

                                    <Divider class="!my-5" />

                                    <div class="grid grid-cols-1 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText id="accEmail" size="large" v-model="accountForm.email" type="email" class="w-full" autocomplete="off" :invalid="!!accountForm.errors.email" />
                                            <label for="accEmail">Email *</label>
                                        </FloatLabel>
                                        <small v-if="accountForm.errors.email" class="text-red-500 -mt-4">{{ accountForm.errors.email }}</small>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <Password id="accPassword" size="large" v-model="accountForm.password" toggleMask :feedback="true" inputClass="w-full" class="w-full" autocomplete="new-password" :invalid="!!accountForm.errors.password" />
                                            <label for="accPassword">New Password</label>
                                        </FloatLabel>
                                        <FloatLabel variant="on">
                                            <Password id="accPasswordConfirm" size="large" v-model="accountForm.password_confirmation" toggleMask :feedback="false" inputClass="w-full" class="w-full" autocomplete="new-password" :invalid="!!accountForm.errors.password" />
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
                                        <Button type="submit" label="Save Changes" icon="pi pi-check" :loading="accountForm.processing" />
                                    </div>
                                </form>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (applied on the root container when isDark is true) lets these rules
   restyle the light-themed Tailwind/PrimeVue defaults used throughout
   this page without touching the light-mode markup. */
/* Token overrides: PrimeVue Aura reads these CSS variables for every
   component's colors. Setting them here means anything nested under
   .dark-scope repaints correctly even if a class-level rule below
   doesn't happen to match that component's internal markup. */
.dark-scope {
    --p-card-background: #141B33;
    --p-card-color: #F8FAFC;
    --p-content-background: #141B33;
    --p-content-color: #F8FAFC;
    --p-inputtext-background: rgba(255, 255, 255, 0.06);
    --p-inputtext-color: #F8FAFC;
    --p-inputtext-border-color: rgba(255, 255, 255, 0.18);
    --p-inputtext-placeholder-color: #64748B;
    --p-select-background: rgba(255, 255, 255, 0.06);
    --p-select-color: #F8FAFC;
    --p-select-border-color: rgba(255, 255, 255, 0.18);
    --p-select-overlay-background: #141B33;
}

.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-\[\#2563EB\]) { color: #60A5FA !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.border-dashed) { border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.text-slate-300) { color: #475569 !important; }

.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }
.dark-scope :deep(.p-card .p-card-content) { background: transparent !important; }

.dark-scope :deep(.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

/* Field backgrounds/borders themselves are handled by the shared
   ".neu-form" rules in app.css (inset pressed-in wells, light + dark) —
   only the teleported Select/Multiselect overlay panel needs its own
   dark treatment here since it renders outside this component. */
.dark-scope :deep(.p-select-overlay),
.dark-scope :deep(.p-multiselect-overlay) { background: #141B33 !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-select-option),
.dark-scope :deep(.p-multiselect-option) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-select-option:hover),
.dark-scope :deep(.p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* FloatLabel variant="on" keeps the label permanently floated, cutting
   through the input's top border — its background must always match
   the surface behind it (the neu-card panel), not just on focus, or it
   renders as a mismatched opaque chip. */
:deep(.p-floatlabel-on label) { background: var(--neu-card-light); }
:deep(.p-floatlabel-on:has(input:focus) label),
:deep(.p-floatlabel-on:has(.p-inputwrapper-focus) label) { color: #2563EB !important; }
.dark-scope :deep(.p-floatlabel-on label) { background: var(--neu-card-dark) !important; color: #94A3B8 !important; }
.dark-scope :deep(.p-floatlabel-on:has(input:focus) label),
.dark-scope :deep(.p-floatlabel-on:has(.p-inputwrapper-focus) label) { color: #60A5FA !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }
.dark-scope :deep(.p-tablist-nav-button) {
    background: rgba(15, 23, 48, 0.9) !important;
    color: #60A5FA !important;
    border: 1px solid rgba(96, 165, 250, 0.5) !important;
    border-radius: 999px !important;
    box-shadow: 0 0 8px rgba(96, 165, 250, 0.55), 0 0 18px rgba(96, 165, 250, 0.25) !important;
    transition: box-shadow 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.dark-scope :deep(.p-tablist-nav-button:hover) {
    color: #93C5FD !important;
    border-color: rgba(147, 197, 253, 0.8) !important;
    box-shadow: 0 0 12px rgba(147, 197, 253, 0.8), 0 0 26px rgba(147, 197, 253, 0.4) !important;
}
.dark-scope :deep(.p-tabs) { background: transparent !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F8FAFC !important; padding: 0 !important; }
.dark-scope :deep(.p-tablist-tab-list) { background: transparent !important; }
.dark-scope :deep(.p-tablist-active-bar) { background: #60A5FA !important; }

.dark-scope :deep(.p-togglebutton),
.dark-scope :deep(.p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-toggleswitch:not(.p-toggleswitch-checked) .p-toggleswitch-slider) { background: rgba(255, 255, 255, 0.15) !important; }

.dark-scope :deep(.p-message) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-message-text) { color: #F8FAFC !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>