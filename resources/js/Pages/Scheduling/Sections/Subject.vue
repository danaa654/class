<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackLink from '@/Components/BackLink.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import DatePicker from 'primevue/datepicker';
import InputNumber from 'primevue/inputnumber';
import Dialog from 'primevue/dialog';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Menu from 'primevue/menu';
import Toast from 'primevue/toast';
import InfoPopover from '@/Components/InfoPopover.vue';

const props = defineProps({
    section: { type: Object, required: true },
    sectionSubjects: { type: Array, default: () => [] },
    curriculumSubjects: { type: Array, default: () => [] },
    allActiveSubjects: { type: Array, default: () => [] },
    activeFaculty: { type: Array, default: () => [] },
    activeRooms: { type: Array, default: () => [] },
});

const toast = useToast();

/* ------------------------------------------------------------------ */
/* Row state — a local editable copy so cells update instantly         */
/* without waiting on a full Inertia reload.                           */
/* ------------------------------------------------------------------ */

const rows = ref(
    props.sectionSubjects.map((row) => ({
        ...row,
        days: row.days ? row.days.split(',').filter(Boolean) : [],
    })),
);

// Per-row, per-field validation error message + saving indicator.
const rowState = reactive({});
const stateFor = (rowId) => {
    if (!rowState[rowId]) {
        rowState[rowId] = { errors: {}, saving: {} };
    }
    return rowState[rowId];
};

/* ------------------------------------------------------------------ */
/* Days                                                                  */
/* ------------------------------------------------------------------ */

const dayOptions = [
    { label: 'Monday', value: 'Mon' },
    { label: 'Tuesday', value: 'Tue' },
    { label: 'Wednesday', value: 'Wed' },
    { label: 'Thursday', value: 'Thu' },
    { label: 'Friday', value: 'Fri' },
    { label: 'Saturday', value: 'Sat' },
];

// Quick-pick common combinations shown above the multi-select list.
const dayPresets = [
    { label: 'MWF', value: ['Mon', 'Wed', 'Fri'] },
    { label: 'MW', value: ['Mon', 'Wed'] },
    { label: 'TTH', value: ['Tue', 'Thu'] },
    { label: 'WF', value: ['Wed', 'Fri'] },
    { label: 'SAT', value: ['Sat'] },
];

// Compact display like "MWF", "TTH", "SAT" — used in the Days cell
// instead of PrimeVue's default comma/chip rendering.
const dayAbbreviations = { Mon: 'M', Tue: 'T', Wed: 'W', Thu: 'TH', Fri: 'F', Sat: 'SAT' };
const orderedDayTokens = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const formatDays = (selected) => {
    if (!selected || selected.length === 0) return '';
    return orderedDayTokens
        .filter((token) => selected.includes(token))
        .map((token) => dayAbbreviations[token])
        .join('');
};

const applyDayPreset = (row, preset) => {
    row.days = [...preset.value];
    onDaysChange(row, row.days);
};

/* ------------------------------------------------------------------ */
/* Faculty — searchable, filtered to those qualified for the subject   */
/* ------------------------------------------------------------------ */

// This Section's own College — e.g. a BSIT section resolves to the
// College of Computer Studies (CCS), a BSHM section to SHTM, a BSCRIM
// section to the College of Criminology, and so on. Same relation
// chain the backend uses (section->major->department->college_id) for
// the equivalent Room/Faculty RBAC scoping.
const sectionCollegeId = computed(() => props.section.major?.department?.college_id ?? null);

const facultyOptionsFor = (row) => {
    const subject = row.subject;
    if (!subject) return [];

    const isGenEd = subject.category === 'General Education';

    return props.activeFaculty
        .filter((faculty) => {
            if (isGenEd && faculty.faculty_category === 'General Education Faculty') {
                return true;
            }
            if (faculty.qualified_subject_ids.includes(subject.id)) return true;
            // Major/Minor subjects fall back to a College match — any
            // active faculty from the subject's own College is a
            // reasonable manual pick even without an explicit Teaching
            // Qualification on file.
            if (!isGenEd && sectionCollegeId.value !== null) {
                return faculty.college_id === sectionCollegeId.value;
            }
            return false;
        })
        .map((faculty) => ({ label: faculty.full_name, value: faculty.id }));
};

