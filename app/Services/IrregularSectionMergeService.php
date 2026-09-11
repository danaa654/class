<?php

namespace App\Services;

use App\Models\SectionSubject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * INTELLIGENT IRREGULAR SECTION SCHEDULING.
 *
 * A Section marked Section Type = 'Irregular' does not get one
 * uniform block schedule the way a Regular section does — its
 * students take a mix of subjects that doesn't line up with any
 * single Regular section's curriculum year. So instead of asking
 * "where does this whole Section meet", Auto Generate Schedule asks
 * this service ONE SUBJECT AT A TIME: "is there already a Regular
 * section's class in session for this exact Subject that this
 * Irregular section's students could simply sit in on, or does this
 * need its own independent class?"
 *
 * This is the single source of truth AutoScheduleService defers to
 * for every Irregular-section placement, so the recommendation shown
 * in the Auto Generate review panel, the "Merge Recommendation" modal,
 * and whatever actually gets written to the row can never disagree.
 *
 * COMPATIBILITY CRITERIA (a candidate Regular section's class must
 * satisfy every one of these to even be considered — see
 * findCandidates()):
 *
 *   1. Same Subject (subject_id match on the candidate's own
 *      SectionSubject row).
 *   2. Same Curriculum, or an "equivalent curriculum mapping" —
 *      curriculum_id matches exactly, OR the two curriculums belong
 *      to the same Major (an older/newer curriculum version under the
 *      same program is treated as equivalent for merge purposes,
 *      since Irregular students very often carry a prior catalog
 *      year's curriculum_id while retaking/advancing alongside a
 *      Regular section on the current one).
 *   3. Same Major/Program (major_id match — implied by #2, checked
 *      explicitly too so the rule reads clearly on its own).
 *   4. Same Academic Year + Semester (both sections must belong to
 *      the currently Active Academic Term — see
 *      ScheduleConflictService::activeSemesterSectionIds()).
 *   5. The candidate class must already have a real schedule slot
 *      (Faculty, Room, Days, Start/End Time all filled in, status
 *      Scheduled or Draft) — merging rides along on an EXISTING
 *      class session, it never invents one.
 *   6. Room capacity remains sufficient after adding the Irregular
 *      section's estimated_students on top of every student count
 *      already resting on that room slot (the host section's own
 *      estimated_students plus every other Irregular section already
 *      merged into it).
 *   7. No Faculty, Room, or Section conflict is introduced — trivially
 *      true for Faculty/Room since merging adds no new booking, but
 *      the Irregular section itself must not already have a different
 *      class in that exact Day/Time window (checked via
 *      ScheduleConflictService::findSectionConflict()).
 *   8. Faculty workload stays within the configured limit — merging
 *      adds ZERO additional teaching load (it's the same one class
 *      session, not a second one), so this can only ever help, never
 *      hurt, an already-valid candidate. Kept as an explicit check
 *      for defense in depth in case workload_type/limits change
 *      out from under an in-flight recommendation.
 *   9. Academic Calendar scheduling rules — already enforced when the
 *      host class was originally scheduled/validated, so a merge can
 *      never violate them; re-checking the DAYS token against the
 *      currently configured available class days is a cheap defensive
 *      re-verification rather than a new rule.
 *
 * SCORING (best match — see scoreCandidate()):
 *   Ranks every compatible candidate by, in order of weight:
 *     - Room capacity headroom AFTER the merge (prefer the tightest
 *       fit that still comfortably fits — fills existing sections
 *       efficiently instead of scattering a few Irregular students
 *       across many different rooms/sections).
 *     - Lowest scheduling impact (merging is always zero-impact vs.
 *       independent scheduling, so this mainly breaks ties between
 *       multiple otherwise-equal candidates by preferring the one
 *       with more remaining capacity buffer, reducing the odds a
 *       LATER merge into the same class gets rejected).
 *     - Faculty workload delta (always 0 for a merge; included so the
 *       score breakdown reads consistently against the "independent"
 *       alternative, which is NOT zero).
 *     - Timetable compatibility (always 100% for a merge — the
 *       Irregular section, by definition, has no existing schedule
 *       of its own yet to conflict with at Auto Generate time).
 *
 * When zero candidates satisfy every constraint, recommend() returns
 * an 'independent' recommendation with a human-readable explanation
 * of the specific constraint that eliminated every candidate (or "no
 * matching class exists yet" when there was nothing to evaluate at
 * all), and AutoScheduleService falls through to its normal
 * Faculty+Room+Time search for that subject exactly as it would for
 * a Regular section.
 */
