<?php

namespace App\Services;

use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Models\User;
use App\Support\AccessScope;
use App\Exceptions\ScheduleConflictAbort;
use App\Exceptions\ScheduleVersionConflictException;
use Illuminate\Support\Facades\DB;

/**
 * "⚡ Auto Generate Schedule" (Prompt 8.9).
 *
 * This is NOT a blind auto-scheduler — it is a bulk driver on top of
 * RecommendationService/ScheduleConflictService, the exact same engine
 * the per-row "Recommend" drawer already uses. For every currently
 * UNSCHEDULED SectionSubject in a Section it asks the Recommendation
 * Engine for ranked Faculty, ranked Rooms, and a conflict-free Time
 * slot, then walks the top candidates until it finds a combination
 * that is fully conflict-free (Faculty, Room, Section, Duplicate,
 * Load, Capacity) — never inventing an assignment the recommendation
 * engine itself wouldn't have suggested one row at a time.
 *
 * Each accepted assignment is written to the row immediately (flagged
 * is_auto_generated = true, Status stays 'Draft') rather than held in
 * memory. This is deliberate: it lets every later subject in the same
 * run reuse ScheduleConflictService/RecommendationService completely
 * unmodified — an already-generated row is, from the engine's point
 * of view, indistinguishable from a manually-saved one, so "Smart
 * Search" (try the next faculty / next room / next day) falls out of
 * the existing ranking + conflict-skip logic for free instead of a
 * second, parallel implementation of it here.
 *
 * Nothing here ever touches a row that already has a Faculty, Room,
 * Days, Start Time, or End Time — manually assigned rows are always
 * left completely alone unless the Registrar explicitly clears/
 * regenerates.
 */
class AutoScheduleService
{
    /** How many top-ranked Faculty candidates are tried per subject. */
    private const CANDIDATE_FACULTY = 4;