/* ------------------------------------------------------------------ */
/* Rooms — all active rooms                                            */
/* ------------------------------------------------------------------ */

const roomOptions = computed(() =>
    props.activeRooms.map((room) => ({
        label: `${room.room_name} (${room.capacity})`,
        value: room.id,
        capacity: room.capacity,
    })),
);

const roomCapacityFor = (roomId) => roomOptions.value.find((r) => r.value === roomId)?.capacity ?? undefined;

/* ------------------------------------------------------------------ */
/* Time pickers — DatePicker bound to a JS Date, converted to "HH:mm"  */
/* on save.                                                             */
/* ------------------------------------------------------------------ */

const timeStringToDate = (value) => {
    if (!value) return null;
    const [hours, minutes] = value.split(':').map(Number);
    const date = new Date();
    date.setHours(hours, minutes, 0, 0);
    return date;
};

const dateToTimeString = (date) => {
    if (!date) return null;
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
};

const startTimeModel = (row) => timeStringToDate(row.start_time);
const endTimeModel = (row) => timeStringToDate(row.end_time);

/* ------------------------------------------------------------------ */
/* Status badge                                                         */
/* ------------------------------------------------------------------ */

const statusSeverity = (status) => {
    switch (status) {
        case 'Scheduled':
            return 'success';
        case 'Conflict':
            return 'danger';
        default:
            return 'secondary';
    }
};