class IrregularSectionMergeService
{
    public function __construct(
        private readonly ScheduleConflictService $conflictService,
        private readonly FacultyWorkloadService $workloadService,
    ) {
    }

    /**
     * FULL MERGE EVALUATION for one Irregular-section subject
     * placement — the single call Auto Generate Schedule, the
     * "Merge Recommendation" modal, and the manual override endpoints
     * all use.
     *
     * @return array{
     *     section_subject_id: int, subject_code: string, subject_title: string,
     *     recommendation: 'merge'|'independent',
     *     best_match: ?array, candidates: array<int, array>,
     *     independent_reason: ?string,
     * }
     */
    public function recommend(SectionSubject $irregularRow): array
    {
        $irregularRow->loadMissing(['subject', 'section']);
        $subject = $irregularRow->subject;
        $section = $irregularRow->section;

        $base = [
            'section_subject_id' => $irregularRow->id,
            'subject_code' => $subject?->subject_code,
            'subject_title' => $subject?->subject_title,
        ];

        if (! $subject || ! $section) {
            return [...$base,
                'recommendation' => 'independent',
                'best_match' => null,
                'candidates' => [],
                'independent_reason' => 'Subject or section record is missing.',
            ];
        }

        $hostRows = $this->findHostCandidates($irregularRow);

        if ($hostRows->isEmpty()) {
            return [...$base,
                'recommendation' => 'independent',
                'best_match' => null,
                'candidates' => [],
                'independent_reason' => "No Regular section currently has a scheduled class of {$subject->subject_code} "
                    .'under the same/equivalent curriculum, major, academic year, and semester to merge into.',
            ];
        }

        $evaluated = $hostRows
            ->map(fn (SectionSubject $host) => $this->evaluateCandidate($irregularRow, $host))
            ->values();

        $compatible = $evaluated->filter(fn (array $candidate) => $candidate['compatible'])
            ->sortByDesc(fn (array $candidate) => $candidate['score'])
            ->values();

        if ($compatible->isEmpty()) {
            // Surface the most common blocking reason across every
            // candidate that was considered, so the Administrator sees
            // WHY merging wasn't possible rather than just "no match".
            $reasons = $evaluated->pluck('blocking_reason')->filter()->values();
            $reason = $reasons->first() ?? 'No compatible section satisfied every merge requirement.';
            $reasonSummary = $reasons->count() > 1
                ? $reason." ({$reasons->count()} candidate sections considered, all blocked.)"
                : $reason;

            return [...$base,
                'recommendation' => 'independent',
                'best_match' => null,
                'candidates' => $evaluated->all(),
                'independent_reason' => $reasonSummary,
            ];
        }

        return [...$base,
            'recommendation' => 'merge',
            'best_match' => $compatible->first(),
            'candidates' => $evaluated->all(),
            'independent_reason' => null,
        ];
    }

    /**
     * Applies a merge — copies the target (host) class session's
     * Faculty/Room/Days/Time/Status onto the Irregular section's row
     * and marks the relationship, WITHOUT creating any new
     * Faculty/Room booking of its own.
     *
     * CONCURRENCY GUARD: the host row is locked (`lockForUpdate`) for
     * the duration of the write, and its Faculty/Room/Days/Time are
     * re-read fresh under that lock rather than trusted from whatever
     * $hostRow the caller resolved a moment earlier — closes the race
     * where two Irregular sections both try to merge into the same
     * host class (or the host itself gets moved) at nearly the same
     * moment; see the concurrency hardening spec, and
     * ScheduleConflictService::lockResources() for why the host's own
     * row (not just its Room/Faculty) needs locking here specifically
     * — a merge's "conflict" is capacity headroom on THIS ROW, which a
     * Room/Faculty lock alone wouldn't serialize against a second
     * merge onto the exact same host.
     */
    public function applyMerge(SectionSubject $irregularRow, SectionSubject $hostRow, ?array $recommendationMeta = null): SectionSubject
    {
        return DB::transaction(function () use ($irregularRow, $hostRow, $recommendationMeta) {
            /** @var SectionSubject $lockedHost */
            $lockedHost = SectionSubject::whereKey($hostRow->id)->lockForUpdate()->firstOrFail();

            $irregularRow->update([
                'faculty_id' => $lockedHost->faculty_id,
                'room_id' => $lockedHost->room_id,
                'days' => $lockedHost->days,
                'start_time' => $lockedHost->start_time,
                'end_time' => $lockedHost->end_time,
                'status' => $lockedHost->status,
                'is_auto_generated' => true,
                'is_merged' => true,
                'merged_into_section_subject_id' => $lockedHost->id,
                'auto_generated_meta' => null,
                'merge_recommendation' => $recommendationMeta,
            ]);

            return $irregularRow->refresh();
        });
    }

