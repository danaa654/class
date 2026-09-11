<script setup>
/**
 * Room Grid — room-centric scheduling view for the Section Subjects
 * page (spec Sections 2, 3, 11, 12, 13).
 *
 * This is a VIEW/EDIT surface on top of the exact same SectionSubject
 * rows the "Subjects" tab edits — no separate schedule table, no
 * duplicated write logic. Every drag-and-drop placement goes through
 * the same PATCH `scheduling.section-subjects.schedule` endpoint
 * (SectionSubjectController::updateSchedule()) that the Subjects
 * tab's inline Room/Days/Time cells already use, so Faculty/Section/
 * Room conflict validation, capacity warnings, Practicum exclusion,
 * and RBAC are enforced exactly once, server-side, in one place.
 */
import { ref, computed, onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Dialog from 'primevue/dialog';
import Tag from 'primevue/tag';
import Button from 'primevue/button';
import ProgressSpinner from 'primevue/progressspinner';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';

const props = defineProps({
    section: { type: Object, required: true },
    rows: { type: Array, required: true }, // parent's reactive sectionSubjects rows
    activeFaculty: { type: Array, default: () => [] },
    // REAL-TIME SCHEDULE CHANGE DETECTION — set by the parent's
    // polling composable when another user has changed this
    // Section's schedule since it was loaded. Blocks new drag moves
    // client-side as an early warning; the actual write is still
    // guarded server-side by expectedScheduleVersion below regardless
    // of this flag's freshness.
    isStale: { type: Boolean, default: false },
    // CONCURRENCY HARDENING — the schedule_version this page last
    // knew about, sent as `expected_schedule_version` on every
    // drag-and-drop write so the backend can reject a stale move
    // with HTTP 409 under its locked transaction.
    expectedScheduleVersion: { type: Number, default: null },
    // DARK MODE — wired from Show.vue's isDark (see useTheme.js). Only
    // needed for the raw Tailwind slate-* colors hardcoded into this
    // grid's own markup below (header row, time labels, borders, cell
    // hover states); everything else here (neu-card/neu-inset shells,
    // PrimeVue components) already themes itself off the `.dark` class.
    isDark: { type: Boolean, default: false },
    schedulingWindow: {
        type: Object,
        default: () => ({
            start_time: '08:00',
            end_time: '18:00',
            available_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            interval_minutes: 30,
            lunch_start: '12:00',
            lunch_end: '13:00',
        }),
    },
    // Institution-wide Teaching Load ceiling (FacultyLoadRequest::
    // effectiveCapFor()) — caps the "Request more units"/"Add Units"
    // dialog's number field at the same limit
    // StoreFacultyLoadRequestRequest enforces server-side. Same prop
    // name/shape/default as Faculty/Index.vue's own hardCapUnits.
    hardCapUnits: { type: Number, default: 40 },
});

// Room Code was retired from the UI — the underlying rooms table still
// stores one (derived from Room Name for uniqueness), but it's no longer
// meaningful to display, so the grid just shows the Room Name.
const roomLabel = (room) => (room?.room_name || '').trim();

// Left border + tint so Lecture vs Laboratory rooms are visually
// distinguishable at a glance in the sidebar lists, without relying
// on reading the small "Lecture · Cap 40" caption every time.
const roomTypeAccentClass = (room) => {
    if (room?.room_type === 'Laboratory') return 'border-l-4 border-l-purple-400 bg-purple-50/40';
    if (room?.room_type === 'Lecture') return 'border-l-4 border-l-sky-400 bg-sky-50/40';
    return 'border-l-4 border-l-slate-200';
};

const roomTypeDotClass = (room) => {
    if (room?.room_type === 'Laboratory') return 'bg-purple-400';
    if (room?.room_type === 'Lecture') return 'bg-sky-400';
    return 'bg-slate-300';
};

// Left border + tint so Major vs Minor vs General Education subjects
// are visually distinguishable in the Unscheduled Subjects list, same
// convention as roomTypeAccentClass() above.
const subjectCategoryAccentClass = (row) => {
    const category = row?.subject?.category;
    if (category === 'Major') return 'border-l-4 border-l-emerald-400 bg-emerald-50/40';
    if (category === 'Minor' || category === 'General Education') return 'border-l-4 border-l-amber-400 bg-amber-50/40';
    return 'border-l-4 border-l-slate-200';
};

const subjectCategoryDotClass = (row) => {
    const category = row?.subject?.category;
    if (category === 'Major') return 'bg-emerald-400';
    if (category === 'Minor' || category === 'General Education') return 'bg-amber-400';
    return 'bg-slate-300';
};

const emit = defineEmits(['row-updated', 'schedule-stale']);

const toast = useToast();

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

/* ------------------------------------------------------------------ */
/* Left sidebar — Room search / selection (spec Section 2 & 11)        */
/* ------------------------------------------------------------------ */

const recommendedRooms = ref([]);
const searchResults = ref([]);
const roomSearch = ref('');
const roomsLoading = ref(false);
let roomSearchDebounce = null;

const loadRooms = async (search = '') => {
    roomsLoading.value = true;
    try {
        const url = new URL(route('scheduling.section-subjects.rooms', props.section.id));
        if (search) url.searchParams.set('search', search);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        recommendedRooms.value = data.recommended ?? [];
        searchResults.value = data.search_results ?? [];
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not load rooms.', life: 5000 });
    } finally {
        roomsLoading.value = false;
    }
};

watch(roomSearch, () => {
    clearTimeout(roomSearchDebounce);
    roomSearchDebounce = setTimeout(() => loadRooms(roomSearch.value), 300);
});

onMounted(() => loadRooms());

/* ------------------------------------------------------------------ */
/* Selected room + its weekly timetable (spec Section 3 & 13)          */
/* ------------------------------------------------------------------ */

const selectedRoomBasic = ref(null); // from the sidebar list (id/code/name/type/capacity)
const selectedRoomDetail = ref(null); // authoritative detail from roomSchedule()
const assignments = ref([]);
const gridLoading = ref(false);

const selectedRoom = computed(() => selectedRoomDetail.value ?? selectedRoomBasic.value);

const isManualOverride = computed(() => {
    if (!selectedRoomBasic.value) return false;
    return !!selectedRoomBasic.value.is_outside_department;
});

const loadRoomSchedule = async (room) => {
    if (!room) return;
    gridLoading.value = true;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.room-schedule', [props.section.id, room.id]),
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not load this room\'s schedule.');
        selectedRoomDetail.value = data.room;
        assignments.value = data.assignments ?? [];
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not load this room\'s schedule.', life: 5000 });
    } finally {
        gridLoading.value = false;
    }
};

const selectRoom = (room) => {
    selectedRoomBasic.value = room;
    selectedRoomDetail.value = null;
    loadRoomSchedule(room);
};

/* ------------------------------------------------------------------ */
/* Time rows — derived from the Active School Year's Scheduling        */
/* Window, hourly granularity, matching the boundary the Subjects      */
/* tab's own Day & Time editor already enforces server-side.           */
/* ------------------------------------------------------------------ */

const days = computed(() => props.schedulingWindow.available_days?.length ? props.schedulingWindow.available_days : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']);
const dayLabels = { Mon: 'Mon', Tue: 'Tue', Wed: 'Wed', Thu: 'Thu', Fri: 'Fri', Sat: 'Sat', Sun: 'Sun' };

const toMinutes = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + (m || 0);
};
const toHHMM = (minutes) => {
    const h = Math.floor(minutes / 60) % 24;
    const m = minutes % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
};
const formatHourLabel = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
};

const intervalMinutes = computed(() => props.schedulingWindow.interval_minutes || 30);

// Lunch Break restriction removed per adviser direction — always false,
// so no row is merged/blocked and every slot (including 12:00-1:00) is
// an ordinary schedulable row.
const isLunchRow = () => false;

// "8:00 AM" -> "8:00 AM – 8:30 AM", using the grid's own interval so a
// 15/30/60-min Time Interval setting always produces the right range.
const formatSlotRange = (hhmm) => {
    const end = toHHMM(toMinutes(hhmm) + intervalMinutes.value);
    return `${formatHourLabel(hhmm)} – ${formatHourLabel(end)}`;
};

// Compact time label for a placed block's card — "8:00am-1:00pm" style
// (lowercase, no space before am/pm) so it reads well at the block's
// small font size without eating into the space subject/faculty need.
const formatCompactTime = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    const period = h >= 12 ? 'pm' : 'am';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return m === 0 ? `${h12}${period}` : `${h12}:${String(m).padStart(2, '0')}${period}`;
};
const formatBlockTimeRange = (block) => {
    if (!block?.start_time || !block?.end_time) return '';
    return `${formatCompactTime(block.start_time)}-${formatCompactTime(block.end_time)}`;
};

// The Lunch Break window is fixed and never scheduled into (see
// isLunchRow above) — visually it's rendered as ONE merged row instead
// of one row per grid interval (e.g. one "12:00 PM – 1:00 PM" row
// instead of two stacked "12:00–12:30" / "12:30–1:00" rows), so it
// doesn't eat extra vertical space the Registrar has to scroll/drag
// past. This is purely a rendering merge — hourRows itself, and every
// index-based calculation in placedBlocks/onDrop/openEditModal, still
// treats lunch as ordinary step-sized rows underneath.
const lunchRowIndices = computed(() => {
    const indices = [];
    hourRows.value.forEach((hour, idx) => {
        if (isLunchRow(hour)) indices.push(idx);
    });
    return indices;
});
const isFirstLunchRow = (rowIndex) => lunchRowIndices.value[0] === rowIndex;
const lunchSpan = computed(() => lunchRowIndices.value.length || 1);
const lunchRangeLabel = computed(() => {
    const start = props.schedulingWindow.lunch_start || '12:00';
    const end = props.schedulingWindow.lunch_end || '13:00';
    return `${formatHourLabel(start)} – ${formatHourLabel(end)}`;
});

const hourRows = computed(() => {
    const start = toMinutes(props.schedulingWindow.start_time || '08:00');
    const end = toMinutes(props.schedulingWindow.end_time || '18:00');
    const step = intervalMinutes.value;
    const rows = [];
    for (let t = start; t < end; t += step) rows.push(toHHMM(t));
    return rows.length ? rows : ['08:00', '08:30', '09:00', '09:30', '10:00'];
});

