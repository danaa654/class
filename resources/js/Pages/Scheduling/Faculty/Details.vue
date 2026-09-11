<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
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
import ProgressBar from 'primevue/progressbar';
import InfoPopover from '@/Components/InfoPopover.vue';
import SendScheduleModal from '@/Pages/Reports/Partials/SendScheduleModal.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    faculty: { type: Object, required: true },
    colleges: { type: Array, default: () => [] },
    subjects: { type: Array, default: () => [] },
    hardCapUnits: { type: Number, default: 40 },
    // Gates the "Edit Information" button — false when this faculty
    // belongs to another College and the viewer got here via the "All
    // Faculty" filter (Dean/OIC read-mostly scope). See
    // FacultyPolicy::canAccess() / FacultyController::show().
    canEdit: { type: Boolean, default: true },
    // Term-scoped data backing the Workload tab's Print / Send via Email
    // buttons — same underlying flow as Reports > Schedule by Faculty's
    // single-faculty send, see FacultyController@show.
    scheduleMeta: {
        type: Object,
        default: () => ({ academic_term_id: null, academic_year: null, semester: null, is_finalized: false }),
    },
});

const toast = useToast();
const page = usePage();

// Same restriction as the Faculty Master (Index) edit modal: only
// Admin/Registrar may change the load ceiling directly. Everyone else
// (Dean/OIC/Assistant Dean) must go through a load request instead.
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
    },
);

const activeTab = ref('information');

const fullName = computed(() => {
    const middleInitial = props.faculty.middle_name ? ` ${props.faculty.middle_name.charAt(0)}.` : '';
    const suffix = props.faculty.suffix ? ` ${props.faculty.suffix}` : '';
    return `${props.faculty.last_name}, ${props.faculty.first_name}${middleInitial}${suffix}`;
});

/* ------------------------------------------------------------------ */
/* Workload tab — Print / Send via Email (mirrors Reports > Schedule    */
/* by Faculty's single-faculty flow, scoped to whichever term this     */
/* user is currently viewing rather than a report filter).             */
/* ------------------------------------------------------------------ */
const showSendScheduleModal = ref(false);

const sendScheduleFaculty = computed(() => ({
    id: props.faculty.id,
    full_name: fullName.value,
    faculty_id: props.faculty.faculty_id,
    email: props.faculty.email,
}));

const sendScheduleTerm = computed(() => {
    if (!props.scheduleMeta.academic_term_id) return null;
    return {
        id: props.scheduleMeta.academic_term_id,
        label: `${props.scheduleMeta.academic_year} · ${props.scheduleMeta.semester}`,
    };
});

function printFacultySchedule() {
    if (!props.scheduleMeta.academic_year) return;
    window.open(
        route('reports.print', {
            report_type: 'schedule_by_faculty',
            faculty_id: [props.faculty.id],
            academic_year: props.scheduleMeta.academic_year,
            semester: props.scheduleMeta.semester,
        }),
        '_blank',
    );
}

/* ------------------------------------------------------------------ */
/* Information tab                                                     */
/* ------------------------------------------------------------------ */

const employmentTypeOptions = ['Full-time', 'Part-time'];
const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const editVisible = ref(false);

const facultyForm = useForm({
    faculty_id: props.faculty.faculty_id,
    first_name: props.faculty.first_name,
    middle_name: props.faculty.middle_name,
    last_name: props.faculty.last_name,
    suffix: props.faculty.suffix,
    employment_type: props.faculty.employment_type,
    college_id: props.faculty.college_id,
    max_teaching_units: props.faculty.max_teaching_units,
    workload_type: props.faculty.workload_type ?? 'units',
    max_weekly_hours: props.faculty.max_weekly_hours,
    status: props.faculty.status,
    email: props.faculty.email,
    contact_number: props.faculty.contact_number,
    remarks: props.faculty.remarks,
});

// Whichever workload measurement the institution uses — 'units'
// (default, checked against Max Teaching Units) or 'hours' (checked
// against Max Weekly Hours instead). See FacultyWorkloadService.
const workloadTypeOptions = [
    { label: 'Teaching Units', value: 'units' },
    { label: 'Weekly Hours', value: 'hours' },
];

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

const openEdit = () => {
    facultyForm.clearErrors();
    facultyForm.faculty_id = props.faculty.faculty_id;
    facultyForm.first_name = props.faculty.first_name;
    facultyForm.middle_name = props.faculty.middle_name;
    facultyForm.last_name = props.faculty.last_name;
    facultyForm.suffix = props.faculty.suffix;
    facultyForm.employment_type = props.faculty.employment_type;
    facultyForm.college_id = props.faculty.college_id;
    facultyForm.max_teaching_units = props.faculty.max_teaching_units;
    facultyForm.workload_type = props.faculty.workload_type ?? 'units';
    facultyForm.max_weekly_hours = props.faculty.max_weekly_hours;
    facultyForm.status = props.faculty.status;
    facultyForm.email = props.faculty.email;
    facultyForm.contact_number = props.faculty.contact_number;
    facultyForm.remarks = props.faculty.remarks;
    editVisible.value = true;
};

const closeEdit = () => {
    editVisible.value = false;
    facultyForm.clearErrors();
};

