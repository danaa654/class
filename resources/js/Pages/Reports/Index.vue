<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import InfoPopover from '@/Components/InfoPopover.vue';
import SendScheduleModal from './Partials/SendScheduleModal.vue';
import BulkSendScheduleModal from './Partials/BulkSendScheduleModal.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message —
// e.g. FacultyScheduleEmailController's back()->with('success', ...)
// after "Send Schedule". Without this the modal just closes with no
// feedback that anything actually happened.
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

// FACULTY SCHEDULE EMAIL SYSTEM — "Send via Email" on the single-faculty
// Schedule by Faculty report. report.facultyMeta (set by
// ReportsService::scheduleByFaculty() only when exactly one faculty is
// selected) carries everything the modal needs; it rides alongside the
// generic {columns, rows} table rather than inside a row, so it never
// shows up as a spurious column.
const showSendScheduleModal = ref(false);

const sendScheduleFaculty = computed(() => {
    const meta = props.report?.facultyMeta;
    if (!meta) return null;
    return {
        id: meta.id,
        full_name: meta.full_name,
        faculty_id: meta.faculty_id,
        email: meta.email,
    };
});

const sendScheduleTerm = computed(() => {
    const meta = props.report?.facultyMeta;
    if (!meta || !meta.academic_term_id) return null;
    return {
        id: meta.academic_term_id,
        label: `${form.value.academic_year} · ${form.value.semester}`,
    };
});

function openSendScheduleModal() {
    if (!sendScheduleFaculty.value || !sendScheduleTerm.value) return;
    showSendScheduleModal.value = true;
}

// "Send All" — bulk-sends to whatever's currently filtered/selected on
// this report instead of one faculty at a time. Reuses facultyIds (the
// multi-select already driving the table) and report.termId (exposed
// by ReportsService::generate() regardless of how many faculty are
// selected, unlike facultyMeta which only exists for exactly one).
const showBulkSendModal = ref(false);

const bulkSendTerm = computed(() => {
    const termId = props.report?.termId;
    if (!termId) return null;
    return {
        id: termId,
        label: `${form.value.academic_year} · ${form.value.semester}`,
    };
});

const bulkSendScopeLabel = computed(() => {
    if (facultyIds.value.length > 0) {
        if (facultyIds.value.length === 1) {
            const match = props.filterOptions.faculty.find((f) => f.id === facultyIds.value[0]);
            return match?.name || '1 selected faculty';
        }
        return `${facultyIds.value.length} selected faculty`;
    }
    if (form.value.college_id) {
        const college = props.filterOptions.colleges.find((c) => c.id === form.value.college_id);
        return college ? `All finalized faculty in ${college.name}` : 'All Active Faculty';
    }
    return 'All Active Faculty (entire school)';
});

// Maps the College/Program filter to what bulkSend() actually accepts
// server-side: 'gened' for the General Education Faculty pseudo-option
// (no real college_id), the numeric college id, or null for "no
// College narrowing" (all programs). Only takes effect when facultyIds
// is empty — an explicit multi-select always wins server-side too.
const bulkSendCollegeId = computed(() => {
    if (form.value.college_id === GENED_COLLEGE_VALUE) return 'gened';
    return form.value.college_id || null;
});

// Only shown when the single-faculty flow doesn't apply — i.e. zero or
// several faculty selected, matching how facultyMeta/sendScheduleFaculty
// only populates for exactly one.
const showBulkSendButton = computed(() => reportType.value === 'schedule_by_faculty' && !sendScheduleFaculty.value);

function openBulkSendModal() {
    if (!bulkSendTerm.value) return;
    showBulkSendModal.value = true;
}

function onBulkSent() {
    showBulkSendModal.value = false;
    router.reload({ only: ['report'] });
}

function onScheduleSent() {
    showSendScheduleModal.value = false;
    router.reload({ only: ['report'] });
}

const props = defineProps({
    filterOptions: { type: Object, required: true },
    filters: { type: Object, required: true },
    termOptions: { type: Array, default: () => [] },
    reportType: { type: String, default: '' },
    summary: { type: Object, required: true },
    report: { type: Object, default: null },
    generatedAt: { type: String, default: '' },
    // Same shape as RoomGrid.vue's own schedulingWindow prop (Class
    // Start/End Time, Time Interval, Available Days, fixed Lunch
    // Break) — reused here so the Reports Grid view lays classes out
    // on the exact same 30-min-row grid the scheduling screens use,
    // for whichever School Year the selected Academic Year belongs to.
    schedulingWindow: {
        type: Object,
        default: () => ({
            start_time: '07:00',
            end_time: '17:00',
            available_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            interval_minutes: 30,
            lunch_start: '12:00',
            lunch_end: '13:00',
        }),
    },
});

// --- Local filter state (mirrors querystring) ---
const form = ref({
    academic_year: props.filters.academic_year || '',
    semester: props.filters.semester || '',
    college_id: props.filters.college_id || '',
    major_id: props.filters.major_id || '',
    year_level: props.filters.year_level || '',
    // Single-select value, used by every report type except Schedule by
    // Section (see section_ids below + sectionIsMultiple).
    section_id: Array.isArray(props.filters.section_id) ? '' : (props.filters.section_id || ''),
    section_type: props.filters.section_type || '',
    faculty_id: props.filters.faculty_id || '',
    room_id: props.filters.room_id || '',
});
const reportType = ref(props.reportType || '');

// Schedule by Section can print several specific, possibly non-contiguous
// sections at once (e.g. BSIT-1, BSIT-3, BSIT-4 — skipping BSIT-2), each
// printed as its own separate block/page rather than merged into one
// continuous table. Kept as its own array field (separate from the plain
// single-select `section_id` every other report still uses) so the two
// selection modes never collide.
const sectionIds = ref(Array.isArray(props.filters.section_id) ? props.filters.section_id : (props.filters.section_id ? [props.filters.section_id] : []));
// Same multi-pick-and-print-separately flow as Schedule by Section, but
// for Schedule by Faculty — pick several faculty at once, each printed as
// its own separate block/page.
const facultyIds = ref(Array.isArray(props.filters.faculty_id) ? props.filters.faculty_id : (props.filters.faculty_id ? [props.filters.faculty_id] : []));