/* Blocks placed on the grid, one entry per (day, assignment) —      */
/* THEN merged blocks (multiple SectionSubject rows that share the    */
/* exact same Day/Start/End — see IrregularSectionMergeService, which */
/* copies the host's Room/Days/Time verbatim onto every merged row)   */
/* are collapsed into ONE visual block instead of stacking invisibly  */
/* on top of each other. Two DIFFERENT (non-merged) sections can      */
/* never legitimately land on identical Room+Day+Time — that's a Room */
/* conflict ScheduleConflictService already blocks — so sharing a     */
/* slot is itself the signal that a merge produced it.                */
const placedBlocks = computed(() => {
    const raw = [];
    assignments.value.forEach((a) => {
        const dayTokens = (a.days || '').split(',').filter(Boolean);
        const startMin = a.start_time ? toMinutes(a.start_time) : null;
        const endMin = a.end_time ? toMinutes(a.end_time) : null;
        if (startMin === null || endMin === null) return;

        const gridStart = toMinutes(hourRows.value[0]);
        const step = intervalMinutes.value;
        const startIndex = Math.max(0, Math.round((startMin - gridStart) / step));
        const span = Math.max(1, Math.round((endMin - startMin) / step));

        dayTokens.forEach((day) => {
            if (!days.value.includes(day)) return;
            raw.push({ ...a, day, startIndex, span });
        });
    });

    // Group by (day, startIndex, span) — everything in a group is the
    // same class session shared across sections via a merge.
    const groups = new Map();
    raw.forEach((b) => {
        const key = `${b.day}-${b.startIndex}-${b.span}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(b);
    });

    return Array.from(groups.values()).map((members) => {
        if (members.length === 1) return members[0];

        // Which row does editing/dragging THIS block act on?
        // - If the current section is one of the merged members, always
        //   use ITS OWN row — never the host's, even though the host is
        //   preferred elsewhere (see below). Editing/moving must change
        //   the current section's own booking, not silently rewrite a
        //   different section's class out from under it.
        // - Otherwise (every member belongs to some other section — this
        //   block is only relevant here as a drop/merge target) prefer
        //   the true host (Regular section) row, since a merged
        //   Irregular row can never itself be a merge host (see
        //   findHostCandidates) — anchoring on it would make a later
        //   merge-drop silently fail to match a candidate.
        const own = members.find((m) => m.is_current_section);
        const host = own ?? members.find((m) => m.section_type === 'Regular') ?? members[0];

        const sectionCodes = [...new Set(members.map((m) => m.section_code).filter(Boolean))];

        return {
            ...host,
            section_code: sectionCodes.join(' / '),
            is_current_section: !!own,
            // A merged block is only draggable/editable here if the row
            // we're actually going to act on (host) is itself
            // authorized — never because SOME member happens to be.
            can_edit: !!host.can_edit,
            merged_member_section_subject_ids: members.map((m) => m.section_subject_id),
            // Full per-member detail — powers the "Move both sections
            // (keep merged) / Move only <section>" choice offered when
            // dragging a merged block (see onDrop()). Each member keeps
            // its own section_subject_id/section_id/section_code/
            // can_edit so the move can be scoped/authorized correctly
            // per row, never assumed from the collapsed host alone.
            merge_members: members.map((m) => ({
                section_subject_id: m.section_subject_id,
                section_id: m.section_id,
                section_code: m.section_code,
                is_current_section: !!m.is_current_section,
                can_edit: !!m.can_edit,
            })),
        };
    });
});

const blockAt = (day, rowIndex) => placedBlocks.value.find((b) => b.day === day && b.startIndex === rowIndex);
const isCovered = (day, rowIndex) => placedBlocks.value.some((b) => b.day === day && rowIndex > b.startIndex && rowIndex < b.startIndex + b.span);

// CONFLICT DETECTION — the Room Grid used to just draw whatever the
// backend returned and trust the comment above ("a Room conflict
// ScheduleConflictService already blocks") that two different
// Sections could never legitimately overlap here. That trust wasn't
// backed by every write path (see overrideFaculty()/overrideRoom() in
// SectionSubjectController — now fixed, but old bad rows, or any
// future gap, would otherwise render here with no visual sign
// anything was wrong). This does its own overlap check independent of
// the backend: any two DIFFERENT, non-merged blocks on the same day
// whose [startIndex, startIndex+span) ranges overlap are flagged,
// regardless of why they ended up that way.
const overlaps = (a, b) => a.startIndex < b.startIndex + b.span && b.startIndex < a.startIndex + a.span;

const conflictedBlockIds = computed(() => {
    const flagged = new Set();
    const list = placedBlocks.value;
    for (let i = 0; i < list.length; i++) {
        for (let j = i + 1; j < list.length; j++) {
            const a = list[i];
            const b = list[j];
            if (a.day !== b.day) continue;
            if (a.section_subject_id === b.section_subject_id) continue;
            // A legitimate merge already collapsed same-slot rows into
            // one block above (they share a key), so anything left as
            // two SEPARATE blocks overlapping in time is a genuine
            // double-booking, not a merge.
            if (!overlaps(a, b)) continue;
            flagged.add(a.section_subject_id);
            flagged.add(b.section_subject_id);
        }
    }
    return flagged;
});

const blockHasConflict = (block) => !!block && conflictedBlockIds.value.has(block.section_subject_id);

// Four visual states for a placed block, per spec:
// - "current": this Section's own booking — normal blue, click to edit.
// - "authorized": belongs to a DIFFERENT Section, but that Section is
//   within the logged-in user's authorized scheduling scope
//   (can_edit, computed server-side from College/Department scope —
//   see roomSchedule()) — still draggable ("OTHER SECTION"), never
//   forcing the user to switch to that Section first.
// - "finalized": the schedule's Section has been finalized (locked)
//   — visible, never draggable/editable, regardless of scheduling
//   scope. Distinct color from "locked" (out-of-scope) so a Dean
//   can tell "I can't touch this because it's someone else's
//   college" apart from "I can't touch this because it's done."
// - "locked": the schedule's Section is OUTSIDE the user's authorized
//   scheduling scope — visible (Room Grid stays room-centric), never
//   draggable/editable.
// - "out_of_category": the block IS within the user's authorized
//   Section scope, but is a Major subject and the user is an
//   Assistant Dean (GenEd/Minor only, spec §8) — visible, never
//   draggable/editable. Distinct from "locked" so an Assistant Dean
//   can tell "this isn't my subject type" apart from "this isn't my
//   college" — see can_edit/is_out_of_category from roomSchedule()
//   in SectionSubjectController.
const blockAuthState = (block) => {
    if (!block) return 'locked';
    if (block.is_finalized) return 'finalized';
    if (block.is_out_of_category) return 'out_of_category';
    if (block.is_current_section) return 'current';
    return block.can_edit ? 'authorized' : 'locked';
};

const blockClass = (block) => {
    if (blockHasConflict(block)) return 'bg-red-50 border-2 border-red-500 text-red-700 cursor-grab hover:border-red-600';
    const state = blockAuthState(block);
    if (state === 'current') return 'bg-blue-50 border border-blue-200 text-blue-700 cursor-grab hover:border-blue-400';
    if (state === 'authorized') return 'bg-emerald-50 border border-emerald-300 border-dashed text-emerald-700 cursor-grab hover:border-emerald-500';
    if (state === 'finalized') return 'bg-amber-50 border border-amber-300 text-amber-800 cursor-not-allowed';
    if (state === 'out_of_category') return 'bg-slate-50 border border-slate-300 border-dotted text-slate-500 cursor-not-allowed';
    return 'bg-slate-100 border border-slate-300 text-slate-500 cursor-not-allowed';
};

const blockTitle = (block) => {
    if (blockHasConflict(block)) {
        return `⚠ Conflict: overlaps another class in this room/time — ${block.section_code} ${block.subject_code}. This needs to be rescheduled.`;
    }
    const mismatchSuffix = block.faculty_mismatch
        ? ` ⚠ Faculty Mismatch — ${block.faculty_name ?? 'this faculty member'} is manually assigned outside ${block.subject_code ?? 'this subject'}'s qualified/academic-home faculty.`
        : '';
    const state = blockAuthState(block);
    if (state === 'current') return `Click to edit · Drag to move${mismatchSuffix}`;
    if (state === 'authorized') return `Belongs to ${block.section_code} — within your authorized scheduling scope. Drag to move.${mismatchSuffix}`;
    if (state === 'finalized') return `${block.section_code}'s schedule is finalized — an Admin/Registrar must unlock it before this can be edited.`;
    if (state === 'out_of_category') return `${block.subject_code} is a Major subject — outside your Assistant Dean scheduling scope (GenEd/Minor only). The Dean/OIC manages this.`;
    return 'This schedule is outside your scheduling scope.';
};

/* ------------------------------------------------------------------ */
/* Unscheduled subjects for the CURRENT section only (spec: "only      */
/* show BSIT-4A subjects" when viewing BSIT-4A). Practicum/OJT is      */
/* excluded — those are never room-scheduled (spec Section 12).        */
/* ------------------------------------------------------------------ */

const needsRoomPlacement = (row) => {
    if (row.subject?.subject_type === 'practicum') return false;
    // SPLIT-DELIVERY SCHEDULING — an 'online' row never gets a Room
    // and never should (see SectionSubject::requiresRoom()), so it
    // must never count as "needs a Room" here — otherwise it (and
    // its subject) sits in this Room Grid sidebar forever, even after
    // it's fully scheduled with Days/Time on the Section Grid/Subjects
    // tab. Same exclusion as Practicum above, different reason.
    if (row.delivery_mode === 'online') return false;
    return !row.room_id || !row.days || row.days.length === 0 || !row.start_time || !row.end_time;
};

const unscheduledSubjects = computed(() => props.rows.filter(needsRoomPlacement));

// CATEGORY SCOPE (Assistant Dean = GenEd/Minor only, spec §8) — reuses
// the same 'auth.user.roles' Inertia already shares globally (see
// HandleInertiaRequests), rather than threading a new prop through
// Show.vue just for this. Major-subject rows stay VISIBLE in the
// "Unscheduled Subjects" sidebar for an Assistant Dean — hiding them
// would make it look like the section has fewer subjects than it
// really does — but greyed out and non-draggable, matching the same
// "visible, not hidden" treatment already given to out-of-category
// placed blocks (blockAuthState()'s 'out_of_category' state below).
// The backend enforces this independently regardless of what the UI
// allows — see performScheduleAssignmentUpdate() in
// SectionSubjectController.
const page = usePage();
const isAssistantDean = computed(() => (page.props.auth?.roles ?? []).includes('Assistant Dean'));
const isRowOutOfCategory = (row) => isAssistantDean.value && row.subject?.category === 'Major';

// Same shared prop Faculty/Index.vue already reads (see its
// canChangeMaxLoad) — Admin/Registrar have a direct write path to
// Faculty::max_teaching_units (FacultyController@update), so for them
// the Room Grid's inline shortcut is really "Add Units" (applies
// immediately); everyone else has no direct write path, so it's a
// "Request more units" ask that goes into the Pending review queue.
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

/* ------------------------------------------------------------------ */
/* GHOST OVERLAY ("x-ray") — Prompt: eye icon showing this section's   */
/* own already-scheduled subjects on top of whichever room's grid is   */
/* currently open, even sessions booked in a DIFFERENT room, so the    */
/* Registrar can see at a glance which Day/Time slots the SECTION is   */
/* already committed to before manually dropping another subject onto */
/* what looks like a free room slot but would double-book the section. */
/* Built entirely from props.rows (already loaded for this section) —  */
/* no extra request needed, since every row already carries its own    */
/* days/start_time/end_time/room.                                      */
/* ------------------------------------------------------------------ */
const showSectionGhost = ref(false);

const sectionGhostBlocks = computed(() => {
    if (!showSectionGhost.value || !selectedRoom.value) return [];

    const raw = [];
    props.rows.forEach((row) => {
        if (needsRoomPlacement(row)) return; // not scheduled yet — nothing to x-ray
        if (row.room_id === selectedRoom.value.id) return; // already drawn as a real block below — no need to ghost it too

        const startMin = toMinutes(row.start_time);
        const endMin = toMinutes(row.end_time);
        const gridStart = toMinutes(hourRows.value[0]);
        const step = intervalMinutes.value;
        const startIndex = Math.max(0, Math.round((startMin - gridStart) / step));
        const span = Math.max(1, Math.round((endMin - startMin) / step));

        (row.days || []).forEach((day) => {
            if (!days.value.includes(day)) return;
            raw.push({
                day,
                startIndex,
                span,
                subject_code: row.subject?.subject_code,
                room_name: row.room?.room_name,
                faculty_name: row.faculty?.full_name ?? row.faculty_name ?? null,
            });
        });
    });
    return raw;
});

const ghostBlockAt = (day, rowIndex) => sectionGhostBlocks.value.find((b) => b.day === day && b.startIndex === rowIndex);
const isGhostCovered = (day, rowIndex) => sectionGhostBlocks.value.some((b) => b.day === day && rowIndex > b.startIndex && rowIndex < b.startIndex + b.span);

/* ------------------------------------------------------------------ */
/* Drag & Drop — writes go through the same updateSchedule() endpoint  */
/* the Subjects tab uses. No optimistic local mutation of the grid;    */
/* we re-fetch the room's schedule on success so it always reflects    */
/* the server's own conflict-checked result.                           */
/*                                                                      */
/* Dropping a NEW (unscheduled) subject opens the Assign modal so the  */
/* Registrar sets Hours/Week, Meetings/Week, and Faculty before it's   */
/* ever saved — a single 1-hour guess was the wrong default for e.g.   */
/* a 5-hr/week Capstone subject. Moving an EXISTING block just         */
/* relocates it (same Faculty, same total duration) with no modal.     */
/* ------------------------------------------------------------------ */

const draggingMode = ref(null); // 'new' | 'move'
const draggingRow = ref(null); // full SectionSubject row, for 'new'
const draggingSubjectId = ref(null); // section_subject_id, for 'move'
const draggingDuration = ref(60); // minutes, for 'move'
const draggingBlock = ref(null); // full block being moved, for 'move' — powers the cross-section confirmation dialog
const savingCell = ref(null); // "day-rowIndex" while a write is in flight

const onDragStartNew = (row) => {
    draggingMode.value = 'new';
    draggingRow.value = row;
};

const onDragStartBlock = (block) => {
    // AUTHORIZATION IS PER-ASSIGNMENT, NOT PER-CURRENTLY-VIEWED-SECTION
    // — a block can belong to a different Section than the one this
    // Room Grid is open for and still be draggable, as long as the
    // logged-in user is authorized for THAT block's own Section
    // (block.can_edit, computed server-side in roomSchedule()). Never
    // gate on is_current_section alone.
    if (!block.can_edit) return;
    // BLOCK STALE SAVES EARLY — don't let a new drag even start once
    // polling has flagged this Section's schedule as changed
    // elsewhere; the Registrar should refresh first. Existing
    // in-progress interactions are never interrupted by this flag —
    // it's only checked at the moment a NEW drag begins.
    if (props.isStale) {
        toast.add({
            severity: 'warn',
            summary: 'Schedule changed',
            detail: 'Another user changed this schedule. Refresh before moving anything.',
            life: 5000,
        });
        return;
    }
    draggingMode.value = 'move';
    draggingSubjectId.value = block.section_subject_id;
    draggingDuration.value = (toMinutes(block.end_time) - toMinutes(block.start_time)) || 60;
    draggingBlock.value = block; // full block, for the "moving another section's class" confirmation
};

// Backend errors that are WARNINGS, not hard conflicts — the
// Registrar can explicitly confirm and save anyway (see
// performScheduleAssignmentUpdate()'s Capacity/Hours checks). Any
// OTHER error key (room_id/faculty_id/days — real Room/Faculty/
// Section/Time conflicts from ScheduleConflictService) is a hard
// block and must never be offered a "confirm anyway" retry.
const CONFIRMABLE_WARNING_KEYS = { capacity: 'capacity_confirmed', hours: 'hours_confirmed', room_type: 'room_type_confirmed', room_college: 'room_college_confirmed', faculty_mismatch: 'faculty_mismatch_confirmed' };

// Of the warning keys above, only Room Capacity is confirmed
// immediately from Room Grid's own retry dialog. Room Type/Room
// College/Hours are DEFERRED — the Registrar can place the subject
// despite the mismatch, but it isn't treated as resolved until
// confirmed for real via the Subjects tab's batch "Save Schedule"
// (see confirmAssign()'s matching dialogs above and
// UpdateSectionSubjectScheduleRequest's docblock for
// defer_mismatch_confirmation).
const DEFERRABLE_WARNING_KEYS = new Set(['hours', 'room_type', 'room_college']);

const writeSchedule = async (subjectId, payload, { successMessage, crossSection = false, silent = false } = {}) => {
    try {
        // Moving a block that belongs to a DIFFERENT Section than the
        // one currently open goes through the dedicated Room Grid move
        // endpoint, which authorizes against THAT block's own Section
        // (see moveRoomGridAssignment()'s docblock) rather than the
        // currently-viewed {section}. Everything else (editing/placing
        // the current section's own subjects) keeps using the existing
        // per-Section endpoint unchanged.
        const url = crossSection
            ? route('scheduling.room-grid.move', subjectId)
            : route('scheduling.section-subjects.schedule', [props.section.id, subjectId]);

        const response = await fetch(
            url,
            {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ ...payload, expected_schedule_version: props.expectedScheduleVersion }),
            },
        );
        const data = await response.json();

        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            // SAVE CONFLICT RESPONSE — another user's change already
            // committed since this page's version baseline. Do not
            // silently move/overwrite anything; tell the parent so it
            // can mark the whole page stale, and resync this grid to
            // whatever the server actually has now.
            emit('schedule-stale', data.current_version ?? null);
            toast.add({
                severity: 'error',
                summary: 'Save prevented',
                detail: 'Your schedule is outdated because another user made a change. Please refresh and try again.',
                life: 7000,
            });
            await loadRoomSchedule(selectedRoom.value);
            return false;
        }

        // WORKLOAD WARNING — Save Schedule Validation
        // (SectionSubjectController::workloadWarningFor()). This is
        // its own 409 shape ({workload_warning, can_override,
        // message} — no `errors` key), so it must be handled BEFORE
        // the generic !response.ok branch below, which only knows how
        // to read data.errors and would otherwise treat this as a
        // dead-end error toast with no action (the pre-existing bug
        // this fixes — the backend has supported an Administrator
        // override via workload_confirmed=true since this endpoint's
        // very first version, but nothing on the frontend ever sent
        // it).
        if (response.status === 409 && data.workload_warning) {
            if (data.can_override) {
                // Anyone with changeMaxLoad access (Administrator,
                // Registrar, Dean, OIC, Assistant Dean) — same message
                // the backend already computed, just given a real
                // "Proceed Anyway" action instead of dead-ending.
                const result = await Swal.fire({
                    icon: 'warning',
                    title: 'Conflict',
                    text: data.message,
                    showCancelButton: true,
                    confirmButtonText: 'Proceed Anyway',
                    cancelButtonText: 'Cancel',
                    customClass: { container: 'roomgrid-swal-on-top' },
                });
                if (!result.isConfirmed) return false;

                return writeSchedule(
                    subjectId,
                    { ...payload, workload_confirmed: true },
                    { successMessage, crossSection, silent },
                );
            }

            // Not an Administrator — no override available, same as
            // before. But now also offer the same "Request more
            // units" shortcut the Faculty dropdown offers, scoped to
            // the faculty already on this row, so a Dean/OIC hitting
            // this wall on Save (not just while picking Faculty) has
            // the same way out instead of a permanent dead end.
            const askForMore = await Swal.fire({
                icon: 'error',
                title: 'Conflict',
                html: `<div class="text-left text-sm">${data.message}</div>`,
                showCancelButton: true,
                confirmButtonText: 'Request More Units',
                cancelButtonText: 'Close',
                customClass: { container: 'roomgrid-swal-on-top' },
            });
            if (askForMore.isConfirmed && data.workload_warning.faculty_id) {
                await openLoadRequestDialog({
                    id: data.workload_warning.faculty_id,
                    full_name: data.workload_warning.faculty_name,
                });
            }
            return false;
        }

        if (!response.ok) {
            const errorKeys = data.errors ? Object.keys(data.errors) : [];
            const isConfirmable = errorKeys.length > 0 && errorKeys.every((k) => k in CONFIRMABLE_WARNING_KEYS);

            // Capacity/Hours mismatches are flagged-not-blocked
            // everywhere else in the app (Subjects tab spreadsheet,
            // Assign modal) — the Room Grid drag/drop must offer the
            // same "Confirm to save anyway" instead of just dead-ending
            // on a permanent error toast.
            if (isConfirmable) {
                const detail = Object.values(data.errors).join(' ');
                // Room Type/Room College/Hours are deferred (placed,
                // but only truly resolved via the Subjects tab's "Save
                // Schedule"); only Room Capacity is confirmed outright
                // from this dialog. Mixed batches (capacity together
                // with a deferrable one) still defer the deferrable
                // ones and confirm capacity — never silently promote a
                // deferrable one to fully confirmed.
                const hasDeferrable = errorKeys.some((k) => DEFERRABLE_WARNING_KEYS.has(k));
                const result = await Swal.fire({
                    icon: 'warning',
                    title: 'Conflict',
                    html: hasDeferrable
                        ? `<div class="text-left text-sm">${detail} This will still need to be confirmed on the Subjects tab's "Save Schedule" before it's fully resolved.</div>`
                        : detail,
                    showCancelButton: true,
                    confirmButtonText: hasDeferrable ? 'Place Anyway' : 'Confirm & Save',
                    cancelButtonText: 'Cancel',
                    customClass: { container: 'roomgrid-swal-on-top' },
                });

                if (!result.isConfirmed) return false;

                const confirmedPayload = { ...payload };
                let deferMismatchConfirmation = false;
                errorKeys.forEach((key) => {
                    if (DEFERRABLE_WARNING_KEYS.has(key)) {
                        confirmedPayload[CONFIRMABLE_WARNING_KEYS[key]] = false;
                        deferMismatchConfirmation = true;
                    } else {
                        confirmedPayload[CONFIRMABLE_WARNING_KEYS[key]] = true;
                    }
                });
                if (deferMismatchConfirmation) {
                    confirmedPayload.defer_mismatch_confirmation = true;
                }

                return writeSchedule(subjectId, confirmedPayload, { successMessage, crossSection, silent });
            }

            const detail = data.errors
                ? Object.values(data.errors).join(' ')
                : (data.message ?? 'Could not save — check for a Room, Faculty, or Section conflict.');
            toast.add({ severity: 'error', summary: 'Conflict', detail, life: 7000 });
            // Reload so a losing drag/drop visually snaps back to
            // wherever the block ACTUALLY is server-side — critical
            // under concurrency: another user may have just taken this
            // slot, and the grid must show their booking, not silently
            // leave the failed drag's optimistic position on screen.
            await loadRoomSchedule(selectedRoom.value);
            return false;
        }

        if (!silent) {
            toast.add({ severity: 'success', summary: 'Scheduled', detail: successMessage ?? data.message ?? 'Schedule updated.', life: 3000 });
        }
        // CONCURRENCY HARDENING — this write just bumped the
        // Section's real schedule_version server-side (see
        // performScheduleAssignmentUpdate()'s bumpScheduleVersion()
        // call). Previously only `sectionSubject` was emitted here,
        // so the parent page's `schedulePolling.currentVersion` (the
        // very value re-sent as `expected_schedule_version` on the
        // NEXT write, including the next Room Grid drag) never
        // advanced past what the page loaded with. That made every
        // save AFTER the first one in a session look stale to the
        // backend's OCC check — a false "Another user changed this
        // schedule" even when only one person, in one tab, made every
        // change. Emitting `schedule_version` here lets the parent
        // (onRoomGridRowUpdated in Show.vue) call
        // schedulePolling.acceptVersion() and stay in sync with what
        // was actually just written, exactly like Save Schedule /
        // Auto Generate / every other write path already does.
        // AUTO-RAISE CEILING — when "Proceed Anyway" on a workload
        // warning just raised this Faculty's max_teaching_units
        // server-side (see performScheduleAssignmentUpdate()'s
        // updated_faculty_load), patch the same local activeFaculty
        // entry openLoadRequestDialog() already patches in place, so
        // this dropdown's own "X units left (Y/Z)" label reflects the
        // new ceiling immediately — no full page reload needed, and
        // no stale "0 units left (12/12)" surviving a save that just
        // moved it to 15/15.
        if (data.updated_faculty_load) {
            const target = props.activeFaculty.find((f) => f.id === data.updated_faculty_load.id);
            if (target) {
                target.max_teaching_units = data.updated_faculty_load.max_teaching_units;
                target.current_load = data.updated_faculty_load.current_load;
            }
        }

        emit('row-updated', data.sectionSubject, data.schedule_version);
        await loadRoomSchedule(selectedRoom.value);
        return true;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not save this placement.', life: 5000 });
        return false;
    }
};

