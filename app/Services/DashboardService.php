<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\RoomSubjectRecommendation;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * DASHBOARD DATA — single source of truth for every widget on the
 * Scheduling Control Center (see DashboardController).
 *
 * ROLE-BASED SCOPE
 * ------------------------------------------------------------------
 * Every number this service returns is filtered through scopeFor():
 *
 *   - Administrator / Registrar -> institution-wide, every College.
 *   - Dean / OIC                -> only the user's own College
 *     (User::college_id), via Section -> Major -> Department ->
 *     College. A Dean/OIC with no College assigned sees nothing
 *     rather than silently falling back to "everything".
 *   - Assistant Dean            -> General Education subjects only
 *     (Subject::category === 'General Education'), which are shared
 *     across every Major/College rather than tied to one. This is
 *     scoped at the SectionSubject level, not the Section level,
 *     since GenEd subjects live inside Sections that otherwise
 *     belong to Majors the Assistant Dean has no visibility into.
 *     NOTE: the source spec also mentions "Minor subjects" — the
 *     Subject model currently only distinguishes 'Major' vs
 *     'General Education' (see subjects table), so that half of the
 *     scope isn't representable yet. Revisit if/when a Minor
 *     category is added.
 *   - Any other/no role         -> nothing (fail safe, not fail
 *     open).
 *
 * ACTIVE SEMESTER SCOPE
 * ------------------------------------------------------------------
 * Every widget is additionally scoped to the currently Active
 * Academic Term, via ScheduleConflictService::activeSemesterSectionIds()
 * — the same method the scheduling workspace's conflict checks use,
 * so the Dashboard's numbers can never quietly disagree with what
 * the Registrar sees while actually building the schedule.
 */
class DashboardService
{
    public function __construct(
        private readonly ScheduleConflictService $conflictService,
    ) {
    }

    /**
     * Everything the Dashboard page needs, already scoped to the
     * given user's role/College.
     *
     * @return array{
     *     scope: array{type: string, college_id: ?int, label: ?string},
     *     kpis: array{active_sections: int, faculty_members: int, rooms: int, scheduled_subjects: int, total_subjects: int},
     *     progress: array{overall_percent: int, sections_scheduled: int, sections_total: int, faculty_assigned: int, rooms_assigned: int, total_subjects: int},
     *     conflicts: array{faculty_conflicts: int, faculty_conflicts_detail: list<array<string, mixed>>, room_conflicts: int, room_conflicts_detail: list<array<string, mixed>>, time_conflicts: int, time_conflicts_detail: list<array<string, mixed>>, unscheduled_subjects: int, major_subjects_scheduled: int, major_subjects_total: int, minor_gened_subjects_scheduled: int, minor_gened_subjects_total: int, major_subjects_by_program: list<array{program_code: string, program_name: string, scheduled: int, total: int}>, minor_gened_subjects_by_program: list<array{program_code: string, program_name: string, scheduled: int, total: int}>},
     * }
     */
    public function overview(User $user): array
    {
        $scope = $this->scopeFor($user);
        $sectionIds = $this->scopedSectionIds($scope);

        return [
            'scope' => $scope,
            'kpis' => $this->kpis($scope, $sectionIds),
            'progress' => $this->schedulingProgress($scope, $sectionIds),
            'conflicts' => $this->conflictSummary($scope, $sectionIds),
        ];
    }

    /**
     * Resolves the widest scope the user's roles grant them. A user
     * can hold more than one role — Administrator/Registrar always
     * wins (institution-wide) over a narrower College/GenEd scope.
     *
     * @return array{type: string, college_id: ?int, label: ?string}
     */
    private function scopeFor(User $user): array
    {
        $roles = $user->getRoleNames();

        if ($roles->contains('Administrator') || $roles->contains('Registrar')) {
            return ['type' => 'institution', 'college_id' => null, 'label' => 'Institution-wide'];
        }

        if ($roles->contains('Dean') || $roles->contains('OIC')) {
            $user->loadMissing('college:id,name,short_name');

            return [
                'type' => 'college',
                'college_id' => $user->college_id,
                'label' => $user->college?->short_name ?? $user->college?->name,
            ];
        }

        if ($roles->contains('Assistant Dean')) {
            return ['type' => 'general_education', 'college_id' => null, 'label' => 'General Education Subjects'];
        }

        return ['type' => 'none', 'college_id' => null, 'label' => null];
    }

