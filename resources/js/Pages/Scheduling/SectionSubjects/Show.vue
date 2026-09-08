<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
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
import Popover from 'primevue/popover';
import Drawer from 'primevue/drawer';
import ProgressBar from 'primevue/progressbar';
import Divider from 'primevue/divider';
import FacultyRecommendationSelector from '@/Components/Scheduling/FacultyRecommendationSelector.vue';
import RoomRecommendationSelector from '@/Components/Scheduling/RoomRecommendationSelector.vue';
import TimeRecommendationSelector from '@/Components/Scheduling/TimeRecommendationSelector.vue';
import { dockedEditSectionSubjectId } from '@/composables/useTimeEditDock';
import MergeRecommendationModal from '@/Components/Scheduling/MergeRecommendationModal.vue';
import RoomGrid from '@/Components/Scheduling/RoomGrid.vue';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';
import { useSchedulePolling } from '@/composables/useSchedulePolling';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    section: { type: Object, required: true },
    // CONCURRENCY HARDENING — this Section's schedule_version at the
    // moment this page was loaded. Carried forward as
    // `expected_schedule_version` on every scheduling write (Save
    // Schedule / Accept All & Save) and used as the baseline for the
    // real-time change-detection poller below.
    scheduleVersion: { type: Number, default: 1 },
    // Every other Section this user can see for the same Academic
    // Year + Semester (Section::visibleTo() — same RBAC scope the
    // Sections list itself uses), powering the header's section
    // switcher so a Dean/OIC doesn't have to leave this page and
    // reopen the Sections list to move between e.g. every BSIT block.
    siblingSections: { type: Array, default: () => [] },
    sectionSubjects: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ subject_search: '' }) },
    curriculums: { type: Array, default: () => [] },
    availableSubjects: { type: Array, default: () => [] },
    yearLevelMap: { type: Object, default: () => ({}) },
    sectionYearLevel: { type: String, default: null },
    sectionSemester: { type: String, default: null },
    semesterOptions: { type: Array, default: () => [] },
    // Scheduling table options — Faculty/Room dropdowns.
    activeFaculty: { type: Array, default: () => [] },
    activeRooms: { type: Array, default: () => [] },
    // Always supplied by SectionSubjectController from the active School
    // Year's Academic Calendar (falls back to SchoolYear's own defaults
    // only when there's genuinely no active term). REQUIRED here, no
    // local hardcoded default — a hardcoded fallback previously masked
    // cases where this prop wasn't forwarded to a child component.
    schedulingWindow: { type: Object, required: true },
    // Institution-wide Teaching Load ceiling (FacultyLoadRequest::
    // effectiveCapFor()) — forwarded straight through to RoomGrid.vue
    // for its inline "Request more units"/"Add Units" shortcut. Same
    // prop name/shape Faculty/Index.vue already receives from
    // FacultyController, so the number field's cap always agrees with
    // what StoreFacultyLoadRequestRequest actually enforces.
    hardCapUnits: { type: Number, default: 40 },
});

const toast = useToast();
const page = usePage();

// A Section whose section_name is identical to its section_code (e.g.
// both "BSHM-2A") would otherwise render duplicated — "BSHM-2A —
// BSHM-2A". Only append the name when it actually adds information,
// same convention RoomGrid.vue's roomLabel() already uses for Rooms.
const sectionLabel = (sec) => {
    if (!sec) return '';
    const code = (sec.section_code || '').trim();
    const name = (sec.section_name || '').trim();
    if (!name || name.toLowerCase() === code.toLowerCase()) return code;
    return `${code} — ${name}`;
};

/* ------------------------------------------------------------------ */
/* Section switcher — jump to a sibling Section without leaving the    */
/* page (header dropdown next to the Section name).                    */
/* ------------------------------------------------------------------ */

// Scheduling-progress status for a Section, matching the Sections
// list's own thresholds exactly (SectionController::index() /
// Sections/Index.vue): no rows at all -> "none"; rows exist but zero
// assigned -> "not_scheduled"; some but not all assigned ->
// "partial"; every row assigned -> "full". Drives the switcher
// dropdown's status dot so a Dean/OIC can see at a glance which
// sibling Sections still need attention without opening each one.
const sectionScheduleStatus = (sec) => {
    const total = sec.total_subjects_count ?? 0;
    const assigned = sec.assigned_subjects_count ?? 0;
    if (total === 0) return 'none';
    if (assigned === 0) return 'not_scheduled';
    if (assigned < total) return 'partial';
    return 'full';
};

const sectionStatusDotClass = (status) => ({
    full: 'bg-green-500',
    partial: 'bg-amber-500',
    not_scheduled: 'bg-slate-300',
    none: 'bg-slate-200',
}[status] ?? 'bg-slate-200');

const sectionSwitcherOptions = computed(() =>
    props.siblingSections.map((s) => ({
        id: s.id,
        label: sectionLabel(s),
        major: s.major?.code || s.major?.name || '',
        status: sectionScheduleStatus(s),
        totalSubjects: s.total_subjects_count ?? 0,
        assignedSubjects: s.assigned_subjects_count ?? 0,
    })),
);

const selectedSectionId = ref(props.section.id);
watch(
    () => props.section.id,
    (id) => {
        selectedSectionId.value = id;
    },
);

const currentSwitcherOption = computed(() =>
    sectionSwitcherOptions.value.find((o) => o.id === selectedSectionId.value) ?? null,
);

const onSwitchSection = (id) => {
    if (!id || id === props.section.id) return;
    router.visit(route('scheduling.section-subjects.show', id), {
        preserveScroll: true,
    });
};

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
/* Search                                                               */
/* ------------------------------------------------------------------ */

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

/* ------------------------------------------------------------------ */
/* Scheduling table — rows are a local editable copy of                */
/* sectionSubjects so Faculty/Room/Days/Time cells update instantly    */
/* without waiting on a full Inertia reload. Re-synced whenever the    */
/* server sends a fresh sectionSubjects prop (search, add, remove).    */
/* ------------------------------------------------------------------ */

const toDaysArray = (days) => (days ? days.split(',').filter(Boolean) : []);

const rows = ref(props.sectionSubjects.map((row) => ({ ...row, days: toDaysArray(row.days) })));

watch(
    () => props.sectionSubjects,
    (fresh) => {
        rows.value = fresh.map((row) => ({ ...row, days: toDaysArray(row.days) }));
    },
);

// Per-row, per-field validation error + saving indicator, keyed by row id.
const rowState = reactive({});
const stateFor = (rowId) => {
    if (!rowState[rowId]) {
        // BUG FIX — Hours Mismatch reappearing after every refresh.
        // Previously this always started at `hoursConfirmed: false`,
        // ignoring the row's own persisted `hours_confirmed` column —
        // so a mismatch the Registrar had already confirmed AND saved
        // would still show up in "Scheduling Issues" the moment the
        // page reloaded, forcing them to reconfirm it forever. Seed
        // the initial value from the row's persisted flag instead; it
        // still gets reset to false the moment Days/Start/End Time
        // change again this session (see onDaysChange/onStartTimeChange/
        // onEndTimeChange below), so a genuinely NEW mismatch on an
        // edited row is never silently hidden — only an already-
        // confirmed-and-saved one stops nagging on reload.
        const row = rows.value.find((r) => r.id === rowId);
        rowState[rowId] = {
            errors: {},
            capacityConfirmed: Boolean(row?.capacity_confirmed),
            workloadConfirmed: false,
            hoursConfirmed: Boolean(row?.hours_confirmed),
            // BUG FIX — Room Type Mismatch reappearing after every
            // reload, same root cause and fix as hoursConfirmed above:
            // room_type_confirmed/room_college_confirmed are now
            // persisted columns (see the 2026_08_24_180000 migration),
            // so seed from them instead of always starting false.
            // onRoomChange below still resets both the moment the Room
            // actually changes, so a genuinely new mismatch on an
            // edited row is never silently hidden.
            roomTypeConfirmed: Boolean(row?.room_type_confirmed),
            // Room College Mismatch (a room owned by a different
            // College than this Section's own — e.g. an SHTM Lab used
            // for a BSIT class) — same persisted-confirmation pattern.
            roomCollegeConfirmed: Boolean(row?.room_college_confirmed),
            // Faculty Mismatch — assigned Faculty carries no Teaching
            // Qualification for the Subject and isn't from its
            // academic home College/GenEd pool (e.g. a CCS faculty
            // member manually placed on a BSED Minor subject). Same
            // persisted-confirmation pattern as roomTypeConfirmed/
            // roomCollegeConfirmed above — see the 2026_08_30_090000
            // migration's docblock. onFacultyChange below resets this
            // the moment the Faculty actually changes.
            facultyMismatchConfirmed: Boolean(row?.faculty_mismatch_confirmed),
        };
    }
    return rowState[rowId];
};

/* --- Days --- */

// Full reference list — every day the app knows about, in calendar
// order. This is NOT what's offered in the dropdown; see dayOptions
// below. Kept for formatDays()/orderedDayTokens, which need to know
// the full display order regardless of which days are selectable
// this School Year.
const allDayDefinitions = [
    { label: 'Mon', value: 'Mon' },
    { label: 'Tue', value: 'Tue' },
    { label: 'Wed', value: 'Wed' },
    { label: 'Thu', value: 'Thu' },
    { label: 'Fri', value: 'Fri' },
    { label: 'Sat', value: 'Sat' },
    { label: 'Sun', value: 'Sun' },
];

// ACADEMIC-CALENDAR-DRIVEN CLASS DAYS — the Days dropdown (and its
// quick-pick presets below) must only ever offer days the active
// School Year's Academic Calendar actually allows (schedulingWindow.
// available_days, sourced server-side from SchoolYear::allowedDays()
// — see SectionSubjectController::show()). Previously this list was
// hardcoded to all 7 days, so e.g. Saturday stayed pickable in the
// dropdown even when the active calendar was Mon–Fri only; the
// server-side check still caught it on Save ("Sat is not an allowed
// class day..."), but only after the Registrar had already picked it.
// This keeps the dropdown itself in sync with that same source of
// truth instead of just failing validation after the fact.
const dayOptions = computed(() => {
    const allowed = props.schedulingWindow?.available_days;
    if (!Array.isArray(allowed) || allowed.length === 0) {
        return allDayDefinitions;
    }
    return allDayDefinitions.filter((day) => allowed.includes(day.value));
});

// Quick-pick common combinations shown above the multi-select list.
// Meetings/Week is capped at 2x (see MultiSelect's selectionLimit),
// so only single- and double-day presets are offered here. Filtered
// the same way as dayOptions above — a preset is only offered when
// every day it contains is actually allowed this School Year (e.g.
// "SAT" disappears entirely once Saturday isn't a class day).
const allDayPresetDefinitions = [
    { label: 'MW', value: ['Mon', 'Wed'] },
    { label: 'TTH', value: ['Tue', 'Thu'] },
    { label: 'WF', value: ['Wed', 'Fri'] },
    { label: 'SAT', value: ['Sat'] },
];
const dayPresets = computed(() => {
    const allowed = props.schedulingWindow?.available_days;
    if (!Array.isArray(allowed) || allowed.length === 0) {
        return allDayPresetDefinitions;
    }
    return allDayPresetDefinitions.filter((preset) => preset.value.every((day) => allowed.includes(day)));
});

// Compact display like "MWF", "TTH", "SAT" instead of PrimeVue's
// default comma/chip rendering.
const dayAbbreviations = { Mon: 'M', Tue: 'T', Wed: 'W', Thu: 'TH', Fri: 'F', Sat: 'SAT', Sun: 'SUN' };
const orderedDayTokens = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const formatDays = (selected) => {
    if (!selected || selected.length === 0) return '';
    return orderedDayTokens
        .filter((token) => selected.includes(token))
        .map((token) => dayAbbreviations[token])
        .join('');
};

const applyDayPreset = (row, preset) => {
    onDaysChange(row, [...preset.value]);
};

/* --- Faculty — every active faculty member, grouped so those            */
/* qualified to teach the subject float to the top, but any active        */
/* faculty can still be picked manually (Registrar override).             */

// This Section's own College — e.g. a BSIT section resolves to the
// College of Computer Studies (CCS). Same relation chain the backend
// uses (section->major->department->college_id) for the same purpose
// (Room/Faculty RBAC scoping), loaded eagerly with the Section so it's
// already on hand here with no extra request.
const sectionCollegeId = computed(() => props.section.major?.department?.college_id ?? null);

// The Section's own owning College's display name (e.g. "College of
// Computer Studies" / "CCS") — used purely for the group HEADER text
// below, exactly the same way roomGroupsFor() labels its type-match
// group "Laboratory Rooms"/"Lecture Rooms" from the subject itself.
const sectionCollegeName = computed(() => props.section.major?.department?.college?.short_name
    ?? props.section.major?.department?.college?.name
    ?? null);

const isQualifiedFor = (faculty, subject) => {
    if (!subject) return false;
    // An explicit Teaching Qualification always wins, regardless of
    // Subject category or the faculty's own College — mirrors
    // SectionSubject::faculty_mismatch on the server, which never
    // flags a mismatch for someone explicitly linked to the Subject.
    // Checked BEFORE the General Education branch below: a College
    // faculty member (e.g. College of Teacher Education) can still
    // carry an explicit qualification for a GenEd subject, and that
    // shouldn't be overridden just because their College isn't
    // "General Education".
    if (faculty.qualified_subject_ids.includes(subject.id)) return true;
    if (subject.category === 'General Education') {
        // General Education subjects are owned by General Education
        // Faculty, i.e. faculty with no College of their own — same
        // rule the backend's subjectCollegeId()/recommendFaculty()
        // uses (a null owning-College routes to the college_id-null
        // GenEd pool), so the client-side grouping never disagrees
        // with what the recommendation engine considers eligible.
        return faculty.faculty_category === 'General Education Faculty' || faculty.college_id === null;
    }
    // Major/Minor subjects fall back to a College match: a BSIT
    // (Major) subject is offered by the College of Computer Studies,
    // so any active CCS faculty member is a reasonable manual pick
    // even without an explicit Teaching Qualification on file — same
    // idea for BSHM -> SHTM, COC subjects -> College of Criminology,
    // and so on for every College/Program pairing.
    if (sectionCollegeId.value !== null) {
        return faculty.college_id === sectionCollegeId.value;
    }
    return false;
};

const facultyGroupsFor = (row) => {
    const subject = row.subject;
    const recs = recommendations[row.id]?.faculty?.recommendations ?? [];
    const recommendedIds = new Set(recs.map((r) => r.id));

    // Built from the ranked recommendation list itself (not
    // props.activeFaculty) so the order matches the engine's ranking,
    // not the alphabetical roster order.
    const recommended = recs
        .filter((r) => props.activeFaculty.some((f) => f.id === r.id))
        .map((r) => ({
            label: r.name,
            value: r.id,
            confidence: r.confidence,
            currentLoad: r.current_load,
            maxUnits: r.max_teaching_units,
        }));

    const qualified = [];
    const others = [];

    props.activeFaculty.forEach((faculty) => {
        if (recommendedIds.has(faculty.id)) return;
        // "Best Match" — this faculty has an explicit Teaching
        // Qualification on file for THIS subject (Faculty Master ->
        // Teaching Qualifications), not just a same-College guess.
        // Mirrors the Room dropdown's "Best Match" tag for rooms
        // explicitly linked to the subject via
        // section_subject_room_preferences, so both dropdowns signal
        // "explicitly linked" the same way.
        const isBestMatch = subject ? faculty.qualified_subject_ids.includes(subject.id) : false;
        const option = {
            label: faculty.full_name,
            value: faculty.id,
            currentLoad: faculty.current_load,
            maxUnits: faculty.max_teaching_units,
            bestMatch: isBestMatch,
        };
        if (isQualifiedFor(faculty, subject)) {
            qualified.push(option);
        } else {
            others.push(option);
        }
    });

    // Within the College group, explicit Teaching-Qualification
    // matches ("Best Match") float above faculty who only match by
    // College membership — same "most specific signal first" idea
    // Rooms already use by listing type-matched rooms before others.
    qualified.sort((a, b) => (b.bestMatch === true) - (a.bestMatch === true));

    // Group label mirrors roomGroupsFor()'s dynamic "Laboratory
    // Rooms"/"Lecture Rooms" naming — a Major/Minor subject shows the
    // Section's OWN owning College by name (e.g. "College of
    // Computer Studies Faculty") instead of the generic "Qualified
    // for This Subject", so the Registrar sees at a glance exactly
    // which College this bucket was scoped to, same as the Room
    // dropdown scopes by Laboratory/Lecture type.
    const qualifiedGroupLabel = subject?.category === 'General Education'
        ? 'General Education Faculty'
        : (sectionCollegeName.value ? `${sectionCollegeName.value} Faculty` : 'Qualified for This Subject');

    const groups = [];
    if (isRecommendationsLoading(row.id) && !recommended.length) {
        groups.push({
            label: 'Recommended',
            items: [{ label: 'Finding the best matches…', value: '__loading__', disabled: true }],
            isRecommended: true,
        });
    } else if (recommended.length) {
        groups.push({ label: 'Recommended', items: recommended, isRecommended: true });
    }
    if (qualified.length) groups.push({ label: qualifiedGroupLabel, items: qualified });
    if (others.length) groups.push({ label: 'Other Active Faculty (Manual Override)', items: others });
    return groups;
};

/* --- Rooms — every active room, grouped so the type that matches the    */
/* subject's hours (Laboratory rooms if it has lab hours, otherwise       */
/* Lecture rooms) floats to the top. Any active room can still be         */
/* picked manually.                                                       */

const roomGroupsFor = (row) => {
    const subject = row.subject;
    const wantsLaboratory = Number(subject?.laboratory_hours ?? 0) > 0;
    const typeMatch = wantsLaboratory ? 'Laboratory' : 'Lecture';

    const recs = recommendations[row.id]?.room?.recommendations ?? [];
    const recommendedIds = new Set(recs.map((r) => r.id));

    const recommended = recs
        .filter((r) => props.activeRooms.some((room) => room.id === r.id))
        .map((r) => ({
            label: `${r.name} (${r.capacity})`,
            value: r.id,
            confidence: r.confidence,
            scheduledHours: r.scheduled_hours,
            maxHours: r.max_hours,
        }));

    // A room belongs to a "different College" when it carries a
    // college_id that ISN'T this Section's own — a Shared room
    // (college_id null) is never flagged, and General Education
    // subjects have no single owning College so every room is
    // fair game for them. Mirrors the roomCollegeMismatch() check in
    // tableConflicts() below and the server's own
    // room_college_confirmed gate, so the dropdown's grouping never
    // disagrees with what actually triggers the warning.
    const isGenEdSubject = subject?.category === 'General Education';
    const isDifferentCollege = (room) =>
        !isGenEdSubject && room.college_id && sectionCollegeId.value && room.college_id !== sectionCollegeId.value;

    const typeMatched = [];
    const others = [];
    const differentCollege = [];

    props.activeRooms.forEach((room) => {
        if (recommendedIds.has(room.id)) return;
        const option = {
            label: `${room.room_name} (${room.capacity})`,
            value: room.id,
            scheduledHours: room.scheduled_hours,
            maxHours: room.max_hours,
            differentCollege: isDifferentCollege(room),
        };
        if (isDifferentCollege(room)) {
            differentCollege.push(option);
        } else if (room.room_type === typeMatch) {
            typeMatched.push(option);
        } else {
            others.push(option);
        }
    });

    const groups = [];
    if (isRecommendationsLoading(row.id) && !recommended.length) {
        groups.push({
            label: 'Recommended',
            items: [{ label: 'Finding the best matches…', value: '__loading__', disabled: true }],
            isRecommended: true,
        });
    } else if (recommended.length) {
        groups.push({ label: 'Recommended', items: recommended, isRecommended: true });
    }
    if (typeMatched.length) {
        groups.push({ label: wantsLaboratory ? 'Laboratory Rooms' : 'Lecture Rooms', items: typeMatched });
    }
    if (others.length) groups.push({ label: 'Other Rooms', items: others });
    // Kept as its own, clearly-labeled group at the bottom rather than
    // folded into "Other Rooms" — picking one still works exactly the
    // same way (Manual Override), but the Registrar sees up front that
    // this pool belongs to another College before they click it,
    // instead of only finding out from the warning after the fact.
    if (differentCollege.length) {
        groups.push({ label: 'Other College Rooms (Override)', items: differentCollege });
    }
    return groups;
};

/* --- Time pickers — 30-minute interval dropdowns, "HH:mm" strings --- */

// --- 30-Minute Interval Time Dropdowns — replaces the old scrolling
// hour/minute/am-pm DatePicker spinner with a flat, searchable list of
// times, generated directly from the active School Year's Academic
// Calendar window (schedulingWindow.start_time/end_time — the same
// hard boundary overrideTime()/candidateStartTimes() already enforce
// server-side), so the Registrar can never even open a time outside
// what the calendar allows. Falls back to a fixed 30-minute step; the
// School Year's own configured interval isn't sent to this page today
// (only Room Grid receives interval_minutes), so 30 is the safe,
// widely-applicable default across every School Year's setup.
const TIME_OPTION_STEP_MINUTES = 30;
const formatTimeOptionLabel = (hours, minutes) => {
    const period = hours >= 12 ? 'PM' : 'AM';
    const hour12 = hours % 12 === 0 ? 12 : hours % 12;
    return `${hour12}:${String(minutes).padStart(2, '0')} ${period}`;
};
const timeOptions = computed(() => {
    const [startH, startM] = props.schedulingWindow.start_time.split(':').map(Number);
    const [endH, endM] = props.schedulingWindow.end_time.split(':').map(Number);
    const startMinutes = startH * 60 + startM;
    const endMinutes = endH * 60 + endM;

    // Lunch Break restriction removed per adviser direction — the
    // 12:00 PM-1:00 PM window is no longer excluded from Start/End
    // Time options; classes may be scheduled through it freely.
    const options = [];
    for (let m = startMinutes; m <= endMinutes; m += TIME_OPTION_STEP_MINUTES) {
        const h = Math.floor(m / 60);
        const min = m % 60;
        const value = `${String(h).padStart(2, '0')}:${String(min).padStart(2, '0')}`;
        options.push({ label: formatTimeOptionLabel(h, min), value });
    }
    return options;
});

/* --- Busy Time Ranges — grey out Start/End Time slots that would        */
/* overlap a schedule the row's currently selected Room and/or Faculty    */
/* already has on the currently selected Days (e.g. Room 306 already      */
/* booked 1:00 PM-5:00 PM and 5:00 PM-7:00 PM on Sat), instead of only     */
/* rejecting the pick after Save. Keyed by row id; refetched whenever     */
/* Room, Faculty, or Days changes on that row.                            */

const busyTimes = reactive({});

const fetchBusyTimes = async (row) => {
    if ((!row.room_id && !row.faculty_id) || !row.days || row.days.length === 0) {
        busyTimes[row.id] = [];
        return;
    }

    try {
        const url = new URL(route('scheduling.section-subjects.busy-times', [props.section.id, row.id]), window.location.origin);
        if (row.room_id) url.searchParams.set('room_id', row.room_id);
        if (row.faculty_id) url.searchParams.set('faculty_id', row.faculty_id);
        url.searchParams.set('days', row.days.join(','));
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        busyTimes[row.id] = data.busy ?? [];
    } catch (e) {
        busyTimes[row.id] = [];
    }
};