// Academic Term quick filter — same "{academic_year}|{semester}" value
// shape and "All Terms" / Archived-tagged options as the Sections page
// (see SectionController@termFilterOptions). Choosing a term just fills
// in the existing Academic Year + Semester selects below it; it doesn't
// bypass them, so either can still be fine-tuned independently before
// clicking Generate Report.
const selectedTerm = ref(props.filters.term || 'all');

function onTermChange() {
    if (selectedTerm.value === 'all') {
        form.value.academic_year = '';
        form.value.semester = '';
        return;
    }

    const [termYear, termSemester] = selectedTerm.value.split('|');
    form.value.academic_year = termYear ?? '';
    form.value.semester = termSemester ?? '';
}

const academicYearOptions = computed(() => props.filterOptions.academicYears.map((v) => ({ label: v, value: v })));
const semesterOptions = computed(() => props.filterOptions.semesters.map((v) => ({ label: v, value: v })));
// Pseudo College/Program value: "General Education Faculty" — GenEd/Minor
// faculty (college_id null) don't belong to any real College, so they get
// their own explicit entry in this dropdown instead of being silently
// mixed into every real College's faculty list.
const GENED_COLLEGE_VALUE = '__gened__';
// "General Education Faculty" only makes sense as a College/Program choice
// when picking Faculty (Schedule by Faculty) — Schedule by Section and
// every other report filters by real Programs/Sections, which GenEd
// doesn't have, so the entry is hidden there.
const collegeOptions = computed(() => [
    { label: 'All Programs', value: '' },
    ...(needsFaculty.value ? [{ label: 'General Education Faculty', value: GENED_COLLEGE_VALUE }] : []),
    ...props.filterOptions.colleges.map((c) => ({ label: c.name, value: c.id })),
]);
const majorOptions = computed(() => {
    if (form.value.college_id === GENED_COLLEGE_VALUE) return [{ label: 'All Majors', value: '' }];
    const college = props.filterOptions.colleges.find((c) => c.id === form.value.college_id);
    const majors = college ? college.majors : props.filterOptions.colleges.flatMap((c) => c.majors);
    return [{ label: 'All Majors', value: '' }, ...majors.map((m) => ({ label: m.name, value: m.id }))];
});
const yearLevelOptions = computed(() => [{ label: 'All Year Levels', value: '' }, ...props.filterOptions.yearLevels.map((v) => ({ label: v, value: v }))]);
// Section dropdown narrows to whatever's currently selected above it
// (Academic Year, Semester, College/Program → Major, Year Level,
// Section Type) — mirrors majorOptions' self-filtering pattern just
// above. Without this, every Section across every term/college ever
// created shows here (including duplicate-looking codes like two
// different terms both having a "BSIT-4A"), which is confusing and
// lets someone pick a Section that doesn't even match their other
// filters. collegeToMajorIds resolves College/Program → Major ids
// since Section only stores major_id, not college_id directly.
const collegeToMajorIds = computed(() => {
    // GenEd Faculty has no majors/sections of its own — treat it like "no
    // College selected" here so it doesn't wipe out the Section list.
    if (!form.value.college_id || form.value.college_id === GENED_COLLEGE_VALUE) return null;
    const college = props.filterOptions.colleges.find((c) => c.id === form.value.college_id);
    return college ? new Set(college.majors.map((m) => m.id)) : new Set();
});
const sectionOptions = computed(() => {
    const filtered = props.filterOptions.sections.filter((s) => {
        if (form.value.academic_year && s.academic_year !== form.value.academic_year) return false;
        if (form.value.semester && s.semester !== form.value.semester) return false;
        if (form.value.major_id && s.major_id !== form.value.major_id) return false;
        if (!form.value.major_id && collegeToMajorIds.value && !collegeToMajorIds.value.has(s.major_id)) return false;
        if (form.value.year_level && s.year_level !== form.value.year_level) return false;
        if (form.value.section_type && s.section_type !== form.value.section_type) return false;
        return true;
    });
    return [{ label: 'All Sections', value: '' }, ...filtered.map((s) => ({ label: s.section_code, value: s.id }))];
});

// If a previously selected Section no longer matches the current
// Academic Year/Semester/College/Major/Year Level/Section Type
// filters (e.g. the person picked a Section, then changed Semester),
// clear it rather than silently keep an invalid, now-hidden selection.
watch(sectionOptions, (options) => {
    if (form.value.section_id && ! options.some((o) => o.value === form.value.section_id)) {
        form.value.section_id = '';
    }
    const validIds = new Set(options.map((o) => o.value));
    sectionIds.value = sectionIds.value.filter((id) => validIds.has(id));
});
const sectionTypeOptions = [{ label: 'All Types', value: '' }, { label: 'Regular', value: 'Regular' }, { label: 'Irregular', value: 'Irregular' }];
const facultyOptions = computed(() => {
    // "General Education Faculty" picked: show only GenEd/Minor faculty
    // (college_id null). A real College picked: show ONLY that College's
    // own faculty — GenEd is no longer auto-mixed in, since it's now its
    // own explicit choice above. No College picked: everyone.
    const filtered = props.filterOptions.faculty.filter((f) => {
        if (form.value.college_id === GENED_COLLEGE_VALUE) return f.college_id === null;
        if (form.value.college_id) return f.college_id === form.value.college_id;
        return true;
    });
    return [{ label: 'All Faculty', value: '' }, ...filtered.map((f) => ({ label: f.name, value: f.id }))];
});

// If a previously selected/picked Faculty no longer belongs to the
// currently-selected College, drop it — mirrors the Section watcher below.
watch(facultyOptions, (options) => {
    const validIds = new Set(options.map((o) => o.value));
    if (form.value.faculty_id && !validIds.has(form.value.faculty_id)) {
        form.value.faculty_id = '';

    }
    facultyIds.value = facultyIds.value.filter((id) => validIds.has(id));
});
const roomOptions = computed(() => {
    // Same self-narrowing pattern as Faculty: rooms with no college_id
    // (gym, shared lecture halls — see Room::college()) are open to
    ///every College and always stay listed; otherwise only the
    // selected College/Program's own rooms show up, so "Schedule by
    // Room" for CCS doesn't list Criminology's rooms.
    const filtered = props.filterOptions.rooms.filter((r) => {
        if (!form.value.college_id || form.value.college_id === GENED_COLLEGE_VALUE) return true;
        return r.college_id === null || r.college_id === form.value.college_id;
    });
    return [{ label: 'All Rooms', value: '' }, ...filtered.map((r) => ({ label: r.room_name, value: r.id }))];
});