const onDrop = async (day, rowIndex) => {
    const mode = draggingMode.value;
    draggingMode.value = null;
    if (!selectedRoom.value) return;

    if (mode === 'move') {
        const subjectId = draggingSubjectId.value;
        const block = draggingBlock.value;
        draggingSubjectId.value = null;
        draggingBlock.value = null;
        if (!subjectId) return;

        const startTime = hourRows.value[rowIndex];
        const endTime = toHHMM(toMinutes(startTime) + draggingDuration.value);

        // KEEP OTHER MEETING DAYS INTACT — a block on the grid is one
        // VISUAL occurrence of a SectionSubject row that may meet on
        // more than one day per week (e.g. "Fri,Sat"); block.day is
        // just THIS occurrence's day, while block.days still carries
        // the row's full original day list (see placedBlocks above,
        // which spreads `...a` — the raw assignment — onto every
        // occurrence before narrowing to a single `day`). Previously
        // this always sent `days: [day]`, replacing the ENTIRE day
        // list with only the day just dropped on — so dragging one
        // meeting of a 2x/week subject silently deleted its other
        // meeting and halved the subject's total scheduled hours.
        // Swapping only the dragged occurrence's OLD day for the NEW
        // one (leaving every other day untouched) keeps the meeting
        // count — and therefore the subject's total hours/week —
        // unchanged. Start/End Time is still shared across every day
        // on the row (a single SectionSubject row has one time slot
        // for all its meeting days, not a separate time per day), so
        // every meeting on this row moves to the newly dropped time —
        // only the DAY of this specific occurrence changes relative
        // to the others.
        const originalDays = (block?.days || '').split(',').filter(Boolean);
        const newDays = originalDays.length > 1
            ? [...new Set(originalDays.map((d) => (d === block.day ? day : d)))]
            : [day];

        // No-op drop (same room/day/time it already occupies) — skip
        // the round trip and, for a cross-section block, skip the
        // confirmation dialog entirely.
        if (
            block
            && block.day === day
            && block.startIndex === rowIndex
        ) {
            return;
        }

        // MOVE-TO-MERGE — dragging an EXISTING Irregular-section block
        // on top of a DIFFERENT block teaching the exact same Subject
        // is the same "join that class instead of double-booking"
        // intent as dropping an unscheduled subject onto an occupied
        // slot (see attemptMergeDrop below for the 'new' case). Without
        // this, dropping here would just try to move the Irregular
        // section's OWN Faculty/Room/Time onto the target's slot and
        // collide with whoever's already teaching there (Faculty
        // Conflict) — even though what the user actually wants is to
        // drop their own separate booking and ride along on the
        // existing class instead. Only offered when: the block being
        // dragged is NOT itself already a merged block (nothing to ride
        // along on if it's the host of its own merge already), its
        // OWNING section is Irregular (mirrors findHostCandidates()'s
        // one-directional rule — a Regular section's class is always
        // the host, never the guest), and the target slot is a
        // DIFFERENT, already-occupied block for the same Subject.
        const dropTarget = blockAt(day, rowIndex);
        if (
            block?.section_type === 'Irregular'
            && (block.merge_members?.length ?? 0) <= 1
            && dropTarget
            && dropTarget.section_subject_id !== block.section_subject_id
            && dropTarget.subject_code === block.subject_code
        ) {
            await attemptMergeDropExisting(block, dropTarget);
            return;
        }

        // MERGED BLOCK — offer a choice instead of silently moving only
        // the collapsed "host" row and leaving its merge partner(s)
        // behind at the old slot (that mismatch is what used to happen
        // here). block.merge_members carries every underlying
        // SectionSubject row this visual block represents.
        const mergeMembers = block?.merge_members ?? [];
        let moveTargets;

        if (mergeMembers.length > 1) {
            const editableMembers = mergeMembers.filter((m) => m.can_edit);
            if (editableMembers.length === 0) return;

            const allEditable = editableMembers.length === mergeMembers.length;
            const inputOptions = {};
            if (allEditable) inputOptions.both = 'Move both sections (keep merged)';
            editableMembers.forEach((m) => {
                inputOptions[`only-${m.section_subject_id}`] = `Move only ${m.section_code}`;
            });

            const choice = await Swal.fire({
                icon: 'question',
                title: 'Move Merged Schedule',
                html: `
                    <div class="text-left text-sm space-y-1">
                        <div><strong>${block.subject_code ?? 'This subject'}</strong> — ${block.section_code}</div>
                        <div class="text-slate-500">This class is shared across sections via a merge. Move both together, or only one?</div>
                    </div>
                `,
                input: 'radio',
                inputOptions,
                inputValue: allEditable ? 'both' : Object.keys(inputOptions)[0],
                showCancelButton: true,
                confirmButtonText: 'Continue',
                cancelButtonText: 'Cancel',
                customClass: { container: 'roomgrid-swal-on-top' },
                inputValidator: (value) => (!value ? 'Choose an option to continue.' : undefined),
            });
            if (!choice.isConfirmed || !choice.value) return;

            moveTargets = choice.value === 'both'
                ? editableMembers
                : editableMembers.filter((m) => `only-${m.section_subject_id}` === choice.value);
        } else {
            moveTargets = [{
                section_subject_id: subjectId,
                section_id: block?.section_id,
                section_code: block?.section_code,
                is_current_section: block?.is_current_section,
            }];
        }

        // A "move only <one section>" pick out of a merged group is the
        // only case that needs to drop that row's merge link — see
        // clear_merge_link's docblock. Moving the whole merged group
        // together keeps them linked; a lone, never-merged block was
        // never linked to begin with.
        const clearMergeLink = mergeMembers.length > 1 && moveTargets.length === 1 && moveTargets.length < mergeMembers.length;

        const anyCrossSection = moveTargets.some((t) => t.section_id !== props.section.id);

        // For a move involving a schedule belonging to ANOTHER Section
        // (spec: "Move Schedule Assignment?" confirmation) — a direct
        // drag/drop is only "optional confirmation" for the CURRENT
        // section's own blocks, which the existing UI already treats
        // as an immediate move with no dialog.
        if (anyCrossSection) {
            const oldDayLabel = dayLabels[block.day] ?? block.day;
            const newDayLabel = dayLabels[day] ?? day;
            const otherSectionCodes = [...new Set(
                moveTargets.filter((t) => t.section_id !== props.section.id).map((t) => t.section_code),
            )].join(', ');
            const result = await Swal.fire({
                icon: 'question',
                title: 'Move Schedule Assignment?',
                html: `
                    <div class="text-left text-sm space-y-2">
                        <div><strong>${block.subject_code ?? 'This subject'}</strong> — ${block.section_code}</div>
                        <div class="text-slate-500">
                            <div>Current: ${roomLabel(selectedRoom.value)}, ${oldDayLabel} ${formatHourLabel(block.start_time)}–${formatHourLabel(block.end_time)}</div>
                            <div>New: ${roomLabel(selectedRoom.value)}, ${newDayLabel} ${formatHourLabel(startTime)}–${formatHourLabel(endTime)}</div>
                        </div>
                        <div class="pt-1">This schedule belongs to another section within your authorized scheduling scope. Moving it will modify <strong>${otherSectionCodes}</strong>'s schedule.</div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Move & Save',
                cancelButtonText: 'Cancel',
                customClass: { container: 'roomgrid-swal-on-top' },
            });
            if (!result.isConfirmed) return;
        }

        savingCell.value = `${day}-${rowIndex}`;
        let allOk = true;
        for (const target of moveTargets) {
            const targetCrossSection = target.section_id !== props.section.id;
            // eslint-disable-next-line no-await-in-loop
            const rowOk = await writeSchedule(
                target.section_subject_id,
                {
                    room_id: selectedRoom.value.id,
                    days: newDays,
                    start_time: startTime,
                    end_time: endTime,
                    // Backend independently re-derives same-section vs
                    // cross-section from these — never trusted for
                    // authorization by itself, only for telling the two
                    // cases apart (see moveRoomGridAssignment()).
                    current_section_id: props.section.id,
                    cross_section_confirmed: targetCrossSection,
                    clear_merge_link: clearMergeLink,
                },
                { crossSection: targetCrossSection, silent: true },
            );
            if (!rowOk) allOk = false;
        }
        savingCell.value = null;

        if (allOk) {
            const summary = moveTargets.length > 1
                ? `${block.subject_code ?? 'Schedule'} moved for ${moveTargets.map((t) => t.section_code).join(' & ')}.`
                : (anyCrossSection
                    ? `${block?.subject_code ?? 'Schedule'} moved for ${moveTargets[0].section_code ?? 'the other section'}.`
                    : 'Schedule updated.');
            toast.add({ severity: 'success', summary: 'Scheduled', detail: summary, life: 3000 });
        }
        // On failure, writeSchedule's own error toast already explains
        // why (Room/Faculty/Section conflict, capacity, etc.) — the
        // grid re-reads from loadRoomSchedule() either way, so a
        // failed move simply leaves the block exactly where the server
        // still has it (no local optimistic mutation to roll back).
        return;
    }

    if (mode === 'new') {
        const row = draggingRow.value;
        draggingRow.value = null;
        if (!row) return;

        // CATEGORY SCOPE (Assistant Dean = GenEd/Minor only) — the
        // sidebar's :draggable="!isRowOutOfCategory(row)" already
        // stops this in the common case, but HTML5 drag state can
        // outlive a stale render (row list refreshed mid-drag, a
        // browser that doesn't honor draggable=false consistently,
        // etc.), so the drop itself re-checks rather than trusting
        // the drag source. This is what stops a Major subject from
        // ever reaching the Schedule Subject modal for an Assistant
        // Dean — previously it opened the modal and only failed on
        // Save Schedule, which is confusing (looks like it worked,
        // then errors after filling out the form). The backend still
        // rejects it independently either way — see
        // performScheduleAssignmentUpdate() in
        // SectionSubjectController — this is purely to fail fast and
        // clearly in the UI instead of relying on that.
        if (isRowOutOfCategory(row)) {
            toast.add({
                severity: 'warn',
                summary: 'Outside your scope',
                detail: `${row.subject?.subject_code ?? 'This subject'} is a Major subject — outside your Assistant Dean scheduling scope (GenEd/Minor only). The Dean/OIC manages this subject's schedule.`,
                life: 5000,
            });
            return;
        }

        // INTELLIGENT IRREGULAR SECTION SCHEDULING, drag-and-drop entry
        // point — mirrors the "Merge Recommendation" flow already
        // available from the Subjects tab (IrregularSectionMergeService
        // via the same merge-recommendation/merge endpoints), just
        // triggered by dropping directly onto an already-occupied slot
        // instead of clicking a button. Only offered when: this Section
        // is Irregular (merging only ever makes sense in that
        // direction — a Regular section's class is the host, never the
        // guest), the target slot belongs to a DIFFERENT section
        // (blockAt(...).is_current_section === false), and it's the
        // exact same Subject. Anything else (different subject, own
        // section's own block, empty slot) falls through to the normal
        // Assign modal/conflict path unchanged.
        const targetBlock = blockAt(day, rowIndex);
        if (
            isIrregularSection.value &&
            targetBlock &&
            !targetBlock.is_current_section &&
            targetBlock.subject_code === row.subject?.subject_code
        ) {
            await attemptMergeDrop(row, targetBlock);
            return;
        }

        openAssignModal(row, day, rowIndex);
    }
};