// Same overlap rule ScheduleConflictService::overlaps() uses server-side
// (new_start < existing_end AND new_end > existing_start), mirrored here
// so the dropdown's greyed-out slots can never disagree with what Save
// would actually reject.
const rangesOverlap = (startA, endA, startB, endB) => startA < endB && endA > startB;

// A Start Time is unusable if it falls inside an already-booked window
// for this row's Room/Faculty — the class can't begin while the
// resource is still occupied by something else.
const timeOptionsFor = (row) => {
    const ranges = busyTimes[row.id] ?? [];
    return timeOptions.value.map((opt) => ({
        ...opt,
        disabled: ranges.some((r) => opt.value >= r.start_time && opt.value < r.end_time),
    }));
};

const endTimeOptionsFor = (row) => {
    const base = row.start_time ? timeOptions.value.filter((opt) => opt.value > row.start_time) : timeOptions.value;
    if (!row.start_time) return base;

    const ranges = busyTimes[row.id] ?? [];
    return base.map((opt) => ({
        ...opt,
        disabled: ranges.some((r) => rangesOverlap(row.start_time, opt.value, r.start_time, r.end_time)),
    }));
};

/* --- Auto End Time — a Subject's weekly contact hours (Lecture +        */
/* Laboratory) are split evenly across however many Days are selected,   */
/* then added to Start Time. E.g. a 5-hour subject on 1 day runs 5       */
/* hours; on 2 days (M&Th) each session runs 2h30m. Recomputed whenever  */
/* Start Time or Days changes — the Registrar can still overwrite End    */
/* Time by hand afterward.                                               */

const weeklyContactHours = (row) => {
    const lecture = Number(row.subject?.lecture_hours ?? 0);
    const laboratory = Number(row.subject?.laboratory_hours ?? 0);
    return lecture + laboratory;
};

// SIBLING SECTION PATTERN — Auto End Time normally targets the
// Subject's full curriculum hours (weeklyContactHours below), which is
// a sensible DEFAULT but ignores an already-confirmed sibling section
// that intentionally runs shorter (e.g. BSIT-4A's CAP102 confirmed at
// 4 hrs/week instead of the curriculum's 5 because of a Room/Faculty
// availability constraint). Reuses the SAME recommendations[] cache
// the Recommend panel already prefetches for every row on load (see
// fetchRecommendations()/prefetchRecommendations() — the identical
// scheduling.section-subjects.recommend endpoint RoomGrid's own
// drag-and-drop sibling prefill calls), so no extra network round
// trip is needed here.
//
// This only changes what Auto End Time defaults TO — the Registrar
// can still overwrite End Time by hand afterward, and the Hours
// Mismatch confirm gate on Save Schedule keeps checking the actual
// scheduled hours against the curriculum's declared hours exactly as
// before (weeklyContactHours is untouched), so a sibling-derived 4
// hrs still asks for the same "Weekly Hours Mismatch — Save Anyway"
// confirmation it would if typed in by hand.
const siblingPatternHoursFor = (row) => {
    const top = recommendations[row.id]?.combined?.recommendations?.[0];
    if (!top?.is_sibling_pattern || !top.time?.start_time || !top.time?.end_time) return null;

    const meetingsPerWeek = (top.time.days ?? []).length || 1;
    const perMeetingMinutes = minutesBetweenTimes(top.time.start_time, top.time.end_time);
    if (!perMeetingMinutes) return null;

    return (perMeetingMinutes * meetingsPerWeek) / 60;
};

const computeAutoEndTime = (row) => {
    const totalHours = siblingPatternHoursFor(row) ?? weeklyContactHours(row);
    const dayCount = row.days?.length ?? 0;

    if (!row.start_time || !totalHours || !dayCount) return null;

    const [startHours, startMinutes] = row.start_time.split(':').map(Number);
    const sessionMinutes = Math.round((totalHours / dayCount) * 60);
    const endTotalMinutes = startHours * 60 + startMinutes + sessionMinutes;

    const endHours = String(Math.floor(endTotalMinutes / 60) % 24).padStart(2, '0');
    const endMinutes = String(endTotalMinutes % 60).padStart(2, '0');

    return `${endHours}:${endMinutes}`;
};

// The curriculum's weekly contact hours, split evenly across however
// many Days are selected, is the MAXIMUM a single meeting may run —
// the Registrar can always trim a meeting shorter (e.g. only 4 of the
// curriculum's 5 hrs fit because of Room/Faculty availability), but
// never stretch it past what the curriculum actually calls for.
const maxSessionMinutes = (row) => {
    const totalHours = weeklyContactHours(row);
    const dayCount = row.days?.length ?? 0;
    if (!totalHours || !dayCount) return null;
    return Math.round((totalHours / dayCount) * 60);
};

const clampEndTimeToMax = (row) => {
    const cap = maxSessionMinutes(row);
    if (!cap || !row.start_time || !row.end_time) return;

    const [startHours, startMinutes] = row.start_time.split(':').map(Number);
    const [endHours, endMinutes] = row.end_time.split(':').map(Number);
    const actualMinutes = (endHours * 60 + endMinutes) - (startHours * 60 + startMinutes);

    if (actualMinutes > cap) {
        const cappedTotal = startHours * 60 + startMinutes + cap;
        const h = String(Math.floor(cappedTotal / 60) % 24).padStart(2, '0');
        const m = String(cappedTotal % 60).padStart(2, '0');
        row.end_time = `${h}:${m}`;
    }
};

const autoFillEndTime = (row) => {
    const computed = computeAutoEndTime(row);
    if (computed) {
        row.end_time = computed;
        markDirty(row, 'end_time');
    }
};

/* --- Status / conflict badge --- */

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

// True once at least one scheduling field is blocked by a Faculty,
// Room, or Time conflict — surfaced as a warning badge next to Status.
// `workload` covers a row skipped by a batch Save because its Faculty's
// Teaching Load warning wasn't confirmed (see the Save handler below) —
// without it, those rows silently fail to save with no visible highlight.
const hasActiveConflict = (row) => {
    const errors = stateFor(row.id).errors;
    return Boolean(errors.faculty_id || errors.room_id || errors.days || errors.start_time || errors.end_time || errors.workload);
};

/* ------------------------------------------------------------------ */
/* Real-time conflict detection (Prompt 8.5)                           */
/*                                                                       */
/* Recomputed on every keystroke/selection from the local `rows` copy — */
/* no server round trip — so the Conflict Panel, row highlighting, and  */
/* Status badges update immediately as the Registrar edits a cell.      */
/* This covers Faculty/Room/Section conflicts *within this section's    */
/* own table* (everything the Registrar can see and cause here); a      */
/* Faculty or Room clash against a *different* section is still caught  */
/* by the existing server-side check when "Save Schedule" is clicked,   */
/* since this workspace doesn't load every other section's schedule.    */
/* ------------------------------------------------------------------ */

const roomsById = computed(() => Object.fromEntries(props.activeRooms.map((room) => [room.id, room])));
const facultyById = computed(() => Object.fromEntries(props.activeFaculty.map((f) => [f.id, f])));

const daysOverlap = (a, b) => (a ?? []).some((day) => (b ?? []).includes(day));
const timeOverlap = (aStart, aEnd, bStart, bEnd) => aStart < bEnd && bStart < aEnd;
const minutesBetweenTimes = (start, end) => {
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    return eh * 60 + em - (sh * 60 + sm);
};

const rowIsSchedulable = (row) => row.days?.length > 0 && row.start_time && row.end_time;

// { faculty: Map<rowId, [{type, message, otherRowId}]>, ... } — built as
// a flat list of conflict entries, each naming the row(s) it applies to.
const tableConflicts = computed(() => {
    const list = [];
    const data = rows.value;

    for (let i = 0; i < data.length; i++) {
        const a = data[i];

        // Room Capacity Warning — independent of other rows.
        if (a.room_id && a.capacity) {
            const room = roomsById.value[a.room_id];
            if (room && Number(a.capacity) > Number(room.capacity)) {
                list.push({
                    type: 'capacity',
                    rowIds: [a.id],
                    label: 'Capacity Warning',
                    detail: `${a.subject?.subject_code ?? 'Subject'} — Section Capacity ${a.capacity}, Room Capacity ${room.capacity} (${room.room_name})`,
                });
            }
        }

        // Room Type Mismatch Warning — a Lecture-only (e.g. Minor/
        // GenEd) subject manually assigned to a Laboratory room, or a
        // Laboratory subject assigned to a plain Lecture room.
        // Confirmable, not a hard block — mirrors the Room Capacity
        // Warning above exactly, and the same room_type check the
        // server runs on Save Schedule (SectionSubjectController).
        if (a.room_id) {
            const room = roomsById.value[a.room_id];
            if (room && room.room_type && !stateFor(a.id).roomTypeConfirmed) {
                const wantsLaboratory = Number(a.subject?.laboratory_hours ?? 0) > 0;
                const typeMismatch = wantsLaboratory
                    ? room.room_type !== 'Laboratory'
                    : room.room_type === 'Laboratory';
                if (typeMismatch) {
                    list.push({
                        type: 'roomType',
                        rowIds: [a.id],
                        label: 'Room Type Mismatch',
                        detail: `${a.subject?.subject_code ?? 'Subject'} is a ${wantsLaboratory ? 'Laboratory' : 'Lecture'} subject, but ${room.room_name} is a ${room.room_type} room.`,
                    });
                }
            }
        }

        // Room College Mismatch Warning — this Room belongs to a
        // DIFFERENT College than the Section's own (e.g. a BSIT/Major
        // subject dropped into an SHTM Laboratory). Confirmable, not a
        // hard block — a Minor/GenEd subject legitimately borrowing
        // another College's room, or a deliberate Registrar override,
        // is allowed, but must be explicitly confirmed first. Mirrors
        // the Room Type Mismatch check above and the server's own
        // room_college_confirmed gate exactly. Shared rooms (no
        // college_id) and General Education subjects (no single
        // owning College) are never flagged.
        if (a.room_id) {
            const room = roomsById.value[a.room_id];
            if (
                room
                && room.college_id
                && a.subject?.category !== 'General Education'
                && sectionCollegeId.value
                && room.college_id !== sectionCollegeId.value
                && !stateFor(a.id).roomCollegeConfirmed
            ) {
                const roomCollegeLabel = room.college_name ?? 'another college';
                list.push({
                    type: 'roomCollege',
                    rowIds: [a.id],
                    label: 'Room College Mismatch',
                    detail: `${a.subject?.subject_code ?? 'Subject'} belongs to ${sectionCollegeName.value ?? "this section's own college"}, but ${room.room_name} belongs to ${roomCollegeLabel}.`,
                });
            }
        }

        // Faculty/Room/Section Double-Booking — set on the row when a
        // manual Time override (see onTimeOverride) was applied
        // against a slot ScheduleConflictService already found
        // occupied elsewhere (a genuine hard_conflict, not just an
        // off-pattern/short-duration warning — see scoreArbitraryTime's
        // docblock). Unlike Hours Mismatch/Capacity above, this is
        // NEVER confirmable — the Registrar can still pick a different
        // Faculty/Room/Time, but this exact combination can never be
        // saved, matching the same hard-block treatment as an
        // in-table Section Conflict.
        if (a.auto_generated_meta?.time?.hard_conflict) {
            list.push({
                type: 'hard_conflict',
                rowIds: [a.id],
                label: 'Schedule Conflict',
                detail: `${a.subject?.subject_code ?? 'Subject'} — ${a.auto_generated_meta.time.override_reason ?? 'The selected Faculty, Room, or Section is already booked at this day/time.'}`,
            });
        }

        // Faculty Mismatch Warning — the assigned Faculty carries no
        // Teaching Qualification for this Subject and isn't from its
        // academic home College/GenEd pool (see isQualifiedFor()
        // above — the exact same rule the Faculty dropdown's
        // Recommended/Qualified/Manual Override grouping already
        // uses). Confirmable, not a hard block — Manual Override is a
        // deliberately supported path — mirrors Room Type/Room
        // College Mismatch exactly.
        if (a.faculty_id && !stateFor(a.id).facultyMismatchConfirmed) {
            const faculty = facultyById.value[a.faculty_id];
            if (faculty && a.subject && !isQualifiedFor(faculty, a.subject)) {
                list.push({
                    type: 'facultyMismatch',
                    rowIds: [a.id],
                    label: 'Faculty Mismatch',
                    detail: `${faculty.full_name} is not on file as qualified or from the academic home for ${a.subject?.subject_code ?? 'this subject'}.`,
                });
            }
        }

        // Weekly Hours Mismatch Warning — mirrors the same check the
        // server runs on Save Schedule (SectionSubjectController).
        // Flexible by design: the Registrar is free to trim a
        // session shorter (or longer) than the Subject's declared
        // Lecture+Laboratory hours — e.g. Sibling Pattern Matching
        // deliberately copies a donor's ACTUAL saved duration even
        // when it diverges from the textbook hours — so this is a
        // confirmable warning, never a hard block. Surfacing it here
        // (rather than only on the server) is what lets the row
        // highlight and the Registrar confirm it up front instead of
        // hitting an opaque "Nothing saved" after clicking Save.
        //
        // Confirmation now SEEDS from the row's persisted
        // `hours_confirmed` column (see stateFor() above) so an
        // already-confirmed-and-saved mismatch doesn't reappear on
        // every page reload — see the BUG FIX note on stateFor() for
        // the full reasoning. It still gets reset to unconfirmed the
        // moment Days/Start/End Time change again this session (see
        // onDaysChange/onStartTimeChange/onEndTimeChange), so editing
        // a row always re-surfaces a genuinely new mismatch.
        if (rowIsSchedulable(a) && !stateFor(a.id).hoursConfirmed) {
            const required = weeklyContactHours(a) > 0 ? weeklyContactHours(a) : 3;
            const dayCount = a.days.length;
            const actual = Math.round(((minutesBetweenTimes(a.start_time, a.end_time) * dayCount) / 60) * 100) / 100;

            if (actual !== required) {
                list.push({
                    type: 'hours',
                    rowIds: [a.id],
                    label: 'Hours Mismatch',
                    detail: `${a.subject?.subject_code ?? 'Subject'} — this schedule totals ${actual} hrs/week, but the subject's declared hours are ${required} hrs/week.`,
                });
            }
        }

        if (!rowIsSchedulable(a)) continue;

        for (let j = i + 1; j < data.length; j++) {
            const b = data[j];
            if (!rowIsSchedulable(b)) continue;
            if (!daysOverlap(a.days, b.days)) continue;
            if (!timeOverlap(a.start_time, a.end_time, b.start_time, b.end_time)) continue;

            // Section Conflict — this section can't be in two places at once,
            // regardless of Faculty/Room, any time two of its own rows overlap.
            list.push({
                type: 'section',
                rowIds: [a.id, b.id],
                label: 'Section Conflict',
                detail: `${a.subject?.subject_code ?? 'Subject'} overlaps ${b.subject?.subject_code ?? 'Subject'} — ${formatDays(a.days.filter((d) => b.days.includes(d)))} ${formatTimeRange(a.start_time, a.end_time)}`,
            });

            if (a.faculty_id && a.faculty_id === b.faculty_id) {
                const facultyName = facultyById.value[a.faculty_id]?.full_name ?? 'Faculty';
                list.push({
                    type: 'faculty',
                    rowIds: [a.id, b.id],
                    label: 'Faculty Conflict',
                    detail: `${facultyName} — ${formatDays(a.days.filter((d) => b.days.includes(d)))} ${formatTimeRange(a.start_time, a.end_time)}`,
                });
            }

            if (a.room_id && a.room_id === b.room_id) {
                const room = roomsById.value[a.room_id];
                list.push({
                    type: 'room',
                    rowIds: [a.id, b.id],
                    label: 'Room Conflict',
                    detail: `${room ? room.room_name : 'Room'} — ${formatDays(a.days.filter((d) => b.days.includes(d)))} ${formatTimeRange(a.start_time, a.end_time)}`,
                });
            }
        }
    }

    return list;
});

const formatTimeRange = (start, end) => {
    const fmt = (value) => {
        if (!value) return '';
        const [h, m] = value.split(':').map(Number);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
    };
    return `${fmt(start)} - ${fmt(end)}`;
};

// Rows with an unconfirmed Capacity Warning — these are the only
// conflict type that can be overridden by the Registrar.
const unconfirmedCapacityRowIds = computed(() =>
    tableConflicts.value.filter((c) => c.type === 'capacity').map((c) => c.rowIds[0]).filter((id) => !stateFor(id).capacityConfirmed),
);

// Rows with an unconfirmed Weekly Hours Mismatch — confirmable, same
// pattern as unconfirmedCapacityRowIds above.
const unconfirmedHoursRowIds = computed(() =>
    tableConflicts.value.filter((c) => c.type === 'hours').map((c) => c.rowIds[0]).filter((id) => !stateFor(id).hoursConfirmed),
);

// Rows with an unconfirmed Room Type Mismatch — confirmable, same
// pattern as unconfirmedCapacityRowIds/unconfirmedHoursRowIds above.
const unconfirmedRoomTypeRowIds = computed(() =>
    tableConflicts.value.filter((c) => c.type === 'roomType').map((c) => c.rowIds[0]).filter((id) => !stateFor(id).roomTypeConfirmed),
);

// Rows with an unconfirmed Room College Mismatch — confirmable, same
// pattern as unconfirmedRoomTypeRowIds above.
const unconfirmedRoomCollegeRowIds = computed(() =>
    tableConflicts.value.filter((c) => c.type === 'roomCollege').map((c) => c.rowIds[0]).filter((id) => !stateFor(id).roomCollegeConfirmed),
);

// Rows with an unconfirmed Faculty Mismatch — confirmable, same
// pattern as unconfirmedRoomTypeRowIds/unconfirmedRoomCollegeRowIds
// above.
const unconfirmedFacultyMismatchRowIds = computed(() =>
    tableConflicts.value.filter((c) => c.type === 'facultyMismatch').map((c) => c.rowIds[0]).filter((id) => !stateFor(id).facultyMismatchConfirmed),
);

// A row is "blocking" (true Conflict — Faculty/Room/Section) if it
// appears in any non-capacity/hours/roomType/roomCollege/facultyMismatch conflict entry.
const blockingConflictRowIds = computed(
    () => new Set(tableConflicts.value.filter((c) => c.type !== 'capacity' && c.type !== 'hours' && c.type !== 'roomType' && c.type !== 'roomCollege' && c.type !== 'facultyMismatch').flatMap((c) => c.rowIds)),
);

const rowHasBlockingConflict = (rowId) => blockingConflictRowIds.value.has(rowId);

// A row counts as "in conflict" for red-highlight/click-to-resolve
// purposes if EITHER the client-side same-table check (Faculty/Room/
// Section overlap against another row in this section) fired, OR the
// server rejected it (e.g. outside allowed class hours, lunch break,
// or a Faculty/Room clash against a *different* section that this
// workspace can't see client-side). Capacity Warnings are excluded —
// those are confirmable, not a hard conflict.
const rowIsInConflict = (row) => rowHasBlockingConflict(row.id) || hasActiveConflict(row);

// Row-click-to-resolve should only fire when the Registrar clicks
// empty space on a conflicted row (e.g. the EDP Code / Subject Title
// area) — never when they're actually interacting with one of the
// row's own Faculty/Room/Days/Time/Recommend/Delete controls, or the
// click would fight with those controls' own handlers.
const isInteractiveTarget = (target) =>
    Boolean(target?.closest?.('button, a, input, textarea, .p-select, .p-multiselect, .p-datepicker, .p-popover, [role="button"]'));

const onRowClick = (event) => {
    if (!rowIsInConflict(event.data)) return;
    if (isInteractiveTarget(event.originalEvent?.target)) return;
    openRecommendDrawer(event.data);
};
const facultyConflictRowIds = computed(() => new Set(tableConflicts.value.filter((c) => c.type === 'faculty').flatMap((c) => c.rowIds)));
const roomConflictRowIds = computed(() => new Set(tableConflicts.value.filter((c) => c.type === 'room').flatMap((c) => c.rowIds)));
const sectionConflictRowIds = computed(() => new Set(tableConflicts.value.filter((c) => c.type === 'section').flatMap((c) => c.rowIds)));
const rowHasCapacityWarning = (rowId) => tableConflicts.value.some((c) => c.type === 'capacity' && c.rowIds.includes(rowId));
const rowHasRoomTypeWarning = (rowId) => tableConflicts.value.some((c) => c.type === 'roomType' && c.rowIds.includes(rowId));
const rowHasRoomCollegeWarning = (rowId) => tableConflicts.value.some((c) => c.type === 'roomCollege' && c.rowIds.includes(rowId));
const rowHasFacultyMismatchWarning = (rowId) => tableConflicts.value.some((c) => c.type === 'facultyMismatch' && c.rowIds.includes(rowId));

const conflictTooltip = (rowId) => {
    const clientMessages = tableConflicts.value
        .filter((c) => c.rowIds.includes(rowId))
        .map((c) => `${c.label}: ${c.detail}`);

    // Server-side errors (outside class hours, lunch break, or a
    // Faculty/Room clash against a section this workspace never
    // loaded) don't have a `tableConflicts` entry — surface them too
    // so the tooltip/click-to-resolve always reflects everything that
    // is actually blocking this row.
    const serverMessages = Object.values(stateFor(rowId).errors ?? {});

    return [...clientMessages, ...serverMessages].join('\n');
};

// Real-time display status — overrides the last-saved `status` value so
// the badge reflects the *current* (unsaved) local edit immediately.
const displayStatus = (row) => {
    if (rowHasBlockingConflict(row.id)) return 'Conflict';
    return row.status;
};

// True when a row has none of its core scheduling fields filled in
// yet (no Faculty, Room, Days, Start Time, or End Time). Distinct from
// a Conflict/capacity warning — an unscheduled row isn't wrong, it's
// just incomplete — so it gets its own subtler "needs scheduling"
// treatment instead of reading like a rendering bug or an error.
const rowIsUnscheduled = (row) => {
    // Practicum/OJT subjects have no Room/Days/Time to fill in and
    // Faculty is an optional Coordinator/Adviser — the row is complete
    // as soon as it exists, so it's never flagged "Not yet scheduled".
    if (row.subject?.subject_type === 'practicum') return false;

    return (
        !row.faculty_id &&
        !row.room_id &&
        (!row.days || row.days.length === 0) &&
        !row.start_time &&
        !row.end_time
    );
};

/* ------------------------------------------------------------------ */
/* Smart Assignment Recommendation Engine (Prompt 8.6)                 */
/*                                                                       */
/* Ranked Faculty/Room/Time suggestions per row, fetched from           */
/* RecommendationService via the server. Purely advisory — nothing      */
/* here ever writes to row.faculty_id / room_id / days / times on its   */
/* own; the Registrar must explicitly click "Use This" for each field.  */
/* ------------------------------------------------------------------ */

const recommendations = reactive({});

const recommendationStateFor = (rowId) => recommendations[rowId] ?? { loading: false, error: null, faculty: null, room: null, time: null };

// CONNECTION-POOL CONTENTION FIX — browsers cap concurrent connections
// per origin at 6 (HTTP/1.1), and PREFETCH_CONCURRENCY below deliberately
// uses all 6 to warm up every row's dropdown as fast as possible. That's
// fine on its own, but it means a user action that needs a fetch of its
// own (like "Clear Schedule") can end up queued behind still-in-flight
// prefetch requests instead of firing immediately — the request *looks*
// slow even though the backend responds fast. Tracking each prefetch's
// AbortController here lets any higher-priority action cancel the whole
// queue first and free up a connection slot right away.
const activeRecommendationRequests = new Set();