// If a previously picked Room no longer belongs to the currently-selected
// College, drop it — mirrors the Section/Faculty watchers above.
watch(roomOptions, (options) => {
    const validIds = new Set(options.map((o) => o.value));
    if (form.value.room_id && !validIds.has(form.value.room_id)) {
        form.value.room_id = '';
    }
});

const reportTypeOptions = computed(() => {
    const groups = [];
    for (const [group, entries] of Object.entries(props.filterOptions.reportGroups)) {
        groups.push({
            label: group,
            items: Object.entries(entries).map(([value, label]) => ({ label, value })),
        });
    }
    return groups;
});

// Which selectors are relevant to the currently chosen report
const needsFaculty = computed(() => ['schedule_by_faculty', 'faculty_teaching_load'].includes(reportType.value));
// If "General Education Faculty" is selected and the report type is then
// switched away from Schedule by Faculty (where collegeOptions drops that
// entry), clear it back to "All Programs" rather than leave a College
// filter pointing at a value that no longer exists in the dropdown.
watch(collegeOptions, (options) => {
    if (form.value.college_id && !options.some((o) => o.value === form.value.college_id)) {
        form.value.college_id = '';
    }
});
const facultyIsMultiple = computed(() => reportType.value === 'schedule_by_faculty');
const facultyMultiOptions = computed(() => facultyOptions.value.filter((o) => o.value !== ''));

watch(facultyIsMultiple, (isMultiple) => {
    if (!isMultiple) facultyIds.value = [];
});
const needsRoom = computed(() => ['schedule_by_room', 'room_utilization', 'room_conflicts'].includes(reportType.value));
// Grid (weekly-timetable-style) view, for the two report types whose rows
// actually carry Day/Start/End — Schedule by Room and Schedule by Faculty.
// Room Utilization/Conflicts and Faculty Teaching Load are plain summary
// tables (no per-slot Day/Time), so the toggle only shows for the two
// schedule reports themselves.
// Grid only makes sense scoped to ONE Room or ONE Faculty member at a
// time — mixing several into one grid would silently overlap/misattribute
// cells (e.g. Room A's 1–5pm class and Room B's own 1–5pm class would
// land in the identical grid cell with no way to tell them apart). So
// Grid is only offered once a specific single pick is made:
// - Schedule by Faculty always uses the multi-select (facultyIds) — Grid
//   requires exactly one faculty checked, not "All Faculty" and not
//   several at once.
// - Schedule by Room uses the plain single Select (form.room_id) — Grid
//   requires a specific Room chosen, not the "All Rooms" default.
const gridEnabled = computed(() => {
    if (reportType.value === 'schedule_by_faculty') return facultyIds.value.length === 1;
    if (reportType.value === 'schedule_by_room') return !!form.value.room_id;
    return false;
});
const viewMode = ref('table');
watch(gridEnabled, (enabled) => {
    if (!enabled) viewMode.value = 'table';
});