// Section prop only ever carries section_type as a plain string (see
// Show.vue's props), so this mirrors isIrregularSection in Show.vue
// rather than depending on it directly.
const isIrregularSection = computed(() => props.section.section_type === 'Irregular');

const attemptMergeDrop = async (row, targetBlock) => {
    let recommendation;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge-recommendation', [props.section.id, row.id]),
            { headers: { Accept: 'application/json' } },
        );
        recommendation = await response.json();
        if (!response.ok) throw new Error(recommendation.message ?? 'Could not check merge compatibility.');
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not check merge compatibility.', life: 6000 });
        return;
    }

    const candidate = (recommendation.candidates ?? [])
        .find((c) => c.section_subject_id === targetBlock.section_subject_id);

    if (!candidate || !candidate.compatible) {
        toast.add({
            severity: 'warn',
            summary: 'Not a Compatible Merge',
            detail: candidate?.blocking_reason
                ?? `This class can't be merged into ${targetBlock.section_code}'s ${targetBlock.subject_code} session — try Merge Recommendation on the Subjects tab for the full reason, or drop into an empty slot to schedule it independently.`,
            life: 8000,
        });
        return;
    }

    const result = await Swal.fire({
        icon: 'question',
        title: 'Merge into this class?',
        html: `<div class="text-left text-sm">Instead of a new booking, <strong>${row.subject?.subject_code ?? 'this subject'}</strong> will join <strong>${targetBlock.section_code}</strong>'s existing class here — same Faculty, Room, and Time. No separate Room/Faculty booking is created.</div>`,
        showCancelButton: true,
        confirmButtonText: 'Merge',
        cancelButtonText: 'Cancel',
        customClass: { container: 'roomgrid-swal-on-top' },
    });
    if (!result.isConfirmed) return;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge', [props.section.id, row.id]),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ target_section_subject_id: targetBlock.section_subject_id }),
            },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply merge.');

        toast.add({ severity: 'success', summary: 'Merged', detail: data.message, life: 5000 });
        (data.sectionSubjects ?? []).forEach((fresh) => emit('row-updated', fresh));
        await loadRoomSchedule(selectedRoom.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not apply merge.', life: 6000 });
    }
};

// Same flow as attemptMergeDrop(), but for dragging an EXISTING placed
// block onto another block instead of dropping a fresh unscheduled
// subject. mergeRecommendation()/applyMerge() are scoped to the
// DRAGGED block's own Section (block.section_id) — never
// props.section.id, the currently-VIEWED Room Grid's section, since a
// cross-section-authorized user can drag a block that belongs to a
// different Section than the one they have open (see
// onDragStartBlock()'s "authorization is per-assignment" note above).
const attemptMergeDropExisting = async (block, targetBlock) => {
    let recommendation;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge-recommendation', [block.section_id, block.section_subject_id]),
            { headers: { Accept: 'application/json' } },
        );
        recommendation = await response.json();
        if (!response.ok) throw new Error(recommendation.message ?? 'Could not check merge compatibility.');
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not check merge compatibility.', life: 6000 });
        return;
    }

    const candidate = (recommendation.candidates ?? [])
        .find((c) => c.section_subject_id === targetBlock.section_subject_id);

    if (!candidate || !candidate.compatible) {
        toast.add({
            severity: 'warn',
            summary: 'Not a Compatible Merge',
            detail: candidate?.blocking_reason
                ?? `${block.section_code}'s ${block.subject_code} can't be merged into ${targetBlock.section_code}'s session — try Merge Recommendation on ${block.section_code}'s Subjects tab for the full reason, or drop into an empty slot to move it independently.`,
            life: 8000,
        });
        return;
    }

    const result = await Swal.fire({
        icon: 'question',
        title: 'Merge into this class?',
        html: `<div class="text-left text-sm">Instead of moving <strong>${block.section_code}</strong>'s own booking, <strong>${block.subject_code ?? 'this subject'}</strong> will join <strong>${targetBlock.section_code}</strong>'s existing class here — same Faculty, Room, and Time. Its separate Room/Faculty booking will be dropped.</div>`,
        showCancelButton: true,
        confirmButtonText: 'Merge',
        cancelButtonText: 'Cancel',
        customClass: { container: 'roomgrid-swal-on-top' },
    });
    if (!result.isConfirmed) return;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge', [block.section_id, block.section_subject_id]),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ target_section_subject_id: targetBlock.section_subject_id }),
            },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply merge.');

        toast.add({ severity: 'success', summary: 'Merged', detail: data.message, life: 5000 });
        (data.sectionSubjects ?? []).forEach((fresh) => emit('row-updated', fresh));
        await loadRoomSchedule(selectedRoom.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not apply merge.', life: 6000 });
    }
};

const onDragOverCell = (event) => event.preventDefault();

/* ------------------------------------------------------------------ */
/* Assign modal — Hours/Week, Meetings/Week, Days, and Faculty for a   */
/* newly-dropped subject. Duration per meeting = Hours/Week divided by */
/* Meetings/Week, applied identically to every selected Day, matching  */
/* the single start/end-time-per-row model the rest of the app uses.   */
/* ------------------------------------------------------------------ */

const assignModalVisible = ref(false);
const assignSaving = ref(false);
const assignForm = ref({
    row: null,
    day: null,
    rowIndex: null,
    hoursPerWeek: 3,
    requiredHours: 3,
    meetingsPerWeek: 1,
    selectedDays: [],
    facultyId: null,
    editing: false, // true when opened by clicking an existing block, not dragging a new one
});

const meetingsOptions = [1, 2].map((n) => ({ label: `${n}x / week`, value: n }));

// Faculty ranking — same tiered recommendation source the Subjects tab's
// FacultyRecommendationSelector uses (RecommendationService, scoped by
// College/Department — e.g. only CCS faculty tier as "College Match"
// for a CCS subject), fetched read-only via the existing
// `faculty-options` endpoint. This is intentionally NOT the same as
// FacultyRecommendationSelector's AutoComplete: that component applies
// a pick immediately via a separate `faculty-override` POST, which
// would fight with this modal's own "Save Changes" button and the
// batched Room/Days/Time/Faculty write in confirmAssign(). Here the
// fetched tiers only decide labels/sort order in the existing Select —
// the actual save still goes through writeSchedule() exactly as before.
const facultyRecommendations = ref([]); // [{ id, tier/badge, score, college, ... }]
const facultyRecommendationsLoading = ref(false);

const recommendedBadge = (f) => {
    if (f.tier === 'teaching_qualification') return 'Qualified Faculty';
    if (f.tier === 'college_match') return 'College Match';
    if (f.tier === 'general_education_match') return 'General Education Match';
    return f.selected_by_college_match ? 'College Match' : 'Qualified Faculty';
};

// Same badge-color mapping as FacultyRecommendationSelector, kept in
// sync intentionally — a badge should mean the same thing everywhere
// in the app.
const facultyBadgeSeverity = (badge) => {
    switch (badge) {
        case 'Qualified Faculty':
            return 'success';
        case 'College Match':
        case 'General Education Match':
            return 'info';
        case 'Manual Override':
            return 'warning';
        default:
            return 'secondary';
    }
};

// Remaining Teaching Load — same current_load/max_teaching_units figures
// FacultyWorkloadService already sends on every Faculty option (see
// activeFaculty in SectionSubjectController), just surfaced here so the
// Registrar can see at a glance how much room a Faculty member has left
// before picking them, instead of only finding out after Save Schedule's
// workload guard rejects an over-capacity pick.
const remainingUnitsLabel = (option) => {
    const max = option.max_teaching_units;
    const load = option.current_load ?? 0;
    if (max == null) return '';
    const remaining = max - load;
    return `${remaining} unit${remaining === 1 ? '' : 's'} left (${load}/${max})`;
};

const remainingUnitsClass = (option) => {
    const max = option.max_teaching_units;
    if (max == null) return 'text-slate-400';
    const remaining = max - (option.current_load ?? 0);
    if (remaining <= 0) return 'text-red-500 font-medium';
    if (remaining <= 3) return 'text-amber-600';
    return 'text-slate-400';
};

// A Faculty option with 0 (or negative — shouldn't happen, but the
// same "exceeds their ceiling" gap workloadWarningFor() would also
// reject) units left has no legitimate way to be picked here without
// first raising their ceiling — same threshold remainingUnitsClass()
// already uses to turn the label red, reused here to decide whether
// the "Request more units" shortcut should even show.
const hasInsufficientUnits = (option) => {
    if (option.max_teaching_units == null) return false;
    return option.max_teaching_units - (option.current_load ?? 0) <= 0;
};

// Inline "Request more units" / "Add Units" shortcut — reachable both
// from a Faculty dropdown option (see facultyOptions' #option
// template below) and from writeSchedule()'s 409 workload-warning
// handling, so a Dean/OIC gets the same way out whether they hit the
// wall while picking Faculty or while Saving. Deliberately a fetch()
// POST to the same scheduling.faculty-load-requests.store endpoint
// the Faculty Master page's Inertia form already uses — not a new
// endpoint, not a new privilege, just a faster on-ramp to the exact
// same request/approval flow (see FacultyLoadRequestController::
// store()'s docblock).
const openLoadRequestDialog = async (faculty) => {
    if (!faculty?.id) return;

    // Prefer whatever this grid currently has loaded for the faculty
    // (activeFaculty) over the possibly-stale copy the caller passed
    // in (e.g. the workload_warning payload only carries id/name),
    // so the "current ceiling" shown here is never out of date.
    const liveFaculty = props.activeFaculty.find((f) => f.id === faculty.id) ?? faculty;
    const currentMax = liveFaculty.max_teaching_units ?? 0;
    // Actual assigned teaching load — the exact same figure already
    // shown as the "Y" in this dropdown's own "X units left (Y/Z)"
    // label (see remainingUnitsLabel()), sourced from
    // FacultyWorkloadService via SectionSubjectController::show().
    const currentLoad = liveFaculty.current_load ?? 0;

    // BUG FIX — a Faculty member can already be carrying MORE than
    // their own ceiling (current_load > max_teaching_units) after an
    // Administrator's workload override on Save (see writeSchedule()'s
    // can_override branch above, or a manual override elsewhere) — the
    // exact scenario in the screenshot this fixed ("15 / 12 Units", -3
    // left). The old default here was just "current max + 1", which
    // silently assumed max_teaching_units was still an accurate
    // ceiling and left them just as visibly over-capacity afterward.
    // Floor the minimum (and default) requested amount at whichever of
    // "current max + 1" or "actual current load" is higher, so the
    // result can never show negative units left. Same floor
    // FacultyLoadRequestController::store() re-enforces server-side as
    // a safety net regardless of what this dialog actually sends.
    //
    // JUDGMENT CALL: when the floor is driven by current_load (i.e.
    // this faculty was already over capacity), the default prefills to
    // EXACTLY current_load — 0 units left, no extra buffer — rather
    // than current_load + N. Reasoning: this path only fires to
    // correct an already-broken state, and the number is a plain
    // editable input the Admin/Registrar can bump up themselves in two
    // seconds if they want headroom for what's coming next; guessing a
    // buffer here would just be a second, less-informed judgment call
    // stacked on top of theirs. The ordinary "current max + 1" default
    // (faculty not over capacity) is unchanged from before.
    const minRequestable = Math.max(currentMax + 1, currentLoad);

    if (minRequestable > props.hardCapUnits) {
        // No valid ceiling can be offered here — even the floor alone
        // already breaches the institution-wide cap (effectiveCapFor()
        // on the backend). Surface this plainly instead of opening a
        // dialog whose only "valid" values the backend would reject.
        toast.add({
            severity: 'error',
            summary: 'Cannot request',
            detail: `${liveFaculty.full_name ?? 'This faculty member'}'s current teaching load (${currentLoad}) already exceeds the institution-wide ceiling (${props.hardCapUnits} units). Raise the ceiling under Settings > Faculty & Workload, or reduce their assigned load, before requesting a change here.`,
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
                    ${liveFaculty.full_name ?? 'This faculty member'}'s current ceiling is ${currentMax} unit(s)${currentLoad > currentMax ? `, but they're already assigned ${currentLoad} unit(s) of actual teaching load` : ''}.
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Requested Max Teaching Units</label>
                    <input id="swal-load-units" type="number" class="swal2-input !m-0 !w-full" min="${minRequestable}" max="${props.hardCapUnits}" value="${defaultRequested}" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Reason</label>
                    <textarea id="swal-load-reason" class="swal2-textarea !m-0 !w-full" rows="3" placeholder="Why does this faculty member need a higher teaching load? (min. 10 characters)"></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: canChangeMaxLoad.value ? 'Add Units' : 'Submit Request',
        cancelButtonText: 'Cancel',
        focusConfirm: false,
        customClass: { container: 'roomgrid-swal-on-top' },
        // Mirrors StoreFacultyLoadRequestRequest's own rules
        // client-side purely as a convenience — the server re-validates
        // everything again regardless (including the current_load floor
        // above), this just saves an obviously doomed round trip.
        preConfirm: () => {
            const units = Number(document.getElementById('swal-load-units')?.value);
            const reason = (document.getElementById('swal-load-reason')?.value ?? '').trim();

            if (!units || units < minRequestable) {
                Swal.showValidationMessage(
                    currentLoad > currentMax
                        ? `Requested units must be at least ${minRequestable} — below this faculty member's actual current teaching load (${currentLoad}), the units-left count would still show negative.`
                        : `Requested units must be higher than the current maximum (${currentMax}).`,
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
                faculty_id: faculty.id,
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
            // Update the local Faculty data the dropdown reads from,
            // in place — activeFaculty is the same reactive prop
            // Show.vue passed down, so every "X units left" label
            // fed by it (including this dropdown's own, mid-open)
            // reflects the new ceiling immediately, with no full
            // page reload and no need to leave the Room Grid.
            const target = props.activeFaculty.find((f) => f.id === faculty.id);
            if (target) {
                if (data.max_teaching_units != null) target.max_teaching_units = data.max_teaching_units;
                if (data.max_weekly_hours != null) target.max_weekly_hours = data.max_weekly_hours;
            }
            toast.add({ severity: 'success', summary: 'Units added', detail: data.message ?? 'Faculty load ceiling updated.', life: 5000 });
            return;
        }

        // Pending — still requires Admin/Registrar review, exactly
        // like every other non-Admin/Registrar submission. Make clear
        // this faculty still can't be picked for THIS assignment yet.
        toast.add({
            severity: 'warn',
            summary: 'Pending approval',
            detail: `${data.message ?? 'Load change request submitted for review.'} You won't be able to select this faculty for this assignment until it's approved.`,
            life: 8000,
        });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not submit the request.', life: 5000 });
    }
};

const loadFacultyRecommendations = async (sectionSubjectId) => {
    if (!sectionSubjectId) {
        facultyRecommendations.value = [];
        return;
    }
    facultyRecommendationsLoading.value = true;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.faculty-options', [props.section.id, sectionSubjectId]),
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();
        facultyRecommendations.value = (data.recommended ?? []).map((f) => ({
            ...f,
            badge: recommendedBadge(f),
        }));
    } catch (e) {
        facultyRecommendations.value = []; // fall back to the plain full-name-sorted list below
    } finally {
        facultyRecommendationsLoading.value = false;
    }
};

