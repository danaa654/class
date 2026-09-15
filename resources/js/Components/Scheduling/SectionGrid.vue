<script setup>
/**
 * SECTION GRID — a weekly Day x Time view scoped to ONE section (as
 * opposed to Room Grid's Room x Time view, which spans every section
 * sharing a Room). Built specifically so an 'online' delivery_mode
 * row (see SectionSubject::requiresRoom()) — which has no Room axis
 * to sit on in Room Grid — has somewhere to be visually placed and
 * assigned, alongside every other subject this section has, F2F or
 * Online.
 *
 * F2F/room-based rows are drawn here too, so this grid reads as the
 * section's WHOLE weekly schedule — not just its Online half — but
 * only as a read-only reference layer. Placing/moving/removing a
 * room-based row is still done exclusively in Room Grid (that's
 * where Room conflicts are checked against); this grid only ever
 * writes Days/Start/End for 'online' rows. See scheduledOnlineRows /
 * scheduledRoomRows below.
 *
 * INTERACTION MODEL — click-to-assign, not drag-and-drop. Room Grid's
 * drag-and-drop relies on native HTML5 drag events wired specifically
 * around a Room+Time cell target. Building an equally solid
 * drag-and-drop interaction here — including the mid-drag validation
 * Room Grid does — isn't something to get right on a first pass
 * without being able to test it live. Click-to-assign (select an
 * unscheduled subject, then click an open Day/Time cell) reaches the
 * same outcome — set a row's Days/Start/End from this grid — without
 * that risk. Click-to-assign is intentionally kept as the ONLY
 * interaction here, even after Room Grid's native drag-and-drop was
 * briefly ported over — it reproducibly wedged the browser's
 * renderer into a stuck native-drag state (unrecoverable even via
 * Escape, only a full page reload cleared it) on at least one
 * environment. Until that's understood well enough to fix at the
 * root, this grid stays drag-free.
 *
 * WHAT THIS GRID DOES NOT CATCH — it only visualizes and edits THIS
 * section's own Days/Time. Faculty double-booking across this
 * faculty member's OTHER sections, and Room conflicts, are not
 * evaluated here — every write still goes through the same
 * scheduling.section-subjects.schedule endpoint and
 * ScheduleConflictService::validate() the Subjects tab and Room Grid
 * both already go through, so a real conflict is still caught and
 * blocked server-side; this grid just doesn't warn about it before
 * you click.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';

const props = defineProps({
    section: { type: Object, required: true },
    rows: { type: Array, required: true },
    schedulingWindow: { type: Object, required: true },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['row-updated']);

const toast = useToast();

// Same key/label shape as RoomGrid's dayLabels, so both tabs read the
// exact same day headers.
const DAY_LABELS = { Mon: 'Mon', Tue: 'Tue', Wed: 'Wed', Thu: 'Thu', Fri: 'Fri', Sat: 'Sat', Sun: 'Sun' };

const days = computed(() =>
    props.schedulingWindow.available_days?.length ? props.schedulingWindow.available_days : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
);

const intervalMinutes = computed(() => props.schedulingWindow.interval_minutes || 30);

const toMinutes = (hhmm) => {
    const [h, m] = (hhmm || '00:00').split(':').map(Number);
    return h * 60 + m;
};
const toHHMM = (minutes) => {
    const h = Math.floor(minutes / 60).toString().padStart(2, '0');
    const m = (minutes % 60).toString().padStart(2, '0');
    return `${h}:${m}`;
};
const to12Hour = (hhmm) => {
    const [h, m] = (hhmm || '00:00').split(':').map(Number);
    const period = h >= 12 ? 'PM' : 'AM';
    const hour12 = h % 12 === 0 ? 12 : h % 12;
    return `${hour12}:${m.toString().padStart(2, '0')} ${period}`;
};

const windowStartMinutes = computed(() => toMinutes(props.schedulingWindow.start_time || '07:00'));
const windowEndMinutes = computed(() => toMinutes(props.schedulingWindow.end_time || '19:00'));

const slots = computed(() => {
    const list = [];
    for (let m = windowStartMinutes.value; m < windowEndMinutes.value; m += intervalMinutes.value) {
        list.push(m);
    }
    return list;
});

// "8:00 AM – 8:30 AM" row label, same phrasing/format as Room Grid's
// formatSlotRange(), so the two tabs' time columns read identically.
const formatSlotRange = (minutes) => {
    const start = toHHMM(minutes);
    const end = toHHMM(minutes + intervalMinutes.value);
    return `${to12Hour(start)} – ${to12Hour(end)}`;
};

// Bold the on-the-hour rows (":00"), same visual rhythm Room Grid uses
// to make the header scannable at a glance.
const isOnHourSlot = (minutes) => minutes % 60 === 0;

// Every ONLINE row that has Days + Start + End set — these are the
// editable blocks drawn on the grid (click/drag to move, ✕ to
// remove). A split subject's Face-to-Face half is never included
// here, since Room Grid is where every F2F/combined row (see
// SectionSubject::requiresRoom()) gets placed and edited.
const scheduledOnlineRows = computed(() => onlineRows.value.filter((r) => r.days && r.start_time && r.end_time));

// Every ROOM-BASED row that already has Days + Start + End set (i.e.
// placed in Room Grid) — drawn here too, read-only, so this grid
// shows the section's whole week rather than just its Online half.
const scheduledRoomRows = computed(() => roomRows.value.filter((r) => r.days && r.start_time && r.end_time));

// Rows still missing Days/Start/End — these appear in the sidebar
// list to pick from before clicking/dragging onto a cell. Room-based
// unscheduled rows aren't listed here since they can't be placed
// from this grid — Room Grid is still where they get assigned.
const unscheduledRows = computed(() => onlineRows.value.filter((r) => !r.days || !r.start_time || !r.end_time));

// EDIT SCOPE — this grid can only WRITE Days/Start/End for rows that
// have no Room axis to sit on in Room Grid (delivery_mode ===
// 'online', per SectionSubject::requiresRoom()). Every other row
// (a never-split subject, or the Face-to-Face half of a split one)
// still needs a Room, so it's still placed/edited in Room Grid —
// this grid only displays it, read-only, via scheduledRoomRows.
const onlineRows = computed(() => props.rows.filter((r) => r.delivery_mode === 'online'));

// Room-based rows (everything requiring a Room per
// SectionSubject::requiresRoom() — i.e. every non-'online' row) —
// shown read-only on this grid, colored/labeled by their room.
const roomRows = computed(() => props.rows.filter((r) => r.delivery_mode !== 'online'));

const rowDayTokens = (row) => (Array.isArray(row.days) ? row.days : String(row.days || '').split(',').filter(Boolean));

const blockFor = (row) => {
    const startRow = Math.round((toMinutes(row.start_time) - windowStartMinutes.value) / intervalMinutes.value) + 2; // +2: header row
    const span = Math.max(1, Math.round((toMinutes(row.end_time) - toMinutes(row.start_time)) / intervalMinutes.value));
    return { startRow, span };
};

const selectedRow = ref(null);

const selectForAssignment = (row) => {
    selectedRow.value = selectedRow.value?.id === row.id ? null : row;
};

// The row's own required hours — split_hours for a split component,
// otherwise the subject's full lecture+laboratory total. Mirrors
// weeklyContactHours() in Show.vue exactly, kept local here since
// this is a standalone component.
const requiredHours = (row) => {
    if (row.split_hours != null) return Number(row.split_hours);
    return Number(row.subject?.lecture_hours ?? 0) + Number(row.subject?.laboratory_hours ?? 0);
};

const saving = ref(false); // kept for potential future use; no longer drives a blocking UI
const savingLabel = ref('');

// Shared write path — both click/drag-to-cell (assignCell below) and
// "Suggest a Time" (applySuggestion) end up here, so there's exactly
// ONE place that talks to the schedule endpoint, rolls back on
// failure, and reports errors. Takes the full resulting Day pattern
// (not a single day) since a suggestion can be multi-day (e.g. MW) in
// one shot, unlike a single cell click/drop.
const writeSchedule = async (row, newDays, startTime, endTime, successMessage, extraPayload = {}) => {
    const previous = { days: row.days, start_time: row.start_time, end_time: row.end_time };
    Object.assign(row, { days: newDays, start_time: startTime, end_time: endTime });
    selectedRow.value = null;

    savingLabel.value = `Placing ${row.subject?.subject_code}…`;
    try {
        const { data } = await window.axios.patch(
            route('scheduling.section-subjects.schedule', [props.section.id, row.id]),
            { days: newDays, start_time: startTime, end_time: endTime, hours_confirmed: true, ...extraPayload },
        );

        emit('row-updated', data.sectionSubject ?? data.section_subject ?? data, data.schedule_version);
        toast.add({ severity: 'success', summary: 'Scheduled', detail: successMessage, life: 2500 });
        return true;
    } catch (error) {
        // Roll back the optimistic placement — the server didn't
        // accept it (a real conflict, a validation error, etc.), so
        // the block must not stay on screen looking scheduled.
        Object.assign(row, previous);

        const responseData = error.response?.data;

        // WORKLOAD WARNING — mirrors RoomGrid.vue's writeSchedule()
        // fix for the exact same pre-existing bug: this is its own
        // 409 shape ({workload_warning, can_override, message} — no
        // `errors` key), and the backend has supported an
        // Administrator/Registrar/Dean/OIC/Assistant Dean override
        // via workload_confirmed=true since this endpoint's very
        // first version. Without this branch, that "Proceed anyway?"
        // in the message text was a lie — there was no action that
        // could ever say yes to it, so every legitimate override
        // (e.g. merging an Online session onto an already-slightly-
        // over-capacity Faculty member) dead-ended on a plain error
        // toast no matter who clicked it.
        if (error.response?.status === 409 && responseData?.workload_warning) {
            if (responseData.can_override) {
                const result = await Swal.fire({
                    icon: 'warning',
                    title: 'Conflict',
                    text: responseData.message,
                    showCancelButton: true,
                    confirmButtonText: 'Proceed Anyway',
                    cancelButtonText: 'Cancel',
                });

                if (result.isConfirmed) {
                    return writeSchedule(row, newDays, startTime, endTime, successMessage, {
                        ...extraPayload,
                        workload_confirmed: true,
                    });
                }

                return false;
            }

            toast.add({ severity: 'error', summary: 'Could not schedule', detail: responseData.message, life: 7000 });
            return false;
        }

        // Mirrors RoomGrid.vue's exact fallback chain: a 422
        // validation failure carries `errors` (Room/Hours/Capacity
        // mismatch, etc.), but a 409 Faculty/Room/Section conflict
        // warning carries only `message` — this grid was only ever
        // checking `errors`, so every other 409 fell through to the
        // generic "Something went wrong" text instead of showing the
        // actual conflict (e.g. which faculty/room/section it
        // collided with).
        const message = responseData?.errors
            ? Object.values(responseData.errors).flat().join(' ')
            : (responseData?.message ?? 'Something went wrong placing this subject. Please try again.');
        // Conflict messages (Faculty/Room/Section double-booking) can
        // run long — give this one more time on screen than a
        // typical error toast so it's actually readable before it
        // disappears.
        toast.add({ severity: 'error', summary: 'Could not schedule', detail: message, life: 7000 });
        return false;
    }
};

const assignCell = async (day, slotStart) => {
    if (!selectedRow.value) {
        toast.add({ severity: 'info', summary: 'Pick a subject first', detail: 'Click an unscheduled subject on the left, then click an open slot here.', life: 3000 });
        return;
    }

    const row = selectedRow.value;
    const durationMinutes = requiredHours(row) * 60;
    const endMinutes = slotStart + durationMinutes;

    if (endMinutes > windowEndMinutes.value) {
        toast.add({ severity: 'warn', summary: "Won't fit", detail: `${row.subject?.subject_code} needs ${requiredHours(row)}h — that runs past the scheduling window from this slot.`, life: 3500 });
        return;
    }

    // Clicking a second day at the SAME start time this row already
    // has adds that day to the meeting pattern (e.g. building MW)
    // instead of overwriting it — clicking a different start time
    // instead replaces the whole pattern with this single new day.
    const existingDays = rowDayTokens(row);
    const alreadyHasThisStart = row.start_time === toHHMM(slotStart);
    const newDays = alreadyHasThisStart && existingDays.length
        ? Array.from(new Set([...existingDays, day]))
        : [day];

    await writeSchedule(
        row,
        newDays,
        toHHMM(slotStart),
        toHHMM(endMinutes),
        `${row.subject?.subject_code} placed on ${DAY_LABELS[day]} at ${to12Hour(toHHMM(slotStart))}.`,
    );
};

// SUGGEST A TIME — for an unscheduled online row, ask
// RecommendationService::recommendTimes() (the exact same conflict-
// checked, scored engine Auto Generate/Room Grid's "Recommend Time"
// already use) for the best conflict-free Day/Time pattern, checking
// not just this grid's own Section availability but the assigned
// Faculty's OTHER sections too — the one thing this grid could never
// see on its own (see this file's header docblock). A candidate is
// never applied silently: the Registrar sees the ranked picks and
// clicks one.
const suggestionsFor = ref(null); // row.id currently showing suggestions
const suggestionResults = ref([]);
const suggestionMessage = ref('');
const suggestionsLoading = ref(false);
// SHARED ONLINE SESSION — other Regular sections' already-scheduled
// Online class for this same Subject (same Year Level, same Faculty
// once one's picked) that this row could ride along on instead of
// getting its own separate slot. See RecommendationService::
// findOnlineMergeCandidates(). Kept in its own list, never mixed into
// suggestionResults, since "merge onto an existing class" is a
// different action than "book a new time".
const mergeSuggestions = ref([]);

const fetchSuggestions = async (row) => {
    if (suggestionsFor.value === row.id) {
        // Toggle off if already open for this row.
        suggestionsFor.value = null;
        return;
    }

    selectedRow.value = null;
    suggestionsFor.value = row.id;
    suggestionResults.value = [];
    mergeSuggestions.value = [];
    suggestionMessage.value = '';
    suggestionsLoading.value = true;

    try {
        const { data } = await window.axios.get(
            route('scheduling.section-subjects.time-recommendations', [props.section.id, row.id]),
        );
        suggestionResults.value = (data.recommendations ?? []).slice(0, 3);
        mergeSuggestions.value = data.merge_suggestions ?? [];
        suggestionMessage.value = data.message ?? (suggestionResults.value.length ? '' : 'No available time slot found without conflicts.');
    } catch (error) {
        suggestionMessage.value = error.response?.data?.message ?? 'Could not load time suggestions. Please try again.';
    } finally {
        suggestionsLoading.value = false;
    }
};

const applySuggestion = async (row, candidate) => {
    const label = `${candidate.days.join('/')} ${to12Hour(candidate.start_time)}–${to12Hour(candidate.end_time)}`;
    const ok = await writeSchedule(
        row,
        candidate.days,
        candidate.start_time,
        candidate.end_time,
        `${row.subject?.subject_code} placed on ${label}.`,
    );
    if (ok) {
        suggestionsFor.value = null;
    }
};

// Apply a Shared Online Session — re-uses the exact same write path
// as applySuggestion() above, just with the target class's own
// Faculty/Days/Time plus merge_target_section_subject_id so the
// backend re-validates and re-points this row onto that existing
// class (SectionSubjectController::performScheduleAssignmentUpdate()
// -> IrregularSectionMergeService::evaluateReversePlacement()) rather
// than booking a brand-new slot.
const applyMergeSuggestion = async (row, candidate) => {
    const label = `${candidate.days.join('/')} ${to12Hour(candidate.start_time)}–${to12Hour(candidate.end_time)}`;
    const ok = await writeSchedule(
        row,
        candidate.days,
        candidate.start_time,
        candidate.end_time,
        `${row.subject?.subject_code} merged with ${candidate.section_code}'s class on ${label}.`,
        { faculty_id: candidate.faculty_id, merge_target_section_subject_id: candidate.section_subject_id },
    );
    if (ok) {
        suggestionsFor.value = null;
    }
};

const removeFromGrid = async (row) => {
    // Same optimistic-then-reconcile approach as assignCell() above —
    // clear the block from the grid immediately, restore it if the
    // server rejects the removal.
    const previous = { days: row.days, start_time: row.start_time, end_time: row.end_time };
    Object.assign(row, { days: [], start_time: null, end_time: null });

    savingLabel.value = `Removing ${row.subject?.subject_code}…`;
    try {
        const { data } = await window.axios.patch(
            route('scheduling.section-subjects.schedule', [props.section.id, row.id]),
            { days: [], start_time: null, end_time: null },
        );
        emit('row-updated', data.sectionSubject ?? data.section_subject ?? data, data.schedule_version);
    } catch (error) {
        Object.assign(row, previous);
        const responseData = error.response?.data;
        const message = responseData?.errors
            ? Object.values(responseData.errors).flat().join(' ')
            : (responseData?.message ?? 'Something went wrong. Please try again.');
        toast.add({ severity: 'error', summary: 'Could not remove', detail: message, life: 4000 });
    }
};
</script>

<template>
    <div
        class="flex flex-col lg:flex-row gap-4"
    >
        <!-- LEFT SIDEBAR: Unscheduled subjects — same neu-inset card
             shape, legend-dot convention, and list-item styling as
             Room Grid's sidebars, so the two tabs read as one system. -->
        <div class="w-full lg:w-56 shrink-0 neu-inset rounded-xl p-2.5">
            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">
                Unscheduled ({{ unscheduledRows.length }})
            </p>
            <div class="flex items-center gap-3 text-[9px] text-slate-400 mb-1.5">
                <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-sky-400"></span>Online</span>
                <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-violet-400"></span>Room (view only)</span>
            </div>
            <div v-if="!unscheduledRows.length" class="text-xs text-slate-400 py-1.5">
                Every subject in this section has Days &amp; Time set.
            </div>
            <ul v-else class="space-y-1">
                <li
                    v-for="row in unscheduledRows"
                    :key="row.id"
                    class="rounded-md text-xs border transition-colors overflow-hidden"
                    :class="selectedRow?.id === row.id
                        ? (isDark ? 'bg-blue-500/20 border-blue-400/40 text-blue-300 font-medium' : 'bg-blue-50 border-blue-200 text-blue-700 font-medium')
                        : (isDark ? 'border-l-4 border-l-sky-400 bg-sky-500/10 border-transparent text-slate-200' : 'border-l-4 border-l-sky-400 bg-sky-50/40 border-transparent text-slate-700')"
                >
                    <div
                        class="px-2 py-1.5 hover:bg-black/5"
                        @click="selectForAssignment(row)"
                    >
                        <div class="font-medium truncate flex items-center gap-1.5">
                            <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0 bg-sky-400"></span>
                            {{ row.subject?.subject_code }}
                        </div>
                        <div class="text-[10px] truncate" :class="isDark ? 'text-slate-400' : 'text-slate-400'">{{ row.subject?.subject_title }}</div>
                        <div class="text-[10px]" :class="isDark ? 'text-slate-400' : 'text-slate-400'">🌐 {{ requiredHours(row) }}h/week</div>
                    </div>

                    <!-- SUGGEST A TIME — faculty-conflict-aware, unlike a
                         plain click/drag onto this grid (see the file
                         header docblock: this grid alone can't see a
                         faculty's OTHER sections). -->
                    <div class="px-2 pb-1.5">
                        <Button
                            :label="suggestionsFor === row.id ? 'Hide suggestions' : '✨ Suggest a Time'"
                            text
                            size="small"
                            class="!text-[10px] !p-0 !h-auto"
                            :loading="suggestionsLoading && suggestionsFor === row.id"
                            @click.stop="fetchSuggestions(row)"
                        />
                    </div>

                    <div
                        v-if="suggestionsFor === row.id && !suggestionsLoading"
                        class="px-2 pb-2 space-y-1"
                    >
                        <p v-if="suggestionMessage" class="text-[10px] italic" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            {{ suggestionMessage }}
                        </p>
                        <button
                            v-for="(candidate, idx) in suggestionResults"
                            :key="idx"
                            type="button"
                            class="w-full text-left rounded-md px-2 py-1 text-[10px] border transition-colors"
                            :class="isDark ? 'border-white/10 bg-white/5 hover:bg-white/10 text-slate-200' : 'border-slate-200 bg-white hover:bg-blue-50 text-slate-700'"
                            @click.stop="applySuggestion(row, candidate)"
                        >
                            <div class="flex items-center justify-between font-medium">
                                <span>{{ candidate.days.join('/') }} · {{ to12Hour(candidate.start_time) }}–{{ to12Hour(candidate.end_time) }}</span>
                                <span :class="isDark ? 'text-emerald-400' : 'text-emerald-600'">{{ candidate.score }}/{{ candidate.score_max }}</span>
                            </div>
                            <div class="opacity-70">No Faculty/Section conflict · click to use</div>
                        </button>

                        <!-- SHARED ONLINE SESSION — other Regular
                             sections already holding this exact
                             Subject Online, this row could ride
                             along on instead of booking its own
                             slot (see findOnlineMergeCandidates()). -->
                        <template v-if="mergeSuggestions.length">
                            <p class="text-[10px] font-semibold uppercase tracking-wide pt-1" :class="isDark ? 'text-emerald-400' : 'text-emerald-600'">
                                Merge with an existing class
                            </p>
                            <button
                                v-for="(candidate, idx) in mergeSuggestions"
                                :key="`merge-${idx}`"
                                type="button"
                                class="w-full text-left rounded-md px-2 py-1 text-[10px] border transition-colors"
                                :class="isDark ? 'border-emerald-400/30 bg-emerald-500/10 hover:bg-emerald-500/20 text-slate-200' : 'border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-slate-700'"
                                @click.stop="applyMergeSuggestion(row, candidate)"
                            >
                                <div class="font-medium">{{ candidate.section_code }} · {{ candidate.days.join('/') }} · {{ to12Hour(candidate.start_time) }}–{{ to12Hour(candidate.end_time) }}</div>
                                <div class="opacity-70">{{ candidate.faculty_name }} · same class, no new slot</div>
                            </button>
                        </template>
                    </div>
                </li>
            </ul>
            <p v-if="selectedRow" class="text-[11px] mt-2.5" :class="isDark ? 'text-blue-400' : 'text-blue-500'">
                Click an open slot to place <strong>{{ selectedRow.subject?.subject_code }}</strong>. Click another day at the same start time to add it to the same meeting pattern (e.g. MW).
            </p>
        </div>

        <!-- MAIN AREA: this section's weekly Online timetable — same
             bordered-table shell (border-slate-300, rounded-xl, 120px
             time column, bg-slate-100 headers) as Room Grid's timetable
             so both grids line up visually; dark-mode variants (#141D33
             card bg / border-white/10) follow the same convention as
             Rooms/Faculty/Sections Index pages (see isDark usage there). -->
        <div class="flex-1 min-w-0 relative">
            <!-- Save-in-flight indicator — was previously tracked in
                 state (`saving`) but never actually shown, so a drag/
                 click looked like it did nothing until the response
                 came back. Now visible for the whole duration of the
                 request. -->
            <div
                v-if="saving"
                class="sticky top-0 left-0 z-30 mb-2 inline-flex items-center gap-2 rounded-full bg-blue-600 text-white text-xs px-3 py-1.5 shadow"
            >
                <i class="pi pi-spin pi-spinner"></i>
                {{ savingLabel || 'Saving…' }}
            </div>

            <div class="overflow-x-auto border rounded-xl" :class="isDark ? 'border-white/10' : 'border-slate-300'">
                <div
                    class="grid text-[13px]"
                    :class="{ 'opacity-60 pointer-events-none': saving }"
                    :style="{
                        gridTemplateColumns: `120px repeat(${days.length}, minmax(130px, 1fr))`,
                        gridTemplateRows: `36px repeat(${slots.length}, 24px)`,
                    }"
                >
                    <!-- corner -->
                    <div class="border-b border-r" :class="isDark ? 'border-white/10 bg-[#141D33]' : 'border-slate-300 bg-slate-100'"></div>
                    <!-- day headers -->
                    <div
                        v-for="day in days"
                        :key="`head-${day}`"
                        class="border-b border-r flex items-center justify-center font-bold"
                        :class="isDark ? 'border-white/10 bg-[#141D33] text-white' : 'border-slate-300 bg-slate-100 text-slate-700'"
                    >
                        {{ DAY_LABELS[day] || day }}
                    </div>

                    <!-- time labels -->
                    <template v-for="(slot, slotIndex) in slots" :key="`t-${slot}`">
                        <div
                            class="border-r border-b flex items-center justify-end px-1.5 leading-none whitespace-nowrap overflow-visible text-[10px]"
                            :class="[
                                isDark ? 'border-white/10' : 'border-slate-300',
                                isOnHourSlot(slot)
                                    ? (isDark ? 'text-slate-200 font-semibold' : 'text-slate-700 font-semibold')
                                    : (isDark ? 'text-slate-400 font-medium' : 'text-slate-500 font-medium'),
                            ]"
                            :style="{ gridColumn: 1, gridRow: slotIndex + 2 }"
                        >
                            {{ formatSlotRange(slot) }}
                        </div>
                    </template>

                    <!-- empty clickable/droppable cells -->
                    <template v-for="(day, dayIndex) in days" :key="`col-${day}`">
                        <div
                            v-for="(slot, slotIndex) in slots"
                            :key="`cell-${day}-${slot}`"
                            class="border-r border-b cursor-pointer transition-colors"
                            :class="[
                                isDark ? 'border-white/10' : 'border-slate-300',
                                isDark ? 'hover:bg-white/5' : 'hover:bg-blue-50',
                            ]"
                            :style="{ gridRow: slotIndex + 2, gridColumn: dayIndex + 2 }"
                            @click="assignCell(day, slot)"
                        ></div>
                    </template>

                    <!-- Read-only room-based blocks (F2F/combined) — placed and
                         edited in Room Grid only; shown here so this grid reads
                         as the section's WHOLE week. No ✕, no click/drag. -->
                    <template v-for="row in scheduledRoomRows" :key="`roomblock-${row.id}`">
                        <div
                            v-for="day in rowDayTokens(row)"
                            :key="`roomblock-${row.id}-${day}`"
                            class="relative z-[5] m-[1px] rounded-md px-2 py-1 overflow-hidden text-white text-[11px] leading-tight bg-violet-500/90 cursor-default"
                            :style="{
                                gridRow: `${blockFor(row).startRow} / span ${blockFor(row).span}`,
                                gridColumn: days.indexOf(day) + 2,
                            }"
                            :title="`${row.subject?.subject_code} — ${row.room?.room_name ?? 'Room TBA'} — ${row.faculty ? row.faculty.last_name : 'No faculty yet'} — ${to12Hour(row.start_time)}–${to12Hour(row.end_time)} — placed via Room Grid`"
                        >
                            <p class="font-semibold truncate">{{ row.subject?.subject_code }}</p>
                            <p class="truncate text-[10px] opacity-90">🏫 {{ row.room?.room_name ?? 'Room TBA' }}</p>
                            <p class="truncate text-[10px] opacity-90">{{ row.faculty?.full_name ?? 'No faculty yet' }}</p>
                            <p class="truncate text-[10px] font-medium opacity-90">{{ to12Hour(row.start_time) }}–{{ to12Hour(row.end_time) }}</p>
                        </div>
                    </template>

                    <!-- Editable Online blocks, drawn on top of the empty cell grid -->
                    <template v-for="row in scheduledOnlineRows" :key="`block-${row.id}`">
                        <div
                            v-for="day in rowDayTokens(row)"
                            :key="`block-${row.id}-${day}`"
                            class="relative z-[5] m-[1px] rounded-md px-2 py-1 overflow-hidden text-white text-[11px] leading-tight group bg-sky-500/90"
                            :style="{
                                gridRow: `${blockFor(row).startRow} / span ${blockFor(row).span}`,
                                gridColumn: days.indexOf(day) + 2,
                            }"
                            :title="`${row.subject?.subject_code} — ${row.faculty ? row.faculty.last_name : 'No faculty yet'} — ${to12Hour(row.start_time)}–${to12Hour(row.end_time)}`"
                        >
                            <p class="font-semibold truncate">{{ row.subject?.subject_code }}</p>
                            <p class="truncate text-[10px] opacity-90">🌐 Online</p>
                            <p class="truncate text-[10px] opacity-90">{{ row.faculty?.full_name ?? 'No faculty yet' }}</p>
                            <p class="truncate text-[10px] font-medium opacity-90">{{ to12Hour(row.start_time) }}–{{ to12Hour(row.end_time) }}</p>
                            <Button
                                icon="pi pi-times"
                                text
                                rounded
                                size="small"
                                class="!absolute !top-0 !right-0 !w-4 !h-4 !text-white opacity-0 group-hover:opacity-100"
                                aria-label="Remove from grid"
                                @click.stop="removeFromGrid(row)"
                            />
                        </div>
                    </template>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mt-2">
                Click a subject in "Unscheduled" to select it, then click an open cell to place it. Click a placed block's ✕ to remove it.
            </p>
        </div>
    </div>
</template>