// Same 30-min-row + fixed-lunch-break grid math as
// Components/Scheduling/RoomGrid.vue (read-only mirror — no drag/drop,
// no writes), driven by the schedulingWindow prop for whichever School
// Year the selected Academic Year belongs to.
const GRID_DAY_LABELS = { Mon: 'Mon', Tue: 'Tue', Wed: 'Wed', Thu: 'Thu', Fri: 'Fri', Sat: 'Sat', Sun: 'Sun' };
const gridAllDays = computed(() => (props.schedulingWindow.available_days?.length ? props.schedulingWindow.available_days : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']));

const toMinutes24 = (hhmm) => {
    const [h, m] = (hhmm || '00:00').split(':').map(Number);
    return h * 60 + (m || 0);
};
const toHHMM = (minutes) => {
    const h = Math.floor(minutes / 60) % 24;
    const m = minutes % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
};
const formatHourLabel = (hhmm) => {
    const [h, m] = (hhmm || '00:00').split(':').map(Number);
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
};
// "5:00 PM" -> 1020 (minutes since midnight) — report rows carry the
// already-12h-formatted string from the backend, not a raw sortable
// "H:i" value, so this parses it back into the same minute space
// toMinutes24()/hourRows use.
const timeToMinutes12h = (label) => {
    const match = /^(\d{1,2}):(\d{2})\s*(AM|PM)$/i.exec((label || '').trim());
    if (!match) return null;
    let hours = parseInt(match[1], 10) % 12;
    if (match[3].toUpperCase() === 'PM') hours += 12;
    return hours * 60 + parseInt(match[2], 10);
};

const gridIntervalMinutes = computed(() => props.schedulingWindow.interval_minutes || 30);

// Lunch Break restriction removed per adviser direction — always false.
const gridIsLunchRow = () => false;
const gridFormatSlotRange = (hhmm) => `${formatHourLabel(hhmm)} – ${formatHourLabel(toHHMM(toMinutes24(hhmm) + gridIntervalMinutes.value))}`;

const gridHourRows = computed(() => {
    const start = toMinutes24(props.schedulingWindow.start_time || '07:00');
    const end = toMinutes24(props.schedulingWindow.end_time || '17:00');
    const step = gridIntervalMinutes.value;
    const rows = [];
    for (let t = start; t < end; t += step) rows.push(toHHMM(t));
    return rows.length ? rows : ['07:00', '07:30', '08:00'];
});

const gridLunchRowIndices = computed(() => gridHourRows.value.reduce((acc, hour, idx) => (gridIsLunchRow(hour) ? [...acc, idx] : acc), []));
const gridIsFirstLunchRow = (rowIndex) => gridLunchRowIndices.value[0] === rowIndex;
const gridLunchSpan = computed(() => gridLunchRowIndices.value.length || 1);
const gridLunchRangeLabel = computed(() => `${formatHourLabel(props.schedulingWindow.lunch_start || '12:00')} – ${formatHourLabel(props.schedulingWindow.lunch_end || '13:00')}`);

// A row may carry more than one meeting — a Face-to-Face/Online split
// (or a Lecture/Lab pair) rides as a 'Schedules' array alongside the
// flat Day/Start/End/Room fields (see ReportsService::scheduleByFaculty()).
// The Grid needs every meeting the row actually has, not just the first
// (Face-to-Face) one those flat fields reflect — this expands each row
// into its full list of {Room, Day, Start, End} meetings, falling back
// to the flat fields themselves for report types that never split.
const rowSchedules = (row) => (row.Schedules?.length ? row.Schedules : [{ Room: row.Room, Day: row.Day, Start: row.Start, End: row.End }]);

// Only the days that actually appear in this report's rows, in
// calendar order — a room/faculty with no Saturday classes doesn't
// need an empty Saturday column.
const gridDays = computed(() => {
    const present = new Set();
    (props.report?.rows ?? []).forEach((row) => rowSchedules(row).forEach((s) => (s.Day || '').split(',').map((d) => d.trim()).filter(Boolean).forEach((d) => present.add(d))));
    return gridAllDays.value.filter((d) => present.has(d));
});

// One block per (day, meeting) this report's rows actually occupy —
// startIndex/span computed the same way RoomGrid.vue computes them for
// its own draggable blocks, just read-only here. A split class (e.g.
// Face-to-Face Mon + Online Fri) contributes one block per meeting, each
// carrying its own Room (so the Online meeting's cell reads "Online"
// instead of silently reusing the Face-to-Face room).
const gridBlocks = computed(() => {
    const rows = props.report?.rows ?? [];
    const gridStart = toMinutes24(gridHourRows.value[0] || '07:00');
    const step = gridIntervalMinutes.value;
    const blocks = [];

    rows.forEach((row) => {
        rowSchedules(row).forEach((schedule) => {
            const startMin = timeToMinutes12h(schedule.Start);
            const endMin = timeToMinutes12h(schedule.End);
            if (startMin === null || endMin === null) return;

            const startIndex = Math.max(0, Math.round((startMin - gridStart) / step));
            const span = Math.max(1, Math.round((endMin - startMin) / step));

            (schedule.Day || '').split(',').map((d) => d.trim()).filter(Boolean).forEach((day) => {
                if (!gridDays.value.includes(day)) return;
                blocks.push({ ...row, ...schedule, day, startIndex, span });
            });
        });
    });

    // MERGED / SHARED SESSIONS — a faculty-shortage merge (e.g. a single
    // Online lecture run for two independent Sections, BSIT-1A and
    // BSIT-1B, at the same time) produces one block per Section from the
    // loop above, both landing in the exact same cell. Left as two
    // separate blocks, the grid could only ever show one (whichever the
    // template happened to look up), silently hiding the other Section.
    // Collapse same-cell, same-meeting blocks (same day/time/room/subject)
    // into a single block whose Section reads as "BSIT-1A/BSIT-1B" —
    // same slash-joined convention as the "Merge" indicator below —
    // rather than losing one Section's half of the class entirely.
    const mergedBlocks = [];
    const seen = new Map();
    blocks.forEach((block) => {
        const key = [block.day, block.startIndex, block.span, block.Room, block.Subject].join('|');
        const existing = seen.get(key);
        if (existing) {
            const sections = existing.Section.split('/');
            if (!sections.includes(block.Section)) {
                existing.Section = `${existing.Section}/${block.Section}`;
                existing.merged = true;
            }
            return;
        }
        const copy = { ...block };
        seen.set(key, copy);
        mergedBlocks.push(copy);
    });

    return mergedBlocks;
});

const gridBlockAt = (day, rowIndex) => gridBlocks.value.find((b) => b.day === day && b.startIndex === rowIndex);
const gridIsCovered = (day, rowIndex) => gridBlocks.value.some((b) => b.day === day && rowIndex > b.startIndex && rowIndex < b.startIndex + b.span);
// Online meetings get their own colour (sky blue) so they read as
// distinct from Face-to-Face blocks (emerald) at a glance — same
// convention as the Section/Room scheduling grid's Online chips.
const gridBlockIsOnline = (block) => block?.Room === 'Online';

// Grid cell label: Room report shows Subject/Section/Faculty; Faculty
// report shows Subject/Section/Room — whichever the row doesn't already
// name in its own column heading.
function gridCellLabel(row) {
    if (!row) return null;
    if (reportType.value === 'schedule_by_room') {
        return { line1: row.Subject, line2: row.Section, line3: row.Faculty };
    }
    // A merged block (see gridBlocks' collapse step) rides two Sections
    // in one cell — "/Merge" appended to the third line flags that at a
    // glance without losing the Room/Online info that line already
    // carries, e.g. "Online/Merge" or "Room 306 (Lab 1)/Merge".
    return { line1: row.Subject, line2: row.Section, line3: row.merged ? `${row.Room}/Merge` : row.Room };
}
const needsSection = computed(() => ['schedule_by_section', 'section_subjects'].includes(reportType.value));
// Major / Year Level / Section Type only matter for reports scoped to a
// Section (by Program, year, or regular/irregular) — Room- and
// Faculty-centric reports don't need them: e.g. "Schedule by Room" only
// needs Term + College/Program (to narrow the Room list) + the Room
// itself, not Year Level or Section Type on top of that. No report type
// selected yet still shows the full set, so the form isn't empty before
// a choice is made.
const ROOM_OR_FACULTY_REPORT_TYPES = ['schedule_by_room', 'room_utilization', 'room_conflicts', 'schedule_by_faculty', 'faculty_teaching_load'];
const showProgramScopedFilters = computed(() => !reportType.value || !ROOM_OR_FACULTY_REPORT_TYPES.includes(reportType.value));

// Clear Major/Year Level/Section Type when they become hidden for the
// newly-picked report type, so a stale filter from an earlier report
// doesn't silently narrow results the person can no longer see or edit.
watch(showProgramScopedFilters, (visible) => {
    if (!visible) {
        form.value.major_id = '';
        form.value.year_level = '';
        if (!forcesIrregular.value) form.value.section_type = '';
    }
});
// Only Schedule by Section gets the multi-pick behavior; section_subjects
// keeps the existing single-section select as-is.
const sectionIsMultiple = computed(() => reportType.value === 'schedule_by_section');

// Same self-filtering as sectionOptions, but as a plain array (no "All
// Sections" placeholder entry) for the MultiSelect.
const sectionMultiOptions = computed(() => sectionOptions.value.filter((o) => o.value !== ''));

watch(sectionIsMultiple, (isMultiple) => {
    if (!isMultiple) sectionIds.value = [];
});
const forcesIrregular = computed(() => ['irregular_sections', 'irregular_merge_report'].includes(reportType.value));

// Builds the querystring payload, swapping in section_id[] (the multi-pick
// list) instead of the plain section_id whenever Schedule by Section's
// multi-select mode is active.
function buildQuery() {
    const query = { ...form.value, term: selectedTerm.value, report_type: reportType.value };
    // GENED_COLLEGE_VALUE only exists to filter the Faculty picker on the
    // client — it isn't a real College/Program id, so never send it to the
    // backend (which would just match nothing and return an empty report).
    if (query.college_id === GENED_COLLEGE_VALUE) {
        query.college_id = '';
    }
    if (sectionIsMultiple.value) {
        query.section_id = sectionIds.value;
    }
    if (facultyIsMultiple.value) {
        query.faculty_id = facultyIds.value;
    }
    return query;
}

function generate() {
    router.get(route('reports'), buildQuery(), { preserveState: true, preserveScroll: true });
}

function resetFilters() {
    form.value = { academic_year: '', semester: '', college_id: '', major_id: '', year_level: '', section_id: '', section_type: '', faculty_id: '', room_id: '' };
    sectionIds.value = [];
    facultyIds.value = [];
    selectedTerm.value = 'all';
    reportType.value = '';
    // Explicitly send term=all (not an empty query) so this always
    // clears back to "All Years" — an empty query object would look
    // identical to a first-ever visit and re-default to the Active
    // Academic Term instead of actually resetting.
    router.get(route('reports'), { term: 'all', academic_year: '', semester: '' }, { preserveState: false });
}

function printReport() {
    // Opens the branded, server-rendered print view (see
    // ReportsController::print() / resources/views/reports/print.blade.php)
    // in its own tab, scoped with the exact same filters currently on
    // screen — rather than window.print()-ing the SPA page itself,
    // which used to print the app's own chrome/columns instead of a
    // proper school-letterhead document.
    window.open(route('reports.print', buildQuery()), '_blank');
}

function exportCsv() {
    if (!props.report || !props.report.rows.length) return;
    const columns = props.report.columns;
    const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [columns.map(escape).join(',')];
    for (const row of props.report.rows) {
        lines.push(columns.map((c) => escape(row[c])).join(','));
    }
    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${(props.report.title || 'report').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

function statusSeverity(value) {
    const map = {
        Scheduled: 'success',
        Merged: 'success',
        'Partially Scheduled': 'warn',
        Unscheduled: 'danger',
        Conflict: 'danger',
        Independent: 'info',
        Draft: 'secondary',
        // Scheduling Conflicts report: Hours Mismatch rows that were
        // already confirmed via "Save Anyway" (hours_confirmed) show
        // as Acknowledged instead of the default Unresolved red flag.
        Unresolved: 'danger',
        Acknowledged: 'warn',
    };
    return map[value] || null;
}

const isStatusColumn = (col) => /status$/i.test(col) || col === 'Merge Status' || col === 'Availability Status';

const activeFiltersLabel = computed(() => {
    const parts = [];
    if (form.value.academic_year) parts.push(form.value.academic_year);
    if (form.value.semester) parts.push(form.value.semester);
    if (form.value.section_type) parts.push(form.value.section_type);
    return parts.join(' · ');
});

// Dashboard Summary tiles — icon + accent per metric, same neu-icon-well
// pattern used on the Scheduling Dashboard's stat cards.
const summaryCards = computed(() => [
    { label: 'Programs', value: props.summary.total_programs, icon: 'pi-sitemap', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
    { label: 'Sections', value: props.summary.total_sections, icon: 'pi-th-large', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
    { label: 'Regular', value: props.summary.regular_sections, icon: 'pi-check-circle', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)' },
    { label: 'Irregular', value: props.summary.irregular_sections, icon: 'pi-exclamation-circle', color: isDark.value ? '#FBBF24' : '#D97706', glow: isDark.value ? 'rgba(251, 191, 36, 0.3)' : 'rgba(217, 119, 6, 0.25)' },
    { label: 'Subjects', value: props.summary.total_subjects, icon: 'pi-book', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    { label: 'Scheduled', value: props.summary.scheduled_subjects, icon: 'pi-calendar-plus', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)' },
    { label: 'Unscheduled', value: props.summary.unscheduled_subjects, icon: 'pi-calendar-times', color: isDark.value ? '#FCA5A5' : '#DC2626', glow: isDark.value ? 'rgba(252, 165, 165, 0.3)' : 'rgba(220, 38, 38, 0.2)' },
    { label: 'Faculty', value: props.summary.total_faculty, icon: 'pi-users', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    { label: 'Rooms', value: props.summary.total_rooms, icon: 'pi-building', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    { label: 'Scheduling Completion', value: `${props.summary.completion_percent}%`, icon: 'pi-percentage', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)' },
]);
</script>

<template>
    <Head title="Reports" />

    <AppLayout>
        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Reports</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <div class="neu-card neu-spotlight relative mb-8 overflow-hidden rounded-2xl no-print">
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                    <div class="absolute inset-0" :class="isDark ? 'bg-gradient-to-br from-blue-500/10 via-transparent to-red-500/10' : 'bg-gradient-to-br from-blue-50 via-transparent to-red-50/60'"></div>
                    <div class="absolute -right-10 -top-16 h-44 w-44 rounded-full bg-gradient-to-br" :class="isDark ? 'from-blue-500/20 via-blue-500/5 to-transparent' : 'from-blue-200/80 via-blue-100/40 to-transparent'"></div>
                    <div class="absolute -bottom-20 right-16 h-40 w-40 rounded-full bg-gradient-to-tr" :class="isDark ? 'from-red-500/20 via-red-500/5 to-transparent' : 'from-red-200/70 via-red-100/30 to-transparent'"></div>
                    <div class="absolute -left-10 bottom-0 h-28 w-28 rounded-full bg-gradient-to-tr border-4" :class="isDark ? 'from-blue-500/10 to-transparent border-blue-400/10' : 'from-blue-100/60 to-transparent border-blue-100'"></div>
                    <div class="absolute left-[38%] top-0 h-3 w-3 rounded-full bg-gradient-to-br" :class="isDark ? 'from-red-400/50 to-blue-400/30' : 'from-red-400/80 to-blue-300/60'"></div>
                    <div class="absolute left-[55%] bottom-4 h-20 w-20 rounded-full bg-gradient-to-r blur-xl" :class="isDark ? 'from-blue-500/10 to-red-500/10' : 'from-blue-200/40 to-red-200/40'"></div>
                </div>
                <div class="relative flex items-center justify-between rounded-2xl p-6 transition-colors duration-300">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                            Reports
                            <InfoPopover
                                title="Reports"
                                :paragraphs="[
                                    'Generate read-only reports on scheduling, faculty load, room usage, and sections for a given academic term.',
                                ]"
                                :bullets="[
                                    'Choose a Term (or Academic Year + Semester) and a Report Type, then click \'Generate Report\'.',
                                    'Some report types reveal extra filters — e.g. Faculty or Room — once selected.',
                                    'Use Print or Export CSV to save a generated report; reports themselves are not saved in the system.',
                                ]"
                            />
                        </h1>
                        <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Generate and view academic scheduling reports.</p>
                    </div>
                </div>
            </div>

            <!-- Global Filters -->
            <div class="neu-card no-print rounded-2xl p-6 transition-colors duration-300">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4 neu-form">
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Term</label>
                        <Select
                            v-model="selectedTerm"
                            :options="termOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="neu-inset w-full mt-1 !rounded-xl !border-none"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                            @change="onTermChange"
                        >
                            <template #option="{ option }">
                                <span class="flex items-center gap-2">
                                    {{ option.label }}
                                    <Tag v-if="option.status === 'Archived'" value="Archived" severity="warn" class="!text-[10px] !py-0.5" />
                                </span>
                            </template>
                        </Select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Academic Year</label>
                        <Select v-model="form.academic_year" :options="academicYearOptions" optionLabel="label" optionValue="value" placeholder="All Years" showClear class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Semester</label>
                        <Select v-model="form.semester" :options="semesterOptions" optionLabel="label" optionValue="value" placeholder="All Semesters" showClear class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Report Type</label>
                        <Select v-model="reportType" :options="reportTypeOptions" optionLabel="label" optionValue="value" optionGroupLabel="label" optionGroupChildren="items" placeholder="Select Report" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">College / Program</label>
                        <Select v-model="form.college_id" :options="collegeOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="showProgramScopedFilters">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Major</label>
                        <Select v-model="form.major_id" :options="majorOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="showProgramScopedFilters">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Year Level</label>
                        <Select v-model="form.year_level" :options="yearLevelOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="showProgramScopedFilters">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Section Type</label>
                        <Select v-model="form.section_type" :disabled="forcesIrregular" :options="sectionTypeOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsSection">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            Section
                            <span v-if="sectionIsMultiple" class="font-normal normal-case" :class="isDark ? 'text-slate-500' : 'text-slate-400'">— pick any combination; each prints as its own section</span>
                        </label>
                        <MultiSelect
                            v-if="sectionIsMultiple"
                            v-model="sectionIds"
                            :options="sectionMultiOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            display="chip"
                            placeholder="All Sections"
                            class="neu-inset w-full mt-1 !rounded-xl !border-none"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <Select v-else v-model="form.section_id" :options="sectionOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsFaculty">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            Faculty
                            <span v-if="facultyIsMultiple" class="font-normal normal-case" :class="isDark ? 'text-slate-500' : 'text-slate-400'">— pick any combination; each prints as its own faculty</span>
                        </label>
                        <MultiSelect
                            v-if="facultyIsMultiple"
                            v-model="facultyIds"
                            :options="facultyMultiOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            display="chip"
                            placeholder="All Faculty"
                            class="neu-inset w-full mt-1 !rounded-xl !border-none"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <Select v-else v-model="form.faculty_id" :options="facultyOptions" optionLabel="label" optionValue="value" filter class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsRoom">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Room</label>
                        <Select v-model="form.room_id" :options="roomOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                </div>
                <div class="mt-5 flex gap-2">
                    <Button label="Generate Report" icon="pi pi-search" severity="success" :disabled="!reportType" @click="generate" />
                    <Button label="Reset Filters" severity="secondary" outlined @click="resetFilters" />
                </div>
            </div>

            <!-- Dashboard Summary -->
            <div class="mt-6 grid grid-cols-2 gap-4 no-print md:grid-cols-5">
                <div v-for="card in summaryCards" :key="card.label" class="neu-card rounded-2xl p-4 transition-colors duration-300">
                    <span
                        class="neu-icon-well neu-glow flex h-10 w-10 items-center justify-center rounded-xl"
                        :style="{ '--neu-glow-color': card.glow }"
                    >
                        <i class="pi text-base" :class="[card.icon]" :style="{ color: card.color }"></i>
                    </span>
                    <p class="mt-3 text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ card.value }}</p>
                    <p class="mt-1 text-xs font-semibold uppercase tracking-wide" :class="isDark ? 'text-slate-400' : 'text-slate-400'">{{ card.label }}</p>
                </div>
            </div>

            <!-- Report Results -->
            <div v-if="report" class="neu-card mt-6 rounded-2xl p-6 transition-colors duration-300">
                <div class="print-header hidden print:block mb-4">
                    <p class="text-lg font-bold">CLASSLY</p>
                    <p class="text-base font-semibold">{{ report.title }}</p>
                    <p class="text-xs text-slate-500" v-if="form.academic_year">Academic Year: {{ form.academic_year }}</p>
                    <p class="text-xs text-slate-500" v-if="form.semester">Semester: {{ form.semester }}</p>
                    <p class="text-xs text-slate-500">Generated: {{ generatedAt }}</p>
                </div>

                <div class="flex items-center justify-between no-print">
                    <div>
                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ report.title }}</h2>
                        <p class="text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'" v-if="activeFiltersLabel">{{ activeFiltersLabel }}</p>
                    </div>
                    <div class="flex gap-2">
                        <div v-if="gridEnabled" class="neu-inset flex rounded-xl p-0.5">
                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors" :class="viewMode === 'table' ? 'bg-emerald-500 text-white' : (isDark ? 'text-slate-400' : 'text-slate-500')" @click="viewMode = 'table'">Table</button>
                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors" :class="viewMode === 'grid' ? 'bg-emerald-500 text-white' : (isDark ? 'text-slate-400' : 'text-slate-500')" @click="viewMode = 'grid'">Grid</button>
                        </div>
                        <Button
                            v-if="sendScheduleFaculty"
                            label="Send via Email"
                            icon="pi pi-send"
                            severity="success"
                            class="report-btn report-btn--send"
                            @click="openSendScheduleModal"
                        />
                        <Button
                            v-if="showBulkSendButton"
                            label="Send All"
                            icon="pi pi-send"
                            severity="success"
                            class="report-btn report-btn--send"
                            :disabled="!bulkSendTerm"
                            @click="openBulkSendModal"
                        />
                        <Button label="Print" icon="pi pi-print" severity="secondary" outlined class="report-btn report-btn--print" @click="printReport" />
                        <Button label="Export CSV" icon="pi pi-file-export" severity="secondary" outlined class="report-btn report-btn--export" @click="exportCsv" :disabled="!report.rows.length" />
                    </div>
                </div>

                <div v-if="report.summary" class="mt-4 grid grid-cols-2 gap-3 no-print md:grid-cols-4">
                    <div v-for="(value, label) in report.summary" :key="label" class="neu-inset rounded-xl p-3 text-center">
                        <p class="text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ label }}</p>
                        <p class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ value }}</p>
                    </div>
                </div>

                <p v-if="!report.rows.length" class="py-10 text-center italic" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ report.empty_message }}</p>

                <DataTable
                    v-else-if="viewMode === 'table'"
                    :value="report.rows"
                    class="neu-inset neu-table mt-4 rounded-xl overflow-hidden"
                    :class="isDark ? 'neu-table-dark' : ''"
                    stripedRows
                    scrollable
                    paginator
                    :rows="15"
                >
                    <Column v-for="col in report.columns" :key="col" :field="col" :header="col">
                        <template #body="{ data }" v-if="isStatusColumn(col)">
                            <Tag :value="data[col]" :severity="statusSeverity(data[col])" v-if="data[col]" />
                            <span v-else>—</span>
                        </template>
                    </Column>
                </DataTable>

                <!-- Grid (weekly-timetable) view — same 30-min-row,
                     rowspan-block, merged-lunch-row layout as
                     Components/Scheduling/RoomGrid.vue's own timetable,
                     just read-only (no drag/drop, no writes) and built
                     from this report's rows instead of one Room's live
                     schedule. -->
                <div v-else class="mt-4 overflow-x-auto rounded-xl border border-slate-300">
                    <div
                        class="grid text-[13px]"
                        :style="{
                            gridTemplateColumns: `120px repeat(${gridDays.length}, minmax(140px, 1fr))`,
                            gridTemplateRows: `36px repeat(${gridHourRows.length}, 26px)`,
                        }"
                    >
                        <!-- Header row -->
                        <div class="border-b border-r border-slate-300 bg-slate-100"></div>
                        <div v-for="day in gridDays" :key="`h-${day}`" class="flex items-center justify-center border-b border-r border-slate-300 bg-slate-100 font-bold text-slate-700">
                            {{ GRID_DAY_LABELS[day] || day }}
                        </div>

                        <!-- Time labels -->
                        <template v-for="(hour, rowIndex) in gridHourRows" :key="`t-${hour}`">
                            <div
                                v-if="!gridIsLunchRow(hour) || gridIsFirstLunchRow(rowIndex)"
                                class="flex items-center justify-end whitespace-nowrap overflow-visible border-b border-r border-slate-300 px-1.5 text-[10px] leading-none"
                                :class="gridIsLunchRow(hour) ? 'font-bold text-amber-700' : (hour.endsWith(':00') ? 'font-semibold text-slate-700' : 'font-medium text-slate-500')"
                                :style="{ gridColumn: 1, gridRow: gridIsLunchRow(hour) ? `${rowIndex + 2} / span ${gridLunchSpan}` : rowIndex + 2 }"
                            >
                                {{ gridIsLunchRow(hour) ? gridLunchRangeLabel : gridFormatSlotRange(hour) }}
                            </div>
                        </template>

                        <!-- Cells + blocks -->
                        <template v-for="(day, dIndex) in gridDays" :key="`col-${day}`">
                            <template v-for="(hour, rowIndex) in gridHourRows" :key="`cell-${day}-${hour}`">
                                <div
                                    v-if="!gridIsCovered(day, rowIndex) && (!gridIsLunchRow(hour) || gridIsFirstLunchRow(rowIndex))"
                                    class="relative border-b border-r border-slate-300"
                                    :class="gridIsLunchRow(hour) ? 'bg-amber-100' : ''"
                                    :style="{
                                        gridColumn: dIndex + 2,
                                        gridRow: gridIsLunchRow(hour)
                                            ? `${rowIndex + 2} / span ${gridLunchSpan}`
                                            : (gridBlockAt(day, rowIndex) ? `${rowIndex + 2} / span ${gridBlockAt(day, rowIndex).span}` : rowIndex + 2),
                                    }"
                                >
                                    <div
                                        v-if="gridBlockAt(day, rowIndex)"
                                        class="absolute inset-0.5 overflow-hidden rounded-md px-2 py-1 text-[11px] leading-tight"
                                        :class="gridBlockIsOnline(gridBlockAt(day, rowIndex)) ? (isDark ? 'bg-sky-500/10' : 'bg-sky-50') : (isDark ? 'bg-emerald-500/10' : 'bg-emerald-50')"
                                    >
                                        <p class="truncate font-semibold" :class="gridBlockIsOnline(gridBlockAt(day, rowIndex)) ? (isDark ? 'text-sky-300' : 'text-sky-700') : (isDark ? 'text-emerald-300' : 'text-emerald-700')">{{ gridCellLabel(gridBlockAt(day, rowIndex)).line1 }}</p>
                                        <p class="truncate" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ gridCellLabel(gridBlockAt(day, rowIndex)).line2 }}</p>
                                        <p class="truncate text-[10px]" :class="gridBlockIsOnline(gridBlockAt(day, rowIndex)) ? (isDark ? 'text-sky-400' : 'text-sky-600') : (isDark ? 'text-slate-500' : 'text-slate-400')">{{ gridCellLabel(gridBlockAt(day, rowIndex)).line3 }}</p>
                                    </div>
                                </div>
                            </template>
                        </template>

                        <!-- Merged LUNCH banner -->
                        <template v-for="(hour, rowIndex) in gridHourRows" :key="`lunch-${hour}`">
                            <div
                                v-if="gridIsLunchRow(hour) && gridIsFirstLunchRow(rowIndex)"
                                class="pointer-events-none flex select-none items-center justify-center text-xs font-bold tracking-wider text-amber-800"
                                :style="{ gridColumn: `2 / span ${gridDays.length}`, gridRow: `${rowIndex + 2} / span ${gridLunchSpan}` }"
                            >
                                LUNCH
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div v-else class="neu-card mt-6 rounded-2xl p-6 transition-colors duration-300">
                <p class="py-10 text-center italic" :class="isDark ? 'text-slate-500' : 'text-slate-400'">Select filters and a Report Type above, then click "Generate Report".</p>
            </div>
        </div>

        <SendScheduleModal
            :show="showSendScheduleModal"
            :faculty="sendScheduleFaculty"
            :academic-term="sendScheduleTerm"
            :is-finalized="!!report?.facultyMeta?.is_finalized"
            @close="showSendScheduleModal = false"
            @sent="onScheduleSent"
        />

        <BulkSendScheduleModal
            :show="showBulkSendModal"
            :faculty-ids="facultyIds"
            :college-id="bulkSendCollegeId"
            :academic-term="bulkSendTerm"
            :scope-label="bulkSendScopeLabel"
            @close="showBulkSendModal = false"
            @sent="onBulkSent"
        />

        <Toast />
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides for teleported PrimeVue overlays (Select
   dropdown panels render outside this component via <Teleport>, so
   they need :global() rather than :deep()). Neumorphic surfaces
   (neu-card / neu-inset) already recolor automatically via the global
   ".dark" class — only the overlay panel and DataTable chrome need
   explicit handling here. */