// This Section's own owning College — e.g. a BSIT Section resolves to
// the College of Computer Studies (CCS), a BSED Section to the College
// of Teacher Education (CTE), and so on. Same relation chain the
// Subjects tab's facultyGroupsFor() uses (section->major->department->college),
// so the Room Grid's "Schedule Subject" modal groups Faculty by College
// the exact same way the Subjects tab already does, rather than a flat
// list with only a small "College Match" tag to notice.
const sectionCollegeId = computed(() => props.section.major?.department?.college_id ?? null);
const sectionCollegeName = computed(() => props.section.major?.department?.college?.short_name
    ?? props.section.major?.department?.college?.name
    ?? null);

const isQualifiedFor = (faculty, subject) => {
    if (!subject) return false;
    // An explicit Teaching Qualification always wins, regardless of
    // Subject category or the faculty's own College — mirrors
    // SectionSubject::faculty_mismatch on the server, which never
    // flags a mismatch for someone explicitly linked to the Subject.
    // This must be checked BEFORE the General Education branch below:
    // a College faculty member (e.g. College of Teacher Education)
    // can still carry an explicit qualification for a GenEd subject
    // like TCW, and that shouldn't be overridden by their College not
    // being "General Education".
    if (faculty.qualified_subject_ids?.includes(subject.id)) return true;
    if (subject.category === 'General Education') {
        return faculty.faculty_category === 'General Education Faculty' || faculty.college_id === null;
    }
    if (sectionCollegeId.value !== null) {
        return faculty.college_id === sectionCollegeId.value;
    }
    return false;
};

// Grouped Faculty options for the "Schedule Subject" modal's Select —
// mirrors the Subjects tab's facultyGroupsFor() exactly (Recommended /
// [This Section's College] Faculty / Other Active Faculty), so a BSED
// (CTE) subject's Room Grid modal groups faculty under "College of
// Teacher Education Faculty" the same way a BSIT (CCS) subject's
// modal — and the Subjects tab itself — groups them under "College of
// Computer Studies Faculty". Falls back to a flat, ungrouped list
// (previous behavior) while no subject/row is known yet.
const facultyOptions = computed(() => {
    const subject = assignForm.value.row?.subject ?? null;
    const recommended = new Map(facultyRecommendations.value.map((f) => [f.id, f]));

    const decorated = [...props.activeFaculty].map((f) => {
        const rec = recommended.get(f.id);
        return {
            ...f,
            full_name: f.full_name,
            recommended: !!rec,
            badge: rec?.badge ?? null,
            score: rec?.score ?? null,
            college: rec?.college ?? f.college_name ?? null,
        };
    });

    if (!subject) {
        return decorated.sort((a, b) => (b.recommended - a.recommended) || (b.score ?? -1) - (a.score ?? -1) || a.full_name.localeCompare(b.full_name));
    }

    const recommendedItems = [];
    const qualified = [];
    const others = [];

    decorated.forEach((faculty) => {
        if (faculty.recommended) {
            recommendedItems.push(faculty);
            return;
        }
        if (isQualifiedFor(faculty, subject)) {
            qualified.push(faculty);
        } else {
            others.push(faculty);
        }
    });

    recommendedItems.sort((a, b) => (b.score ?? -1) - (a.score ?? -1) || a.full_name.localeCompare(b.full_name));
    qualified.sort((a, b) => a.full_name.localeCompare(b.full_name));
    others.sort((a, b) => a.full_name.localeCompare(b.full_name));

    const qualifiedGroupLabel = subject.category === 'General Education'
        ? 'General Education Faculty'
        : (sectionCollegeName.value ? `${sectionCollegeName.value} Faculty` : 'Qualified for This Subject');

    const groups = [];
    if (facultyRecommendationsLoading.value && !recommendedItems.length) {
        groups.push({ label: 'Recommended', items: [{ full_name: 'Finding the best matches…', id: '__loading__', disabled: true }], isRecommended: true });
    } else if (recommendedItems.length) {
        groups.push({ label: 'Recommended', items: recommendedItems, isRecommended: true });
    }
    if (qualified.length) groups.push({ label: qualifiedGroupLabel, items: qualified });
    if (others.length) groups.push({ label: 'Other Active Faculty (Manual Override)', items: others });
    return groups;
});

const openAssignModal = async (row, day, rowIndex) => {
    const subject = row.subject || {};
    const defaultHours = (Number(subject.lecture_hours) || 0) + (Number(subject.laboratory_hours) || 0) || 3;

    // SHARED CLASS — if this exact slot is already occupied by a
    // DIFFERENT section's class for the SAME subject, prefill the
    // form to match that existing class's Faculty/Days/Hours instead
    // of the subject's own defaults. Without this, the form opens
    // with "Unassigned" Faculty and the curriculum's default Hours/
    // Week — which almost never happens to equal the existing class's
    // own Faculty/Time, so Save then fails on an unrelated Faculty/
    // Section conflict instead of ever reaching the "Combine
    // Sections?" check in confirmAssign(). Matching the values up
    // front is what makes that check actually fire on Save.
    const targetBlock = blockAt(day, rowIndex);
    const isShareable = targetBlock
        && !targetBlock.is_current_section
        && targetBlock.subject_code === subject.subject_code;

    if (isShareable) {
        const targetDays = (targetBlock.days || '').split(',').filter(Boolean);
        const meetingsPerWeek = targetDays.length || 1;
        const perMeetingHours = ((toMinutes(targetBlock.end_time) - toMinutes(targetBlock.start_time)) || 60) / 60;

        assignForm.value = {
            row,
            day,
            rowIndex,
            hoursPerWeek: Math.round(perMeetingHours * meetingsPerWeek * 100) / 100,
            requiredHours: defaultHours,
            meetingsPerWeek,
            selectedDays: targetDays.includes(day) ? [...targetDays] : [day],
            facultyId: targetBlock.faculty_id ?? null,
            editing: false,
            siblingPatternSource: null,
        };
        assignModalVisible.value = true;
        loadFacultyRecommendations(row.id);
        return;
    }

    // Open immediately with the plain curriculum defaults — the
    // sibling lookup below is a network round trip and shouldn't make
    // the modal feel like it's hanging.
    assignForm.value = {
        row,
        day,
        rowIndex,
        hoursPerWeek: defaultHours,
        requiredHours: defaultHours,
        meetingsPerWeek: 1,
        selectedDays: [day],
        facultyId: row.faculty_id ?? null,
        editing: false,
        siblingPatternSource: null,
    };
    assignModalVisible.value = true;
    loadFacultyRecommendations(row.id);

    // SIBLING SECTION PATTERN — prefill (never silently apply)
    // Hours/Week, Meetings/Week, Days, and Faculty from a sibling
    // Section's already-scheduled pattern for this exact Subject
    // (same Major/Curriculum/Academic Year/Semester/Year Level — see
    // SiblingSectionPatternService), when dropping into an otherwise
    // empty slot. Without this, dropping into any slot that ISN'T the
    // sibling's own exact occupied cell (the isShareable branch above)
    // always fell back to the curriculum's textbook Hours/Week even
    // when a sibling section had already been intentionally trimmed
    // (e.g. Registrar manually confirmed 4 hrs/week instead of the
    // curriculum's 5 for a faculty/room-availability reason) — forcing
    // the same manual retyping + re-confirmation on every sibling
    // section instead of just once.
    //
    // This mirrors Auto Generate Schedule's own use of the sibling
    // pattern (AutoScheduleService::applySiblingPattern) but is purely
    // a convenience default here: it does NOT set hours_confirmed, so
    // if the copied hours still diverge from the curriculum, Save
    // Schedule asks for the exact same "Weekly Hours Mismatch —
    // Save Anyway" confirmation as always. Room is deliberately left
    // alone (the Registrar already chose it by dropping here); only
    // Hours/Week, Meetings/Week, Days, and Faculty are prefilled.
    try {
        const response = await fetch(
            route('scheduling.section-subjects.recommend', [props.section.id, row.id]),
            { headers: { Accept: 'application/json' } },
        );
        if (!response.ok) return;
        const data = await response.json();
        const top = data.combined?.recommendations?.[0];
        if (!top?.is_sibling_pattern) return;

        // Guard against a slow response landing after the modal was
        // closed or reopened for a different row/drop in the meantime.
        if (!assignModalVisible.value || assignForm.value.row?.id !== row.id || assignForm.value.day !== day) return;

        const patternDays = top.time?.days ?? [];
        const meetingsPerWeek = patternDays.length || 1;
        const perMeetingHours = ((toMinutes(top.time.end_time) - toMinutes(top.time.start_time)) || 60) / 60;

        assignForm.value = {
            ...assignForm.value,
            hoursPerWeek: Math.round(perMeetingHours * meetingsPerWeek * 100) / 100,
            meetingsPerWeek,
            selectedDays: patternDays.includes(day) ? [...patternDays] : [day],
            facultyId: top.faculty?.id ?? assignForm.value.facultyId,
            siblingPatternSource: top.pattern_source?.donor_section_code ?? null,
        };
    } catch (e) {
        // Silent — the plain curriculum defaults are already shown and
        // remain a perfectly valid starting point on their own.
    }
};

// Click an existing block belonging to this section to edit its
// Faculty, Hours/Week, Meetings/Week, and Days — the same form the
// drag-a-new-subject flow uses, prefilled from the block's current
// values, writing through the same updateSchedule() endpoint. Start
// time/slot and Room stay as-is here; use drag-to-move for those.
const openEditModal = (block) => {
    const row = props.rows.find((r) => r.id === block.section_subject_id);
    if (!row) return;

    const subject = row.subject || {};
    const requiredHours = (Number(subject.lecture_hours) || 0) + (Number(subject.laboratory_hours) || 0) || 3;
    const rowDays = (row.days || []);
    const meetingsPerWeek = rowDays.length || 1;
    const perMeetingHours = ((toMinutes(row.end_time) - toMinutes(row.start_time)) || 60) / 60;
    const startTime = row.start_time || hourRows.value[0];
    const rowIndex = Math.max(0, hourRows.value.indexOf(startTime));

    assignForm.value = {
        row,
        day: null, // no fixed/locked day in edit mode — all days toggle freely
        rowIndex,
        hoursPerWeek: Math.round(perMeetingHours * meetingsPerWeek * 100) / 100,
        requiredHours,
        meetingsPerWeek,
        selectedDays: [...rowDays],
        facultyId: row.faculty_id ?? null,
        editing: true,
        siblingPatternSource: null,
    };
    assignModalVisible.value = true;
    loadFacultyRecommendations(row.id);
};

// Keep the dropped day always selected; toggling others in/out of the
// meeting pattern, capped at Meetings/Week.
const toggleAssignDay = (day) => {
    if (day === assignForm.value.day) return; // the dropped day/slot is fixed
    const selected = assignForm.value.selectedDays;
    const idx = selected.indexOf(day);
    if (idx !== -1) {
        selected.splice(idx, 1);
    } else if (selected.length < assignForm.value.meetingsPerWeek) {
        selected.push(day);
    }
};

watch(() => assignForm.value.meetingsPerWeek, (n) => {
    // Trim extra days if Meetings/Week was lowered below the current
    // selection; always keep the originally-dropped day — but only
    // when there IS one. In edit mode (openEditModal) `day` is
    // deliberately null since no day is locked, so forcing it back in
    // would splice a `null` into selectedDays — which Laravel's
    // `days.0` validation then rejects as "invalid", surfacing as a
    // Conflict toast with no visible cause. Only re-pin the dropped
    // day when we actually have one (the drag-a-new-subject flow).
    if (assignForm.value.selectedDays.length > n) {
        assignForm.value.selectedDays = assignForm.value.selectedDays.slice(0, Math.max(1, n));
        if (assignForm.value.day && !assignForm.value.selectedDays.includes(assignForm.value.day)) {
            assignForm.value.selectedDays[0] = assignForm.value.day;
        }
    }

    // Raising Meetings/Week raises the per-meeting floor too (see
    // minHoursPerWeek) — bump Hours/Week up if the current total
    // would now split into sub-1-hr sessions.
    if (Number(assignForm.value.hoursPerWeek) < minHoursPerWeek.value) {
        assignForm.value.hoursPerWeek = minHoursPerWeek.value;
    }
});

const assignDaysValid = computed(() => assignForm.value.selectedDays.length === assignForm.value.meetingsPerWeek);

// Mirrors the server's own check (SectionSubjectController) so the
// Registrar sees the mismatch — and why Save is disabled — before
// clicking, instead of after a round-trip Conflict toast.
// A single meeting shorter than this isn't a real class session (e.g.
// a 30-min slot). This is a floor on PER-MEETING duration — since
// Hours/Week is split across Meetings/Week, the Hours/Week input's
// own minimum must scale with Meetings/Week so a 2x/week subject
// can't be dragged down to two 30-min sessions via a 1-hr weekly
// total.
const MIN_SESSION_HOURS = 1;