const fetchRecommendations = async (row, force = false) => {
    const existing = recommendations[row.id];
    if (!force && (existing?.loading || existing?.faculty)) return;

    recommendations[row.id] = { ...(existing ?? {}), loading: true, error: null };

    const controller = new AbortController();
    activeRecommendationRequests.add(controller);

    try {
        const response = await fetch(route('scheduling.section-subjects.recommend', [props.section.id, row.id]), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (!response.ok) throw new Error('Request failed');
        const data = await response.json();
        recommendations[row.id] = { loading: false, error: null, ...data };
    } catch (e) {
        // An aborted prefetch (e.g. because Clear Schedule needed the
        // connection slot) isn't a real error — leave the row as
        // "not yet loaded" so a later hover/prefetch can retry it,
        // instead of permanently flagging it as failed.
        if (e?.name === 'AbortError') {
            recommendations[row.id] = { loading: false, error: null, faculty: null, room: null, time: null };
        } else {
            recommendations[row.id] = { loading: false, error: 'Could not load recommendations for this subject.', faculty: null, room: null, time: null };
        }
    } finally {
        activeRecommendationRequests.delete(controller);
    }
};

// Cancels every recommendation prefetch still in flight so an urgent
// request (Clear Schedule, Clear Auto-Generated, etc.) doesn't have to
// wait its turn behind the per-origin connection cap.
const cancelPendingRecommendationPrefetch = () => {
    activeRecommendationRequests.forEach((controller) => controller.abort());
    activeRecommendationRequests.clear();
};

// BUG FIX — "Select faculty"/"Select room" dropdown flashing the
// unranked full roster/room list first, then re-rendering a few
// seconds later once fetchRecommendations() resolves. Two things
// combine to fix this:
//   1. Prefetch every row's recommendations in the background as
//      soon as the row list is available/changes (below), AND on
//      @mouseenter of the field itself (before the click that opens
//      it even lands) — buying the request extra head start.
//   2. While a row's fetch is still in flight, facultyGroupsFor()/
//      roomGroupsFor() surface a "Loading Recommendations…" group
//      instead of silently falling back to the plain roster, so a
//      very fast click never reads as "no recommendation exists" —
//      the panel swaps to the real "Recommended" group the instant
//      the fetch resolves (recommendations is reactive, so this
//      happens live even while the dropdown is already open).
// fetchRecommendations() itself already no-ops once a row is
// loaded/loading, so prefetch/hover/@show can all call it freely.
const PREFETCH_CONCURRENCY = 6;
const prefetchRecommendations = async (targetRows) => {
    const queue = [...targetRows];
    const worker = async () => {
        let row;
        while ((row = queue.shift())) {
            await fetchRecommendations(row);
        }
    };
    await Promise.all(Array.from({ length: PREFETCH_CONCURRENCY }, worker));
};

onMounted(() => {
    prefetchRecommendations(rows.value);
    rows.value.forEach((row) => fetchBusyTimes(row));
});

watch(
    () => props.sectionSubjects,
    () => {
        prefetchRecommendations(rows.value);
        rows.value.forEach((row) => fetchBusyTimes(row));
    },
);

const isRecommendationsLoading = (rowId) => recommendations[rowId]?.loading === true && !recommendations[rowId]?.faculty;

const confidenceSeverity = (label) => {
    switch (label) {
        case 'Best Match':
            return 'success';
        case 'Good Match':
            return 'info';
        default:
            return 'warning';
    }
};

// Recommendation Score progress-bar color — mirrors the same
// Best Match / Good Match / Alternative bands the badge uses.
const scoreColor = (score) => {
    if (score >= 85) return '#16a34a';
    if (score >= 65) return '#2563eb';
    return '#d97706';
};

// "Use This" — the Registrar's explicit confirmation. Recommendations
// never auto-assign; these are the only paths that fill a field from
// a suggestion, and they're only ever triggered by a manual click.
const applyFacultyRecommendation = (row, rec) => onFacultyChange(row, rec.id);
const applyRoomRecommendation = (row, rec) => onRoomChange(row, rec.id);
const applyTimeRecommendation = (row, rec) => {
    row.days = [...rec.days];
    row.start_time = rec.start_time;
    row.end_time = rec.end_time;
    markDirty(row, 'days');
    markDirty(row, 'start_time');
    markDirty(row, 'end_time');
};

/* --- Inline "Suggested Time" popover (Days column) ------------------ */
/* Faculty/Room already surface a "Recommended" group right inside      */
/* their own dropdown; Time gets the same one-click treatment here      */
/* instead of requiring the Registrar to expand the row's full panel.   */

const helpPopover = ref(null);
const toggleHelp = (event) => helpPopover.value?.toggle(event);

/* ------------------------------------------------------------------ */
/* Smart Schedule Recommendation Drawer (Prompt 8.7)                    */
/*                                                                       */
/* Right-side PrimeVue Drawer opened from a per-row "Recommend" button. */
/* Shows the same Faculty/Room/Time lists as the row's inline panel,    */
/* plus full-schedule Combined Recommendations built server-side by     */
/* RecommendationService (Faculty x Room x Time, all conflict-checked   */
/* via ScheduleConflictService). Reject = just close the Drawer; Apply  */
/* only ever populates the row locally — the Registrar still has to     */
/* click "Save Schedule" to persist anything.                           */
/* ------------------------------------------------------------------ */

const recommendDrawerVisible = ref(false);
const recommendDrawerRow = ref(null);

const openRecommendDrawer = (row) => {
    recommendDrawerRow.value = row;
    recommendDrawerVisible.value = true;
    fetchRecommendations(row, true);
};

const recommendDrawerState = computed(() => recommendationStateFor(recommendDrawerRow.value?.id));

const closeRecommendDrawer = () => {
    recommendDrawerVisible.value = false;
};

// Accept a Combined Recommendation — populates Faculty, Room, Days,
// Start Time, and End Time on the row in one click, exactly like
// applying all three individual recommendations at once.
const applyCombinedRecommendation = (row, combo) => {
    onFacultyChange(row, combo.faculty.id);
    onRoomChange(row, combo.room.id);
    row.days = [...combo.time.days];
    row.start_time = combo.time.start_time;
    row.end_time = combo.time.end_time;
    markDirty(row, 'days');
    markDirty(row, 'start_time');
    markDirty(row, 'end_time');
    closeRecommendDrawer();
};

const applyFacultyRecommendationFromDrawer = (row, rec) => {
    applyFacultyRecommendation(row, rec);
};
const applyRoomRecommendationFromDrawer = (row, rec) => {
    applyRoomRecommendation(row, rec);
};
const applyTimeRecommendationFromDrawer = (row, rec) => {
    applyTimeRecommendation(row, rec);
};

/* --- Manual scheduling — edits stay local until "Save Schedule" is      */
/* clicked. No per-cell auto-save; the Registrar can edit as many rows    */
/* as they like first. Every edited field just updates the local row     */
/* and marks it "dirty"; nothing hits the server until Save.              */

// Laravel refreshes the XSRF-TOKEN cookie on every response, unlike the
// <meta name="csrf-token"> tag which is frozen at initial page load and
// goes stale the moment the session's token rotates (e.g. right after
// login). Reading it fresh from the cookie for every request avoids
// spurious "CSRF token mismatch" errors without needing a page refresh.
const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

/* ------------------------------------------------------------------ */
/* REAL-TIME SCHEDULE CHANGE DETECTION — lightweight polling.          */
/*                                                                       */
/* UX/early-warning layer only. Every 15s (paused/slowed while this     */
/* tab is hidden), asks a tiny endpoint "has this Section's schedule    */
/* changed?" and, if so, marks the page stale — a dismissible banner    */
/* plus a disabled/"refresh first" state on Save Schedule / Accept All  */
/* & Save. Never auto-reloads, never touches in-progress edits or the   */
/* open Auto Schedule modal. The backend's own version check inside a   */
/* locked transaction (HTTP 409 SCHEDULE_VERSION_CONFLICT) remains the  */
/* only thing that actually blocks a stale write — see saveSchedule().  */
/* ------------------------------------------------------------------ */

const schedulePolling = useSchedulePolling({
    sectionId: () => props.section.id,
    initialVersion: () => props.scheduleVersion,
    // ACTOR-AWARE VERSION — read from the existing shared Inertia
    // auth prop (HandleInertiaRequests::share()) rather than hardcoding
    // an id, so the "was this my own change?" check always reflects
    // whoever is actually logged in.
    currentUserId: () => page.props.auth?.user?.id ?? null,
    fetchVersion: async (sectionId) => {
        const response = await fetch(route('scheduling.section-subjects.version', sectionId), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Version check failed');
        return response.json();
    },
    intervalMs: 15000,
});

schedulePolling.start();

// BUG FIX — switching Sections via the header dropdown above
// (onSwitchSection()) does a router.visit() to this SAME route
// component, so Inertia reuses the existing Vue instance instead of
// remounting it. useSchedulePolling() only reads `initialVersion`
// ONCE, at setup time, so without this its `currentVersion` stays
// frozen at whichever Section was loaded first — the very next poll
// then compares the NEW Section's real version against that stale
// leftover number, and (since they're essentially never equal)
// immediately — and wrongly — reports "Another user changed this
// schedule" on a Section nobody else has touched. resetForSection()
// exists on the composable for exactly this case; it just needed to
// be wired up here.
watch(
    () => props.section.id,
    () => {
        schedulePolling.resetForSection(props.scheduleVersion);
    },
);

// Header's "Refresh Schedule" action — re-fetches this Section's page
// via Inertia (same route, so it's a lightweight partial reload of
// props only) rather than a hard browser reload, then clears the
// stale flag once the fresh props have landed. If the Registrar has
// local unsaved edits (dirty rows, or the Auto Schedule review panel
// still open), confirm first so nothing is silently discarded.
const refreshingSchedule = ref(false);
const refreshSchedule = () => {
    const hasUnsavedWork = dirtyRowIds.value.size > 0 || autoSummaryVisible.value;

    const doRefresh = () => {
        refreshingSchedule.value = true;
        router.reload({
            onFinish: () => {
                refreshingSchedule.value = false;
                dirtyRowIds.value = new Set();
                autoSummaryVisible.value = false;
                autoSummary.value = null;
                schedulePolling.acceptVersion(props.scheduleVersion);
                schedulePolling.checkNow();
                toast.add({ severity: 'info', summary: 'Refreshed', detail: 'Showing the latest schedule.', life: 3500 });
            },
        });
    };

    if (!hasUnsavedWork) {
        doRefresh();
        return;
    }

    Swal.fire({
        icon: 'warning',
        title: 'Refresh Schedule?',
        text: 'Refreshing will discard your unsaved changes. Continue?',
        showCancelButton: true,
        confirmButtonText: 'Refresh',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((result) => {
        if (result.isConfirmed) doRefresh();
    });
};

// Section Subjects page top-level tab — "Subjects" (the existing
// scheduling spreadsheet) vs "Room Grid" (spec: room-centric
// drag-and-drop view of the exact same SectionSubject rows).
const pageTab = ref('subjects');

// Room Grid writes go straight through updateSchedule() (immediate
// save, not staged like the spreadsheet's dirty-row batch), so the
// fresh row it returns is merged into `rows` the same way Faculty/
// Room override selectors already do above.
//
// BUG FIX — Room Grid drags kept triggering a false "Another user
// changed this schedule" on the very next save, even in a single-user
// session. Each successful drag DOES bump the Section's real
// schedule_version server-side, but this handler previously only
// updated the local `rows` array — it never told schedulePolling
// about the new version. So `schedulePolling.currentVersion` (sent
// back as `expected_schedule_version` on every subsequent write —
// see RoomGrid.vue's writeSchedule() and this page's saveSchedule())
// stayed frozen at whatever the page loaded with, while the DB kept
// moving forward with every drag. The very next write of ANY kind —
// another drag, or clicking Save Schedule on the Subjects tab — then
// failed ScheduleConflictService::checkSectionVersion()'s check
// against a version that was already stale, purely because of this
// page's own earlier successful saves, not another user's. Now that
// RoomGrid.vue emits the fresh schedule_version alongside the row,
// accepting it here keeps this page's baseline correct after every
// single drag, the same way saveSchedule()/autoGenerate()/etc. below
// already do for their own writes.
const onRoomGridRowUpdated = (fresh, scheduleVersion) => {
    if (fresh) {
        const row = rows.value.find((r) => r.id === fresh.id);
        if (row) {
            Object.assign(row, { ...fresh, days: toDaysArray(fresh.days) });
        }
    }
    if (typeof scheduleVersion === 'number') {
        schedulePolling.acceptVersion(scheduleVersion);
    }
};

// Room Grid's own writeSchedule() already handles a stale drag-drop
// save (409 SCHEDULE_VERSION_CONFLICT) locally — this just makes sure
// that also flips the page-wide stale banner + disables Save
// Schedule, since a conflict discovered from the Room Grid tab means
// the Subjects tab's data is equally out of date.
const onScheduleStaleFromRoomGrid = (currentVersion) => {
    if (typeof currentVersion === 'number') {
        schedulePolling.backendVersion.value = currentVersion;
    }
    schedulePolling.isStale.value = true;
};

const dirtyRowIds = ref(new Set());
const hasUnsavedChanges = computed(() => dirtyRowIds.value.size > 0);

// "Just Saved" — hides the "Clear Schedule" button right after a full
// Save Schedule succeeds, so there's nothing destructive sitting next
// to a schedule the Registrar just confirmed is correct. It comes
// back the moment they touch anything again (markDirty below), since
// at that point there's unsaved work a Clear could legitimately wipe.
// "Just Saved" — hides the "Clear Schedule" button once there's
// nothing dirty/pending left to clear. Unlike a plain ref, this is a
// COMPUTED derived from the actual row state (dirtyRowIds,
// hasAutoGeneratedRows), not a one-off flag toggled by the save
// handler — so it naturally survives a page refresh: freshly-loaded
// rows from the server start with no dirty edits and no pending
// Auto Generate suggestions, so this computes to "just saved" again
// on load too, instead of resetting to false and popping the button
// back up. It automatically flips back the moment the Registrar
// edits anything (markDirty) or a new Auto Generate run produces
// pending rows.
const justSaved = computed(() => !hasUnsavedChanges.value && !hasAutoGeneratedRows.value);

const markDirty = (row, field) => {
    dirtyRowIds.value.add(row.id);
    delete stateFor(row.id).errors[field];
};

const onFacultyChange = (row, value) => {
    if (value === '__loading__') return;
    row.faculty_id = value;
    markDirty(row, 'faculty_id');
    // A new Faculty means any previous Teaching Load Limit
    // confirmation no longer applies — must be re-confirmed.
    stateFor(row.id).workloadConfirmed = false;
    // A new Faculty means any previous Faculty Mismatch confirmation
    // no longer applies — the new Faculty may or may not be
    // qualified/from-college, so it must be re-evaluated fresh.
    stateFor(row.id).facultyMismatchConfirmed = false;
    fetchBusyTimes(row);
};
const onRoomChange = (row, value) => {
    if (value === '__loading__') return;
    row.room_id = value;
    markDirty(row, 'room_id');
    // A new Room means any previous Capacity Warning / Room Type
    // Mismatch confirmation no longer applies — both must be
    // re-confirmed against the new Room.
    stateFor(row.id).capacityConfirmed = false;
    stateFor(row.id).roomTypeConfirmed = false;
    stateFor(row.id).roomCollegeConfirmed = false;
    // BUG FIX — same reasoning as onDaysChange's row.hours_confirmed
    // reset below: row.room_type_confirmed/room_college_confirmed are
    // the PERSISTED flags loaded from the server (true if a prior save
    // confirmed a mismatch against the OLD Room). Only resetting the
    // local session flags above left these stale, so tableConflicts()'s
    // guard would keep treating the row as still-confirmed against a
    // Room that no longer applies.
    row.room_type_confirmed = false;
    row.room_college_confirmed = false;
    fetchBusyTimes(row);
};
const onDaysChange = (row, value) => {
    row.days = value;
    markDirty(row, 'days');
    autoFillEndTime(row);
    clampEndTimeToMax(row);
    fetchBusyTimes(row);
    stateFor(row.id).hoursConfirmed = false;
    // BUG FIX — row.hours_confirmed is the PERSISTED flag loaded from
    // the server (true if a prior save confirmed an Hours Mismatch at
    // the OLD Days/Time). Only resetting the local session flag above
    // left this stale, so tableConflicts()'s guard
    // (`!stateFor(a.id).hoursConfirmed && !a.hours_confirmed`) kept
    // treating an edited row as still-confirmed against hours that no
    // longer apply — the Weekly Hours Mismatch warning silently never
    // fired, and Save Schedule would fail server-side with no visible
    // explanation. Must invalidate both flags together whenever Days
    // or Time changes.
    row.hours_confirmed = false;
};
const onStartTimeChange = (row, value) => {
    row.start_time = value;
    markDirty(row, 'start_time');
    autoFillEndTime(row);
    clampEndTimeToMax(row);
    stateFor(row.id).hoursConfirmed = false;
    row.hours_confirmed = false; // see onDaysChange's comment above
};
const onEndTimeChange = (row, value) => {
    row.end_time = value;
    markDirty(row, 'end_time');
    clampEndTimeToMax(row);
    stateFor(row.id).hoursConfirmed = false;
    row.hours_confirmed = false; // see onDaysChange's comment above
};

/* --- Save Schedule — validates every row client-side, then saves        */
/* every row in a single request/transaction on the server. Nothing is    */
/* saved unless every row passes. --- */

const savingSchedule = ref(false);
// Whether the CURRENT user can Override & Save a Teaching Load
// warning (FacultyPolicy::changeMaxLoad() — Admin, Registrar, Dean,
// OIC, Assistant Dean), as told by the last save response's
// can_override flag. Starts false so the confirm affordance below
// never renders before the first save attempt reports it.
const workloadCanOverride = ref(false);

// Same permission RoomGrid.vue's own "Add Units" shortcut gates on
// (FacultyPolicy::changeMaxLoad()) — Admin/Registrar/Dean/OIC/Assistant
// Dean get a direct write to the ceiling via the same auto-approved
// endpoint; everyone else sees "Request more units" and it goes to
// review instead. Mirrors RoomGrid's canChangeMaxLoad exactly so the
// two "Add Units" entry points (Room Grid modal vs this Subjects
// table) behave identically for the same user.
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

// Per-row cache of the last workload_warnings payload for that row
// (see batchUpdateSchedule()'s $workloadWarnings / workloadWarningFor()
// on the backend) — stateFor(id).errors.workload only keeps the
// human-readable message, but the inline "Add Units" shortcut below
// needs the actual faculty_id/current/max figures to prefill its
// dialog, so they're kept here keyed by row id alongside the error.
const workloadWarningDetails = reactive({});

// "X units left (Y/Z)" label + color, and the "0 (or negative) units
// left" gate — identical logic to RoomGrid.vue's own
// remainingUnitsLabel()/remainingUnitsClass()/hasInsufficientUnits(),
// duplicated here (rather than imported) because these read the
// facultyGroupsFor() option shape (`maxUnits`/`currentLoad`), not
// RoomGrid's own (`max_teaching_units`/`current_load`) — same numbers,
// different option objects.
const remainingUnitsLabel = (option) => {
    const max = option.maxUnits;
    const load = option.currentLoad ?? 0;
    if (max == null) return '';
    const remaining = max - load;
    return `${remaining} unit${remaining === 1 ? '' : 's'} left (${load}/${max})`;
};

const remainingUnitsClass = (option) => {
    const max = option.maxUnits;
    if (max == null) return 'text-slate-400';
    const remaining = max - (option.currentLoad ?? 0);
    if (remaining <= 0) return 'text-red-500 font-medium';
    if (remaining <= 3) return 'text-amber-600';
    return 'text-slate-400';
};

const hasInsufficientUnits = (option) => {
    if (option.maxUnits == null) return false;
    return option.maxUnits - (option.currentLoad ?? 0) <= 0;
};

// Inline "Add Units" / "Request more units" shortcut — the Subjects
// tab's equivalent of RoomGrid's openLoadRequestDialog(), reused from
// two places: (1) the Faculty dropdown's own option row, the same
// on-ramp Room Grid's "Schedule Subject" modal offers right where a
// Dean/OIC discovers a faculty member is already full (Image 2), and
// (2) a saved row's Teaching Load warning banner, for a faculty
// already assigned before their ceiling got tight. Same endpoint,
// same approval rules, same auto-approve-vs-pending split as
// RoomGrid — this only differs in WHERE it can be triggered from and
// which local caches it patches afterward.
//
// facultyId/facultyName/currentMax/currentLoad describe the faculty
// member directly; rowId is optional and only supplied by the warning
// banner path, so its cached workloadWarningDetails/errors.workload
// can be cleared once the ceiling is raised.
const openAddUnitsDialog = async ({ facultyId, facultyName, currentMax, currentLoad, rowId = null }) => {
    if (!facultyId) return;

    // Same floor as RoomGrid's openLoadRequestDialog(): never offer a
    // default that would still leave "units left" negative, even if
    // this faculty is already over their own ceiling.
    const minRequestable = Math.max((currentMax ?? 0) + 1, currentLoad ?? 0);

    if (minRequestable > props.hardCapUnits) {
        toast.add({
            severity: 'error',
            summary: 'Cannot request',
            detail: `${facultyName ?? 'This faculty member'}'s current teaching load (${currentLoad ?? 0}) already exceeds the institution-wide ceiling (${props.hardCapUnits} units). Raise the ceiling under Settings > Faculty & Workload, or reduce their assigned load, before requesting a change here.`,
            life: 8000,
        });
        return;
    }

    const defaultRequested = Math.min(minRequestable, props.hardCapUnits);

    const { value: formValues } = await Swal.fire({
        icon: 'question',
        title: canChangeMaxLoad.value ? 'Add Units' : 'Request More Units',
        html: `
            <div class="text-left text-sm space-y-3">
                <div class="text-slate-500">
                    ${facultyName ?? 'This faculty member'}'s current ceiling is ${currentMax ?? 0} unit(s)${(currentLoad ?? 0) > (currentMax ?? 0) ? `, but they're already assigned ${currentLoad} unit(s) of actual teaching load` : ''}.
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Requested Max Teaching Units</label>
                    <input id="swal-load-units-row" type="number" class="swal2-input !m-0 !w-full" min="${minRequestable}" max="${props.hardCapUnits}" value="${defaultRequested}" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Reason</label>
                    <textarea id="swal-load-reason-row" class="swal2-textarea !m-0 !w-full" rows="3" placeholder="Why does this faculty member need a higher teaching load? (min. 10 characters)"></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: canChangeMaxLoad.value ? 'Add Units' : 'Submit Request',
        cancelButtonText: 'Cancel',
        focusConfirm: false,
        preConfirm: () => {
            const units = Number(document.getElementById('swal-load-units-row')?.value);
            const reason = (document.getElementById('swal-load-reason-row')?.value ?? '').trim();

            if (!units || units < minRequestable) {
                Swal.showValidationMessage(
                    (currentLoad ?? 0) > (currentMax ?? 0)
                        ? `Requested units must be at least ${minRequestable} — below this faculty member's actual current teaching load (${currentLoad}), the units-left count would still show negative.`
                        : `Requested units must be higher than the current maximum (${currentMax ?? 0}).`,
                );
                return false;
            }
            if (units > props.hardCapUnits) {
                Swal.showValidationMessage(`Requests above ${props.hardCapUnits} units cannot be submitted — that is the current maximum teaching load ceiling.`);
                return false;
            }
            if (reason.length < 10) {
                Swal.showValidationMessage('Please give a bit more detail — a one-word reason isn\'t enough for the reviewer to approve this.');
                return false;
            }
            return { units, reason };
        },
    });

    if (!formValues) return;

    try {
        const response = await fetch(route('scheduling.faculty-load-requests.store'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                faculty_id: facultyId,
                requested_max_teaching_units: formValues.units,
                reason: formValues.reason,
            }),
        });
        const data = await response.json();

        if (!response.ok) {
            const detail = data.errors
                ? Object.values(data.errors).flat().join(' ')
                : (data.message ?? 'Could not submit the request.');
            toast.add({ severity: 'error', summary: 'Could not submit', detail, life: 7000 });
            return;
        }

        if (data.auto_approved) {
            // Same in-place patch RoomGrid does — activeFaculty is the
            // same reactive prop this page passed to RoomGrid, so
            // every "units left" label fed by it (including this
            // page's own Faculty dropdown, mid-open) updates
            // immediately, with no full page reload.
            const target = props.activeFaculty.find((f) => f.id === facultyId);
            if (target) {
                if (data.max_teaching_units != null) target.max_teaching_units = data.max_teaching_units;
                if (data.max_weekly_hours != null) target.max_weekly_hours = data.max_weekly_hours;
            }
            Object.values(recommendations).forEach((entry) => {
                const recMatch = entry?.faculty?.recommendations?.find((r) => r.id === facultyId);
                if (recMatch && data.max_teaching_units != null) recMatch.max_teaching_units = data.max_teaching_units;
            });

            // If this came from a saved row's warning banner, the
            // ceiling is now high enough for that row's existing
            // assignment, so the stale warning (and its cached
            // details) can clear — re-saving will pass on its own,
            // with no Override needed.
            if (rowId != null) {
                delete stateFor(rowId).errors.workload;
                delete workloadWarningDetails[rowId];
            }

            toast.add({ severity: 'success', summary: 'Units added', detail: data.message ?? 'Faculty load ceiling updated.', life: 5000 });
            return;
        }

        toast.add({
            severity: 'warn',
            summary: 'Pending approval',
            detail: `${data.message ?? 'Load change request submitted for review.'} This faculty member still can't be assigned until it's approved.`,
            life: 8000,
        });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not submit the request.', life: 5000 });
    }
};

// Row warning banner's "Add Units" — pulls the faculty_id/current/max
// cached from the last save's workload_warnings for this row (see
// workloadWarningDetails above) and threads them into the shared
// dialog, preferring activeFaculty's live numbers when available in
// case they've since moved (e.g. another row's Add Units already
// raised this same faculty's ceiling).
const openAddUnitsForRow = (rowId) => {
    const warning = workloadWarningDetails[rowId];
    if (!warning?.faculty_id) return;
    const liveFaculty = props.activeFaculty.find((f) => f.id === warning.faculty_id);
    openAddUnitsDialog({
        facultyId: warning.faculty_id,
        facultyName: warning.faculty_name,
        currentMax: liveFaculty?.max_teaching_units ?? warning.max ?? 0,
        currentLoad: liveFaculty?.current_load ?? warning.current ?? 0,
        rowId,
    });
};

// Faculty dropdown's own "Add Units" — reachable straight from a full
// faculty's option row (see the #option template below), same as
// RoomGrid's "Schedule Subject" modal already offers.
const openAddUnitsForOption = (option) => {
    openAddUnitsDialog({
        facultyId: option.value,
        facultyName: option.label,
        currentMax: option.maxUnits,
        currentLoad: option.currentLoad,
    });
};

const validateRowsClientSide = () => {
    let valid = true;

    rows.value.forEach((row) => {
        const state = stateFor(row.id);
        state.errors = {};

        if (row.start_time && row.end_time && row.start_time >= row.end_time) {
            state.errors.end_time = 'End Time must be later than Start Time.';
            valid = false;
        }

        // A schedule slot must be fully filled in, or fully empty — no
        // half-assigned rows (e.g. Faculty picked but no Room/Days/Time).
        const filledCount = [row.faculty_id, row.room_id, row.days?.length > 0, row.start_time, row.end_time].filter(
            Boolean,
        ).length;

        if (filledCount > 0 && filledCount < 5) {
            if (!row.faculty_id) state.errors.faculty_id = 'Required to complete this schedule.';
            if (!row.room_id) state.errors.room_id = 'Required to complete this schedule.';
            if (!row.days || row.days.length === 0) state.errors.days = 'Required to complete this schedule.';
            if (!row.start_time) state.errors.start_time = 'Required to complete this schedule.';
            if (!row.end_time) state.errors.end_time = 'Required to complete this schedule.';
            valid = false;
        }
    });

    return valid;
};

// Opens the same server-rendered, branded printable page the Reports
// page's own Print button uses (ReportsController::print() /
// resources/views/reports/print.blade.php) — scoped to just this
// Section via the "Schedule by Section" report type, so it always
// reflects whatever's actually saved for this section rather than a
// screenshot of this editing screen.
const printSectionSchedule = () => {
    window.open(
        route('reports.print', {
            report_type: 'schedule_by_section',
            section_id: props.section.id,
            academic_year: props.section.academic_year,
            semester: props.section.semester,
        }),
        '_blank',
    );
};

// WORKLOAD OVERRIDE — Subjects tab equivalent of the Room Grid's
// "Proceed Anyway" (see RoomGrid.vue's writeSchedule()). Unlike
// Capacity/Hours/Room Type/Room College/Faculty Mismatch above,
// this warning can only be known AFTER the server rejects a save
// (it depends on the Faculty's actual current load across every
// OTHER section too, not just what's visible in this table), so it
// can't be pre-checked client-side the way those are — the flow is
// reactive: attempt Save, server skips the row and reports it via
// workload_warnings, THEN the Dean/Registrar/Admin/OIC/Assistant
// Dean confirms it right here and retries. Previously this warning
// text had no action at all — the row would just keep getting
// silently skipped on every subsequent Save attempt with no way out
// except deleting/reassigning the row, even for a user who has
// every right to override it.
const confirmWorkloadOverride = (rowId) => {
    stateFor(rowId).workloadConfirmed = true;
    stateFor(rowId).errors.workload = null;
    dirtyRowIds.value.add(rowId);

    return saveSchedule();
};

const saveSchedule = async () => {
    if (rows.value.length === 0) {
        return;
    }

    // BLOCK STALE SAVES EARLY — the poller already told us the
    // backend moved past what this page loaded. This is only an
    // early warning (the transaction + version check below is still
    // the real guard), but there's no point letting the Registrar
    // fill out a Swal confirmation dialog for a save the server is
    // about to reject with 409 anyway — send them to Refresh first.
    if (schedulePolling.isStale.value) {
        toast.add({
            severity: 'warn',
            summary: 'Schedule changed',
            detail: 'Another user changed this schedule. Please refresh before saving.',
            life: 6000,
        });
        return;
    }

    if (!validateRowsClientSide()) {
        toast.add({
            severity: 'warn',
            summary: 'Fix the highlighted rows',
            detail: 'Each schedule needs Faculty, Room, Days, Start Time, and End Time filled in together — or all left empty.',
            life: 6000,
        });
        return;
    }

    // Faculty/Room/Section conflicts are hard blocks — never savable.
    // The Save button is already disabled in this state, but this is
    // the authoritative reject in case it's reached another way.
    if (blockingConflictRowIds.value.size > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Cannot Save Schedule',
            text: 'Cannot save because scheduling conflicts were detected. Resolve the Scheduling Issues listed above and try again.',
            confirmButtonColor: '#dc2626',
        });
        return;
    }

    // Room Capacity Warnings can be saved, but only once the Registrar
    // explicitly confirms each one.
    const stillUnconfirmed = unconfirmedCapacityRowIds.value;
    if (stillUnconfirmed.length > 0) {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Room Capacity Warning',
            html: tableConflicts.value
                .filter((c) => c.type === 'capacity' && stillUnconfirmed.includes(c.rowIds[0]))
                .map((c) => `<div class="text-left text-sm">${c.detail}</div>`)
                .join(''),
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
        });

        if (!result.isConfirmed) {
            return;
        }

        stillUnconfirmed.forEach((rowId) => {
            stateFor(rowId).capacityConfirmed = true;
        });
    }

    // Weekly Hours Mismatch — also confirmable, not a hard block.
    // Intentional trims/extensions (e.g. copied from a sibling
    // section's manually-adjusted duration) are allowed; this just
    // makes sure the Registrar sees it before it's saved instead of
    // being blocked by the server with no visible reason.
    const stillUnconfirmedHours = unconfirmedHoursRowIds.value;
    if (stillUnconfirmedHours.length > 0) {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Weekly Hours Mismatch',
            html: tableConflicts.value
                .filter((c) => c.type === 'hours' && stillUnconfirmedHours.includes(c.rowIds[0]))
                .map((c) => `<div class="text-left text-sm">${c.detail}</div>`)
                .join(''),
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
        });

        if (!result.isConfirmed) {
            return;
        }

        stillUnconfirmedHours.forEach((rowId) => {
            stateFor(rowId).hoursConfirmed = true;
        });
    }

    // Room Type Mismatch — also confirmable, not a hard block. Some
    // rooms legitimately double up as both Lecture and Laboratory
    // space; this just makes sure the Registrar explicitly sees and
    // accepts the mismatch before it's saved.
    const stillUnconfirmedRoomType = unconfirmedRoomTypeRowIds.value;
    if (stillUnconfirmedRoomType.length > 0) {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Room Type Mismatch',
            html: tableConflicts.value
                .filter((c) => c.type === 'roomType' && stillUnconfirmedRoomType.includes(c.rowIds[0]))
                .map((c) => `<div class="text-left text-sm">${c.detail}</div>`)
                .join(''),
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
        });

        if (!result.isConfirmed) {
            return;
        }

        stillUnconfirmedRoomType.forEach((rowId) => {
            stateFor(rowId).roomTypeConfirmed = true;
        });
    }

    // Room College Mismatch — also confirmable, not a hard block. A
    // Minor/GenEd subject legitimately borrowing another College's
    // room, or a deliberate cross-college override, is allowed; this
    // just makes sure the Registrar explicitly sees and accepts it
    // before it's saved. Same pattern as Room Type Mismatch above.
    const stillUnconfirmedRoomCollege = unconfirmedRoomCollegeRowIds.value;
    if (stillUnconfirmedRoomCollege.length > 0) {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Room Belongs to Another College',
            html: tableConflicts.value
                .filter((c) => c.type === 'roomCollege' && stillUnconfirmedRoomCollege.includes(c.rowIds[0]))
                .map((c) => `<div class="text-left text-sm">${c.detail}</div>`)
                .join(''),
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
        });

        if (!result.isConfirmed) {
            return;
        }

        stillUnconfirmedRoomCollege.forEach((rowId) => {
            stateFor(rowId).roomCollegeConfirmed = true;
        });
    }

    // Faculty Mismatch — also confirmable, not a hard block. Manual
    // Override is a deliberately supported path (see the "Other
    // Active Faculty (Manual Override)" group in the Faculty picker);
    // this just makes sure the Registrar explicitly sees and accepts
    // it before it's saved. Same pattern as Room Type/College
    // Mismatch above.
    const stillUnconfirmedFacultyMismatch = unconfirmedFacultyMismatchRowIds.value;
    if (stillUnconfirmedFacultyMismatch.length > 0) {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Faculty Mismatch',
            html: tableConflicts.value
                .filter((c) => c.type === 'facultyMismatch' && stillUnconfirmedFacultyMismatch.includes(c.rowIds[0]))
                .map((c) => `<div class="text-left text-sm">${c.detail}</div>`)
                .join(''),
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
        });

        if (!result.isConfirmed) {
            return;
        }

        stillUnconfirmedFacultyMismatch.forEach((rowId) => {
            stateFor(rowId).facultyMismatchConfirmed = true;
        });
    }

    savingSchedule.value = true;

    const buildPayload = () =>
        rows.value.map((row) => ({
            id: row.id,
            faculty_id: row.faculty_id || null,
            room_id: row.room_id || null,
            days: row.days ?? [],
            start_time: row.start_time || null,
            end_time: row.end_time || null,
            capacity: row.capacity || null,
            capacity_confirmed: Boolean(stateFor(row.id).capacityConfirmed),
            workload_confirmed: Boolean(stateFor(row.id).workloadConfirmed),
            hours_confirmed: Boolean(stateFor(row.id).hoursConfirmed),
            room_type_confirmed: Boolean(stateFor(row.id).roomTypeConfirmed),
            room_college_confirmed: Boolean(stateFor(row.id).roomCollegeConfirmed),
            faculty_mismatch_confirmed: Boolean(stateFor(row.id).facultyMismatchConfirmed),
        }));

    const submit = async () => {
        const response = await fetch(route('scheduling.section-subjects.schedule.batch', props.section.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            // CONCURRENCY HARDENING — the version this page last knew
            // about. The backend re-checks this itself, under a row
            // lock, inside the save transaction (the real guard); this
            // is just what makes that check possible.
            body: JSON.stringify({ rows: buildPayload(), expected_schedule_version: schedulePolling.currentVersion.value }),
        });

        return { response, data: await response.json() };
    };

    try {
        let { response, data } = await submit();

        // SAVE CONFLICT RESPONSE (spec Section 13) — another user's
        // save committed and bumped schedule_version after this page
        // loaded (or since the last successful save/refresh). Nothing
        // was written server-side (the whole transaction rolled
        // back). Keep the Registrar's current UI/edits exactly as-is,
        // mark the page stale so Save is blocked until they refresh,
        // and surface it clearly instead of a generic error toast.
        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            if (typeof data.current_version === 'number') {
                schedulePolling.backendVersion.value = data.current_version;
            }
            schedulePolling.isStale.value = true;

            toast.add({
                severity: 'error',
                summary: 'Save prevented',
                detail: 'Another user changed this schedule while you were editing. Refresh to see the latest version, then re-apply your changes.',
                life: 8000,
            });
            savingSchedule.value = false;
            return;
        }

        // FACULTY WORKLOAD VALIDATION — "Save Schedule Validation"
        // (SectionSubjectController::workloadWarningFor()). A row
        // whose Faculty would exceed their Teaching Load ceiling is
        // skipped from the write, but batchUpdateSchedule() still
        // returns 200 (partial save) whenever at least one OTHER row
        // saved fine, or 422 only if EVERY row in the batch was
        // skipped — never 409. Show the same "Conflict / Proceed
        // Anyway" modal Room Grid's own writeSchedule() pops for the
        // exact same warning, right here, instead of only surfacing a
        // "Partially saved" toast + inline row banner with no
        // immediate way to actually finish saving those rows.
        if (data.workload_warnings && Object.keys(data.workload_warnings).length > 0) {
            const warnings = Object.values(data.workload_warnings);
            const canOverride = Boolean(data.can_override);

            const result = await Swal.fire({
                icon: canOverride ? 'warning' : 'error',
                title: 'Conflict',
                html:
                    '<div class="text-left text-sm space-y-2">'
                    + warnings.map((w) => `<div>${w.message ?? `${w.faculty_name} would exceed their allowable teaching load.`}</div>`).join('')
                    + (canOverride
                        ? ''
                        : '<p class="mt-2 text-red-600">Only an Administrator, Registrar, Dean, OIC, or Assistant Dean may override this.</p>')
                    + '</div>',
                showCancelButton: canOverride,
                confirmButtonText: canOverride ? 'Proceed Anyway' : 'OK',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
            });

            if (canOverride && result.isConfirmed) {
                // BUG FIX — the first submit() above already committed
                // a PARTIAL save (every row without a conflict) and
                // bumped the Section's schedule_version server-side
                // (batchUpdateSchedule() bumps the version whenever
                // ANY row changed, not just on a full success). A
                // naive retry here would still send the STALE version
                // this page loaded with, so the backend's own
                // concurrency guard would reject the retry outright
                // as a 409 SCHEDULE_VERSION_CONFLICT — silently
                // undoing "Proceed Anyway" with no visible save and
                // no further error, which is exactly the bug this
                // fixes. Adopt the version the first submit just
                // produced, and resync every row's fields from what
                // actually landed in the database, BEFORE resubmitting
                // — same two steps the full success path below
                // performs anyway, just done early so the retry's own
                // expected_schedule_version and payload are both
                // already up to date.
                if (typeof data.schedule_version === 'number') {
                    schedulePolling.acceptVersion(data.schedule_version);
                }
                rows.value.forEach((row) => {
                    const fresh = data.sectionSubjects?.find((r) => r.id === row.id);
                    if (fresh) {
                        Object.assign(row, { ...fresh, days: toDaysArray(fresh.days) });
                    }
                });

                // Same override flag confirmWorkloadOverride()/the row
                // banner's "Confirm & Save Anyway" already sets — mark
                // every warned row confirmed and resubmit the WHOLE
                // batch once more so this save actually finishes in
                // one action, the same way clicking "Proceed Anyway"
                // on Room Grid immediately re-writes the assignment
                // rather than leaving the Registrar to press Save a
                // second time.
                Object.keys(data.workload_warnings).forEach((rowId) => {
                    stateFor(Number(rowId)).workloadConfirmed = true;
                });

                ({ response, data } = await submit());
            }
            // Not overridable, or the Registrar cancelled — fall
            // through to the normal partial-save handling below,
            // which still highlights these exact rows via
            // data.workload_warnings/errors.workload so nothing is
            // silently lost, just not auto-retried.
        }

        if (response.status === 409 && data.workload_warnings) {
            const warnings = Object.values(data.workload_warnings);
            const canOverride = Boolean(data.can_override);

            const result = await Swal.fire({
                icon: 'warning',
                title: '⚠ Teaching Load Limit Exceeded',
                html:
                    '<div class="text-left text-sm space-y-2">' +
                    warnings
                        .map(
                            (w) =>
                                `<div><strong>${w.faculty_name}</strong><br/>Current: ${w.current} / ${w.max} ${w.unit_label} &rarr; assigning ${w.subject_code} would bring it to ${w.projected} / ${w.max} ${w.unit_label}.</div>`,
                        )
                        .join('') +
                    (canOverride
                        ? '<p class="mt-2">Please resolve these conflicts or override manually.</p>'
                        : '<p class="mt-2 text-red-600">Only an Administrator, Registrar, Dean, OIC, or Assistant Dean may override this validation.</p>') +
                    '</div>',
                showCancelButton: canOverride,
                confirmButtonText: canOverride ? 'Override & Save' : 'OK',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
            });

            if (!canOverride || !result.isConfirmed) {
                savingSchedule.value = false;
                return;
            }

            Object.keys(data.workload_warnings).forEach((rowId) => {
                stateFor(Number(rowId)).workloadConfirmed = true;
            });

            ({ response, data } = await submit());
        }

        if (!response.ok) {
            // Errors are keyed by SectionSubject id — map each back to
            // its row so the table highlights exactly what to fix.
            if (data.errors) {
                Object.entries(data.errors).forEach(([rowId, fieldErrors]) => {
                    Object.assign(stateFor(Number(rowId)).errors, fieldErrors);
                });
            }

            toast.add({
                severity: 'error',
                summary: 'Nothing saved',
                detail: data.message ?? 'Some rows have validation errors.',
                life: 7000,
            });
            return;
        }

        rows.value.forEach((row) => {
            const fresh = data.sectionSubjects?.find((r) => r.id === row.id);
            if (fresh) {
                Object.assign(row, { ...fresh, days: toDaysArray(fresh.days) });
            }
        });

        // PARTIAL SAVE — the request still succeeded (200): every row
        // WITHOUT a conflict was written. Rows that DID have an overlap
        // (data.errors) were skipped and never touched in the database,
        // so re-highlight exactly those rows instead of clearing every
        // error, and keep them dirty since the user's edits for those
        // specific rows still haven't been saved.
        const skippedIds = new Set((data.skipped_ids ?? []).map(Number));

        rows.value.forEach((row) => {
            const rowState = stateFor(row.id);
            rowState.errors = {};
        });

        if (data.errors) {
            Object.entries(data.errors).forEach(([rowId, fieldErrors]) => {
                Object.assign(stateFor(Number(rowId)).errors, fieldErrors);
            });
        }

        // A row can also be skipped because its Faculty's Teaching Load
        // warning was never confirmed (Administrator-only "Override &
        // Save") — the backend tracks that separately from data.errors
        // (see batchUpdateSchedule()'s $workloadWarnings), so without
        // this it's counted in the "N subjects were skipped" toast but
        // never actually highlighted on the table, leaving no visible
        // sign of which rows need attention.
        if (data.workload_warnings) {
            workloadCanOverride.value = Boolean(data.can_override);

            Object.entries(data.workload_warnings).forEach(([rowId, warning]) => {
                stateFor(Number(rowId)).errors.workload = warning.message
                    ?? `${warning.faculty_name} would exceed their allowable teaching load. Confirm to save anyway.`;
                // Cached for openAddUnitsForRow()'s inline "Add Units"
                // shortcut — see its declaration above.
                workloadWarningDetails[Number(rowId)] = warning;
            });
        }

        dirtyRowIds.value = new Set([...dirtyRowIds.value].filter((id) => skippedIds.has(id)));

        // SAVE SUCCESS — the backend incremented schedule_version by
        // exactly 1 as part of this same transaction; adopt it as the
        // new baseline so the next poll (and the next save) compares
        // against it, not the version this page loaded with.
        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        // AUTO-RAISE CEILING — same fix as RoomGrid.vue's
        // writeSchedule(): when Override & Save just raised one or
        // more Faculty's max_teaching_units server-side (see
        // batchUpdateSchedule()'s updated_faculty_loads), patch this
        // page's own activeFaculty in place — the same array RoomGrid
        // is handed via the :active-faculty prop — so every "units
        // left" / "Current Load: X / Y Units" label fed by it
        // (recommendation dropdowns included) reflects the new
        // ceiling immediately, with no full page reload.
        (data.updated_faculty_loads ?? []).forEach((updated) => {
            const target = props.activeFaculty.find((f) => f.id === updated.id);
            if (target) {
                target.max_teaching_units = updated.max_teaching_units;
                target.current_load = updated.current_load;
            }

            // The Faculty dropdown's own "RECOMMENDED" section reads
            // current_load/max_teaching_units off this per-row cached
            // recs list (recommendations[row.id].faculty.recommendations
            // — see facultyGroupsFor()), NOT off activeFaculty above, so
            // patching activeFaculty alone would leave a recommended
            // pick's badge showing the old ceiling until its dropdown
            // happened to refetch. Walk every row's cached recs (across
            // every subject row on this page, not just the one just
            // saved — the same Faculty member can be recommended for
            // more than one row) and patch the matching entry too.
            Object.values(recommendations).forEach((entry) => {
                const recMatch = entry?.faculty?.recommendations?.find((r) => r.id === updated.id);
                if (recMatch) {
                    recMatch.max_teaching_units = updated.max_teaching_units;
                    recMatch.current_load = updated.current_load;
                }
            });
        });

        if (skippedIds.size > 0) {
            toast.add({
                severity: 'warn',
                summary: 'Partially saved',
                detail: data.message ?? `${skippedIds.size} subject(s) were skipped due to a scheduling conflict.`,
                life: 7000,
            });
        } else {
            // A clean save (nothing skipped) naturally clears
            // dirtyRowIds below, which is what the `justSaved`
            // computed above actually keys off of — no manual flag
            // needed here anymore.
            await Swal.fire({
                icon: 'success',
                title: 'Schedule Saved',
                text: data.message ?? 'The schedule was saved successfully.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#16a34a',
                timer: 4000,
                timerProgressBar: true,
            });
        }
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Could not save the schedule. Please check your connection and try again.',
            life: 6000,
        });
    } finally {
        savingSchedule.value = false;
    }
};

