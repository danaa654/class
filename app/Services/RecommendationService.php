<?php

namespace App\Services;

use App\Models\SchoolYear;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Services\RoomUtilizationService;

/**
 * Smart Assignment Recommendation Engine (Prompt 8.6).
 *
 * Given a Subject placed into a Section (a SectionSubject row on the
 * scheduling workspace), this service ranks the best candidate
 * Faculty, Room, and Time slot for it. It NEVER assigns anything —
 * it only returns ranked suggestions for the Registrar (or, later,
 * the Genetic Algorithm) to choose from.
 *
 * Kept framework-thin and free of HTTP concerns — same reasoning as
 * ScheduleConflictService — so the future Genetic Algorithm scheduler
 * can call recommend()/recommendFaculty()/recommendRooms()/
 * recommendTimes() directly when searching for candidate assignments,
 * exactly the same way the manual workspace does here.
 */
class RecommendationService
{
    private const MAX_ROOM_RESULTS = 5;

    // Was referenced (recommendFaculty()'s primary/fallback ranking,
    // and the College/GenEd override ranking around line 716) but
    // never declared, so any Faculty recommendation call — including
    // the one Auto Generate Schedule fires per subject — fatal'd with
    // "Undefined constant ...::MAX_FACULTY_RESULTS". Mirrors
    // MAX_ROOM_RESULTS above: cap the ranked Faculty list to the top
    // 5 candidates.
    private const MAX_FACULTY_RESULTS = 5;

    private const MAX_TIME_RESULTS = 5;

    public function __construct(
        private readonly ScheduleConflictService $conflictService,
        private readonly MeetingPatternService $meetingPatternService,
        private readonly FacultyWorkloadService $workloadService,
        private readonly SiblingSectionPatternService $siblingPatternService,
    ) {
    }

    /**
     * Build the full Faculty + Room + Time recommendation set for one
     * scheduling workspace row. This is what the "Recommendation
     * Panel" on the frontend calls.
     */
    public function recommend(SectionSubject $sectionSubject): array
    {
        $sectionSubject->loadMissing(['subject', 'section.major.department']);

        $subject = $sectionSubject->subject;
        $section = $sectionSubject->section;

        $faculty = $this->recommendFaculty($subject, $section, $sectionSubject);

        // SPLIT-DELIVERY SCHEDULING — an Online row never occupies a
        // Room (SectionSubject::requiresRoom() === false), so ranking
        // Rooms for it is both meaningless AND actively harmful here:
        // feeding a topRoomId into recommendTimes() below would check
        // that (irrelevant) Room's conflicts too, potentially rejecting
        // an otherwise perfectly free Faculty+Section slot just
        // because SOME room happens to be booked then. Skip straight
        // to a "no room needed" result and let the Time search below
        // run on Faculty+Section conflicts only — exactly what an
        // Online class actually needs to avoid.
        $room = $sectionSubject->requiresRoom()
            ? $this->recommendRooms($subject, $section, $sectionSubject)
            : ['recommendations' => [], 'message' => 'This class meets online — no Room is needed.'];

        // The Time recommendation is checked against the *top* Faculty
        // and Room picks (falling back to whatever's already saved on
        // the row) so the suggested slot is actually usable with the
        // other two suggestions, not just theoretically open.
        $topFacultyId = $faculty['recommendations'][0]['id'] ?? $sectionSubject->faculty_id;
        $topRoomId = $sectionSubject->requiresRoom()
            ? ($room['recommendations'][0]['id'] ?? $sectionSubject->room_id)
            : null;

        $time = $this->recommendTimes($subject, $section, $topFacultyId, $topRoomId, $sectionSubject);

        // Combined suggestions reuse the Faculty/Room lists already
        // computed above (no re-querying) and reuse recommendTimes()
        // — and therefore ScheduleConflictService — for every
        // Faculty/Room pairing, so a Combined Recommendation can never
        // disagree with the individual Faculty/Room/Time lists or with
        // what happens when the Registrar actually saves. For an
        // Online row (no Room list above), this naturally produces no
        // Faculty x Room combinations — the frontend falls back to the
        // plain Faculty/Time lists for it instead.
        $combined = $this->buildCombinedRecommendations(
            $subject, $section, $faculty['recommendations'], $room['recommendations'], $sectionSubject
        );

        // SIBLING SECTION PATTERN MATCHING — if another Section in
        // the same cohort (same Major, Curriculum, Academic Year,
        // Semester, Year Level) already has this exact Subject fully
        // scheduled, surface that Faculty+Room+copied-duration
        // combination as the TOP Combined suggestion (and pin it atop
        // Faculty/Room individually too), ahead of whatever the
        // general ranking would otherwise put first. This never
        // removes the other ranked options — the Registrar still sees
        // and can pick any of them — it only makes the "match what
        // the sibling section already does" option the obvious
        // default. See SiblingSectionPatternService for the full
        // rationale.
        $this->applySiblingPatternBoost($sectionSubject, $faculty, $room, $combined);

        // Exposed so the Recommend drawer can offer a "Why wasn't a
        // sibling section's schedule copied?" explainer even when a
        // match WAS eventually found via the general engine — the
        // Registrar can see every donor considered and every Day
        // candidate tried against it. Empty when no sibling donor
        // exists at all (nothing to explain).
        $siblingDiagnostics = $this->siblingPatternService->getDiagnostics();

        return compact('faculty', 'room', 'time', 'combined', 'siblingDiagnostics');
    }

    /**
     * Mutates $faculty/$room/$combined in place, pinning the sibling
     * pattern (if any usable one exists) to the front of each list.
     */
    private function applySiblingPatternBoost(SectionSubject $sectionSubject, array &$faculty, array &$room, array &$combined): void
    {
        $pattern = $this->siblingPatternService->findPattern($sectionSubject);

        if (! $pattern) {
            return;
        }

        $facultyModel = \App\Models\Faculty::find($pattern['faculty_id']);
        $roomModel = \App\Models\Room::find($pattern['room_id']);

        if (! $facultyModel || ! $roomModel) {
            return;
        }

        $note = "Matches {$pattern['donor_section_code']}, which already teaches this subject with this faculty, room, and duration.";
        $reasonObjects = [['label' => $note, 'met' => true]];

        $facultyEntry = [
            'id' => $facultyModel->id,
            'name' => $facultyModel->full_name,
            'faculty_category' => $facultyModel->faculty_category ?? null,
            'employment_type' => $facultyModel->employment_type ?? null,
            'current_load' => null,
            'max_teaching_units' => null,
            'score' => 100,
            'score_max' => 100,
            'confidence' => 'High',
            'reasons' => $reasonObjects,
            'is_sibling_pattern' => true,
        ];

        $roomEntry = [
            'id' => $roomModel->id,
            'name' => $roomModel->room_name,
            'room_category' => $roomModel->room_category ?? null,
            'room_type' => $roomModel->room_type ?? null,
            'capacity' => $roomModel->capacity ?? null,
            'department' => null,
            'score' => 100,
            'score_max' => 100,
            'confidence' => 'High',
            'reasons' => $reasonObjects,
            'is_sibling_pattern' => true,
        ];

        $faculty['recommendations'] = $this->pinToFront($faculty['recommendations'] ?? [], $facultyEntry);
        $room['recommendations'] = $this->pinToFront($room['recommendations'] ?? [], $roomEntry);

        $combinedEntry = [
            'faculty' => $facultyEntry,
            'room' => $roomEntry,
            'time' => [
                'days' => $pattern['days'],
                'start_time' => $pattern['start_time'],
                'end_time' => $pattern['end_time'],
                'score' => 100,
                'score_max' => 100,
                'confidence' => 'High',
                'reasons' => $reasonObjects,
            ],
            'score' => 100,
            'score_max' => 100,
            'confidence' => 'High',
            'conflict' => null,
            'is_sibling_pattern' => true,
            'pattern_source' => [
                'donor_section_id' => $pattern['donor_section_id'],
                'donor_section_code' => $pattern['donor_section_code'],
            ],
        ];

        $combined['recommendations'] = array_merge(
            [$combinedEntry],
            $combined['recommendations'] ?? []
        );
    }

    /**
     * Removes any existing entry with the same id (avoids a literal
     * duplicate row) and inserts $entry at index 0.
     */
    private function pinToFront(array $recommendations, array $entry): array
    {
        $filtered = array_values(array_filter(
            $recommendations,
            fn (array $existing) => ($existing['id'] ?? null) !== $entry['id']
        ));

        array_unshift($filtered, $entry);

        return $filtered;
    }

    private const MAX_COMBINED_RESULTS = 5;

    private const COMBINED_CANDIDATE_FACULTY = 3;

    private const COMBINED_CANDIDATE_ROOMS = 3;

    /**
     * COMBINED RECOMMENDATION.
     *
     * Pairs the top-ranked Faculty with the top-ranked Rooms and, for
     * each pairing, asks recommendTimes() (which itself calls
     * ScheduleConflictService) for the single best conflict-free Time
     * slot for that exact Faculty + Room combination. Pairings with no
     * available Time slot are simply dropped — a Combined
     * Recommendation is never shown unless it is fully conflict-free
     * across Faculty, Room, Section, and Time simultaneously.
     */
    private function buildCombinedRecommendations(
        Subject $subject,
        Section $section,
        array $facultyList,
        array $roomList,
        ?SectionSubject $current = null
    ): array {
        if (empty($facultyList) || empty($roomList)) {
            return ['recommendations' => [], 'message' => 'Not enough qualified faculty or available rooms to build combined suggestions.'];
        }

        $combos = [];

        foreach (array_slice($facultyList, 0, self::COMBINED_CANDIDATE_FACULTY) as $facultyRec) {
            foreach (array_slice($roomList, 0, self::COMBINED_CANDIDATE_ROOMS) as $roomRec) {
                $timeResult = $this->recommendTimes($subject, $section, $facultyRec['id'], $roomRec['id'], $current);
                $bestTime = $timeResult['recommendations'][0] ?? null;

                if (! $bestTime) {
                    // No conflict-free slot exists for this exact
                    // Faculty + Room pairing — never surfaced.
                    continue;
                }

                $combinedScore = (int) round(($facultyRec['score'] + $roomRec['score'] + $bestTime['score']) / 3);

                $combos[] = [
                    'faculty' => [
                        'id' => $facultyRec['id'],
                        'name' => $facultyRec['name'],
                        'score' => $facultyRec['score'],
                    ],
                    'room' => [
                        'id' => $roomRec['id'],
                        'name' => $roomRec['name'],
                        'score' => $roomRec['score'],
                    ],
                    'time' => [
                        'days' => $bestTime['days'],
                        'start_time' => $bestTime['start_time'],
                        'end_time' => $bestTime['end_time'],
                        'score' => $bestTime['score'],
                    ],
                    'score' => $combinedScore,
                    'score_max' => 100,
                    'conflict' => null,
                ];
            }
        }

        if (empty($combos)) {
            return ['recommendations' => [], 'message' => 'No fully conflict-free combined schedule could be generated.'];
        }

        // RANK — highest combined score first; ties broken by the
        // higher-scoring Faculty pick (units/qualification carry more
        // weight than Room fit for the Registrar's purposes).
        usort($combos, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $b['faculty']['score'] <=> $a['faculty']['score'];
        });

        $combos = array_slice($combos, 0, self::MAX_COMBINED_RESULTS);

        foreach ($combos as &$c) {
            $c['confidence'] = $this->confidenceFromScore($c['score']);
        }
        unset($c);