const minHoursPerWeek = computed(() => {
    const floor = MIN_SESSION_HOURS * (assignForm.value.meetingsPerWeek || 1);
    // Never let the floor exceed the curriculum's own required hours
    // — a subject with fewer required hours than the floor would
    // otherwise be impossible to save at all.
    return Math.min(floor, assignForm.value.requiredHours || floor);
});

const assignHoursValid = computed(() => Number(assignForm.value.hoursPerWeek) === Number(assignForm.value.requiredHours));

// ROOM TYPE MISMATCH — mirrors assignHoursValid's "check client-side
// before Save, confirmable, not a hard block" pattern. A Minor/GenEd
// (or any Lecture-only) subject dropped into a Laboratory room, or a
// Laboratory subject dropped into a plain Lecture room, still saves
// once the Registrar explicitly confirms — see confirmAssign()'s
// "Room Type Mismatch" Swal below. The authoritative check is still
// server-side (performScheduleAssignmentUpdate()'s room_type error,
// caught generically by writeSchedule()'s CONFIRMABLE_WARNING_KEYS)
// — this is only a same-page heads-up so the Registrar sees it the
// moment they drop, not only after the round trip.
const assignRoomTypeValid = computed(() => {
    const room = selectedRoom.value;
    const subject = assignForm.value.row?.subject;
    if (!room?.room_type || !subject) return true;
    const wantsLaboratory = Number(subject.laboratory_hours ?? 0) > 0;
    return wantsLaboratory ? room.room_type === 'Laboratory' : room.room_type !== 'Laboratory';
});

// ROOM COLLEGE MISMATCH — same "check client-side before Save,
// confirmable, not a hard block" pattern as assignRoomTypeValid
// above. A room owned by a DIFFERENT College than this Section's own
// (e.g. an SHTM Lab dropped for a BSIT class) still saves once the
// Registrar explicitly confirms — see confirmAssign()'s "Room
// Belongs to Another College" Swal below. Shared rooms (no
// college_id) and General Education subjects (no single owning
// College) are never flagged, mirroring the Subjects tab's own
// roomCollege check in SectionSubjects/Show.vue.
const assignRoomCollegeValid = computed(() => {
    const room = selectedRoom.value;
    const subject = assignForm.value.row?.subject;
    if (!room?.college_id || !sectionCollegeId.value) return true;
    if (subject?.category === 'General Education') return true;
    return room.college_id === sectionCollegeId.value;
});

// FACULTY MISMATCH — advisory-only heads-up (never blocks Save,
// mirrors assignRoomTypeValid/assignRoomCollegeValid's "confirmable,
// not a hard block" spirit, except there's no confirm dialog here —
// Manual Override is already a first-class supported path via the
// "Other Active Faculty (Manual Override)" group above). Reuses the
// exact same isQualifiedFor() rule the Faculty dropdown's grouping
// already uses, so a Faculty picked from the "Manual Override" group
// is exactly the case this flags. The server computes the
// authoritative version of this as SectionSubject::faculty_mismatch
// (see app/Models/SectionSubject.php), which is what actually
// persists and drives the Master Grid badge after Save — this is
// only a same-page heads-up before the round trip.
const assignFacultyMismatch = computed(() => {
    const facultyId = assignForm.value.facultyId;
    const subject = assignForm.value.row?.subject;
    if (!facultyId || !subject) return false;
    const faculty = props.activeFaculty.find((f) => f.id === facultyId);
    if (!faculty) return false;
    return !isQualifiedFor(faculty, subject);
});

const perMeetingPreview = computed(() => {
    const step = intervalMinutes.value;
    const totalMinutes = (assignForm.value.hoursPerWeek || 0) * 60;
    const minutes = Math.max(step, Math.round((totalMinutes / assignForm.value.meetingsPerWeek) / step) * step);
    return minutes / 60;
});

// SAME-SECTION OWN-SCHEDULE CONFLICT — checked entirely client-side
// against props.rows (this Section's own SectionSubject rows, already
// loaded for the Subjects tab), so the Registrar sees "BSIT-3A already
// has CAP101 at this time" the moment they configure a drop — not only
// after Save round-trips to the server and ScheduleConflictService's
// findSectionConflict() (step 5 of validate()) rejects it. That
// server-side check is still the actual enforcement (this is a warning
// aid, not a replacement for it) — but a Section can never really be in
// two classes at once regardless of which Room each is in, so this
// mirrors that same rule locally using the day/time the modal is
// currently configured for (assignForm.selectedDays + the dropped
// start time + the live Hours/Meetings preview), not the numbers last
// saved.
const sectionScheduleConflict = computed(() => {
    const { row, rowIndex, selectedDays, meetingsPerWeek } = assignForm.value;
    if (!row || !selectedDays.length || selectedDays.length !== meetingsPerWeek) return null;

    const step = intervalMinutes.value;
    const totalMinutes = (assignForm.value.hoursPerWeek || 0) * 60;
    const perMeetingMinutes = Math.max(step, Math.round((totalMinutes / meetingsPerWeek) / step) * step);
    const newStart = toMinutes(hourRows.value[rowIndex]);
    const newEnd = newStart + perMeetingMinutes;

    for (const other of props.rows) {
        if (other.id === row.id) continue; // never conflicts with itself
        if (!other.days || !other.start_time || !other.end_time) continue;

        const otherDays = String(other.days).split(',').filter(Boolean);
        if (!selectedDays.some((d) => otherDays.includes(d))) continue;

        const otherStart = toMinutes(other.start_time);
        const otherEnd = toMinutes(other.end_time);
        if (newStart < otherEnd && newEnd > otherStart) {
            const sharedDay = selectedDays.find((d) => otherDays.includes(d));
            return {
                subject_code: other.subject?.subject_code,
                room_code: other.room?.room_code,
                day: sharedDay,
                start_time: other.start_time,
                end_time: other.end_time,
            };
        }
    }
    return null;
});

const confirmAssign = async () => {
    const { row, rowIndex, hoursPerWeek, meetingsPerWeek, selectedDays, requiredHours } = assignForm.value;
    if (!row || !assignDaysValid.value || !hoursPerWeek || hoursPerWeek <= 0) return;

    // Section Conflict — hard block, not confirmable. Unlike Weekly
    // Hours Mismatch above, there's no legitimate reason to schedule
    // the same Section into two classes at the same day/time, so this
    // stops Save entirely rather than offering "Save Anyway" — the
    // server would reject it either way (findSectionConflict()), this
    // just saves the round trip and shows the reason inline instead.
    if (sectionScheduleConflict.value) return;

    // Weekly Hours Mismatch — confirmable, but NOT here. Room Grid is an
    // instant place/drag surface, not the final confirmation point —
    // that's the Subjects tab's batch "Save Schedule" (see its own
    // tableConflicts()/"Save Anyway" flow in Show.vue). So this only
    // warns and, if the Registrar proceeds, the placement saves with
    // defer_mismatch_confirmation so the server accepts it WITHOUT
    // marking hours_confirmed true — it stays a live Scheduling Issue
    // until confirmed for real on the Subjects tab.
    let deferMismatchConfirmation = false;
    if (!assignHoursValid.value) {
        // Hide the Schedule Subject dialog while the confirm popup is
        // up rather than relying on z-index to win against PrimeVue's
        // own dynamically-assigned Dialog stacking — guarantees "Place
        // Anyway" is always reachable regardless of stacking context.
        assignModalVisible.value = false;
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Weekly Hours Mismatch',
            html: `<div class="text-left text-sm">This schedule totals ${hoursPerWeek} hrs/week, but ${row.subject?.subject_code ?? 'this subject'} requires ${requiredHours} hrs/week. This will still need to be confirmed on the Subjects tab's "Save Schedule" before it's fully resolved.</div>`,
            showCancelButton: true,
            confirmButtonText: 'Place Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
            customClass: { container: 'roomgrid-swal-on-top' },
        });
        if (!result.isConfirmed) {
            assignModalVisible.value = true; // reopen so they can adjust
            return;
        }
        deferMismatchConfirmation = true;
    }

    // Room Type Mismatch — same deferred-confirmation pattern as
    // Weekly Hours Mismatch above: the Registrar acknowledges it here
    // just to place the subject, but the mismatch only actually
    // resolves via the Subjects tab's "Save Schedule".
    if (!assignRoomTypeValid.value) {
        assignModalVisible.value = false;
        const wantsLaboratory = Number(row.subject?.laboratory_hours ?? 0) > 0;
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Room Type Mismatch',
            html: `<div class="text-left text-sm">${row.subject?.subject_code ?? 'This subject'} is a ${wantsLaboratory ? 'Laboratory' : 'Lecture'} subject, but ${roomLabel(selectedRoom.value)} is a ${selectedRoom.value?.room_type ?? 'different type of'} room. This will still need to be confirmed on the Subjects tab's "Save Schedule" before it's fully resolved.</div>`,
            showCancelButton: true,
            confirmButtonText: 'Place Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
            customClass: { container: 'roomgrid-swal-on-top' },
        });
        if (!result.isConfirmed) {
            assignModalVisible.value = true; // reopen so they can adjust
            return;
        }
        deferMismatchConfirmation = true;
    }

    // Room College Mismatch — same deferred-confirmation pattern as
    // above.
    if (!assignRoomCollegeValid.value) {
        assignModalVisible.value = false;
        const roomCollegeLabel = selectedRoom.value?.college_name ?? 'another college';
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Room Belongs to Another College',
            html: `<div class="text-left text-sm">${row.subject?.subject_code ?? 'This subject'} belongs to this section's own college, but ${roomLabel(selectedRoom.value)} belongs to ${roomCollegeLabel}. This will still need to be confirmed on the Subjects tab's "Save Schedule" before it's fully resolved.</div>`,
            showCancelButton: true,
            confirmButtonText: 'Place Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
            customClass: { container: 'roomgrid-swal-on-top' },
        });
        if (!result.isConfirmed) {
            assignModalVisible.value = true; // reopen so they can adjust
            return;
        }
        deferMismatchConfirmation = true;
    }

    // Faculty Mismatch — same deferred-confirmation pattern as Room
    // Type/Room College Mismatch above: the Registrar acknowledges it
    // here just to place the subject, but the mismatch only actually
    // resolves via the Subjects tab's "Save Schedule".
    if (assignFacultyMismatch.value) {
        assignModalVisible.value = false;
        const facultyOption = props.activeFaculty.find((f) => f.id === assignForm.value.facultyId);
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Faculty Mismatch',
            html: `<div class="text-left text-sm">${facultyOption?.full_name ?? 'This faculty member'} is not on file as qualified or from the academic home for ${row.subject?.subject_code ?? 'this subject'}. This will still need to be confirmed on the Subjects tab's "Save Schedule" before it's fully resolved.</div>`,
            showCancelButton: true,
            confirmButtonText: 'Place Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
            customClass: { container: 'roomgrid-swal-on-top' },
        });
        if (!result.isConfirmed) {
            assignModalVisible.value = true; // reopen so they can adjust
            return;
        }
        deferMismatchConfirmation = true;
    }

    // Duration per meeting, rounded to the nearest 30 minutes so the
    // grid (hourly rows) still reads cleanly, minimum 30 minutes.
    const totalMinutes = hoursPerWeek * 60;
    // Round to the nearest grid interval (matches the Academic Term's
    // Time Interval setting) so the resulting block always lands
    // cleanly on a grid row.
    const step = intervalMinutes.value;
    const perMeeting = Math.max(step, Math.round((totalMinutes / meetingsPerWeek) / step) * step);
    const startTime = hourRows.value[rowIndex];
    const endTime = toHHMM(toMinutes(startTime) + perMeeting);

    const schedulePayload = {
        room_id: selectedRoom.value.id,
        days: selectedDays,
        start_time: startTime,
        end_time: endTime,
        faculty_id: assignForm.value.facultyId,
        // Deliberately never sent as true from Room Grid — see the
        // deferMismatchConfirmation dialogs above. Only the Subjects
        // tab's batch "Save Schedule" is allowed to actually confirm
        // these.
        hours_confirmed: false,
        room_type_confirmed: false,
        room_college_confirmed: false,
        faculty_mismatch_confirmed: false,
        defer_mismatch_confirmation: deferMismatchConfirmation,
    };

    // SHARED CLASS — the exact slot this row is about to be saved at
    // may already be occupied by a DIFFERENT section's class for the
    // SAME subject (e.g. dropping a Regular section's CAP102 onto an
    // Irregular section's existing CAP102 booking). Rather than let
    // that go straight to writeSchedule() and dead-end on a plain
    // "Faculty Conflict", check whether the two can share the
    // schedule and, if so, offer to combine them — same pattern as
    // attemptMergeDrop()/attemptMergeDropExisting() already use for
    // the direct drag-onto-occupied-cell case, just covering the
    // Assign modal's manual Save path too.
    const mergeTargetId = await checkSharedClassOpportunity(row, schedulePayload);
    if (mergeTargetId === 'declined') {
        assignModalVisible.value = true; // reopen so they can adjust
        return;
    }
    if (mergeTargetId) {
        schedulePayload.merge_target_section_subject_id = mergeTargetId;
    }

    assignSaving.value = true;
    const ok = await writeSchedule(row.id, schedulePayload, {
        successMessage: mergeTargetId
            ? `${row.subject?.subject_code ?? 'Subject'} combined into the shared class.`
            : `${row.subject?.subject_code ?? 'Subject'} ${assignForm.value.editing ? 'updated' : 'scheduled'}.`,
    });
    assignSaving.value = false;

    // If the write itself failed (e.g. a Faculty/Room conflict caught
    // server-side), bring the form back so they can see the error
    // toast and adjust rather than being left with nothing on screen.
    assignModalVisible.value = !ok;
};