    /**
     * Reverses a merge — clears the copied schedule and the merge
     * relationship, leaving the row back at "needs scheduling" so it
     * can be picked up by the normal independent Auto Generate path
     * or a fresh merge recommendation.
     */
    public function unmerge(SectionSubject $irregularRow): SectionSubject
    {
        $irregularRow->update([
            'faculty_id' => null,
            'room_id' => null,
            'days' => null,
            'start_time' => null,
            'end_time' => null,
            'status' => 'Draft',
            'is_auto_generated' => false,
            'is_merged' => false,
            'merged_into_section_subject_id' => null,
            'auto_generated_meta' => null,
        ]);

        return $irregularRow->refresh();
    }

    /**
     * SHARED CLASS — REVERSE DIRECTION. recommend()/findHostCandidates()
     * above only ever look for an Irregular row that can fold into an
     * ALREADY-SAVED Regular section's class. This is the mirror image,
     * for the opposite real-world case: $subject is a row about to be
     * scheduled RIGHT NOW at $proposedSlot, and $target is a DIFFERENT
     * section's row that ALREADY occupies that exact slot (most often
     * a Regular section being placed directly on top of an Irregular
     * section's existing class — see the "Regular + IRREG" example).
     * Business rule stays the same either way — a Regular section's
     * class is always the host, never the guest — it just means the
     * row being placed here ends up as host and $target gets
     * re-pointed onto IT, instead of $subject folding into $target.
     *
     * Never writes anything — purely evaluates compatibility so the
     * caller can show a "Combine Sections?" confirmation before
     * anything is saved (spec: offer the merge, don't force it).
     *
     * @param  array{room_id:?int, faculty_id:?int, days:list<string>, start_time:?string, end_time:?string}  $proposedSlot
     * @return array{compatible:bool, blocking_reason:?string, capacity:int, current_headcount:int, projected_headcount:int}
     */
    public function evaluateReversePlacement(SectionSubject $subject, array $proposedSlot, SectionSubject $target): array
    {
        $empty = ['capacity' => 0, 'current_headcount' => 0, 'projected_headcount' => 0];

        $target->loadMissing(['section', 'room', 'faculty']);
        $subjectSection = $subject->section ?? $subject->section()->first();
        $targetSection = $target->section;

        if (! $subjectSection || ! $targetSection) {
            return [...$empty, 'compatible' => false, 'blocking_reason' => 'Section record is missing.'];
        }

        if ((int) $target->section_id === (int) $subject->section_id) {
            return [...$empty, 'compatible' => false, 'blocking_reason' => 'That class already belongs to this section.'];
        }

        if ((int) $target->subject_id !== (int) $subject->subject_id) {
            return [...$empty, 'compatible' => false, 'blocking_reason' => 'Different subjects cannot share a class session.'];
        }

        // Same Major, and same/equivalent Curriculum under it —
        // mirrors findHostCandidates()'s #2/#3 criteria.
        $equivalentCurriculumIds = \App\Models\Curriculum::query()
            ->where('major_id', $subjectSection->major_id)
            ->pluck('id');

        if ((int) $targetSection->major_id !== (int) $subjectSection->major_id
            || ! $equivalentCurriculumIds->contains($targetSection->curriculum_id)) {
            return [...$empty, 'compatible' => false, 'blocking_reason' => 'The existing class belongs to a different program/curriculum.'];
        }

        if ($targetSection->academic_year !== $subjectSection->academic_year || $targetSection->semester !== $subjectSection->semester) {
            return [...$empty, 'compatible' => false, 'blocking_reason' => 'The existing class belongs to a different academic term.'];
        }

        if (! $target->faculty_id || ($target->requiresRoom() && ! $target->room_id) || ! $target->days || ! $target->start_time || ! $target->end_time) {
            return [...$empty, 'compatible' => false, 'blocking_reason' => 'The existing class has no complete schedule to share.'];
        }

        // EXACT SLOT MATCH REQUIRED — sharing only ever applies to an
        // identical Faculty + Room + Day(s) + Start/End Time; anything
        // else (including a partial day/time overlap) is a genuine
        // conflict, never an automatic merge candidate.
        $proposedDays = array_values(array_unique(array_filter($proposedSlot['days'] ?? [])));
        $targetDays = array_values(array_unique(array_filter(explode(',', (string) $target->days))));
        sort($proposedDays);
        sort($targetDays);

        $exactMatch = (int) ($proposedSlot['faculty_id'] ?? 0) === (int) $target->faculty_id
            && (int) ($proposedSlot['room_id'] ?? 0) === (int) $target->room_id
            && $proposedDays === $targetDays
            && ($proposedSlot['start_time'] ?? null) === $target->start_time
            && ($proposedSlot['end_time'] ?? null) === $target->end_time;

        if (! $exactMatch) {
            return [...$empty, 'compatible' => false, 'blocking_reason' => 'This is not an exact Faculty/Room/Day/Time match with the existing class, so it cannot share the same schedule.'];
        }

        // Room capacity — combine everyone who would end up sharing
        // this one class session: the target's own section, every
        // OTHER row already merged into it, and $subject's own
        // section.
        $roomCapacity = $target->room?->capacity ?? 0;
        $alreadySeated = ($targetSection->estimated_students ?? 0)
            + $target->mergedPlacements()
                ->with('section:id,estimated_students')
                ->get()
                ->sum(fn (SectionSubject $m) => $m->section->estimated_students ?? 0);
        $incoming = $subjectSection->estimated_students ?? 0;
        $projected = $alreadySeated + $incoming;

        if ($roomCapacity > 0 && $projected > $roomCapacity) {
            return [
                'compatible' => false,
                'blocking_reason' => "The combined enrollment exceeds Room {$target->room?->room_code}'s capacity ({$roomCapacity}) by ".($projected - $roomCapacity).' students. Please select a larger room or a different schedule.',
                'capacity' => $roomCapacity,
                'current_headcount' => $alreadySeated,
                'projected_headcount' => $projected,
            ];
        }

        // $subject's own Section must not already have a different
        // class in this window (excluding $subject itself).
        $sectionConflict = $this->conflictService->findSectionConflict(
            $subjectSection->id,
            $subject->id,
            $proposedDays,
            (string) $proposedSlot['start_time'],
            (string) $proposedSlot['end_time'],
        );
        if ($sectionConflict) {
            return [
                'compatible' => false,
                'blocking_reason' => 'This section already has another class scheduled during this day/time window.',
                'capacity' => $roomCapacity,
                'current_headcount' => $alreadySeated,
                'projected_headcount' => $projected,
            ];
        }

        return [
            'compatible' => true,
            'blocking_reason' => null,
            'capacity' => $roomCapacity,
            'current_headcount' => $alreadySeated,
            'projected_headcount' => $projected,
        ];
    }