    /**
     * Every Section id the given scope is allowed to see, already
     * intersected with the active semester. 'general_education'
     * intentionally returns every active-semester Section id — GenEd
     * subjects can appear inside a Section belonging to any Major, so
     * the real filtering for that scope happens per-SectionSubject
     * (see sectionSubjectsQuery()), not per-Section.
     */
    private function scopedSectionIds(array $scope): Collection
    {
        $activeSectionIds = $this->conflictService->activeSemesterSectionIds();

        return match ($scope['type']) {
            'institution', 'general_education' => $activeSectionIds,
            'college' => $scope['college_id']
                ? Section::query()
                    ->whereIn('id', $activeSectionIds)
                    ->whereHas('major.department', fn (Builder $q) => $q->where('college_id', $scope['college_id']))
                    ->pluck('id')
                : collect(),
            default => collect(),
        };
    }

    /**
     * The scoped SectionSubject placements every widget below reads
     * from — every widget building its own query off this one method
     * is what keeps the KPI cards, Progress widget, and Conflict
     * panel from being able to quietly disagree with each other.
     */
    private function sectionSubjectsQuery(array $scope, Collection $sectionIds): Builder
    {
        $query = SectionSubject::query()->whereIn('section_id', $sectionIds);

        if ($scope['type'] === 'general_education') {
            $query->whereHas('subject', fn (Builder $q) => $q->where('category', 'General Education'));
        }

        return $query;
    }

    /**
     * @return array{active_sections: int, faculty_members: int, rooms: int, scheduled_subjects: int, total_subjects: int}
     */
    private function kpis(array $scope, Collection $sectionIds): array
    {
        $activeSections = Section::query()
            ->whereIn('id', $sectionIds)
            ->where('status', 'Active')
            ->count();

        $facultyQuery = Faculty::query()->where('status', 'Active');
        $roomQuery = Room::query()->where('status', 'Active');

        if ($scope['type'] === 'college') {
            $facultyQuery->where('college_id', $scope['college_id']);
            $roomQuery->where('college_id', $scope['college_id']);
        } elseif ($scope['type'] === 'general_education') {
            // GenEd Faculty/Rooms carry no College — see
            // Faculty::getFacultyCategoryAttribute().
            $facultyQuery->whereNull('college_id');
            $roomQuery->whereNull('college_id');
        } elseif ($scope['type'] === 'none') {
            $facultyQuery->whereRaw('1 = 0');
            $roomQuery->whereRaw('1 = 0');
        }

        $subjectsQuery = $this->sectionSubjectsQuery($scope, $sectionIds);

        return [
            'active_sections' => $activeSections,
            'faculty_members' => $facultyQuery->count(),
            'rooms' => $roomQuery->count(),
            'scheduled_subjects' => (clone $subjectsQuery)->where('status', 'Scheduled')->count(),
            'total_subjects' => (clone $subjectsQuery)->count(),
        ];
    }