/* ------------------------------------------------------------------ */
/* ⚡ Auto Generate Schedule (Prompt 8.9)                               */
/*                                                                       */
/* Server-side, AutoScheduleService walks every currently unscheduled   */
/* subject and writes the best conflict-free Faculty/Room/Time it can   */
/* find (Status stays 'Draft', is_auto_generated = true) — it never     */
/* touches a row that already has an assignment. This panel just shows  */
/* the result and lets the Registrar Accept (Save), Regenerate, or      */
/* Clear it before anything is finalized.                               */
/* ------------------------------------------------------------------ */

const autoGenerating = ref(false);

// ⚡ Simulated progress for the Auto Generate Schedule button.
// The backend runs auto-scheduling as a single request/response — it does
// not report real incremental progress — so this is a COSMETIC approximation
// only, purely to give the user visual feedback that something is happening.
// It never blocks, delays, or alters the actual fetch/response below; it
// just drives the number shown in the button label while the real request
// is in flight, and always resolves to 100% the instant the real response
// arrives (success or failure).
const autoGenerateProgress = ref(0);
let autoGenerateProgressTimer = null;

const startAutoGenerateProgress = () => {
    autoGenerateProgress.value = 0;
    clearInterval(autoGenerateProgressTimer);
    autoGenerateProgressTimer = setInterval(() => {
        // Ease toward 90% and stall there — the real completion (fetch
        // resolving) is what pushes it to 100%, never this timer.
        const remaining = 90 - autoGenerateProgress.value;
        if (remaining <= 0) return;
        autoGenerateProgress.value = Math.min(90, autoGenerateProgress.value + Math.max(1, Math.round(remaining * 0.15)));
    }, 200);
};

