<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
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
import ProgressBar from 'primevue/progressbar';
import RoomRecommendedSubjects from '@/Components/Scheduling/RoomRecommendedSubjects.vue';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    rooms: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    summary: {
        type: Object,
        default: () => ({
            total_rooms: 0,
            active_rooms: 0,
            available_rooms: 0,
            fully_booked_rooms: 0,
            average_utilization: 0,
            rooms_with_conflicts: 0,
        }),
    },
    filters: {
        type: Object,
        default: () => ({ room_search: '', quick_filter: 'all' }),
    },
    roomTypes: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    buildings: { type: Array, default: () => [] },
    floors: { type: Array, default: () => [] },
});

/* ------------------------------------------------------------------ */
/* Utilization / Availability display helpers                          */
/* ------------------------------------------------------------------ */

const UTILIZATION_SEVERITY = {
    Normal: 'success',
    'High Usage': 'warning',
    'Nearly Full': 'warning',
    Overbooked: 'danger',
};

const AVAILABILITY_SEVERITY = {
    Available: 'success',
    'Partially Available': 'info',
    'Fully Booked': 'warning',
    'Overbooked / Conflict': 'danger',
    Overbooked: 'danger',
    Inactive: 'secondary',
};

const utilizationSeverity = (status) => UTILIZATION_SEVERITY[status] ?? 'info';
const availabilitySeverity = (status) => AVAILABILITY_SEVERITY[status] ?? 'info';

const toast = useToast();
const page = usePage();

// Room Master administration (Add/Edit/Delete) is Admin/Registrar
// only — Dean/OIC/Assistant Dean may view and use Rooms for
// scheduling but never touch the master record. Mirrors
// RoomPolicy::create()/update()/delete(); this is UI-visibility
// only, the real enforcement is server-side on every request.
const canManageRooms = computed(() => !!page.props.auth?.can?.manageRooms);

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
/* Bulk Import — Room Master                                          */
/*                                                                      */
/* Adding a whole building's worth of Rooms one at a time through the */
/* Add Room dialog doesn't scale. Backed by RoomController::import() —*/
/* every row gets the exact same validation a manual Add Room would,  */
/* and rows that fail are reported back individually rather than      */
/* aborting the whole file. Same pattern as Subjects/Index.vue's Bulk */
/* Import.                                                             */
/* ------------------------------------------------------------------ */

const importVisible = ref(false);
const importForm = useForm({ file: null });
const importFileName = ref('');

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const previewRows = ref([]);
const previewLoading = ref(false);
const previewError = ref('');
const previewSummary = computed(() => ({
    new: previewRows.value.filter((r) => r.status === 'new').length,
    exists: previewRows.value.filter((r) => r.status === 'exists').length,
    error: previewRows.value.filter((r) => r.status === 'error').length,
}));
const previewStatusMeta = {
    new: { severity: 'success', label: 'New' },
    exists: { severity: 'warn', label: 'Already exists' },
    error: { severity: 'danger', label: 'Invalid' },
};

const resetImportPreview = () => {
    previewRows.value = [];
    previewError.value = '';
    previewLoading.value = false;
};

const openImport = () => {
    importForm.reset();
    importForm.clearErrors();
    importFileName.value = '';
    resetImportPreview();
    importVisible.value = true;
};

const onImportFileChange = async (event) => {
    const file = event.target.files?.[0] ?? null;
    importForm.file = file;
    importFileName.value = file?.name ?? '';
    resetImportPreview();

    if (!file) return;

    previewLoading.value = true;
    try {
        const body = new FormData();
        body.append('file', file);

        const response = await fetch(route('scheduling.rooms.import.preview'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body,
        });

        const data = await response.json();

        if (!response.ok) {
            previewError.value = data?.error ?? 'Could not read this file. Please check it and try again.';
            return;
        }

        previewRows.value = data.rows ?? [];
    } catch (e) {
        previewError.value = 'Could not reach the server to preview this file.';
    } finally {
        previewLoading.value = false;
    }
};

const submitImport = () => {
    if (!importForm.file) {
        toast.add({ severity: 'warn', summary: 'No file selected', detail: 'Please choose a CSV file first.', life: 4000 });
        return;
    }

    importForm.post(route('scheduling.rooms.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importVisible.value = false;
            importForm.reset();
            importFileName.value = '';
            resetImportPreview();
        },
    });
};

/* ------------------------------------------------------------------ */
/* Search / list                                                       */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.room_search ?? '');
const quickFilter = ref(props.filters.quick_filter ?? 'all');
const building = ref(props.filters.building ?? null);
const floor = ref(props.filters.floor ?? null);
const roomTypeFilter = ref(props.filters.room_type ?? null);
const collegeFilter = ref(props.filters.college_id ? Number(props.filters.college_id) : null);
const departmentFilter = ref(props.filters.department_id ? Number(props.filters.department_id) : null);
const statusFilter = ref(props.filters.status ?? null);
const availabilityFilter = ref(props.filters.availability ?? null);
const loading = ref(false);
let searchDebounce = null;