    /**
     * @return array{overall_percent: int, sections_scheduled: int, sections_total: int, faculty_assigned: int, rooms_assigned: int, total_subjects: int}
     */
    private function schedulingProgress(array $scope, Collection $sectionIds): array
    {
        $subjectsQuery = $this->sectionSubjectsQuery($scope, $sectionIds);

        $totalSubjects = (clone $subjectsQuery)->count();
        $scheduledSubjects = (clone $subjectsQuery)->where('status', 'Scheduled')->count();
        $facultyAssigned = (clone $subjectsQuery)->whereNotNull('faculty_id')->count();
        $roomsAssigned = (clone $subjectsQuery)->whereNotNull('room_id')->count();

        // A Section counts as "Scheduled" once every one of its
        // (scope-filtered) subject placements is Scheduled — the same
        // "N/N assigned" idea SectionController::index() shows per
        // row, rolled up here into one scope-wide count.
        $perSection = (clone $subjectsQuery)
            ->selectRaw('section_id, COUNT(*) as total, SUM(status = "Scheduled") as scheduled')
            ->groupBy('section_id')
            ->get();

        return [
            'overall_percent' => $totalSubjects > 0 ? (int) round($scheduledSubjects / $totalSubjects * 100) : 0,
            'sections_scheduled' => $perSection->filter(
                fn ($row) => (int) $row->total > 0 && (int) $row->scheduled === (int) $row->total
            )->count(),
            'sections_total' => $perSection->count(),
            'faculty_assigned' => $facultyAssigned,
            'rooms_assigned' => $roomsAssigned,
            'total_subjects' => $totalSubjects,
        ];
    }