    /**
     * Applies a REVERSE merge — the mirror image of applyMerge().
     * $hostRow must already have been saved (in the SAME transaction,
     * by the normal schedule-write path) with its own real Faculty/
     * Room/Days/Time/Status; this re-points $existingGuestRow — which
     * previously had its OWN independent booking at the exact same
     * slot — onto it, exactly the way applyMerge() re-points an
     * Irregular row onto an existing Regular host, just in the
     * opposite direction. Any row that was ALREADY merged into
     * $existingGuestRow (it was a host of its own before this) is
     * re-parented onto $hostRow too, so a merge chain never ends up
     * two levels deep.
     */
    public function applyReverseMerge(SectionSubject $hostRow, SectionSubject $existingGuestRow, ?array $recommendationMeta = null): SectionSubject
    {
        /** @var SectionSubject $lockedHost */
        $lockedHost = SectionSubject::whereKey($hostRow->id)->lockForUpdate()->firstOrFail();
        /** @var SectionSubject $lockedGuest */
        $lockedGuest = SectionSubject::whereKey($existingGuestRow->id)->lockForUpdate()->firstOrFail();

        SectionSubject::where('merged_into_section_subject_id', $lockedGuest->id)
            ->update(['merged_into_section_subject_id' => $lockedHost->id]);

        $lockedGuest->update([
            'faculty_id' => $lockedHost->faculty_id,
            'room_id' => $lockedHost->room_id,
            'days' => $lockedHost->days,
            'start_time' => $lockedHost->start_time,
            'end_time' => $lockedHost->end_time,
            'status' => $lockedHost->status,
            'is_auto_generated' => true,
            'is_merged' => true,
            'merged_into_section_subject_id' => $lockedHost->id,
            'auto_generated_meta' => null,
            'merge_recommendation' => $recommendationMeta,
        ]);

        return $lockedHost->refresh();
    }