const onSaveFaculty = () => {
    facultyForm.put(route('scheduling.faculty.update', props.faculty.id), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};

/* ------------------------------------------------------------------ */
/* Teaching Qualifications tab                                         */
/* ------------------------------------------------------------------ */

const selectedSubjectIds = ref((props.faculty.subjects ?? []).map((subject) => subject.id));
const savingQualifications = ref(false);

// Groups the (already relevance-ordered — see FacultyController::show())
// subject list so the picker visually leads with what this faculty is
// most likely to teach: their own College's Major subjects first, then
// shared GenEd/Minor, then everyone else's Major subjects last.
const subjectGroups = computed(() => {
    const facultyCollegeId = props.faculty.college_id;
    const own = [];
    const shared = [];
    const other = [];

    for (const subject of props.subjects) {
        if (subject.category === 'Major' && subject.college_id === facultyCollegeId) {
            own.push(subject);
        } else if (subject.category === 'General Education' || subject.category === 'Minor') {
            shared.push(subject);
        } else {
            other.push(subject);
        }
    }

    const groups = [];
    if (own.length) {
        groups.push({
            label: props.faculty.college?.name
                ? `${props.faculty.college.name} (This Faculty's College)`
                : "This Faculty's College",
            subjects: own,
        });
    }
    if (shared.length) {
        groups.push({ label: 'General Education / Minor', subjects: shared });
    }
    if (other.length) {
        groups.push({ label: 'Other Colleges', subjects: other });
    }

    return groups;
});

watch(
    () => props.faculty.subjects,
    (subjects) => {
        selectedSubjectIds.value = (subjects ?? []).map((subject) => subject.id);
    },
);

const isQualificationsDirty = computed(() => {
    const current = new Set(selectedSubjectIds.value);
    const original = new Set((props.faculty.subjects ?? []).map((subject) => subject.id));
    if (current.size !== original.size) return true;
    for (const id of current) if (!original.has(id)) return true;
    return false;
});

const assignedSubjects = computed(() => {
    const bySubjectId = new Map(props.subjects.map((subject) => [subject.id, subject]));
    return selectedSubjectIds.value
        .map((id) => bySubjectId.get(id))
        .filter(Boolean)
        .sort((a, b) => a.subject_code.localeCompare(b.subject_code));
});

const removeSubject = (subjectId) => {
    selectedSubjectIds.value = selectedSubjectIds.value.filter((id) => id !== subjectId);
};

const saveQualifications = () => {
    savingQualifications.value = true;

    router.put(
        route('scheduling.teaching-qualifications.update', props.faculty.id),
        { subject_ids: selectedSubjectIds.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['faculty', 'flash'],
            onFinish: () => {
                savingQualifications.value = false;
            },
        },
    );
};

/* ------------------------------------------------------------------ */
/* Shared helpers (time formatting used by the Workload tab)           */
/* ------------------------------------------------------------------ */

const formatTime = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 === 0 ? 12 : hour % 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${period}`;
};

/* ------------------------------------------------------------------ */
/* Workload tab — FACULTY WORKLOAD VALIDATION SYSTEM.                  */
/* `faculty.workload` is computed server-side by FacultyWorkloadService */
/* (FacultyController@show) — the same engine Auto Generate/Recommend  */
/* Faculty/Manual Assignment/Save Schedule all use — so this tab can    */
/* never disagree with what the scheduling engine actually enforces.   */
/* ------------------------------------------------------------------ */

const workload = computed(() => props.faculty.workload ?? null);

const workloadPercent = computed(() => {
    if (!workload.value) return 0;
    return Math.min(100, Math.max(0, workload.value.percent ?? 0));
});

const workloadStatusMeta = computed(() => {
    switch (workload.value?.status) {
        case 'overloaded':
            return { emoji: '🔴', label: 'Overloaded', class: 'text-red-600 bg-red-50 border-red-200' };
        case 'high':
            return { emoji: '🟡', label: 'Approaching Limit', class: 'text-amber-600 bg-amber-50 border-amber-200' };
        default:
            return { emoji: '🟢', label: 'Healthy', class: 'text-emerald-600 bg-emerald-50 border-emerald-200' };
    }
});

// Kept for the "qualified subjects" reference figure still shown
// beneath the main workload cards (how many units this faculty member
// is qualified to teach in total, regardless of whether scheduled).
const qualifiedUnits = computed(() =>
    (props.faculty.subjects ?? []).reduce((sum, subject) => sum + Number(subject.units || 0), 0),
);

// The actual Subjects/Sections making up the "Current Load" number
// above — same 'Scheduled'/'Draft', active-semester placements,
// straight from FacultyWorkloadService::assignedPlacements() via
// FacultyController@show (evaluate(..., includePlacements: true)).
const assignedPlacements = computed(() => workload.value?.assigned_placements ?? []);

// Reuses the formatTime(time) helper declared above —
// same 'HH:mm'/'HH:mm:ss' -> 12-hour am/pm formatting applies here.

// SPLIT-DELIVERY SCHEDULING — assignedPlacements() now groups a
// Face-to-Face/Online split pair into ONE `data` row with a
// `data.schedules` array (one entry per delivery-mode row) instead of
// two separate top-level rows — see
// FacultyWorkloadService::assignedPlacements()'s docblock. The
// Schedule/Room/Actions columns below render one line per
// `data.schedules` entry so a split subject shows both its
// Face-to-Face and Online lines under a single EDP Code/Subject/Load,
// while a never-split subject (schedules.length === 1) looks exactly
// as it did before this change.
//
// startEditPlacement()/saveEditPlacement()/fetchTimeSuggestions()
// were all written against a flat placement object (id, section_id,
// subject_code, requires_lab, required_hours, room_id, room_name,
// days, start_time, end_time) — this merges a `data.schedules` entry
// (which only carries its OWN id/section_id/room/days/time) with the
// group-level fields those three functions also need, rather than
// changing their signatures.
const scheduleEditTarget = (data, schedule) => ({
    ...schedule,
    subject_code: data.subject_code,
    requires_lab: data.requires_lab,
    required_hours: data.required_hours,
});

const placementStatusSeverity = (status) => {
    switch (status) {
        case 'Scheduled':
            return 'success';
        case 'Draft':
            return 'warn';
        default:
            return 'secondary';
    }
};

/* ------------------------------------------------------------------ */
/* Assigned Subjects — inline Day/Time/Room editor.                    */
/*                                                                      */
/* Adviser request: this list should not be view-only — a change in a  */
/* faculty member's actual schedule should be editable right here,     */
/* with the same conflict detection the Room Grid / Subjects tab use,  */
/* instead of forcing a trip to the Section's own Subjects tab.        */
/*                                                                      */
/* Reuses scheduling.room-grid.move (SectionSubjectController::         */
/* moveRoomGridAssignment() -> performScheduleAssignmentUpdate()) —     */
/* the SAME write path + conflict checks as the Room Grid — rather      */
/* than a second, parallel one. That endpoint authorizes against the    */
/* schedule row's OWN Section (never a "currently open" Section), which */
/* is exactly right here since this page has no single Section in       */
/* context and different rows can belong to different Sections.         */
/* ------------------------------------------------------------------ */

const DAY_TOKENS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const editingPlacementId = ref(null);
const editForm = ref({ days: [], start_time: '', end_time: '', room_id: null });
const editSaving = ref(false);
const editErrors = ref({});
const editRoomOptions = ref([]);
const editRoomsLoading = ref(false);
const editTimeSuggestions = ref([]);
const editTimeSuggestionsLoading = ref(false);
const editTimeSuggestionsMessage = ref('');
// The Subject's real required weekly teaching hours (lecture +
// laboratory), captured when the editor opens — see startEditPlacement().
// Used to filter out RecommendationService::recommendSingleDaySlots()'s
// "fill in just ONE occurrence" candidates from this editor's
// suggestions, since those are meant to pair with the row's OTHER
// already-kept meeting day(s) (a different, partial-fix workflow —
// see RecommendedTimeModal.vue's docblock), not to stand alone as a
// complete weekly schedule. Left null when it can't be determined
// (e.g. the row wasn't fully scheduled yet), in which case every
// candidate is shown rather than risk hiding valid ones.
const editRequiredHours = ref(null);

// Warning keys the backend flags as "confirm to save anyway" rather
// than a hard block — see UpdateSectionSubjectScheduleRequest's
// docblock. A real Room/Faculty/Section/Time conflict comes back
// under a different key (room_id/faculty_id/days) and is never
// offered a retry, matching RoomGrid.vue's CONFIRMABLE_WARNING_KEYS.
const CONFIRMABLE_WARNING_KEYS = { room_type: 'room_type_confirmed', room_college: 'room_college_confirmed', hours: 'hours_confirmed' };

const minutesBetween = (start, end) => {
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    return (eh * 60 + em) - (sh * 60 + sm);
};

// A candidate's TOTAL weekly hours = (session length) x (how many
// days it meets) — must equal the Subject's required hours exactly,
// same check performSchedule AssignmentUpdate() runs server-side
// before allowing a save without an explicit hours_confirmed=true.
const totalsRequiredHours = (candidate) => {
    if (editRequiredHours.value == null) return true;
    const hours = (minutesBetween(candidate.start_time, candidate.end_time) * candidate.days.length) / 60;
    return Math.abs(hours - editRequiredHours.value) < 0.01;
};

// "Suggest Available Time" — reuses the exact same recommendation
// engine (RecommendationService::recommendTimes()/recommendSingleDaySlots())
// the Auto Generate review panel and Room Grid's own Day/Time editor
// use, via scheduling.section-subjects.time-recommendations. It scores
// candidate Day/Time slots against THIS faculty member's and THIS
// row's currently-saved Room's actual bookings, so anything it returns
// is already conflict-free — never a second, hand-rolled availability
// check that could disagree with what Save actually validates against.
//
// Only full, COMPLETE weekly patterns are surfaced here (see
// totalsRequiredHours() above) — this editor replaces the row's
// entire Day/Time in one shot, so a single leftover "Fri 2.5 hrs"
// option that only covers half of a 5-hr subject would silently
// under-schedule it if applied on its own.
const fetchTimeSuggestions = async (placement) => {
    if (!placement.section_id) return;

    editTimeSuggestionsLoading.value = true;
    editTimeSuggestions.value = [];
    editTimeSuggestionsMessage.value = '';

    try {
        const url = new URL(route('scheduling.section-subjects.time-recommendations', [placement.section_id, placement.id]));

        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        const complete = (data.recommendations ?? []).filter(totalsRequiredHours);
        editTimeSuggestions.value = complete.slice(0, 3);

        if (!editTimeSuggestions.value.length) {
            editTimeSuggestionsMessage.value = (data.recommendations ?? []).length
                ? `No open slot covers the full ${editRequiredHours.value} hrs/week this subject needs in this room right now.`
                : (data.message ?? 'No open slots found for this room right now.');
        }
    } catch (e) {
        editTimeSuggestionsMessage.value = 'Could not load suggestions. Please try again.';
    } finally {
        editTimeSuggestionsLoading.value = false;
    }
};

const applyTimeSuggestion = (suggestion) => {
    editForm.value.days = [...suggestion.days];
    editForm.value.start_time = suggestion.start_time;
    editForm.value.end_time = suggestion.end_time;
    editTimeSuggestions.value = [];
};

const startEditPlacement = async (placement) => {
    editingPlacementId.value = placement.id;
    editErrors.value = {};
    editTimeSuggestions.value = [];
    editTimeSuggestionsMessage.value = '';
    editRequiredHours.value = placement.required_hours ?? null;
    editForm.value = {
        days: placement.days ? placement.days.split(',').map((d) => d.trim()).filter(Boolean) : [],
        start_time: placement.start_time ? placement.start_time.slice(0, 5) : '',
        end_time: placement.end_time ? placement.end_time.slice(0, 5) : '',
        room_id: placement.room_id ?? null,
    };

    editRoomOptions.value = [];
    if (placement.section_id) {
        editRoomsLoading.value = true;
        try {
            const response = await fetch(route('scheduling.section-subjects.rooms', placement.section_id), {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            // Same scope Room Grid's own sidebar uses (see RoomGrid.vue's
            // recommendedRooms) — only rooms belonging to this Section's
            // own Department/College/shared-pool, never the full,
            // unfiltered Room list.
            const recommended = data.recommended ?? [];

            // Same "type-matched first" grouping SectionSubjects/Show.vue's
            // own Room dropdown uses — a Major/lab subject should see
            // Laboratory rooms before plain Lecture rooms, not an
            // alphabetical mix of both.
            const wantsLaboratory = !!placement.requires_lab;
            const typeMatched = [];
            const others = [];
            recommended.forEach((room) => {
                (room.room_type === (wantsLaboratory ? 'Laboratory' : 'Lecture') ? typeMatched : others).push(room);
            });

            const groups = [];
            if (typeMatched.length) groups.push({ label: wantsLaboratory ? 'Laboratory Rooms' : 'Lecture Rooms', rooms: typeMatched });
            if (others.length) groups.push({ label: wantsLaboratory ? 'Other Rooms' : 'Laboratory Rooms', rooms: others });

            // If this row's CURRENT room isn't in the recommended set at
            // all (e.g. an earlier manual override), keep it in its own
            // group so the Select doesn't show blank for it.
            const hasCurrent = placement.room_id && recommended.some((r) => r.id === placement.room_id);
            if (placement.room_id && !hasCurrent) {
                groups.unshift({ label: 'Current Room', rooms: [{ id: placement.room_id, room_name: placement.room_name ?? 'Current room' }] });
            }

            editRoomOptions.value = groups;
        } catch (e) {
            // Room list failing to load isn't fatal — the Select just
            // shows the room currently assigned (if any) and the user
            // can retry by re-opening the editor.
        } finally {
            editRoomsLoading.value = false;
        }
    }
};

const cancelEditPlacement = () => {
    editingPlacementId.value = null;
    editErrors.value = {};
    editTimeSuggestions.value = [];
    editTimeSuggestionsMessage.value = '';
};

const saveEditPlacement = async (placement, confirmedKeys = {}) => {
    editSaving.value = true;
    editErrors.value = {};

    try {
        const response = await fetch(route('scheduling.room-grid.move', placement.id), {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                days: editForm.value.days,
                start_time: editForm.value.start_time || null,
                end_time: editForm.value.end_time || null,
                room_id: editForm.value.room_id || null,
                ...confirmedKeys,
            }),
        });
        const data = await response.json();

        if (response.ok) {
            toast.add({ severity: 'success', summary: 'Schedule updated', detail: `${placement.subject_code}'s schedule was saved.`, life: 4000 });
            editingPlacementId.value = null;
            // Refreshes both the placements list and the Current
            // Load summary cards from the server — a Day/Time/Room
            // change doesn't move the Units number, but keeps this
            // page's copy of the row (and any other viewer's) exactly
            // in sync with what was just saved.
            router.reload({ only: ['faculty'] });
            return;
        }

        // Hard conflict (Room/Faculty/Section/Time already booked
        // elsewhere) OR a confirmable soft warning — both come back
        // as { errors: { key: message } }.
        if (data.errors) {
            const confirmableKey = Object.keys(data.errors).find((key) => CONFIRMABLE_WARNING_KEYS[key]);

            if (confirmableKey) {
                const result = await Swal.fire({
                    icon: 'warning',
                    title: 'Confirm this change',
                    text: data.errors[confirmableKey],
                    showCancelButton: true,
                    confirmButtonText: 'Save Anyway',
                    cancelButtonText: 'Cancel',
                });
                if (result.isConfirmed) {
                    return saveEditPlacement(placement, { ...confirmedKeys, [CONFIRMABLE_WARNING_KEYS[confirmableKey]]: true });
                }
                return;
            }

            // A real conflict — never silently overridable. Toast it
            // front-and-center (easy to miss the row itself if the
            // table has scrolled) AND show it right on the row so it's
            // obvious which field caused it.
            editErrors.value = data.errors;
            toast.add({
                severity: 'error',
                summary: 'Scheduling conflict',
                detail: Object.values(data.errors).join(' '),
                life: 8000,
            });
            return;
        }

        // Workload Limit warning (409) — only relevant if a Faculty
        // reassignment were allowed here, but the backend still runs
        // this check on every save, so it's handled defensively.
        if (data.workload_warning) {
            if (!data.can_override) {
                toast.add({ severity: 'error', summary: 'Cannot save', detail: data.message, life: 6000 });
                return;
            }
            const result = await Swal.fire({
                icon: 'warning',
                title: 'Conflict',
                text: data.message,
                showCancelButton: true,
                confirmButtonText: 'Proceed Anyway',
                cancelButtonText: 'Cancel',
            });
            if (result.isConfirmed) {
                return saveEditPlacement(placement, { ...confirmedKeys, workload_confirmed: true });
            }
            return;
        }

        toast.add({ severity: 'error', summary: 'Could not save', detail: data.message || 'Please try again.', life: 5000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Network error', detail: 'Could not reach the server. Please try again.', life: 5000 });
    } finally {
        editSaving.value = false;
    }
};
</script>