.dark-scope :deep(.p-select-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-select-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select.p-disabled) { background: rgba(255, 255, 255, 0.03) !important; color: #64748B !important; }

.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; background: transparent !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: #1C2947 !important; color: #F8FAFC !important; border-color: rgba(255, 255, 255, 0.14) !important; font-weight: 700 !important; }
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

:global(.p-select-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-option-group) { background: rgba(255, 255, 255, 0.04) !important; color: #94A3B8 !important; }
:global(.p-select-overlay.dark-scope .p-select-filter) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; border-color: rgba(255, 255, 255, 0.15) !important; }
:global(.p-select-overlay.dark-scope .p-select-empty-message) { color: #94A3B8 !important; }

/* Print / Export CSV: on hover, tint the outline + text to a
   semantic color (green = print, blue = export) with a matching soft
   glow, instead of the generic gray outline hover. */
.report-btn--print:hover:not(:disabled) {
    border-color: rgba(34, 197, 94, 0.6) !important;
    color: #16A34A !important;
    background: rgba(34, 197, 94, 0.08) !important;
    box-shadow: 0 0 12px 1px rgba(34, 197, 94, 0.35) !important;
}
.report-btn--export:hover:not(:disabled) {
    border-color: rgba(37, 99, 235, 0.6) !important;
    color: #2563EB !important;
    background: rgba(37, 99, 235, 0.08) !important;
    box-shadow: 0 0 12px 1px rgba(37, 99, 235, 0.35) !important;
}
.report-btn--send:hover:not(:disabled) {
    box-shadow: 0 0 12px 1px rgba(34, 197, 94, 0.35) !important;
}

.dark-scope :deep(.report-btn--print:hover:not(:disabled)) {
    color: #4ADE80 !important;
    box-shadow: 0 0 16px 2px rgba(34, 197, 94, 0.5) !important;
}
.dark-scope :deep(.report-btn--export:hover:not(:disabled)) {
    color: #60A5FA !important;
    box-shadow: 0 0 16px 2px rgba(37, 99, 235, 0.5) !important;
}

/* DataTable's horizontal scrollbar was rendering as the browser's
   chunky default (bright blue on some platforms) — thin it out and
   theme it to match the rest of the app's slim scrollbars. */
.dark-scope :deep(.p-datatable-table-container) {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
}
.dark-scope :deep(.p-datatable-table-container::-webkit-scrollbar) {
    height: 6px;
    width: 6px;
}
.dark-scope :deep(.p-datatable-table-container::-webkit-scrollbar-thumb) {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 999px;
}
.dark-scope :deep(.p-datatable-table-container::-webkit-scrollbar-track) {
    background: transparent;
}
:deep(.p-datatable-table-container) {
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.5) transparent;
}
:deep(.p-datatable-table-container::-webkit-scrollbar) {
    height: 6px;
    width: 6px;
}
:deep(.p-datatable-table-container::-webkit-scrollbar-thumb) {
    background: rgba(148, 163, 184, 0.5);
    border-radius: 999px;
}
:deep(.p-datatable-table-container::-webkit-scrollbar-track) {
    background: transparent;
}

/* Light-mode header row: PrimeVue's default header background is a
   near-white tint that barely contrasts against the neu-card surface
   it sits on — give it a solid, clearly darker background instead. */
:deep(.p-datatable-thead > tr > th) {
    background: #EEF1F6 !important;
    color: #1E293B !important;
    font-weight: 700 !important;
}
</style>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    header, aside, nav, .no-print {
        display: none !important;
    }
    .neu-card, .neu-card * {
        visibility: visible;
    }
    .neu-card {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        box-shadow: none !important;
        border: none !important;
    }
}
</style>