/* ------------------------------------------------------------------ */
/* Auto-save — direct fetch() so the fresh row + validation errors     */
/* come back without a full Inertia page reload.                       */
/* ------------------------------------------------------------------ */

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const submit = async (row, field, payload) => {
    const state = stateFor(row.id);
    const errorKey = field === 'start_time_date' ? 'start_time' : field === 'end_time_date' ? 'end_time' : field;
    delete state.errors[errorKey];
    state.saving[field] = true;

    try {
        const response = await fetch(
            route('scheduling.sections.subjects.schedule', { section: props.section.id, subject: row.id }),
            {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(payload),
            },
        );

        const data = await response.json();

        if (!response.ok) {
            Object.assign(state.errors, data.errors ?? {});
            toast.add({
                severity: 'error',
                summary: 'Cannot save',
                detail: Object.values(data.errors ?? {})[0] ?? 'Validation failed.',
                life: 5000,
            });
            return;
        }

        const fresh = data.sectionSubject;
        Object.assign(row, {
            ...fresh,
            days: fresh.days ? fresh.days.split(',').filter(Boolean) : [],
        });

        toast.add({ severity: 'success', summary: 'Saved', detail: 'Schedule updated.', life: 2000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not save changes. Please try again.', life: 5000 });
    } finally {
        state.saving[field] = false;
    }
};

const onFacultyChange = (row, value) => submit(row, 'faculty_id', { faculty_id: value });
const onRoomChange = (row, value) => submit(row, 'room_id', { room_id: value });
const onDaysChange = (row, value) => submit(row, 'days', { days: value });
const onStartTimeChange = (row, date) => submit(row, 'start_time_date', { start_time: dateToTimeString(date) });
const onEndTimeChange = (row, date) => submit(row, 'end_time_date', { end_time: dateToTimeString(date) });

let capacityTimer = null;
const onCapacityChange = (row, value) => {
    clearTimeout(capacityTimer);
    capacityTimer = setTimeout(() => submit(row, 'capacity', { capacity: value }), 500);
};

/* ------------------------------------------------------------------ */
/* Row actions — Duplicate Row, Delete Row, View Details               */
/* ------------------------------------------------------------------ */

// A Subject can only appear once per Section, so "Duplicate Row"
// copies this row's schedule (Faculty/Room/Days/Time/Capacity) onto a
// clipboard that can be pasted onto a different (still-unscheduled or
// to-be-overwritten) row from that row's own Actions menu.
const scheduleClipboard = ref(null);

const menuRefs = ref({});
const setMenuRef = (rowId) => (el) => {
    menuRefs.value[rowId] = el;
};
const menuItemsFor = (row) => {
    const items = [
        {
            label: 'Duplicate Row',
            icon: 'pi pi-copy',
            command: () => onDuplicateRow(row),
        },
    ];

    if (scheduleClipboard.value) {
        items.push({
            label: 'Paste Schedule',
            icon: 'pi pi-clipboard',
            command: () => onPasteSchedule(row),
        });
    }

    items.push(
        {
            label: 'View Details',
            icon: 'pi pi-eye',
            command: () => onViewDetails(row),
        },
        {
            label: 'Delete Row',
            icon: 'pi pi-trash',
            class: 'text-red-500',
            command: () => onDeleteRow(row),
        },
    );

    return items;
};
const toggleMenu = (rowId, event) => menuRefs.value[rowId]?.toggle(event);

const onDuplicateRow = (row) => {
    scheduleClipboard.value = {
        faculty_id: row.faculty_id,
        room_id: row.room_id,
        days: [...row.days],
        start_time: row.start_time,
        end_time: row.end_time,
        capacity: row.capacity,
    };
    toast.add({
        severity: 'info',
        summary: 'Schedule copied',
        detail: 'Open another row\'s Actions menu and choose "Paste Schedule" to apply it.',
        life: 4000,
    });
};

const onPasteSchedule = (row) => {
    if (!scheduleClipboard.value) return;
    const clip = scheduleClipboard.value;

    Object.assign(row, {
        faculty_id: clip.faculty_id,
        room_id: clip.room_id,
        days: [...clip.days],
        start_time: clip.start_time,
        end_time: clip.end_time,
        capacity: clip.capacity,
    });

    submit(row, 'paste', {
        faculty_id: clip.faculty_id,
        room_id: clip.room_id,
        days: clip.days,
        start_time: clip.start_time,
        end_time: clip.end_time,
        capacity: clip.capacity,
    });
};

const detailsDialogVisible = ref(false);
const detailsRow = ref(null);
const onViewDetails = (row) => {
    detailsRow.value = row;
    detailsDialogVisible.value = true;
};

const onDeleteRow = async (row) => {
    const result = await Swal.fire({
        title: 'Remove this subject?',
        text: `${row.subject?.subject_code} will be removed from this section. This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        confirmButtonColor: '#DC2626',
        cancelButtonText: 'Cancel',
    });

    if (!result.isConfirmed) return;

    deleteForm.delete(
        route('scheduling.section-subjects.destroy', { section: props.section.id, subject: row.subject.id }),
        {
            preserveScroll: true,
            onSuccess: () => {
                rows.value = rows.value.filter((r) => r.id !== row.id);
                toast.add({ severity: 'success', summary: 'Removed', detail: 'Subject removed from the section.', life: 3000 });
            },
        },
    );
};
const deleteForm = useForm({});

/* ------------------------------------------------------------------ */
/* Add Subject — unchanged workflow: generate from curriculum, or      */
/* pick subjects manually.                                              */
/* ------------------------------------------------------------------ */

const addDialogVisible = ref(false);
const activeTab = ref('curriculum');
const openAddDialog = () => {
    activeTab.value = 'curriculum';
    manualForm.reset();
    manualForm.clearErrors();
    addDialogVisible.value = true;
};
const closeAddDialog = () => {
    addDialogVisible.value = false;
};

const pendingCurriculumSubjects = computed(() => props.curriculumSubjects.filter((s) => !s.already_assigned));

const generateForm = useForm({});
const onGenerateCurriculum = () => {
    generateForm.post(route('scheduling.section-subjects.generate-curriculum', props.section.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeAddDialog();
        },
    });
};

const manualForm = useForm({
    source: 'Manual',
    subject_ids: [],
});
const onAddManual = () => {
    manualForm.post(route('scheduling.section-subjects.store', props.section.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeAddDialog();
        },
    });
};
</script>

<template>
    <Head :title="`${section.section_code} — Scheduling Workspace`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Subject Assignment Workspace</span>
        </template>

        <div class="max-w-[100rem] mx-auto w-full">
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
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                    {{ section.section_code }} — {{ section.section_name }}
                    <InfoPopover
                        title="Subject Assignment Workspace"
                        :paragraphs="[
                            'Assign Faculty, Room, Days, and Time for this section\'s subjects directly in the table.',
                        ]"
                        :bullets="[
                            'Each field saves automatically as soon as it\'s changed — there is no separate Save button.',
                            'The server still validates room, faculty, and time conflicts on every change; a Conflict status means one of those checks failed.',
                            'Faculty options are filtered to those qualified to teach each subject.',
                        ]"
                    />
                </h1>
                <p class="mt-1 text-slate-500">
                    Assign Faculty, Room, and Time directly in the table. Changes save automatically.
                </p>
                </div>
            </div>

            <!-- Section Information -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm mb-6">
                <template #content>
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-6">
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Section</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.section_code }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Major</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.major?.name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Curriculum</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.curriculum?.code || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Academic Year</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.academic_year }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Semester</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.semester }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Year Level</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.year_level }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Est. Students</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.estimated_students }}</p>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Scheduling workspace -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                        <template #start>
                            <span class="text-sm text-slate-500">{{ rows.length }} subject(s) in this section</span>
                        </template>
                        <template #end>
                            <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAddDialog" />
                        </template>
                    </Toolbar>

                    <DataTable
                        :value="rows"
                        dataKey="id"
                        class="rounded-xl overflow-hidden schedule-table"
                        stripedRows
                        responsiveLayout="scroll"
                        scrollable
                        scrollHeight="flex"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No subjects assigned yet.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Subject" to load from the curriculum or select manually.
                                </p>
                                <Button label="Add Subject" icon="pi pi-plus" severity="success" class="mt-3" @click="openAddDialog" />
                            </div>
                        </template>

                        <Column header="Subject Code" style="min-width: 9rem">
                            <template #body="{ data }">
                                <span class="font-medium text-slate-700">{{ data.subject?.subject_code }}</span>
                            </template>
                        </Column>

                        <Column header="Subject Title" style="min-width: 14rem">
                            <template #body="{ data }">
                                {{ data.subject?.subject_title }}
                            </template>
                        </Column>

                        <!-- Faculty -->
                        <Column header="Faculty" style="min-width: 14rem">
                            <template #body="{ data }">
                                <Select
                                    v-model="data.faculty_id"
                                    :options="facultyOptionsFor(data)"
                                    optionLabel="label"
                                    optionValue="value"
                                    filter
                                    showClear
                                    placeholder="Select faculty"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.faculty_id }"
                                    :loading="stateFor(data.id).saving.faculty_id"
                                    :emptyMessage="'No qualified faculty'"
                                    :emptyFilterMessage="'No qualified faculty'"
                                    @update:modelValue="(v) => onFacultyChange(data, v)"
                                />
                                <p v-if="stateFor(data.id).errors.faculty_id" class="text-red-500 text-xs mt-1">
                                    {{ stateFor(data.id).errors.faculty_id }}
                                </p>
                            </template>
                        </Column>

                        <!-- Room -->
                        <Column header="Room" style="min-width: 13rem">
                            <template #body="{ data }">
                                <Select
                                    v-model="data.room_id"
                                    :options="roomOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    filter
                                    showClear
                                    placeholder="Select room"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.room_id }"
                                    :loading="stateFor(data.id).saving.room_id"
                                    @update:modelValue="(v) => onRoomChange(data, v)"
                                />
                                <p v-if="stateFor(data.id).errors.room_id" class="text-red-500 text-xs mt-1">
                                    {{ stateFor(data.id).errors.room_id }}
                                </p>
                            </template>
                        </Column>

                        <!-- Days -->
                        <Column header="Days" style="min-width: 11rem">
                            <template #body="{ data }">
                                <MultiSelect
                                    v-model="data.days"
                                    :options="dayOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select days"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.days }"
                                    :loading="stateFor(data.id).saving.days"
                                    @update:modelValue="(v) => onDaysChange(data, v)"
                                >
                                    <template #value="{ value, placeholder }">
                                        <span v-if="!value || value.length === 0" class="text-slate-400">{{ placeholder }}</span>
                                        <span v-else class="font-medium">{{ formatDays(value) }}</span>
                                    </template>
                                    <template #header>
                                        <div class="flex flex-wrap gap-1 px-3 pt-2 pb-1">
                                            <button
                                                v-for="preset in dayPresets"
                                                :key="preset.label"
                                                type="button"
                                                class="text-xs px-2 py-1 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-600"
                                                @click="applyDayPreset(data, preset)"
                                            >
                                                {{ preset.label }}
                                            </button>
                                        </div>
                                    </template>
                                </MultiSelect>
                                <p v-if="stateFor(data.id).errors.days" class="text-red-500 text-xs mt-1">
                                    {{ stateFor(data.id).errors.days }}
                                </p>
                            </template>
                        </Column>

                        <!-- Start Time -->
                        <Column header="Start Time" style="min-width: 9rem">
                            <template #body="{ data }">
                                <DatePicker
                                    :modelValue="startTimeModel(data)"
                                    timeOnly
                                    hourFormat="12"
                                    showIcon
                                    iconDisplay="input"
                                    placeholder="Start"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.start_time }"
                                    @update:modelValue="(v) => onStartTimeChange(data, v)"
                                />
                                <p v-if="stateFor(data.id).errors.start_time" class="text-red-500 text-xs mt-1">
                                    {{ stateFor(data.id).errors.start_time }}
                                </p>
                            </template>
                        </Column>

                        <!-- End Time -->
                        <Column header="End Time" style="min-width: 9rem">
                            <template #body="{ data }">
                                <DatePicker
                                    :modelValue="endTimeModel(data)"
                                    timeOnly
                                    hourFormat="12"
                                    showIcon
                                    iconDisplay="input"
                                    placeholder="End"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.end_time }"
                                    @update:modelValue="(v) => onEndTimeChange(data, v)"
                                />
                                <p v-if="stateFor(data.id).errors.end_time" class="text-red-500 text-xs mt-1">
                                    {{ stateFor(data.id).errors.end_time }}
                                </p>
                            </template>
                        </Column>

                        <!-- Capacity -->
                        <Column header="Capacity" style="min-width: 8rem">
                            <template #body="{ data }">
                                <InputNumber
                                    v-model="data.capacity"
                                    :min="1"
                                    :max="roomCapacityFor(data.room_id)"
                                    showButtons
                                    buttonLayout="horizontal"
                                    class="w-full capacity-input"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.capacity }"
                                    @update:modelValue="(v) => onCapacityChange(data, v)"
                                >
                                    <template #incrementicon><i class="pi pi-plus text-xs"></i></template>
                                    <template #decrementicon><i class="pi pi-minus text-xs"></i></template>
                                </InputNumber>
                                <p v-if="stateFor(data.id).errors.capacity" class="text-red-500 text-xs mt-1">
                                    {{ stateFor(data.id).errors.capacity }}
                                </p>
                            </template>
                        </Column>

                        <!-- Status -->
                        <Column style="width: 8rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Status
                                    <InfoPopover
                                        title="Row Status"
                                        :bullets="[
                                            'Scheduled — Faculty, Room, Days, and Time are all set with no conflicts.',
                                            'Conflict — the server detected an overlap with another schedule; review and change the flagged field.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <Tag :value="data.status" :severity="statusSeverity(data.status)" />
                            </template>
                        </Column>

                        <!-- Actions -->
                        <Column header="Actions" style="width: 5rem">
                            <template #body="{ data }">
                                <Button
                                    icon="pi pi-ellipsis-v"
                                    text
                                    rounded
                                    severity="secondary"
                                    size="small"
                                    aria-label="Row actions"
                                    @click="toggleMenu(data.id, $event)"
                                />
                                <Menu :ref="setMenuRef(data.id)" :model="menuItemsFor(data)" :popup="true" />
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add Subject Dialog — unchanged workflow -->
        <Dialog
            v-model:visible="addDialogVisible"
            modal
            header="Add Subject"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            @hide="closeAddDialog"
        >
            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab value="curriculum">Load From Curriculum</Tab>
                    <Tab value="manual">Manual Selection</Tab>
                </TabList>
                <TabPanels>
                    <!-- Option 1: Load From Curriculum -->
                    <TabPanel value="curriculum">
                        <p class="text-sm text-slate-500 mb-3">
                            Every subject from this section's own Curriculum, Year Level, and Semester that hasn't been
                            added yet.
                        </p>

                        <div v-if="pendingCurriculumSubjects.length > 0" class="border border-slate-200 rounded-lg overflow-hidden mb-4">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th class="text-left px-3 py-2 font-medium">Code</th>
                                        <th class="text-left px-3 py-2 font-medium">Title</th>
                                        <th class="text-center px-3 py-2 font-medium">Units</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="subject in pendingCurriculumSubjects" :key="subject.id">
                                        <td class="px-3 py-2 font-medium text-slate-700">{{ subject.subject_code }}</td>
                                        <td class="px-3 py-2 text-slate-600">{{ subject.subject_title }}</td>
                                        <td class="px-3 py-2 text-center text-slate-600">{{ subject.units }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-slate-400 text-sm mb-4">
                            Every curriculum subject for this section has already been added.
                        </p>

                        <div class="flex justify-end">
                            <Button
                                label="Generate All"
                                icon="pi pi-download"
                                severity="success"
                                :loading="generateForm.processing"
                                :disabled="pendingCurriculumSubjects.length === 0"
                                @click="onGenerateCurriculum"
                            />
                        </div>
                    </TabPanel>

                    <!-- Option 2: Manual Selection -->
                    <TabPanel value="manual">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm font-medium text-slate-700">Search Subject</label>
                            <MultiSelect
                                v-model="manualForm.subject_ids"
                                :options="allActiveSubjects"
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

                        <div class="flex justify-end mt-4">
                            <Button
                                label="Add"
                                icon="pi pi-plus"
                                severity="success"
                                :loading="manualForm.processing"
                                :disabled="manualForm.subject_ids.length === 0"
                                @click="onAddManual"
                            />
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="closeAddDialog" />
            </template>
        </Dialog>

        <!-- View Details Dialog — read-only, not used for scheduling -->
        <Dialog
            v-model:visible="detailsDialogVisible"
            modal
            header="Subject Details"
            :style="{ width: '480px' }"
            :draggable="false"
        >
            <div v-if="detailsRow" class="flex flex-col gap-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-slate-400">Subject</span>
                    <span class="font-medium text-slate-700">
                        {{ detailsRow.subject?.subject_code }} — {{ detailsRow.subject?.subject_title }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Units</span>
                    <span class="font-medium text-slate-700">{{ detailsRow.subject?.units }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Faculty</span>
                    <span class="font-medium text-slate-700">{{ detailsRow.faculty?.full_name || '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Room</span>
                    <span class="font-medium text-slate-700">{{ detailsRow.room?.room_name || '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Days</span>
                    <span class="font-medium text-slate-700">{{ formatDays(detailsRow.days) || '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Time</span>
                    <span class="font-medium text-slate-700">
                        {{ detailsRow.start_time ?? '—' }} – {{ detailsRow.end_time ?? '—' }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Capacity</span>
                    <span class="font-medium text-slate-700">{{ detailsRow.capacity ?? '—' }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Status</span>
                    <Tag :value="detailsRow.status" :severity="statusSeverity(detailsRow.status)" />
                </div>
            </div>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="detailsDialogVisible = false" />
            </template>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
.schedule-table :deep(.p-select),
.schedule-table :deep(.p-multiselect),
.schedule-table :deep(.p-datepicker-input) {
    font-size: 0.85rem;
}

.capacity-input :deep(.p-inputnumber-input) {
    width: 3.5rem;
    text-align: center;
}
</style>