    /**
     * Every OTHER SectionSubject row, belonging to a Regular section,
     * that is a structural candidate to merge this Irregular
     * placement into (criteria #1–#5 from the class docblock — the
     * "does a matching class exist at all" filter). Room capacity,
     * conflict, workload, and calendar checks happen per-candidate in
     * evaluateCandidate() so each one can report its own specific
     * blocking reason.
     *
     * @return Collection<int, SectionSubject>
     */
    private function findHostCandidates(SectionSubject $irregularRow): Collection
    {
        $section = $irregularRow->section;
        $activeSectionIds = $this->conflictService->activeSemesterSectionIds();

        // "Same Curriculum or equivalent curriculum mapping" — any
        // Curriculum under the same Major counts as equivalent.
        $equivalentCurriculumIds = \App\Models\Curriculum::query()
            ->where('major_id', $section->major_id)
            ->pluck('id');

        return SectionSubject::query()
            ->where('subject_id', $irregularRow->subject_id)
            ->where('id', '!=', $irregularRow->id)
            ->whereIn('status', ['Scheduled', 'Draft'])
            ->whereNotNull('faculty_id')
            ->whereNotNull('room_id')
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereIn('section_id', $activeSectionIds)
            ->whereHas('section', function ($query) use ($section, $equivalentCurriculumIds) {
                $query->where('section_type', 'Regular')
                    ->where('major_id', $section->major_id)
                    ->where('academic_year', $section->academic_year)
                    ->where('semester', $section->semester)
                    ->whereIn('curriculum_id', $equivalentCurriculumIds);
            })
            ->with(['faculty', 'room', 'section', 'subject'])
            ->get();
    }