const finishAutoGenerateProgress = () => {
    clearInterval(autoGenerateProgressTimer);
    autoGenerateProgressTimer = null;
    autoGenerateProgress.value = 100;
    // Let the user see 100% briefly instead of the label snapping away.
    setTimeout(() => { autoGenerateProgress.value = 0; }, 400);
};

const autoGenerateButtonLabel = computed(() =>
    autoGenerating.value ? `⚡ Generating… ${autoGenerateProgress.value}%` : '⚡ Auto Generate Schedule'
);
const autoClearing = ref(false);
const clearingSchedule = ref(false);
const autoSummaryVisible = ref(false);
const autoSummary = ref(null); // { total, scheduled, results, unresolved, message }

const applyFreshRows = (fresh) => {
    fresh.forEach((freshRow) => {
        const row = rows.value.find((r) => r.id === freshRow.id);
        if (row) {
            Object.assign(row, { ...freshRow, days: toDaysArray(freshRow.days) });
        }
    });
};

const hasAutoGeneratedRows = computed(() => rows.value.some((row) => row.is_auto_generated));

/* -------------------------------------------------------------- */
/* INTELLIGENT IRREGULAR SECTION SCHEDULING — "Merge Recommendation" */
/* modal. Opens from either a merged result ("Merge into BSIT-4A")   */
/* or an unresolved/independent one, always calling                  */
/* mergeRecommendation() fresh so the candidate list reflects        */
/* current data even if it was computed earlier during generate().   */
/* -------------------------------------------------------------- */

const isIrregularSection = computed(() => props.section.section_type === 'Irregular');

/* -------------------------------------------------------------- */
/* SECTION-LEVEL SCHEDULE FINALIZATION — the frontend mirror of    */
/* Section::isEditable() / ScheduleConflictService::lockResources().*/
/* This is a UX convenience only: the backend independently rejects */
/* every write for a finalized Section regardless of what this flag */
/* disables here (see SectionFinalizedException, which every write  */
/* handler below already catches and surfaces via toast). Switching */
/* sections via the header dropdown does a full router.visit(), so  */
/* props.section is always the section actually on screen — no need */
/* to track selectedSectionId separately here.                      */
/* -------------------------------------------------------------- */
const isSectionFinalized = computed(() => Boolean(props.section.is_finalized));
const mergeModalVisible = ref(false);
const mergeModalLoading = ref(false);
const mergeModalApplying = ref(false);
const mergeModalResult = ref(null); // the result/unresolved item the modal was opened for
const mergeModalRecommendation = ref(null); // { recommendation, best_match, candidates, independent_reason }

const openMergeModal = async (item) => {
    mergeModalResult.value = item;
    mergeModalRecommendation.value = item.merge_recommendation ?? null;
    mergeModalVisible.value = true;
    mergeModalLoading.value = true;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge-recommendation', [props.section.id, item.section_subject_id]),
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not load merge recommendation.');
        mergeModalRecommendation.value = data;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not load merge recommendation.', life: 6000 });
    } finally {
        mergeModalLoading.value = false;
    }
};

const chooseMergeCandidate = async (candidate) => {
    if (!mergeModalResult.value) return;

    mergeModalApplying.value = true;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge', [props.section.id, mergeModalResult.value.section_subject_id]),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ target_section_subject_id: candidate.section_subject_id }),
            },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply merge.');

        applyFreshRows(data.sectionSubjects ?? []);
        mergeModalVisible.value = false;
        toast.add({ severity: 'success', summary: 'Merged', detail: data.message, life: 5000 });

        // Refresh the panel's own copy of the result so the "Merge into
        // X" summary updates without needing to re-run Auto Generate.
        if (autoSummary.value) {
            const merged = data.sectionSubjects.find((r) => r.id === mergeModalResult.value.section_subject_id);
            if (merged) {
                mergeModalResult.value.is_merged = true;
                mergeModalResult.value.merged_into_section_code = candidate.section_code;
            }
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not apply merge.', life: 6000 });
    } finally {
        mergeModalApplying.value = false;
    }
};

const chooseIndependentSchedule = async () => {
    if (!mergeModalResult.value) return;

    mergeModalApplying.value = true;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.schedule-independently', [props.section.id, mergeModalResult.value.section_subject_id]),
            { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() } },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not switch to an independent schedule.');

        applyFreshRows(data.sectionSubjects ?? []);
        mergeModalVisible.value = false;
        toast.add({ severity: 'info', summary: 'Independent Schedule', detail: data.message, life: 5000 });

        // Refresh the panel's own copy of the result — same pattern as
        // chooseMergeCandidate() above — so the "Auto Schedule
        // Complete" card immediately switches from the "Merged into
        // X" summary to the normal editable Faculty/Room/Time card
        // (with the Merge Recommendation link still available below
        // it), instead of continuing to show a stale "Merged" tag
        // until the panel is reopened.
        if (autoSummary.value) {
            const freshRow = data.sectionSubjects?.find((r) => r.id === mergeModalResult.value.section_subject_id);
            if (freshRow) {
                mergeModalResult.value.is_merged = false;
                mergeModalResult.value.merged_into_section_code = null;
                mergeModalResult.value.pattern_source = null;
                mergeModalResult.value.faculty = freshRow.faculty
                    ? { id: freshRow.faculty.id, name: freshRow.faculty.full_name }
                    : null;
                mergeModalResult.value.room = freshRow.room
                    ? { id: freshRow.room.id, name: freshRow.room.room_name }
                    : null;
                mergeModalResult.value.time = {
                    days: toDaysArray(freshRow.days),
                    start_time: freshRow.start_time,
                    end_time: freshRow.end_time,
                };
            }
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not switch to an independent schedule.', life: 6000 });
    } finally {
        mergeModalApplying.value = false;
    }
};

const runAutoGenerate = async () => {
    if (rows.value.length === 0) return;

    autoGenerating.value = true;
    startAutoGenerateProgress();

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            // CONCURRENCY HARDENING — captured as `generated_from_version`
            // server-side; if another user's change already landed since
            // this page loaded, the backend rejects with 409 below
            // instead of silently generating against stale data.
            body: JSON.stringify({ expected_schedule_version: schedulePolling.currentVersion.value }),
        });
        const data = await response.json();

        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            if (typeof data.current_version === 'number') schedulePolling.backendVersion.value = data.current_version;
            schedulePolling.isStale.value = true;
            toast.add({
                severity: 'error',
                summary: 'Schedule changed',
                detail: 'Another user changed this schedule. Please refresh before running Auto Schedule.',
                life: 7000,
            });
            return;
        }

        if (!response.ok) throw new Error(data.message ?? 'Auto generate failed.');

        applyFreshRows(data.sectionSubjects ?? []);
        autoSummary.value = data;
        autoSummaryVisible.value = true;

        // This action itself just wrote to the database and advanced
        // schedule_version — adopt the new baseline immediately so the
        // next poll doesn't mistake our OWN write for someone else's
        // change and falsely lock out "Accept All & Save".
        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({
            severity: data.scheduled === data.total ? 'success' : 'warn',
            summary: 'Auto Schedule Complete',
            detail: data.message,
            life: 6000,
        });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not auto-generate the schedule.', life: 6000 });
    } finally {
        finishAutoGenerateProgress();
        autoGenerating.value = false;
    }
};

const regenerateAutoSchedule = async () => {
    autoGenerating.value = true;
    startAutoGenerateProgress();

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate.regenerate', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ expected_schedule_version: schedulePolling.currentVersion.value }),
        });
        const data = await response.json();

        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            if (typeof data.current_version === 'number') schedulePolling.backendVersion.value = data.current_version;
            schedulePolling.isStale.value = true;
            toast.add({
                severity: 'error',
                summary: 'Schedule changed',
                detail: 'Another user changed this schedule. Please refresh before regenerating.',
                life: 7000,
            });
            return;
        }

        if (!response.ok) throw new Error(data.message ?? 'Regenerate failed.');

        applyFreshRows(data.sectionSubjects ?? []);
        autoSummary.value = data;

        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'info', summary: 'Schedule Regenerated', detail: data.message, life: 6000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not regenerate the schedule.', life: 6000 });
    } finally {
        finishAutoGenerateProgress();
        autoGenerating.value = false;
    }
};

const clearAutoSchedule = async () => {
    autoClearing.value = true;

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate.clear', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Clear failed.');

        applyFreshRows(data.sectionSubjects ?? []);
        autoSummary.value = null;
        autoSummaryVisible.value = false;

        // "Clear" also writes (reverts rows to empty) and bumps the
        // version — same reasoning as runAutoGenerate() above.
        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'info', summary: 'Cleared', detail: data.message, life: 5000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not clear the auto-generated schedule.', life: 6000 });
    } finally {
        autoClearing.value = false;
    }
};

// "Clear Schedule" — wipes EVERY subject's Faculty/Room/Days/Time back
// to blank, including already-Saved/Scheduled rows (not just pending
// Auto Generate suggestions like clearAutoSchedule() above). This is
// destructive and cannot be undone, so it requires TWO separate
// confirmations before the request fires. Server also re-checks
// isSectionFinalized under lock — the :disabled on the button is just
// the first line of defense, not the real guard.
const clearWholeSchedule = async () => {
    if (isSectionFinalized.value) return;

    const first = await Swal.fire({
        icon: 'warning',
        title: 'Clear the entire schedule?',
        html: `This will remove the Faculty, Room, Day, and Time from <strong>all ${rows.value.length} subject(s)</strong> in this section — including ones already saved. This cannot be undone.`,
        showCancelButton: true,
        confirmButtonText: 'Continue',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    });
    if (!first.isConfirmed) return;

    const second = await Swal.fire({
        icon: 'error',
        title: 'Are you absolutely sure?',
        html: 'This is your last chance to back out. Every subject in <strong>' + (props.section.section_code ?? 'this section') + '</strong> will go back to blank.',
        showCancelButton: true,
        confirmButtonText: 'Yes, clear everything',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        focusCancel: true,
    });
    if (!second.isConfirmed) return;

    clearingSchedule.value = true;

    // Free up a connection slot immediately instead of queuing behind
    // whatever's left of the on-mount recommendation prefetch — see
    // cancelPendingRecommendationPrefetch() above for why this matters.
    cancelPendingRecommendationPrefetch();

    try {
        const response = await fetch(route('scheduling.section-subjects.schedule.clear', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });
        const data = await response.json();

        if (response.status === 423) {
            toast.add({ severity: 'error', summary: 'Cannot clear', detail: data.message, life: 6000 });
            return;
        }
        if (!response.ok) throw new Error(data.message ?? 'Clear failed.');

        applyFreshRows(data.sectionSubjects ?? []);
        rows.value.forEach((row) => {
            stateFor(row.id).errors = {};
        });
        dirtyRowIds.value = new Set();
        autoSummary.value = null;
        autoSummaryVisible.value = false;

        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'info', summary: 'Schedule cleared', detail: data.message, life: 5000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not clear the schedule.', life: 6000 });
    } finally {
        clearingSchedule.value = false;
    }
};

// Closing the review panel (✕ button, ESC, or clicking outside)
// WITHOUT clicking "Accept All & Save" must not leave anything
// behind — the generated rows (and any manual Faculty/Room overrides
// made while the panel was open) are already persisted as Draft the
// instant they happen, so "close without accepting" has to actively
// discard them via the same endpoint "Discard Suggestions" uses,
// not just hide the dialog. Only fires for a user-initiated close
// (the ✕ icon / ESC / mask click all emit @update:visible); the
// programmatic `autoSummaryVisible.value = false` inside
// acceptAutoSchedule() does not re-enter this handler, so an actual
// Accept is never undone.
const onAutoSummaryVisibleChange = async (visible) => {
    if (visible) {
        autoSummaryVisible.value = true;
        return;
    }

    autoSummaryVisible.value = false;
    // Nothing should still be docked open in the right-side Edit Day
    // & Time panel once the modal itself is closed — otherwise the
    // very next Auto Generate Schedule run would open with some
    // unrelated previous subject's panel already showing.
    dockedEditSectionSubjectId.value = null;

    if (!hasAutoGeneratedRows.value) {
        autoSummary.value = null;
        return;
    }

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate.clear', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Clear failed.');

        applyFreshRows(data.sectionSubjects ?? []);

        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'info', summary: 'Not Saved', detail: 'The generated schedule was discarded — nothing was saved.', life: 5000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not discard the generated schedule.', life: 6000 });
    } finally {
        autoSummary.value = null;
    }
};

// "Accept All" — the generated rows are already persisted as Draft;
// this simply runs the normal Save Schedule flow (same conflict
// re-check, same batch endpoint) to finalize them to 'Scheduled'.
const acceptAutoSchedule = async () => {
    await saveSchedule();
    autoSummaryVisible.value = false;
    autoSummary.value = null;
};

// Faculty Recommendation Selector (Prompt 8.11) — the override is
// already persisted server-side by the time this fires; this just
// refreshes the review panel's in-memory summary (Faculty block +
// Overall Score) so the Registrar sees the Live Score update
// instantly, with no modal close and no re-fetch of the whole panel.
const onFacultyOverride = (result, { faculty, overall_score }) => {
    result.faculty = faculty;
    if (overall_score !== undefined && overall_score !== null) {
        result.overall_score = overall_score;
    }

    const row = rows.value.find((r) => r.id === result.section_subject_id);
    if (row) {
        row.faculty_id = faculty.id;
        if (row.faculty) row.faculty = { ...row.faculty, id: faculty.id, full_name: faculty.name };
        // A new Faculty may have resolved (or introduced) a Time
        // hard_conflict — clear the stale flag optimistically; if it's
        // still actually conflicting, Save Schedule's server-side
        // ScheduleConflictService check catches it regardless.
        if (row.auto_generated_meta?.time?.hard_conflict) {
            row.auto_generated_meta = {
                ...row.auto_generated_meta,
                time: { ...row.auto_generated_meta.time, hard_conflict: false },
            };
        }
    }
};

// Room Recommendation Selector — same instant-refresh pattern as
// onFacultyOverride() above; the override is already persisted
// server-side, this just syncs the review panel's in-memory Room
// block + Overall Score so the Live Score updates with no modal
// close and no re-fetch of the whole panel.
const onRoomOverride = (result, { room, overall_score }) => {
    result.room = room;
    if (overall_score !== undefined && overall_score !== null) {
        result.overall_score = overall_score;
    }

    const row = rows.value.find((r) => r.id === result.section_subject_id);
    if (row) {
        row.room_id = room.id;
        if (row.room) row.room = { ...row.room, id: room.id, room_name: room.name ?? row.room.room_name };
        // Same optimistic-clear reasoning as onFacultyOverride above.
        if (row.auto_generated_meta?.time?.hard_conflict) {
            row.auto_generated_meta = {
                ...row.auto_generated_meta,
                time: { ...row.auto_generated_meta.time, hard_conflict: false },
            };
        }
    }
};

// Time Recommendation Selector — same instant-refresh pattern as
// onFacultyOverride()/onRoomOverride() above; the override is already
// persisted server-side, this just syncs the review panel's in-memory
// Time block + Overall Score so the Live Score updates with no modal
// close and no re-fetch of the whole panel.
const onTimeOverride = (result, { time, overall_score }) => {
    result.time = time;
    if (overall_score !== undefined && overall_score !== null) {
        result.overall_score = overall_score;
    }

    const row = rows.value.find((r) => r.id === result.section_subject_id);
    if (row) {
        row.days = [...time.days];
        row.start_time = time.start_time;
        row.end_time = time.end_time;
        // Carry the hard_conflict/override_reason flag onto the row
        // itself (not just the review-panel result object) so
        // tableConflicts — which drives blockingConflictRowIds and
        // therefore the Save Schedule / Accept All & Save button —
        // can see it. A genuine Faculty/Room/Section double-booking
        // picked here must block saving, the same way an in-table
        // Section Conflict already does; see tableConflicts below.
        row.auto_generated_meta = {
            ...(row.auto_generated_meta ?? {}),
            time: {
                ...((row.auto_generated_meta ?? {}).time ?? {}),
                hard_conflict: time.hard_conflict ?? false,
                override_reason: time.override_reason ?? null,
            },
        };
    }
};

// Auto Schedule review panel — whether a generated result currently
// carries a hard Faculty/Room/Section double-booking (set by
// onTimeOverride above from scoreArbitraryTime()'s hard_conflict
// flag). Drives the red card border/background + "Scheduling
// Conflict" tag so a blocking row is obvious at a glance, not just
// buried in the amber Manual Override note under Time.
const resultHasHardConflict = (result) => Boolean(result?.time?.hard_conflict);

// Cheap Day/Time signature passed to Faculty/Room selectors so each
// can clear its own stale conflictError the moment the Registrar
// edits Time to work around the exact conflict it was complaining
// about (see FacultyRecommendationSelector.vue's timeSignature prop).
const timeSignatureFor = (time) => `${(time?.days ?? []).join(',')}|${time?.start_time ?? ''}-${time?.end_time ?? ''}`;

// One line per conflicting resource (Faculty/Room/Section), naming
// exactly which existing Subject/Section already holds that slot —
// built from conflict_details (see RecommendationService::
// scoreArbitraryTime()/conflictDetail()). Falls back to the plain
// override_reason sentence if conflict_details wasn't returned
// (e.g. an older cached auto_generated_meta from before this field
// existed).
const resultConflictMessages = (result) => {
    const details = result?.time?.conflict_details;
    if (Array.isArray(details) && details.length) {
        const resourceLabel = { faculty: 'Faculty', room: 'Room', section: 'Section' };
        return details.map((d) => {
            const who = [d.subject_code, d.section_code].filter(Boolean).join(' — ');
            const label = resourceLabel[d.resource] ?? 'Slot';
            return who
                ? `${label} conflict — already scheduled for ${who} at this day/time.`
                : `${label} conflict — already booked at this day/time.`;
        });
    }
    return result?.time?.override_reason ? [result.time.override_reason] : ['This time conflicts with an existing schedule.'];
};

/* ------------------------------------------------------------------ */
/* Add Subject dialog                                                  */
/* ------------------------------------------------------------------ */

const addDialogVisible = ref(false);
const activeTab = ref('curriculum');

const openAddDialog = () => {
    activeTab.value = 'curriculum';
    curriculumForm.curriculum_id = props.curriculums.length === 1 ? props.curriculums[0].id : null;
    curriculumForm.year_level = props.sectionYearLevel;
    curriculumForm.semester = props.sectionSemester;
    curriculumPreviewRows.value = [];
    hasPreviewed.value = false;
    manualForm.reset();
    manualForm.clearErrors();
    addDialogVisible.value = true;
};

const closeAddDialog = () => {
    addDialogVisible.value = false;
    curriculumPreviewRows.value = [];
    hasPreviewed.value = false;
};

/* --- Option 1: Load From Curriculum --- */

const yearLevelOptions = computed(() => Object.values(props.yearLevelMap).map((label) => ({ label, value: label })));
const semesterOptions = computed(() => props.semesterOptions.map((label) => ({ label, value: label })));

const curriculumForm = useForm({
    curriculum_id: null,
    year_level: null,
    semester: null,
});

const curriculumPreviewRows = ref([]);
const hasPreviewed = ref(false);
const previewLoading = ref(false);
const previewError = ref('');

const onPreview = async () => {
    previewError.value = '';

    if (!curriculumForm.curriculum_id || !curriculumForm.year_level || !curriculumForm.semester) {
        previewError.value = 'Select a curriculum, year level, and semester first.';
        return;
    }

    previewLoading.value = true;

    try {
        const { data } = await window.axios.get(
            route('scheduling.section-subjects.curriculum-preview', props.section.id),
            {
                params: {
                    curriculum_id: curriculumForm.curriculum_id,
                    year_level: curriculumForm.year_level,
                    semester: curriculumForm.semester,
                },
            },
        );
        curriculumPreviewRows.value = data.subjects;
        hasPreviewed.value = true;

        if (data.subjects.length === 0) {
            previewError.value = 'No new subjects found for this curriculum, year level, and semester.';
        }
    } catch (error) {
        previewError.value = 'Unable to load subjects for that curriculum, year level, and semester.';
    } finally {
        previewLoading.value = false;
    }
};

const removePreviewRow = (subjectId) => {
    curriculumPreviewRows.value = curriculumPreviewRows.value.filter((subject) => subject.id !== subjectId);
};

const curriculumSaving = ref(false);
const curriculumSaveErrors = ref([]);

const onConfirmCurriculumLoad = () => {
    if (curriculumPreviewRows.value.length === 0) {
        return;
    }

    curriculumSaving.value = true;
    curriculumSaveErrors.value = [];

    router.post(
        route('scheduling.section-subjects.store', props.section.id),
        {
            source: 'Curriculum',
            subject_ids: curriculumPreviewRows.value.map((subject) => subject.id),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                // CONCURRENCY HARDENING — this write goes through
                // generateCurriculumSubjects()/store(), which returns a
                // normal Inertia redirect (not a JSON schedule_version
                // response), but it DOES bump the Section's real
                // schedule_version server-side. Inertia's default
                // reload already refreshed `props.scheduleVersion` to
                // the new value by the time onSuccess runs — without
                // this, schedulePolling.currentVersion would stay
                // frozen at the OLD version, so the very next Room Grid
                // or Subjects tab save would send a stale
                // expected_schedule_version and fail with a false
                // SCHEDULE_VERSION_CONFLICT, even though it's the same
                // admin who just added these subjects.
                schedulePolling.acceptVersion(props.scheduleVersion);
                closeAddDialog();
            },
            onError: (errors) => {
                curriculumSaveErrors.value = Object.values(errors);
            },
            onFinish: () => {
                curriculumSaving.value = false;
            },
        },
    );
};