    /**
     * @return array{faculty_conflicts: int, faculty_conflicts_detail: list<array<string, mixed>>, room_conflicts: int, room_conflicts_detail: list<array<string, mixed>>, time_conflicts: int, time_conflicts_detail: list<array<string, mixed>>, unscheduled_subjects: int, major_subjects_scheduled: int, major_subjects_total: int, minor_gened_subjects_scheduled: int, minor_gened_subjects_total: int, major_subjects_by_program: list<array{program_code: string, program_name: string, scheduled: int, total: int}>, minor_gened_subjects_by_program: list<array{program_code: string, program_name: string, scheduled: int, total: int}>}
     */
    private function conflictSummary(array $scope, Collection $sectionIds): array
    {
        $subjectsQuery = $this->sectionSubjectsQuery($scope, $sectionIds);

        // "Missing Faculty"/"Missing Rooms" were dropped from this
        // panel — a subject offering with no Faculty/Room assigned
        // yet is already surfaced by Unscheduled Subjects (it can't
        // be Scheduled without both), so the two tiles were counting
        // the same underlying problem twice. Replaced with a
        // scheduled/total breakdown (same "N / N" shape as the
        // Sections/Faculty/Rooms Assigned cards above) split by
        // Subject::category, so a Dean can see progress within Major
        // vs Minor/GenEd, not just a flat count.
        $majorQuery = (clone $subjectsQuery)
            ->whereHas('subject', fn (Builder $q) => $q->where('category', 'Major'));
        $minorGenedQuery = (clone $subjectsQuery)
            ->whereHas('subject', fn (Builder $q) => $q->whereIn('category', ['Minor', 'General Education']));

        $majorTotal = (clone $majorQuery)->count();
        $majorScheduled = (clone $majorQuery)->where('status', 'Scheduled')->count();
        $minorGenedTotal = (clone $minorGenedQuery)->count();
        $minorGenedScheduled = (clone $minorGenedQuery)->where('status', 'Scheduled')->count();

        $unscheduled = (clone $subjectsQuery)
            ->where(function (Builder $q) {
                $q->whereNull('days')->orWhereNull('start_time')->orWhereNull('end_time');
            })
            ->count();

        // Only fully-timed placements can meaningfully overlap or be
        // checked for a Room Type / Hours mismatch — pulled once and
        // reused for every check below, with the display relations
        // (section/subject/faculty/room) eager-loaded so the
        // breakdown dialogs can name names instead of just counting.
        // merged_into_section_subject_id is pulled alongside so
        // overlappingPairs() can recognise a merged Irregular-section
        // row and its Regular-section host (or two rows merged into
        // the same host) as the SAME class occupying the room/
        // faculty/slot on purpose, not a double-booking — mirrors
        // ScheduleConflictService::mergeExclusionIds().
        $placements = (clone $subjectsQuery)
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->with(['section:id,section_code', 'subject:id,subject_code,lecture_hours,laboratory_hours,major_id', 'faculty:id,first_name,last_name,college_id', 'faculty.subjects:id', 'subject.major.department:id,college_id', 'room:id,room_code,room_name,room_type'])
            ->get(['id', 'section_id', 'subject_id', 'faculty_id', 'room_id', 'days', 'start_time', 'end_time', 'merged_into_section_subject_id', 'hours_confirmed', 'room_type_confirmed', 'room_college_confirmed', 'faculty_mismatch_confirmed']);

        // Double-booking pairs — Faculty/Room/Section sharing a Day +
        // overlapping Time.
        $facultyPairs = $this->overlappingPairs($placements, 'faculty_id');
        $roomPairs = $this->overlappingPairs($placements, 'room_id');
        $sectionPairs = $this->overlappingPairs($placements, 'section_id');

        // Room Conflicts now also folds in Room Type Mismatch (a
        // Lecture/Laboratory subject sitting in the wrong kind of
        // room) — same "problem with the Room this class landed in"
        // family as a double-booking, just non-blocking rather than
        // blocking. Mirrors SectionSubjectController's own
        // room_type_confirmed check.
        $roomTypeMismatches = $this->roomTypeMismatchDetails($placements);

        // Time Conflicts now also folds in Weekly Hours Mismatch (the
        // scheduled Days x Start/End doesn't add up to what the
        // Subject's curriculum requires) — same "problem with the
        // time this class landed in" family as a same-section
        // overlap, just non-blocking rather than blocking. Mirrors
        // SectionSubjectController's own hours_confirmed check.
        $hoursMismatches = $this->hoursMismatchDetails($placements);

        // Faculty Conflicts now also folds in Faculty Mismatch (a
        // manually-assigned Faculty who isn't Teaching-Qualified for
        // the Subject and isn't from its academic home College/GenEd
        // pool) — same "problem with the Faculty on this class"
        // family as a double-booking, just non-blocking rather than
        // blocking. Mirrors SectionSubjectController's own
        // faculty_mismatch_confirmed check, and brings Faculty
        // Conflicts in line with how Room/Time Conflicts already fold
        // in their own mismatch flavor below.
        $facultyMismatches = $this->facultyMismatchDetails($placements);

        $facultyConflictIds = $this->pairConflictIds($facultyPairs);
        foreach ($facultyMismatches as $row) {
            $facultyConflictIds[$row['id']] = true;
        }

        $roomConflictIds = $this->pairConflictIds($roomPairs);
        foreach ($roomTypeMismatches as $row) {
            $roomConflictIds[$row['id']] = true;
        }

        $timeConflictIds = $this->pairConflictIds($sectionPairs);
        foreach ($hoursMismatches as $row) {
            $timeConflictIds[$row['id']] = true;
        }

        return [
            // Faculty double-booked across any Section/College, PLUS
            // Faculty Mismatch (assigned Faculty isn't qualified for
            // or from the academic home of the Subject they're
            // teaching).
            'faculty_conflicts' => count($facultyConflictIds),
            'faculty_conflicts_detail' => array_merge(
                $this->pairDetails($facultyPairs, 'Double-Booked', 'faculty'),
                array_map(fn ($row) => $this->stripId($row), $facultyMismatches),
            ),
            // Room double-booked across any Section/College, PLUS Room
            // Type Mismatch (Lecture subject in a Lab room or vice
            // versa).
            'room_conflicts' => count($roomConflictIds),
            'room_conflicts_detail' => array_merge(
                $this->pairDetails($roomPairs, 'Double-Booked', 'room'),
                array_map(fn ($row) => $this->stripId($row), $roomTypeMismatches),
            ),
            // A single Section's own two classes overlapping each
            // other ("Time Conflicts" in the spec) — distinct from
            // the double-booking checks above, which compare across
            // Sections — PLUS Weekly Hours Mismatch (scheduled hours
            // don't add up to the Subject's required weekly hours).
            'time_conflicts' => count($timeConflictIds),
            'time_conflicts_detail' => array_merge(
                $this->pairDetails($sectionPairs, 'Overlapping Classes', 'section'),
                array_map(fn ($row) => $this->stripId($row), $hoursMismatches),
            ),
            'unscheduled_subjects' => $unscheduled,
            'major_subjects_scheduled' => $majorScheduled,
            'major_subjects_total' => $majorTotal,
            'minor_gened_subjects_scheduled' => $minorGenedScheduled,
            'minor_gened_subjects_total' => $minorGenedTotal,
            'major_subjects_by_program' => $this->byProgram($majorQuery),
            'minor_gened_subjects_by_program' => $this->byProgram($minorGenedQuery),
        ];
    }