    /**
     * Runs every merge-eligibility check (criteria #6–#9) against one
     * candidate host class and, if it passes all of them, scores it.
     * Always returns a full row for the "Merge Recommendation" modal —
     * compatible or not — so the Administrator can see every
     * candidate considered and exactly why each one was or wasn't
     * viable, not just the winner.
     *
     * @return array{
     *     section_subject_id: int, section_id: int, section_code: string,
     *     section_name: string, faculty_name: ?string, room_name: ?string,
     *     days: string, start_time: string, end_time: string,
     *     capacity: int, current_headcount: int, projected_headcount: int,
     *     workload_delta: int, score: int, compatible: bool,
     *     reasons: list<string>, blocking_reason: ?string,
     * }
     */
    private function evaluateCandidate(SectionSubject $irregularRow, SectionSubject $host): array
    {
        $irregularSection = $irregularRow->section;
        $reasons = [];
        $blockingReason = null;
        $compatible = true;

        // --- #6 Room capacity ------------------------------------------------
        $room = $host->room;
        $roomCapacity = $room?->capacity ?? 0;

        // Effective headcount already resting on this class: the host
        // section's own estimated_students plus every OTHER Irregular
        // section already merged into it (never double-counting the
        // row being evaluated).
        $alreadyMergedStudents = $host->mergedPlacements()
            ->where('id', '!=', $irregularRow->id)
            ->with('section:id,estimated_students')
            ->get()
            ->sum(fn (SectionSubject $merged) => $merged->section->estimated_students ?? 0);

        $currentHeadcount = ($host->section->estimated_students ?? 0) + $alreadyMergedStudents;
        $addingStudents = $irregularSection->estimated_students ?? 0;
        $projectedHeadcount = $currentHeadcount + $addingStudents;

        if ($roomCapacity > 0 && $projectedHeadcount > $roomCapacity) {
            $compatible = false;
            $blockingReason = "Room {$room?->room_name} capacity ({$roomCapacity}) would be exceeded — "
                ."{$currentHeadcount} already seated + {$addingStudents} incoming = {$projectedHeadcount}.";
        } else {
            $reasons[] = "Room capacity holds: {$projectedHeadcount}/{$roomCapacity}.";
        }

        // --- #7 Section conflict for the IRREGULAR section itself ------------
        // (Faculty/Room conflicts are structurally impossible — merging
        // reuses the host's exact existing booking rather than creating
        // a new one — but the Irregular section could already have a
        // DIFFERENT class of its own in this same Day/Time window.)
        $dayTokens = array_values(array_filter(explode(',', (string) $host->days)));
        if ($compatible) {
            $sectionConflict = $this->conflictService->findSectionConflict(
                $irregularSection->id,
                $irregularRow->id,
                $dayTokens,
                (string) $host->start_time,
                (string) $host->end_time,
            );

            if ($sectionConflict) {
                $compatible = false;
                $blockingReason = 'This Irregular section already has another class scheduled '
                    .'during this class\'s day/time window.';
            } else {
                $reasons[] = 'No conflict with this Irregular section\'s own schedule.';
            }
        }

        // --- #8 Faculty workload ---------------------------------------------
        // Merging adds ZERO new teaching load (same single class
        // session) — always compatible on this axis, but still
        // re-verified defensively against the faculty's CURRENT
        // standing in case they're already over cap for unrelated
        // reasons (a pre-existing overload elsewhere shouldn't block a
        // merge that adds nothing to it).
        $workloadDelta = 0;
        if ($host->faculty) {
            $currentLoad = $this->workloadService->currentLoad($host->faculty);
            $maxLoad = $this->workloadService->maxLoad($host->faculty);
            $reasons[] = "No additional load added to {$host->faculty->last_name} — same class session "
                .($maxLoad > 0 ? "(currently {$currentLoad}/{$maxLoad} {$this->workloadService->unitLabel($host->faculty)})." : '.');
        }

        // --- #9 Academic Calendar days re-check -------------------------------
        // Defensive re-verification only — the host class was already
        // validated against calendar rules when it was created.
        $meetingDaysConfigured = ! empty($dayTokens);
        if (! $meetingDaysConfigured) {
            $compatible = false;
            $blockingReason ??= 'The host class has no meeting days recorded.';
        }

        $score = $compatible ? $this->scoreCandidate($roomCapacity, $projectedHeadcount) : 0;

        return [
            'section_subject_id' => $host->id,
            'section_id' => $host->section_id,
            'section_code' => $host->section->section_code ?? '—',
            'section_name' => $host->section->section_name ?? '—',
            'faculty_name' => $host->faculty
                ? trim("{$host->faculty->last_name}, {$host->faculty->first_name}")
                : null,
            'room_name' => $room?->room_name,
            'days' => $host->days,
            'start_time' => $host->start_time,
            'end_time' => $host->end_time,
            'capacity' => $roomCapacity,
            'current_headcount' => $currentHeadcount,
            'projected_headcount' => $projectedHeadcount,
            'workload_delta' => $workloadDelta,
            'score' => $score,
            'compatible' => $compatible,
            'reasons' => $reasons,
            'blocking_reason' => $blockingReason,
        ];
    }

    /**
     * Higher score = better merge target. Rewards efficient fits
     * (filling an existing class close to capacity) over scattering
     * Irregular students across many lightly-filled sections, while
     * still leaving comfortable headroom — peaks around ~85–95%
     * projected utilization and tapers off in both directions.
     */
    private function scoreCandidate(int $roomCapacity, int $projectedHeadcount): int
    {
        if ($roomCapacity <= 0) {
            return 50;
        }

        $utilization = min(1.0, $projectedHeadcount / $roomCapacity);
        $target = 0.90;
        $distance = abs($utilization - $target);

        // 100 at the target utilization, decaying smoothly to a floor
        // of 60 at the extremes (still comfortably "compatible", just
        // not as efficient a use of the room as a tighter fit).
        return (int) round(max(60, 100 - ($distance * 100)));
    }
}