<template>
    <Head :title="`${fullName} — Faculty Details`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Faculty Details</span>
        </template>

        <div class="max-w-6xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Back link -->
            <div class="mb-4">
                <Link :href="route('scheduling.faculty')" class="text-sm text-slate-500 hover:text-slate-700">
                    <i class="pi pi-arrow-left mr-1"></i> Back to Faculty
                </Link>
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
                <div>
                    <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                        {{ fullName }}
                        <InfoPopover
                            title="Faculty Details"
                            :paragraphs="[
                                'Manage this faculty member\'s profile, teaching qualifications, and current teaching workload.',
                            ]"
                            :bullets="[
                                'Teaching Qualifications control which subjects this faculty member can be assigned to when scheduling.',
                                'Workload shows units currently assigned against the Max Teaching Units cap set on the Information tab.',
                            ]"
                        />
                    </h1>
                    <p class="mt-1 text-slate-500">
                        {{ faculty.faculty_id }} &middot; {{ faculty.college?.name || '—' }} &middot;
                        {{ faculty.employment_type }}
                    </p>
                </div>
                <Tag
                    :value="faculty.status"
                    :severity="faculty.status === 'Active' ? 'success' : 'danger'"
                    class="!text-sm"
                />
                </div>
            </div>

            <div class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <Tabs v-model:value="activeTab">
                        <TabList>
                            <Tab value="information">Information</Tab>
                            <Tab value="qualifications">Teaching Qualifications</Tab>
                            <Tab value="workload">Workload</Tab>
                        </TabList>

                        <TabPanels>
                            <!-- INFORMATION -->
                            <TabPanel value="information">
                                <div class="flex justify-end mb-4">
                                    <Button
                                        v-if="canEdit"
                                        label="Edit Information"
                                        icon="pi pi-pencil"
                                        severity="secondary"
                                        outlined
                                        @click="openEdit"
                                    />
                                    <Tag
                                        v-else
                                        value="View only — outside your College"
                                        severity="secondary"
                                    />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Faculty ID</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.faculty_id }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Full Name</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ fullName }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Employment Type</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.employment_type }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">College</p>
                                        <p class="mt-1">
                                            <span v-if="faculty.college?.name" class="text-slate-800 font-medium">{{ faculty.college.name }}</span>
                                            <Tag v-else value="General Education" severity="warning" />
                                        </p>
                                    </div>
                                    <div v-if="!faculty.college?.name" class="sm:col-span-2 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5">
                                        <i class="pi pi-info-circle text-amber-500 mt-0.5"></i>
                                        <p class="text-sm text-amber-700">
                                            This faculty member is not assigned to any specific college and
                                            may teach General Education subjects.
                                        </p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Max Teaching Units</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.max_teaching_units }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Email</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.email || '—' }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Contact Number</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.contact_number || '—' }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4 sm:col-span-2 lg:col-span-3">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Remarks</p>
                                        <p class="mt-1 text-slate-800 font-medium whitespace-pre-line">{{ faculty.remarks || '—' }}</p>
                                    </div>
                                </div>
                            </TabPanel>

                            <!-- TEACHING QUALIFICATIONS -->
                            <TabPanel value="qualifications">
                                <label class="text-sm font-medium text-slate-700">Assign Subjects</label>
                                <MultiSelect
                                    v-model="selectedSubjectIds"
                                    :options="subjectGroups"
                                    optionLabel="subject_code"
                                    optionValue="id"
                                    optionGroupLabel="label"
                                    optionGroupChildren="subjects"
                                    filter
                                    filterPlaceholder="Search subjects"
                                    display="chip"
                                    placeholder="Select subjects this faculty member can teach"
                                    class="w-full mt-1 neu-inset !border-none"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                >
                                    <template #optiongroup="{ option }">
                                        <span class="text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ option.label }}</span>
                                    </template>
                                    <template #option="{ option }">
                                        <span class="font-medium">{{ option.subject_code }}</span>
                                        <span class="text-slate-400"> — {{ option.subject_title }}</span>
                                    </template>
                                </MultiSelect>

                                <div class="flex justify-end mt-3">
                                    <Button
                                        label="Save Qualifications"
                                        icon="pi pi-check"
                                        severity="success"
                                        :loading="savingQualifications"
                                        :disabled="!isQualificationsDirty"
                                        @click="saveQualifications"
                                    />
                                </div>

                                <DataTable
                                    :value="assignedSubjects"
                                    dataKey="id"
                                    class="neu-inset neu-table rounded-xl overflow-hidden mt-4"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                >
                                    <template #empty>
                                        <div class="text-center py-8">
                                            <p class="text-slate-500 font-medium text-sm">No subjects assigned yet.</p>
                                        </div>
                                    </template>
                                    <Column field="subject_code" header="Subject Code" style="width: 10rem" />
                                    <Column field="subject_title" header="Subject Title" />
                                    <Column header="Category" style="width: 11rem">
                                        <template #body="{ data }"><Tag :value="data.category" severity="info" /></template>
                                    </Column>
                                    <Column header="Units" style="width: 7rem">
                                        <template #body="{ data }">{{ data.units }}</template>
                                    </Column>
                                    <Column header="Remove" style="width: 7rem">
                                        <template #body="{ data }">
                                            <Button
                                                icon="pi pi-trash"
                                                text
                                                rounded
                                                severity="danger"
                                                size="small"
                                                aria-label="Remove"
                                                @click="removeSubject(data.id)"
                                            />
                                        </template>
                                    </Column>
                                </DataTable>

                                <p v-if="isQualificationsDirty" class="text-xs text-amber-600 mt-2">
                                    You have unsaved changes — click "Save Qualifications" to apply them.
                                </p>
                            </TabPanel>

                            <!-- WORKLOAD -->
                            <TabPanel value="workload">
                                <div class="mb-4 flex items-center justify-end gap-2">
                                    <Button
                                        label="Print"
                                        icon="pi pi-print"
                                        severity="secondary"
                                        outlined
                                        :disabled="!scheduleMeta.academic_year"
                                        title="Print this faculty member's schedule (Schedule by Faculty report)."
                                        @click="printFacultySchedule"
                                    />
                                    <Button
                                        label="Send via Email"
                                        icon="pi pi-send"
                                        severity="success"
                                        :disabled="!scheduleMeta.academic_year"
                                        @click="showSendScheduleModal = true"
                                    />
                                </div>
                                <div
                                    class="mb-6 flex items-center justify-between rounded-xl p-4 neu-inset"
                                    :class="workloadStatusMeta.class"
                                >
                                    <div>
                                        <p class="text-xs font-semibold tracking-wide uppercase opacity-70">Current Load</p>
                                        <p class="mt-1 text-2xl font-bold">
                                            {{ workload?.current ?? 0 }} / {{ workload?.max ?? 0 }} {{ workload?.unit_label ?? 'Units' }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-3xl leading-none">{{ workloadStatusMeta.emoji }}</p>
                                        <p class="mt-1 text-sm font-semibold">{{ workloadStatusMeta.label }} — {{ workloadPercent }}%</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Maximum Load</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.max ?? 0 }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Current Assigned</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.current ?? 0 }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Remaining</p>
                                        <p
                                            class="mt-1 text-2xl font-bold"
                                            :class="(workload?.remaining ?? 0) < 0 ? 'text-red-600' : 'text-[#1E293B]'"
                                        >
                                            {{ workload?.remaining ?? 0 }}
                                        </p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Assigned Subjects</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.assigned_subjects ?? 0 }}</p>
                                    </div>
                                </div>

                                <ProgressBar
                                    :value="workloadPercent"
                                    :showValue="false"
                                    class="mb-6 h-2"
                                    :pt="{
                                        value: {
                                            class:
                                                workload?.status === 'overloaded'
                                                    ? '!bg-red-500'
                                                    : workload?.status === 'high'
                                                      ? '!bg-amber-500'
                                                      : '!bg-emerald-500',
                                        },
                                    }"
                                />

                                <p class="text-xs text-slate-400">
                                    <i class="pi pi-info-circle mr-1"></i>
                                    Current Load reflects every 'Scheduled' or 'Draft' subject this faculty member is actually
                                    assigned to teach in the active semester (Auto Generate + manually saved schedules alike),
                                    measured in {{ workload?.unit_label ?? 'Units' }} — the same figure Auto Generate Schedule,
                                    Recommend Faculty, Manual Assignment, and Save Schedule all validate against. Reference:
                                    qualified to teach {{ qualifiedUnits }} unit(s) worth of subjects in total (Teaching
                                    Qualifications tab).
                                </p>

                                <!-- Assigned Subjects list — what actually makes up Current Load -->
                                <div class="mt-8">
                                    <h3 class="mb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase">
                                        Assigned Subjects — {{ assignedPlacements.length }}
                                    </h3>

                                    <DataTable
                                        v-if="assignedPlacements.length"
                                        :value="assignedPlacements"
                                        dataKey="id"
                                        class="text-sm neu-inset neu-table rounded-xl overflow-hidden"
                                        :class="isDark ? 'neu-table-dark' : ''"
                                        stripedRows
                                    >
                                        <Column field="edp_code" header="EDP Code">
                                            <template #body="{ data }">
                                                <span class="font-mono text-xs text-slate-600">{{ data.edp_code || '—' }}</span>
                                            </template>
                                        </Column>
                                        <Column field="subject_code" header="Subject">
                                            <template #body="{ data }">
                                                <div class="font-semibold text-[#1E293B]">{{ data.subject_code }}</div>
                                                <div class="text-xs text-slate-500">{{ data.subject_title }}</div>
                                            </template>
                                        </Column>
                                        <Column field="section_code" header="Section">
                                            <template #body="{ data }">{{ data.section_code || '—' }}</template>
                                        </Column>
                                        <Column header="Schedule">
                                            <template #body="{ data }">
                                                <div class="flex flex-col gap-2.5">
                                                    <div v-for="schedule in data.schedules" :key="schedule.id">
                                                        <div v-if="editingPlacementId === schedule.id" class="flex flex-col gap-1.5">
                                                            <MultiSelect
                                                                v-model="editForm.days"
                                                                :options="DAY_TOKENS"
                                                                placeholder="Days"
                                                                class="w-full text-xs"
                                                                display="chip"
                                                            />
                                                            <div class="flex items-center gap-1.5">
                                                                <input v-model="editForm.start_time" type="time" class="w-full rounded border border-slate-300 px-2 py-1 text-xs" />
                                                                <span class="text-slate-400">–</span>
                                                                <input v-model="editForm.end_time" type="time" class="w-full rounded border border-slate-300 px-2 py-1 text-xs" />
                                                            </div>
                                                            <small v-if="editErrors.days" class="text-red-600">{{ editErrors.days }}</small>
                                                            <small v-if="editErrors.start_time" class="text-red-600">{{ editErrors.start_time }}</small>
                                                            <small v-if="editErrors.end_time" class="text-red-600">{{ editErrors.end_time }}</small>
                                                            <small v-if="editErrors.hours" class="text-red-600">{{ editErrors.hours }}</small>

                                                            <Button
                                                                label="Suggest Available Time"
                                                                icon="pi pi-sparkles"
                                                                size="small"
                                                                text
                                                                :loading="editTimeSuggestionsLoading"
                                                                class="!text-xs !p-0 !justify-start"
                                                                @click="fetchTimeSuggestions(scheduleEditTarget(data, schedule))"
                                                            />
                                                            <div v-if="editTimeSuggestions.length" class="flex flex-col gap-1">
                                                                <button
                                                                    v-for="(s, idx) in editTimeSuggestions"
                                                                    :key="idx"
                                                                    type="button"
                                                                    class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-left text-xs text-emerald-700 hover:bg-emerald-100"
                                                                    @click="applyTimeSuggestion(s)"
                                                                >
                                                                    {{ s.days.join(', ') }} &middot; {{ formatTime(s.start_time) }}–{{ formatTime(s.end_time) }}
                                                                </button>
                                                            </div>
                                                            <small v-else-if="editTimeSuggestionsMessage" class="text-slate-400">{{ editTimeSuggestionsMessage }}</small>
                                                        </div>
                                                        <span v-else-if="schedule.days">
                                                            {{ schedule.days }} &middot; {{ formatTime(schedule.start_time) }}–{{ formatTime(schedule.end_time) }}
                                                            <span
                                                                v-if="schedule.shared_with_section"
                                                                class="ml-1 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
                                                                :title="`Shared Online session — one Faculty covering both this Section and ${schedule.shared_with_section}`"
                                                            >
                                                                Shared with {{ schedule.shared_with_section }}
                                                            </span>
                                                        </span>
                                                        <span v-else class="text-slate-400">Not yet scheduled</span>
                                                    </div>
                                                </div>
                                            </template>
                                        </Column>
                                        <Column field="room_name" header="Room">
                                            <template #body="{ data }">
                                                <div class="flex flex-col gap-2.5">
                                                    <div v-for="schedule in data.schedules" :key="schedule.id">
                                                        <div v-if="editingPlacementId === schedule.id" class="flex flex-col gap-1.5">
                                                            <Select
                                                                v-model="editForm.room_id"
                                                                :options="editRoomOptions"
                                                                optionLabel="room_name"
                                                                optionValue="id"
                                                                optionGroupLabel="label"
                                                                optionGroupChildren="rooms"
                                                                :loading="editRoomsLoading"
                                                                placeholder="Select room"
                                                                showClear
                                                                class="w-full text-xs"
                                                            >
                                                                <template #optiongroup="{ option }">
                                                                    <span class="text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ option.label }}</span>
                                                                </template>
                                                            </Select>
                                                            <small v-if="editErrors.room_id" class="text-red-600">{{ editErrors.room_id }}</small>
                                                            <small v-if="editErrors.room_type" class="text-red-600">{{ editErrors.room_type }}</small>
                                                            <small v-if="editErrors.room_college" class="text-red-600">{{ editErrors.room_college }}</small>
                                                        </div>
                                                        <span v-else>{{ schedule.room_name || '—' }}</span>
                                                    </div>
                                                </div>
                                            </template>
                                        </Column>
                                        <Column header="Load">
                                            <template #body="{ data }">{{ data.load }} {{ workload?.unit_label ?? 'Units' }}</template>
                                        </Column>
                                        <Column field="status" header="Status">
                                            <template #body="{ data }">
                                                <Tag :value="data.status" :severity="placementStatusSeverity(data.status)" />
                                            </template>
                                        </Column>
                                        <Column header="Actions" style="width: 6rem">
                                            <template #body="{ data }">
                                                <div class="flex flex-col gap-2.5">
                                                    <div v-for="schedule in data.schedules" :key="schedule.id">
                                                        <div v-if="editingPlacementId === schedule.id" class="flex items-center gap-1">
                                                            <Button
                                                                icon="pi pi-check"
                                                                severity="success"
                                                                text
                                                                rounded
                                                                :loading="editSaving"
                                                                @click="saveEditPlacement(scheduleEditTarget(data, schedule))"
                                                            />
                                                            <Button icon="pi pi-times" severity="secondary" text rounded :disabled="editSaving" @click="cancelEditPlacement" />
                                                        </div>
                                                        <Button
                                                            v-else-if="canEdit"
                                                            icon="pi pi-pencil"
                                                            text
                                                            rounded
                                                            aria-label="Edit schedule"
                                                            @click="startEditPlacement(scheduleEditTarget(data, schedule))"
                                                        />
                                                    </div>
                                                </div>
                                            </template>
                                        </Column>
                                    </DataTable>

                                    <div
                                        v-else
                                        class="rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-400"
                                    >
                                        No subjects assigned to this faculty member in the active semester yet.
                                    </div>
                                </div>
                            </TabPanel>
                        </TabPanels>
                    </Tabs>
                </template>
            </Card>
            </div>
        </div>

        <!-- Edit Faculty Information Dialog -->
        <Dialog
            v-model:visible="editVisible"
            modal
            header="Edit Faculty"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeEdit"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveFaculty">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Faculty ID <span class="text-red-500">*</span></label>
                    <InputText v-uppercase v-model="facultyForm.faculty_id" :invalid="!!facultyForm.errors.faculty_id" class="w-full" />
                    <small v-if="facultyForm.errors.faculty_id" class="text-red-500">{{ facultyForm.errors.faculty_id }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Employment Type <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.employment_type"
                        :options="employmentTypeOptions"
                        placeholder="Select employment type"
                        :invalid="!!facultyForm.errors.employment_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.employment_type" class="text-red-500">{{ facultyForm.errors.employment_type }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">First Name <span class="text-red-500">*</span></label>
                    <InputText v-uppercase v-model="facultyForm.first_name" :invalid="!!facultyForm.errors.first_name" class="w-full" />
                    <small v-if="facultyForm.errors.first_name" class="text-red-500">{{ facultyForm.errors.first_name }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Middle Name</label>
                    <InputText v-uppercase v-model="facultyForm.middle_name" :invalid="!!facultyForm.errors.middle_name" class="w-full" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Last Name <span class="text-red-500">*</span></label>
                    <InputText v-uppercase v-model="facultyForm.last_name" :invalid="!!facultyForm.errors.last_name" class="w-full" />
                    <small v-if="facultyForm.errors.last_name" class="text-red-500">{{ facultyForm.errors.last_name }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Suffix</label>
                    <InputText v-uppercase v-model="facultyForm.suffix" :invalid="!!facultyForm.errors.suffix" class="w-full" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">College</label>
                    <Select
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
                    <small v-if="facultyForm.errors.college_id" class="text-red-500">{{ facultyForm.errors.college_id }}</small>
                    <p v-else class="text-xs text-slate-400">
                        Leave blank if this faculty member is General Education (no department).
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Maximum Teaching Units <span class="text-red-500">*</span></label>
                    <InputNumber
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
                    <small v-if="facultyForm.errors.max_teaching_units" class="text-red-500">{{ facultyForm.errors.max_teaching_units }}</small>
                    <!-- Only Admin/Registrar may raise this ceiling directly, same as the Faculty Master edit modal. -->
                    <p v-if="!canChangeMaxLoad" class="text-xs text-slate-400">
                        Only Admin/Registrar can change this directly.
                        <Link :href="route('scheduling.faculty')" class="text-teal-600 hover:underline font-medium">
                            Request a load increase
                        </Link>
                    </p>
                    <p v-else class="text-xs text-slate-400">Current teaching load ceiling: {{ hardCapUnits }} units.</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Workload Measurement</label>
                    <Select
                        v-model="facultyForm.workload_type"
                        :options="workloadTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="!!facultyForm.errors.workload_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <p class="text-xs text-slate-400">Whichever the institution uses to cap this faculty member's load.</p>
                </div>

                <div v-if="facultyForm.workload_type === 'hours'" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Maximum Weekly Hours</label>
                    <InputNumber
                        v-model="facultyForm.max_weekly_hours"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_weekly_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_weekly_hours" class="text-red-500">{{ facultyForm.errors.max_weekly_hours }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!facultyForm.errors.status"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.status" class="text-red-500">{{ facultyForm.errors.status }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Email</label>
                    <InputText v-model="facultyForm.email" type="email" :invalid="!!facultyForm.errors.email" class="w-full" />
                    <small v-if="facultyForm.errors.email" class="text-red-500">{{ facultyForm.errors.email }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Contact Number</label>
                    <InputText v-uppercase v-model="facultyForm.contact_number" :invalid="!!facultyForm.errors.contact_number" class="w-full" />
                </div>

                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea v-model="facultyForm.remarks" autoResize rows="3" :invalid="!!facultyForm.errors.remarks" class="w-full" />
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeEdit" />
                <Button label="Save Changes" icon="pi pi-check" severity="success" :loading="facultyForm.processing" @click="onSaveFaculty" />
            </template>
        </Dialog>

        <SendScheduleModal
            :show="showSendScheduleModal"
            :faculty="sendScheduleFaculty"
            :academic-term="sendScheduleTerm"
            :is-finalized="scheduleMeta.is_finalized"
            @close="showSendScheduleModal = false"
            @sent="showSendScheduleModal = false"
        />

    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page. Dialogs and overlay panels (Select/MultiSelect/
   DatePicker) are teleported to <body> by PrimeVue, so their rules use
   :global() with a compound selector instead of :deep() — Vue's
   scoped-CSS attribute doesn't reliably travel through the teleport
   boundary. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F1F5F9 !important; padding: 0 !important; }
.dark-scope :deep(.p-tabpanel) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-textarea),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder),
.dark-scope :deep(.p-textarea::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-checkbox .p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.25) !important; }
.dark-scope :deep(.p-progressbar) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; font-weight: 600; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.07) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* Amber "General Education" info banner (Information tab) */
.dark-scope :deep(.bg-amber-50) { background: rgba(245, 158, 11, 0.12) !important; }
.dark-scope :deep(.border-amber-200) { border-color: rgba(245, 158, 11, 0.3) !important; }
.dark-scope :deep(.text-amber-700),
.dark-scope :deep(.text-amber-800) { color: #FCD34D !important; }

/* Dialogs (teleported to <body>) */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-xs.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect),
:global(.dark-scope.p-dialog .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.18) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-checkbox .p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.3) !important; }

/* Select / MultiSelect dropdown overlays + DatePicker time panel
   (also teleported) */
:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-filter),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-filter) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }

:global(.p-datepicker-panel.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-time-picker) { border-color: rgba(255, 255, 255, 0.1) !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-time-picker span) { color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-button-text) { color: #CBD5E1 !important; }
</style>