const activeQuery = () => ({
    room_search: search.value,
    quick_filter: quickFilter.value,
    building: building.value,
    floor: floor.value,
    room_type: roomTypeFilter.value,
    college_id: collegeFilter.value,
    department_id: departmentFilter.value,
    status: statusFilter.value,
    availability: availabilityFilter.value,
});

const reloadRooms = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.rooms'),
        { ...activeQuery(), ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['rooms', 'summary', 'filters'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadRooms({ room_page: 1 });
    }, 350);
});

// Program filter options depend on the selected College filter — only
// that College's programs are offered, mirroring the same cascading
// behavior already used in the Add/Edit Room modal's departmentOptions.
const departmentFilterOptions = computed(() => {
    if (!collegeFilter.value) {
        return props.departments;
    }

    return props.departments.filter((department) => department.college_id === collegeFilter.value);
});

// Switching (or clearing) the College filter invalidates a previously
// selected Program filter that doesn't belong to the new College.
watch(collegeFilter, () => {
    if (
        departmentFilter.value &&
        !departmentFilterOptions.value.some((department) => department.id === departmentFilter.value)
    ) {
        departmentFilter.value = null;
    }
});

watch([building, floor, roomTypeFilter, collegeFilter, departmentFilter, statusFilter, availabilityFilter], () => {
    reloadRooms({ room_page: 1 });
});

const quickFilters = [
    { label: 'All Rooms', value: 'all', icon: 'pi pi-th-large' },
    { label: 'Available', value: 'available', icon: 'pi pi-check-circle' },
    { label: 'Fully Booked', value: 'fully_booked', icon: 'pi pi-lock' },
    { label: 'High Usage', value: 'high_usage', icon: 'pi pi-chart-line' },
    { label: 'Conflicts', value: 'conflicts', icon: 'pi pi-exclamation-triangle' },
    { label: 'Inactive', value: 'inactive', icon: 'pi pi-ban' },
];

const selectQuickFilter = (value) => {
    quickFilter.value = value;
    reloadRooms({ room_page: 1 });
};

const onPage = (event) => {
    reloadRooms({ room_page: event.page + 1 });
};