        return ['recommendations' => $combos, 'message' => null];
    }

    /**
     * FACULTY SELECTION — qualification-first, ownership-second
     * strategy.
     *
     * The question asked, in order, is "who's explicitly qualified to
     * teach this?" before "who owns this subject academically?":
     *
     *   LEVEL 1 — Explicit Teaching Qualification (any College)
     *     Active faculty explicitly linked to this exact Subject via
     *     Teaching Qualifications, regardless of which College they
     *     belong to. An explicit Teaching Qualification is the
     *     strongest signal available — the Registrar/Dean has
     *     recorded, by name, "this faculty member can teach this
     *     Subject" — so it is never subordinated to a same-College
     *     candidate who merely happens to belong to the right pool
     *     but has no such record (mirrors
     *     SectionSubject::faculty_mismatch, which likewise never
     *     flags a mismatch for an explicit Teaching Qualification
     *     link regardless of College).
     *     Ranked by: No Schedule Conflicts, Lowest Teaching Load,
     *     Full-time before Part-time.
     *
     *   LEVEL 2 — Owning College/GenEd Pool (no Teaching Qualification
     *     required)
     *     Only reached when Level 1 returns zero candidates. For a
     *     Major/Professional Subject, Active faculty whose own
     *     `college_id` matches the Subject's owning College (via
     *     Subject -> Major -> Department -> College). For a General
     *     Education/Minor Subject, Active faculty with no College of
     *     their own. Being in the right pool without a recorded
     *     Teaching Qualification is still a reasonable candidate —
     *     just ranked below anyone with an explicit qualification.
     *
     *   LEVEL 5 — Manual Scheduling
     *     No faculty eligible at either tier -> empty recommendations
     *     with an explanatory message. The Auto Generate engine marks
     *     the subject as requiring manual scheduling; nothing is
     *     auto-assigned.
     *
     * Both the human-facing selector (this method) and Auto Schedule
     * (AutoScheduleService, via the same call) share this exact
     * eligibility logic — there is only ever one ranked candidate list
     * for a given Subject/Section, never a looser "any available
     * faculty" version for the automatic path.
     */
    private const TQ_POINTS_QUALIFIED = 25;

    private const TQ_POINTS_NO_CONFLICT = 50;

    private const TQ_POINTS_LOW_LOAD = 15;

    private const TQ_POINTS_FULL_TIME = 10;

    private const COLLEGE_POINTS_MATCH = 30;

    private const COLLEGE_POINTS_NO_CONFLICT = 45;

    private const COLLEGE_POINTS_LOW_LOAD = 15;

    private const COLLEGE_POINTS_FULL_TIME = 10;

    /**
     * General Education Match uses the same weight distribution as
     * College Match (they're both "owning academic home" tiers) —
     * kept as separate constants only so the two can be tuned
     * independently later without touching each other.
     */
    private const GENED_POINTS_MATCH = 30;

    private const GENED_POINTS_NO_CONFLICT = 45;

    private const GENED_POINTS_LOW_LOAD = 15;

    private const GENED_POINTS_FULL_TIME = 10;

    /**
     * Manual Override carries no "match" points at all — the
     * Registrar has deliberately stepped outside every recommended
     * tier, so the category criterion scores 0 and the Live Score
     * reflects only the faculty's own Load/Conflict standing.
     */
    private const OVERRIDE_POINTS_MATCH = 0;

    private const OVERRIDE_POINTS_NO_CONFLICT = 45;

    private const OVERRIDE_POINTS_LOW_LOAD = 15;

    private const OVERRIDE_POINTS_FULL_TIME = 10;

    /**
     * @param  bool  $requireQualified  Kept for API compatibility with
     *                                  existing callers (AutoScheduleService
     *                                  passes `true` to make the "never
     *                                  auto-assign an unqualified faculty
     *                                  member" constraint explicit at the
     *                                  call site). Every tier this method
     *                                  returns is already Teaching-
     *                                  Qualification-gated — College match
     *                                  alone was never sufficient — so this
     *                                  flag no longer changes the result;
     *                                  it's accepted and ignored rather than
     *                                  removed, to avoid a breaking-change
     *                                  ripple through every caller.
     */
    public function recommendFaculty(Subject $subject, Section $section, ?SectionSubject $current = null, bool $requireQualified = false): array
    {
        $collegeId = $this->subjectCollegeId($subject);
        $primaryTier = $collegeId !== null ? 'college_match' : 'general_education_match';

        // LEVEL 1 — explicit Teaching Qualification, ANY college. An
        // explicit Teaching Qualification link is the strongest
        // possible signal the Registrar/Dean has recorded — "this
        // named faculty member is qualified to teach this exact
        // Subject" — and it stands on its own regardless of which
        // College that faculty happens to belong to (mirrors
        // SectionSubject::faculty_mismatch, which never flags a
        // Faculty Mismatch for someone with an explicit Teaching
        // Qualification, college match or not). Previously this tier
        // also required a college/GenEd-pool match, which meant a
        // qualified-but-cross-college faculty member (e.g. a College
        // of Teacher Education faculty explicitly qualified to teach
        // a General Education subject) got buried behind unqualified
        // same-pool faculty at Level 2 below, or never surfaced at
        // all outside a "fallback" notice.
        $primaryFaculty = Faculty::query()
            ->where('status', 'Active')
            ->whereHas('subjects', fn ($q) => $q->where('subjects.id', $subject->id))
            ->with(['subjects:id'])
            ->get();

        $primaryRanked = $primaryFaculty->isNotEmpty()
            ? $this->rankFacultyCandidates($primaryFaculty, $subject, $current, tier: 'teaching_qualification')
            : [];

        if (! empty($primaryRanked)) {
            return [
                'recommendations' => array_slice($primaryRanked, 0, self::MAX_FACULTY_RESULTS),
                'message' => null,
                'tier' => 'teaching_qualification',
                'fallback' => false,
                'fallback_reason' => null,
            ];
        }

        // LEVEL 2 — SAME College/GenEd pool as Level 1, but WITHOUT
        // requiring an explicit Teaching Qualification link. A BSIT
        // (Major) Subject is still owned by the College of Computer
        // Studies, a BSED (Major) Subject by the College of Teacher
        // Education, a BSCRIM (Major) Subject by the College of
        // Criminology, and so on — every Major/Professional Subject
        // belongs to the Section's own College, and any Active
        // faculty member in that same College is a reasonable
        // scheduling candidate even before the Registrar has gotten
        // around to recording a Teaching Qualification for them on
        // the Faculty page. Same idea for a GenEd/Minor Subject and
        // the College-less General Education pool. This is exactly
        // the "College Match"/"General Education Match" scoring tier
        // rankFacultyCandidates() already supports (see
        // 'selected_by_college_match' below) — it just wasn't
        // reachable before without a Teaching Qualification record.
        // $requireQualified exists for callers (none currently) that
        // must never accept a non-TQ pick; Auto Generate Schedule
        // intentionally DOES fall through to this level, same as the
        // manual Faculty dropdown does.
        if (! $requireQualified) {
            $collegeQuery = Faculty::query()
                ->where('status', 'Active')
                ->with(['subjects:id']);

            // The GenEd pool is "no College" faculty PLUS faculty from
            // any College that has opted in via counts_as_gened (e.g.
            // College of Teacher Education faculty who teach most of
            // this school's GenEd/Minor load despite belonging to a
            // real College). See colleges.counts_as_gened.
            $collegeQuery = $collegeId !== null
                ? $collegeQuery->where('college_id', $collegeId)
                : $collegeQuery->where(fn ($q) => $q->whereNull('college_id')
                    ->orWhereHas('college', fn ($c) => $c->where('counts_as_gened', true)));

            $collegeFaculty = $collegeQuery->get();

            $collegeRanked = $collegeFaculty->isNotEmpty()
                ? $this->rankFacultyCandidates($collegeFaculty, $subject, $current, tier: $primaryTier)
                : [];

            if (! empty($collegeRanked)) {
                return [
                    'recommendations' => array_slice($collegeRanked, 0, self::MAX_FACULTY_RESULTS),
                    'message' => null,
                    'tier' => $primaryTier,
                    'fallback' => false,
                    'fallback_reason' => null,
                ];
            }
        }

        // LEVEL 5 — nobody eligible at any tier: no Active faculty
        // exists in this Subject's own College/GenEd pool at all
        // (Level 2 already covers "College has faculty, just no TQ
        // recorded yet"), and no Active+TQ'd faculty exists anywhere
        // else either (Level 1 already searched every College, not
        // just the Subject's own). The Registrar (or Auto Generate
        // Schedule) must assign this one manually — likely by adding
        // a faculty member to that College on the Faculty page first.
        return [
            'recommendations' => [],
            'message' => $collegeId !== null
                ? 'No Active faculty exists in this Subject\'s own College, and no qualified faculty exists in any other College either.'
                : 'No Active General Education faculty exists, and no qualified faculty exists in any College either.',
            'tier' => null,
            'fallback' => false,
            'fallback_reason' => null,
        ];
    }

    /**
     * FACULTY RECOMMENDATION SELECTOR (Prompt 8.11).
     *
     * Powers the interactive Faculty selector on the Auto Generate
     * review panel. Returns the same ranked recommendation list
     * recommendFaculty() produces (so "recommended first, AI pick
     * defaulted" always matches what Auto Generate itself chose),
     * plus — when the Registrar types into the search box — a
     * global search across every Active faculty member regardless
     * of College/Teaching Qualification, each scored via
     * scoreArbitraryFaculty() so the dropdown can show a live
     * Recommendation Score even for out-of-pool candidates.
     */
    public function facultyOptionsForSelector(Subject $subject, Section $section, ?SectionSubject $current, ?string $search = null): array
    {
        $recommended = $this->recommendFaculty($subject, $section, $current);

        $searchResults = [];

        if ($search !== null && trim($search) !== '') {
            $recommendedIds = collect($recommended['recommendations'])->pluck('id')->all();

            $matches = Faculty::query()
                ->where('status', 'Active')
                ->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('faculty_id', 'like', "%{$search}%");
                })
                ->whereNotIn('id', $recommendedIds)
                // FIX: 'counts_as_gened' MUST be selected here — this
                // partial eager load previously only pulled
                // id/name/short_name, so scoreArbitraryFaculty()'s
                // $faculty->college?->counts_as_gened read below always
                // came back null (falsy) regardless of what a College
                // was actually flagged as in Academic Structure. That
                // silently defeated the "Counts as General Education
                // provider" setting for every faculty search result,
                // mislabeling a legitimate GenEd-provider match as a
                // "Manual Override" needing correction.
                ->with(['subjects:id', 'college:id,name,short_name,counts_as_gened'])
                ->limit(20)
                ->get();

            $searchResults = $matches
                ->map(fn (Faculty $faculty) => $this->scoreArbitraryFaculty($faculty, $subject, $section, $current))
                ->sortByDesc('score')
                ->values()
                ->all();
        }

        return [
            'recommended' => $recommended['recommendations'],
            'tier' => $recommended['tier'],
            'message' => $recommended['message'],
            'search_results' => $searchResults,
        ];
    }

    /**
     * Score ANY single faculty member against a Subject/Section, used
     * both for global search results and for recomputing the Live
     * Score the instant the Registrar manually overrides the
     * AI-selected faculty with someone outside the recommended pool.
     *
     * Badge/tier resolution (Prompt 8.11 spec):
     *   - Explicitly linked via Teaching Qualifications -> "Qualified Faculty"
     *   - No Teaching Qualifications anywhere for the Subject, Subject
     *     is Major, faculty's College matches the curriculum's owning
     *     College -> "College Match"
     *   - No Teaching Qualifications, Subject is General Education (or
     *     has no owning College — Minor subjects fall back to the same
     *     GenEd-style pool per Faculty::college_id being null for
     *     GenEd/Minor faculty), faculty has no College of their own -> "General Education Match"
     *   - None of the above -> "Manual Override": the Registrar
     *     deliberately picked someone outside every recommended tier.
     */
    public function scoreArbitraryFaculty(Faculty $faculty, Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $subject->loadMissing('major.department');
        // FIX: same missing-column bug as the search-results eager
        // load above — 'counts_as_gened' MUST be included here too,
        // or $faculty->college?->counts_as_gened on the next line
        // always evaluates false, silently ignoring the College's
        // "Counts as General Education provider" setting and
        // mislabeling that faculty's GenEd assignment as a
        // "Manual Override" (with the scary yellow warning) even
        // when it's a fully legitimate, intended match. This is what
        // caused GARCIA, VIRRA FLOR T. (College of Teacher Education,
        // flagged as a GenEd provider) to show "Manual Override" on
        // Art Appreciation (a General Education subject) during Auto
        // Generate, instead of "General Education Match".
        $faculty->loadMissing(['subjects:id', 'college:id,name,short_name,counts_as_gened']);

        $isQualified = $faculty->subjects->contains('id', $subject->id);
        $collegeId = $this->subjectCollegeId($subject);
        $isGenEdSubject = $collegeId === null;
        $isCollegeMatch = ! $isGenEdSubject && $faculty->college_id === $collegeId;
        $isGenEdMatch = $isGenEdSubject && ($faculty->college_id === null || (bool) $faculty->college?->counts_as_gened);

        $tier = match (true) {
            $isQualified => 'teaching_qualification',
            $isCollegeMatch => 'college_match',
            $isGenEdMatch => 'general_education_match',
            default => 'manual_override',
        };

        $badge = match ($tier) {
            'teaching_qualification' => 'Qualified Faculty',
            'college_match' => 'College Match',
            'general_education_match' => 'General Education Match',
            default => 'Manual Override',
        };

        $scored = $this->scoreCandidate($faculty, $subject, $current, $tier);

        $overrideReason = null;
        if ($tier === 'manual_override') {
            $facultyCollege = $faculty->college?->name ?? 'General Education / no College';
            $subjectCollege = $collegeId !== null
                ? ($subject->major?->department?->college?->name ?? 'the curriculum\'s owning College')
                : 'General Education faculty';

            $overrideReason = $collegeId !== null
                ? "Selected faculty belongs to {$facultyCollege}. This subject is recommended for {$subjectCollege}."
                : "Selected faculty belongs to {$facultyCollege}. This subject is a General Education subject and is recommended for General Education faculty.";
        }

        return array_merge($scored, [
            'college' => $faculty->college?->short_name ?? $faculty->college?->name ?? 'General Education',
            'employment_type' => $faculty->employment_type,
            'status' => $faculty->status,
            'badge' => $badge,
            'manual_override' => $tier === 'manual_override',
            'override_reason' => $overrideReason,
        ]);
    }

    /**
     * Shared ranking pass for the Priority 1 (Teaching Qualification),
     * Priority 2 (College Match), and Priority 2b (General Education
     * Match) candidate pools — the criteria checked (Conflicts,
     * Conflicts, Load, Full-time, Preferred Block) are identical in
     * kind, only the point weights and whether "Teaching
     * Qualification"/"College Match"/"General Education
     * Match"/"Preferred Block" apply differ between tiers.
     *
     * @param  \Illuminate\Support\Collection<int, Faculty>  $candidates
     */
    private function rankFacultyCandidates(
        $candidates,
        Subject $subject,
        ?SectionSubject $current,
        string $tier
    ): array {
        $isTeachingQualification = $tier === 'teaching_qualification';
        $isGenEdMatch = $tier === 'general_education_match';

        // Point weights differ per tier (Teaching Qualification / College
        // Match / General Education Match); the criteria checked are the
        // same in kind for all three, so pick the max-points-per-criterion
        // set up front and share one scoring pass.
        [$noConflictMax, $lowLoadMax, $fullTimeMax] = match ($tier) {
            'teaching_qualification' => [self::TQ_POINTS_NO_CONFLICT, self::TQ_POINTS_LOW_LOAD, self::TQ_POINTS_FULL_TIME],
            'general_education_match' => [self::GENED_POINTS_NO_CONFLICT, self::GENED_POINTS_LOW_LOAD, self::GENED_POINTS_FULL_TIME],
            default => [self::COLLEGE_POINTS_NO_CONFLICT, self::COLLEGE_POINTS_LOW_LOAD, self::COLLEGE_POINTS_FULL_TIME],
        };

        $ranked = $candidates->map(function (Faculty $faculty) use ($subject, $current, $isTeachingQualification, $isGenEdMatch, $tier, $noConflictMax, $lowLoadMax, $fullTimeMax) {
            $currentLoad = $this->currentTeachingLoad($faculty, $current);
            $loadRatio = $faculty->max_teaching_units > 0 ? $currentLoad / $faculty->max_teaching_units : 0;
            $wouldExceedLoad = $this->workloadService->wouldExceed($faculty, $subject, $current?->id);
            $remainingLoad = $this->workloadService->maxLoad($faculty) > 0
                ? $this->workloadService->maxLoad($faculty) - $currentLoad
                : null;

            $conflictCount = $this->facultyConflictCount($faculty, $current);

            $isFullTime = $faculty->employment_type === 'Full-time';

            $noConflictPoints = $conflictCount === 0 ? $noConflictMax : 0;

            // Lowest current load scales smoothly: a completely free
            // faculty member earns full points, one already at their
            // max_teaching_units earns none.
            $lowLoadPoints = (int) round($lowLoadMax * (1 - min($loadRatio, 1)));

            $fullTimePoints = $isFullTime ? $fullTimeMax : 0;

            $points = [];
            $reasons = [];

            if ($isTeachingQualification) {
                $points['teaching_qualification'] = self::TQ_POINTS_QUALIFIED;
                $reasons[] = ['label' => 'Teaching Qualification', 'met' => true];
            } elseif ($isGenEdMatch) {
                $points['general_education_match'] = self::GENED_POINTS_MATCH;
                $reasons[] = ['label' => 'General Education Match', 'met' => true];
            } else {
                $points['college_match'] = self::COLLEGE_POINTS_MATCH;
                $reasons[] = ['label' => 'College Match', 'met' => true];
            }

            $points['no_conflict'] = $noConflictPoints;
            $reasons[] = ['label' => 'No Schedule Conflicts', 'met' => $conflictCount === 0];

            $points['low_load'] = $lowLoadPoints;
            $reasons[] = ['label' => 'Lowest Teaching Load', 'met' => $loadRatio < 0.5];

            $points['full_time'] = $fullTimePoints;
            $reasons[] = ['label' => 'Full-time', 'met' => $isFullTime];

            // Teaching Load Limit — surfaced as a reason (not a point
            // criterion; it's a hard-cap flag, not a scoring nudge) so
            // the Registrar sees "✗ Higher Teaching Load" exactly like
            // the recommendation card spec, even though ranking itself
            // never fully excludes an overloaded candidate here — the
            // hard reject/remove happens in AutoScheduleService (Auto
            // Generate) and SectionSubjectController (Manual/Save),
            // which both call FacultyWorkloadService directly.
            $reasons[] = ['label' => $wouldExceedLoad ? 'Higher Teaching Load' : 'Within Teaching Load Limit', 'met' => ! $wouldExceedLoad];

            $score = array_sum($points);

            return [
                'id' => $faculty->id,
                'name' => $faculty->full_name,
                'faculty_category' => $faculty->faculty_category,
                'employment_type' => $faculty->employment_type,
                'is_full_time' => $isFullTime,
                'current_load' => $currentLoad,
                'max_teaching_units' => $faculty->max_teaching_units,
                'remaining_load' => $remainingLoad,
                'exceeds_max_load' => $wouldExceedLoad,
                'load_ratio' => $loadRatio,
                'conflict_count' => $conflictCount,
                'tier' => $tier,
                'selected_by_college_match' => $tier === 'college_match',
                'selected_by_general_education_match' => $isGenEdMatch,
                'score' => $score,
                'score_max' => 100,
                'score_breakdown' => $points,
                'reasons' => $reasons,
            ];
        })->values()->all();

        // RANK BY SCORE — highest Recommendation Score first. Ties are
        // broken by lowest current teaching load, then fewest
        // scheduling conflicts.
        usort($ranked, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['current_load'] !== $b['current_load']) {
                return $a['current_load'] <=> $b['current_load'];
            }

            return $a['conflict_count'] <=> $b['conflict_count'];
        });

        $ranked = array_slice($ranked, 0, self::MAX_FACULTY_RESULTS);

        foreach ($ranked as &$item) {
            $item['confidence'] = $this->confidenceFromScore($item['score']);
        }
        unset($item);

        return $ranked;
    }

    /**
     * Single-candidate version of the scoring pass rankFacultyCandidates()
     * runs over a whole pool — same criteria (Conflicts, Load,
     * Full-time), generalized across all four tiers
     * ('teaching_qualification', 'college_match',
     * 'general_education_match', 'manual_override') so the Faculty
     * Recommendation Selector's Live Score always uses the exact same
     * math as the initial Auto Generate ranking, no matter which tier
     * the currently-selected faculty falls into.
     */
    private function scoreCandidate(Faculty $faculty, Subject $subject, ?SectionSubject $current, string $tier): array
    {
        $currentLoad = $this->currentTeachingLoad($faculty, $current);
        $loadRatio = $faculty->max_teaching_units > 0 ? $currentLoad / $faculty->max_teaching_units : 0;
        $wouldExceedLoad = $this->workloadService->wouldExceed($faculty, $subject, $current?->id);
        $remainingLoad = $this->workloadService->maxLoad($faculty) > 0
            ? $this->workloadService->maxLoad($faculty) - $currentLoad
            : null;

        $conflictCount = $this->facultyConflictCount($faculty, $current);

        $isFullTime = $faculty->employment_type === 'Full-time';

        [$matchPoints, $noConflictPointsMax, $lowLoadMax, $fullTimeMax, $matchLabel] = match ($tier) {
            'teaching_qualification' => [self::TQ_POINTS_QUALIFIED, self::TQ_POINTS_NO_CONFLICT, self::TQ_POINTS_LOW_LOAD, self::TQ_POINTS_FULL_TIME, 'Teaching Qualification'],
            'college_match' => [self::COLLEGE_POINTS_MATCH, self::COLLEGE_POINTS_NO_CONFLICT, self::COLLEGE_POINTS_LOW_LOAD, self::COLLEGE_POINTS_FULL_TIME, 'College Match'],
            'general_education_match' => [self::GENED_POINTS_MATCH, self::GENED_POINTS_NO_CONFLICT, self::GENED_POINTS_LOW_LOAD, self::GENED_POINTS_FULL_TIME, 'General Education Match'],
            default => [self::OVERRIDE_POINTS_MATCH, self::OVERRIDE_POINTS_NO_CONFLICT, self::OVERRIDE_POINTS_LOW_LOAD, self::OVERRIDE_POINTS_FULL_TIME, 'Manual Override'],
        };

        $noConflictPoints = $conflictCount === 0 ? $noConflictPointsMax : 0;
        $lowLoadPoints = (int) round($lowLoadMax * (1 - min($loadRatio, 1)));
        $fullTimePoints = $isFullTime ? $fullTimeMax : 0;

        $points = ['match' => $matchPoints];
        $reasons = [['label' => $matchLabel, 'met' => $tier !== 'manual_override']];

        $points['no_conflict'] = $noConflictPoints;
        $reasons[] = ['label' => 'No Schedule Conflict', 'met' => $conflictCount === 0];

        $points['low_load'] = $lowLoadPoints;
        $reasons[] = ['label' => 'Lowest Teaching Load', 'met' => $loadRatio < 0.5];

        $points['full_time'] = $fullTimePoints;
        $reasons[] = ['label' => 'Full-time', 'met' => $isFullTime];

        $reasons[] = ['label' => $wouldExceedLoad ? 'Higher Teaching Load' : 'Within Teaching Load Limit', 'met' => ! $wouldExceedLoad];

        $score = array_sum($points);

        return [
            'id' => $faculty->id,
            'name' => $faculty->full_name,
            'faculty_category' => $faculty->faculty_category,
            'employment_type' => $faculty->employment_type,
            'is_full_time' => $isFullTime,
            'current_load' => $currentLoad,
            'max_teaching_units' => $faculty->max_teaching_units,
            'remaining_load' => $remainingLoad,
            'exceeds_max_load' => $wouldExceedLoad,
            'load_ratio' => $loadRatio,
            'conflict_count' => $conflictCount,
            'tier' => $tier,
            'selected_by_college_match' => $tier === 'college_match',
            'score' => $score,
            'score_max' => 100,
            'score_breakdown' => $points,
            'reasons' => $reasons,
            'confidence' => $this->confidenceFromScore($score),
        ];
    }

    /**
     * A Faculty member's committed teaching load right now, used both
     * for the "Lowest Teaching Load" ranking criterion and as the hard
     * teaching-load cap AutoScheduleService enforces.
     *
     * Delegates to FacultyWorkloadService — the single source of truth
     * for workload math shared by Auto Generate, Recommend Faculty,
     * Manual Assignment, Save Schedule, and the Faculty Master's
     * Workload tab/Dashboard Indicators — rather than summing here
     * directly, so this number can never drift out of sync with the
     * one those other integration points compute.
     *
     * Scoped to the currently ACTIVE School Year + Semester only (per
     * FacultyWorkloadService/ScheduleConflictService::activeSemesterSectionIds()):
     * a Faculty member's load from a past or future semester never
     * counts against their current-term Maximum Teaching Load.
     *
     * Counts both 'Scheduled' AND 'Draft' rows — never 'Conflict'.
     * Draft is included deliberately: Auto Schedule's generateOne()
     * persists each accepted assignment with status = 'Draft' (it only
     * becomes 'Scheduled' once the Registrar clicks "Accept All &
     * Save"). If Draft rows were excluded here, every subject
     * processed later in the *same* Auto Schedule run would still see
     * an earlier-assigned faculty member as having zero load from the
     * subjects just handed to them moments ago — which is exactly why
     * one faculty member (e.g. the sole Teaching-Qualification/College
     * Match candidate) kept winning "Lowest Teaching Load" for every
     * subject in a batch instead of the load rotating across other
     * eligible faculty as their load actually grows.
     */
    private function currentTeachingLoad(Faculty $faculty, ?SectionSubject $current): int
    {
        return $this->workloadService->currentLoad($faculty, $current?->id);
    }

    /**
     * The College that owns a Subject — via Subject -> Major ->
     * Department -> College, the same chain the Curriculum for that
     * Major belongs to (a Curriculum's `major_id` always matches its
     * Subjects' `major_id`, so "the curriculum's college" and "the
     * subject's college" are one and the same lookup here). Returns
     * null for General Education subjects, which have no Major/College
     * of their own — College Matching simply doesn't apply to them.
     */
    /**
     * Weekly hours this specific row must fit, accounting for
     * SPLIT-DELIVERY SCHEDULING — a split row (component !== 'combined')
     * only needs to add up to ITS OWN split_hours share, not the
     * Subject's full weekly total (e.g. the Face-to-Face half of a
     * 5-hr Subject split into 4 F2F + 1 Online should size its
     * sessions against 4, not 5). Same rule
     * SectionSubjectController::update()'s "Weekly Hours Mismatch"
     * check already applies when validating a manual save — kept here
     * so every recommendation/scoring path in this service agrees
     * with what that validation will accept, instead of Auto Generate
     * or Suggest Available Time sizing a split row's session off the
     * Subject's combined total and then never matching split_hours.
     */
    private function requiredHoursFor(Subject $subject, ?SectionSubject $current): int
    {
        $hours = $current?->split_hours
            ?? ((int) $subject->lecture_hours + (int) $subject->laboratory_hours);

        return $hours > 0 ? (int) $hours : 3;
    }

    private function subjectCollegeId(Subject $subject): ?int
    {
        $subject->loadMissing('major.department');

        return $subject->major?->department?->college_id;
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', substr($end, 0, 5)));

        return ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute);
    }

    private function minutesFromTime(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }

    /**
     * Shared Recommendation Score -> Badge mapping used for Faculty,
     * Room, and Time candidates alike, so "Best Match" / "Good Match"
     * / "Alternative" always means the same score range everywhere in
     * the UI.
     */
    private function confidenceFromScore(int $score): string
    {
        if ($score >= 85) {
            return 'Best Match';
        }

        if ($score >= 65) {
            return 'Good Match';
        }

        return 'Alternative';
    }

    /**
     * INTELLIGENT ROOM ASSIGNMENT — hierarchical strategy.
     *
     * A room only ever appears in the returned list if it clears three
     * hard requirements, evaluated in this order:
     *
     *   Priority 1 — Room Type MUST match the Subject (Lecture subjects
     *                only get Lecture rooms, Laboratory subjects only
     *                get Laboratory rooms). No match, no candidacy.
     *   Priority 5 — Capacity MUST be >= the estimated headcount.
     *   Priority 6 — Availability: the room must have no scheduling
     *                conflict at the row's current Day/Time (skipped
     *                — treated as neutral — when the row has no
     *                Day/Time yet, same as before).
     *
     * Rooms that fail any of the three are excluded outright — never
     * scored down, never shown ("do not recommend that room").
     *
     * Among the rooms that clear all three, a room is only a valid
     * candidate if it is scoped to the Section's own Program, the
     * Section's own College, or is a fully Shared room (no College/
     * Department at all). A room scoped to a *different* College is
     * excluded too — it was never meant for this Section. Candidates
     * are then tiered (Priorities 2–4):
     *
     *   Program tier (exact Department match) ......... base + 16 pts
     *   College tier  (same College, any Program) ...... base + 8 pts
     *   Shared tier   (no College/Department at all) ... base + 0 pts
     *
     * where base = Room Type (34) + Capacity (30) + Availability (20)
     * = 84, always earned in full since those three are hard filters
     * — every returned room already satisfies them. Score is therefore
     * 84 (Shared) / 92 (College) / 100 (Program).
     *
     * Kept modular: ROOM_POINTS_* constants and resolveRoomScopeTier()
     * are the only places that would need to change to add future
     * criteria (equipment, building proximity, air conditioning,
     * etc.) without touching the hard-filter or ranking logic.
     */
    /**
     * ROOM SCORE WEIGHTS — configurable percentages summing to 100,
     * matching the Smart Room Recommendation spec exactly. This is
     * the single place an administrator (or a future Settings screen)
     * would change to retune the balance between Availability, Room
     * Type, Capacity, College/Program match, Facilities, Utilization,
     * and Building convenience — recommendRooms() and
     * scoreArbitraryRoom() both read from here so they can never
     * drift out of sync with each other.
     *
     * "Facilities/Equipment" has no dedicated data in the Room Master
     * yet (no equipment table), so it is currently awarded in full
     * for every eligible room — once equipment tracking exists this
     * is the only line that needs to change. Same for "Distance /
     * Building Convenience": with no campus-map/travel-time data
     * available, it is awarded in full rather than guessed at.
     *
     * @var array<string,int>
     */
    private const ROOM_SCORE_WEIGHTS = [
        'availability' => 25,
        'room_type' => 20,
        'capacity' => 15,
        'scope' => 15,
        'facilities' => 10,
        'utilization' => 10,
        'convenience' => 5,
    ];

    private const ROOM_POINTS_TYPE_MATCH = 34;

    private const ROOM_POINTS_CAPACITY_OK = 30;

    private const ROOM_POINTS_AVAILABLE = 20;

    private const ROOM_POINTS_SCOPE_PROGRAM = 16;

    private const ROOM_POINTS_SCOPE_COLLEGE = 8;

    private const ROOM_POINTS_SCOPE_SHARED = 0;

    /**
     * Bonus points added on top of the weighted 100-point score when
     * the Subject has an explicit, active Room Recommendation
     * configured on the Room Details page (Room Recommendation &
     * Smart Auto-Scheduling). This is a SOFT preference bonus only —
     * applied after every hard filter has already passed, so it can
     * never make an otherwise-invalid room eligible, and a room
     * without the bonus can still outrank a recommended one on
     * availability/scope/utilization alone.
     */
    private const ROOM_RECOMMENDATION_BONUS = 12;

    /**
     * Weighted Room Score (out of 100) per ROOM_SCORE_WEIGHTS, shared
     * by recommendRooms() (the hard-filtered candidate pool) and
     * scoreArbitraryRoom() (search/manual-override scoring) so the
     * two can never compute a different score for the same room.
     *
     * @return array{score:int, breakdown:array<string,int>}
     */
    private function weightedRoomScore(
        bool $typeMatch,
        bool $available,
        bool $capacityOk,
        int $capacityNeeded,
        int $roomCapacity,
        string $tier,
        float $utilizationPercent,
    ): array {
        $w = self::ROOM_SCORE_WEIGHTS;

        // Capacity suitability — full credit for a right-sized room,
        // tapering down (never below 40% of the weight) the more the
        // room overshoots what's actually needed, so a 40-seat room
        // for a 10-student section scores lower than a 12-seat room
        // for the same section, without excluding either outright.
        $capacityPoints = 0;
        if ($capacityOk) {
            if ($capacityNeeded <= 0 || $roomCapacity <= 0) {
                $capacityPoints = $w['capacity'];
            } else {
                $overshoot = max(0, $roomCapacity - $capacityNeeded) / max(1, $capacityNeeded);
                $capacityPoints = (int) round($w['capacity'] * max(0.4, 1 - min(1, $overshoot / 2)));
            }
        }

        $scopePoints = match ($tier) {
            'program' => $w['scope'],
            'college' => (int) round($w['scope'] * 0.6),
            'shared' => (int) round($w['scope'] * 0.25),
            default => 0,
        };

        // Utilization balancing — the less-used room among otherwise
        // equal candidates earns more of this weight; a fully-booked
        // room (100%+) earns none of it, matching "Room A 95% / Room
        // B 40% -> prefer Room B" from the spec.
        $utilizationPoints = (int) round($w['utilization'] * (1 - min(1, max(0, $utilizationPercent) / 100)));

        $breakdown = [
            'availability' => $available ? $w['availability'] : 0,
            'room_type' => $typeMatch ? $w['room_type'] : 0,
            'capacity' => $capacityPoints,
            'scope' => $scopePoints,
            // No Equipment/Facilities data model exists yet — see the
            // ROOM_SCORE_WEIGHTS docblock — awarded in full for now.
            'facilities' => $w['facilities'],
            'utilization' => $utilizationPoints,
            // No building/travel-time data exists yet — see the
            // ROOM_SCORE_WEIGHTS docblock — awarded in full for now.
            'convenience' => $w['convenience'],
        ];

        return ['score' => array_sum($breakdown), 'breakdown' => $breakdown];
    }

    /**
     * One-sentence, human-readable explanation for why a room was
     * (or wasn't) the top recommendation — the "Why Room 306?" panel
     * from the spec.
     */
    /**
     * BUG FIX — display label for a Room. Previously concatenated
     * "{$code} — {$name}" (with a guard for when the two matched), but
     * every OTHER room dropdown in the app (Section Subjects' Room
     * select, the Rooms Master list, etc.) shows only `room_name` —
     * `room_code` is an internal, often auto-generated slug (e.g. a
     * CSV import can derive "ROOM306LAB1" from a name like "Room 306
     * (Lab 1)") that a Registrar never otherwise sees. Concatenating
     * it here made a Recommended room look like a completely
     * different room from the exact same one appearing lower in the
     * same dropdown's "Laboratory Rooms"/"Other Rooms" groups. Always
     * using `room_name` alone keeps every room label in the app
     * consistent, regardless of what its underlying code happens to be.
     */
    private function roomDisplayName(Room $room): string
    {
        return $room->room_name;
    }

    private function roomExplanation(string $roomLabel, bool $isTopPick, string $tier, float $utilizationPercent, int $score): string
    {
        if (! $isTopPick) {
            return "{$roomLabel} is a valid, conflict-free option but scored lower overall (".$score.'/100), mainly on scope match and current utilization.';
        }

        $scopeText = match ($tier) {
            'program' => 'matches the section\'s own program',
            'college' => 'is shared within the section\'s college',
            default => 'is a shared room open to every college/program',
        };

        return "Best overall match — {$roomLabel} is available at the selected schedule, has the correct room type, has sufficient capacity, {$scopeText}, ".
            "and is currently at only {$utilizationPercent}% utilization compared to other eligible rooms.";
    }

    /**
     * Green/Blue/Yellow/Red status per the spec's indicator legend:
     *   Green  = Recommended / fully compatible (the top pick)
     *   Blue   = Valid alternative
     *   Yellow = Valid but has a soft preference issue (e.g. shared
     *            with another college/program, oversized capacity)
     *   Red    = Hard conflict — never returned by recommendRooms(),
     *            only ever produced by scoreArbitraryRoom() for a
     *            Manual Override pick.
     */
    private function roomStatusColor(bool $isManualOverride, bool $isTopPick, string $tier): string
    {
        return match (true) {
            $isManualOverride => 'red',
            $isTopPick => 'green',
            $tier === 'program' => 'blue',
            default => 'yellow',
        };
    }

    /**
     * Active Room ids explicitly recommended for a Subject, per the
     * `room_subject_recommendations` table configured on the Room
     * Details page. Used purely as a scoring bonus — see
     * ROOM_RECOMMENDATION_BONUS.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function recommendedRoomIdsFor(Subject $subject): \Illuminate\Support\Collection
    {
        return $subject->recommendedRooms()->pluck('rooms.id');
    }

    /**
     * ROOM OWNERSHIP + CONTROLLED OVERRIDE — resolve the three-level
     * recommendation the Room Details "Recommended Subjects" UI and
     * the Auto Scheduler explanation panel both need:
     *
     *   Preferred  — an administrator explicitly recommended this
     *                Subject for this Room (room_subject_recommendations).
     *   Suitable   — no explicit recommendation, but the Room
     *                naturally matches the Subject's Program/College
     *                scope (tier is program/college/shared).
     *   Available  — technically usable (cleared every hard filter)
     *                but neither explicitly recommended nor a natural
     *                scope match (a different College's room).
     *
     * Also flags is_manual_override: true only when an explicit
     * recommendation crosses scope lines (Preferred + mismatch tier)
     * — i.e. an administrator intentionally recommending, say, an ITE
     * subject for a CCS laboratory. This is what earns the "Manual
     * Recommendation / Administrator Override" label rather than
     * pretending the Subject naturally belongs to that Room.
     *
     * @return array{level:string, is_manual_override:bool}
     */
    private function recommendationLevel(bool $isRecommended, string $tier): array
    {
        $naturalMatch = in_array($tier, ['program', 'college', 'shared'], true);

        return match (true) {
            $isRecommended => [
                'level' => 'preferred',
                'is_manual_override' => ! $naturalMatch,
            ],
            $naturalMatch => ['level' => 'suitable', 'is_manual_override' => false],
            default => ['level' => 'available', 'is_manual_override' => false],
        };
    }

    /**
     * Display label/color for a recommendation level, per the
     * 🟢 Preferred / 🟡 Suitable / ⚪ Available legend.
     *
     * @return array{label:string, color:string}
     */
    private function recommendationLevelMeta(string $level): array
    {
        return match ($level) {
            'preferred' => ['label' => 'Preferred', 'color' => 'green'],
            'suitable' => ['label' => 'Suitable', 'color' => 'yellow'],
            default => ['label' => 'Available', 'color' => 'gray'],
        };
    }

    public function recommendRooms(Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $section->loadMissing('major.department');

        $wantsLaboratory = (int) $subject->laboratory_hours > 0;
        $preferredType = $wantsLaboratory ? 'Laboratory' : 'Lecture';

        $sectionDepartmentId = $section->major?->department_id;
        $sectionCollegeId = $section->major?->department?->college_id;

        $capacityNeeded = $current?->capacity ?? $section->estimated_students ?? 0;

        // Explicit admin recommendations (room_subject_recommendations)
        // are resolved BEFORE the query so a recommended Room's Type/
        // Scope can be included even when it wouldn't otherwise match —
        // see the Type/Scope-bypass rule below.
        $recommendedRoomIds = $this->recommendedRoomIdsFor($subject);

        // SCOPE-AWARE QUERY — pushed down to SQL instead of loading
        // every Active room in the system and filtering Type/Scope in
        // PHP afterward. A room only comes back from the database at
        // all if it's the right Room Type (Lecture/Laboratory) AND
        // properly owned by this Section's Program, College, or fully
        // Shared (no department_id/college_id) — or explicitly
        // Administrator-recommended for this Subject, which is the one
        // case allowed to bypass both. This is what keeps a BSIT
        // Section's Auto Schedule run from ever even fetching a BSHM
        // Foods Lab or a Criminology room like "Ground Zero": those
        // rows never leave the database, they aren't just scored low.
        // Capacity and Conflict stay PHP-side below — Conflict needs
        // the placement data anyway, and Capacity is cheap once the
        // candidate pool is already this small.
        $allRooms = Room::query()
            ->where('status', 'Active')
            ->where(function ($query) use ($preferredType, $sectionDepartmentId, $sectionCollegeId, $recommendedRoomIds) {
                $query->where(function ($typeScope) use ($preferredType, $sectionDepartmentId, $sectionCollegeId) {
                    $typeScope->where('room_type', $preferredType)
                        ->where(function ($scope) use ($sectionDepartmentId, $sectionCollegeId) {
                            // Shared — no owning Department or College at all.
                            $scope->where(function ($shared) {
                                $shared->whereNull('department_id')->whereNull('college_id');
                            });

                            if ($sectionDepartmentId) {
                                $scope->orWhere('department_id', $sectionDepartmentId);
                            }

                            if ($sectionCollegeId) {
                                $scope->orWhere('college_id', $sectionCollegeId);
                            }
                        });
                });

                if ($recommendedRoomIds->isNotEmpty()) {
                    $query->orWhereIn('id', $recommendedRoomIds);
                }
            })
            ->with(['department', 'college'])
            ->get();

        if ($allRooms->isEmpty()) {
            // Nothing came back from the scope-aware query above — run
            // the cheap diagnostic counts ONLY on this (rare) empty
            // path, against every Active room regardless of scope, so
            // "No suitable room available" can still say *why* (wrong
            // Type vs. wrong Scope) without paying that cost on every
            // normal, successful recommendation.
            return [
                'recommendations' => [],
                'message' => 'No suitable room available.',
                'reasons' => $this->noRoomReasons(
                    $preferredType,
                    Room::where('status', 'Active')->where('room_type', '!=', $preferredType)->count(),
                    0,
                    0,
                    Room::where('status', 'Active')
                        ->where('room_type', $preferredType)
                        ->when($sectionDepartmentId, fn ($q) => $q->where('department_id', '!=', $sectionDepartmentId))
                        ->when($sectionCollegeId, fn ($q) => $q->where('college_id', '!=', $sectionCollegeId))
                        ->where(fn ($q) => $q->whereNotNull('department_id')->orWhereNotNull('college_id'))
                        ->count()
                ),
            ];
        }

        // --- Hard filters (Priorities 1, 2-4, 5, 6) -------------------
        // A room that fails any of these is never a candidate — EXCEPT
        // Type and Scope, which an explicit administrator recommendation
        // is now allowed to override (e.g. deliberately assigning a
        // Lecture subject to a Laboratory room, or pulling in a
        // cross-college room). Scope IS a hard filter for every other
        // room: a room scoped to a *different* College/Program (e.g. a
        // COC Criminology lab like "Ground Zero" or "Room 201 (Forensic
        // BSCRIM Lab)") must never be suggested for a BSIT/other-college
        // Subject just because it happens to be the right Room Type —
        // only Program tier, College tier, fully Shared (no College/
        // Department at all) rooms, or an explicit admin recommendation
        // are valid candidates. Capacity and Availability/Conflict
        // remain hard filters even for a recommended Room — an
        // explicit recommendation can never seat more students than
        // the room holds or double-book an occupied slot.
        $typeExcluded = 0;
        $capacityExcluded = 0;
        $conflictExcluded = 0;
        $scopeExcluded = 0;

        $eligible = $allRooms->filter(function (Room $room) use (
            $preferredType, $capacityNeeded, $current, $recommendedRoomIds,
            $sectionDepartmentId, $sectionCollegeId,
            &$typeExcluded, &$capacityExcluded, &$conflictExcluded, &$scopeExcluded,
        ) {
            $isRecommended = $recommendedRoomIds->contains($room->id);

            if (! $isRecommended && $room->room_type !== $preferredType) {
                $typeExcluded++;

                return false;
            }

            if (! $isRecommended && $this->resolveRoomScopeTier($room, $sectionDepartmentId, $sectionCollegeId) === 'mismatch') {
                $scopeExcluded++;

                return false;
            }

            if ($capacityNeeded && $room->capacity < $capacityNeeded) {
                $capacityExcluded++;

                return false;
            }

            if ($this->roomConflictCount($room, $current) > 0) {
                $conflictExcluded++;

                return false;
            }

            return true;
        })->values();

        if ($eligible->isEmpty()) {
            return [
                'recommendations' => [],
                'message' => 'No suitable room available.',
                'reasons' => $this->noRoomReasons($preferredType, $typeExcluded, $capacityExcluded, $conflictExcluded, $scopeExcluded),
            ];
        }

        // Used for the Closest Capacity Match tie-breaker among rooms
        // that already cleared the hard capacity filter — the room
        // whose capacity is nearest to what's needed sorts first
        // among equally-scored candidates, so a needlessly huge room
        // never outranks a right-sized one.
        $closestCapacity = $eligible->pluck('capacity')->min();

        // ROOM LOAD SPREADING — how many schedule slots each eligible
        // room is already carrying right now (across every Section,
        // not just this one). Rooms that clear all the same hard
        // filters and land on the same score would otherwise always
        // resolve in the same fixed order (e.g. Auto Generate always
        // picking "Room 304 (ICT Workshop)" for every BSIT Lab subject
        // even though Room 305/306 are equally eligible and sitting
        // empty). This is purely a tie-breaker — it never changes a
        // Room's score/eligibility, only which equally-good Room sorts
        // first, so utilization spreads across the whole eligible pool
        // (e.g. all "All Colleges" lecture rooms, or all of a
        // program's own labs) instead of monopolizing a single one.
        // Prefer real Room Utilization % (Scheduled Hours / Max
        // Available Hours from the Academic Calendar) over a raw
        // schedule-slot count — a room with three short slots isn't
        // necessarily busier than one with two long ones, and this
        // keeps the Auto Scheduler's tie-break reading the exact same
        // number the Rooms page shows as "X% Utilized".
        $utilization = app(RoomUtilizationService::class)->summarizeRooms($eligible);
        $usageCounts = $eligible->mapWithKeys(
            fn (Room $room) => [$room->id => $utilization[$room->id]['utilization_percent'] ?? 0]
        );

        $ranked = $eligible->map(function (Room $room) use (
            $sectionDepartmentId, $sectionCollegeId, $capacityNeeded, $usageCounts, $recommendedRoomIds, $preferredType, $utilization,
        ) {
            $tier = $this->resolveRoomScopeTier($room, $sectionDepartmentId, $sectionCollegeId);
            $isTypeOverride = $room->room_type !== $preferredType;
            $utilizationPercent = round((float) ($usageCounts[$room->id] ?? 0), 1);
            $scheduledHours = (float) ($utilization[$room->id]['scheduled_hours'] ?? 0);
            $maxHours = (float) ($utilization[$room->id]['max_hours'] ?? 0);

            // Every room reaching this point already cleared the hard
            // filters (Type/Capacity/Availability/Scope), so those
            // booleans are always true here — only the weighted score
            // varies by how WELL each room fits.
            $scored = $this->weightedRoomScore(
                typeMatch: true,
                available: true,
                capacityOk: true,
                capacityNeeded: $capacityNeeded,
                roomCapacity: $room->capacity,
                tier: $tier,
                utilizationPercent: $utilizationPercent,
            );

            // Room Recommendation bonus — a soft preference only,
            // applied after every hard filter already passed. Never
            // pushes the score above 100.
            $isRecommended = $recommendedRoomIds->contains($room->id);
            if ($isRecommended) {
                $scored['score'] = min(100, $scored['score'] + self::ROOM_RECOMMENDATION_BONUS);
                $scored['breakdown']['recommended'] = self::ROOM_RECOMMENDATION_BONUS;
            }

            $levelInfo = $this->recommendationLevel($isRecommended, $tier);
            $levelInfo['is_manual_override'] = $levelInfo['is_manual_override'] || ($isRecommended && $isTypeOverride);
            $levelMeta = $this->recommendationLevelMeta($levelInfo['level']);

            $reasons = [
                $isTypeOverride
                    ? ['label' => 'Room Type overridden by Administrator recommendation', 'met' => true, 'type' => 'warning']
                    : ['label' => 'Correct Room Type', 'met' => true, 'type' => 'success'],
            ];

            if ($isRecommended) {
                $reasons[] = [
                    'label' => $levelInfo['is_manual_override'] || $isTypeOverride
                        ? 'Administrator recommended (manual override)'
                        : 'Recommended for this Subject',
                    'met' => true,
                    'type' => 'success',
                ];
            }

            if ($tier === 'program') {
                $reasons[] = ['label' => 'Same Program', 'met' => true, 'type' => 'success'];
                $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
            } elseif ($tier === 'college') {
                $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
                $reasons[] = ['label' => 'Shared by all programs', 'met' => true, 'type' => 'warning'];
            } elseif ($tier === 'shared') {
                $reasons[] = ['label' => 'Shared Room', 'met' => true, 'type' => 'success'];
            } else {
                $reasons[] = [
                    'label' => $levelInfo['is_manual_override']
                        ? 'Subject belongs to another college — manual override'
                        : 'Different college — not scope-matched',
                    'met' => $levelInfo['is_manual_override'],
                    'type' => $levelInfo['is_manual_override'] ? 'warning' : 'danger',
                ];
            }

            $reasons[] = ['label' => 'Capacity OK', 'met' => true, 'type' => 'success'];
            $reasons[] = ['label' => 'Available', 'met' => true, 'type' => 'success'];
            $reasons[] = ['label' => "Room Utilization: {$utilizationPercent}%", 'met' => true, 'type' => $utilizationPercent >= 90 ? 'warning' : 'success'];

            return [
                'id' => $room->id,
                'name' => $this->roomDisplayName($room),
                'room_type' => $room->room_type,
                'room_category' => $room->room_category,
                'department' => $room->department?->name,
                'college' => $room->college?->name,
                'capacity' => $room->capacity,
                'match_tier' => $tier,
                'badge' => match (true) {
                    $levelInfo['is_manual_override'] => 'Administrator Override',
                    $isRecommended => 'Recommended Room',
                    $tier === 'program' => 'Program Match',
                    $tier === 'college' => 'College Match',
                    $tier === 'shared' => 'Shared Room',
                    default => 'Available',
                },
                'is_recommended' => $isRecommended,
                'recommendation_level' => $levelInfo['level'],
                'recommendation_level_label' => $levelMeta['label'],
                'recommendation_level_color' => $levelMeta['color'],
                'is_manual_override' => $levelInfo['is_manual_override'],
                'score' => $scored['score'],
                'score_max' => 100,
                'score_breakdown' => $scored['breakdown'],
                'reasons' => $reasons,
                'utilization_percent' => $utilizationPercent,
                // Same shape as Faculty's "current_load / max_teaching_units"
                // (see recommendFaculty()) — lets the Room dropdown show
                // "8 / 63 hrs" right next to each candidate, exactly like
                // the Rooms page's own Room Utilization column.
                'scheduled_hours' => $scheduledHours,
                'max_hours' => $maxHours,
            ];
        })->values()->all();

        usort($ranked, function (array $a, array $b) use ($closestCapacity) {
            // Priority 1: an explicit administrator recommendation
            // always outranks a non-recommended room, regardless of
            // score — "highest priority" per the Room Recommendation
            // spec. Hard constraints have already been enforced
            // before this point, so this can never surface an invalid
            // room, only re-order equally-valid ones.
            if ($a['is_recommended'] !== $b['is_recommended']) {
                return $b['is_recommended'] <=> $a['is_recommended'];
            }

            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            // Tie-break 1: prefer the LEAST-used room among equally
            // scored candidates so Auto Generate/the selector spreads
            // subjects across every eligible room (all lecture rooms,
            // all of a program's labs, etc.) instead of always
            // defaulting to the same one.
            if ($a['utilization_percent'] !== $b['utilization_percent']) {
                return $a['utilization_percent'] <=> $b['utilization_percent'];
            }

            // Tie-break 2: capacity closest to what's needed wins over
            // a needlessly larger room.
            return abs($a['capacity'] - $closestCapacity) <=> abs($b['capacity'] - $closestCapacity);
        });

        $ranked = array_slice($ranked, 0, self::MAX_ROOM_RESULTS);

        foreach ($ranked as $index => &$item) {
            $isTopPick = $index === 0;
            $item['confidence'] = $this->confidenceFromScore($item['score']);
            $item['status_color'] = $this->roomStatusColor(false, $isTopPick, $item['match_tier']);
            $item['is_top_pick'] = $isTopPick;
            $item['explanation'] = $this->roomExplanation($item['name'], $isTopPick, $item['match_tier'], $item['utilization_percent'], $item['score']);
        }
        unset($item);

        return ['recommendations' => $ranked, 'message' => null, 'reasons' => []];
    }

    /**
     * ROOM RECOMMENDATION SELECTOR.
     *
     * Powers the interactive Room selector on the Auto Generate review
     * panel, mirroring facultyOptionsForSelector(). Returns the same
     * ranked, hard-filtered recommendation list recommendRooms()
     * produces (so "recommended first, AI pick defaulted" always
     * matches what Auto Generate itself chose), plus — when the
     * Registrar types into the search box — a global search across
     * every Active room regardless of Type/College/Capacity/
     * Availability, each scored via scoreArbitraryRoom() so the
     * dropdown can show a live Recommendation Score (and a clear
     * "why this isn't ideal" explanation) even for a room the hard
     * filters would normally exclude — the Registrar always keeps
     * full manual override freedom.
     */
    public function roomOptionsForSelector(Subject $subject, Section $section, ?SectionSubject $current, ?string $search = null): array
    {
        $recommended = $this->recommendRooms($subject, $section, $current);

        $searchResults = [];

        if ($search !== null && trim($search) !== '') {
            $recommendedIds = collect($recommended['recommendations'])->pluck('id')->all();

            $matches = Room::query()
                ->where('status', 'Active')
                ->where(function ($q) use ($search) {
                    $q->where('room_code', 'like', "%{$search}%")
                        ->orWhere('room_name', 'like', "%{$search}%");
                })
                ->whereNotIn('id', $recommendedIds)
                ->with(['department', 'college'])
                ->limit(20)
                ->get();

            $searchResults = $matches
                ->map(fn (Room $room) => $this->scoreArbitraryRoom($room, $subject, $section, $current))
                ->sortByDesc('score')
                ->values()
                ->all();
        }

        return [
            'recommended' => $recommended['recommendations'],
            'message' => $recommended['message'],
            'search_results' => $searchResults,
        ];
    }

    /**
     * Score ANY single Active room against a Subject/Section — used
     * both for global search results and for recomputing the Live
     * Score the instant the Registrar manually overrides the
     * AI-selected room with one outside the recommended pool (e.g.
     * searching "Room 108" directly by code).
     *
     * Unlike recommendRooms()'s hard filters, nothing here excludes a
     * room — every check that would normally disqualify it (Wrong
     * Room Type, Capacity Too Small, Occupied, Different College) is
     * instead surfaced as a failed (✗) reason and an explanatory
     * override_reason, exactly like scoreArbitraryFaculty() does for
     * Manual Override faculty picks. The Registrar always keeps full
     * freedom to pick it anyway.
     */
    public function scoreArbitraryRoom(Room $room, Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $section->loadMissing('major.department');
        $room->loadMissing(['department', 'college']);

        $wantsLaboratory = (int) $subject->laboratory_hours > 0;
        $preferredType = $wantsLaboratory ? 'Laboratory' : 'Lecture';
        $typeMatch = $room->room_type === $preferredType;

        $capacityNeeded = $current?->capacity ?? $section->estimated_students ?? 0;
        $capacityOk = $capacityNeeded ? $room->capacity >= $capacityNeeded : true;

        $conflictCount = $this->roomConflictCount($room, $current);
        $available = $conflictCount === 0;

        $sectionDepartmentId = $section->major?->department_id;
        $sectionCollegeId = $section->major?->department?->college_id;
        $tier = $this->resolveRoomScopeTier($room, $sectionDepartmentId, $sectionCollegeId);

        $roomSummary = app(RoomUtilizationService::class)->summarizeRoom($room);
        $utilizationPercent = round($roomSummary['utilization_percent'] ?? 0.0, 1);
        // Same "X / Y hrs" shape recommendRooms() already attaches to
        // its ranked pool (see the comment there) — search results
        // from scoreArbitraryRoom() were missing it, so the selector
        // dropdown showed hours for the recommended pool but not for
        // a room found via search (e.g. typing "Room 108").
        $scheduledHours = (float) ($roomSummary['scheduled_hours'] ?? 0);
        $maxHours = (float) ($roomSummary['max_hours'] ?? 0);

        $scored = $this->weightedRoomScore(
            typeMatch: $typeMatch,
            available: $available,
            capacityOk: $capacityOk,
            capacityNeeded: $capacityNeeded,
            roomCapacity: $room->capacity,
            tier: $tier,
            utilizationPercent: $utilizationPercent,
        );

        $isRecommended = $this->recommendedRoomIdsFor($subject)->contains($room->id);
        if ($isRecommended) {
            $scored['score'] = min(100, $scored['score'] + self::ROOM_RECOMMENDATION_BONUS);
            $scored['breakdown']['recommended'] = self::ROOM_RECOMMENDATION_BONUS;
        }
        $score = $scored['score'];

        $levelInfo = $this->recommendationLevel($isRecommended, $tier);

        $reasons = [
            ['label' => $typeMatch ? 'Correct Room Type' : 'Wrong Room Type', 'met' => $typeMatch, 'type' => $typeMatch ? 'success' : 'danger'],
        ];

        if ($isRecommended) {
            $reasons[] = [
                'label' => $levelInfo['is_manual_override']
                    ? 'Administrator recommended (cross-college override)'
                    : 'Recommended for this Subject',
                'met' => true,
                'type' => 'success',
            ];
        }

        if ($tier === 'program') {
            $reasons[] = ['label' => 'Same Program', 'met' => true, 'type' => 'success'];
            $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
        } elseif ($tier === 'college') {
            $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
            $reasons[] = ['label' => 'Shared by all programs', 'met' => true, 'type' => 'warning'];
        } elseif ($tier === 'shared') {
            $reasons[] = ['label' => 'Shared Room', 'met' => true, 'type' => 'success'];
        } else {
            $reasons[] = [
                'label' => $levelInfo['is_manual_override']
                    ? 'Subject belongs to another college — manual override'
                    : 'Different College',
                'met' => $levelInfo['is_manual_override'],
                'type' => $levelInfo['is_manual_override'] ? 'warning' : 'danger',
            ];
        }

        $reasons[] = ['label' => $capacityOk ? 'Capacity OK' : 'Capacity Too Small', 'met' => $capacityOk, 'type' => $capacityOk ? 'success' : 'danger'];
        $reasons[] = ['label' => $available ? 'Available' : 'Occupied at this time', 'met' => $available, 'type' => $available ? 'success' : 'danger'];

        // A "Manual Override" pick, in the hard-constraint sense, is
        // only ever Type/Capacity/Availability failing — those are
        // the only true hard constraints (Priorities per the spec).
        // Scope mismatch alone is NOT a hard-constraint override; a
        // cross-college room is a legitimate "Available" (or, if
        // explicitly recommended, "Preferred") pick, never blocked.
        $isManualOverride = ! $typeMatch || ! $capacityOk || ! $available;

        $badge = match (true) {
            $isManualOverride => 'Manual Override',
            $levelInfo['is_manual_override'] => 'Administrator Override',
            $isRecommended => 'Recommended Room',
            $tier === 'program' => 'Program Match',
            $tier === 'college' => 'College Match',
            $tier === 'shared' => 'Shared Room',
            default => 'Available',
        };

        $overrideReason = null;
        if ($isManualOverride) {
            $issues = [];
            if (! $typeMatch) {
                $issues[] = "requires a {$preferredType} room";
            }
            if (! $capacityOk) {
                $issues[] = "needs seating for at least {$capacityNeeded} students";
            }
            if (! $available) {
                $issues[] = 'is already booked at this day/time';
            }
            $overrideReason = 'This room '.implode(', ', $issues).'.';
        }

        return [
            'id' => $room->id,
            'name' => $this->roomDisplayName($room),
            'room_type' => $room->room_type,
            'room_category' => $room->room_category,
            'department' => $room->department?->name,
            'college' => $room->college?->name,
            'capacity' => $room->capacity,
            'match_tier' => $tier,
            'score' => $score,
            'score_max' => 100,
            'score_breakdown' => $scored['breakdown'],
            'reasons' => $reasons,
            'confidence' => $this->confidenceFromScore($score),
            'is_recommended' => $isRecommended,
            'recommendation_level' => $levelInfo['level'],
            'recommendation_level_label' => $this->recommendationLevelMeta($levelInfo['level'])['label'],
            'recommendation_level_color' => $this->recommendationLevelMeta($levelInfo['level'])['color'],
            'is_manual_override' => $levelInfo['is_manual_override'],
            'badge' => $badge,
            'manual_override' => $isManualOverride,
            'override_reason' => $overrideReason,
            'utilization_percent' => $utilizationPercent,
            'scheduled_hours' => $scheduledHours,
            'max_hours' => $maxHours,
            'status_color' => $this->roomStatusColor($isManualOverride, false, $tier),
            'explanation' => $isManualOverride
                ? $overrideReason
                : $this->roomExplanation($this->roomDisplayName($room), false, $tier, $utilizationPercent, $score),

        ];
    }

    /**
     * Resolve which scoping tier a Room falls into for a given
     * Section, or null if the Room belongs to a College/Department
     * that ISN'T the Section's own — i.e. not a valid candidate at
     * all (Priorities 2–4 of the Intelligent Room Assignment spec).
     *
     *   'program' — Room's Department is exactly the Section's own.
     *   'college'  — Room has no Department (or a different one) but
     *                its College matches the Section's College.
     *   'shared'   — Room has no College and no Department — usable
     *                by any Section.
     *   null       — Room is scoped to a College the Section doesn't
     *                belong to (or a Department without a College
     *                match) — never recommended.
     */
    /**
     * Resolve which scoping tier a Room falls into for a given
     * Section — 'program' / 'college' / 'shared' (naturally suitable)
     * or 'mismatch' (belongs to a different College/Program entirely).
     *
     * As of the Room Ownership + Controlled Override enhancement,
     * 'mismatch' is NOT a hard exclusion — a cross-college room can
     * still be a valid (if low-scoring) "Available" candidate, or a
     * high-scoring one if the administrator has explicitly
     * recommended it (see recommendationLevel()).
     */
    private function resolveRoomScopeTier(Room $room, ?int $sectionDepartmentId, ?int $sectionCollegeId): string
    {
        if (! $room->college_id && ! $room->department_id) {
            return 'shared';
        }

        if ($sectionDepartmentId && $room->department_id === $sectionDepartmentId) {
            return 'program';
        }

        if ($sectionCollegeId && $room->college_id === $sectionCollegeId) {
            return 'college';
        }

        return 'mismatch';
    }

    /**
     * Build the itemized "No suitable room available" reasons the
     * spec calls for, based on exactly which hard filter(s) emptied
     * the candidate pool.
     *
     * @return list<string>
     */
    private function noRoomReasons(
        string $preferredType,
        int $typeExcluded,
        int $capacityExcluded,
        int $conflictExcluded,
        int $scopeExcluded,
    ): array {
        $reasons = [];

        if ($typeExcluded > 0 && $capacityExcluded === 0 && $conflictExcluded === 0 && $scopeExcluded === 0) {
            $reasons[] = "No available {$preferredType} room found.";
        }

        if ($capacityExcluded > 0) {
            $reasons[] = 'The available rooms are too small for the estimated number of students.';
        }

        if ($conflictExcluded > 0) {
            $reasons[] = 'All matching rooms are occupied during this time.';
        }

        if ($scopeExcluded > 0 && $typeExcluded === 0) {
            $reasons[] = 'No room belonging to this Program, College, or a Shared room, is currently free.';
        }

        if (empty($reasons)) {
            $reasons[] = "No {$preferredType} room is currently available for this Section.";
        }

        return $reasons;
    }

    /**
     * TIME RECOMMENDATION.
     *
     * Recommends available Time periods (Day pattern + Start/End
     * Time) that do NOT conflict with the given candidate Faculty,
     * Room, or the Section itself — reusing ScheduleConflictService's
     * exact overlap rule so this can never disagree with what happens
     * when the Registrar actually tries to save. Returns the first
     * available options, best (earliest, most standard) first.
     */
    /**
     * Maximum lecture-only teaching hours the Section Daily Load
     * Optimizer allows on a single day before it starts penalizing
     * the candidate for student fatigue.
     */
    private const MAX_DAILY_LECTURE_MINUTES = 6 * 60;

    /**
     * Maximum teaching hours allowed on a day that includes at least
     * one Laboratory meeting (labs run longer, so the ceiling is
     * raised, never removed).
     */
    private const MAX_DAILY_LAB_MINUTES = 8 * 60;

    /**
     * An idle gap between two of the Section's meetings on the same
     * day longer than this is flagged as a "long vacant period" the
     * spec asks the optimizer to avoid.
     */
    private const LONG_IDLE_GAP_MINUTES = 120;

    /** A gap this long or longer is a severe penalty, not just a warning. */
    private const SEVERE_IDLE_GAP_MINUTES = 240;

    /**
     * More than this many back-to-back (zero-gap) meetings in a row
     * on the same day counts as "excessive consecutive class hours."
     */
    private const MAX_CONSECUTIVE_MEETINGS = 3;

    /**
     * SHARED ONLINE SESSION CANDIDATES — for a still-unscheduled
     * Online row, find other Regular sections' Online classes this
     * row could ride along on instead of getting its own separate
     * time slot: same Subject, same Year Level, same Faculty (once
     * this row already has one picked), same Major + equivalent
     * Curriculum, and the same Academic Year + Semester as this
     * row's own Section. E.g. BSIT-1A and BSIT-1B both taking CC101
     * under the same instructor can sit in on one combined Online
     * session instead of booking that Faculty member twice.
     *
     * Purely a SUGGESTION finder — never writes anything. Applying
     * one of these goes through the exact same
     * `merge_target_section_subject_id` ("Shared Class") write path
     * SectionSubjectController::performScheduleAssignmentUpdate()
     * already uses (re-validated fresh there via
     * IrregularSectionMergeService::evaluateReversePlacement(), which
     * is not actually Irregular-specific — see its docblock), so a
     * candidate that's gone stale by the time the Registrar clicks it
     * is still caught before anything saves.
     *
     * Deliberately ONLINE-ONLY (room_id null on both sides) — a
     * face-to-face class merge still has a physical room capacity to
     * respect and stays on the Irregular-merge flow's existing
     * "Combine Sections?" path; this is only for Online rows where
     * there's no seat ceiling to check.
     *
     * @return list<array{section_subject_id:int, section_id:int, section_code:?string, section_name:?string, faculty_id:int, faculty_name:?string, days:list<string>, start_time:string, end_time:string}>
     */
    public function findOnlineMergeCandidates(SectionSubject $current): array
    {
        $current->loadMissing(['section']);
        $section = $current->section;

        if (! $section) {
            return [];
        }

        $equivalentCurriculumIds = \App\Models\Curriculum::query()
            ->where('major_id', $section->major_id)
            ->pluck('id');

        $query = SectionSubject::query()
            ->where('subject_id', $current->subject_id)
            ->where('section_id', '!=', $current->section_id)
            ->whereNull('room_id')
            ->whereNotNull('faculty_id')
            ->whereNotNull('days')
            ->where('days', '!=', '')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereHas('section', function ($q) use ($section, $equivalentCurriculumIds) {
                $q->where('section_type', 'Regular')
                    ->where('year_level', $section->year_level)
                    ->where('major_id', $section->major_id)
                    ->whereIn('curriculum_id', $equivalentCurriculumIds)
                    ->where('academic_year', $section->academic_year)
                    ->where('semester', $section->semester);
            });

        // Once this row already has a Faculty picked, only offer
        // sessions taught by that SAME Faculty — merging is meant to
        // save one instructor from teaching the same class twice, not
        // to silently reassign the row to a different teacher.
        if ($current->faculty_id) {
            $query->where('faculty_id', $current->faculty_id);
        }

        return $query
            ->with(['section:id,section_code,section_name', 'faculty:id,first_name,last_name'])
            ->orderBy('start_time')
            ->limit(self::MAX_TIME_RESULTS)
            ->get()
            ->map(fn (SectionSubject $candidate) => [
                'section_subject_id' => $candidate->id,
                'section_id' => $candidate->section_id,
                'section_code' => $candidate->section?->section_code,
                'section_name' => $candidate->section?->section_name,
                'faculty_id' => $candidate->faculty_id,
                'faculty_name' => $candidate->faculty?->full_name,
                'days' => array_values(array_filter(explode(',', (string) $candidate->days))),
                'start_time' => $candidate->start_time,
                'end_time' => $candidate->end_time,
            ])
            ->all();
    }

    public function recommendTimes(
        Subject $subject,
        Section $section,
        ?int $facultyId,
        ?int $roomId,
        ?SectionSubject $current = null
    ): array {
        $totalHours = $this->requiredHoursFor($subject, $current);
        $subjectHasLab = (int) $subject->laboratory_hours > 0;

        $excludingId = $current?->id ?? 0;
        $results = [];

        // How many meetings/week this Subject expects (1 for Lab/
        // Special subjects, 2 for Lecture, per MeetingPatternService).
        // Threaded through into every candidate below so the frontend
        // Time selector can cap manual day-picking at this count and
        // never silently turn a 1-meeting Subject into a 2-meeting one.
        $expectedMeetings = $this->meetingPatternService->meetingsPerWeek($subject);

        // SCHEDULING PREFERENCES (Academic Calendar -> active School
        // Year) — Class Start/End Time, the Time Interval, and the
        // fixed Lunch Break all come from here now. Nothing in this
        // service hardcodes a start-time list anymore.
        $activeSchoolYear = SchoolYear::active();
        $candidateStartTimes = $activeSchoolYear ? $activeSchoolYear->candidateStartTimes() : (new SchoolYear)->candidateStartTimes();
        $classEndMinutes = $this->minutesFromTime($activeSchoolYear?->classEndTime() ?? SchoolYear::DEFAULT_CLASS_END_TIME);

        // MEETING PATTERN INTELLIGENCE (Prompt: Meeting Pattern
        // Intelligence) — which Day combinations are even considered
        // now depends on what kind of Subject this is (Lecture ->
        // 2 meetings/week, Laboratory/Special -> 1 meeting/week, never
        // 3 unless a future config explicitly raises the cap) AND on
        // the School Year's Class Days — see MeetingPatternService for
        // the full rule set.
        $dayPatterns = $this->meetingPatternService->dayGroups($subject);

        // SECTION DAILY LOAD OPTIMIZATION — snapshot of every OTHER
        // meeting this Section already has, per Day, gathered ONCE
        // before the search loop (not re-queried per candidate) so
        // scoring every candidate slot stays cheap. See
        // sectionScheduleSnapshot() / scoreSectionDailyLoad().
        $sectionSnapshot = $section->id ? $this->sectionScheduleSnapshot($section->id, $excludingId) : [];
        $allowedDays = $activeSchoolYear ? $activeSchoolYear->allowedDays() : SchoolYear::DEFAULT_CLASS_DAYS;

        foreach ($dayPatterns as $days) {
            $sessionMinutes = (int) round(($totalHours / count($days)) * 60);

            foreach ($candidateStartTimes as $start) {
                [$hour, $minute] = array_map('intval', explode(':', $start));
                $endMinutes = ($hour * 60 + $minute) + $sessionMinutes;

                // Never recommend a session that runs past the
                // Academic Term's configured Class End Time.
                if ($endMinutes > $classEndMinutes) {
                    continue;
                }

                $end = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);

                // LUNCH BREAK (12:00 PM - 1:00 PM) — excluded from
                // every Auto Generate/Regenerate and Recommend Day &
                // Time candidate; the automated engine never proposes
                // a slot overlapping it. This is a SOFT exclusion
                // scoped to this recommendation engine only — it does
                // not stop a Registrar/Dean/OIC from manually placing
                // a class across lunch (ScheduleConflictService::
                // validate() deliberately doesn't check this).
                if (SchoolYear::overlapsLunchBreak($start, $end)) {
                    continue;
                }

                $hasConflict = false;

                if ($section->id) {
                    $hasConflict = $hasConflict || (bool) $this->conflictService->findSectionConflict(
                        $section->id, $excludingId, $days, $start, $end
                    );
                }
                if (! $hasConflict && $facultyId) {
                    $hasConflict = (bool) $this->conflictService->findFacultyConflict(
                        $facultyId, $excludingId, $days, $start, $end
                    );
                }
                if (! $hasConflict && $roomId) {
                    $hasConflict = (bool) $this->conflictService->findRoomConflict(
                        $roomId, $excludingId, $days, $start, $end
                    );
                }

                if ($hasConflict) {
                    continue;
                }

                // TIME RECOMMENDATION SCORE (100 points) — computed by
                // buildTimeCandidate(), shared with
                // recommendSingleDaySlots() so both search spaces
                // score a candidate identically. The engine no longer
                // stops at the first conflict-free slot — every
                // candidate is fully scored, then ranked, so Auto
                // Generate / Regenerate / Recommend Time / Recommend
                // Schedule all pick the highest-QUALITY conflict-free
                // slot rather than merely the first one found.
                $results[] = $this->buildTimeCandidate(
                    $days, $start, $end, $totalHours, $subjectHasLab,
                    $expectedMeetings, $sectionSnapshot, $allowedDays, $subject
                );

                // Unlike the old "first conflict-free slot wins" search,
                // the optimizer needs a wide enough pool of candidates
                // to actually have something to rank — so it keeps
                // gathering well past MAX_TIME_RESULTS and only trims
                // down to the top N after sorting by score below.
                if (count($results) >= self::MAX_TIME_RESULTS * 6) {
                    break 2;
                }
            }
        }

        if (empty($results)) {
            return ['recommendations' => [], 'message' => 'No available time slot found without conflicts.'];
        }

        // SMART EVALUATION — always choose the highest quality-scoring
        // schedule, never simply the first one found. Ties broken by
        // earlier start time (a small, stable, student-friendly
        // tiebreaker) so results stay deterministic.
        usort($results, function (array $a, array $b) {
            return $b['score'] <=> $a['score']
                ?: strcmp($a['start_time'], $b['start_time']);
        });

        $results = array_slice($results, 0, self::MAX_TIME_RESULTS);

        foreach ($results as &$item) {
            $item['confidence'] = $this->confidenceFromScore($item['score']);
        }
        unset($item);

        return ['recommendations' => $results, 'message' => null];
    }

    /**
     * SINGLE-DAY SLOT SEARCH — Smart Day & Time Recommendation modal.
     *
     * recommendTimes() above only ever searches Day COMBINATIONS that
     * match the Subject's full weekly meeting count (e.g. a Lecture
     * subject only gets MW/TTH/... 2-day patterns back). That's
     * correct when generating a Subject's whole schedule from
     * scratch, but wrong for the "fix just ONE conflicting meeting"
     * flow: a Tue/Thu subject where the Registrar is only replacing
     * the Thursday occurrence needs single-day candidates (e.g. just
     * "Saturday"), not another full 2-day pattern.
     *
     * Reuses the exact same conflict checks (ScheduleConflictService)
     * and scoring (buildTimeCandidate()) as recommendTimes() — this
     * is a different SEARCH SPACE (one Day at a time, across every
     * allowed Day), never a different or simplified validation rule.
     *
     * @param  int|null  $sessionMinutes  Duration of the specific occurrence being replaced (e.g. the Registrar's current Start/End). Falls back to the Subject's usual per-meeting length when omitted.
     * @param  list<string>  $excludeDays  Days already used by this row's OTHER meetings (or otherwise off the table) — never offered again as a "new" single-day option.
     */
    public function recommendSingleDaySlots(
        Subject $subject,
        Section $section,
        ?int $facultyId,
        ?int $roomId,
        ?SectionSubject $current = null,
        ?int $sessionMinutes = null,
        array $excludeDays = []
    ): array {
        $totalHours = $this->requiredHoursFor($subject, $current);
        $subjectHasLab = (int) $subject->laboratory_hours > 0;

        $excludingId = $current?->id ?? 0;
        $expectedMeetings = $this->meetingPatternService->meetingsPerWeek($subject);

        // Duration for this single occurrence: the exact Start/End
        // the Registrar was just editing when given, otherwise the
        // Subject's usual per-meeting length (weekly hours split
        // across its expected meeting count), capped to a sane
        // single block the same way MeetingPatternService bumps
        // meeting frequency for long Subjects.
        $maxContinuousHours = (float) config('scheduling.meeting_patterns.max_continuous_hours', 3);
        $defaultSessionMinutes = (int) round(($totalHours / max($expectedMeetings, 1)) * 60);
        if ($maxContinuousHours > 0) {
            $defaultSessionMinutes = min($defaultSessionMinutes, (int) round($maxContinuousHours * 60));
        }
        $sessionMinutes = $sessionMinutes && $sessionMinutes > 0 ? $sessionMinutes : max($defaultSessionMinutes, 30);

        $activeSchoolYear = SchoolYear::active();
        $candidateStartTimes = $activeSchoolYear ? $activeSchoolYear->candidateStartTimes() : (new SchoolYear)->candidateStartTimes();
        $classEndMinutes = $this->minutesFromTime($activeSchoolYear?->classEndTime() ?? SchoolYear::DEFAULT_CLASS_END_TIME);
        $allowedDays = $activeSchoolYear ? $activeSchoolYear->allowedDays() : SchoolYear::DEFAULT_CLASS_DAYS;

        $searchDays = array_values(array_diff($allowedDays, $excludeDays));

        $sectionSnapshot = $section->id ? $this->sectionScheduleSnapshot($section->id, $excludingId) : [];

        $results = [];

        foreach ($searchDays as $day) {
            $days = [$day];

            foreach ($candidateStartTimes as $start) {
                [$hour, $minute] = array_map('intval', explode(':', $start));
                $endMinutes = ($hour * 60 + $minute) + $sessionMinutes;

                if ($endMinutes > $classEndMinutes) {
                    continue;
                }

                $end = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);

                if (SchoolYear::overlapsLunchBreak($start, $end)) {
                    continue;
                }

                $hasConflict = false;

                if ($section->id) {
                    $hasConflict = $hasConflict || (bool) $this->conflictService->findSectionConflict(
                        $section->id, $excludingId, $days, $start, $end
                    );
                }
                if (! $hasConflict && $facultyId) {
                    $hasConflict = (bool) $this->conflictService->findFacultyConflict(
                        $facultyId, $excludingId, $days, $start, $end
                    );
                }
                if (! $hasConflict && $roomId) {
                    $hasConflict = (bool) $this->conflictService->findRoomConflict(
                        $roomId, $excludingId, $days, $start, $end
                    );
                }

                if ($hasConflict) {
                    continue;
                }

                $candidate = $this->buildTimeCandidate(
                    $days, $start, $end, $totalHours, $subjectHasLab,
                    $expectedMeetings, $sectionSnapshot, $allowedDays, $subject
                );
                $candidate['single_day'] = true;
                $results[] = $candidate;

                if (count($results) >= self::MAX_TIME_RESULTS * 6) {
                    break 2;
                }
            }
        }

        if (empty($results)) {
            return ['recommendations' => [], 'message' => 'No available single-day time slot found without conflicts.'];
        }

        usort($results, function (array $a, array $b) {
            return $b['score'] <=> $a['score']
                ?: strcmp($a['start_time'], $b['start_time']);
        });

        $results = array_slice($results, 0, self::MAX_TIME_RESULTS);

        foreach ($results as &$item) {
            $item['confidence'] = $this->confidenceFromScore($item['score']);
        }
        unset($item);

        return ['recommendations' => $results, 'message' => null];
    }

    /**
     * Shared per-candidate scorer for both recommendTimes() (full
     * weekly-pattern search) and recommendSingleDaySlots() (one-Day
     * search) — a candidate is scored identically no matter which
     * search space found it, so results from both can be safely
     * merged and ranked together by the caller.
     *
     * @param  list<string>  $days
     * @param  array<string, list<array{start:int,end:int,has_lab:bool}>>  $sectionSnapshot
     * @param  list<string>  $allowedDays
     */
    private function buildTimeCandidate(
        array $days,
        string $start,
        string $end,
        int $totalHours,
        bool $subjectHasLab,
        int $expectedMeetings,
        array $sectionSnapshot,
        array $allowedDays,
        Subject $subject
    ): array {
        // TIME RECOMMENDATION SCORE (100 points):
        //   Faculty Available            10
        //   Room Available                10
        //   Section Available             10
        //   Fits Subject Hours            10
        //   Preferred Day Combination     20
        //   Smart Time Preference         15
        //   Section Daily Load Balance    25
        //   -------------------------------
        //   Total                        100
        $tier = $this->meetingPatternService->priorityTier($days);
        $dayPatternBonus = (int) round((($tier['priority'] ?? 50) / 100) * 20);

        [$timePreferenceBonus, $timePreferenceLabel] = $this->scoreTimePreference($start);

        [$loadScore, $loadReasons] = $this->scoreSectionDailyLoad(
            $sectionSnapshot, $allowedDays, $days, $start, $end, $subjectHasLab
        );

        $points = [
            'faculty_available' => 10,
            'room_available' => 10,
            'section_available' => 10,
            'fits_subject_hours' => 10,
            'preferred_day_combination' => $dayPatternBonus,
            'smart_time_preference' => $timePreferenceBonus,
            'section_daily_load_balance' => $loadScore,
        ];

        $reasons = [
            ['label' => 'Faculty Available', 'met' => true, 'type' => 'success'],
            ['label' => 'Room Available', 'met' => true, 'type' => 'success'],
            ['label' => 'Section Available', 'met' => true, 'type' => 'success'],
            ['label' => 'Fits Subject Hours', 'met' => true, 'type' => 'success'],
            $tier && $tier['tier'] === 'Preferred'
                ? ['label' => 'Preferred Day Combination', 'met' => true, 'type' => 'success']
                : ['label' => ($tier['tier'] ?? 'Non-preferred').' Day Combination', 'met' => false, 'type' => 'warning'],
            ['label' => $timePreferenceLabel, 'met' => $timePreferenceBonus >= 12, 'type' => $timePreferenceBonus >= 12 ? 'success' : 'warning'],
            ...$loadReasons,
        ];

        return [
            'days' => $days,
            'start_time' => $start,
            'end_time' => $end,
            'meetings_per_week' => count($days),
            'expected_meetings' => $expectedMeetings,
            'required_weekly_hours' => $totalHours,
            'subject_type' => $this->meetingPatternService->label($subject),
            'day_pattern_name' => $tier['name'] ?? implode('/', $days),
            'score' => array_sum($points),
            'score_max' => 100,
            'score_breakdown' => $points,
            'reasons' => $reasons,
        ];
    }

    /**
     * SMART TIME PREFERENCE — Morning slots are preferred, then Early
     * Afternoon, then Late Afternoon; Evening is only ever a fallback
     * once nothing better scores higher. Returns [bonus points (0-15),
     * a short reason label] for the given Start Time.
     *
     * @return array{0: int, 1: string}
     */
    private function scoreTimePreference(string $start): array
    {
        $minutes = $this->minutesFromTime($start);

        if ($minutes < 11 * 60 + 30) {
            return [15, 'Morning Schedule (Preferred)'];
        }

        if ($minutes < 16 * 60) {
            return [12, 'Early Afternoon Schedule'];
        }

        if ($minutes < 18 * 60) {
            return [6, 'Late Afternoon Schedule'];
        }

        return [0, 'Evening Schedule'];
    }

    /**
     * SECTION DAILY LOAD OPTIMIZATION (Smart Scheduling).
     *
     * Every OTHER meeting this Section already has (across every
     * other Subject already scheduled/auto-generated for it),
     * grouped by Day, as `[['start' => minutes, 'end' => minutes,
     * 'has_lab' => bool], ...]` sorted by start time.
     *
     * Gathered once per recommendTimes() call — never re-queried per
     * candidate slot — since the Section's own schedule doesn't
     * change mid-search.
     *
     * @return array<string, list<array{start:int,end:int,has_lab:bool}>>
     */
    private function sectionScheduleSnapshot(int $sectionId, int $excludingId): array
    {
        $rows = SectionSubject::query()
            ->where('section_id', $sectionId)
            ->where('id', '!=', $excludingId)
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->with('subject:id,laboratory_hours')
            ->get();

        $byDay = [];

        foreach ($rows as $row) {
            $hasLab = (int) ($row->subject?->laboratory_hours ?? 0) > 0;
            $start = $this->minutesFromTime($row->start_time);
            $end = $this->minutesFromTime($row->end_time);

            foreach (array_filter(explode(',', (string) $row->days)) as $day) {
                $byDay[$day][] = ['start' => $start, 'end' => $end, 'has_lab' => $hasLab];
            }
        }

        foreach ($byDay as &$meetings) {
            usort($meetings, fn ($a, $b) => $a['start'] <=> $b['start']);
        }
        unset($meetings);

        return $byDay;
    }

    /**
     * Scores a candidate slot against the Section's existing schedule
     * for daily workload balance, idle-time, consecutive-class limits,
     * and even weekly distribution — the four pillars of Section
     * Daily Load Optimization. Returns [score out of 25, list of
     * reason rows] so the caller can merge both straight into the
     * existing score/reasons shape.
     *
     * @param  array<string, list<array{start:int,end:int,has_lab:bool}>>  $snapshot
     * @param  list<string>  $allowedDays
     * @param  list<string>  $days
     * @return array{0:int, 1:list<array>}
     */
    private function scoreSectionDailyLoad(
        array $snapshot,
        array $allowedDays,
        array $days,
        string $start,
        string $end,
        bool $subjectHasLab
    ): array {
        $startMin = $this->minutesFromTime($start);
        $endMin = $this->minutesFromTime($end);

        // Even Weekly Distribution baseline — how many meetings the
        // Section already has on each allowed day, so a candidate that
        // lands on an already-busy day scores lower than one that
        // fills in a quiet/empty day.
        $countsByDay = [];
        foreach ($allowedDays as $day) {
            $countsByDay[$day] = count($snapshot[$day] ?? []);
        }
        $maxDayCount = ! empty($countsByDay) ? max($countsByDay) : 0;

        $dailyBudget = 25 / max(count($days), 1);
        $total = 0.0;
        $worstDailyHoursPenalty = 0;
        $worstIdlePenalty = 0;
        $worstConsecutivePenalty = 0;
        $distributionPenalty = 0;

        foreach ($days as $day) {
            $existing = $snapshot[$day] ?? [];
            $dayHasLab = $subjectHasLab || collect($existing)->contains('has_lab', true);
            $dailyLimit = $dayHasLab ? self::MAX_DAILY_LAB_MINUTES : self::MAX_DAILY_LECTURE_MINUTES;

            // Build the day's full meeting list WITH the candidate
            // slot inserted, sorted, to evaluate gaps/consecutiveness/
            // total load exactly as the Section's timetable would
            // actually look if this candidate were chosen.
            $meetings = $existing;
            $meetings[] = ['start' => $startMin, 'end' => $endMin, 'has_lab' => $subjectHasLab];
            usort($meetings, fn ($a, $b) => $a['start'] <=> $b['start']);

            $dayScore = $dailyBudget;

            // Daily Class Hour Limit — total instructional minutes for
            // the day, including this candidate, must stay within the
            // 6hr lecture / 8hr lab-inclusive ceiling.
            $dayTotalMinutes = array_sum(array_map(fn ($m) => $m['end'] - $m['start'], $meetings));
            if ($dayTotalMinutes > $dailyLimit) {
                $overBy = $dayTotalMinutes - $dailyLimit;
                $penalty = min($dailyBudget * 0.6, $dailyBudget * 0.6 * ($overBy / $dailyLimit));
                $dayScore -= $penalty;
                $worstDailyHoursPenalty = max($worstDailyHoursPenalty, (int) round($penalty));
            }

            // Idle Time / Consecutive Class Limit — walk the sorted
            // meeting list once, tracking both the size of each gap
            // between meetings and how many meetings in a row have
            // (effectively) no gap between them.
            $consecutiveRun = 1;
            for ($i = 1; $i < count($meetings); $i++) {
                $gap = $meetings[$i]['start'] - $meetings[$i - 1]['end'];

                if ($gap >= self::SEVERE_IDLE_GAP_MINUTES) {
                    $dayScore -= $dailyBudget * 0.35;
                    $worstIdlePenalty = max($worstIdlePenalty, (int) round($dailyBudget * 0.35));
                    $consecutiveRun = 1;
                } elseif ($gap >= self::LONG_IDLE_GAP_MINUTES) {
                    $dayScore -= $dailyBudget * 0.2;
                    $worstIdlePenalty = max($worstIdlePenalty, (int) round($dailyBudget * 0.2));
                    $consecutiveRun = 1;
                } elseif ($gap <= 0) {
                    // Back-to-back, no break at all.
                    $consecutiveRun++;
                    if ($consecutiveRun > self::MAX_CONSECUTIVE_MEETINGS) {
                        $penalty = $dailyBudget * 0.25;
                        $dayScore -= $penalty;
                        $worstConsecutivePenalty = max($worstConsecutivePenalty, (int) round($penalty));
                    }
                } else {
                    // A short, natural break — resets the consecutive
                    // run without any idle-gap penalty.
                    $consecutiveRun = 1;
                }
            }

            // Even Weekly Distribution — landing on the Section's
            // currently busiest day (when a quieter allowed day is
            // available) is penalized proportionally.
            $currentCount = $countsByDay[$day] ?? 0;
            if ($maxDayCount > 0 && $currentCount >= $maxDayCount && $maxDayCount > 1) {
                $penalty = $dailyBudget * 0.15;
                $dayScore -= $penalty;
                $distributionPenalty = max($distributionPenalty, (int) round($penalty));
            }

            $total += max(0, $dayScore);
        }

        $score = (int) round(max(0, min(25, $total)));

        $reasons = [];
        $reasons[] = $worstDailyHoursPenalty > 0
            ? ['label' => 'Daily Hour Limit Exceeded', 'met' => false, 'type' => 'warning']
            : ['label' => 'Balanced Daily Hours', 'met' => true, 'type' => 'success'];

        $reasons[] = $worstIdlePenalty > 0
            ? ['label' => 'Long Idle Gap Between Classes', 'met' => false, 'type' => 'warning']
            : ['label' => 'Minimal Idle Time', 'met' => true, 'type' => 'success'];

        $reasons[] = $worstConsecutivePenalty > 0
            ? ['label' => 'Excessive Consecutive Classes', 'met' => false, 'type' => 'warning']
            : ['label' => 'Reasonable Class Blocks', 'met' => true, 'type' => 'success'];

        $reasons[] = $distributionPenalty > 0
            ? ['label' => 'Concentrates Load on a Busy Day', 'met' => false, 'type' => 'warning']
            : ['label' => 'Even Weekly Distribution', 'met' => true, 'type' => 'success'];

        return [$score, $reasons];
    }

    /**
     * Time Recommendation Selector — Manual Override support.
     *
     * Scores a Registrar-picked Days/Start/End combination the exact
     * same way recommendTimes() scores its own suggestions — reusing
     * ScheduleConflictService for the Faculty/Room/Section overlap
     * checks — so a manually-typed time can never disagree with what
     * happens when "Accept All & Save" / "Save Schedule" actually
     * commits it. Unlike recommendTimes() this scores exactly the one
     * slot the Registrar picked rather than searching for the best
     * one, and it never rejects a conflicting slot outright — it
     * reports the conflict as a "Manual Override" so the Registrar can
     * still see and decide, the same freedom Faculty/Room overrides
     * already give.
     *
     * @param  list<string>  $days  Short Day tokens (Mon/Tue/Wed/...).
     */
    public function scoreArbitraryTime(
        array $days,
        string $startTime,
        string $endTime,
        Subject $subject,
        Section $section,
        ?int $facultyId,
        ?int $roomId,
        ?SectionSubject $current = null
    ): array {
        $excludingId = $current?->id ?? 0;

        // Keep the actual conflicting SectionSubject (not just a bool)
        // so the override reason below can name exactly which Section/
        // Subject already holds that Faculty/Room/Section slot, instead
        // of a generic "already booked" with no way to tell what to
        // reschedule around.
        $sectionConflictRow = $section->id
            ? $this->conflictService->findSectionConflict($section->id, $excludingId, $days, $startTime, $endTime)
            : null;

        $facultyConflictRow = $facultyId
            ? $this->conflictService->findFacultyConflict($facultyId, $excludingId, $days, $startTime, $endTime)
            : null;

        $roomConflictRow = $roomId
            ? $this->conflictService->findRoomConflict($roomId, $excludingId, $days, $startTime, $endTime)
            : null;

        $sectionConflict = (bool) $sectionConflictRow;
        $facultyConflict = (bool) $facultyConflictRow;
        $roomConflict = (bool) $roomConflictRow;

        $expectedMeetings = $this->meetingPatternService->meetingsPerWeek($subject);
        $fitsPattern = count($days) === $expectedMeetings;

        $totalHours = $this->requiredHoursFor($subject, $current);
        $expectedMinutes = (int) round(($totalHours / max(count($days), 1)) * 60);
        $actualMinutes = $this->minutesBetween($startTime, $endTime);
        $fitsHours = $actualMinutes >= $expectedMinutes - 5; // small tolerance for rounding

        // SCHEDULING PREFERENCES — a manually-typed time can still
        // violate the active School Year's Class Start/End Time,
        // Class Days, or the fixed Lunch Break. None of these block
        // the Registrar from saving it (same "Manual Override"
        // freedom as Faculty/Room), but they ARE surfaced as failed
        // reasons so the Registrar sees exactly why.
        $activeSchoolYear = SchoolYear::active();
        $allowedDays = $this->meetingPatternService->allowedDays();
        $daysAllowed = empty(array_diff($days, $allowedDays));
        $withinPolicy = $activeSchoolYear
            ? $activeSchoolYear->isWithinSchedulingPolicy($startTime, $endTime)
            : ! SchoolYear::overlapsLunchBreak($startTime, $endTime);
        $overlapsLunch = SchoolYear::overlapsLunchBreak($startTime, $endTime);

        // Day pattern tier + Evening Schedule check — same Meeting
        // Pattern Table / Student-Friendly-Time rules recommendTimes()
        // applies to its own suggestions, reused here so a manually
        // typed day/time gets an honest score and honest reasons too.
        $tier = $this->meetingPatternService->priorityTier($days);
        $dayPatternBonus = $fitsPattern ? (int) round((($tier['priority'] ?? 50) / 100) * 35) : 0;
        $isEvening = $this->minutesFromTime($startTime) >= (17 * 60);
        $studentFriendlyBonus = $isEvening ? 0 : 15;

        $points = [
            'faculty_available' => $facultyConflict ? 0 : 15,
            'room_available' => $roomConflict ? 0 : 15,
            'section_available' => $sectionConflict ? 0 : 10,
            'fits_subject_hours' => $fitsHours ? 10 : 0,
            'preferred_day_combination' => $dayPatternBonus,
            'student_friendly_time' => $studentFriendlyBonus,
        ];
        $score = ($daysAllowed && $withinPolicy) ? array_sum($points) : 0;

        $reasons = [
            ['label' => 'Faculty Available', 'met' => ! $facultyConflict, 'type' => $facultyConflict ? 'danger' : 'success'],
            ['label' => 'Room Available', 'met' => ! $roomConflict, 'type' => $roomConflict ? 'danger' : 'success'],
            ['label' => 'Section Available', 'met' => ! $sectionConflict, 'type' => $sectionConflict ? 'danger' : 'success'],
            ['label' => $fitsHours ? 'Fits Subject Hours' : 'Shorter Than Required Hours', 'met' => $fitsHours, 'type' => $fitsHours ? 'success' : 'warning'],
            ['label' => $daysAllowed ? 'Within Available Class Days' : 'Outside Available Class Days', 'met' => $daysAllowed, 'type' => $daysAllowed ? 'success' : 'danger'],
            ['label' => $overlapsLunch ? 'Overlaps Lunch Break (12:00 PM - 1:00 PM)' : 'Does Not Overlap Lunch Break', 'met' => ! $overlapsLunch, 'type' => $overlapsLunch ? 'danger' : 'success'],
        ];

        if ($fitsPattern) {
            $reasons[] = $tier && $tier['tier'] === 'Preferred'
                ? ['label' => 'Preferred Day Combination', 'met' => true, 'type' => 'success']
                : ['label' => ($tier['tier'] ?? 'Non-preferred').' Day Combination', 'met' => false, 'type' => 'warning'];
        }

        if ($isEvening) {
            $reasons[] = ['label' => 'Evening Schedule', 'met' => false, 'type' => 'warning'];
        } else {
            $reasons[] = ['label' => 'Student-Friendly Time', 'met' => true, 'type' => 'success'];
        }

        $isManualOverride = $facultyConflict || $roomConflict || $sectionConflict || ! $fitsHours || ! $daysAllowed || ! $withinPolicy;

        $overrideReason = null;
        if ($isManualOverride) {
            $issues = [];
            if ($facultyConflict) {
                $issues[] = 'the selected faculty is already booked at this day/time'.$this->conflictSuffix($facultyConflictRow, 'teaching');
            }
            if ($roomConflict) {
                $issues[] = 'the selected room is already booked at this day/time'.$this->conflictSuffix($roomConflictRow, 'for');
            }
            if ($sectionConflict) {
                $issues[] = 'this section already has another subject at this day/time'.$this->conflictSuffix($sectionConflictRow, null);
            }
            if (! $fitsHours) {
                $issues[] = 'this block is shorter than the subject\'s required weekly hours';
            }
            if (! $daysAllowed) {
                $issues[] = 'one or more selected days is not an Available Class Day for the active Academic Term';
            }
            if ($overlapsLunch) {
                $issues[] = 'this time overlaps the Lunch Break (12:00 PM - 1:00 PM)';
            } elseif (! $withinPolicy) {
                $issues[] = 'this time falls outside the active Academic Term\'s Class Start/End Time';
            }
            $overrideReason = 'This time '.implode(', ', $issues).'.';
        }

        return [
            'days' => $days,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'meetings_per_week' => count($days),
            'expected_meetings' => $expectedMeetings,
            'required_weekly_hours' => $totalHours,
            'subject_type' => $this->meetingPatternService->label($subject),
            'score' => $score,
            'score_max' => 100,
            'score_breakdown' => $points,
            'reasons' => $reasons,
            'confidence' => $this->confidenceFromScore($score),
            'manual_override' => $isManualOverride,
            'override_reason' => $overrideReason,
            // Distinguishes a genuine double-booking (Faculty, Room, or
            // this Section already occupied at this exact day/time)
            // from a merely soft/informational mismatch (off-pattern
            // meeting count, a trimmed duration, outside class hours).
            // manual_override alone can't tell these apart — it's true
            // for both — but only a hard_conflict should ever block
            // "Accept All & Save"/"Save Schedule"; a soft mismatch
            // stays a freely-overridable warning by design.
            'hard_conflict' => $facultyConflict || $roomConflict || $sectionConflict,
            // Structured version of the same conflicts named in
            // override_reason above — the review panel uses this to
            // decide which row(s) to highlight red and to build its
            // own "already booked by <Subject> — <Section>" copy
            // without having to re-parse the sentence.
            'conflict_details' => array_values(array_filter([
                $facultyConflict ? $this->conflictDetail('faculty', $facultyConflictRow) : null,
                $roomConflict ? $this->conflictDetail('room', $roomConflictRow) : null,
                $sectionConflict ? $this->conflictDetail('section', $sectionConflictRow) : null,
            ])),
        ];
    }

    /**
     * " — currently teaching CS101 for BSIT 2A" style suffix naming
     * exactly which existing Section/Subject occupies the slot a
     * Manual Override just conflicted with, so the Registrar knows
     * what to reschedule instead of just "already booked".
     */
    private function conflictSuffix(?SectionSubject $conflict, ?string $verb): string
    {
        if (! $conflict) {
            return '';
        }

        $conflict->loadMissing(['subject:id,subject_code', 'section:id,section_code']);

        $subjectCode = $conflict->subject?->subject_code;
        $sectionCode = $conflict->section?->section_code;

        if (! $subjectCode && ! $sectionCode) {
            return '';
        }

        $what = $verb ? " ({$verb} " : ' (';
        $what .= $subjectCode ?? 'another subject';
        if ($sectionCode) {
            $what .= " — {$sectionCode}";
        }
        $what .= ')';

        return $what;
    }

    /**
     * Structured counterpart to conflictSuffix() — one entry per
     * conflicting resource, for the frontend to render without
     * string-parsing override_reason.
     */
    private function conflictDetail(string $resource, ?SectionSubject $conflict): ?array
    {
        if (! $conflict) {
            return null;
        }

        $conflict->loadMissing(['subject:id,subject_code,subject_title', 'section:id,section_code']);

        return [
            'resource' => $resource, // 'faculty' | 'room' | 'section'
            'subject_code' => $conflict->subject?->subject_code,
            'subject_title' => $conflict->subject?->subject_title,
            'section_code' => $conflict->section?->section_code,
            'days' => $conflict->days,
            'start_time' => $conflict->start_time,
            'end_time' => $conflict->end_time,
        ];
    }

    /**
     * How many existing schedule conflicts a Faculty member would
     * have at the row's currently selected Days/Start/End Time. Zero
     * (neutral) if the row has no Day/Time on it yet — ranking then
     * falls back to Department + Load alone.
     */
    private function facultyConflictCount(Faculty $faculty, ?SectionSubject $current): int
    {
        if (! $current || ! $current->days || ! $current->start_time || ! $current->end_time) {
            return 0;
        }

        $dayTokens = array_filter(explode(',', $current->days));

        $conflict = $this->conflictService->findFacultyConflict(
            $faculty->id, $current->id, $dayTokens, $current->start_time, $current->end_time
        );

        return $conflict ? 1 : 0;
    }

    /**
     * How many scheduling conflicts a candidate Room would have at
     * the row's currently selected Day/Time. Zero (neutral) if the
     * row doesn't have a Day/Time selected yet.
     */
    private function roomConflictCount(Room $room, ?SectionSubject $current): int
    {
        if (! $current || ! $current->days || ! $current->start_time || ! $current->end_time) {
            return 0;
        }

        $dayTokens = array_filter(explode(',', $current->days));

        $conflict = $this->conflictService->findRoomConflict(
            $room->id, $current->id, $dayTokens, $current->start_time, $current->end_time
        );

        return $conflict ? 1 : 0;
    }

}