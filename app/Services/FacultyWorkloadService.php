<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\SectionSubject;

/**
 * FACULTY WORKLOAD VALIDATION SYSTEM.
 *
 * Single source of truth for "how loaded is this Faculty member right
 * now, and would assigning them one more Subject push them over their
 * Maximum Teaching Load". Every place in the scheduling engine that
 * needs a workload number or a workload decision — Auto Generate
 * Schedule, Recommend Faculty, Manual Assignment, Save Schedule, and
 * the Faculty Master's Workload tab/dashboard indicators — calls this
 * service rather than recomputing the sum itself, so the number the
 * Registrar sees in the recommendation panel, the Faculty profile, and
 * the "Teaching Load Limit Exceeded" warning can never quietly
 * disagree with each other.
 *
 * ACTIVE SEMESTER SCOPE
 * ------------------------------------------------------------------
 * Workload is always computed across every College/Department/Section/
 * Year Level, but ONLY for placements belonging to Sections in the
 * currently Active Academic Term (School Year + Semester) — see
 * ScheduleConflictService::activeSemesterSectionIds(), which this
 * service defers to so the two can never disagree about what "the
 * active semester" means. Inactive/past/future semesters never count
 * toward a Faculty member's current load.
 *
 * Counts both 'Scheduled' AND 'Draft' placements (never 'Conflict')
 * belonging to that faculty member — Draft is included deliberately:
 * Auto Generate Schedule writes each accepted assignment as Draft
 * before the Registrar clicks "Save Schedule", and later subjects in
 * the same run must still see that committed load, or the same
 * faculty member would keep winning "Lowest Teaching Load" for every
 * subject in the batch instead of load rotating across the department
 * as it actually grows.
 *
 * WORKLOAD MEASUREMENT
 * ------------------------------------------------------------------
 * Supports whichever measurement the institution has configured on
 * Faculty::workload_type — 'units' (Subject::units, the default) or
 * 'hours' (Subject::lecture_hours + Subject::laboratory_hours per
 * week, checked against Faculty::max_weekly_hours instead of
 * Faculty::max_teaching_units).
 */
class FacultyWorkloadService
{
    public function __construct(
        private readonly ScheduleConflictService $conflictService,
    ) {
    }

    /**
     * "Overloaded" threshold — current/projected load at or beyond
     * this percentage of the Maximum Teaching Load counts as
     * overloaded (🔴). Below WARNING_THRESHOLD is "healthy" (🟢); in
     * between is "approaching the limit" (🟡).
     */
    public const OVERLOADED_THRESHOLD = 100;

    public const WARNING_THRESHOLD = 85;