    /**
     * Breaks a (Major or Minor/GenEd) subjects query down per Program
     * — i.e. per Major record, since that's the "program" a Section
     * belongs to (Section::major_id; see Major model docblock: "the
     * major this section belongs to"). Powers the drill-down when a
     * Dean clicks the Major Subjects / Minor-GenEd Subjects tile —
     * e.g. a CCS Dean with 4 programs sees BSCS/BSIT/BSCRIMFI/etc.
     * broken out individually instead of one lump total.
     *
     * A GenEd subject offering still lives inside a Section that
     * belongs to a Major/Program (GenEd is shared curriculum content,
     * not a program-less Section), so grouping by the Section's own
     * Program works identically for both callers.
     *
     * @return list<array{program_code: string, program_name: string, scheduled: int, total: int}>
     */
    private function byProgram(Builder $subjectsQuery): Collection
    {
        return (clone $subjectsQuery)
            ->join('sections', 'sections.id', '=', 'section_subjects.section_id')
            ->join('majors', 'majors.id', '=', 'sections.major_id')
            ->selectRaw('majors.id as program_id, majors.code as program_code, majors.name as program_name, '
                .'COUNT(*) as total, SUM(section_subjects.status = "Scheduled") as scheduled')
            ->groupBy('majors.id', 'majors.code', 'majors.name')
            ->orderBy('majors.code')
            ->get()
            ->map(fn ($row) => [
                'program_code' => $row->program_code,
                'program_name' => $row->program_name,
                'scheduled' => (int) $row->scheduled,
                'total' => (int) $row->total,
            ])
            ->values();
    }

    /**
     * Finds every pair of placements that overlap another placement
     * sharing the same $groupKey (faculty_id / room_id / section_id),
     * using the exact same Day/Time overlap rule as
     * ScheduleConflictService::sharesDay()/overlaps() — so this can
     * never quietly disagree with what the scheduling workspace
     * itself would flag as a conflict. Groups are small (one
     * faculty/room/section's own placements), so the pairwise scan
     * below stays cheap despite being O(n^2) per group.
     *
     * @param  Collection<int, SectionSubject>  $placements
     * @return Collection<int, array{0: SectionSubject, 1: SectionSubject}>
     */
    private function overlappingPairs(Collection $placements, string $groupKey): Collection
    {
        $pairs = collect();

        $placements
            ->filter(fn (SectionSubject $p) => $p->{$groupKey} !== null)
            ->groupBy($groupKey)
            ->each(function (Collection $group) use (&$pairs) {
                $items = $group->values();

                for ($i = 0; $i < $items->count(); $i++) {
                    for ($j = $i + 1; $j < $items->count(); $j++) {
                        $a = $items[$i];
                        $b = $items[$j];

                        // Same class, not a conflict: $a and $b belong
                        // to the same merge group when one is merged
                        // into the other, or both are merged into the
                        // same host (Regular + Irregular riders sharing
                        // one Faculty/Room/Day/Time on purpose).
                        $sameMergeGroup = $a->id === $b->merged_into_section_subject_id
                            || $b->id === $a->merged_into_section_subject_id
                            || ($a->merged_into_section_subject_id !== null
                                && $a->merged_into_section_subject_id === $b->merged_into_section_subject_id);

                        if ($sameMergeGroup) {
                            continue;
                        }

                        $daysA = array_values(array_filter(explode(',', (string) $a->days)));
                        $daysB = array_values(array_filter(explode(',', (string) $b->days)));

                        if (
                            $this->conflictService->sharesDay($daysA, $daysB)
                            && $this->conflictService->overlaps($a->start_time, $a->end_time, $b->start_time, $b->end_time)
                        ) {
                            $pairs->push([$a, $b]);
                        }
                    }
                }
            });

        return $pairs;
    }