/* --- Option 2: Manual Selection --- */

const manualForm = useForm({
    subject_ids: [],
});

const manualSubjectOptions = computed(() => props.availableSubjects);

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
            onSuccess: () => {
                // See the matching comment in onConfirmCurriculumLoad()
                // above — store() also bumps schedule_version via a
                // plain Inertia redirect, so the local polling baseline
                // needs to be told about it explicitly here too.
                schedulePolling.acceptVersion(props.scheduleVersion);
                closeAddDialog();
            },
            onError: (errors) => {
                manualForm.errors.subject_ids = Object.values(errors).flat().join(' ');
            },
        },
    );
};

/* ------------------------------------------------------------------ */
/* Remove Subject                                                      */
/* ------------------------------------------------------------------ */

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
                onSuccess: () => {
                    // See the matching comment in
                    // onConfirmCurriculumLoad() above — destroy() also
                    // bumps schedule_version via a plain Inertia
                    // redirect, so the local polling baseline needs to
                    // be told about it explicitly here too.
                    schedulePolling.acceptVersion(props.scheduleVersion);
                },
            });
        }
    });
};

const sourceSeverity = (source) => (source === 'Curriculum' ? 'info' : 'secondary');
const categorySeverity = (category) => (category === 'Major' ? 'info' : 'secondary');
</script>

