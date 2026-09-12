<?php

namespace App\Services;

use App\Exceptions\ScheduleVersionConflictException;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Support\ViewingTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * All Section / Faculty / Room / Time-overlap conflict-detection logic
 * for the scheduling workspace lives here — controllers never run their
 * own conflict queries.
 *
 * SCOPE: THE SECTION BEING EDITED'S OWN TERM, NOT THE GLOBAL ACTIVE TERM
 * ------------------------------------------------------------------
 * Every check in this service is scoped to every Section that shares
 * the SAME Academic Year + Semester as the Section currently being
 * edited/scheduled — via scopedSectionIds()/sectionTermSectionIds()
 * (Section::academic_year + Section::semester), NOT the single
 * institution-wide "Active Academic Term" pill shown in the topbar.
 *
 * This distinction matters: the Active Academic Term is a global
 * setting (only ever one at a time — see AcademicTerm::active()) used
 * for things like which term new records default to. But a
 * Registrar/Dean can, and routinely does, plan a FUTURE term's
 * schedule (e.g. 2nd Semester) while the CURRENT term (1st Semester)
 * is still the one marked Active. If conflict checks stayed scoped to
 * the Active Term, planning that 2nd-Semester section would compare
 * it against 1st-Semester bookings (irrelevant noise) while silently
 * missing real double-bookings against OTHER 2nd-Semester sections
 * sharing the same Room/Faculty — a false negative that would let two
 * 2nd-Semester sections get scheduled into the same Room at the same
 * time undetected. Scoping to the edited Section's own term instead
 * means a Faculty member, Room, or Section can never end up
 * double-booked within the semester actually being planned, no matter
 * which term happens to be globally Active at the time. Placements
 * belonging to a genuinely different semester are intentionally
 * ignored — they cannot conflict with a schedule being built for a
 * different one.
 *
 * Two schedules are considered overlapping when they share at least one
 * Day AND:
 *
 *     new_start < existing_end  AND  new_end > existing_start
 *
 * This single rule is applied identically for Section, Faculty, and
 * Room conflicts (see overlaps()) so the three checks can never drift
 * out of sync with each other.
 *
 * VALIDATION PRIORITY
 * ------------------------------------------------------------------
 * validate() runs every check in this fixed order, per the Registrar's
 * spec, and returns as soon as a higher-priority check fails so the
 * Registrar / Auto Generate engine always sees the single most
 * important reason first:
 *
 *   1. Faculty availability      (Faculty exists & is Active)
 *   2. Faculty schedule conflict (this Section's own term)
 *   2b. Faculty daily-hours cap (this Section's own term) — separate
 *       from the weekly/semester Max Teaching Load: caps how many
 *       hours a Faculty member can be scheduled on ONE calendar day,
 *       regardless of how far under their weekly load they are. See
 *       findFacultyDailyHoursViolation() and the
 *       'workload.max_daily_teaching_hours' setting.
 *   3. Room availability         (Room exists & is Active)
 *   4. Room conflict             (this Section's own term)
 *   5. Section conflict          (this Section's own term)
 *   6. Lunch break restriction
 *   7. Academic calendar allowed days
 *   8. Time within allowed class hours
 *   9. Subject duration fits the slot
 *
 * Only when every one of these passes does a placement qualify for a
 * 100% confidence score (see RecommendationService).
 *
 * This service is deliberately framework-thin (one Eloquent query per
 * check, no HTTP concerns) so the future Genetic Algorithm scheduler
 * can call validate() on every candidate placement before finalizing a
 * generated schedule, exactly the same way the manual workspace does.
 */
class ScheduleConflictService
{
    public function __construct(
        private readonly \App\Services\SettingsService $settings,
    ) {
    }

    /**
     * Per-request cache for activeSemesterSectionIds() — see that
     * method's docblock. Reset is never needed: a single HTTP request
     * (and the single ScheduleConflictService instance Laravel's
     * container resolves for it) never spans more than one resolved
     * Viewing Term.
     */
    private ?Collection $activeSemesterSectionIdsCache = null;

    /**
     * Per-request cache for sectionTermSectionIds(), keyed by
     * "academic_year|semester" since a single request (e.g. Auto
     * Generate across a whole department) can legitimately touch more
     * than one Section, and therefore more than one term, even though
     * each individual conflict check only ever cares about one term at
     * a time.
     *
     * @var array<string, Collection>
     */
    private array $termSectionIdsCache = [];

    /**
     * Run every conflict/availability check for one schedule slot, in
     * strict priority order, and return the errors keyed the way the
     * scheduling workspace's form expects (faculty_id / room_id /
     * days). Empty array = fully valid, conflict-free placement.
     *
     * @param  array{section_id:int, faculty_id:?int, room_id:?int, days:list<string>, start_time:?string, end_time:?string, expected_minutes?:?int}  $slot
     * @return array<string, string>
     */
    public function validate(array $slot, int|array $excludingSectionSubjectId): array
    {
        $dayTokens = array_values(array_unique(array_filter($slot['days'] ?? [])));
        $startTime = $slot['start_time'] ?? null;
        $endTime = $slot['end_time'] ?? null;
        $facultyId = $slot['faculty_id'] ?? null;
        $roomId = $slot['room_id'] ?? null;
        $sectionId = $slot['section_id'] ?? null;

        // 1 & 3. Availability checks don't need a Day/Time window —
        // an Inactive Faculty/Room is never assignable, period.
        if ($facultyId) {
            $faculty = Faculty::find($facultyId);
            if (! $faculty) {
                return ['faculty_id' => 'Selected faculty member no longer exists.'];
            }
            if ($faculty->status !== 'Active') {
                return ['faculty_id' => "{$faculty->full_name} is not an Active faculty member and cannot be assigned."];
            }
        }

        if ($roomId) {
            $room = Room::find($roomId);
            if (! $room) {
                return ['room_id' => 'Selected room no longer exists.'];
            }
            if ($room->status !== 'Active') {
                return ['room_id' => "Room {$room->room_code} is not Active and cannot be assigned."];
            }
        }

        // Nothing further to validate until the slot has a full
        // Day/Time window.
        if (empty($dayTokens) || ! $startTime || ! $endTime) {
            return [];
        }

        // 2. Faculty schedule conflict — scoped to this Section's own term.
        if ($facultyId) {
            $conflict = $this->findFacultyConflict($facultyId, $excludingSectionSubjectId, $dayTokens, $startTime, $endTime, $sectionId);
            if ($conflict) {
                $facultyName = $conflict->faculty?->full_name ?? 'This faculty member';

                return ['faculty_id' => "Faculty Conflict: {$facultyName} already teaches "
                    ."{$conflict->subject?->subject_code} in {$conflict->section?->section_code} on "
                    .$this->describeWindow($conflict).'.', ];
            }
        }

        // 2b. Faculty daily-hours cap — independent of the weekly/
        // semester unit total. A faculty member can be well under
        // their Max Teaching Load and still get crammed into an
        // unrealistic single day (e.g. the whole 8AM-7PM window).
        if ($facultyId) {
            $violation = $this->findFacultyDailyHoursViolation($facultyId, $excludingSectionSubjectId, $dayTokens, $startTime, $endTime, $sectionId);
            if ($violation) {
                return ['faculty_id' => "Daily Load Exceeded: this would put {$violation['faculty_name']} at "
                    ."{$violation['projected_hours']} hours on {$violation['day']}, above the "
                    ."{$violation['cap']}-hour daily teaching cap.", ];
            }
        }

        // 4. Room conflict — scoped to this Section's own term.
        if ($roomId) {
            $conflict = $this->findRoomConflict($roomId, $excludingSectionSubjectId, $dayTokens, $startTime, $endTime, $sectionId);
            if ($conflict) {
                $roomCode = $conflict->room?->room_code ?? 'selected';

                return ['room_id' => "Room Conflict: Room {$roomCode} is already occupied by "
                    ."{$conflict->subject?->subject_code} ({$conflict->section?->section_code}) on "
                    .$this->describeWindow($conflict).'.', ];
            }
        }

        // 5. Section conflict — a Section cannot have two overlapping
        // classes, regardless of Faculty/Room.
        if ($sectionId) {
            $conflict = $this->findSectionConflict($sectionId, $excludingSectionSubjectId, $dayTokens, $startTime, $endTime);
            if ($conflict) {
                return ['days' => 'Section Conflict: This section already has '
                    ."{$conflict->subject?->subject_code} scheduled on ".$this->describeWindow($conflict).'.', ];
            }
        }

        $activeSchoolYear = SchoolYear::active();

        // 6. Lunch break restriction (fixed, non-editable window).
        if (SchoolYear::overlapsLunchBreak($startTime, $endTime)) {
            return ['days' => 'This time slot overlaps the Lunch Break (12:00 PM - 1:00 PM).'];
        }

        // 7. Academic calendar allowed days — every Day requested must
        // be one of the active School Year's configured Class Days.
        if ($activeSchoolYear) {
            foreach ($dayTokens as $day) {
                if (! $activeSchoolYear->isDayAllowed($day)) {
                    return ['days' => "{$day} is not an allowed class day for the active academic calendar."];
                }
            }
        }

        // 8. Time within allowed class hours (Class Start/End Time).
        if ($activeSchoolYear && ! $activeSchoolYear->isWithinSchedulingPolicy($startTime, $endTime)) {
            return ['days' => 'This time falls outside the allowed class hours ('
                .SchoolYear::to12Hour($activeSchoolYear->classStartTime()).' - '.SchoolYear::to12Hour($activeSchoolYear->classEndTime()).').', ];
        }

        // 9. Subject duration — the slot must actually fit the
        // Subject's required contact minutes for this meeting, when
        // the caller supplied one to check against.
        if (! empty($slot['expected_minutes'])) {
            $actualMinutes = $this->minutesBetween($startTime, $endTime);
            if ($actualMinutes + 5 < $slot['expected_minutes']) {
                return ['days' => "This time slot is shorter than the subject's required meeting duration."];
            }
        }

        return [];
    }

    /**
     * A Section cannot have two overlapping classes, regardless of
     * which Faculty or Room is assigned to either one. Scoped to the
     * Section itself, so it needs no term scoping of its own.
     */
    public function findSectionConflict(
        int $sectionId,
        int|array $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()->where('section_id', $sectionId),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
    }

    /**
     * A Faculty member cannot teach two overlapping classes, across
     * ANY Section, College, or Curriculum — scoped to every Section
     * sharing the acting Section's own term (see class docblock).
     *
     * $sectionId is the Section this placement is FOR (i.e.
     * $slot['section_id'] from validate()) — used only to resolve
     * WHICH term to scope the search to, not to exclude/include that
     * Section itself (it's already included, since it shares its own
     * term with itself).
     */
    public function findFacultyConflict(
        int $facultyId,
        int|array $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime,
        ?int $sectionId = null
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()
                ->where('faculty_id', $facultyId)
                ->whereIn('section_id', $this->scopedSectionIds($sectionId)),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
    }

    /**
     * DAILY-HOURS CAP — separate from Max Teaching Load (a weekly/
     * semester unit total). Sums this Faculty member's ALREADY-
     * scheduled minutes on each requested Day (across every Section
     * sharing the acting Section's own term, same scope as
     * findFacultyConflict()), adds the new slot's minutes, and flags
     * it when the projected total for any one Day exceeds the
     * institution's 'workload.max_daily_teaching_hours' setting
     * (default 8 — a realistic single-day teaching ceiling; the
     * 8:00 AM-7:00 PM room-scheduling window is NOT a per-faculty
     * daily load, it's just how late the campus runs classes).
     *
     * Returns null when within the cap, uncapped (setting = 0), or
     * $facultyId/window can't be resolved.
     *
     * @return array{day: string, faculty_name: string, projected_hours: float, cap: int}|null
     */
    public function findFacultyDailyHoursViolation(
        int $facultyId,
        int|array $excludingSectionSubjectId,
        array $dayTokens,
        string $startTime,
        string $endTime,
        ?int $sectionId = null
    ): ?array {
        $capHours = (int) $this->settings->get('workload.max_daily_teaching_hours', 8);

        if ($capHours <= 0) {
            return null;
        }

        $newMinutes = $this->minutesBetween($startTime, $endTime);
        $capMinutes = $capHours * 60;

        // PERFORMANCE — this used to pull every placement this faculty
        // member has across the whole term into a PHP collection and
        // re-filter/re-sum it once per requested day (an O(placements
        // x days) loop running on the request thread for every single
        // drag/click drop). Doing the per-day sum in SQL with
        // TIMESTAMPDIFF + a LIKE match on the days column lets the DB
        // (now aided by the faculty_id/start_time/end_time composite
        // index — see the accompanying migration) do this in one pass
        // per day instead of loading rows into memory at all.
        foreach ($dayTokens as $day) {
            // FIX (SQLite test suite failure): TIMESTAMPDIFF() is a
            // MySQL-only function. Production runs on MySQL so this
            // worked there, but the SQLite :memory: DB the test suite
            // runs against has no such function and threw "no such
            // column: MINUTE" (SQLite tried to parse MINUTE as a
            // column reference). strftime('%s', ...) is SQLite's
            // native equivalent for this — and per the manual check
            // above, it also correctly parses bare `HH:MM:SS` TIME
            // values (no date part) as today's date at that time, so
            // the subtraction still yields the right minute delta.
            // Branching on the connection driver keeps the real
            // MySQL codepath byte-for-byte unchanged.
            $diffInMinutesSql = SectionSubject::query()->getConnection()->getDriverName() === 'sqlite'
                ? "((strftime('%s', end_time) - strftime('%s', start_time)) / 60)"
                : 'TIMESTAMPDIFF(MINUTE, start_time, end_time)';

            $existingMinutes = (int) SectionSubject::query()
                ->where('faculty_id', $facultyId)
                ->whereIn('section_id', $this->scopedSectionIds($sectionId))
                ->whereNotIn('id', (array) $excludingSectionSubjectId)
                ->whereNotNull('days')
                ->whereNotNull('start_time')
                ->whereNotNull('end_time')
                ->where('days', 'like', "%{$day}%")
                ->sum(\Illuminate\Support\Facades\DB::raw($diffInMinutesSql));

            $projectedMinutes = $existingMinutes + $newMinutes;

            if ($projectedMinutes > $capMinutes) {
                return [
                    'day' => $day,
                    'faculty_name' => Faculty::find($facultyId)?->full_name ?? 'this faculty member',
                    'projected_hours' => round($projectedMinutes / 60, 1),
                    'cap' => $capHours,
                ];
            }
        }

        return null;
    }

    /**
     * A Room cannot host two overlapping classes, across ANY Section
     * or College — scoped to every Section sharing the acting
     * Section's own term (see class docblock and findFacultyConflict()).
     * Room is irrelevant to Faculty conflicts and vice versa: each
     * check only ever compares like-for-like (Room vs Room, Faculty
     * vs Faculty).
     */
    public function findRoomConflict(
        int $roomId,
        int|array $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime,
        ?int $sectionId = null
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()
                ->where('room_id', $roomId)
                ->whereIn('section_id', $this->scopedSectionIds($sectionId)),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
    }

    /**
     * Resolves which set of Section ids a conflict check should run
     * against: every Section sharing $sectionId's own Academic
     * Year + Semester, via sectionTermSectionIds(). Falls back to
     * activeSemesterSectionIds() (the Viewing-Term-aware scope — see
     * that method's docblock) when $sectionId is null/unresolvable —
     * e.g. a caller that genuinely doesn't have a Section context yet
     * — so nothing here throws or silently returns "every Section"
     * for an unrelated reason.
     */
    private function scopedSectionIds(?int $sectionId): Collection
    {
        if ($sectionId === null) {
            return $this->activeSemesterSectionIds();
        }

        $section = Section::find($sectionId, ['id', 'academic_year', 'semester']);

        if (! $section) {
            return $this->activeSemesterSectionIds();
        }

        return $this->sectionTermSectionIds($section);
    }

    /**
     * Every Section sharing the given Section's own Academic Year +
     * Semester (a straight column match against Section's own
     * academic_year/semester — no AcademicTerm lookup needed, since
     * the Section already stores both directly). This is the scope
     * conflict checks actually use now (see class docblock) — public
     * so controllers (Room Grid display, busy-times lookup) can reuse
     * the exact same set validate()/Save would use, and so the Grid
     * can never show/allow something Save would then reject.
     */
    public function sectionTermSectionIds(Section $section): Collection
    {
        $cacheKey = $section->academic_year.'|'.$section->semester;

        if (isset($this->termSectionIdsCache[$cacheKey])) {
            return $this->termSectionIdsCache[$cacheKey];
        }

        return $this->termSectionIdsCache[$cacheKey] = Section::query()
            ->where('academic_year', $section->academic_year)
            ->where('semester', $section->semester)
            ->pluck('id');
    }

    /**
     * NOTE: no longer used by findFacultyConflict()/findRoomConflict()
     * for a resolvable Section (see sectionTermSectionIds() above) —
     * this remains the fallback for callers with no Section context,
     * and is still deliberately used as-is by RoomUtilizationService/
     * FacultyWorkloadService for their dashboard stats.
     *
     * Every Section belonging to THIS user's Viewing Academic Term —
     * their session override if an Administrator/Registrar switched
     * it (see App\Support\ViewingTerm), else the real system-wide
     * Active term — via AcademicTerm::matchingSectionsQuery() (never a
     * raw string compare of the Semester name: Sections and the
     * Semester model spell the same Semester differently, and an
     * exact compare silently matches nothing). Using the Viewing Term
     * here means an Admin/Registrar who's switched their view to plan
     * a future semester also gets THAT semester's dashboard stats,
     * without affecting what any other user sees.
     *
     * When there's no resolvable term at all, this deliberately falls
     * back to "every Section" rather than "no Sections", so conflict
     * detection never silently turns itself off just because nobody
     * has marked a term Active yet.
     */
    public function activeSemesterSectionIds(): Collection
    {
        // Memoized per-request — a single overrideTime()/save call can
        // invoke this 2-3x (Faculty check, Room check, and indirectly
        // via recommendTimes()'s own candidate scoring). Without this,
        // every one of those re-runs ViewingTerm::resolve() plus the
        // full matching-Sections query from scratch even though the
        // resolved term never changes mid-request — the main reason
        // "Apply" on the Edit Day & Time popover feels slower than it
        // needs to.
        if ($this->activeSemesterSectionIdsCache !== null) {
            return $this->activeSemesterSectionIdsCache;
        }

        $viewingTerm = ViewingTerm::resolve(request());

        $query = $viewingTerm ? $viewingTerm->matchingSectionsQuery() : Section::query();

        return $this->activeSemesterSectionIdsCache = $query->pluck('id');
    }

    /**
     * Time-overlap rule shared by every conflict type:
     *
     *     new_start < existing_end  AND  new_end > existing_start
     *
     * Exposed publicly so the frontend's real-time check and any future
     * scheduler can both cite the exact same rule when documenting or
     * mirroring this logic client-side.
     */
    public function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }

    /**
     * Whether two Day lists share at least one common Day token.
     */
    public function sharesDay(array $daysA, array $daysB): bool
    {
        return ! empty(array_intersect($daysA, $daysB));
    }

    /**
     * Finds another SectionSubject row (excluding the one being
     * edited) already booked on any of the given Days whose
     * Start/End Time overlaps the given window, within an
     * already-scoped query (by section_id, faculty_id, or room_id,
     * plus — for Faculty/Room — the acting Section's own term's
     * Section ids).
     */
    private function findOverlap(
        Builder $query,
        int|array $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $query->with(['subject:id,subject_code', 'section:id,section_code', 'faculty:id,first_name,last_name', 'room:id,room_code'])
            ->whereNotIn('id', (array) $excludingId)
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where(function ($q) use ($dayTokens) {
                foreach ($dayTokens as $day) {
                    // Days are stored as a comma-separated string of
                    // exact tokens (Mon/Tue/Wed/Thu/Fri/Sat) — every
                    // token is a distinct 3-letter code, so a plain
                    // substring LIKE can never false-match a different
                    // Day (e.g. "Tue" is never a substring of any
                    // other token).
                    $q->orWhere('days', 'like', "%{$day}%");
                }
            })
            // Mirrors overlaps() as a SQL predicate — kept in sync by hand
            // since the query needs it inline, not as a callable.
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->first();
    }

    private function describeWindow(SectionSubject $conflict): string
    {
        $start = $conflict->start_time ? \Carbon\Carbon::parse($conflict->start_time)->format('g:i A') : '';
        $end = $conflict->end_time ? \Carbon\Carbon::parse($conflict->end_time)->format('g:i A') : '';
        $range = trim("{$start}-{$end}", '-');

        return trim("{$conflict->days} {$range}", ' -');
    }

    /**
     * INTELLIGENT IRREGULAR SECTION SCHEDULING — Merge Recommendation.
     *
     * A merged Irregular-section row (`merged_into_section_subject_id`
     * set) is DELIBERATELY given the exact same Faculty/Room/Days/
     * Time as its host Regular-section row — that overlap is the
     * entire point of merging, not a double-booking. Without this,
     * every merged row fails validate()/findFacultyConflict()/
     * findRoomConflict() against its own host the moment it's saved
     * (see Save Schedule / batchUpdateSchedule), reporting a phantom
     * "Faculty Conflict" / "Room Conflict" against the very class it
     * was intentionally merged into.
     *
     * Returns every SectionSubject id that must be excluded when
     * conflict-checking $subject: itself, the host it merged into (if
     * any), and — symmetrically — every OTHER row merged into it, in
     * case $subject IS itself a host with riders attached.
     *
     * @return list<int>
     */
    public function mergeExclusionIds(SectionSubject $subject): array
    {
        $ids = [$subject->id];

        if ($subject->merged_into_section_subject_id) {
            $ids[] = $subject->merged_into_section_subject_id;
        }

        $riders = $subject->mergedPlacements()->pluck('id')->all();

        return array_values(array_unique(array_merge($ids, $riders)));
    }

    /**
     * CONCURRENCY GUARD — acquires a row-level `SELECT ... FOR UPDATE`
     * lock on the Room/Faculty/Section themselves (never on the
     * SectionSubject rows being written) before validate() runs and
     * the write happens, both inside the SAME DB::transaction(). This
     * closes the check-then-write race: without it, two requests can
     * both read "this room is free" before either has saved, and both
     * pass validate() — see the concurrency hardening spec.
     *
     * MUST be called in this exact fixed order (Room, then Faculty,
     * then Section) by every caller — manual Save Schedule, Room Grid
     * move, merge, AND Auto Generate — so two transactions that both
     * touch, say, a Room and a Section can never deadlock each other
     * by acquiring the two locks in opposite orders. A missing
     * resource (null id) is simply skipped; there's nothing to
     * serialize against.
     *
     * Locking the single parent row (not every SectionSubject in that
     * Room/Faculty/Section) keeps this cheap: it's a mutex per
     * resource, not a table scan, and it works identically for a
     * brand-new placement (no existing SectionSubject rows to lock
     * yet) as it does for a move.
     *
     * SECTION-LEVEL SCHEDULE FINALIZATION — this is also the single
     * enforcement gate for that feature. Every caller listed above
     * already routes its write through this method before touching
     * anything, so checking `is_finalized` here (rather than
     * duplicating the check at each call site) guarantees a finalized
     * Section's schedule cannot be modified by ANY write path,
     * present or future, without deliberately bypassing this method.
     *
     * @throws \App\Exceptions\SectionFinalizedException  when the
     *         locked Section's schedule is finalized. Thrown from
     *         inside the transaction so it rolls back with no
     *         partial write, same pattern as ScheduleConflictAbort /
     *         ScheduleVersionConflictException.
     */
    public function lockResources(?int $roomId, ?int $facultyId, ?int $sectionId): ?Section
    {
        if ($roomId) {
            Room::whereKey($roomId)->lockForUpdate()->first();
        }
        if ($facultyId) {
            Faculty::whereKey($facultyId)->lockForUpdate()->first();
        }

        // The locked Section row is returned (rather than discarded,
        // as before) so callers can read its CURRENT schedule_version
        // — under the very same lock a racing request would also have
        // to wait on — and pass it straight into checkSectionVersion()
        // / bumpScheduleVersion() below without a second round trip.
        if ($sectionId) {
            $lockedSection = Section::whereKey($sectionId)->lockForUpdate()->first();

            if ($lockedSection !== null && $lockedSection->is_finalized) {
                throw new \App\Exceptions\SectionFinalizedException($lockedSection);
            }

            return $lockedSection;
        }

        return null;
    }

    /**
     * CONCURRENCY HARDENING — Optimistic Concurrency Control (spec
     * Section 3/4).
     *
     * Compares a caller-submitted `expected_schedule_version` against
     * the Section's CURRENT schedule_version. $lockedSection MUST
     * already have been read under `lockForUpdate()` (see
     * lockResources()) inside the same transaction as the eventual
     * write, so this reads the true latest-committed value — never a
     * stale snapshot — and blocks until any other in-flight
     * transaction touching this Section has committed or rolled back.
     *
     * A null $expectedVersion means the caller didn't opt into
     * version checking for this write (e.g. an older/partial
     * frontend payload) — this is intentionally a no-op, not a
     * failure, so version checking can be adopted per-endpoint
     * without breaking callers that don't send it yet.
     *
     * @throws ScheduleVersionConflictException  when the submitted
     *         version no longer matches the current one — the caller
     *         is expected to let this propagate out of the
     *         transaction so it rolls back with no partial write.
     */
    public function checkSectionVersion(?Section $lockedSection, ?int $expectedVersion): void
    {
        if ($expectedVersion === null || $lockedSection === null) {
            return;
        }

        if ((int) $lockedSection->schedule_version !== (int) $expectedVersion) {
            // TEMPORARY DIAGNOSTIC LOGGING — see the matching block in
            // bumpScheduleVersion() below. Remove/reduce both once the
            // false-conflict issue is confirmed fixed in production.
            Log::info('[ScheduleVersionConflict]', [
                'section' => $lockedSection->id,
                'expected' => (int) $expectedVersion,
                'current' => (int) $lockedSection->schedule_version,
                'user' => Auth::id(),
                'updated_by' => $lockedSection->schedule_version_updated_by,
                'endpoint' => request()?->path(),
            ]);

            throw new ScheduleVersionConflictException((int) $lockedSection->schedule_version, $expectedVersion);
        }
    }

    /**
     * Advances a Section's schedule_version by exactly 1 and records
     * WHO made the change. MUST only be called after a successful
     * write, on a $section instance already locked via
     * lockResources() within the SAME transaction — so the increment
     * can never race with, or be lost to, another transaction's own
     * read-check-write of the same counter. A failed/rolled-back
     * transaction never reaches this call, so the version is left
     * untouched on failure (spec Section 20).
     *
     * ACTOR-AWARE VERSION — `schedule_version_updated_by` is bumped in
     * the exact same `increment()` call (a single UPDATE) rather than
     * a separate ->update() afterward, so this can never introduce a
     * second query/race on the same locked row. auth()->id() is
     * resolved fresh from the request that's actually performing this
     * write; when there's no authenticated user (a console/system
     * process), it's left null — the frontend treats a null actor as
     * "unknown" and stays conservative (still warns), never as
     * "the current user". See the
     * 2026_08_17_090000_add_schedule_version_to_sections_table
     * migration's docblock and SectionSubjectController::
     * scheduleVersion().
     */
    public function bumpScheduleVersion(?Section $section): void
    {
        if ($section === null) {
            return;
        }

        $userId = auth()->id();

        $section->increment('schedule_version', 1, [
            'schedule_version_updated_by' => $userId,
        ]);

        // TEMPORARY DIAGNOSTIC LOGGING — makes it immediately obvious,
        // per-request, who advanced the version and to what — remove
        // or reduce once the false "another user" conflict issue is
        // confirmed fixed in production.
        Log::info('[ScheduleVersion]', [
            'section' => $section->id,
            'new_version' => $section->schedule_version,
            'updated_by' => $userId,
            'endpoint' => request()?->path(),
        ]);
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }
}