    /**
     * Every SectionSubject id (from either side of every pair) that
     * shows up in $pairs, as a lookup set — used both to size a tile
     * (count of distinct affected placements, not pair count) and to
     * union with a mismatch id list without double-counting a row
     * that has both problems at once.
     *
     * @param  Collection<int, array{0: SectionSubject, 1: SectionSubject}>  $pairs
     * @return array<int, true>
     */
    private function pairConflictIds(Collection $pairs): array
    {
        $ids = [];

        foreach ($pairs as [$a, $b]) {
            $ids[$a->id] = true;
            $ids[$b->id] = true;
        }

        return $ids;
    }

    /**
     * Turns overlap pairs into breakdown-dialog rows. $focus picks
     * which shared resource the row is "about" (Room/Faculty/
     * Section), matching the tile the row will be shown under.
     *
     * @param  Collection<int, array{0: SectionSubject, 1: SectionSubject}>  $pairs
     * @return list<array<string, mixed>>
     */
    private function pairDetails(Collection $pairs, string $type, string $focus): array
    {
        return $pairs->map(function (array $pair) use ($type, $focus) {
            [$a, $b] = $pair;

            $sharedDays = array_values(array_intersect(
                array_filter(explode(',', (string) $a->days)),
                array_filter(explode(',', (string) $b->days)),
            ));

            $resource = match ($focus) {
                'room' => $a->room?->room_name ?? $a->room?->room_code ?? 'this room',
                'faculty' => $a->faculty?->full_name ?? 'this faculty member',
                'section' => $a->section?->section_code ?? 'this section',
                default => '—',
            };

            $subjectA = $a->subject?->subject_code ?? '—';
            $subjectB = $b->subject?->subject_code ?? '—';
            $sectionA = $a->section?->section_code ?? '—';
            $sectionB = $b->section?->section_code ?? '—';

            return [
                'id' => $a->id,
                'type' => $type,
                'section_a' => $sectionA,
                'subject_a' => $subjectA,
                'section_b' => $sectionB,
                'subject_b' => $subjectB,
                'day' => $this->formatDays($sharedDays),
                'time' => $this->formatTimeRange12h($a->start_time, $a->end_time),
                'note' => $focus === 'section'
                    ? "{$subjectA} and {$subjectB} are both scheduled for {$sectionA} at the same time."
                    : "{$subjectA} ({$sectionA}) and {$subjectB} ({$sectionB}) are both booked on {$resource} at the same time.",
                'open_section_id' => $a->section_id,
            ];
        })->values()->all();
    }