<template>
    <Head :title="`${section.section_code} — Subjects`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Section Subjects</span>
        </template>

        <div class="max-w-[100rem] mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Back link -->
            <div class="mb-4">
                <Link :href="route('scheduling.sections')" class="text-sm text-slate-500 hover:text-slate-700">
                    <i class="pi pi-arrow-left mr-1"></i> Back to Sections
                </Link>
            </div>

            <!-- REAL-TIME SCHEDULE CHANGE DETECTION — non-blocking notice
                 shown when polling detects another user changed this
                 Section's schedule. Never auto-dismisses/auto-refreshes;
                 the Registrar's current view and any unsaved edits stay
                 exactly as they are until they choose to refresh. -->
            <div
                v-if="schedulePolling.isStale.value"
                class="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3"
            >
                <div class="flex items-start gap-2.5">
                    <i class="pi pi-exclamation-triangle text-amber-500 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-amber-800">Schedule Updated</p>
                        <p class="text-sm text-amber-700">
                            {{ schedulePolling.staleUpdatedByName.value
                                ? `${schedulePolling.staleUpdatedByName.value} changed this schedule.`
                                : 'Another user changed this schedule.' }}
                            Your current view may be outdated — refresh before saving.
                        </p>
                    </div>
                </div>
                <Button
                    label="Refresh Schedule"
                    icon="pi pi-refresh"
                    size="small"
                    severity="warn"
                    :loading="refreshingSchedule"
                    @click="refreshSchedule"
                />
            </div>

            <!-- Page Title -->
            <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-[#1E293B] flex items-center gap-2">
                        {{ sectionLabel(section) }}
                        <i
                            v-if="isSectionFinalized"
                            class="pi pi-lock text-amber-500 text-lg"
                            title="This section's schedule is finalized and locked."
                        ></i>
                        <Button
                            icon="pi pi-info-circle"
                            text
                            rounded
                            size="small"
                            severity="secondary"
                            class="!p-1.5 !text-slate-400"
                            aria-label="How this page works"
                            title="How this page works"
                            @click="toggleHelp"
                        />
                    </h1>
                    <!-- Section switcher — jump straight to another Section
                         (same Academic Year/Semester, same visibility scope
                         as the Sections list) without going Back to Sections. -->
                    <Select
                        v-if="sectionSwitcherOptions.length > 1"
                        v-model="selectedSectionId"
                        :options="sectionSwitcherOptions"
                        optionLabel="label"
                        optionValue="id"
                        filter
                        placeholder="Switch section"
                        class="mt-2 w-full sm:w-72"
                        size="small"
                        @update:modelValue="onSwitchSection"
                    >
                        <template #value="{ value }">
                            <span v-if="currentSwitcherOption" class="flex items-center gap-2">
                                <span
                                    class="inline-block h-2 w-2 rounded-full shrink-0"
                                    :class="[
                                        sectionStatusDotClass(currentSwitcherOption.status),
                                        currentSwitcherOption.status === 'full' ? 'animate-pulse' : '',
                                    ]"
                                ></span>
                                <span>{{ currentSwitcherOption.label }}</span>
                            </span>
                            <span v-else>{{ value }}</span>
                        </template>
                        <template #option="{ option }">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="flex items-center gap-2">
                                    <span
                                        class="inline-block h-2 w-2 rounded-full shrink-0"
                                        :class="[
                                            sectionStatusDotClass(option.status),
                                            option.status === 'full' ? 'animate-pulse' : '',
                                        ]"
                                        :title="{
                                            full: `Fully Scheduled (${option.assignedSubjects}/${option.totalSubjects})`,
                                            partial: `Partially Scheduled (${option.assignedSubjects}/${option.totalSubjects})`,
                                            not_scheduled: 'Not Scheduled',
                                            none: 'No Subjects Yet',
                                        }[option.status]"
                                    ></span>
                                    <span>{{ option.label }}</span>
                                </span>
                                <span v-if="option.major" class="text-xs text-slate-400">{{ option.major }}</span>
                            </div>
                        </template>
                    </Select>
                </div>
                <Popover ref="helpPopover" :pt="{ root: { class: isDark ? 'dark-scope' : '' } }">
                    <div class="w-80 max-w-[85vw] text-sm text-slate-600 leading-relaxed space-y-2">
                        <p class="font-semibold text-[#1E293B]">Section Subjects</p>
                        <p>
                            Build this section's subject list and assign Faculty, Room, Days, and Time directly in
                            the table, or let the recommendation engine do it for you.
                        </p>
                        <ul class="list-disc space-y-1 pl-4">
                            <li>"Auto Generate Schedule" proposes Faculty, Room, and Time for every unscheduled subject — review before saving.</li>
                            <li>"Save Schedule" persists everything at once; the server validates room, faculty, section, and time conflicts before committing.</li>
                            <li>⚡ Auto tags mark rows assigned by the recommendation engine, not yet saved.</li>
                            <li>🔒 Finalized sections are locked from editing — only Admin/Registrar can unlock them.</li>
                        </ul>
                    </div>
                </Popover>
                <div class="flex items-center gap-3 shrink-0 section-actions">
                    <span v-if="hasUnsavedChanges" class="text-sm text-amber-600 font-medium whitespace-nowrap">
                        <i class="pi pi-circle-fill text-[6px] align-middle mr-1"></i>Unsaved changes
                    </span>
                    <Button
                        v-if="hasAutoGeneratedRows"
                        label="Discard Suggestions"
                        icon="pi pi-eraser"
                        severity="secondary"
                        outlined
                        :loading="autoClearing"
                        :disabled="isSectionFinalized"
                        @click="clearAutoSchedule"
                    />
                    <Button
                        v-if="!justSaved"
                        label="Clear Schedule"
                        icon="pi pi-trash"
                        severity="danger"
                        outlined
                        :loading="clearingSchedule"
                        :disabled="rows.length === 0 || isSectionFinalized"
                        :title="isSectionFinalized ? 'This section is finalized and locked.' : 'Wipe every subject\'s Faculty, Room, Day, and Time — including already-saved schedules.'"
                        @click="clearWholeSchedule"
                    />
                    <Button
                        label="Print"
                        icon="pi pi-print"
                        severity="info"
                        outlined
                        title="Print this section's schedule (Schedule by Section report)."
                        @click="printSectionSchedule"
                    />
                    <Button
                        :label="autoGenerateButtonLabel"
                        icon="pi pi-bolt"
                        severity="help"
                        :loading="autoGenerating"
                        :disabled="rows.length === 0 || isSectionFinalized"
                        :title="isSectionFinalized ? 'This section is finalized and locked.' : 'Automatically assign the best Faculty, Room, Day, and Time for every unscheduled subject.'"
                        @click="runAutoGenerate"
                    />
                    <Button
                        label="Save Schedule"
                        icon="pi pi-save"
                        severity="success"
                        :loading="savingSchedule"
                        :disabled="rows.length === 0 || blockingConflictRowIds.size > 0 || schedulePolling.isStale.value || isSectionFinalized"
                        :title="
                            schedulePolling.isStale.value
                                ? 'Schedule changed. Please refresh before saving.'
                                : blockingConflictRowIds.size > 0
                                  ? 'Resolve the Scheduling Issues above before saving.'
                                  : undefined
                        "
                        @click="saveSchedule"
                    />
                </div>
            </div>

            <!-- Section Information -->
            <div class="neu-card rounded-2xl mb-6 transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div class="neu-inset rounded-xl p-3">
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Major</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.major?.name || '—' }}</p>
                        </div>
                        <div class="neu-inset rounded-xl p-3">
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Curriculum</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.curriculum?.code || '—' }}</p>
                        </div>
                        <div class="neu-inset rounded-xl p-3">
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Academic Year</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.academic_year }}</p>
                        </div>
                        <!--
                            Semester — this Section's OWN semester (2nd Sem
                            BSIT-3A, etc.), distinct from the global "Active
                            Academic Term" pill in the topbar (AppLayout.vue),
                            which reflects the institution-wide active term
                            and is NOT scoped to whichever Section is open
                            here. Without this card, the only semester text
                            visible on this page was that topbar pill, which
                            made it look like Room Grid/Subjects data hadn't
                            "switched" to the Section's real semester even
                            though the underlying query ($section->sectionSubjects())
                            was always correct — this card exists purely to
                            make that already-correct semester visible.
                        -->
                        <div class="neu-inset rounded-xl p-3">
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Semester</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.semester || '—' }}</p>
                        </div>
                        <div class="neu-inset rounded-xl p-3">
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Year Level</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.year_level }}</p>
                        </div>
                        <div class="neu-inset rounded-xl p-3">
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Est. Students</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.estimated_students }}</p>
                        </div>
                    </div>
                </template>
            </Card>
            </div>

            <!-- Scheduling Issues panel — real-time, recomputed on every edit -->
            <div v-if="tableConflicts.length > 0" class="neu-card rounded-2xl mb-6 transition-colors duration-300" :class="isDark ? '' : '!bg-red-50/60'">
            <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none">
                <template #content>
                    <div class="flex items-center gap-2 mb-3">
                        <i class="pi pi-exclamation-triangle text-red-500"></i>
                        <h2 class="font-semibold text-red-700">Scheduling Issues</h2>
                        <Tag :value="`${tableConflicts.length}`" severity="danger" class="!text-xs" />
                    </div>
                    <ul class="space-y-2">
                        <li
                            v-for="(conflict, index) in tableConflicts"
                            :key="index"
                            class="text-sm neu-inset rounded-lg px-3 py-2 flex items-start gap-2"
                        >
                            <Tag
                                :value="conflict.label"
                                :severity="conflict.type === 'capacity' || conflict.type === 'hours' || conflict.type === 'roomType' || conflict.type === 'roomCollege' || conflict.type === 'facultyMismatch' ? 'warning' : 'danger'"
                                class="!text-xs shrink-0"
                            />
                            <span class="text-slate-600">{{ conflict.detail }}</span>
                        </li>
                    </ul>
                </template>
            </Card>
            </div>

            <!-- SECTION-LEVEL SCHEDULE FINALIZATION — visible lock banner.
                 Backed up server-side by SectionFinalizedException on every
                 write path; this is purely so the Registrar/Dean sees WHY
                 every field below is grayed out instead of guessing. -->
            <div
                v-if="isSectionFinalized"
                class="mb-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                <i class="pi pi-lock"></i>
                <span class="font-medium">🔒 Finalized</span>
                <span>— this section's schedule is locked. Only an Admin/Registrar can unlock it before it can be edited again.</span>
            </div>

            <!-- Subjects / Room Grid tab switcher -->
            <div class="mb-4 flex items-center gap-1 border-b border-slate-200">
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
                    :class="pageTab === 'subjects' ? 'border-blue-500 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="pageTab = 'subjects'"
                >
                    Subjects
                </button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
                    :class="pageTab === 'room-grid' ? 'border-blue-500 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="pageTab = 'room-grid'"
                >
                    Room Grid
                </button>
                <InfoPopover
                    title="Room Grid"
                    :paragraphs="['Displays room usage by day and time for this section\'s scheduled subjects.']"
                    :bullets="[
                        'View scheduled classes and identify room availability at a glance.',
                        'Detect overlapping room assignments before they become conflicts.',
                        'Changes made here still go through the same server-side conflict validation as the Subjects table.',
                    ]"
                    width="w-72"
                />
            </div>

            <!-- Room Grid tab -->
            <div v-show="pageTab === 'room-grid'" class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <RoomGrid
                        :section="section"
                        :rows="rows"
                        :active-faculty="activeFaculty"
                        :scheduling-window="schedulingWindow"
                        :hard-cap-units="hardCapUnits"
                        :is-stale="schedulePolling.isStale.value"
                        :expected-schedule-version="schedulePolling.currentVersion.value"
                        @row-updated="onRoomGridRowUpdated"
                        @schedule-stale="onScheduleStaleFromRoomGrid"
                    />
                </template>
            </Card>
            </div>

            <!-- Subjects / Scheduling table -->
            <div v-show="pageTab === 'subjects'" class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3 neu-form">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-uppercase
                                    v-model="search"
                                    placeholder="Search by code, title or category"
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
                                <Button
                                    label="Add Subject"
                                    icon="pi pi-plus"
                                    severity="success"
                                    :disabled="isSectionFinalized"
                                    :title="isSectionFinalized ? 'This section is finalized and locked.' : null"
                                    @click="openAddDialog"
                                />
                            </div>
                        </template>
                    </Toolbar>

                    <DataTable
                        :value="rows"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden schedule-table"
                        :class="isDark ? 'neu-table-dark' : ''"
                        :rowClass="
                            (row) =>
                                rowIsInConflict(row)
                                    ? '!bg-red-50 conflict-row-clickable row-light-bg'
                                    : rowHasCapacityWarning(row.id)
                                      ? '!bg-amber-50 row-light-bg'
                                      : dirtyRowIds.has(row.id)
                                        ? '!bg-amber-50 row-light-bg'
                                        : undefined
                        "
                        @row-click="onRowClick"
                        stripedRows
                        responsiveLayout="scroll"
                        scrollable
                        scrollHeight="flex"
                        paginator
                        :rows="10"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No subjects assigned yet.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Subject" to load from the curriculum or select manually.
                                </p>
                                <Button
                                    label="Add Subject"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    :disabled="isSectionFinalized"
                                    @click="openAddDialog"
                                />
                            </div>
                        </template>

                        <Column style="width: 100%">
                            <template #body="{ data }">
                                <div
                                    class="flex flex-col gap-2.5 py-1.5 px-3 -mx-3 rounded-lg transition-colors"
                                    :class="{ 'unscheduled-row': rowIsUnscheduled(data) }"
                                >
                                    <!-- Line 1: EDP Code / Subject Code / Subject Title / Category / Units / Status / Source / Actions -->
                                    <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5">
                                        <div class="min-w-[7rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">EDP Code</p>
                                            <span v-if="data.edp_code" class="font-mono text-xs font-semibold text-indigo-700">
                                                {{ data.edp_code }}
                                            </span>
                                            <Tag v-else value="Pending" severity="secondary" class="!text-[0.65rem]" />
                                        </div>
                                        <div class="min-w-[7rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Subject Code</p>
                                            <span class="text-xs font-medium text-slate-700">{{ data.subject?.subject_code }}</span>
                                        </div>
                                        <div class="min-w-[12rem] flex-1">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Subject Title</p>
                                            <span class="text-xs text-slate-700">{{ data.subject?.subject_title }}</span>
                                        </div>
                                        <div class="min-w-[7rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Category</p>
                                            <Tag :value="data.subject?.category" :severity="categorySeverity(data.subject?.category)" class="!text-[0.65rem]" />
                                        </div>
                                        <div class="w-10">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Units</p>
                                            <span class="text-xs text-slate-700">{{ data.subject?.units }}</span>
                                        </div>
                                        <div class="min-w-[8rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Status</p>
                                            <div class="flex items-center gap-1 flex-wrap">
                                                <Tag :value="displayStatus(data)" :severity="statusSeverity(displayStatus(data))" class="!text-[0.65rem]" />
                                                <Tag
                                                    v-if="isSectionFinalized"
                                                    value="🔒 Finalized"
                                                    severity="warn"
                                                    class="!text-[0.65rem]"
                                                    title="This section's schedule is finalized and locked. Only an Admin/Registrar can unlock it."
                                                />
                                                <Tag
                                                    v-if="data.is_auto_generated"
                                                    value="⚡ Auto"
                                                    severity="help"
                                                    class="!text-[0.65rem]"
                                                    title="Assigned by Auto Generate Schedule — review and click Save Schedule to keep it, or Discard Suggestions to discard it."
                                                />
                                                <i
                                                    v-if="rowIsInConflict(data) || rowHasCapacityWarning(data.id) || rowHasRoomTypeWarning(data.id) || rowHasFacultyMismatchWarning(data.id)"
                                                    class="pi pi-exclamation-triangle"
                                                    :class="rowIsInConflict(data) ? 'text-red-500' : 'text-amber-500'"
                                                    :title="rowIsInConflict(data) ? (conflictTooltip(data.id) || 'Conflict — click the row to find the best schedule') : (conflictTooltip(data.id) || 'Unresolved scheduling conflict')"
                                                ></i>
                                                <span
                                                    v-if="rowIsInConflict(data)"
                                                    class="text-[0.65rem] text-red-500 underline decoration-dotted cursor-pointer"
                                                    @click.stop="openRecommendDrawer(data)"
                                                >
                                                    Click to find best schedule
                                                </span>
                                                <span
                                                    v-else-if="rowIsUnscheduled(data)"
                                                    class="text-[0.65rem] text-blue-500 italic"
                                                >
                                                    Not yet scheduled
                                                </span>
                                            </div>
                                        </div>
                                        <div class="min-w-[6rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Source</p>
                                            <Tag :value="data.source" :severity="sourceSeverity(data.source)" class="!text-[0.65rem]" />
                                        </div>
                                        <!-- INTELLIGENT IRREGULAR SECTION SCHEDULING — a merged row
                                             rides along on a Regular section's existing class
                                             (see mergeExclusionIds()/IrregularSectionMergeService);
                                             naming that host Section here, next to Status/Source,
                                             makes it obvious at a glance why this row's
                                             Faculty/Room/Days/Time exactly duplicate another
                                             class's, instead of looking like an unexplained
                                             coincidence. -->
                                        <div v-if="data.is_merged" class="min-w-[8rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Merged Into</p>
                                            <Tag
                                                :value="data.merged_into?.section?.section_code ?? 'Regular Section'"
                                                severity="info"
                                                icon="pi pi-sitemap"
                                                class="!text-[0.65rem]"
                                            />
                                        </div>
                                        <div class="ml-auto flex items-center gap-1 self-end">
                                            <Button
                                                icon="pi pi-sparkles"
                                                label="Recommend"
                                                text
                                                size="small"
                                                class="!text-xs"
                                                aria-label="Smart Schedule Recommendation"
                                                :disabled="isSectionFinalized"
                                                @click="openRecommendDrawer(data)"
                                            />
                                            <Button
                                                icon="pi pi-trash"
                                                text
                                                rounded
                                                severity="danger"
                                                size="small"
                                                aria-label="Remove"
                                                :disabled="isSectionFinalized"
                                                :title="isSectionFinalized ? 'This section is finalized and locked.' : null"
                                                @click="onRemove(data)"
                                            />
                                        </div>
                                    </div>

                                    <!-- Line 2: Faculty / Room / Days / Start Time / End Time -->
                                    <div class="flex flex-wrap items-start gap-3 pt-2 border-t border-slate-100">
                                        <!-- Faculty -->
                                        <div class="flex-1 min-w-[15rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Faculty</p>
                                            <div class="flex items-start gap-1" @mouseenter="fetchRecommendations(data)">
                                                <Select
                                                    v-model="data.faculty_id"
                                                    :options="facultyGroupsFor(data)"
                                                    optionLabel="label"
                                                    optionValue="value"
                                                    optionGroupLabel="label"
                                                    optionGroupChildren="items"
                                                    optionDisabled="disabled"
                                                    filter
                                                    showClear
                                                    placeholder="Select faculty"
                                                    class="w-full"
                                                    :disabled="isSectionFinalized"
                                                    :class="{ 'p-invalid': stateFor(data.id).errors.faculty_id || facultyConflictRowIds.has(data.id) || rowHasFacultyMismatchWarning(data.id), 'unscheduled-field': rowIsUnscheduled(data) }"
                                                    emptyMessage="No active faculty"
                                                    emptyFilterMessage="No matching faculty"
                                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                    @update:modelValue="(v) => onFacultyChange(data, v)"
                                                    @show="fetchRecommendations(data)"
                                                >
                                                    <template #optiongroup="{ option }">
                                                        <span class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-400">{{ option.label }}</span>
                                                    </template>
                                                    <template #option="{ option }">
                                                        <div class="flex flex-col gap-0.5 py-0.5 w-full" :class="{ 'italic text-slate-400': option.disabled }">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span class="text-xs">{{ option.label }}</span>
                                                                <span v-if="!option.disabled" class="flex items-center gap-1 shrink-0">
                                                                    <Tag v-if="option.confidence" :value="option.confidence" :severity="confidenceSeverity(option.confidence)" class="!text-[0.6rem] !py-0.5" />
                                                                    <Tag v-else-if="option.bestMatch" value="Best Match" severity="success" class="!text-[0.6rem] !py-0.5" />
                                                                </span>
                                                            </div>
                                                            <div v-if="!option.disabled && option.maxUnits != null" class="flex items-center gap-1.5">
                                                                <span class="text-[0.65rem]" :class="remainingUnitsClass(option)">
                                                                    {{ remainingUnitsLabel(option) }}
                                                                </span>
                                                                <!-- No legitimate way to pick this Faculty
                                                                     member as-is (0 units left) without first
                                                                     raising their ceiling — same inline
                                                                     request/approval on-ramp RoomGrid's
                                                                     "Schedule Subject" modal offers.
                                                                     mousedown.stop (not click.stop) so this
                                                                     fires before PrimeVue's own option-select
                                                                     handler. -->
                                                                <button
                                                                    v-if="hasInsufficientUnits(option)"
                                                                    type="button"
                                                                    class="text-[0.65rem] font-medium text-blue-500 hover:text-blue-600 underline"
                                                                    @mousedown.stop.prevent="openAddUnitsForOption(option)"
                                                                >
                                                                    {{ canChangeMaxLoad ? 'Add Units' : 'Request more units' }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </Select>
                                            </div>
                                            <p v-if="stateFor(data.id).errors.faculty_id" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.faculty_id }}
                                            </p>
                                            <p v-else-if="facultyConflictRowIds.has(data.id)" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Faculty already booked this day/time.
                                            </p>
                                        </div>

                                        <!-- Room -->
                                        <div class="flex-1 min-w-[14rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Room</p>
                                            <div class="flex items-start gap-1" @mouseenter="fetchRecommendations(data)">
                                                <Select
                                                    v-model="data.room_id"
                                                    :options="roomGroupsFor(data)"
                                                    optionLabel="label"
                                                    optionValue="value"
                                                    optionGroupLabel="label"
                                                    optionGroupChildren="items"
                                                    optionDisabled="disabled"
                                                    filter
                                                    showClear
                                                    placeholder="Select room"
                                                    class="w-full"
                                                    :disabled="isSectionFinalized"
                                                    :class="{ 'p-invalid': stateFor(data.id).errors.room_id || roomConflictRowIds.has(data.id), 'unscheduled-field': rowIsUnscheduled(data) }"
                                                    emptyMessage="No active rooms"
                                                    emptyFilterMessage="No matching rooms"
                                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                    @update:modelValue="(v) => onRoomChange(data, v)"
                                                    @show="fetchRecommendations(data)"
                                                >
                                                    <template #optiongroup="{ option }">
                                                        <span class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-400">{{ option.label }}</span>
                                                    </template>
                                                    <template #option="{ option }">
                                                        <div class="flex items-center justify-between gap-2 w-full" :class="{ 'italic text-slate-400': option.disabled }">
                                                            <span class="text-xs">{{ option.label }}</span>
                                                            <span v-if="!option.disabled" class="flex items-center gap-1 shrink-0">
                                                                <span
                                                                    v-if="option.maxHours"
                                                                    class="text-[0.65rem] text-slate-400 whitespace-nowrap"
                                                                    :title="'Room Utilization: ' + (option.scheduledHours ?? 0) + ' / ' + option.maxHours + ' hrs'"
                                                                >
                                                                    {{ option.scheduledHours ?? 0 }}/{{ option.maxHours }} hrs
                                                                </span>
                                                                <Tag v-if="option.confidence" :value="option.confidence" :severity="confidenceSeverity(option.confidence)" class="!text-[0.6rem] !py-0.5" />
                                                            </span>
                                                        </div>
                                                    </template>
                                                </Select>
                                            </div>
                                            <p v-if="stateFor(data.id).errors.room_id" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.room_id }}
                                            </p>
                                            <p v-else-if="roomConflictRowIds.has(data.id)" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Room already booked this day/time.
                                            </p>
                                            <p v-else-if="rowHasCapacityWarning(data.id)" class="text-amber-600 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Section capacity exceeds this room's capacity.
                                            </p>
                                            <p v-else-if="rowHasRoomTypeWarning(data.id)" class="text-amber-600 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Room type doesn't match this subject — you'll be asked to confirm before saving.
                                            </p>
                                            <p v-else-if="rowHasRoomCollegeWarning(data.id)" class="text-amber-600 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>This room belongs to another college — you'll be asked to confirm before saving.
                                            </p>
                                        </div>

                                        <!-- Days -->
                                        <div class="w-28 shrink-0">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Days</p>
                                            <div class="flex items-start gap-1">
                                                <MultiSelect
                                                    v-model="data.days"
                                                    :options="dayOptions"
                                                    optionLabel="label"
                                                    optionValue="value"
                                                    placeholder="Select days"
                                                    :selectionLimit="2"
                                                    class="w-full"
                                                    :disabled="isSectionFinalized"
                                                    :class="{ 'p-invalid': stateFor(data.id).errors.days || sectionConflictRowIds.has(data.id), 'unscheduled-field': rowIsUnscheduled(data) }"
                                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                    @update:modelValue="(v) => onDaysChange(data, v)"
                                                >
                                                    <template #value="{ value, placeholder }">
                                                        <span v-if="!value || value.length === 0" class="text-slate-400">{{ placeholder }}</span>
                                                        <span v-else class="font-medium">{{ formatDays(value) }}</span>
                                                    </template>
                                                    <template #option="{ option }">
                                                        <span class="text-xs">{{ option.label }}</span>
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
                                            </div>
                                            <p v-if="stateFor(data.id).errors.days" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.days }}
                                            </p>
                                            <p v-else-if="sectionConflictRowIds.has(data.id)" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Overlaps another class in this section.
                                            </p>
                                        </div>

                                        <!-- Start Time -->
                                        <div class="w-44 shrink-0">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Start Time</p>
                                            <Select
                                                :modelValue="data.start_time"
                                                :options="timeOptionsFor(data)"
                                                optionLabel="label"
                                                optionValue="value"
                                                optionDisabled="disabled"
                                                filter
                                                showClear
                                                placeholder="Start"
                                                class="w-full"
                                                :disabled="isSectionFinalized"
                                                :class="{ 'p-invalid': stateFor(data.id).errors.start_time, 'unscheduled-field': rowIsUnscheduled(data) }"
                                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                @update:modelValue="(v) => onStartTimeChange(data, v)"
                                                @show="fetchBusyTimes(data)"
                                            >
                                                <template #option="{ option }">
                                                    <span class="text-xs" :class="{ 'text-slate-300 line-through': option.disabled }" :title="option.disabled ? 'Already booked at this time' : undefined">{{ option.label }}</span>
                                                </template>
                                            </Select>
                                            <p v-if="stateFor(data.id).errors.start_time" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.start_time }}
                                            </p>
                                        </div>

                                        <!-- End Time -->
                                        <div class="w-44 shrink-0">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">End Time</p>
                                            <Select
                                                :modelValue="data.end_time"
                                                :options="endTimeOptionsFor(data)"
                                                optionLabel="label"
                                                optionValue="value"
                                                optionDisabled="disabled"
                                                filter
                                                showClear
                                                placeholder="End"
                                                class="w-full"
                                                :disabled="isSectionFinalized"
                                                :class="{ 'p-invalid': stateFor(data.id).errors.end_time, 'unscheduled-field': rowIsUnscheduled(data) }"
                                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                @update:modelValue="(v) => onEndTimeChange(data, v)"
                                                @show="fetchBusyTimes(data)"
                                            >
                                                <template #option="{ option }">
                                                    <span class="text-xs" :class="{ 'text-slate-300 line-through': option.disabled }" :title="option.disabled ? 'Already booked at this time' : undefined">{{ option.label }}</span>
                                                </template>
                                            </Select>
                                            <p v-if="stateFor(data.id).errors.end_time" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.end_time }}
                                            </p>
                                            <!-- SIBLING SECTION PATTERN note — mirrors RoomGrid's own
                                                 prefill hint (see computeAutoEndTime/siblingPatternHoursFor
                                                 above). Only shown while End Time still equals the
                                                 auto-filled sibling-derived value; editing it by hand
                                                 hides this automatically since the values diverge. -->
                                            <p
                                                v-else-if="siblingPatternHoursFor(data) && data.end_time === computeAutoEndTime(data)"
                                                class="text-[11px] text-teal-600 mt-1"
                                            >
                                                <i class="pi pi-copy"></i>
                                                Matches {{ recommendations[data.id]?.combined?.recommendations?.[0]?.pattern_source?.donor_section_code }}'s schedule.
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Row-level errors — Hours Mismatch / Capacity. Unlike
                                         Faculty/Room/Days/Time, these don't belong to one single
                                         field, so they were previously set into stateFor(id).errors
                                         by the server (batchUpdateSchedule()'s 'hours'/'capacity'
                                         keys) but never actually rendered anywhere — a save could
                                         fail for one of these reasons with the row highlighted and
                                         NO visible explanation at all. Surfaced here as a banner
                                         for the whole row instead. -->
                                    <p v-if="stateFor(data.id).errors.hours" class="text-red-500 text-xs mt-1">
                                        <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.hours }}
                                    </p>
                                    <p v-if="stateFor(data.id).errors.capacity" class="text-red-500 text-xs mt-1">
                                        <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.capacity }}
                                    </p>
                                    <p v-if="stateFor(data.id).errors.workload" class="text-red-500 text-xs mt-1">
                                        <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.workload }}
                                        <a
                                            v-if="workloadCanOverride"
                                            href="#"
                                            class="underline font-medium ml-1"
                                            @click.prevent="confirmWorkloadOverride(data.id)"
                                        >Confirm &amp; Save Anyway</a>
                                        <a
                                            v-if="workloadWarningDetails[data.id]"
                                            href="#"
                                            class="underline font-medium ml-1"
                                            @click.prevent="openAddUnitsForRow(data.id)"
                                        >{{ canChangeMaxLoad ? 'Add Units' : 'Request more units' }}</a>
                                    </p>
                                </div>
                            </template>
                        </Column>

                    </DataTable>

                </template>
            </Card>
            </div>
        </div>

        <!-- Smart Schedule Recommendation Drawer (Prompt 8.7) -->
        <Drawer
            v-model:visible="recommendDrawerVisible"
            position="right"
            :style="{ width: '38rem' }"
            :breakpoints="{ '960px': '90vw', '640px': '100vw' }"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
        >
            <template #header>
                <div>
                    <p class="text-lg font-semibold text-slate-800">
                        <i class="pi pi-sparkles mr-1.5 text-indigo-500"></i>Smart Schedule Recommendation
                    </p>
                    <p v-if="recommendDrawerRow" class="text-xs text-slate-400 mt-0.5">
                        Analyzed against all available Faculty, Rooms, and Time slots
                    </p>
                </div>
            </template>

            <div v-if="recommendDrawerRow" class="flex flex-col gap-5">
                <!-- Context header: Subject / Section / Major / Academic Year / Semester -->
                <div class="grid grid-cols-2 gap-3 bg-slate-50 rounded-lg p-3 text-sm">
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Subject</p>
                        <p class="font-medium text-slate-800">
                            {{ recommendDrawerRow.subject?.subject_code }} — {{ recommendDrawerRow.subject?.subject_name }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Section</p>
                        <p class="font-medium text-slate-800">{{ sectionLabel(section) }}</p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Major</p>
                        <p class="font-medium text-slate-800">{{ section.major?.name || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Academic Year</p>
                        <p class="font-medium text-slate-800">{{ section.academic_year || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Semester</p>
                        <p class="font-medium text-slate-800">{{ section.semester || '—' }}</p>
                    </div>
                </div>

                <div class="flex justify-end -mt-2">
                    <Button
                        icon="pi pi-refresh"
                        label="Re-analyze"
                        text
                        size="small"
                        :loading="recommendDrawerState.loading"
                        @click="fetchRecommendations(recommendDrawerRow, true)"
                    />
                </div>

                <div v-if="recommendDrawerState.loading" class="text-sm text-slate-500 text-center py-6">
                    <i class="pi pi-spin pi-spinner mr-1.5"></i>Analyzing Faculty, Rooms, and Time slots…
                </div>
                <div v-else-if="recommendDrawerState.error" class="text-sm text-red-500 text-center py-6">
                    {{ recommendDrawerState.error }}
                </div>

                <template v-else>
                    <!-- Combined Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-star-fill mr-1 text-amber-400"></i>Combined Recommendations
                        </p>
                        <p v-if="recommendDrawerState.combined?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.combined.message }}
                        </p>
                        <div v-else class="flex flex-col gap-3">
                            <Card
                                v-for="(combo, idx) in recommendDrawerState.combined?.recommendations ?? []"
                                :key="idx"
                                class="border border-slate-200 shadow-none"
                            >
                                <template #content>
                                    <div class="flex items-start justify-between gap-2 mb-2">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="text-sm font-semibold text-slate-800">Recommendation #{{ idx + 1 }}</p>
                                            <Tag
                                                v-if="combo.is_sibling_pattern"
                                                :value="`Matches ${combo.pattern_source?.donor_section_code ?? 'sibling section'}`"
                                                severity="info"
                                                icon="pi pi-sparkles"
                                            />
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <Tag :value="combo.confidence" :severity="confidenceSeverity(combo.confidence)" />
                                            <span class="text-xs font-semibold text-slate-500">{{ combo.score }}/{{ combo.score_max }}%</span>
                                        </div>
                                    </div>
                                    <ProgressBar
                                        :value="combo.score"
                                        :showValue="false"
                                        style="height: 6px"
                                        :pt="{ value: { style: { backgroundColor: scoreColor(combo.score) } } }"
                                        class="mb-3"
                                    />
                                    <div class="grid grid-cols-3 gap-2 text-xs mb-3">
                                        <div>
                                            <p class="text-slate-400 uppercase tracking-wide text-[0.65rem]">Faculty</p>
                                            <p class="font-medium text-slate-700">{{ combo.faculty.name }}</p>
                                        </div>
                                        <div>
                                            <p class="text-slate-400 uppercase tracking-wide text-[0.65rem]">Room</p>
                                            <p class="font-medium text-slate-700">{{ combo.room.name }}</p>
                                        </div>
                                        <div>
                                            <p class="text-slate-400 uppercase tracking-wide text-[0.65rem]">Schedule</p>
                                            <p class="font-medium text-slate-700">
                                                {{ formatDays(combo.time.days) }} · {{ formatTimeRange(combo.time.start_time, combo.time.end_time) }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <p class="text-[0.7rem] text-emerald-600">
                                            <i class="pi pi-check-circle mr-1"></i>Conflict: {{ combo.conflict || 'None' }}
                                        </p>
                                        <Button
                                            label="Apply"
                                            size="small"
                                            @click="applyCombinedRecommendation(recommendDrawerRow, combo)"
                                        />
                                    </div>
                                </template>
                            </Card>
                        </div>
                    </div>

                    <Divider />

                    <!-- Faculty Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-user mr-1 text-indigo-500"></i>Top Faculty Recommendations
                        </p>
                        <p v-if="recommendDrawerState.faculty?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.faculty.message }}
                        </p>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="rec in recommendDrawerState.faculty?.recommendations ?? []"
                                :key="rec.id"
                                class="border border-slate-200 rounded-lg p-3"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate">{{ rec.name }}</p>
                                        <p class="text-[0.7rem] text-slate-400">{{ rec.faculty_category }} · {{ rec.employment_type }}</p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.65rem]" />
                                        <span class="text-xs font-semibold text-slate-500">{{ rec.score }}%</span>
                                    </div>
                                </div>
                                <Tag
                                    v-if="rec.is_sibling_pattern"
                                    value="Matches sibling section"
                                    severity="info"
                                    icon="pi pi-sparkles"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <Tag
                                    v-if="rec.selected_by_college_match"
                                    value="Selected by College Match"
                                    severity="warning"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <Tag
                                    v-if="rec.selected_by_general_education_match"
                                    value="Selected by General Education Match"
                                    severity="warning"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <ProgressBar
                                    :value="rec.score"
                                    :showValue="false"
                                    style="height: 5px"
                                    :pt="{ value: { style: { backgroundColor: scoreColor(rec.score) } } }"
                                    class="my-2"
                                />
                                <p class="text-[0.7rem] text-slate-500 mb-1.5">
                                    Current Load: {{ rec.current_load }} / {{ rec.max_teaching_units }} Units
                                </p>
                                <ul class="flex flex-wrap gap-x-3 gap-y-0.5 mb-2">
                                    <li
                                        v-for="reason in rec.reasons"
                                        :key="reason.label"
                                        class="text-[0.7rem] flex items-center gap-1"
                                        :class="reason.met ? 'text-emerald-600' : 'text-slate-400'"
                                    >
                                        <i :class="reason.met ? 'pi pi-check-circle' : 'pi pi-times-circle'"></i>{{ reason.label }}
                                    </li>
                                </ul>
                                <div class="flex justify-end">
                                    <Button label="Apply" text size="small" @click="applyFacultyRecommendationFromDrawer(recommendDrawerRow, rec)" />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <Divider />

                    <!-- Room Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-building mr-1 text-indigo-500"></i>Top Room Recommendations
                        </p>
                        <p v-if="recommendDrawerState.room?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.room.message }}
                        </p>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="rec in recommendDrawerState.room?.recommendations ?? []"
                                :key="rec.id"
                                class="border border-slate-200 rounded-lg p-3"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate">{{ rec.name }}</p>
                                        <p class="text-[0.7rem] text-slate-400">
                                            {{ rec.room_category || rec.room_type }} · Capacity {{ rec.capacity }}
                                            <span v-if="rec.department"> · {{ rec.department }}</span>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.65rem]" />
                                        <span class="text-xs font-semibold text-slate-500">{{ rec.score }}%</span>
                                    </div>
                                </div>
                                <Tag
                                    v-if="rec.is_sibling_pattern"
                                    value="Matches sibling section"
                                    severity="info"
                                    icon="pi pi-sparkles"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <ProgressBar
                                    :value="rec.score"
                                    :showValue="false"
                                    style="height: 5px"
                                    :pt="{ value: { style: { backgroundColor: scoreColor(rec.score) } } }"
                                    class="my-2"
                                />
                                <ul class="flex flex-wrap gap-x-3 gap-y-0.5 mb-2">
                                    <li
                                        v-for="reason in rec.reasons"
                                        :key="reason.label"
                                        class="text-[0.7rem] flex items-center gap-1"
                                        :class="reason.type === 'warning' ? 'text-amber-600' : (reason.met ? 'text-emerald-600' : 'text-slate-400')"
                                    >
                                        <i :class="reason.type === 'warning' ? 'pi pi-exclamation-triangle' : (reason.met ? 'pi pi-check-circle' : 'pi pi-times-circle')"></i>{{ reason.label }}
                                    </li>
                                </ul>
                                <div class="flex justify-end">
                                    <Button label="Apply" text size="small" @click="applyRoomRecommendationFromDrawer(recommendDrawerRow, rec)" />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <Divider />

                    <!-- Time Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-clock mr-1 text-indigo-500"></i>Top Time Recommendations
                        </p>
                        <p v-if="recommendDrawerState.time?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.time.message }}
                        </p>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="(rec, idx) in recommendDrawerState.time?.recommendations ?? []"
                                :key="idx"
                                class="border border-slate-200 rounded-lg p-3"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate">
                                            {{ formatDays(rec.days) }} · {{ formatTimeRange(rec.start_time, rec.end_time) }}
                                        </p>
                                        <p class="text-[0.7rem] text-emerald-600">No conflicts detected</p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.65rem]" />
                                        <span class="text-xs font-semibold text-slate-500">{{ rec.score }}%</span>
                                    </div>
                                </div>
                                <ProgressBar
                                    :value="rec.score"
                                    :showValue="false"
                                    style="height: 5px"
                                    :pt="{ value: { style: { backgroundColor: scoreColor(rec.score) } } }"
                                    class="my-2"
                                />
                                <ul class="flex flex-wrap gap-x-3 gap-y-0.5 mb-2">
                                    <li
                                        v-for="reason in rec.reasons"
                                        :key="reason.label"
                                        class="text-[0.7rem] flex items-center gap-1 text-emerald-600"
                                    >
                                        <i class="pi pi-check-circle"></i>{{ reason.label }}
                                    </li>
                                </ul>
                                <div class="flex justify-end">
                                    <Button label="Apply" text size="small" @click="applyTimeRecommendationFromDrawer(recommendDrawerRow, rec)" />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- SIBLING SECTION PATTERN MATCHING — diagnostic trail for
                         this row, showing every sibling donor considered and why
                         each Day candidate was accepted/rejected. Present even
                         when a sibling match WAS found (top Combined suggestion)
                         so the Registrar can see the full reasoning, not just the
                         winning candidate. -->
                    <template v-if="recommendDrawerState.siblingDiagnostics?.length">
                        <Divider />
                        <details>
                            <summary class="text-sm font-semibold text-slate-700 cursor-pointer select-none">
                                <i class="pi pi-copy mr-1 text-indigo-500"></i>Sibling Section Pattern Matching — details
                            </summary>
                            <div class="mt-2 space-y-2">
                                <div
                                    v-for="(donorTrace, dIdx) in recommendDrawerState.siblingDiagnostics"
                                    :key="dIdx"
                                    class="text-xs bg-slate-50 border border-slate-200 rounded-md p-2"
                                >
                                    <p class="font-medium text-slate-700">
                                        Donor: {{ donorTrace.donor_section }}
                                        <span v-if="donorTrace.faculty || donorTrace.room" class="text-slate-400 font-normal">
                                            — {{ donorTrace.faculty || '—' }}, {{ donorTrace.room || '—' }}
                                        </span>
                                    </p>
                                    <p class="text-slate-500">{{ donorTrace.outcome }}</p>
                                    <ul v-if="donorTrace.days_tried?.length" class="mt-1 space-y-0.5">
                                        <li
                                            v-for="(attempt, aIdx) in donorTrace.days_tried"
                                            :key="aIdx"
                                            class="flex items-start gap-1"
                                            :class="attempt.result === 'rejected' ? 'text-red-500' : (attempt.result === 'accepted' ? 'text-emerald-600' : 'text-slate-400')"
                                        >
                                            <i
                                                :class="attempt.result === 'rejected' ? 'pi pi-times-circle' : (attempt.result === 'accepted' ? 'pi pi-check-circle' : 'pi pi-minus-circle')"
                                                class="mt-0.5"
                                            ></i>
                                            <span>
                                                <span class="font-medium">{{ formatDays(attempt.days) }}</span>
                                                — {{ attempt.reason }}
                                            </span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </details>
                    </template>
                </template>

                <p class="text-xs text-slate-400 border-t border-slate-100 pt-3">
                    <i class="pi pi-info-circle mr-1"></i>Recommendations are suggestions only. Applying one only fills in the
                    scheduling table below — click <span class="font-medium">Save Schedule</span> to persist it.
                </p>
            </div>
        </Drawer>

        <!-- Add Subject Dialog -->
        <Dialog
            v-model:visible="addDialogVisible"
            modal
            header="Add Subject"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeAddDialog"
        >
            <Tabs v-model:value="activeTab" class="neu-form">
                <TabList>
                    <Tab value="curriculum">Load From Curriculum</Tab>
                    <Tab value="manual">Manual Selection</Tab>
                </TabList>
                <TabPanels>
                    <!-- Option 1: Load From Curriculum -->
                    <TabPanel value="curriculum">
                        <p class="text-sm text-slate-500 mb-3">
                            Curricula are limited to <span class="font-medium text-slate-700">{{ section.major?.name }}</span>.
                            Year Level and Semester are pre-filled from this section but can be changed.
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Curriculum</label>
                                <Select
                                    v-model="curriculumForm.curriculum_id"
                                    :options="curriculums"
                                    optionLabel="code"
                                    optionValue="id"
                                    filter
                                    placeholder="Select a curriculum"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                >
                                    <template #option="{ option }">
                                        <span class="font-medium">{{ option.code }}</span>
                                        <span class="text-slate-400"> — {{ option.name }}</span>
                                    </template>
                                </Select>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Year Level</label>
                                <Select
                                    v-model="curriculumForm.year_level"
                                    :options="yearLevelOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select year level"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Semester</label>
                                <Select
                                    v-model="curriculumForm.semester"
                                    :options="semesterOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select semester"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                            </div>
                        </div>

                        <Button
                            label="Preview Subjects"
                            icon="pi pi-eye"
                            severity="secondary"
                            outlined
                            :loading="previewLoading"
                            @click="onPreview"
                        />

                        <small v-if="previewError" class="block text-red-500 mt-2">{{ previewError }}</small>
                        <small v-for="(err, idx) in curriculumSaveErrors" :key="idx" class="block text-red-500 mt-1">
                            {{ err }}
                        </small>

                        <!-- Preview table -->
                        <div v-if="hasPreviewed && curriculumPreviewRows.length > 0" class="mt-4">
                            <p class="text-sm font-medium text-slate-700 mb-2">
                                Preview — remove any subjects you don't want before confirming.
                            </p>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-slate-500">
                                        <tr>
                                            <th class="text-left px-3 py-2 font-medium">Code</th>
                                            <th class="text-left px-3 py-2 font-medium">Title</th>
                                            <th class="text-center px-3 py-2 font-medium">Units</th>
                                            <th class="px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr v-for="subject in curriculumPreviewRows" :key="subject.id">
                                            <td class="px-3 py-2 font-medium text-slate-700">{{ subject.subject_code }}</td>
                                            <td class="px-3 py-2 text-slate-600">{{ subject.subject_title }}</td>
                                            <td class="px-3 py-2 text-center text-slate-600">{{ subject.units }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <Button
                                                    icon="pi pi-times"
                                                    text
                                                    rounded
                                                    severity="danger"
                                                    size="small"
                                                    aria-label="Remove from preview"
                                                    @click="removePreviewRow(subject.id)"
                                                />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex justify-end mt-4">
                                <Button
                                    label="Confirm & Add"
                                    icon="pi pi-check"
                                    severity="success"
                                    :loading="curriculumSaving"
                                    @click="onConfirmCurriculumLoad"
                                />
                            </div>
                        </div>
                    </TabPanel>

                    <!-- Option 2: Manual Selection -->
                    <TabPanel value="manual">
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
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
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
                                label="Save"
                                icon="pi pi-check"
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

        <!-- ⚡ Auto Generate Schedule — review panel.
             :focusOnShow="false" — PrimeVue Dialog auto-focuses the
             first focusable element inside it on open by default —
             here that's one of the Faculty/Room AutoComplete inputs.
             That focus event alone was enough to trigger
             RoomRecommendationSelector's/FacultyRecommendationSelector's
             @focus="loadRecommended" (added to fix the previous
             "panels open on mount" bug), popping that row's suggestion
             panel open with nobody having clicked anything — the same
             class of bug, just re-triggered through Dialog's own focus
             trap instead of onMounted(). Stopping the Dialog from
             moving focus into its content at all when it opens is the
             right fix here regardless of what's inside — a review
             panel shouldn't silently steal focus into a random input
             the moment it appears. -->
        <Dialog
            :visible="autoSummaryVisible"
            @update:visible="onAutoSummaryVisibleChange"
            modal
            header="⚡ Auto Schedule Complete"
            :style="{ width: dockedEditSectionSubjectId ? '1100px' : '760px', maxWidth: '95vw', transition: 'width 0.2s ease' }"
            :breakpoints="{ '960px': '95vw', '640px': '98vw' }"
            :draggable="false"
            :focusOnShow="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <!-- Schedule changed while this Auto Schedule review panel was
                 open (spec Section 9). "Accept All & Save" is already
                 disabled for this; this just makes the reason visible
                 inside the modal itself. -->
            <div
                v-if="schedulePolling.isStale.value"
                class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 mb-4 flex items-start gap-2.5"
            >
                <i class="pi pi-exclamation-triangle text-amber-500 mt-0.5"></i>
                <p class="text-sm text-amber-700">
                    Schedule changed while Auto Schedule was being generated. Please refresh before accepting this schedule.
                </p>
            </div>

            <div v-if="autoSummary">
                <div
                    class="rounded-xl neu-inset p-4 mb-4 flex items-center gap-3"
                >
                    <i
                        class="pi text-2xl"
                        :class="autoSummary.scheduled === autoSummary.total ? 'pi-check-circle text-green-600' : 'pi-exclamation-triangle text-amber-600'"
                    ></i>
                    <div>
                        <p class="font-semibold text-slate-800">
                            {{ autoSummary.scheduled }} of {{ autoSummary.total }} subjects scheduled.
                        </p>
                        <p class="text-sm text-slate-600">{{ autoSummary.message }}</p>
                    </div>
                </div>

                <!-- Two-column layout: left = the scrollable Generated
                     Assignments / Requires Manual Scheduling content
                     (unchanged), right = a docked "Edit Day & Time"
                     panel that lives beside the modal's own content
                     instead of a popover floating on top of it.
                     Collapses to a single column below the lg
                     breakpoint (see the responsive note on
                     #autoScheduleTimeDock below) so this never forces
                     an oversized modal on narrower screens — the dock
                     column simply drops beneath the assignments list
                     there instead of sitting beside it. -->
                <div
                    class="grid grid-cols-1 gap-4 items-start transition-[grid-template-columns] duration-200"
                    :class="dockedEditSectionSubjectId ? 'lg:grid-cols-[1fr_300px]' : 'lg:grid-cols-1'"
                >
                    <div class="min-w-0">
                        <!-- Successfully scheduled subjects -->
                        <div v-if="autoSummary.results?.length" class="space-y-3 mb-4">
                            <p class="text-sm font-medium text-slate-700">Generated Assignments</p>
                            <div
                                v-for="result in autoSummary.results"
                                :key="result.section_subject_id"
                                class="rounded-xl p-4"
                                :class="resultHasHardConflict(result) ? 'border border-red-300 bg-red-50' : 'neu-inset'"
                            >
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <p class="font-semibold text-slate-800">
                                        {{ result.subject_code }} <span class="text-slate-400 font-normal">— {{ result.subject_title }}</span>
                                    </p>
                                    <Tag v-if="resultHasHardConflict(result)" value="Scheduling Conflict" severity="danger" icon="pi pi-exclamation-triangle" class="!text-xs shrink-0" />
                                    <Tag v-else-if="result.is_merged" value="Merged" severity="info" class="!text-xs shrink-0" />
                                </div>

                                <!-- Hard conflict banner — names exactly which Section/Subject
                                     already occupies the Faculty/Room/Section slot that was
                                     just picked, so this row must be fixed before "Accept
                                     All & Save" can be used (see blockingConflictRowIds). -->
                                <div
                                    v-if="resultHasHardConflict(result)"
                                    class="mb-3 rounded-lg border border-red-200 bg-red-100/60 px-3 py-2"
                                >
                                    <p v-for="(msg, mIdx) in resultConflictMessages(result)" :key="mIdx" class="text-xs text-red-700">
                                        <i class="pi pi-exclamation-triangle mr-1"></i>{{ msg }}
                                    </p>
                                </div>

                                <!-- INTELLIGENT IRREGULAR SECTION SCHEDULING — merged subjects
                                     don't get their own Faculty/Room/Time selectors; they ride
                                     along on the host Regular section's existing class. -->
                                <div v-if="result.is_merged" class="rounded-lg bg-blue-50 border border-blue-100 p-3">
                                    <p class="text-sm text-slate-700">
                                        <i class="pi pi-sitemap mr-1 text-blue-600"></i>
                                        Merged into <span class="font-semibold">{{ result.merged_into_section_code }}</span>
                                        — {{ result.faculty?.name || '—' }}, {{ result.room?.name || '—' }},
                                        {{ (result.time?.days || []).join('/') }}
                                    </p>
                                    <div class="mt-2">
                                        <Button
                                            label="Merge Recommendation"
                                            icon="pi pi-list"
                                            size="small"
                                            text
                                            @click="openMergeModal(result)"
                                        />
                                    </div>
                                </div>

                                <div v-else>
                                    <!-- SIBLING SECTION PATTERN MATCHING — this is a NEW,
                                         independent ScheduleAssignment for THIS section; only
                                         its Faculty/Room/duration PREFERENCE was based on
                                         another section of the same cohort that already
                                         teaches this exact subject, and the Day was
                                         deliberately changed to avoid conflicting with that
                                         other section's own booking. Nothing was copied or
                                         moved from the donor — see SiblingSectionPatternService.
                                         No badge/explanation shown here on purpose — end users
                                         found "Based on <section>" confusing; result.pattern_source
                                         is still available on the row for anyone who needs it
                                         (e.g. future admin-facing diagnostics). -->

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                        <!-- Faculty — interactive recommendation selector (Prompt 8.11) -->
                                        <div>
                                            <FacultyRecommendationSelector
                                                :section-id="section.id"
                                                :section-subject-id="result.section_subject_id"
                                                :model-value="result.faculty"
                                                :time-signature="timeSignatureFor(result.time)"
                                                @updated="onFacultyOverride(result, $event)"
                                            />
                                        </div>

                                        <!-- Room — interactive recommendation selector, same click-to-edit/search flow as Faculty -->
                                        <div>
                                            <RoomRecommendationSelector
                                                :section-id="section.id"
                                                :section-subject-id="result.section_subject_id"
                                                :model-value="result.room"
                                                :time-signature="timeSignatureFor(result.time)"
                                                @updated="onRoomOverride(result, $event)"
                                            />
                                        </div>

                                        <!-- Time — interactive recommendation selector. dock-target
                                             routes its "Edit Day & Time" panel into the right-side
                                             column below instead of a popover, exactly like Faculty/
                                             Room stay click-to-edit inline; only Time needed the dock
                                             since its editor is by far the largest of the three. -->
                                        <div>
                                            <TimeRecommendationSelector
                                                :section-id="section.id"
                                                :section-subject-id="result.section_subject_id"
                                                :model-value="result.time"
                                                :scheduling-window="schedulingWindow"
                                                dock-target="#autoScheduleTimeDock"
                                                @updated="onTimeOverride(result, $event)"
                                            />
                                        </div>

                                        <div v-if="isIrregularSection" class="sm:col-span-3">
                                            <Button
                                                label="Merge Recommendation"
                                                icon="pi pi-sitemap"
                                                size="small"
                                                text
                                                @click="openMergeModal(result)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Subjects that need manual scheduling -->
                        <div v-if="autoSummary.unresolved?.length">
                            <p class="text-sm font-medium text-slate-700 mb-2">Requires Manual Scheduling</p>
                            <div class="space-y-2">
                                <div
                                    v-for="item in autoSummary.unresolved"
                                    :key="item.section_subject_id"
                                    class="border border-amber-200 bg-amber-50 rounded-lg p-3 flex items-start gap-2"
                                >
                                    <i class="pi pi-exclamation-triangle text-amber-600 mt-0.5"></i>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-slate-800">
                                            {{ item.subject_code }} — {{ item.subject_title }}
                                        </p>
                                        <p class="text-xs text-slate-600">{{ item.reason }}</p>
                                        <ul v-if="item.reason_details?.length" class="mt-1 space-y-0.5">
                                            <li v-for="(detail, idx) in item.reason_details" :key="idx" class="text-xs text-slate-500">
                                                • {{ detail }}
                                            </li>
                                        </ul>

                                        <!-- SIBLING SECTION PATTERN MATCHING — diagnostic trail
                                             showing exactly why this row could NOT inherit a
                                             sibling section's Faculty/Room/Duration pattern
                                             (which donor(s) were considered, which Day
                                             candidates were tried, and the exact Section/
                                             Faculty/Room conflict that rejected each one). -->
                                        <details v-if="item.sibling_pattern_diagnostics?.length" class="mt-2">
                                            <summary class="text-xs text-amber-700 cursor-pointer select-none">
                                                <i class="pi pi-info-circle mr-1"></i>Why wasn't a sibling section's pattern used?
                                            </summary>
                                            <div class="mt-1.5 space-y-2 pl-1">
                                                <div
                                                    v-for="(donorTrace, dIdx) in item.sibling_pattern_diagnostics"
                                                    :key="dIdx"
                                                    class="text-xs bg-white border border-amber-100 rounded-md p-2"
                                                >
                                                    <p class="font-medium text-slate-700">
                                                        Donor: {{ donorTrace.donor_section }}
                                                        <span v-if="donorTrace.faculty || donorTrace.room" class="text-slate-400 font-normal">
                                                            — {{ donorTrace.faculty || '—' }}, {{ donorTrace.room || '—' }}
                                                        </span>
                                                    </p>
                                                    <p class="text-slate-500">{{ donorTrace.outcome }}</p>
                                                    <ul v-if="donorTrace.days_tried?.length" class="mt-1 space-y-0.5">
                                                        <li
                                                            v-for="(attempt, aIdx) in donorTrace.days_tried"
                                                            :key="aIdx"
                                                            class="flex items-start gap-1"
                                                            :class="attempt.result === 'rejected' ? 'text-red-500' : (attempt.result === 'accepted' ? 'text-emerald-600' : 'text-slate-400')"
                                                        >
                                                            <i
                                                                :class="attempt.result === 'rejected' ? 'pi pi-times-circle' : (attempt.result === 'accepted' ? 'pi pi-check-circle' : 'pi pi-minus-circle')"
                                                                class="mt-0.5"
                                                            ></i>
                                                            <span>
                                                                <span class="font-medium">{{ formatDays(attempt.days) }}</span>
                                                                — {{ attempt.reason }}
                                                            </span>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </details>

                                        <Button
                                            v-if="isIrregularSection"
                                            label="Merge Recommendation"
                                            icon="pi pi-sitemap"
                                            size="small"
                                            text
                                            class="mt-1 !p-0"
                                            @click="openMergeModal(item)"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right column — Edit Day & Time dock. Sticky so it
                         stays level with wherever the modal is scrolled to.
                         Empty/placeholder state when no subject's Time is
                         currently being edited; TimeRecommendationSelector
                         teleports the actual "Edit Day & Time" panel in
                         here the moment a Time trigger above is clicked
                         (see dockedEditSectionSubjectId / useTimeEditDock.js). -->
                    <div v-show="dockedEditSectionSubjectId" id="autoScheduleTimeDock" class="lg:sticky lg:top-0"></div>
                </div>
            </div>

            <template #footer>
                <Button label="Discard Suggestions" icon="pi pi-eraser" severity="secondary" outlined :loading="autoClearing" @click="clearAutoSchedule" />
                <Button label="Regenerate" icon="pi pi-refresh" severity="warning" outlined :loading="autoGenerating" @click="regenerateAutoSchedule" />
                <Button
                    label="Accept All & Save"
                    icon="pi pi-check"
                    severity="success"
                    :loading="savingSchedule"
                    :disabled="blockingConflictRowIds.size > 0 || schedulePolling.isStale.value"
                    :title="schedulePolling.isStale.value ? 'Schedule changed while Auto Schedule was being generated. Please refresh before accepting.' : undefined"
                    @click="acceptAutoSchedule"
                />
            </template>
        </Dialog>

        <!-- INTELLIGENT IRREGULAR SECTION SCHEDULING — Merge Recommendation modal -->
        <MergeRecommendationModal
            v-model:visible="mergeModalVisible"
            :loading="mergeModalLoading"
            :applying="mergeModalApplying"
            :subject-code="mergeModalResult?.subject_code"
            :subject-title="mergeModalResult?.subject_title"
            :recommendation="mergeModalRecommendation"
            @choose-candidate="chooseMergeCandidate"
            @choose-independent="chooseIndependentSchedule"
        />
    </AppLayout>
</template>

<style scoped>
/* Header action buttons (Discard/Clear/Print/Auto Generate/Save) —
   fuller pill radius than the site-wide 8px default so this busy
   button row reads as one cohesive, more modern group. */
.section-actions :deep(.p-button) {
    border-radius: 9999px !important;
}

/* Conflicted rows (red) are click-to-resolve — opens the Smart
   Schedule Recommendation drawer for that row (see openRecommendDrawer). */
.schedule-table :deep(.conflict-row-clickable) {
    cursor: pointer;
}
.schedule-table :deep(.conflict-row-clickable:hover) {
    background-color: rgb(254 226 226) !important; /* red-100 */
}

/* "Not yet scheduled" rows — a Draft row with no Faculty/Room/Days/
   Time assigned at all. Applied to the row's inner content wrapper
   (not the <tr> itself — table rows don't reliably paint left
   borders across browsers due to border-collapse behavior) so the
   dashed accent + tint actually render. Baby-blue so it's clearly
   visible against the neutral table background, distinct from
   conflict/warning rows (red/amber) — an empty row reads as "still
   needs setup", not an error. */
.schedule-table :deep(.unscheduled-row) {
    background-color: #EFF6FF !important; /* blue-50 */
    border-left: 3px dashed #93C5FD !important; /* blue-300 */
}
.schedule-table :deep(.unscheduled-field.p-select),
.schedule-table :deep(.unscheduled-field.p-multiselect),
.schedule-table :deep(.unscheduled-field .p-datepicker-input) {
    border-style: dashed !important;
    border-color: #93C5FD !important; /* blue-300 */
    background-color: #F0F7FF !important;
}

.schedule-table :deep(.p-select),
.schedule-table :deep(.p-multiselect),
.schedule-table :deep(.p-datepicker-input) {
    font-size: 0.8rem;
}

/* Slightly tighter row height/padding + smaller base font so more of
   the table is visible at once and it's easier to scan/read. */
.schedule-table :deep(.p-datatable-thead > tr > th) {
    padding: 0.6rem 0.75rem;
    font-size: 0.8rem;
}
.schedule-table :deep(.p-datatable-tbody > tr > td) {
    padding: 0.5rem 0.75rem;
    font-size: 0.8rem;
}
.schedule-table :deep(.p-select-label),
.schedule-table :deep(.p-multiselect-label),
.schedule-table :deep(.p-inputtext) {
    padding-top: 0.4rem;
    padding-bottom: 0.4rem;
}

/* Dark-mode overrides. Wrapping the page body in the "dark-scope" class
   (added conditionally via isDark) recolors PrimeVue chrome and the
   Tailwind utility classes used throughout the scheduling table, the
   summary card, the Popovers/Drawer, and both Dialogs. Popovers/Drawer/
   Dialog/Select overlay panels are teleported to <body>, so they're
   handled with their own :pt="{ ... : isDark ? 'dark-scope' : '' }"
   props above and matched here via :global(). */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-800) { color: #F1F5F9 !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.hover\:text-slate-700:hover) { color: #E2E8F0 !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.divide-slate-100 > :not([hidden]) ~ :not([hidden])) { border-color: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.05) !important; }
.dark-scope :deep(.bg-green-50) { background-color: rgba(16, 185, 129, 0.12) !important; }
.dark-scope :deep(.border-green-200) { border-color: rgba(16, 185, 129, 0.35) !important; }
.dark-scope :deep(.text-green-600) { color: #34D399 !important; }
.dark-scope :deep(.bg-amber-50) { background-color: rgba(217, 119, 6, 0.14) !important; }
.dark-scope :deep(.border-amber-200) { border-color: rgba(217, 119, 6, 0.35) !important; }
.dark-scope :deep(.unscheduled-row) {
    background-color: rgba(59, 130, 246, 0.10) !important; /* blue-500 @10% */
    border-left-color: rgba(96, 165, 250, 0.55) !important; /* blue-400 */
}
.dark-scope :deep(.unscheduled-field.p-select),
.dark-scope :deep(.unscheduled-field.p-multiselect),
.dark-scope :deep(.unscheduled-field .p-datepicker-input) {
    border-color: rgba(96, 165, 250, 0.45) !important;
    background-color: rgba(59, 130, 246, 0.08) !important;
}
.dark-scope :deep(.text-amber-600) { color: #FBBF24 !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-datepicker-input) {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-select-label.p-placeholder),
.dark-scope :deep(.p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-multiselect-chip .p-chip) { background: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }

/* Conflict / capacity-warning / unsaved (dirty) rows keep a light
   pink or amber background even in dark mode (see :rowClass above) so
   they read as a warning against the otherwise-dark table. The
   general dark-mode field styling above (near-white text on a
   near-transparent white fill) is invisible against that light row
   background — this restores normal dark text and a solid white
   field fill specifically inside those rows. */
.dark-scope :deep(tr.row-light-bg .p-inputtext),
.dark-scope :deep(tr.row-light-bg .p-select),
.dark-scope :deep(tr.row-light-bg .p-multiselect),
.dark-scope :deep(tr.row-light-bg .p-datepicker-input) {
    background: #ffffff !important;
    border-color: rgba(15, 23, 42, 0.15) !important;
    color: #1E293B !important;
}
.dark-scope :deep(tr.row-light-bg .p-select-label),
.dark-scope :deep(tr.row-light-bg .p-multiselect-label) { color: #1E293B !important; }
.dark-scope :deep(tr.row-light-bg .p-select-label.p-placeholder),
.dark-scope :deep(tr.row-light-bg .p-multiselect-label.p-placeholder) { color: #64748B !important; }
.dark-scope :deep(tr.row-light-bg .p-inputtext::placeholder) { color: #64748B !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.03) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #F1F5F9 !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; background: transparent !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }
.dark-scope :deep(.p-progressbar) { background: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(table.w-full) { color: #F1F5F9 !important; }
.dark-scope :deep(table.w-full thead) { background: rgba(255, 255, 255, 0.06) !important; color: #94A3B8 !important; }
.dark-scope :deep(.border.border-slate-200.rounded-lg) { border-color: rgba(255, 255, 255, 0.12) !important; }

/* Dialogs, Drawer, Popovers, and Select/MultiSelect/DatePicker overlay
   panels are teleported to <body>, so they need :global() rather than
   :deep() to be reached from this scoped style block. */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-slate-500),
:global(.dark-scope.p-dialog p.text-slate-400),
:global(.dark-scope.p-dialog small.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .font-medium.text-slate-700) { color: #E2E8F0 !important; }
:global(.dark-scope.p-dialog .p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-tab) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-tab-active) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-tabpanels) { background: transparent !important; }
:global(.dark-scope.p-dialog .p-tabpanel) { background: transparent !important; color: #F1F5F9 !important; }
:global(.dark-scope.p-dialog .p-tabpanel p),
:global(.dark-scope.p-dialog .p-tabpanel span),
:global(.dark-scope.p-dialog .p-tabpanel div) { color: inherit; }
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-700) { color: #E2E8F0 !important; }
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-600) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-500),
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-tabpanel label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect) {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog table.w-full) { color: #F1F5F9 !important; }
:global(.dark-scope.p-dialog table.w-full thead) { background: rgba(255, 255, 255, 0.06) !important; color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .border.border-slate-200.rounded-lg) { border-color: rgba(255, 255, 255, 0.12) !important; }
:global(.dark-scope.p-dialog .divide-slate-100 > *) { border-color: rgba(255, 255, 255, 0.08) !important; }

:global(.dark-scope.p-drawer) { background: #0F1730 !important; color: #F8FAFC !important; border-left: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-drawer .p-drawer-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-drawer .p-drawer-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-drawer .text-slate-800) { color: #F1F5F9 !important; }
:global(.dark-scope.p-drawer .text-slate-700) { color: #CBD5E1 !important; }
:global(.dark-scope.p-drawer .text-slate-500),
:global(.dark-scope.p-drawer .text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-drawer .bg-slate-50) { background-color: rgba(255, 255, 255, 0.06) !important; }

:global(.dark-scope.p-popover) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; }
:global(.dark-scope.p-popover:before),
:global(.dark-scope.p-popover:after) { border-bottom-color: #0F1730 !important; }
:global(.dark-scope.p-popover .text-slate-600) { color: #CBD5E1 !important; }

:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-option-group),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option-group) { background: rgba(255, 255, 255, 0.04) !important; }
:global(.p-select-overlay.dark-scope .p-select-filter),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-filter) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; border-color: rgba(255, 255, 255, 0.15) !important; }
:global(.p-select-overlay.dark-scope .p-select-empty-message),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-empty-message) { color: #94A3B8 !important; }

:global(.p-datepicker-panel.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-calendar td span) { color: #F1F5F9 !important; }
</style>