// SHARED CLASS — checks whether the slot this row is about to save at
// is already occupied by a DIFFERENT section's class for the SAME
// subject, and if the two are compatible to share it, asks the
// Registrar to confirm before anything is written. Returns:
//   - a section_subject_id  → confirmed, include as merge_target_section_subject_id
//   - null                  → no occupied/compatible target, proceed as a normal save
//   - 'declined'            → a compatible target exists but the Registrar said No
const checkSharedClassOpportunity = async (row, schedulePayload) => {
    const targetBlock = blockAt(assignForm.value.day, assignForm.value.rowIndex);
    if (
        !targetBlock
        || targetBlock.is_current_section
        || targetBlock.subject_code !== row.subject?.subject_code
    ) {
        return null;
    }

    let evaluation;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.reverse-merge-recommendation', [props.section.id, row.id])
                + `?${new URLSearchParams({
                    target_section_subject_id: targetBlock.section_subject_id,
                    room_id: schedulePayload.room_id,
                    faculty_id: schedulePayload.faculty_id ?? '',
                    start_time: schedulePayload.start_time,
                    end_time: schedulePayload.end_time,
                    ...Object.fromEntries(schedulePayload.days.map((d, i) => [`days[${i}]`, d])),
                }).toString()}`,
            { headers: { Accept: 'application/json' } },
        );
        evaluation = await response.json();
        if (!response.ok) return null;
    } catch (e) {
        return null; // fall through to the normal save/conflict path
    }

    if (!evaluation.compatible) {
        // Everything lined up (same subject/program/term, exact
        // Faculty/Room/Day/Time match) EXCEPT room capacity — this is
        // specifically a "Cannot Combine" case, not a silent
        // fallthrough, since the two classes clearly could share this
        // schedule if the room were bigger.
        if (evaluation.capacity > 0 && evaluation.projected_headcount > evaluation.capacity) {
            assignModalVisible.value = false;
            await Swal.fire({
                icon: 'error',
                title: 'Cannot Combine',
                html: `<div class="text-left text-sm">The combined enrollment exceeds the room capacity by ${evaluation.projected_headcount - evaluation.capacity} students. Please select a larger room or a different schedule.</div>`,
                confirmButtonText: 'OK',
                customClass: { container: 'roomgrid-swal-on-top' },
            });
            return 'declined';
        }
        // Otherwise not shareable (different program, not an exact
        // match, etc.) — fall through silently to the normal save,
        // which will report the real conflict if there is one.
        return null;
    }

    assignModalVisible.value = false;
    const result = await Swal.fire({
        icon: 'question',
        title: 'Combine Sections?',
        html: `
            <div class="text-left text-sm space-y-2">
                <div>This section has the same subject, faculty, room, and schedule as an existing class.</div>
                <div class="space-y-0.5">
                    <div>Existing: <strong>${targetBlock.section_code}</strong></div>
                    <div>New: <strong>${props.section.section_code}</strong></div>
                </div>
                <div>Both sections can share this class schedule.</div>
                <div class="pt-1 text-slate-600">
                    Room capacity: ${evaluation.capacity}<br>
                    Current students: ${evaluation.current_headcount}<br>
                    New students: ${evaluation.projected_headcount - evaluation.current_headcount}<br>
                    Combined: ${evaluation.projected_headcount} / ${evaluation.capacity}
                </div>
                <div class="pt-1">Do you want to combine these sections?</div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Combine Sections',
        cancelButtonText: 'Cancel',
        customClass: { container: 'roomgrid-swal-on-top' },
    });

    return result.isConfirmed ? targetBlock.section_subject_id : 'declined';
};

// Remove this block from the room — only ever reachable in Edit mode
// (an existing placement). This does NOT delete the Subject from the
// Section; it just clears Room/Days/Time/Faculty back to unscheduled
// via the same updateSchedule() endpoint everything else here uses, so
// the row goes back to "Unscheduled Subjects" and can be re-placed
// later. A Practicum row is never placed on this grid in the first
// place (see UpdateSectionSubjectScheduleRequest), so no special-case
// is needed here for that.
const assignRemoving = ref(false);

const removeAssignment = async () => {
    const { row } = assignForm.value;
    if (!row) return;

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Remove from Room?',
        html: `<div class="text-left text-sm">This will unassign <strong>${row.subject?.subject_code ?? 'this subject'}</strong> from ${roomLabel(selectedRoom.value)}. It will go back to Unscheduled Subjects — you can place it again anytime.</div>`,
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        customClass: { container: 'roomgrid-swal-on-top' },
    });
    if (!result.isConfirmed) return;

    assignRemoving.value = true;
    const ok = await writeSchedule(row.id, {
        room_id: null,
        days: [],
        start_time: null,
        end_time: null,
        faculty_id: null,
    }, { successMessage: `${row.subject?.subject_code ?? 'Subject'} removed from ${roomLabel(selectedRoom.value)}.` });
    assignRemoving.value = false;

    if (ok) {
        assignModalVisible.value = false;
    }
};
</script>

<template>
    <div class="flex flex-col lg:flex-row gap-4">
        <!-- LEFT SIDEBAR: Room search / selection -->
        <div class="w-full lg:w-40 shrink-0 space-y-3">
            <div>
                <span class="relative w-full block">
                    <i class="pi pi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <InputText v-uppercase v-model="roomSearch" placeholder="Search rooms" class="neu-inset w-full !rounded-lg !border-none !pl-8 !text-xs !py-1.5" />
                </span>
            </div>

            <div class="neu-inset rounded-xl p-2.5">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Recommended Rooms</p>
                <div class="flex items-center gap-3 text-[9px] text-slate-400 mb-1.5">
                    <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-sky-400"></span>Lecture</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-purple-400"></span>Laboratory</span>
                </div>
                <div v-if="roomsLoading" class="text-xs text-slate-400 py-1.5">Loading…</div>
                <div v-else-if="recommendedRooms.length === 0" class="text-xs text-slate-400 py-1.5">No department rooms found.</div>
                <ul v-else class="space-y-1">
                    <li
                        v-for="room in recommendedRooms"
                        :key="room.id"
                        class="rounded-md px-2 py-1.5 cursor-pointer text-xs border transition-colors"
                        :class="selectedRoom?.id === room.id
                            ? 'bg-blue-50 border-blue-200 text-blue-700 font-medium'
                            : `border-transparent hover:bg-slate-50 text-slate-700 ${roomTypeAccentClass(room)}`"
                        @click="selectRoom(room)"
                    >
                        <div class="font-medium truncate flex items-center gap-1.5">
                            <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0" :class="roomTypeDotClass(room)"></span>
                            {{ roomLabel(room) }}
                        </div>
                        <div class="text-[10px] text-slate-400">{{ room.room_type }} · Cap {{ room.capacity }}</div>
                    </li>
                </ul>
            </div>

            <div v-if="roomSearch && searchResults.length" class="neu-inset rounded-xl p-2.5">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Other / Shared Rooms</p>
                <ul class="space-y-1">
                    <li
                        v-for="room in searchResults"
                        :key="room.id"
                        class="rounded-md px-2 py-1.5 cursor-pointer text-xs border transition-colors"
                        :class="selectedRoom?.id === room.id
                            ? 'bg-blue-50 border-blue-200 text-blue-700 font-medium'
                            : `border-transparent hover:bg-slate-50 text-slate-700 ${roomTypeAccentClass(room)}`"
                        @click="selectRoom(room)"
                    >
                        <div class="font-medium flex items-center gap-1.5 truncate">
                            <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0" :class="roomTypeDotClass(room)"></span>
                            {{ roomLabel(room) }}
                            <Tag v-if="room.is_outside_department" value="Override" severity="warning" class="!text-[9px] !py-0" />
                        </div>
                        <div class="text-[10px] text-slate-400">{{ room.department_name || room.college_name || room.room_type }} · Cap {{ room.capacity }}</div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- MAIN AREA: Selected room's weekly timetable -->
        <div class="flex-1 min-w-0">
            <div v-if="!selectedRoom" class="h-64 flex items-center justify-center text-slate-400 text-sm neu-inset rounded-xl">
                Select a room on the left to view and schedule its weekly timetable.
            </div>
            <div v-else>
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-slate-800 flex items-center gap-2">
                            {{ roomLabel(selectedRoom) }}
                            <Tag v-if="isManualOverride" value="Manual Room Override" severity="warning" class="!text-[10px]" />
                        </h3>
                        <p class="text-xs text-slate-400">{{ selectedRoom.room_type }} · Capacity {{ selectedRoom.capacity }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-[11px] font-medium transition-colors"
                            :class="showSectionGhost
                                ? 'border-violet-300 bg-violet-50 text-violet-700'
                                : 'border-slate-200 text-slate-500 hover:border-violet-300 hover:text-violet-600'"
                            :title="`${showSectionGhost ? 'Hide' : 'Show'} ${section.section_code}'s full weekly schedule overlaid here, so you can see when it's already busy in other rooms.`"
                            @click="showSectionGhost = !showSectionGhost"
                        >
                            <i :class="showSectionGhost ? 'pi pi-eye-slash' : 'pi pi-eye'"></i>
                            {{ section.section_code }}'s schedule
                        </button>
                        <ProgressSpinner v-if="gridLoading" style="width: 22px; height: 22px" strokeWidth="6" />
                    </div>
                </div>

                <div class="overflow-x-auto border rounded-xl" :class="isDark ? 'border-white/10' : 'border-slate-300'">
                    <div
                        class="grid text-[13px]"
                        :style="{
                            gridTemplateColumns: `120px repeat(${days.length}, minmax(130px, 1fr))`,
                            gridTemplateRows: `36px repeat(${hourRows.length}, 24px)`,
                        }"
                    >
                        <!-- Header row -->
                        <div class="border-b border-r" :class="isDark ? 'border-white/10 bg-[#141D33]' : 'border-slate-300 bg-slate-100'"></div>
                        <div
                            v-for="day in days"
                            :key="`h-${day}`"
                            class="border-b border-r flex items-center justify-center font-bold"
                            :class="isDark ? 'border-white/10 bg-[#141D33] text-white' : 'border-slate-300 bg-slate-100 text-slate-700'"
                        >
                            {{ dayLabels[day] || day }}
                        </div>

                        <!-- Time labels — each row shows its own start–end
                             range (e.g. "8:00 AM – 8:30 AM") so it's always
                             clear exactly which slot a block occupies. -->
                        <template v-for="(hour, rowIndex) in hourRows" :key="`t-${hour}`">
                            <div
                                v-if="!isLunchRow(hour) || isFirstLunchRow(rowIndex)"
                                class="border-r border-b flex items-center justify-end px-1.5 leading-none whitespace-nowrap overflow-visible text-[10px]"
                                :class="[
                                    isDark ? 'border-white/10' : 'border-slate-300',
                                    isLunchRow(hour)
                                        ? (isDark ? 'text-amber-400 font-bold' : 'text-amber-700 font-bold')
                                        : (hour.endsWith(':00')
                                            ? (isDark ? 'text-slate-200 font-semibold' : 'text-slate-700 font-semibold')
                                            : (isDark ? 'text-slate-400 font-medium' : 'text-slate-500 font-medium')),
                                ]"
                                :style="{ gridColumn: 1, gridRow: isLunchRow(hour) ? `${rowIndex + 2} / span ${lunchSpan}` : rowIndex + 2 }"
                            >
                                {{ isLunchRow(hour) ? lunchRangeLabel : formatSlotRange(hour) }}
                            </div>
                        </template>

                        <!-- Drop-target cells + placed blocks -->
                        <template v-for="(day, dIndex) in days" :key="`col-${day}`">
                            <template v-for="(hour, rowIndex) in hourRows" :key="`cell-${day}-${hour}`">
                                <div
                                    v-if="!isCovered(day, rowIndex) && !isGhostCovered(day, rowIndex) && (!isLunchRow(hour) || isFirstLunchRow(rowIndex))"
                                    class="border-r border-b relative"
                                    :class="[
                                        isDark ? 'border-white/10' : 'border-slate-300',
                                        isLunchRow(hour) ? (isDark ? 'bg-amber-500/20' : 'bg-amber-100') : '',
                                    ]"
                                    :style="{
                                        gridColumn: dIndex + 2,
                                        gridRow: isLunchRow(hour)
                                            ? `${rowIndex + 2} / span ${lunchSpan}`
                                            : (blockAt(day, rowIndex)
                                                ? `${rowIndex + 2} / span ${blockAt(day, rowIndex).span}`
                                                : (ghostBlockAt(day, rowIndex) ? `${rowIndex + 2} / span ${ghostBlockAt(day, rowIndex).span}` : rowIndex + 2)),
                                    }"
                                    @dragover="isLunchRow(hour) ? null : onDragOverCell($event)"
                                    @drop="isLunchRow(hour) ? null : onDrop(day, rowIndex)"
                                >
                                    <div
                                        v-if="savingCell === `${day}-${rowIndex}`"
                                        class="absolute inset-0.5 rounded-md bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-500"
                                    >
                                        <i class="pi pi-spin pi-spinner"></i>
                                    </div>
                                    <div
                                        v-else-if="blockAt(day, rowIndex)"
                                        class="absolute inset-0.5 rounded-md px-2 py-1 overflow-hidden text-[11px] leading-tight group"
                                        :class="blockClass(blockAt(day, rowIndex))"
                                        :draggable="blockAt(day, rowIndex).can_edit"
                                        :title="blockTitle(blockAt(day, rowIndex))"
                                        @dragstart="onDragStartBlock(blockAt(day, rowIndex))"
                                        @click="blockAt(day, rowIndex).is_current_section && !blockAt(day, rowIndex).is_finalized ? openEditModal(blockAt(day, rowIndex)) : null"
                                    >
                                        <i
                                            v-if="blockHasConflict(blockAt(day, rowIndex))"
                                            class="pi pi-exclamation-triangle absolute top-1 right-1 text-[10px] text-red-600"
                                        ></i>
                                        <i
                                            v-else-if="blockAt(day, rowIndex).faculty_mismatch"
                                            class="pi pi-user-edit absolute top-1 right-1 text-[10px] text-amber-600"
                                            title="Faculty Mismatch — manually assigned outside this subject's qualified/academic-home faculty"
                                        ></i>
                                        <i
                                            v-else-if="blockAt(day, rowIndex).is_finalized"
                                            class="pi pi-lock absolute top-1 right-1 text-[9px] text-amber-700 opacity-80"
                                        ></i>
                                        <i
                                            v-else-if="blockAt(day, rowIndex).is_current_section"
                                            class="pi pi-pencil absolute top-1 right-1 text-[9px] opacity-0 group-hover:opacity-60"
                                        ></i>
                                        <i
                                            v-else-if="!blockAt(day, rowIndex).can_edit"
                                            class="pi pi-lock absolute top-1 right-1 text-[9px] opacity-60"
                                        ></i>
                                        <i
                                            v-else
                                            class="pi pi-arrows-alt absolute top-1 right-1 text-[9px] opacity-0 group-hover:opacity-60"
                                        ></i>
                                        <div class="font-semibold truncate">{{ blockAt(day, rowIndex).subject_code }}</div>
                                        <div class="truncate text-[10px] opacity-80">
                                            <span v-if="blockAt(day, rowIndex).edp_code">EDP {{ blockAt(day, rowIndex).edp_code }} · </span>{{ blockAt(day, rowIndex).section_code }}
                                        </div>
                                        <div v-if="blockAt(day, rowIndex).faculty_name" class="truncate text-[10px] opacity-75">{{ blockAt(day, rowIndex).faculty_name }}</div>
                                        <div class="truncate text-[10px] font-medium opacity-90">{{ formatBlockTimeRange(blockAt(day, rowIndex)) }}</div>
                                        <div v-if="blockHasConflict(blockAt(day, rowIndex))" class="text-[9px] font-semibold text-red-700 truncate">⚠ Conflict — needs rescheduling</div>
                                        <div v-else-if="blockAt(day, rowIndex).faculty_mismatch" class="text-[9px] font-semibold text-amber-700 truncate">⚠ Faculty Mismatch</div>
                                        <div v-else-if="blockAt(day, rowIndex).is_finalized" class="text-[9px] italic opacity-75 truncate">Finalized — locked</div>
                                        <div v-else-if="!blockAt(day, rowIndex).can_edit" class="text-[9px] italic opacity-75 truncate">Outside your scheduling scope</div>
                                    </div>
                                    <!-- Ghost overlay ("x-ray") — this section is already busy at
                                         this Day/Time in a DIFFERENT room. Decorative only:
                                         pointer-events-none so dragover/drop on the cell underneath
                                         still work exactly as if this weren't here, letting the
                                         Registrar still drop a subject into this slot (and get the
                                         normal Faculty/Section conflict error) rather than being
                                         silently blocked by the overlay. -->
                                    <div
                                        v-else-if="ghostBlockAt(day, rowIndex)"
                                        class="absolute inset-0.5 rounded-md px-2 py-1 overflow-hidden text-[11px] leading-tight pointer-events-none border-2 border-dashed border-violet-300 bg-[repeating-linear-gradient(45deg,rgba(139,92,246,0.08),rgba(139,92,246,0.08)_6px,rgba(139,92,246,0.14)_6px,rgba(139,92,246,0.14)_12px)] text-violet-700"
                                        :title="`${section.section_code} already has ${ghostBlockAt(day, rowIndex).subject_code} scheduled here (${ghostBlockAt(day, rowIndex).room_name ?? 'another room'}).`"
                                    >
                                        <i class="pi pi-eye absolute top-1 right-1 text-[9px] opacity-70"></i>
                                        <div class="font-semibold truncate">{{ ghostBlockAt(day, rowIndex).subject_code }}</div>
                                        <div class="truncate text-[10px] opacity-80">{{ ghostBlockAt(day, rowIndex).room_name ?? 'Another room' }}</div>
                                        <div v-if="ghostBlockAt(day, rowIndex).faculty_name" class="truncate text-[10px] opacity-70">{{ ghostBlockAt(day, rowIndex).faculty_name }}</div>
                                    </div>
                                </div>
                            </template>
                        </template>

                        <!-- Single "LUNCH" banner spanning the whole merged
                             lunch row and every day column. -->
                        <template v-for="(hour, rowIndex) in hourRows" :key="`lunch-${hour}`">
                            <div
                                v-if="isLunchRow(hour) && isFirstLunchRow(rowIndex)"
                                class="flex items-center justify-center text-xs font-bold text-amber-800 tracking-wider select-none pointer-events-none"
                                :style="{ gridColumn: `2 / span ${days.length}`, gridRow: `${rowIndex + 2} / span ${lunchSpan}` }"
                            >
                                LUNCH
                            </div>
                        </template>
                    </div>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">
                    Drag a subject from "Unscheduled Subjects" onto a slot to place it, or drag an existing block to move it. Click a block to edit its Faculty, Hours/Week, Meetings/Week, or Days.
                    Schedules belonging to any section within your authorized scheduling scope can be moved from here, even if it isn't the currently selected section — schedules outside your scope stay locked, and a finalized section's schedule (amber, padlock) stays locked for everyone until an Admin/Registrar unlocks it.
                    <span v-if="showSectionGhost" class="block mt-1"><i class="pi pi-eye mr-1 text-violet-500"></i>Striped violet slots show where {{ section.section_code }} is already scheduled in another room — dropping a subject there will still conflict for the section even though this room is free.</span>
                </p>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: Draggable unscheduled subjects for this section -->
        <div class="w-full lg:w-40 shrink-0 neu-inset rounded-xl p-2.5">
            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Unscheduled Subjects</p>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[9px] text-slate-400 mb-1.5">
                <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Major</span>
                <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-400"></span>Minor / GenEd</span>
            </div>
            <div v-if="unscheduledSubjects.length === 0" class="text-xs text-slate-400 py-1.5">
                Every subject in {{ section.section_code }} is placed.
            </div>
            <ul v-else class="space-y-1">
                <li
                    v-for="row in unscheduledSubjects"
                    :key="row.id"
                    :draggable="!section.is_finalized && !isRowOutOfCategory(row)"
                    class="rounded-md px-2 py-1.5 text-xs border border-slate-200"
                    :class="[
                        isRowOutOfCategory(row) ? 'opacity-50 cursor-not-allowed grayscale' : 'cursor-grab hover:border-blue-300 hover:bg-blue-50/40',
                        subjectCategoryAccentClass(row),
                    ]"
                    :title="isRowOutOfCategory(row) ? `${row.subject?.subject_code} is a Major subject — outside your Assistant Dean scheduling scope (GenEd/Minor only). The Dean/OIC schedules this.` : (section.is_finalized ? 'This section is finalized and can\'t be edited.' : (!selectedRoom ? 'Select a room first' : 'Drag onto the grid'))"
                    @dragstart="(section.is_finalized || isRowOutOfCategory(row)) ? null : onDragStartNew(row)"
                >
                    <div class="font-medium text-slate-700 truncate flex items-center gap-1.5">
                        <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0" :class="subjectCategoryDotClass(row)"></span>
                        {{ row.subject?.subject_code }}
                    </div>
                    <div class="text-[10px] text-slate-400 truncate">{{ row.subject?.subject_title }}</div>
                </li>
            </ul>
        </div>

        <!-- Assign / Edit modal — Hours/Week, Meetings/Week, Days, Faculty -->
        <Dialog
            v-model:visible="assignModalVisible"
            modal
            :header="assignForm.editing ? 'Edit Schedule' : 'Schedule Subject'"
            :style="{ width: '30rem' }"
        >
            <div v-if="assignForm.row" class="space-y-4">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Subject</p>
                    <p class="text-sm font-medium text-slate-700">
                        {{ assignForm.row.subject?.subject_code }} — {{ assignForm.row.subject?.subject_title }}
                    </p>
                    <p class="text-xs text-slate-400">
                        {{ roomLabel(selectedRoom) }} · starting {{ formatHourLabel(hourRows[assignForm.rowIndex]) }}
                        <span v-if="!assignForm.editing"> on {{ dayLabels[assignForm.day] }}</span>
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-500 mb-1 block">Hours / Week</label>
                        <InputNumber v-model="assignForm.hoursPerWeek" :min="minHoursPerWeek" :max="assignForm.requiredHours" :step="0.5" showButtons class="w-full" />
                        <p class="text-[11px] text-slate-400 mt-1">
                            Curriculum load for {{ assignForm.row?.subject?.subject_code ?? 'this subject' }}: up to
                            <button
                                v-if="!assignHoursValid"
                                type="button"
                                class="font-semibold text-blue-600 underline underline-offset-2"
                                @click="assignForm.hoursPerWeek = assignForm.requiredHours"
                            >{{ assignForm.requiredHours }} hrs/week</button>
                            <span v-else class="font-medium text-slate-500">{{ assignForm.requiredHours }} hrs/week</span>
                        </p>
                        <!-- SIBLING SECTION PATTERN prefill note — see
                             openAssignModal()'s sibling lookup. Purely
                             informational: the Hours Mismatch confirm
                             gate below still fires the same way if this
                             copied value diverges from the curriculum. -->
                        <p v-if="assignForm.siblingPatternSource" class="text-[11px] text-teal-600 mt-1">
                            <i class="pi pi-copy"></i>
                            Prefilled to match {{ assignForm.siblingPatternSource }}'s existing schedule for this subject.
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500 mb-1 block">Meetings / Week</label>
                        <Select
                            v-model="assignForm.meetingsPerWeek"
                            :options="meetingsOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                        />
                    </div>
                </div>

                <!--
                    Section Conflict warning — this Section already has
                    another Subject scheduled on a day/time this drop
                    would overlap, regardless of Room (see
                    sectionScheduleConflict computed above). Shown as
                    soon as the modal opens/day-time is configured, not
                    only after Save round-trips to the server.
                -->
                <div
                    v-if="sectionScheduleConflict"
                    class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                >
                    <i class="pi pi-exclamation-triangle mt-0.5"></i>
                    <div>
                        <span class="font-semibold">Section Conflict:</span>
                        {{ props.section.section_code }} already has {{ sectionScheduleConflict.subject_code }}
                        <span v-if="sectionScheduleConflict.room_code">in {{ sectionScheduleConflict.room_code }}</span>
                        on {{ dayLabels[sectionScheduleConflict.day] ?? sectionScheduleConflict.day }}
                        {{ formatHourLabel(sectionScheduleConflict.start_time) }}–{{ formatHourLabel(sectionScheduleConflict.end_time) }}.
                        Pick a different day/time before saving.
                    </div>
                </div>

                <div>
                    <label class="text-xs font-medium text-slate-500 mb-1 block">
                        Days ({{ assignForm.selectedDays.length }}/{{ assignForm.meetingsPerWeek }} selected)
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="day in days"
                            :key="day"
                            type="button"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors"
                            :class="assignForm.selectedDays.includes(day)
                                ? 'bg-blue-500 border-blue-500 text-white'
                                : 'bg-white border-slate-200 text-slate-600 hover:border-blue-300'"
                            :disabled="day === assignForm.day"
                            :title="day === assignForm.day ? 'This is the slot you dropped on' : undefined"
                            @click="toggleAssignDay(day)"
                        >
                            {{ dayLabels[day] || day }}
                        </button>
                    </div>
                    <p v-if="!assignDaysValid" class="text-xs text-amber-600 mt-1">
                        Select exactly {{ assignForm.meetingsPerWeek }} day(s) to match Meetings/Week.
                    </p>
                    <p v-if="!assignRoomTypeValid" class="text-xs text-amber-600 mt-1">
                        {{ selectedRoom?.room_name ?? 'This room' }} is a {{ selectedRoom?.room_type }} room, but {{ assignForm.row?.subject?.subject_code ?? 'this subject' }} is a {{ Number(assignForm.row?.subject?.laboratory_hours ?? 0) > 0 ? 'Laboratory' : 'Lecture' }} subject — you'll be asked to confirm before saving.
                    </p>
                    <p v-if="!assignRoomCollegeValid" class="text-xs text-amber-600 mt-1">
                        {{ selectedRoom?.room_name ?? 'This room' }} belongs to {{ selectedRoom?.college_name ?? 'another college' }}, not this section's own college — you'll be asked to confirm before saving.
                    </p>
                    <p v-if="!assignHoursValid" class="text-xs text-amber-600 mt-1">
                        Hours/Week doesn't match {{ assignForm.row?.subject?.subject_code ?? 'this subject' }}'s required {{ assignForm.requiredHours }} hrs/week — you'll be asked to confirm before saving.
                    </p>
                </div>

                <div>
                    <label class="text-xs font-medium text-slate-500 mb-1 block">
                        Faculty
                        <i v-if="facultyRecommendationsLoading" class="pi pi-spin pi-spinner text-[10px] text-slate-400 ml-1"></i>
                    </label>
                    <Select
                        v-model="assignForm.facultyId"
                        :options="facultyOptions"
                        optionLabel="full_name"
                        optionValue="id"
                        optionGroupLabel="label"
                        optionGroupChildren="items"
                        optionDisabled="disabled"
                        filter
                        showClear
                        placeholder="Unassigned"
                        class="w-full"
                        :class="{ '!border-amber-300': assignFacultyMismatch }"
                    >
                        <template #optiongroup="{ option }">
                            <span class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-400">{{ option.label }}</span>
                        </template>
                        <template #option="{ option }">
                            <div class="flex flex-col gap-0.5 py-0.5 w-full" :class="{ 'italic text-slate-400': option.disabled }">
                                <div class="flex items-center gap-2">
                                    <span>{{ option.full_name }}</span>
                                    <Tag
                                        v-if="option.badge"
                                        :value="option.badge"
                                        :severity="facultyBadgeSeverity(option.badge)"
                                        class="!text-[10px]"
                                    />
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span v-if="option.college" class="text-[10px] text-slate-400">{{ option.college }}</span>
                                    <span v-if="option.college && option.max_teaching_units != null" class="text-[10px] text-slate-300">·</span>
                                    <span
                                        v-if="option.max_teaching_units != null"
                                        class="text-[10px]"
                                        :class="remainingUnitsClass(option)"
                                    >
                                        {{ remainingUnitsLabel(option) }}
                                    </span>
                                    <!-- No legitimate way to pick this Faculty member
                                         as-is (0 units left) without first raising their
                                         ceiling — offer the same request/approval flow
                                         the Faculty Master page uses, inline, so this
                                         doesn't dead-end the Registrar mid-scheduling.
                                         mousedown.stop (not click.stop) so it fires
                                         before PrimeVue's own option-select handler. -->
                                    <button
                                        v-if="hasInsufficientUnits(option)"
                                        type="button"
                                        class="text-[10px] font-medium text-blue-500 hover:text-blue-600 underline"
                                        @mousedown.stop.prevent="openLoadRequestDialog(option)"
                                    >
                                        {{ canChangeMaxLoad ? 'Add Units' : 'Request more units' }}
                                    </button>
                                </div>
                            </div>
                        </template>
                    </Select>
                    <p v-if="assignFacultyMismatch" class="flex items-start gap-1.5 text-xs text-amber-600 mt-1">
                        <i class="pi pi-exclamation-triangle mt-0.5"></i>
                        <span>
                            <span class="font-semibold">Faculty Mismatch:</span>
                            not on file as qualified or from the academic home for
                            {{ assignForm.row?.subject?.subject_code ?? 'this subject' }}. You can still save this as
                            a manual override — the assignment will carry a Faculty Mismatch flag on the grid.
                        </span>
                    </p>
                </div>

                <p class="text-[11px] text-slate-400">
                    Each meeting runs {{ perMeetingPreview }} hr(s), starting at {{ formatHourLabel(hourRows[assignForm.rowIndex]) }} on every selected day.
                    <span v-if="assignForm.editing">Drag the block on the grid to change its start time or room.</span>
                </p>
            </div>

            <template #footer>
                <Button
                    v-if="assignForm.editing"
                    label="Remove from Room"
                    icon="pi pi-trash"
                    text
                    severity="danger"
                    :loading="assignRemoving"
                    class="mr-auto"
                    @click="removeAssignment"
                />
                <Button label="Cancel" text severity="secondary" @click="assignModalVisible = false" />
                <Button
                    :label="assignForm.editing ? 'Save Changes' : 'Save Schedule'"
                    icon="pi pi-check"
                    :loading="assignSaving"
                    :disabled="!assignDaysValid || !assignForm.hoursPerWeek || !!sectionScheduleConflict"
                    @click="confirmAssign"
                />
            </template>
        </Dialog>
    </div>
</template>

<style>
/* Swal2's default z-index (1060) can sit underneath PrimeVue's
   Dialog portal, which assigns its own z-index dynamically and is
   often higher — making confirm dialogs like "Weekly Hours Mismatch"
   render behind the Schedule Subject modal and unclickable. This is
   global (unscoped) because Swal2 mounts outside this component's
   DOM tree, so a scoped style wouldn't reach it. */
.roomgrid-swal-on-top {
    z-index: 2147483647 !important;
}
</style>