    /**
     * Placements whose Room's type (Lecture/Laboratory) doesn't match
     * what the Subject needs — same rule SectionSubjectController
     * checks at save time before requiring room_type_confirmed=true.
     *
     * A row the Registrar already confirmed via "Save Anyway"
     * (room_type_confirmed=true, persisted at save time — see the
     * 2026_08_24_180000 migration) is intentional, not a conflict, so
     * it's excluded here exactly the same way the Section Subjects
     * page's own "Scheduling Issues" panel stops flagging it: this
     * Dashboard tile and that panel now read the same persisted
     * confirmation instead of the Dashboard silently disagreeing with
     * what the Registrar already acknowledged.
     *
     * @param  Collection<int, SectionSubject>  $placements
     * @return list<array<string, mixed>>
     */
    private function roomTypeMismatchDetails(Collection $placements): array
    {
        // Explicit Administrator Overrides (room_subject_recommendations)
        // among these placements' actual Room/Subject pairings — fetched
        // in ONE batched query rather than per-placement, same
        // reasoning as $facultyCurrentLoads in SectionSubjectController.
        // A pairing in this set is never a Room Type Mismatch: same
        // exemption AutoScheduleService's own Room Type filter and
        // Show.vue's client-side check already grant it.
        $overridePairs = RoomSubjectRecommendation::query()
            ->where('active', true)
            ->whereIn('room_id', $placements->pluck('room_id')->filter()->unique())
            ->whereIn('subject_id', $placements->pluck('subject_id')->filter()->unique())
            ->get(['room_id', 'subject_id'])
            ->map(fn ($pair) => "{$pair->room_id}:{$pair->subject_id}")
            ->flip();

        return $placements
            ->filter(function (SectionSubject $p) use ($overridePairs) {
                if (! $p->room_id || ! $p->room || ! $p->subject || $p->room_type_confirmed) {
                    return false;
                }

                if ($overridePairs->has("{$p->room_id}:{$p->subject_id}")) {
                    return false;
                }

                $wantsLaboratory = (int) $p->subject->laboratory_hours > 0;

                return $wantsLaboratory
                    ? $p->room->room_type !== 'Laboratory'
                    : $p->room->room_type === 'Laboratory';
            })
            ->map(function (SectionSubject $p) {
                $wantsLaboratory = (int) $p->subject->laboratory_hours > 0;

                return [
                    'id' => $p->id,
                    'type' => 'Room Type Mismatch',
                    'section_a' => $p->section?->section_code ?? '—',
                    'subject_a' => $p->subject->subject_code,
                    'section_b' => null,
                    'subject_b' => null,
                    'day' => $this->formatDays(array_values(array_filter(explode(',', (string) $p->days)))),
                    'time' => $this->formatTimeRange12h($p->start_time, $p->end_time),
                    'note' => "{$p->subject->subject_code} is a ".($wantsLaboratory ? 'Laboratory' : 'Lecture')
                        ." subject, but {$p->room->room_name} is a {$p->room->room_type} room.",
                    'open_section_id' => $p->section_id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Placements whose scheduled Days x Start/End doesn't add up to
     * the Subject's declared weekly hours — same formula
     * SectionSubjectController checks at save time before requiring
     * hours_confirmed=true (falls back to 3 hrs/week when the Subject
     * declares none, matching RecommendationService's own fallback).
     *
     * SPLIT-DELIVERY SCHEDULING — a Face-to-Face/Online split (e.g. 3
     * hrs/week in-room + 2 hrs/week Online, same Section+Subject) is
     * saved as TWO SectionSubject rows. Judging each row against the
     * Subject's full weekly requirement on its own used to flag both
     * halves as a mismatch even though they correctly add up together
     * — same "one class, split across rows" grouping rule used
     * elsewhere (e.g. FacultyWorkloadService::assignedPlacements()).
     * Rows are grouped by section_id+subject_id and their hours summed
     * before comparing against the requirement, so a correctly-split
     * schedule is no longer flagged.
     *
     * A row already confirmed (hours_confirmed=true, persisted at
     * save time) is excluded — see roomTypeMismatchDetails()'s
     * docblock above for why. For a split pair, ANY row in the group
     * being confirmed is enough to exclude the whole group, since the
     * Registrar reviewed the combined schedule as one unit.
     *
     * @param  Collection<int, SectionSubject>  $placements
     * @return list<array<string, mixed>>
     */
    private function hoursMismatchDetails(Collection $placements): array
    {
        return $placements
            ->filter(fn (SectionSubject $p) => $p->subject !== null)
            ->groupBy(fn (SectionSubject $p) => $p->section_id.'-'.$p->subject_id)
            ->filter(fn (Collection $group) => $group->every(fn (SectionSubject $p) => ! $p->hours_confirmed))
            ->map(function (Collection $group) {
                $primary = $group->first();
                $requiredHours = ((int) $primary->subject->lecture_hours) + ((int) $primary->subject->laboratory_hours);
                if ($requiredHours <= 0) {
                    $requiredHours = 3;
                }

                $actualMinutes = $group->sum(function (SectionSubject $p) {
                    $dayTokens = array_values(array_filter(explode(',', (string) $p->days)));

                    return (strtotime($p->end_time) - strtotime($p->start_time)) / 60 * count($dayTokens);
                });
                $actualHours = round($actualMinutes / 60, 2);

                return [$primary, $requiredHours, $actualHours];
            })
            ->filter(fn (array $row) => $row[2] !== (float) $row[1])
            ->map(function (array $row) {
                [$p, $requiredHours, $actualHours] = $row;

                return [
                    'id' => $p->id,
                    'type' => 'Hours Mismatch',
                    'section_a' => $p->section?->section_code ?? '—',
                    'subject_a' => $p->subject->subject_code,
                    'section_b' => null,
                    'subject_b' => null,
                    'day' => $this->formatDays(array_values(array_filter(explode(',', (string) $p->days)))),
                    'time' => $this->formatTimeRange12h($p->start_time, $p->end_time),
                    'note' => "This schedule totals {$actualHours} hrs/week, but {$p->subject->subject_code} requires {$requiredHours} hrs/week.",
                    'open_section_id' => $p->section_id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Placements whose assigned Faculty isn't Teaching-Qualified for
     * the Subject and isn't from the Subject's own academic home
     * College/GenEd pool — same rule SectionSubjectController checks
     * at save time before requiring faculty_mismatch_confirmed=true,
     * and the same computed flag SectionSubject::faculty_mismatch
     * already exposes to the Section Subjects page's own "Scheduling
     * Issues" panel.
     *
     * A row already confirmed (faculty_mismatch_confirmed=true,
     * persisted at save time) is excluded — see
     * roomTypeMismatchDetails()'s docblock above for why.
     *
     * @param  Collection<int, SectionSubject>  $placements
     * @return list<array<string, mixed>>
     */
    private function facultyMismatchDetails(Collection $placements): array
    {
        return $placements
            ->filter(fn (SectionSubject $p) => $p->faculty_id && $p->faculty && $p->subject
                && ! $p->faculty_mismatch_confirmed
                && $p->faculty_mismatch === true)
            ->map(function (SectionSubject $p) {
                return [
                    'id' => $p->id,
                    'type' => 'Faculty Mismatch',
                    'section_a' => $p->section?->section_code ?? '—',
                    'subject_a' => $p->subject->subject_code,
                    'section_b' => null,
                    'subject_b' => null,
                    'day' => $this->formatDays(array_values(array_filter(explode(',', (string) $p->days)))),
                    'time' => $this->formatTimeRange12h($p->start_time, $p->end_time),
                    'note' => "{$p->faculty->full_name} is not on file as qualified or from the academic home for {$p->subject->subject_code}.",
                    'open_section_id' => $p->section_id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Drops the internal 'id' key (only used for building the union
     * of conflicting ids) before a detail row is sent to the
     * frontend.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function stripId(array $row): array
    {
        unset($row['id']);

        return $row;
    }

    /**
     * Compact "MWF" / "TTH" style day abbreviations, matching the
     * frontend's own formatDays() (see SectionSubjects/Show.vue) so
     * the Dashboard's breakdown dialogs read identically to the
     * scheduling workspace.
     *
     * @param  list<string>  $days
     */
    private function formatDays(array $days): string
    {
        $abbreviations = ['Mon' => 'M', 'Tue' => 'T', 'Wed' => 'W', 'Thu' => 'TH', 'Fri' => 'F', 'Sat' => 'SAT', 'Sun' => 'SUN'];
        $order = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        return collect($order)
            ->filter(fn ($token) => in_array($token, $days, true))
            ->map(fn ($token) => $abbreviations[$token])
            ->implode('');
    }

    /**
     * Formats a "h:mm AM/PM – h:mm AM/PM" range, mirroring
     * ReportsService::formatTimeRange12h() so this reads identically
     * to the Scheduling Conflicts report.
     */
    private function formatTimeRange12h(?string $start, ?string $end): string
    {
        if (! $start || ! $end) {
            return '—';
        }

        return $this->formatTime12h($start).'–'.$this->formatTime12h($end);
    }

    private function formatTime12h(string $time): string
    {
        return date('g:i A', strtotime($time));
    }
}