    /** How many top-ranked Room candidates are tried per subject. */
    private const CANDIDATE_ROOMS = 4;

    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly ScheduleConflictService $conflictService,
        private readonly FacultyWorkloadService $workloadService,
        private readonly IrregularSectionMergeService $mergeService,
        private readonly SiblingSectionPatternService $siblingPatternService,
    ) {
    }

    /**
     * Generate schedules for every unscheduled Subject in the Section.
     * Returns a summary the frontend uses to render the review panel.
     *
     * CONCURRENCY HARDENING (spec Section 15) — $expectedVersion is
     * the Section's schedule_version the frontend had loaded right
     * before clicking "Auto Generate". When supplied, it's checked
     * under lock (see ScheduleConflictService::checkSectionVersion())
     * BEFORE any row is touched, so a run started against stale data
     * (another user already changed this Section's schedule) is
     * rejected with a ScheduleVersionConflictException rather than
     * generating on top of it. Every accepted placement below still
     * goes through persistIfStillAvailable()'s own lock + re-validate
     * + write, so this is a belt-and-suspenders check at the start of
     * the run, not a substitute for it.
     *
     * SCOPE ENFORCEMENT (Assistant Dean = GenEd/Minor only) — $user is
     * the acting user. When they're an Assistant Dean, Major subjects
     * are never included in $targets at all (see unscheduledRows()) —
     * not attempted, not written, not counted toward 'total'/
     * 'scheduled'. They're reported separately under
     * 'skipped_out_of_scope' so the review panel can say "left for
     * the Dean" instead of silently doing nothing. Every other role
     * is unaffected — unscheduledRows() returns every unscheduled row
     * for them, exactly as before.
     *
     * @throws ScheduleVersionConflictException
     */
    public function generate(Section $section, ?int $expectedVersion = null, ?User $user = null): array
    {
        $section->loadMissing('major.department');

        // CONCURRENCY HARDENING — TRUE FIRST-COME-FIRST-SERVED.
        //
        // Previously the version check above ran in its OWN short
        // transaction that committed (and released the Section's row
        // lock) immediately after checking — before any subject was
        // actually scheduled. That let two runs started moments apart
        // (e.g. Admin clicks, then Dean clicks) both pass the version
        // check against the same starting version and then race each
        // other subject-by-subject at the database level, so whichever
        // request's PHP process happened to reach a given row's lock
        // first — not whichever user clicked first in the browser —
        // won that subject. From the Registrar's point of view this
        // looked like "the second click won."
        //
        // Holding the Section's row lock for the ENTIRE run (version
        // check through the final version bump, in one transaction)
        // fixes this: the second request's own lockForUpdate() call
        // now BLOCKS until the first run's transaction fully commits,
        // so runs are serialized in true database-arrival order, and
        // the second run's version check (re-read fresh once it
        // finally gets the lock) correctly rejects with
        // ScheduleVersionConflictException if the first run already
        // bumped the version — exactly the "please refresh" outcome
        // the frontend already handles today.
        return DB::transaction(function () use ($section, $expectedVersion, $user) {
            $lockedSection = $this->conflictService->lockResources(null, null, $section->id);
            $this->conflictService->checkSectionVersion($lockedSection, $expectedVersion);

            $targets = $this->unscheduledRows($section, $user);
            $skippedOutOfScope = AccessScope::isAssistantDean($user)
                ? $this->outOfScopeRows($section)
                : collect();

            if ($targets->isEmpty()) {
                return [
                    'total' => 0,
                    'scheduled' => 0,
                    'results' => [],
                    'unresolved' => [],
                    'skipped_out_of_scope' => $this->summarizeSkipped($skippedOutOfScope),
                    'message' => $skippedOutOfScope->isEmpty()
                        ? 'Every subject in this section already has a schedule assigned.'
                        : 'No GenEd/Minor subjects need scheduling. '
                            .$skippedOutOfScope->count().' Major '
                            .($skippedOutOfScope->count() === 1 ? 'subject is' : 'subjects are')
                            .' left for the Dean/OIC to schedule.',
                ];
            }

            $results = [];
            $unresolved = [];

            // Heaviest (highest-unit) subjects first — these are the
            // hardest to fit around everything else, so give them first
            // pick of Faculty/Room/Time before the schedule fills up.
            $targets = $targets->sortByDesc(fn (SectionSubject $row) => $row->subject->units ?? 0)->values();

            foreach ($targets as $sectionSubject) {
                $outcome = $this->generateOne($section, $sectionSubject);

                if ($outcome['success']) {
                    $results[] = $outcome['result'];
                } else {
                    $unresolved[] = $outcome['result'];
                }
            }

            // SPLIT-DELIVERY SCHEDULING — SAME-FACULTY RESYNC (final
            // pass). generateOne()'s own inline sync (above) only
            // catches the case where the Online row is processed AFTER
            // its Face-to-Face sibling already has a Faculty. $targets
            // is sorted by unit count, not split-pairing, so the
            // opposite order happens just as often: the Online row
            // gets processed first (inheriting whatever stale Faculty
            // it already had, or none), and its Face-to-Face sibling
            // is independently assigned a Faculty afterward by
            // searchIndependent()'s Room+Time+Faculty search — which
            // has no reason to reuse the split subject's OLD Faculty
            // if a different one scored better. Nothing in that inline
            // sync runs again once the Face-to-Face half changes, so
            // the pair could silently end up split across two
            // different Faculty members for the rest of this run. This
            // pass runs once, after every row in this batch is
            // resolved, and re-syncs every split pair Online.faculty_id
            // to its Face-to-Face sibling's FINAL value — Face-to-Face
            // is always the authoritative half, since it's the one
            // actually searched for Faculty/Room/Time availability.
            $this->resyncSplitFacultyPairs($section);

            $mergedCount = collect($results)->where('is_merged', true)->count();

            $message = count($results) === $targets->count()
                ? count($results).' of '.$targets->count().' subjects scheduled. No conflicts detected.'
                : count($results).' of '.$targets->count().' subjects scheduled. '
                    .count($unresolved).' '.(count($unresolved) === 1 ? 'subject requires' : 'subjects require').' manual scheduling.';

            if ($mergedCount > 0) {
                $message .= ' '.$mergedCount.' '.($mergedCount === 1 ? 'subject was' : 'subjects were')
                    .' merged into existing Regular section classes.';
            }

            if ($skippedOutOfScope->isNotEmpty()) {
                $message .= ' '.$skippedOutOfScope->count().' Major '
                    .($skippedOutOfScope->count() === 1 ? 'subject was' : 'subjects were')
                    .' left for the Dean/OIC (outside your GenEd/Minor scheduling scope).';
            }

            // Advance the Section's schedule_version exactly once for
            // this run, but only when it actually wrote something — a run
            // that placed nothing (every candidate lost its race, or
            // there was simply nothing left to try) must not bump the
            // version with no corresponding change (spec Section 20).
            // Still under the SAME lock/transaction as the version
            // check above — no second lockResources() call needed, and
            // no window where another run could sneak in between the
            // check and the bump.
            if (count($results) > 0) {
                $this->conflictService->bumpScheduleVersion($lockedSection);
            }

            return [
                'total' => $targets->count(),
                'scheduled' => count($results),
                'merged' => $mergedCount,
                'results' => $results,
                'unresolved' => $unresolved,
                'skipped_out_of_scope' => $this->summarizeSkipped($skippedOutOfScope),
                'message' => $message,
            ];
        });
    }

    /**
     * See the call site in generate() for why this exists. Re-reads
     * every Face-to-Face/Online split pair in $section fresh from the
     * DB — not limited to rows this run just processed, since a
     * Face-to-Face half scheduled in an EARLIER run could just as
     * easily have drifted from its Online sibling as one scheduled in
     * THIS run — and forces the Online half's faculty_id to match its
     * Face-to-Face sibling's whenever both are set but disagree. A
     * pair where either half still has no Faculty is left alone —
     * there's nothing yet to sync to.
     */
    private function resyncSplitFacultyPairs(Section $section): void
    {
        $splitRows = $section->sectionSubjects()
            ->whereIn('delivery_mode', ['face_to_face', 'online'])
            ->get(['id', 'subject_id', 'delivery_mode', 'faculty_id']);

        $f2fFacultyBySubjectId = $splitRows
            ->where('delivery_mode', 'face_to_face')
            ->whereNotNull('faculty_id')
            ->pluck('faculty_id', 'subject_id');

        $splitRows
            ->where('delivery_mode', 'online')
            ->whereNotNull('faculty_id')
            ->each(function (SectionSubject $onlineRow) use ($f2fFacultyBySubjectId) {
                $f2fFacultyId = $f2fFacultyBySubjectId->get($onlineRow->subject_id);

                if ($f2fFacultyId && $onlineRow->faculty_id !== $f2fFacultyId) {
                    $onlineRow->forceFill(['faculty_id' => $f2fFacultyId])->save();
                }
            });
    }

    /**
     * "Clear Generated Schedule" — reverts every row this engine
     * produced (is_auto_generated = true) back to an empty slot.
     * Rows the Registrar assigned or edited by hand are never touched
     * (is_auto_generated is cleared the moment a row is hand-edited
     * via the normal Save Schedule flow — see SectionSubjectController).
     */
    public function clear(Section $section, ?User $user = null): int
    {
        return DB::transaction(function () use ($section, $user) {
            $lockedSection = $this->conflictService->lockResources(null, null, $section->id);

            $query = $section->sectionSubjects()->where('is_auto_generated', true);

            // Assistant Dean's "Regenerate" must never wipe out a
            // Major subject's auto-generated schedule — that row is
            // outside their scope to touch even to clear it, same as
            // it's outside their scope to write in the first place.
            if (AccessScope::isAssistantDean($user)) {
                $query->whereHas('subject', fn ($q) => $q->whereIn('category', ['General Education', 'Minor']));
            }

            $cleared = $query->update([
                    'faculty_id' => null,
                    'room_id' => null,
                    'days' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'status' => 'Draft',
                    'is_auto_generated' => false,
                    'auto_generated_meta' => null,
                    'is_merged' => false,
                    'merged_into_section_subject_id' => null,
                    'merge_recommendation' => null,
                ]);

            if ($cleared > 0) {
                $this->conflictService->bumpScheduleVersion($lockedSection);
            }

            return $cleared;
        });
    }

    /**
     * "Regenerate" — clears every previously auto-generated row (but
     * never manually-assigned ones), then runs generate() again from
     * a clean slate.
     */
    public function regenerate(Section $section, ?User $user = null): array
    {
        $this->clear($section, $user);

        return $this->generate($section, null, $user);
    }

    /**
     * Only subjects with a completely empty schedule slot are ever
     * touched — this is the "don't overwrite manual assignments"
     * rule enforced at the query level, not just by convention.
     *
     * SCOPE ENFORCEMENT — when $user is an Assistant Dean, Major
     * subjects are excluded here entirely (reuses Subject's own
     * 'General Education'/'Minor' category list — see
     * AccessScope::isSharedCategory() / Subject::scopeManageableBy())
     * so they're never handed to generateOne() at all. Every other
     * role's query is unchanged.
     */
    private function unscheduledRows(Section $section, ?User $user = null)
    {
        $query = $section->sectionSubjects()
            ->whereNull('faculty_id')
            ->whereNull('room_id')
            ->whereNull('days')
            ->whereNull('start_time')
            ->whereNull('end_time')
            ->with('subject');

        if (AccessScope::isAssistantDean($user)) {
            $query->whereHas('subject', fn ($q) => $q->whereIn('category', ['General Education', 'Minor']));
        }

        return $query->get()
            ->filter(fn (SectionSubject $row) => $row->subject !== null)
            ->values();
    }

    /**
     * The Major-subject unscheduled rows an Assistant Dean's run
     * deliberately left alone — used only to build the
     * 'skipped_out_of_scope' summary/message, never scheduled.
     */
    private function outOfScopeRows(Section $section)
    {
        return $section->sectionSubjects()
            ->whereNull('faculty_id')
            ->whereNull('room_id')
            ->whereNull('days')
            ->whereNull('start_time')
            ->whereNull('end_time')
            ->with('subject')
            ->whereHas('subject', fn ($q) => $q->where('category', 'Major'))
            ->get()
            ->filter(fn (SectionSubject $row) => $row->subject !== null)
            ->values();
    }

    /**
     * Small frontend-friendly summary of skipped Major subjects —
     * count plus subject codes, so the review panel can list them
     * ("BSIT 301, BSIT 305 left for the Dean") instead of just a number.
     *
     * @param  \Illuminate\Support\Collection<int, SectionSubject>  $skipped
     */
    private function summarizeSkipped($skipped): array
    {
        return [
            'count' => $skipped->count(),
            'subject_codes' => $skipped->pluck('subject.subject_code')->filter()->values()->all(),
        ];
    }

    /**
     * Find and persist the best conflict-free Faculty + Room + Time
     * combination for one Subject, trying the top-ranked candidates
     * from RecommendationService in order (STEP 1–4 of the spec) and
     * falling through to the next one whenever a combination turns
     * out to have a conflict — this is the "Smart Search" behavior.
     */
    private function generateOne(Section $section, SectionSubject $sectionSubject): array
    {
        // PRACTICUM / OJT — an explicitly non-room-based delivery
        // type (Subject -> Faculty Supervisor -> Off-Campus ->
        // Required Hours). Never enters the Faculty+Room+Time search
        // or the Irregular-section merge evaluation below — both of
        // those exist to fit a class into a physical Room, which a
        // Practicum/OJT placement never occupies.
        if ($sectionSubject->subject?->isPracticum()) {
            return $this->resolvePracticum($section, $sectionSubject);
        }

        // SPLIT-DELIVERY SCHEDULING — an 'online' row is exempt from
        // Room conflict detection (see SectionSubject::requiresRoom()),
        // but the Faculty+Time search below (searchIndependent() ->
        // RecommendationService::recommendTimes()) currently requires
        // a room_id to check Time availability against — Room and
        // Time are resolved together there, not separately. Rather
        // than risk assigning this row a Room it should never have
        // (or silently reusing whatever Room its Face-to-Face sibling
        // got), leave it for the Registrar to assign Faculty/Days/
        // Time manually until recommendTimes() supports a
        // Room-independent search. This never blocks the rest of the
        // batch — every other subject in the Section still runs
        // through Auto Generate normally.
        if ($sectionSubject->delivery_mode === 'online') {
            // SAME-FACULTY RULE — an Online split row is never meant
            // to have its own independent Faculty; it always follows
            // whatever Faculty its Face-to-Face sibling already has
            // (see splitSchedule()'s docblock). If that sibling is
            // already assigned, inherit it here instead of leaving
            // the row stuck on "Select faculty" — the Registrar only
            // ever needs to pick Faculty once per Subject, on the
            // Face-to-Face half.
            $siblingFacultyId = SectionSubject::query()
                ->where('section_id', $section->id)
                ->where('subject_id', $sectionSubject->subject_id)
                ->where('delivery_mode', 'face_to_face')
                ->whereNotNull('faculty_id')
                ->value('faculty_id');

            if ($siblingFacultyId && $sectionSubject->faculty_id !== $siblingFacultyId) {
                $sectionSubject->forceFill(['faculty_id' => $siblingFacultyId])->save();
            }

            return $this->unresolved(
                $sectionSubject,
                $siblingFacultyId
                    ? 'Faculty inherited from this subject\'s Face-to-Face schedule. Auto Generate currently only assigns Days/Time automatically for Face-to-Face schedules — assign Days/Time for this Online row manually.'
                    : 'This is the Online half of a split subject — Auto Generate currently only assigns Faculty/Days/Time automatically for Face-to-Face schedules. Assign this row manually (its Faculty will match the Face-to-Face half once that one is assigned).',
            );
        }

        // INTELLIGENT IRREGULAR SECTION SCHEDULING — for an Irregular
        // section, evaluate a merge into a compatible Regular
        // section's existing class BEFORE ever searching for an
        // independent Faculty+Room+Time combination. The merge
        // recommendation (and every candidate considered) is always
        // recorded on the row — including when the outcome is
        // "independent" — so the review panel and the "Merge
        // Recommendation" modal have something to show regardless of
        // which path this subject ended up taking.
        if ($section->isIrregular()) {
            $mergeOutcome = $this->mergeService->recommend($sectionSubject);

            if ($mergeOutcome['recommendation'] === 'merge' && $mergeOutcome['best_match']) {
                $host = SectionSubject::find($mergeOutcome['best_match']['section_subject_id']);

                if ($host) {
                    $this->mergeService->applyMerge($sectionSubject, $host, $mergeOutcome);

                    return $this->mergedResult($sectionSubject, $mergeOutcome);
                }
            }

            // No viable merge — fall through to the normal independent
            // search below, but keep the recommendation (with its
            // 'independent_reason') attached to the row so the
            // Administrator can see why merging wasn't possible.
            $sectionSubject->update(['merge_recommendation' => $mergeOutcome]);
        }

        return $this->searchIndependent($section, $sectionSubject);
    }

    /**
     * Public entry point for "Create Independent Schedule Instead" —
     * the Administrator has already declined every merge candidate
     * offered by the Merge Recommendation modal, so this skips the
     * merge evaluation entirely and goes straight to the same
     * Faculty/Room/Time search generateOne() would otherwise fall
     * through to. Safe to call on any SectionSubject, Irregular
     * section or not.
     */
    public function scheduleIndependently(Section $section, SectionSubject $sectionSubject): array
    {
        return $this->searchIndependent($section, $sectionSubject);
    }

    /**
     * The actual independent Faculty+Room+Time search — Sibling
     * Section Pattern Matching first, then the general
     * RecommendationService-ranked "Smart Search". Shared by
     * generateOne() (after its merge-evaluation gate) and the public
     * scheduleIndependently() entry point above, so both paths stay
     * in exact agreement about what "independent" means.
     */
    private function searchIndependent(Section $section, SectionSubject $sectionSubject): array
    {
        $subject = $sectionSubject->subject;

        // SIBLING SECTION PATTERN MATCHING — before ever consulting
        // the general Faculty/Room/Time ranking, check whether a
        // sibling Section of the same cohort (same Major, Curriculum,
        // Academic Year, Semester, Year Level — e.g. 4A/4B/4C/4D)
        // already has this exact Subject fully scheduled. If so,
        // reuse that Faculty, Room, and — critically — the sibling's
        // ACTUAL saved duration (which may have been manually
        // trimmed shorter than the Subject's declared hours), only
        // searching for a different Day. This keeps a cohort's
        // sections consistently taught by the same Faculty in the
        // same Room whenever possible, which is what the Registrar
        // wants far more often than whatever the general engine would
        // otherwise rank highest. Falls straight through to the
        // normal search below the moment no usable donor pattern
        // exists.
        $siblingPattern = $this->siblingPatternService->findPattern($sectionSubject);
        $siblingDiagnostics = $this->siblingPatternService->getDiagnostics();

        if ($siblingPattern) {
            $errors = $this->conflictService->validate([
                'section_id' => $section->id,
                'faculty_id' => $siblingPattern['faculty_id'],
                'room_id' => $siblingPattern['room_id'],
                'days' => $siblingPattern['days'],
                'start_time' => $siblingPattern['start_time'],
                'end_time' => $siblingPattern['end_time'],
            ], $sectionSubject->id);

            if (empty($errors)) {
                $faculty = \App\Models\Faculty::find($siblingPattern['faculty_id']);

                if ($faculty && ! $this->workloadService->wouldExceed($faculty, $subject, $sectionSubject->id)) {
                    return $this->applySiblingPattern($sectionSubject, $siblingPattern);
                }
            }
        }

        // RESPECT AN EXISTING MANUAL ASSIGNMENT — if the Registrar
        // already picked a Faculty AND Room on this row before ever
        // clicking Auto Generate (e.g. via the Subjects tab
        // dropdowns), try that exact pairing FIRST, before consulting
        // the general ranked search below at all. Previously this
        // method ignored whatever was already on the row completely
        // and started over from its own top-ranked Faculty/Room
        // candidates (recommendFaculty()/recommendRooms(), each
        // capped to CANDIDATE_FACULTY/CANDIDATE_ROOMS) — so a
        // perfectly valid, conflict-free manual pick could go
        // completely untried and the row would still be reported
        // "unresolved" simply because it never ranked high enough to
        // make that shortlist. This is a PREFERENCE, not a hard
        // requirement — it falls straight through to the normal
        // ranked search the moment either piece is missing/inactive
        // or that exact pairing has no open Day/Time left.
        if ($sectionSubject->faculty_id && $sectionSubject->room_id) {
            $manualResult = $this->tryManualAssignment($section, $sectionSubject, $subject);

            if ($manualResult) {
                return $manualResult;
            }
        }

        // TEACHING QUALIFICATION preferred, College/GenEd match
        // accepted as a fallback (spec Sections 1-4, 26, updated).
        // recommendFaculty() tries an explicit Teaching-Qualification
        // match first; if this Subject's own College (or the GenEd
        // pool) has no faculty with a Teaching Qualification on file
        // yet, it falls through to any Active faculty in that same
        // College/GenEd pool — the same College-scoped fallback the
        // manual Faculty dropdown already offers, so Auto Generate
        // Schedule no longer stalls on subjects the Registrar simply
        // hasn't gotten around to recording a Teaching Qualification
        // for. requireQualified is left false (the default) so this
        // fallback level is reachable here too.
        $facultyRec = $this->recommendationService->recommendFaculty($subject, $section, $sectionSubject);
        $facultyCandidates = $facultyRec['recommendations'];

        if (empty($facultyCandidates)) {
            return $this->unresolved($sectionSubject, $facultyRec['message'] ?? 'No Active faculty exists in this Subject\'s own College (or General Education pool), and none exists in any other College either.', [], $siblingDiagnostics);
        }

        $roomRec = $this->recommendationService->recommendRooms($subject, $section, $sectionSubject);
        $roomCandidates = $roomRec['recommendations'];

        // ROOM TYPE COMPATIBILITY — HARD REQUIREMENT (spec Section 11).
        // recommendRooms() lets an explicit Room recommendation bypass
        // the Lecture/Laboratory Type filter, which is correct for the
        // human-facing selector (a Registrar can deliberately override
        // it) but not for an unattended automatic pick — a recommended
        // Room must still be the right Type before Auto Schedule will
        // ever use it. Type-overridden candidates are dropped here
        // rather than in RecommendationService so the selector's
        // "Administrator Override" option is untouched.
        $roomCandidates = array_values(array_filter($roomCandidates, function (array $room) use ($subject) {
            $preferredType = ((int) $subject->laboratory_hours > 0) ? 'Laboratory' : 'Lecture';

            return ($room['room_type'] ?? null) === $preferredType;
        }));

        if (empty($roomCandidates)) {
            $reasons = $roomRec['reasons'] ?? [];
            $detail = $reasons ? implode(' ', $reasons) : 'No available room of the correct type was found for this subject.';

            return $this->unresolved($sectionSubject, $detail, $reasons, $siblingDiagnostics);
        }

        // DIAGNOSTIC TRAIL — every Faculty×Room pairing this loop
        // actually got to (i.e. passed the Teaching Load and Capacity
        // hard caps) but found no open conflict-free Day/Time for, so
        // the final "unresolved" message below can say WHICH
        // combinations were tried and WHY each one failed, instead of
        // a single generic line that looks identical whether the
        // cause is "everyone is fully booked" or "something is
        // misconfigured". $loadSkipped / $capacitySkipped are kept
        // separate since those never even reached a Time search.
        $attempted = [];
        $loadSkipped = [];
        $capacitySkipped = [];

        foreach (array_slice($facultyCandidates, 0, self::CANDIDATE_FACULTY) as $facultyCandidate) {
            // Teaching Load hard cap — never assign a subject that
            // would push this faculty member past their declared
            // max_teaching_units, even if they're otherwise the top
            // ranked candidate. RecommendationService already scores
            // load, but scoring alone doesn't stop an overload; this
            // is the hard block the spec asks for.
            if (! $this->withinTeachingLoad($facultyCandidate, $subject, $sectionSubject)) {
                $loadSkipped[] = $facultyCandidate['name'] ?? "Faculty #{$facultyCandidate['id']}";

                continue;
            }

            foreach (array_slice($roomCandidates, 0, self::CANDIDATE_ROOMS) as $roomCandidate) {
                // Room Capacity hard cap.
                $capacityNeeded = $sectionSubject->capacity ?? $section->estimated_students ?? 0;
                if ($capacityNeeded && $roomCandidate['capacity'] < $capacityNeeded) {
                    $capacitySkipped[] = ($roomCandidate['name'] ?? "Room #{$roomCandidate['id']}").
                        " (seats {$roomCandidate['capacity']}, needs {$capacityNeeded})";

                    continue;
                }

                $timeRec = $this->recommendationService->recommendTimes(
                    $subject, $section, $facultyCandidate['id'], $roomCandidate['id'], $sectionSubject
                );

                $bestTime = $timeRec['recommendations'][0] ?? null;

                if (! $bestTime) {
                    // No open Day/Time for this exact Faculty+Room
                    // pairing — Smart Search moves on to the next
                    // Room, then the next Faculty, automatically via
                    // these loops.
                    $attempted[] = ($facultyCandidate['name'] ?? "Faculty #{$facultyCandidate['id']}").' × '.
                        ($roomCandidate['name'] ?? "Room #{$roomCandidate['id']}");

                    continue;
                }

                // Final authoritative safety net — re-validate the
                // exact combination through the same
                // ScheduleConflictService the manual Save Schedule
                // button uses, so nothing generated here could ever
                // be rejected when the Registrar clicks Save.
                $errors = $this->conflictService->validate([
                    'section_id' => $section->id,
                    'faculty_id' => $facultyCandidate['id'],
                    'room_id' => $roomCandidate['id'],
                    'days' => $bestTime['days'],
                    'start_time' => $bestTime['start_time'],
                    'end_time' => $bestTime['end_time'],
                ], $sectionSubject->id);

                if (! empty($errors)) {
                    $attempted[] = ($facultyCandidate['name'] ?? "Faculty #{$facultyCandidate['id']}").' × '.
                        ($roomCandidate['name'] ?? "Room #{$roomCandidate['id']}").' ('.reset($errors).')';

                    continue;
                }

                return $this->apply($sectionSubject, $facultyCandidate, $roomCandidate, $bestTime);
            }
        }

        // Build a message that actually says WHY, not just THAT it
        // failed — "no combination found" reads identically whether
        // the real cause is "only one qualified Faculty and they're
        // fully booked" or "every scoped Room is already full" or a
        // genuine misconfiguration, and the Registrar has no way to
        // tell those apart from the old generic line alone.
        $detailParts = [];

        if ($loadSkipped) {
            $names = array_unique($loadSkipped);
            $detailParts[] = 'Over Teaching Load cap: '.implode(', ', $names).'.';

            // ACTIONABLE NEXT STEP — this is the one failure reason
            // with an obvious fix, so spell it out rather than making
            // the Registrar guess: either raise that Faculty's Max
            // Teaching Units (Faculty Master), get a second Faculty
            // qualified for this Subject so there's a fallback, or
            // just assign this row manually — the manual Faculty/Room
            // dropdowns are never blocked by the Teaching Load cap the
            // way Auto Generate's automatic pick is.
            $detailParts[] = 'Consider raising '.implode(' / ', $names).
                "'s Max Teaching Units on the Faculty Master, qualifying another Faculty for this Subject, or assigning this row's Faculty/Room manually instead.";
        }

        if ($capacitySkipped) {
            $detailParts[] = 'Room too small: '.implode(', ', array_unique($capacitySkipped)).'.';
        }

        if ($attempted) {
            $shown = array_slice(array_unique($attempted), 0, 6);
            $detailParts[] = 'No open Day/Time for: '.implode('; ', $shown).
                (count($attempted) > count($shown) ? '; and others.' : '.');
        }

        $message = $detailParts
            ? 'No conflict-free day/time combination could be found among the qualified faculty and available rooms. '.implode(' ', $detailParts)
            : 'No conflict-free day/time combination could be found among the qualified faculty and available rooms.';

        return $this->unresolved(
            $sectionSubject,
            $message,
            [],
            $siblingDiagnostics ?? []
        );
    }

    /**
     * FACULTY WORKLOAD VALIDATION — STEP 3 ("Remove faculty exceeding
     * workload"). Faculty's current committed load (Scheduled + Draft,
     * active semester only) plus this subject's load must not exceed
     * their Maximum Teaching Load. Delegates to FacultyWorkloadService
     * — the same hard-cap math Manual Assignment and Save Schedule
     * enforce — so Auto Generate can never produce a placement that
     * "Save Schedule" would turn around and reject.
     *
     * Auto Generate never offers an override: an Administrator can
     * only override the cap through Manual Assignment / Save Schedule,
     * never through the bulk generator.
     */
    private function withinTeachingLoad(array $facultyCandidate, Subject $subject, SectionSubject $sectionSubject): bool
    {
        $faculty = \App\Models\Faculty::find($facultyCandidate['id']);

        if (! $faculty) {
            return false;
        }

        // SPLIT-DELIVERY SAFE EXCLUSION — mirrors
        // SectionSubjectController::workloadWarningFor(). A
        // Face-to-Face/Online split pair shares one section_id +
        // subject_id but is stored as two SectionSubject rows. If we
        // only excluded $sectionSubject->id, a sibling row that
        // already carries this Faculty (e.g. the Online half,
        // inherited once the Face-to-Face half is saved) would still
        // be counted in "current load", and then the full Subject
        // units would be added again as "additional" — double
        // counting a single 3-unit Subject as 6 and tripping the cap
        // even when the true combined load is well within it.
        $excludingIds = SectionSubject::query()
            ->where('section_id', $sectionSubject->section_id)
            ->where('subject_id', $sectionSubject->subject_id)
            ->pluck('id')
            ->push($sectionSubject->id)
            ->unique()
            ->all();

        return ! $this->workloadService->wouldExceed($faculty, $subject, $excludingIds);
    }

    /**
     * See searchIndependent()'s "RESPECT AN EXISTING MANUAL
     * ASSIGNMENT" comment — tries the row's OWN already-assigned
     * Faculty + Room first, via the exact same scoring
     * (scoreArbitraryFaculty()/scoreArbitraryRoom()) and time search
     * (recommendTimes()) the general search uses, so a manual pick
     * that succeeds here is indistinguishable from one the ranked
     * search would have found itself. Returns null (never throws) to
     * fall through to the normal search on any failure — inactive
     * Faculty/Room, Teaching Load cap, Room capacity, or simply no
     * conflict-free Day/Time left for that specific pairing.
     */
    private function tryManualAssignment(Section $section, SectionSubject $sectionSubject, Subject $subject): ?array
    {
        $faculty = \App\Models\Faculty::query()->where('status', 'Active')->find($sectionSubject->faculty_id);
        $room = \App\Models\Room::query()->where('status', 'Active')->find($sectionSubject->room_id);

        if (! $faculty || ! $room) {
            return null;
        }

        $facultyCandidate = $this->recommendationService->scoreArbitraryFaculty($faculty, $subject, $section, $sectionSubject);

        if (! $this->withinTeachingLoad($facultyCandidate, $subject, $sectionSubject)) {
            return null;
        }

        $capacityNeeded = $sectionSubject->capacity ?? $section->estimated_students ?? 0;
        if ($capacityNeeded && $room->capacity < $capacityNeeded) {
            return null;
        }

        // Same hard Room-Type requirement the general search enforces
        // just below (Prompt/spec Section 11) — an unattended
        // automatic pick, manual or ranked, never lands a Lecture
        // subject in a Laboratory room or vice versa.
        $preferredType = ((int) $subject->laboratory_hours > 0) ? 'Laboratory' : 'Lecture';
        if ($room->room_type !== $preferredType) {
            return null;
        }

        $roomCandidate = $this->recommendationService->scoreArbitraryRoom($room, $subject, $section, $sectionSubject);

        $timeRec = $this->recommendationService->recommendTimes(
            $subject, $section, $faculty->id, $room->id, $sectionSubject
        );

        $bestTime = $timeRec['recommendations'][0] ?? null;

        if (! $bestTime) {
            return null;
        }

        $errors = $this->conflictService->validate([
            'section_id' => $section->id,
            'faculty_id' => $faculty->id,
            'room_id' => $room->id,
            'days' => $bestTime['days'],
            'start_time' => $bestTime['start_time'],
            'end_time' => $bestTime['end_time'],
        ], $sectionSubject->id);

        if (! empty($errors)) {
            return null;
        }

        return $this->apply($sectionSubject, $facultyCandidate, $roomCandidate, $bestTime);
    }

    /**
     * CONCURRENCY GUARD for Auto Generate — every write this service
     * makes (independent placement, sibling-pattern placement) goes
     * through here rather than a bare $sectionSubject->update(...).
     * Auto Generate builds its candidate (Faculty/Room/Time) from data
     * read BEFORE this point — another Admin's manual drag/drop, or a
     * second concurrent Auto Generate run for a different Section, can
     * commit to the exact same Room/Faculty/Time in between. Locking
     * (see ScheduleConflictService::lockResources()) and RE-VALIDATING
     * against the latest committed state, both inside the same
     * transaction as the write, is what makes this safe rather than
     * just fast — never trust the availability Auto Generate's own
     * candidate search saw a few milliseconds ago (see the concurrency
     * hardening spec, Scenarios 4 & 5).
     *
     * Returns false (caller falls through to the next candidate, or —
     * if it was the last one — reports this subject as unresolved/
     * needing manual scheduling) instead of throwing, so ONE subject
     * losing a race never aborts the rest of the batch other subjects
     * in the same Auto Generate run are still being placed for.
     */
    private function persistIfStillAvailable(SectionSubject $sectionSubject, array $slot, callable $write): bool
    {
        try {
            DB::transaction(function () use ($sectionSubject, $slot, $write) {
                $this->conflictService->lockResources(
                    $slot['room_id'] ?? null,
                    $slot['faculty_id'] ?? null,
                    $sectionSubject->section_id
                );

                $errors = $this->conflictService->validate([
                    'section_id' => $sectionSubject->section_id,
                    'faculty_id' => $slot['faculty_id'] ?? null,
                    'room_id' => $slot['room_id'] ?? null,
                    'days' => $slot['days'] ?? [],
                    'start_time' => $slot['start_time'] ?? null,
                    'end_time' => $slot['end_time'] ?? null,
                ], $this->conflictService->mergeExclusionIds($sectionSubject));

                if (! empty($errors)) {
                    throw new ScheduleConflictAbort($errors);
                }

                $write();

                // NOTE: the version bump for a full Auto Generate run
                // happens ONCE at the end of generate() (see its
                // docblock) rather than per placement here — an Auto
                // Generate run that places 12 subjects should read as
                // one logical write for optimistic-concurrency
                // purposes, matching how "Save Schedule" treats an
                // entire batch as one write. The lock+re-validate
                // above is still per-placement, exactly as before.
            });

            return true;
        } catch (ScheduleConflictAbort) {
            return false;
        }
    }

    /**
     * Persist the winning combination and build the Recommendation
     * Score summary (Faculty %, Room %, Time %, reasons) the review
     * panel displays.
     */
    private function apply(SectionSubject $sectionSubject, array $faculty, array $room, array $time): array
    {
        $meta = [
            'faculty' => [
                'id' => $faculty['id'],
                'name' => $faculty['name'],
                'score' => $faculty['score'],
                'confidence' => $faculty['confidence'],
                'reasons' => $faculty['reasons'],
                'tier' => $faculty['tier'] ?? null,
                'selected_by_college_match' => $faculty['selected_by_college_match'] ?? false,
            ],
            'room' => [
                'id' => $room['id'],
                'name' => $room['name'],
                'score' => $room['score'],
                'confidence' => $room['confidence'],
                'reasons' => $room['reasons'],
                'utilization_percent' => $room['utilization_percent'] ?? null,
                'scheduled_hours' => $room['scheduled_hours'] ?? null,
                'max_hours' => $room['max_hours'] ?? null,
                'status_color' => $room['status_color'] ?? null,
                'explanation' => $room['explanation'] ?? null,
                'badge' => $room['badge'] ?? null,
                'match_tier' => $room['match_tier'] ?? null,
                'is_recommended' => $room['is_recommended'] ?? false,
                'recommendation_level' => $room['recommendation_level'] ?? null,
                'is_manual_override' => $room['is_manual_override'] ?? false,
            ],
            'time' => [
                'days' => $time['days'],
                'start_time' => $time['start_time'],
                'end_time' => $time['end_time'],
                'score' => $time['score'],
                'confidence' => $time['confidence'],
                'reasons' => $time['reasons'],
            ],
            'overall_score' => (int) round(($faculty['score'] + $room['score'] + $time['score']) / 3),
        ];

        $slot = [
            'room_id' => $room['id'],
            'faculty_id' => $faculty['id'],
            'days' => $time['days'],
            'start_time' => $time['start_time'],
            'end_time' => $time['end_time'],
        ];

        $persisted = $this->persistIfStillAvailable($sectionSubject, $slot, function () use ($sectionSubject, $faculty, $room, $time, $meta) {
            $sectionSubject->update([
                'faculty_id' => $faculty['id'],
                'room_id' => $room['id'],
                'days' => implode(',', $time['days']),
                'start_time' => $time['start_time'],
                'end_time' => $time['end_time'],
                'status' => 'Draft',
                'is_auto_generated' => true,
                'auto_generated_meta' => $meta,
            ]);
        });

        if (! $persisted) {
            // Lost the race to another concurrent save (manual drag or
            // a different Auto Generate run) between candidate search
            // and commit — leave this ONE subject unscheduled rather
            // than silently overwriting whoever won, or aborting the
            // rest of the batch. The Registrar can re-run Auto
            // Generate / Regenerate for just this subject afterward.
            return $this->unresolved(
                $sectionSubject,
                "The best available slot ({$room['name']}, {$time['days'][0]} {$time['start_time']}\u{2013}{$time['end_time']}) "
                    .'was taken by another schedule change at the same moment. Try Auto Generate again for this subject.'
            );
        }

        return [
            'success' => true,
            'result' => array_merge($meta, [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'is_merged' => false,
                'merge_recommendation' => $sectionSubject->merge_recommendation,
            ]),
        ];
    }

    /**
     * Persist a NEW, independent ScheduleAssignment for THIS
     * SectionSubject's own Section — never the donor's row, never the
     * donor's row id — using a sibling Section's already-scheduled
     * Faculty/Room/duration purely as a PREFERENCE/REFERENCE (see
     * SiblingSectionPatternService). Builds the same review-panel
     * shape apply() produces so the frontend needs no special-casing
     * — it just additionally sees `pattern_source` identifying which
     * sibling Section this preference was based on. The donor's own
     * SectionSubject row is never read again after this point and is
     * never written to.
     */
    private function applySiblingPattern(SectionSubject $sectionSubject, array $pattern): array
    {
        $faculty = \App\Models\Faculty::find($pattern['faculty_id']);
        $room = \App\Models\Room::find($pattern['room_id']);

        $meta = [
            'faculty' => [
                'id' => $faculty->id,
                'name' => $faculty->full_name ?? $faculty->name ?? '',
                'score' => 100,
                'confidence' => 'High',
                'reasons' => ["Based on {$pattern['donor_section_code']}, which already teaches this subject with this faculty member."],
            ],
            'room' => [
                'id' => $room->id,
                'name' => $room->room_name ?? $room->name ?? '',
                'score' => 100,
                'confidence' => 'High',
                'reasons' => ["Based on {$pattern['donor_section_code']}'s existing schedule for this subject."],
            ],
            'time' => [
                'days' => $pattern['days'],
                'start_time' => $pattern['start_time'],
                'end_time' => $pattern['end_time'],
                'score' => 100,
                'confidence' => 'High',
                'reasons' => ["Same duration as {$pattern['donor_section_code']}'s schedule for this subject, on a different day to avoid conflicts — a new, independent assignment for this section."],
            ],
            'overall_score' => 100,
            'pattern_source' => [
                'donor_section_id' => $pattern['donor_section_id'],
                'donor_section_code' => $pattern['donor_section_code'],
            ],
        ];

        $slot = [
            'room_id' => $pattern['room_id'],
            'faculty_id' => $pattern['faculty_id'],
            'days' => $pattern['days'],
            'start_time' => $pattern['start_time'],
            'end_time' => $pattern['end_time'],
        ];

        $persisted = $this->persistIfStillAvailable($sectionSubject, $slot, function () use ($sectionSubject, $pattern, $meta) {
            $sectionSubject->update([
                'faculty_id' => $pattern['faculty_id'],
                'room_id' => $pattern['room_id'],
                'days' => implode(',', $pattern['days']),
                'start_time' => $pattern['start_time'],
                'end_time' => $pattern['end_time'],
                'status' => 'Draft',
                'is_auto_generated' => true,
                'auto_generated_meta' => $meta,
            ]);
        });

        if (! $persisted) {
            // Same race-loss handling as apply() — fall through to the
            // general search below instead, rather than leaving this
            // subject unresolved just because its preferred sibling
            // slot got taken in between.
            return $this->searchIndependent($sectionSubject->section()->firstOrFail(), $sectionSubject);
        }

        return [
            'success' => true,
            'result' => array_merge($meta, [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'is_merged' => false,
                'merge_recommendation' => $sectionSubject->merge_recommendation,
            ]),
        ];
    }

    /**
     * Resolve a Practicum/OJT SectionSubject row. Faculty Supervisor
     * is optional (spec Section 4: "Faculty/adviser assignment should
     * still be supported"), so this simply picks the first Active,
     * qualified faculty member who wouldn't exceed their teaching
     * load if one exists — it never blocks on failing to find one.
     * No Room, Days, Start Time, or End Time are ever set: the row is
     * immediately marked Draft/scheduled with Days/Time left empty
     * and displayed by the frontend as "Off-Campus / OJT" instead of
     * a classroom (spec Section 4/6).
     */
    private function resolvePracticum(Section $section, SectionSubject $sectionSubject): array
    {
        $subject = $sectionSubject->subject;

        $facultyId = null;
        $facultyName = null;

        foreach ($subject->faculty()->where('status', 'Active')->get() as $candidate) {
            if (! $this->workloadService->wouldExceed($candidate, $subject, $sectionSubject->id)) {
                $facultyId = $candidate->id;
                $facultyName = $candidate->full_name ?? $candidate->name ?? null;
                break;
            }
        }

        $meta = [
            'delivery_type' => 'practicum',
            'faculty' => [
                'id' => $facultyId,
                'name' => $facultyName,
                'score' => $facultyId ? 100 : 0,
                'confidence' => $facultyId ? 'High' : 'None',
                'reasons' => $facultyId
                    ? ["Qualified, Active faculty supervisor assigned for {$subject->subject_code}."]
                    : ['No Active qualified faculty supervisor available yet — assign one manually.'],
            ],
            'room' => null,
            'time' => null,
            'deployment_type' => $subject->deployment_type,
            'required_hours' => $subject->required_hours,
            'overall_score' => $facultyId ? 100 : 60,
        ];

        $sectionSubject->update([
            'faculty_id' => $facultyId,
            'room_id' => null,
            'days' => null,
            'start_time' => null,
            'end_time' => null,
            'status' => 'Draft',
            'is_auto_generated' => true,
            'auto_generated_meta' => $meta,
        ]);

        return [
            'success' => true,
            'result' => array_merge($meta, [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $subject->subject_code,
                'subject_title' => $subject->subject_title,
                'is_merged' => false,
                'is_practicum' => true,
                'merge_recommendation' => null,
            ]),
        ];
    }

    /**
     * Builds the review-panel result row for a subject that was
     * MERGED into an existing Regular section's class rather than
     * scheduled independently — "Merge into BSIT-4A" per the spec.
     */
    private function mergedResult(SectionSubject $sectionSubject, array $mergeOutcome): array
    {
        $host = $mergeOutcome['best_match'];

        return [
            'success' => true,
            'result' => [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'is_merged' => true,
                'merged_into_section_code' => $host['section_code'],
                'merge_recommendation' => $mergeOutcome,
                'overall_score' => $host['score'],
                'faculty' => ['name' => $host['faculty_name']],
                'room' => ['name' => $host['room_name']],
                'time' => [
                    'days' => explode(',', (string) $host['days']),
                    'start_time' => $host['start_time'],
                    'end_time' => $host['end_time'],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $reasonDetails  Itemized bullet reasons (e.g. from
     *                                       RecommendationService::recommendRooms()'s
     *                                       'reasons' key) for the frontend's
     *                                       "⚠ No suitable room available" panel.
     *                                       Empty when the single $reason string
     *                                       already says everything (faculty/time
     *                                       cases untouched by this task).
     * @param  list<array<string, mixed>>  $siblingDiagnostics  The full trace from
     *                                       SiblingSectionPatternService::getDiagnostics()
     *                                       for this row — every donor considered and
     *                                       every Day candidate tried against it, with
     *                                       the exact conflict (Section/Faculty/Room)
     *                                       that rejected each one. Surfaced so the
     *                                       Registrar can see *why* a subject didn't
     *                                       inherit a sibling section's pattern instead
     *                                       of having to dig through the log file.
     */
    private function unresolved(SectionSubject $sectionSubject, string $reason, array $reasonDetails = [], array $siblingDiagnostics = []): array
    {
        return [
            'success' => false,
            'result' => [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'reason' => $reason,
                'reason_details' => $reasonDetails,
                'sibling_pattern_diagnostics' => $siblingDiagnostics,
                'merge_recommendation' => $sectionSubject->merge_recommendation,
            ],
        ];
    }
}