const onRefresh = () => {
    reloadRooms({ room_page: props.rooms.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Room                                                      */
/* ------------------------------------------------------------------ */

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

// College & Department/Program Assignment (Room Management enhancement).
//
// college_id = null  -> "All Colleges" — a shared room every college may use.
// department_id = null -> "All Programs" — open to every program within the
//                          selected college (or, if college is also null,
//                          the room is fully shared with no restriction at all).
//
// This only prepares the data (see Room::department()/college() relations,
// already used elsewhere by the scheduling engine's Room recommendation
// scoring) — no AI/Auto Scheduler logic is touched here.
const ALL_COLLEGES_OPTION = { id: null, name: 'All Colleges' };
const ALL_PROGRAMS_OPTION = { id: null, name: 'All Programs' };

const collegeOptions = [ALL_COLLEGES_OPTION, ...props.colleges];

const addRoomVisible = ref(false);
const editingRoom = ref(null); // null => Add mode, otherwise the Room being edited

const roomForm = useForm({
    room_code: '',
    room_name: '',
    building: '',
    floor: '',
    room_type: null,
    college_id: null,
    department_id: null,
    capacity: 1,
    status: 'Active',
    remarks: '',
});

// Department/Program options depend on the selected College — only
// that College's programs are offered, plus "All Programs".
const departmentOptions = computed(() => {
    if (!roomForm.college_id) {
        return [ALL_PROGRAMS_OPTION];
    }

    return [
        ALL_PROGRAMS_OPTION,
        ...props.departments.filter((department) => department.college_id === roomForm.college_id),
    ];
});

// Selecting "All Colleges" (or switching Colleges) makes the previous
// Department/Program choice invalid, so it's cleared here — but only
// on a user-driven change; openEdit()/openAdd() set both fields
// programmatically and must not have this clear them.
const onCollegeChange = () => {
    roomForm.department_id = null;
};

const openAdd = () => {
    editingRoom.value = null;
    roomForm.reset();
    roomForm.clearErrors();
    addRoomVisible.value = true;
};

const openEdit = (room) => {
    editingRoom.value = room;
    roomForm.clearErrors();
    roomForm.room_code = room.room_code;
    roomForm.room_name = room.room_name;
    roomForm.building = room.building;
    roomForm.floor = room.floor ?? '';
    roomForm.room_type = room.room_type;
    roomForm.college_id = room.college_id ?? null;
    roomForm.department_id = room.department_id ?? null;
    roomForm.capacity = room.capacity;
    roomForm.status = room.status;
    roomForm.remarks = room.remarks ?? '';
    addRoomVisible.value = true;
};

const closeAddRoom = () => {
    addRoomVisible.value = false;
    editingRoom.value = null;
    roomForm.reset();
    roomForm.clearErrors();
};

// Room Code was dropped from the UI (it duplicated Room Name for every
// room in practice), but the `rooms` table still stores it as a unique,
// required column. Rather than migrating the schema, it's derived here
// from Room Name right before saving — e.g. "Computer Laboratory 2"
// becomes "COMPUTERLABORATORY2" — so the field stays populated behind
// the scenes without the person ever having to type it twice. A short
// numeric suffix is appended on retry if the derived code collides with
// an existing room.
const deriveRoomCode = (name, suffix = '') => {
    const base = (name || '')
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 20 - suffix.length);

    return `${base}${suffix}`;
};

const submitRoomForm = (options) => {
    if (editingRoom.value) {
        roomForm.put(route('scheduling.rooms.update', editingRoom.value.id), options);
    } else {
        roomForm.post(route('scheduling.rooms.store'), options);
    }
};

const onSaveRoom = (retried = false) => {
    roomForm.room_code = deriveRoomCode(
        roomForm.room_name,
        retried ? String(Math.floor(Math.random() * 900) + 100) : ''
    );

    submitRoomForm({
        preserveScroll: true,
        onSuccess: () => {
            const wasEditing = !!editingRoom.value;
            closeAddRoom();
            Swal.fire({
                title: wasEditing ? 'Room updated' : 'Room saved',
                text: wasEditing
                    ? 'The room was updated successfully.'
                    : 'The room was created successfully.',
                icon: 'success',
                confirmButtonColor: '#16A34A',
            });
            onRefresh();
        },
        onError: (errors) => {
            // A derived-code collision (two rooms with a very similar
            // name) is the one room_code error a person could actually
            // hit despite never seeing the field — retry once, silently,
            // with a random suffix before bothering them about it.
            if (errors.room_code && !retried) {
                onSaveRoom(true);
                return;
            }

            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};

// Double-confirmation flow, mirroring Faculty/Index.vue's
// onDeleteFaculty(): the first Swal is a plain "are you sure", the
// second (only shown when the backend flashes roomDeletionImpact
// because this room has active scheduled classes) spells out exactly
// what's affected before letting the delete through.
const pendingDeleteRoom = ref(null);

const onDeleteRoom = (room) => {
    Swal.fire({
        title: 'Delete this room?',
        html: `<strong>${room.room_name}</strong><br/><br/>
            This permanently removes it from the Room Master. If it simply isn't in use this term but may be needed
            again later, cancel this and use <strong>Edit → Status → Inactive</strong> instead.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Continue',
    }).then((confirmStep) => {
        if (!confirmStep.isConfirmed) return;

        pendingDeleteRoom.value = room;
        router.delete(route('scheduling.rooms.destroy', room.id), {
            preserveScroll: true,
            onSuccess: () => onRefresh(),
            onError: () => {},
            // If the backend has no active assignment to warn about,
            // it deletes immediately here and the watcher below never
            // fires — this Swal chain is skipped entirely.
        });
    });
};

// Real second-step confirmation, using the flashed impact summary the
// backend returns when it declines an unconfirmed delete because the
// room has active (non-finalized) scheduled classes
// (`roomDeletionImpact` — see RoomController::destroy()).
watch(
    () => page.props.flash?.roomDeletionImpact,
    (impact) => {
        if (!impact || !pendingDeleteRoom.value) return;
        const room = pendingDeleteRoom.value;
        pendingDeleteRoom.value = null;

        Swal.fire({
            title: '⚠ Room Has Active Scheduled Classes',
            html: `<strong>${room.room_name}</strong><br/>
                Current active assignments:<br/>
                ${impact.subject_count} subject(s), ${impact.section_count} section(s)<br/><br/>
                <span class="text-amber-600">Deleting this room will leave those scheduled classes without an assigned room.</span><br/><br/>
                Affected subjects: ${impact.subject_codes.join(', ') || '—'}<br/>
                Affected sections: ${impact.section_codes.join(', ') || '—'}`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#64748B',
            confirmButtonText: 'Delete Room',
        }).then((second) => {
            if (!second.isConfirmed) return;
            router.delete(route('scheduling.rooms.destroy', room.id), {
                data: { confirmed: true },
                preserveScroll: true,
                onSuccess: () => onRefresh(),
            });
        });
    },
);

/* ------------------------------------------------------------------ */
/* Room Schedule Details modal                                         */
/* ------------------------------------------------------------------ */

const scheduleVisible = ref(false);
const scheduleLoading = ref(false);
const scheduleRoom = ref(null);
const scheduleSummary = ref(null);
const scheduleTimetable = ref({});

function formatTime12h(time) {
    if (!time) return time;
    const parts = String(time).split(':');
    let hours = parseInt(parts[0], 10);
    const minutes = parts[1] ?? '00';
    if (isNaN(hours)) return time;
    const suffix = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    if (hours === 0) hours = 12;
    return `${hours}:${minutes.padStart(2, '0')} ${suffix}`;
}
const roomRecommendedSubjectsRef = ref(null);

const openSchedule = async (room) => {
    scheduleVisible.value = true;
    scheduleLoading.value = true;
    scheduleRoom.value = room;
    scheduleSummary.value = null;
    scheduleTimetable.value = {};

    try {
        const { data } = await window.axios.get(route('scheduling.rooms.schedule', room.id));
        scheduleRoom.value = data.room;
        scheduleSummary.value = data.summary;
        scheduleTimetable.value = data.timetable;
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not load the room schedule.', life: 4000 });
        scheduleVisible.value = false;
    } finally {
        scheduleLoading.value = false;
    }
};

// Clicking anywhere on a room row opens the same Room Schedule Details
// modal as the Utilization column / calendar action — including the
// "Recommended Subjects" panel at the bottom — so an admin doesn't have
// to know that only the Utilization cell was clickable before.
const onRowClick = (event) => {
    openSchedule(event.data);
};

const closeSchedule = () => {
    scheduleVisible.value = false;
    scheduleRoom.value = null;
    scheduleSummary.value = null;
    scheduleTimetable.value = {};
};
</script>

<template>
    <Head title="Rooms" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Rooms</span>
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
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                    Rooms
                    <InfoPopover
                        title="Rooms"
                        :paragraphs="[
                            'The master list of classrooms, laboratories, and other scheduling facilities available to the scheduling engine.',
                        ]"
                        :bullets="[
                            'Utilization shows scheduled hours against each room\'s weekly capacity — click it to view the full room schedule.',
                            'Availability reflects current bookings; a room can show open seats but still be flagged for a capacity or scheduling conflict.',
                            'Inactive rooms are kept for records but are not offered when scheduling new classes.',
                        ]"
                    />
                </h1>
                <p class="mt-1 text-slate-500">
                    Manage classrooms, laboratories, and other scheduling facilities.
                </p>
                </div>
            </div>

            <!-- Room Usage Summary Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
                <div class="neu-card rounded-xl p-4 transition-colors duration-300">
                    <p class="text-xs font-medium text-slate-500">Total Rooms</p>
                    <p class="text-2xl font-bold text-[#1E293B] mt-1">{{ summary.total_rooms }}</p>
                </div>
                <div class="neu-card rounded-xl p-4 transition-colors duration-300">
                    <p class="text-xs font-medium text-slate-500">Active Rooms</p>
                    <p class="text-2xl font-bold text-[#1E293B] mt-1">{{ summary.active_rooms }}</p>
                </div>
                <div class="neu-card rounded-xl p-4 transition-colors duration-300">
                    <p class="text-xs font-medium text-slate-500">Available Rooms</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ summary.available_rooms }}</p>
                </div>
                <div class="neu-card rounded-xl p-4 transition-colors duration-300">
                    <p class="text-xs font-medium text-slate-500">Fully Booked</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ summary.fully_booked_rooms }}</p>
                </div>
                <div class="neu-card rounded-xl p-4 transition-colors duration-300">
                    <p class="text-xs font-medium text-slate-500">Avg. Utilization</p>
                    <p class="text-2xl font-bold text-[#1E293B] mt-1">{{ summary.average_utilization }}%</p>
                </div>
                <div class="neu-card rounded-xl p-4 transition-colors duration-300">
                    <p class="text-xs font-medium text-slate-500">Conflicts</p>
                    <p class="text-2xl font-bold" :class="summary.rooms_with_conflicts > 0 ? 'text-red-600' : 'text-[#1E293B]'">
                        {{ summary.rooms_with_conflicts }}
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
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3 neu-form">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-uppercase
                                    v-model="search"
                                    placeholder="Search by code, name, building or type"
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
                                    :loading="loading"
                                    @click="onRefresh"
                                    aria-label="Refresh"
                                />
                                <Button v-if="canManageRooms" label="Import" icon="pi pi-upload" severity="secondary" outlined @click="openImport" />
                                <Button v-if="canManageRooms" label="Add Room" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Quick Filters -->
                    <div class="flex flex-wrap gap-2 mb-4">
                        <Button
                            v-for="qf in quickFilters"
                            :key="qf.value"
                            :label="qf.label"
                            :icon="qf.icon"
                            size="small"
                            :severity="quickFilter === qf.value ? 'success' : 'secondary'"
                            :outlined="quickFilter !== qf.value"
                            @click="selectQuickFilter(qf.value)"
                        />
                    </div>

                    <!-- Advanced Filters -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 mb-4 neu-form">
                        <Select
                            v-model="building"
                            :options="buildings"
                            placeholder="Building"
                            showClear
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <Select
                            v-model="floor"
                            :options="floors"
                            placeholder="Floor"
                            showClear
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <Select
                            v-model="roomTypeFilter"
                            :options="roomTypes"
                            placeholder="Room Type"
                            showClear
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <Select
                            v-model="collegeFilter"
                            :options="colleges"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="College"
                            showClear
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <Select
                            v-model="departmentFilter"
                            :options="departmentFilterOptions"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Program"
                            showClear
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <Select
                            v-model="statusFilter"
                            :options="['Active', 'Inactive']"
                            placeholder="Status"
                            showClear
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                    </div>

                    <!-- Rooms Table -->
                    <DataTable
                        :value="rooms.data"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden"
                        :class="isDark ? 'neu-table-dark' : ''"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="rooms.per_page"
                        :totalRecords="rooms.total"
                        :first="(rooms.current_page - 1) * rooms.per_page"
                        :rowClass="() => (isDark ? 'cursor-pointer' : 'cursor-pointer hover:bg-slate-50')"
                        @page="onPage"
                        @row-click="onRowClick"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No rooms found.</p>
                                <p v-if="canManageRooms" class="text-slate-400 text-sm mt-1">
                                    Click "Add Room" to create your first room.
                                </p>
                                <Button
                                    v-if="canManageRooms"
                                    label="Add Room"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="room_name" header="Room Name" style="width: 16rem" />
                        <Column field="building" header="Building" style="width: 12rem" />
                        <Column header="Floor" style="width: 7rem">
                            <template #body="{ data }">
                                {{ data.floor || '—' }}
                            </template>
                        </Column>
                        <Column header="Room Type" style="width: 13rem">
                            <template #body="{ data }">
                                {{ data.room_type }}
                            </template>
                        </Column>
                        <Column header="College" style="width: 14rem">
                            <template #body="{ data }">
                                {{ data.college?.name ?? 'All Colleges' }}
                            </template>
                        </Column>
                        <Column header="Department / Program" style="width: 13rem">
                            <template #body="{ data }">
                                {{ data.department?.name ?? 'All Programs' }}
                            </template>
                        </Column>
                        <Column style="width: 14rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Utilization
                                    <InfoPopover
                                        title="Utilization"
                                        :paragraphs="[
                                            'Weekly scheduled hours against this room\'s maximum available hours. Click a row\'s bar to open the full room schedule.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <button
                                    type="button"
                                    class="w-full text-left group"
                                    :title="`Weekly scheduled hours: ${data.utilization?.scheduled_hours ?? 0} / ${data.utilization?.max_hours ?? 0} hrs. Click to view the full room schedule.`"
                                    @click.stop="openSchedule(data)"
                                >
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-medium text-slate-600 group-hover:text-slate-900">
                                            {{ data.utilization?.scheduled_hours ?? 0 }} / {{ data.utilization?.max_hours ?? 0 }} hrs
                                        </span>
                                        <Tag
                                            :value="`${data.utilization?.utilization_percent ?? 0}%`"
                                            :severity="utilizationSeverity(data.utilization?.utilization_status)"
                                        />
                                    </div>
                                    <ProgressBar
                                        :value="Math.min(100, data.utilization?.utilization_percent ?? 0)"
                                        :showValue="false"
                                        style="height: 8px"
                                    />
                                    <span class="text-xs text-slate-400">
                                        {{ data.utilization?.remaining_hours ?? 0 }} hrs remaining
                                    </span>
                                </button>
                            </template>
                        </Column>
                        <Column style="width: 15rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Capacity &amp; Availability
                                    <InfoPopover
                                        title="Capacity &amp; Availability"
                                        :bullets="[
                                            'Available — open slots remain this week.',
                                            'Fully Booked — no open slots remain, but no conflicts.',
                                            'Overbooked / Conflict — two or more classes overlap in this room; must be resolved before saving further schedules.',
                                            'The seat count is this room\'s fixed capacity minus its peak scheduled enrollment.',
                                        ]"
                                        width="w-72"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <Tag
                                    :value="data.utilization?.availability ?? '—'"
                                    :severity="availabilitySeverity(data.utilization?.availability)"
                                />
                                <div v-if="data.utilization?.has_conflict" class="text-xs text-red-600 mt-1 flex items-center gap-1">
                                    <i class="pi pi-exclamation-triangle"></i> Conflict
                                </div>
                                <div
                                    v-if="data.utilization?.capacity_exceeded"
                                    class="text-xs text-red-600 mt-1"
                                    :title="`Peak assigned enrollment ${data.utilization.peak_enrollment} exceeds capacity ${data.utilization.capacity}`"
                                >
                                    Capacity exceeded — {{ data.utilization.peak_enrollment }} / {{ data.capacity }}
                                </div>
                                <div v-else-if="data.utilization" class="text-xs text-slate-400 mt-1">
                                    {{ data.utilization.seats_available }} / {{ data.capacity }} seats available
                                </div>
                                <div v-else class="text-xs text-slate-400 mt-1">
                                    {{ data.capacity }} seats
                                </div>
                            </template>
                        </Column>
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.status"
                                    :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 10rem">
                            <template #body="{ data }">
                                <div class="flex gap-1" @click.stop>
                                    <Button
                                        icon="pi pi-calendar"
                                        text
                                        rounded
                                        severity="info"
                                        size="small"
                                        aria-label="View Schedule"
                                        @click="openSchedule(data)"
                                    />
                                    <template v-if="canManageRooms">
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
                                            icon="pi pi-trash"
                                            text
                                            rounded
                                            severity="danger"
                                            size="small"
                                            aria-label="Delete"
                                            @click="onDeleteRoom(data)"
                                        />
                                    </template>
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
            </div>
        </div>

        <!-- Add Room Dialog -->
        <Dialog
            v-model:visible="addRoomVisible"
            modal
            :header="editingRoom ? 'Edit Room' : 'Add Room'"
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeAddRoom"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveRoom()">
                <!-- Room Name -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="room_name" class="text-sm font-medium text-slate-700">
                        Room Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
                        id="room_name"
                        v-model="roomForm.room_name"
                        placeholder="e.g. Computer Laboratory 2"
                        :invalid="!!roomForm.errors.room_name || !!roomForm.errors.room_code"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.room_name || roomForm.errors.room_code" class="text-red-500">
                        {{ roomForm.errors.room_name || roomForm.errors.room_code }}
                    </small>
                </div>

                <!-- Building -->
                <div class="flex flex-col gap-1">
                    <label for="building" class="text-sm font-medium text-slate-700">
                        Building <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
                        id="building"
                        v-model="roomForm.building"
                        placeholder="e.g. Main Building"
                        :invalid="!!roomForm.errors.building"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.building" class="text-red-500">
                        {{ roomForm.errors.building }}
                    </small>
                </div>

                <!-- Floor -->
                <div class="flex flex-col gap-1">
                    <label for="floor" class="text-sm font-medium text-slate-700">Floor</label>
                    <InputText
                        v-uppercase
                        id="floor"
                        v-model="roomForm.floor"
                        placeholder="e.g. 2nd Floor"
                        :invalid="!!roomForm.errors.floor"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.floor" class="text-red-500">
                        {{ roomForm.errors.floor }}
                    </small>
                </div>

                <!-- Room Type -->
                <div class="flex flex-col gap-1">
                    <label for="room_type" class="text-sm font-medium text-slate-700">
                        Room Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="room_type"
                        v-model="roomForm.room_type"
                        :options="roomTypes"
                        placeholder="Select a room type"
                        :invalid="!!roomForm.errors.room_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="roomForm.errors.room_type" class="text-red-500">
                        {{ roomForm.errors.room_type }}
                    </small>
                </div>

                <!-- College -->
                <div class="flex flex-col gap-1">
                    <label for="college_id" class="text-sm font-medium text-slate-700">College</label>
                    <Select
                        id="college_id"
                        v-model="roomForm.college_id"
                        :options="collegeOptions"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="All Colleges"
                        showClear
                        :invalid="!!roomForm.errors.college_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        @update:modelValue="onCollegeChange"
                    />
                    <small class="text-slate-400">Leave as "All Colleges" for a room shared by everyone.</small>
                    <small v-if="roomForm.errors.college_id" class="text-red-500">
                        {{ roomForm.errors.college_id }}
                    </small>
                </div>

                <!-- Department / Program -->
                <div class="flex flex-col gap-1">
                    <label for="department_id" class="text-sm font-medium text-slate-700">Department / Program</label>
                    <Select
                        id="department_id"
                        v-model="roomForm.department_id"
                        :options="departmentOptions"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="All Programs"
                        showClear
                        :disabled="!roomForm.college_id"
                        :invalid="!!roomForm.errors.department_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small class="text-slate-400">
                        {{ roomForm.college_id ? 'Leave as "All Programs" to open this room to every program in the selected College.' : 'Select a College first to assign a specific Program.' }}
                    </small>
                    <small v-if="roomForm.errors.department_id" class="text-red-500">
                        {{ roomForm.errors.department_id }}
                    </small>
                </div>

                <!-- Capacity -->
                <div class="flex flex-col gap-1">
                    <label for="capacity" class="text-sm font-medium text-slate-700">
                        Capacity <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="capacity"
                        v-model="roomForm.capacity"
                        :min="1"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!roomForm.errors.capacity"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="roomForm.errors.capacity" class="text-red-500">
                        {{ roomForm.errors.capacity }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="status"
                        v-model="roomForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!roomForm.errors.status"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="roomForm.errors.status" class="text-red-500">
                        {{ roomForm.errors.status }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="roomForm.remarks"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this room"
                        :invalid="!!roomForm.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.remarks" class="text-red-500">
                        {{ roomForm.errors.remarks }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddRoom" />
                <Button
                    :label="editingRoom ? 'Update Room' : 'Save Room'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="roomForm.processing"
                    @click="onSaveRoom()"
                />
            </template>
        </Dialog>

        <!-- Bulk Import Dialog -->
        <Dialog
            v-model:visible="importVisible"
            modal
            header="Import Rooms"
            :style="{ width: '680px' }"
            :breakpoints="{ '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <div class="flex flex-col gap-4">
                <p class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    Upload a CSV of rooms to add them all at once — handy when setting up a whole new building or
                    laboratory wing instead of adding each room one by one.
                </p>

                <a
                    :href="route('scheduling.rooms.import.template')"
                    class="inline-flex w-fit items-center gap-2 text-sm font-medium text-blue-600 hover:underline"
                >
                    <i class="pi pi-download"></i>
                    Download CSV template
                </a>

                <div>
                    <label class="mb-1 block text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">CSV File</label>
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="neu-inset w-full rounded-xl border-none p-2 text-sm"
                        :class="isDark ? 'text-slate-200' : ''"
                        @change="onImportFileChange"
                    />
                    <small v-if="importFileName" class="mt-1 block text-slate-400">Selected: {{ importFileName }}</small>
                    <small v-if="importForm.errors.file" class="mt-1 block text-red-500">{{ importForm.errors.file }}</small>
                </div>

                <!-- Preview overview — read straight from the file the
                     moment it's chosen, before anything is saved, so a
                     room that already exists is flagged up front
                     instead of only surfacing as an error after Import
                     is clicked. -->
                <div v-if="previewLoading" class="flex items-center gap-2 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    <i class="pi pi-spin pi-spinner"></i>
                    Reading file…
                </div>

                <div v-else-if="previewError" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    {{ previewError }}
                </div>

                <div v-else-if="previewRows.length" class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <Tag severity="success" :value="`${previewSummary.new} new`" />
                        <Tag severity="warn" :value="`${previewSummary.exists} already exist`" v-if="previewSummary.exists" />
                        <Tag severity="danger" :value="`${previewSummary.error} invalid`" v-if="previewSummary.error" />
                        <span :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            — rooms already on file will be skipped, not duplicated.
                        </span>
                    </div>

                    <DataTable :value="previewRows" size="small" scrollable scrollHeight="260px" class="text-sm">
                        <Column field="room_name" header="Name" style="width: 180px" />
                        <Column field="building" header="Building" style="width: 160px" />
                        <Column header="Status" style="min-width: 220px">
                            <template #body="{ data }">
                                <div class="flex flex-col gap-0.5">
                                    <Tag :severity="previewStatusMeta[data.status].severity" :value="previewStatusMeta[data.status].label" class="w-fit" />
                                    <small v-if="data.message" :class="data.status === 'error' ? 'text-red-500' : (isDark ? 'text-slate-400' : 'text-slate-500')">
                                        {{ data.message }}
                                    </small>
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </div>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="importVisible = false" />
                <Button
                    label="Import"
                    icon="pi pi-upload"
                    severity="success"
                    :loading="importForm.processing"
                    :disabled="previewLoading || (previewRows.length > 0 && previewSummary.new === 0)"
                    @click="submitImport"
                />
            </template>
        </Dialog>

        <!-- Room Schedule Details -->
        <Dialog
            v-model:visible="scheduleVisible"
            modal
            :header="scheduleRoom ? scheduleRoom.room_name : 'Room Schedule'"
            :style="{ width: '900px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :contentStyle="{ maxHeight: '70vh', overflowY: 'auto' }"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeSchedule"
        >
            <div v-if="scheduleLoading" class="py-16 text-center text-slate-400">
                <i class="pi pi-spin pi-spinner text-2xl"></i>
                <p class="mt-2 text-sm">Loading room schedule…</p>
            </div>

            <div v-else-if="scheduleRoom && scheduleSummary" class="space-y-5">
                <!-- Room info -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div class="neu-inset rounded-xl p-3">
                        <p class="text-xs text-slate-400">Room Type</p>
                        <p class="font-medium text-slate-700">{{ scheduleRoom.room_type }}</p>
                    </div>
                    <div class="neu-inset rounded-xl p-3">
                        <p class="text-xs text-slate-400">Capacity</p>
                        <p class="font-medium text-slate-700">{{ scheduleRoom.capacity }}</p>
                    </div>
                    <div class="neu-inset rounded-xl p-3">
                        <p class="text-xs text-slate-400">College</p>
                        <p class="font-medium text-slate-700">{{ scheduleRoom.college?.name ?? 'All Colleges' }}</p>
                    </div>
                    <div class="neu-inset rounded-xl p-3">
                        <p class="text-xs text-slate-400">Program</p>
                        <p class="font-medium text-slate-700">{{ scheduleRoom.department?.name ?? 'All Programs' }}</p>
                    </div>
                </div>

                <!-- Quick action — jumps straight to Add Subject Recommendation
                     (the full panel is further down, near "Recommended
                     Subjects"), so it's reachable without scrolling. -->
                <div class="flex justify-end">
                    <Button
                        label="Add Subject Recommendation"
                        icon="pi pi-plus"
                        severity="success"
                        size="small"
                        @click="roomRecommendedSubjectsRef?.openAdd()"
                    />
                </div>

                <!-- Utilization -->
                <div class="rounded-xl p-4 neu-inset">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-slate-600">
                            {{ scheduleSummary.scheduled_hours }} / {{ scheduleSummary.max_hours }} hrs weekly
                        </span>
                        <Tag
                            :value="`${scheduleSummary.utilization_percent}% Utilized`"
                            :severity="utilizationSeverity(scheduleSummary.utilization_status)"
                        />
                    </div>
                    <ProgressBar :value="Math.min(100, scheduleSummary.utilization_percent)" :showValue="false" style="height: 10px" />
                    <p class="text-xs text-slate-400 mt-1">{{ scheduleSummary.remaining_hours }} hrs remaining this week</p>
                </div>

                <!-- Utilization by day -->
                <div>
                    <p class="text-sm font-semibold text-slate-700 mb-2">Utilization by Day</p>
                    <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                        <div
                            v-for="(day, name) in scheduleSummary.by_day"
                            :key="name"
                            class="neu-inset rounded-lg p-2 text-center"
                        >
                            <p class="text-xs text-slate-500">{{ name }}</p>
                            <p class="text-sm font-bold text-slate-700">{{ day.utilization_percent }}%</p>
                        </div>
                    </div>
                </div>

                <!-- Weekly Timetable -->
                <div>
                    <p class="text-sm font-semibold text-slate-700 mb-2">Weekly Timetable</p>
                    <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                        <div v-for="(day, name) in scheduleTimetable" :key="name" class="neu-inset rounded-lg p-3">
                            <p class="text-xs font-semibold text-slate-500 mb-2">{{ name }}</p>

                            <div v-if="day.booked.length" class="space-y-1 mb-2">
                                <div
                                    v-for="slot in day.booked"
                                    :key="slot.section_subject_id"
                                    class="flex items-center justify-between text-xs rounded px-2 py-1"
                                    :class="isDark ? 'bg-white/5' : 'bg-white'"
                                >
                                    <span class="font-medium text-slate-700">{{ formatTime12h(slot.start_time) }}–{{ formatTime12h(slot.end_time) }}</span>
                                    <span class="text-slate-600">{{ slot.subject }} · {{ slot.section }}</span>
                                    <span class="text-slate-500">{{ slot.faculty }}</span>
                                </div>
                            </div>
                            <p v-else class="text-xs text-slate-400 italic mb-2">No classes scheduled.</p>

                            <div v-if="day.available.length" class="flex flex-wrap gap-1">
                                <span
                                    v-for="(gap, i) in day.available"
                                    :key="i"
                                    class="text-[11px] rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5"
                                >
                                    {{ formatTime12h(gap.start_time) }}–{{ formatTime12h(gap.end_time) }} open
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recommended Subjects -->
                <div class="rounded-xl p-4 neu-inset">
                    <RoomRecommendedSubjects ref="roomRecommendedSubjectsRef" :room="scheduleRoom" />
                </div>
            </div>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="closeSchedule" />
            </template>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (applied only when the app theme is dark) lets these rules repaint
   PrimeVue internals and Tailwind utility classes without touching the
   light-mode styling used everywhere else. Mirrors the pattern already
   used on the Faculty Master and Curriculum pages. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

/* Summary cards + timetable panels are plain Tailwind divs, not p-card,
   so they need explicit background overrides. */
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.05) !important; }
.dark-scope :deep(.bg-emerald-50) { background-color: rgba(16, 185, 129, 0.12) !important; }
.dark-scope :deep(.text-emerald-700) { color: #6EE7B7 !important; }
.dark-scope :deep(.text-emerald-600) { color: #34D399 !important; }
.dark-scope :deep(.text-amber-600) { color: #FBBF24 !important; }
.dark-scope :deep(.text-red-600) { color: #F87171 !important; }
.dark-scope :deep(.hover\:bg-slate-50:hover) { background-color: rgba(255, 255, 255, 0.06) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-textarea),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder),
.dark-scope :deep(.p-textarea::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-select-label.p-placeholder),
.dark-scope :deep(.p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.18) !important; color: #F8FAFC !important; }

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

.dark-scope :deep(.p-progressbar) { background: rgba(255, 255, 255, 0.1) !important; }

/* Dialog + overlay panels render outside the scoped tree (teleported to
   body), so they need :global() rather than :deep(). */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-slate-400),
:global(.dark-scope.p-dialog small.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect),
:global(.dark-scope.p-dialog .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder),
:global(.dark-scope.p-dialog .p-textarea::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder),
:global(.dark-scope.p-dialog .p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.18) !important; color: #F8FAFC !important; }

/* Room Schedule dialog content: plain Tailwind cards + timetable rows. */
:global(.dark-scope.p-dialog .rounded-xl.border-slate-100),
:global(.dark-scope.p-dialog .rounded-lg.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .bg-slate-50) { background-color: rgba(255, 255, 255, 0.06) !important; }
:global(.dark-scope.p-dialog .bg-emerald-50) { background-color: rgba(16, 185, 129, 0.12) !important; }
:global(.dark-scope.p-dialog .text-emerald-700) { color: #6EE7B7 !important; }
:global(.dark-scope.p-dialog .text-slate-700),
:global(.dark-scope.p-dialog .text-slate-600) { color: #E2E8F0 !important; }
:global(.dark-scope.p-dialog .text-slate-500),
:global(.dark-scope.p-dialog .text-slate-400) { color: #94A3B8 !important; }

:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-option.p-select-option-selected),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option.p-multiselect-option-selected) { background: rgba(37, 99, 235, 0.25) !important; }
</style>