    /**
     * Every 'Scheduled'/'Draft' SectionSubject placement currently
     * assigned to this Faculty member, scoped to the active semester.
     * Loaded once and reused by both currentLoad() and
     * assignedSubjectsCount() so a single call site never issues the
     * query twice for the same evaluation.
     *
     * INTELLIGENT IRREGULAR SECTION SCHEDULING — a merged Irregular-
     * section row (`merged_into_section_subject_id` set) is the SAME
     * class session as its host row, just ridden along on by another
     * Section — never a second class the Faculty member actually
     * teaches. Counting both would double the Faculty's load and
     * "Assigned Subjects" count for a single hour actually spent in
     * front of a room, so merged riders are excluded here; the host
     * row alone represents that session's load. See
     * ScheduleConflictService::mergeExclusionIds() for the matching
     * "never a conflict" rule this same relationship drives.
     *
     * @return \Illuminate\Support\Collection<int, SectionSubject>
     */
    private function activePlacements(Faculty $faculty, int|array|null $excludingSectionSubjectId = null)
    {
        return SectionSubject::query()
            ->where('faculty_id', $faculty->id)
            ->whereIn('status', ['Scheduled', 'Draft'])
            ->whereIn('section_id', $this->conflictService->activeSemesterSectionIds())
            ->whereNull('merged_into_section_subject_id')
            ->when($excludingSectionSubjectId, fn ($q) => $q->whereNotIn('id', (array) $excludingSectionSubjectId))
            ->with('subject:id,units,lecture_hours,laboratory_hours')
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->subject !== null);
    }

    /**
     * The Faculty member's current committed load, in whichever unit
     * their `workload_type` uses (Units, or Weekly Hours).
     *
     * $excludingSectionSubjectId accepts a single id or an array of
     * ids — callers evaluating a split-delivery row (Face-to-Face +
     * Online pair, same section_id+subject_id) must exclude BOTH the
     * row being saved AND its sibling component, or the sibling's
     * still-counted placement plus this row's own "additional" load
     * double-counts a single 3-unit Subject as 6. See
     * SectionSubjectController::workloadWarningFor(), which builds
     * that id list.
     */
    public function currentLoad(Faculty $faculty, int|array|null $excludingSectionSubjectId = null): int
    {
        $placements = $this->activePlacements($faculty, $excludingSectionSubjectId);

        return $this->sumLoad($faculty, $placements);
    }

    /**
     * BATCHED "current load" LOOKUP — the N+1-free counterpart to
     * calling currentLoad() once per Faculty in a loop.
     *
     * SectionSubjectController::show() needs `current_load` for every
     * Active Faculty member so the scheduling table's Faculty dropdown
     * can show it next to each option. Doing that via currentLoad()
     * inside a ->map() over the whole Faculty roster runs one fresh
     * SectionSubject query per Faculty member (activePlacements()
     * queries individually, scoped by faculty_id) — on a roster of
     * N Active Faculty that's N extra round trips on every single
     * Section click, which is what was actually making that page slow
     * to load. RoomUtilizationService::summarizeRooms() already avoids
     * this for Rooms by loading every placement once and grouping in
     * memory; this does the same thing for Faculty.
     *
     * @param  \Illuminate\Support\Collection<int, Faculty>  $faculty
     * @return array<int, int> Faculty id => current load
     */
    public function currentLoadsFor($faculty): array
    {
        $facultyIds = $faculty->pluck('id')->all();

        if (empty($facultyIds)) {
            return [];
        }

        $placementsByFaculty = SectionSubject::query()
            ->whereIn('faculty_id', $facultyIds)
            ->whereIn('status', ['Scheduled', 'Draft'])
            ->whereIn('section_id', $this->conflictService->activeSemesterSectionIds())
            ->whereNull('merged_into_section_subject_id')
            ->with('subject:id,units,lecture_hours,laboratory_hours')
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->subject !== null)
            ->groupBy('faculty_id');

        return $faculty->mapWithKeys(function (Faculty $facultyMember) use ($placementsByFaculty) {
            $placements = $placementsByFaculty->get($facultyMember->id, collect());

            return [$facultyMember->id => $this->sumLoad($facultyMember, $placements)];
        })->all();
    }

    /**
     * BATCHED FULL EVALUATION — same output shape as evaluate() (with
     * no additional-subject/excluding-id arguments), for an entire
     * roster of Faculty in one pass instead of one call per row.
     *
     * FacultyController::index() was calling evaluate() once per
     * Faculty row on the page — and evaluate() itself calls
     * currentLoad() AND assignedSubjectsCount(), each of which queries
     * activePlacements() independently — so a page of 10 Faculty ran
     * 20 fresh SectionSubject queries on every single visit. That was
     * the actual source of the Faculty page's sidebar-click lag, the
     * same pattern currentLoadsFor() already fixed for
     * SectionSubjectController::show(). This loads every relevant
     * placement once, groups it in memory, then reuses evaluate()'s
     * exact math with zero extra queries per Faculty member.
     *
     * Only covers the "no candidate additional Subject" case (the one
     * FacultyController::index() actually needs) and never attaches
     * the placement list ($includePlacements) — the "would adding one
     * more Subject exceed the cap" check and the Faculty Details
     * Workload tab's placement list stay on evaluate() itself,
     * unchanged, exactly as before.
     *
     * @param  \Illuminate\Support\Collection<int, Faculty>  $faculty
     * @return array<int, array> Faculty id => same shape as evaluate()
     */
    public function evaluateMany($faculty): array
    {
        $facultyIds = $faculty->pluck('id')->all();

        if (empty($facultyIds)) {
            return [];
        }

        $placementsByFaculty = SectionSubject::query()
            ->whereIn('faculty_id', $facultyIds)
            ->whereIn('status', ['Scheduled', 'Draft'])
            ->whereIn('section_id', $this->conflictService->activeSemesterSectionIds())
            ->whereNull('merged_into_section_subject_id')
            ->with('subject:id,units,lecture_hours,laboratory_hours')
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->subject !== null)
            ->groupBy('faculty_id');

        return $faculty->mapWithKeys(function (Faculty $facultyMember) use ($placementsByFaculty) {
            $placements = $placementsByFaculty->get($facultyMember->id, collect());

            $max = $this->maxLoad($facultyMember);
            $current = $this->sumLoad($facultyMember, $placements);
            $percent = $max > 0 ? (int) round(($current / $max) * 100) : 0;
            $status = $this->statusFor($percent);

            return [$facultyMember->id => [
                'current' => $current,
                'max' => $max,
                'remaining' => $max - $current,
                'projected' => $current,
                'additional' => 0,
                'percent' => $percent,
                'projected_percent' => $percent,
                'exceeds' => false,
                'status' => $status,
                'status_color' => $this->statusColor($status),
                'unit_label' => $this->unitLabel($facultyMember),
                'assigned_subjects' => $placements->count(),
            ]];
        })->all();
    }

    /**
     * How many Subjects (placements) this Faculty member is currently
     * carrying in the active semester — the "Number of Assigned
     * Subjects" field the Faculty profile exposes.
     */
    public function assignedSubjectsCount(Faculty $faculty, int|array|null $excludingSectionSubjectId = null): int
    {
        // Same dedup as sumLoad() — a split Face-to-Face/Online pair is
        // one assigned Subject, not two.
        return $this->activePlacements($faculty, $excludingSectionSubjectId)
            ->unique(fn (SectionSubject $ss) => $ss->section_id.'-'.$ss->subject_id)
            ->count();
    }

    /**
     * The actual list of Subjects/Sections this Faculty member is
     * currently assigned to teach (the same 'Scheduled'/'Draft',
     * active-semester placements that back currentLoad() and
     * assignedSubjectsCount()) — what the Faculty Workload tab lists
     * under "Assigned Subjects" so the Registrar can see *which*
     * subjects make up the load figure, not just the count.
     *
     * INTELLIGENT IRREGULAR SECTION SCHEDULING — same "one class
     * session, one row" rule as activePlacements(): a merged
     * Irregular-section row is folded into its host row's
     * `section_code`, e.g. "BSIT-4A & BSIT-4A-IRREG", so the
     * Registrar can see every Section actually sitting in that one
     * hour without the class being counted (or its load charged)
     * twice.
     *
     * FIX (cross-section merge visibility): a merged row used to be
     * excluded outright via whereNull('merged_into_section_subject_id')
     * below. That's fine for the "one Faculty, same Section family"
     * Irregular-section case (BSIT-4A-IRREG has no workload page of
     * its own that would miss anything), but breaks down the moment
     * two INDEPENDENT Sections (e.g. BSIT-1A and BSIT-1B) merge a
     * shared Online slot: the rider row still belongs to ITS OWN
     * Section (section_id is unchanged by merging — only
     * merged_into_section_subject_id is set), so dropping it from the
     * query dropped it from its own Section's group too, making that
     * Section look like it had no Online meeting at all, even though
     * the class still happens and the Section is still enrolled in
     * it. Riders are no longer excluded from the query — grouping is
     * still keyed by section_id+subject_id (see below), so a rider
     * simply reappears as an extra Schedule line inside its OWN
     * Section's existing group, exactly like a normal split
     * Face-to-Face/Online pair. This does NOT affect Current Load
     * totals: `load` here is still computed ONCE per group from
     * $primary->subject, never per Schedule row, and sumLoad()/
     * activePlacements() (the totals used for load-cap checks) are
     * untouched and keep their own whereNull() exclusion.
     *
     * SPLIT-DELIVERY SCHEDULING — a Face-to-Face/Online split pair
     * (same section_id + subject_id) is grouped into ONE array entry
     * here, not two, so it reads as one assigned Subject with two
     * Schedule lines rather than the Subject's Units appearing to
     * double. See this method's inline docblock for the grouping rule
     * and sumLoad()'s docblock for why the double-count mattered.
     *
     * @return array<int, array{
     *     id: int, edp_code: ?string, subject_code: ?string,
     *     subject_title: ?string, units: int, load: int,
     *     section_code: ?string, status: ?string,
     *     schedules: array<int, array{
     *         id: int, section_id: int, delivery_mode: ?string,
     *         room_id: ?int, room_name: ?string, days: ?string,
     *         start_time: ?string, end_time: ?string, status: ?string,
     *         shared_with_section: ?string,
     *     }>,
     * }>
     */
    public function assignedPlacements(Faculty $faculty, int|array|null $excludingSectionSubjectId = null): array
    {
        $placements = SectionSubject::query()
            ->where('faculty_id', $faculty->id)
            ->whereIn('status', ['Scheduled', 'Draft'])
            ->whereIn('section_id', $this->conflictService->activeSemesterSectionIds())
            // NOTE: no longer whereNull('merged_into_section_subject_id')
            // here — see the "FIX (cross-section merge visibility)"
            // docblock above. Riders are kept and handled per-group
            // below instead of being dropped from the query outright.
            ->when($excludingSectionSubjectId, fn ($q) => $q->where('id', '!=', $excludingSectionSubjectId))
            ->with([
                'subject:id,subject_code,subject_title,units,lecture_hours,laboratory_hours',
                'section:id,section_code',
                'room:id,room_name',
                // Every Irregular-section row merged into THIS one —
                // riding along on the exact same class session, never
                // a separate one. See mergedPlacements() on the model.
                'mergedPlacements.section:id,section_code',
                // A rider row's own merge target — needed to label
                // that row "Shared with <host Section>" below (the
                // host side already gets its partner's code via
                // mergedPlacements above).
                'mergedInto.section:id,section_code',
            ])
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->subject !== null);

        $usesHours = $this->usesHours($faculty);

        // SPLIT-DELIVERY SCHEDULING — group by section_id+subject_id so
        // a Face-to-Face/Online split pair renders (and is edited) as
        // ONE row with two Schedule lines, instead of two separate rows
        // that (before this fix) double-counted the subject's Units in
        // the "Load" column shown for each — same dedup key sumLoad()
        // uses for Current Load, so this table's total always matches
        // the summary card above it.
        return $placements
            ->groupBy(fn (SectionSubject $ss) => $ss->section_id.'-'.$ss->subject_id)
            ->map(function ($group) use ($usesHours) {
                // The Face-to-Face half (or the only row, for a Subject
                // that was never split) is the group's "primary" row —
                // its id/edp_code identifies the whole group. An Online
                // row is never primary on its own: the Registrar already
                // recognizes a split Subject+Section by its Face-to-Face
                // half's EDP Code from the Scheduling Workspace.
                $primary = $group->first(fn (SectionSubject $ss) => $ss->delivery_mode !== 'online') ?? $group->first();

                $sectionCodes = collect([$primary->section?->section_code])
                    ->merge($primary->mergedPlacements->pluck('section.section_code'))
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'id' => $primary->id,
                    'section_id' => $primary->section_id,
                    'edp_code' => $primary->edp_code,
                    'subject_code' => $primary->subject->subject_code,
                    'subject_title' => $primary->subject->subject_title,
                    'units' => (int) $primary->subject->units,
                    // Counted ONCE per group, never per row — see this
                    // method's docblock.
                    'load' => $usesHours
                        ? (int) $primary->subject->lecture_hours + (int) $primary->subject->laboratory_hours
                        : (int) $primary->subject->units,
                    'requires_lab' => (int) $primary->subject->laboratory_hours > 0,
                    'required_hours' => ((int) $primary->subject->lecture_hours + (int) $primary->subject->laboratory_hours) ?: 3,
                    'section_code' => $sectionCodes->implode(' & '),
                    // Group-level Status: 'Scheduled' only once EVERY
                    // row in the group is — mirrors
                    // Section::withSubjectProgressCounts()'s same
                    // "every component row must be done" rule, so a
                    // Draft Online half with an already-Scheduled
                    // Face-to-Face half still reads as needing
                    // attention rather than as fully Scheduled.
                    'status' => $group->every(fn (SectionSubject $ss) => $ss->status === 'Scheduled') ? 'Scheduled' : 'Draft',
                    // One entry per delivery-mode row in the group — a
                    // never-split Subject has exactly one (same single
                    // Schedule line as before this change); a split
                    // Subject has two (Face-to-Face, then Online),
                    // each independently editable in the Faculty
                    // Details page's inline editor.
                    //
                    // `shared_with_section` — UX LABEL for the
                    // faculty-shortage merge scenario: when a Section
                    // can't get its own instructor for a slot and the
                    // school instead has one Faculty run a single
                    // Online session for two Sections at once, this
                    // makes that visible on BOTH sides (not just as a
                    // combined section_code on the host) so the
                    // Registrar immediately understands why this one
                    // line reads differently from a normal class,
                    // instead of assuming the slot is free or that
                    // something's broken. Null for a normal,
                    // never-merged row.
                    'schedules' => $group
                        ->sortBy(fn (SectionSubject $ss) => $ss->delivery_mode === 'online' ? 1 : 0)
                        ->map(function (SectionSubject $ss) {
                            $sharedWith = $ss->merged_into_section_subject_id
                                // Rider row: labelled with the host's Section.
                                ? $ss->mergedInto?->section?->section_code
                                // Host row: labelled with its rider(s)' Section(s).
                                : $ss->mergedPlacements->pluck('section.section_code')->filter()->implode(' & ');

                            return [
                                'id' => $ss->id,
                                'section_id' => $ss->section_id,
                                'delivery_mode' => $ss->delivery_mode,
                                'room_id' => $ss->room_id,
                                'room_name' => $ss->delivery_mode === 'online' ? 'Online' : $ss->room?->room_name,
                                'days' => $ss->days,
                                'start_time' => $ss->start_time,
                                'end_time' => $ss->end_time,
                                'status' => $ss->status,
                                'shared_with_section' => $sharedWith ?: null,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('subject_code')
            ->values()
            ->all();
    }

    /**
     * Deactivation-impact snapshot (Faculty Management request
     * workflow) — everything a requester or reviewer needs to see
     * before deactivating this Faculty member: how many active
     * subjects/sections/hours are riding on them right now, and
     * whether any of those sit inside a FINALIZED/locked Section
     * (spec Section 10 — finalized schedules must never be silently
     * modified by a deactivation).
     *
     * Reuses assignedPlacements() so this can never disagree with the
     * Workload tab/dashboard indicators about what "currently
     * assigned" means. Called both when a Dean/OIC/Assistant Dean
     * submits a Deactivation request (stored as
     * FacultyRequest::affected_summary) AND again at review/direct-
     * deactivation time (never trusted as still current) — see
     * FacultyRequestController and FacultyController::destroy().
     *
     * @return array{
     *   has_active_assignments: bool,
     *   subject_count: int,
     *   section_count: int,
     *   weekly_load: int,
     *   load_unit: string,
     *   subject_codes: array<int, string>,
     *   section_codes: array<int, string>,
     *   has_finalized_assignment: bool,
     *   finalized_section_codes: array<int, string>,
     * }
     */
    public function deactivationImpact(Faculty $faculty): array
    {
        $placements = $this->assignedPlacements($faculty);

        $sectionIds = collect($placements)->pluck('id');
        $finalizedSectionIds = SectionSubject::query()
            ->whereIn('id', $sectionIds)
            ->whereHas('section', fn ($q) => $q->where('is_finalized', true))
            ->with('section:id,section_code')
            ->get()
            ->pluck('section.section_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $subjectCodes = collect($placements)->pluck('subject_code')->unique()->values()->all();
        $sectionCodes = collect($placements)
            ->flatMap(fn (array $p) => explode(' & ', $p['section_code']))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'has_active_assignments' => count($placements) > 0,
            'subject_count' => count($subjectCodes),
            'section_count' => count($sectionCodes),
            'weekly_load' => collect($placements)->sum('load'),
            'load_unit' => $this->unitLabel($faculty),
            'subject_codes' => $subjectCodes,
            'section_codes' => $sectionCodes,
            'has_finalized_assignment' => count($finalizedSectionIds) > 0,
            'finalized_section_codes' => $finalizedSectionIds,
        ];
    }

    /**
     * How much load one Subject contributes, in whichever unit the
     * Faculty member's `workload_type` uses.
     */
    public function loadForSubject(Faculty $faculty, ?\App\Models\Subject $subject): int
    {
        if (! $subject) {
            return 0;
        }

        return $this->usesHours($faculty)
            ? (int) $subject->lecture_hours + (int) $subject->laboratory_hours
            : (int) $subject->units;
    }

    /**
     * The Maximum Teaching Load configured for this Faculty member, in
     * whichever unit their `workload_type` uses. 0 means "no cap
     * configured" — every validation below treats 0 as "cannot be
     * overloaded" rather than "always overloaded".
     */
    public function maxLoad(Faculty $faculty): int
    {
        return $this->usesHours($faculty)
            ? (int) ($faculty->max_weekly_hours ?? 0)
            : (int) ($faculty->max_teaching_units ?? 0);
    }

    public function usesHours(Faculty $faculty): bool
    {
        return $faculty->workload_type === 'hours';
    }

    public function unitLabel(Faculty $faculty): string
    {
        return $this->usesHours($faculty) ? 'Hours' : 'Units';
    }

    /**
     * FULL WORKLOAD EVALUATION — the single call every integration
     * point (Auto Generate, Recommend Faculty, Manual Assignment,
     * Save Schedule, Faculty Workload tab, Dashboard Indicators) uses
     * to get a complete, consistent picture of a Faculty member's
     * standing against one candidate additional Subject (or none, to
     * just inspect their current standing).
     *
     * @return array{
     *     current: int, max: int, remaining: int, projected: int,
     *     additional: int, percent: int, projected_percent: int,
     *     exceeds: bool, status: string, status_color: string,
     *     unit_label: string, assigned_subjects: int,
     *     assigned_placements?: array,
     * }
     *
     * @param  bool  $includePlacements  Whether to also attach the full
     *      assigned-subjects list (assignedPlacements()). Off by default
     *      because list/dashboard views (Faculty Master roster, Recommend
     *      Faculty panel, etc.) evaluate() every row and only need the
     *      summary numbers — the Faculty Details "Workload" tab is the
     *      one place that actually needs the list, so it opts in.
     */
    public function evaluate(Faculty $faculty, ?\App\Models\Subject $additionalSubject = null, int|array|null $excludingSectionSubjectId = null, bool $includePlacements = false): array
    {
        $max = $this->maxLoad($faculty);
        $current = $this->currentLoad($faculty, $excludingSectionSubjectId);
        $additional = $this->loadForSubject($faculty, $additionalSubject);
        $projected = $current + $additional;

        $percent = $max > 0 ? (int) round(($current / $max) * 100) : 0;
        $projectedPercent = $max > 0 ? (int) round(($projected / $max) * 100) : 0;
        $exceeds = $max > 0 && $projected > $max;
        $status = $this->statusFor($percent);

        $result = [
            'current' => $current,
            'max' => $max,
            'remaining' => $max - $current,
            'projected' => $projected,
            'additional' => $additional,
            'percent' => $percent,
            'projected_percent' => $projectedPercent,
            'exceeds' => $exceeds,
            'status' => $status,
            'status_color' => $this->statusColor($status),
            'unit_label' => $this->unitLabel($faculty),
            'assigned_subjects' => $this->assignedSubjectsCount($faculty, $excludingSectionSubjectId),
        ];

        if ($includePlacements) {
            $result['assigned_placements'] = $this->assignedPlacements($faculty, $excludingSectionSubjectId);
        }

        return $result;
    }

    /**
     * VALIDATION RULE — "Current + New > Maximum -> Reject", the hard
     * cap every integration point enforces unless an Administrator
     * explicitly overrides it. A Faculty member with no Maximum Load
     * configured (max = 0) can never be "exceeded".
     */
    public function wouldExceed(Faculty $faculty, ?\App\Models\Subject $additionalSubject, int|array|null $excludingSectionSubjectId = null): bool
    {
        $max = $this->maxLoad($faculty);
        if ($max <= 0) {
            return false;
        }

        $current = $this->currentLoad($faculty, $excludingSectionSubjectId);
        $additional = $this->loadForSubject($faculty, $additionalSubject);

        return ($current + $additional) > $max;
    }

    /**
     * Percent -> status bucket shared by the Recommendation Score
     * breakdown and the Faculty Workload tab / Dashboard Indicators,
     * so "🟢/🟡/🔴" always means the same thresholds everywhere.
     */
    public function statusFor(int $percent): string
    {
        return match (true) {
            $percent >= self::OVERLOADED_THRESHOLD => 'overloaded',
            $percent >= self::WARNING_THRESHOLD => 'high',
            default => 'healthy',
        };
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'overloaded' => 'red',
            'high' => 'yellow',
            default => 'green',
        };
    }

    public function statusEmoji(string $status): string
    {
        return match ($status) {
            'overloaded' => '🔴',
            'high' => '🟡',
            default => '🟢',
        };
    }

    /**
     * Sums a collection of SectionSubject placements' load in
     * whichever unit the Faculty member's `workload_type` uses.
     *
     * @param  \Illuminate\Support\Collection<int, SectionSubject>  $placements
     */
    private function sumLoad(Faculty $faculty, $placements): int
    {
        $usesHours = $this->usesHours($faculty);

        // SPLIT-DELIVERY SCHEDULING — a Face-to-Face/Online split pair
        // (same section_id + subject_id — see
        // SectionSubject::isSplitComponent()) is stored as TWO rows but
        // is only ONE class this Faculty member teaches. Summing every
        // row's units/hours — as this used to do — silently DOUBLED a
        // split subject's contribution to the Faculty's load: a 3-unit
        // subject split into a Face-to-Face row and an Online row
        // counted as 6, inflating Current Load (and therefore every
        // Teaching Load Limit check that relies on it — Auto Generate,
        // Recommend Faculty, Save Schedule) for any Faculty teaching
        // even one split subject. Deduping by section_id+subject_id
        // first — counting that pair's units/hours once, however many
        // delivery-mode rows it's split into — fixes that. A Faculty
        // teaching the SAME subject across two DIFFERENT Sections is
        // still counted twice, correctly, since that's two distinct
        // classes, not one split one.
        return $placements
            ->filter(fn (SectionSubject $ss) => $ss->subject !== null)
            ->unique(fn (SectionSubject $ss) => $ss->section_id.'-'.$ss->subject_id)
            ->sum(function (SectionSubject $ss) use ($usesHours) {
                return $usesHours
                    ? (int) $ss->subject->lecture_hours + (int) $ss->subject->laboratory_hours
                    : (int) $ss->subject->units;
            });
    }
}