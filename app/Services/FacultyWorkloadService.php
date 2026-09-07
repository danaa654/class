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
    private function activePlacements(Faculty $faculty, ?int $excludingSectionSubjectId = null)
    {
        return SectionSubject::query()
            ->where('faculty_id', $faculty->id)
            ->whereIn('status', ['Scheduled', 'Draft'])
            ->whereIn('section_id', $this->conflictService->activeSemesterSectionIds())
            ->whereNull('merged_into_section_subject_id')
            ->when($excludingSectionSubjectId, fn ($q) => $q->where('id', '!=', $excludingSectionSubjectId))
            ->with('subject:id,units,lecture_hours,laboratory_hours')
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->subject !== null);
    }

    /**
     * The Faculty member's current committed load, in whichever unit
     * their `workload_type` uses (Units, or Weekly Hours).
     */
    public function currentLoad(Faculty $faculty, ?int $excludingSectionSubjectId = null): int
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
    public function assignedSubjectsCount(Faculty $faculty, ?int $excludingSectionSubjectId = null): int
    {
        return $this->activePlacements($faculty, $excludingSectionSubjectId)->count();
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
     * Irregular-section row is never listed on its own here (it's
     * excluded via whereNull('merged_into_section_subject_id') below,
     * same as activePlacements()); instead its Section Code is folded
     * into its host row's `section_code`, e.g. "BSIT-4A &
     * BSIT-4A-IRREG", so the Registrar can see every Section actually
     * sitting in that one hour without the class being counted (or
     * its load charged) twice.
     *
     * @return array<int, array{
     *     id: int, edp_code: ?string, subject_code: ?string,
     *     subject_title: ?string, units: int, load: int,
     *     section_code: ?string, room_name: ?string, days: ?string,
     *     start_time: ?string, end_time: ?string, status: ?string,
     * }>
     */
    public function assignedPlacements(Faculty $faculty, ?int $excludingSectionSubjectId = null): array
    {
        $placements = SectionSubject::query()
            ->where('faculty_id', $faculty->id)
            ->whereIn('status', ['Scheduled', 'Draft'])
            ->whereIn('section_id', $this->conflictService->activeSemesterSectionIds())
            ->whereNull('merged_into_section_subject_id')
            ->when($excludingSectionSubjectId, fn ($q) => $q->where('id', '!=', $excludingSectionSubjectId))
            ->with([
                'subject:id,subject_code,subject_title,units,lecture_hours,laboratory_hours',
                'section:id,section_code',
                'room:id,room_name',
                // Every Irregular-section row merged into THIS one —
                // riding along on the exact same class session, never
                // a separate one. See mergedPlacements() on the model.
                'mergedPlacements.section:id,section_code',
            ])
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->subject !== null)
            ->sortBy(fn (SectionSubject $ss) => $ss->subject->subject_code)
            ->values();

        $usesHours = $this->usesHours($faculty);

        return $placements->map(function (SectionSubject $ss) use ($usesHours) {
            $sectionCodes = collect([$ss->section?->section_code])
                ->merge($ss->mergedPlacements->pluck('section.section_code'))
                ->filter()
                ->unique()
                ->values();

            return [
                'id' => $ss->id,
                // Needed by the Faculty Details page's inline schedule
                // editor: the Room options endpoint
                // (scheduling.section-subjects.rooms) is Section-scoped,
                // and the save itself goes through the Room Grid's
                // cross-section move endpoint
                // (scheduling.room-grid.move), which authorizes against
                // THIS row's own Section regardless of what Section (if
                // any) is "currently open" elsewhere in the UI — see
                // SectionSubjectController::moveRoomGridAssignment().
                'section_id' => $ss->section_id,
                'edp_code' => $ss->edp_code,
                'subject_code' => $ss->subject->subject_code,
                'subject_title' => $ss->subject->subject_title,
                'units' => (int) $ss->subject->units,
                'load' => $usesHours
                    ? (int) $ss->subject->lecture_hours + (int) $ss->subject->laboratory_hours
                    : (int) $ss->subject->units,
                // Lets the Faculty Details page's Room picker put
                // Laboratory rooms first for a subject that needs lab
                // time — same "wants Laboratory" signal SectionSubjects/
                // Show.vue's own Room dropdown groups on
                // (subject.laboratory_hours > 0).
                'requires_lab' => (int) $ss->subject->laboratory_hours > 0,
                // The Subject's real required weekly teaching hours —
                // same value + fallback rule
                // SectionSubjectController::performScheduleAssignmentUpdate()'s
                // Weekly Hours Mismatch check uses (lecture + laboratory
                // hours, defaulting to 3 when the Subject has neither
                // set). Lets the Faculty Details page's "Suggest
                // Available Time" filter out incomplete candidates
                // before they're ever shown, instead of only catching
                // the mismatch after the Registrar tries to Save.
                'required_hours' => ((int) $ss->subject->lecture_hours + (int) $ss->subject->laboratory_hours) ?: 3,
                // Host + every merged rider's Section Code, joined
                // with " & " (e.g. "BSIT-4A & BSIT-4A-IRREG") — falls
                // back to the plain single Section Code when nothing
                // is merged into this row.
                'section_code' => $sectionCodes->implode(' & '),
                'room_id' => $ss->room_id,
                'room_name' => $ss->room?->room_name,
                'days' => $ss->days,
                'start_time' => $ss->start_time,
                'end_time' => $ss->end_time,
                'status' => $ss->status,
            ];
        })->all();
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
    public function evaluate(Faculty $faculty, ?\App\Models\Subject $additionalSubject = null, ?int $excludingSectionSubjectId = null, bool $includePlacements = false): array
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
    public function wouldExceed(Faculty $faculty, ?\App\Models\Subject $additionalSubject, ?int $excludingSectionSubjectId = null): bool
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

        return $placements->sum(function (SectionSubject $ss) use ($usesHours) {
            if (! $ss->subject) {
                return 0;
            }

            return $usesHours
                ? (int) $ss->subject->lecture_hours + (int) $ss->subject->laboratory_hours
                : (int) $ss->subject->units;
        });
    }
}