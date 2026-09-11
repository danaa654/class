<?php

namespace App\Services;

use App\Models\SchoolYear;
use App\Models\Subject;

/**
 * MEETING PATTERN INTELLIGENCE.
 *
 * Decides HOW MANY times a week a Subject should meet, and WHICH Day
 * combinations are acceptable, before RecommendationService::recommendTimes()
 * ever starts hunting for an open Start/End Time.
 *
 * This replaces the old hardcoded "always try Mon-Wed-Fri, then
 * Tue-Thu, then Mon-Wed..." list that used to live directly on
 * RecommendationService. That list mixed 3-meeting and 2-meeting
 * patterns together with no regard for what kind of Subject it was,
 * which is why the Auto Generate engine kept producing MWF lectures.
 *
 * Rules (per the Registrar's spec):
 *   - Lecture subjects        -> 2 meetings/week (Mon&Wed or Tue&Thu preferred)
 *   - Laboratory subjects     -> 1 meeting/week, one continuous block
 *   - Special subjects        -> 1 meeting/week (NSTP, PE, OJT, Thesis,
 *                                 Capstone, Practicum, Seminar, ...)
 *   - 3-meetings/week (MWF, TThS, ...) is NEVER generated unless a
 *     future administrator-facing config explicitly raises the cap.
 *
 * Everything here is config-driven (config/scheduling.php) so a future
 * "3 meetings/week" toggle, or per-college/per-subject overrides, can
 * be added without touching RecommendationService or AutoScheduleService
 * at all — they only ever ask this service "how many meetings, which
 * Day combos", never encode day-pattern logic themselves.
 */
class MeetingPatternService
{
    public const TYPE_LECTURE = 'lecture';

    public const TYPE_LABORATORY = 'laboratory';

    public const TYPE_SPECIAL = 'special';

    /**
     * Subject Title/Code keywords that mark a Subject as a "Special"
     * subject (defaults to 1 meeting/week) regardless of its
     * lecture/laboratory hour split. Checked case-insensitively.
     *
     * @var list<string>
     */
    private const SPECIAL_KEYWORDS = [
        'NSTP',
        'ROTC',
        'CWTS',
        'PE ', // "PE 1", "PE 2" — trailing space avoids matching e.g. "TYPE"
        'P.E.',
        'PHYSICAL EDUCATION',
        'OJT',
        'ON-THE-JOB TRAINING',
        'THESIS',
        'CAPSTONE',
        'PRACTICUM',
        'INTERNSHIP',
        'SEMINAR',
    ];

    /**
     * Fallback meetings-per-week for each Subject Type, used whenever
     * config/scheduling.php doesn't override it.
     *
     * @var array<string, int>
     */
    private const DEFAULT_MEETINGS_PER_WEEK = [
        self::TYPE_LECTURE => 2,
        self::TYPE_LABORATORY => 1,
        self::TYPE_SPECIAL => 1,
    ];

    /**
     * MEETING PATTERN TABLE — the data-driven source of truth for
     * which Day combination goes with which meeting frequency, and
     * how strongly each is preferred. This is the "Pattern / Meetings
     * / Priority" table from the spec: adding, removing, or
     * re-ranking a Day combination is a one-line change here, never a
     * change to the scoring/search code in this service or in
     * RecommendationService.
     *
     * `priority` is an arbitrary 0-100 scale, higher = more
     * preferred. dayGroups() sorts candidates by this value
     * (descending) before returning them, and RecommendationService
     * uses it directly to score/label a combination as "Preferred
     * Day Combination" vs progressively lower tiers.
     *
     * @var list<array{name: string, days: list<string>, meetings: int, priority: int}>
     */
    private const MEETING_PATTERN_TABLE = [
        // 2 meetings/week — lecture subjects.
        ['name' => 'MW', 'days' => ['Mon', 'Wed'], 'meetings' => 2, 'priority' => 100],
        ['name' => 'TTH', 'days' => ['Tue', 'Thu'], 'meetings' => 2, 'priority' => 100],
        ['name' => 'TF', 'days' => ['Tue', 'Fri'], 'meetings' => 2, 'priority' => 90],
        ['name' => 'WS', 'days' => ['Wed', 'Sat'], 'meetings' => 2, 'priority' => 85],
        ['name' => 'MT', 'days' => ['Mon', 'Tue'], 'meetings' => 2, 'priority' => 80],
        ['name' => 'WH', 'days' => ['Wed', 'Thu'], 'meetings' => 2, 'priority' => 75],
        ['name' => 'MF', 'days' => ['Mon', 'Fri'], 'meetings' => 2, 'priority' => 60],
        ['name' => 'TW', 'days' => ['Tue', 'Wed'], 'meetings' => 2, 'priority' => 60],

        // 1 meeting/week — laboratory / special subjects. Listed in
        // the Registrar's preferred day order (Mon -> Sun); Sat/Sun
        // are automatically dropped by dayGroups() unless the active
        // School Year's Class Days has them checked.
        ['name' => 'Monday', 'days' => ['Mon'], 'meetings' => 1, 'priority' => 95],
        ['name' => 'Tuesday', 'days' => ['Tue'], 'meetings' => 1, 'priority' => 95],
        ['name' => 'Wednesday', 'days' => ['Wed'], 'meetings' => 1, 'priority' => 95],
        ['name' => 'Thursday', 'days' => ['Thu'], 'meetings' => 1, 'priority' => 95],
        ['name' => 'Friday', 'days' => ['Fri'], 'meetings' => 1, 'priority' => 100],
        ['name' => 'Saturday', 'days' => ['Sat'], 'meetings' => 1, 'priority' => 80],
        ['name' => 'Sunday', 'days' => ['Sun'], 'meetings' => 1, 'priority' => 70],

        // 3 meetings/week — future-proofing only (per the spec's
        // "Future Compatibility" section). Never reached unless a
        // future config raises max_meetings_per_week to 3+.
        ['name' => 'MWF', 'days' => ['Mon', 'Wed', 'Fri'], 'meetings' => 3, 'priority' => 100],
        ['name' => 'TTHS', 'days' => ['Tue', 'Thu', 'Sat'], 'meetings' => 3, 'priority' => 90],
    ];

    /**
     * Fallback ordered Day-combination candidates, keyed by meetings
     * per week — derived from MEETING_PATTERN_TABLE (kept as a
     * generated constant, not authored by hand, so the table above
     * stays the single source of truth). Every inner array's length
     * MUST equal its key.
     *
     * @var array<int, list<list<string>>>
     */
    private static ?array $defaultDayGroupsCache = null;

    /**
     * Builds the DEFAULT_DAY_GROUPS-equivalent array from
     * MEETING_PATTERN_TABLE, sorted by priority (highest first)
     * within each meetings-per-week bucket. Cached per-request since
     * the table itself is a class constant.
     *
     * @return array<int, list<list<string>>>
     */
    private static function defaultDayGroups(): array
    {
        if (self::$defaultDayGroupsCache !== null) {
            return self::$defaultDayGroupsCache;
        }

        $byMeetings = [];
        foreach (self::MEETING_PATTERN_TABLE as $pattern) {
            $byMeetings[$pattern['meetings']][] = $pattern;
        }

        foreach ($byMeetings as $meetings => $patterns) {
            usort($patterns, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);
            $byMeetings[$meetings] = array_map(fn (array $p) => $p['days'], $patterns);
        }

        return self::$defaultDayGroupsCache = $byMeetings;
    }

    /**
     * Looks up a Day combination's entry in MEETING_PATTERN_TABLE
     * (order-insensitive — ['Thu', 'Tue'] matches ['Tue', 'Thu']),
     * used by RecommendationService to label/score a combination by
     * its preference tier rather than a flat yes/no.
     *
     * Returns null for a combination that isn't in the table at all
     * (e.g. a fully arbitrary manual override like Mon+Thu).
     *
     * @param  list<string>  $days
     * @return array{name: string, priority: int, tier: string}|null
     */
    public function priorityTier(array $days): ?array
    {
        $sorted = $days;
        sort($sorted);

        foreach (self::MEETING_PATTERN_TABLE as $pattern) {
            $patternDays = $pattern['days'];
            sort($patternDays);

            if ($patternDays === $sorted) {
                return [
                    'name' => $pattern['name'],
                    'priority' => $pattern['priority'],
                    'tier' => match (true) {
                        $pattern['priority'] >= 95 => 'Preferred',
                        $pattern['priority'] >= 80 => 'Second Priority',
                        $pattern['priority'] >= 65 => 'Third Priority',
                        default => 'Lowest Priority',
                    },
                ];
            }
        }

        return null;
    }

    /**
     * Classify a Subject into Lecture / Laboratory / Special so the
     * right default meeting frequency can be looked up. Special
     * (keyword) subjects take priority over the hours-based check,
     * since e.g. "NSTP" or "Thesis Writing" may have hours recorded
     * either way but should still only meet once a week.
     */
    public function classify(Subject $subject): string
    {
        $haystack = ' '.mb_strtoupper(trim($subject->subject_title.' '.$subject->subject_code)).' ';

        foreach (self::SPECIAL_KEYWORDS as $keyword) {
            if (str_contains($haystack, mb_strtoupper($keyword))) {
                return self::TYPE_SPECIAL;
            }
        }

        $lecture = (int) $subject->lecture_hours;
        $laboratory = (int) $subject->laboratory_hours;

        // Purely a Laboratory subject (no lecture component) -> 1
        // continuous block/week. Subjects with BOTH a lecture and a
        // laboratory component are still scheduled as a Lecture for
        // meeting-pattern purposes (2 meetings/week); their lab hours
        // are additional weekly hours split across those meetings,
        // same as before.
        if ($laboratory > 0 && $lecture === 0) {
            return self::TYPE_LABORATORY;
        }

        return self::TYPE_LECTURE;
    }

    /**
     * How many times per week this Subject should meet. Config
     * (config/scheduling.php -> meeting_patterns.meetings_per_week.*)
     * can override the per-type default; max_meetings_per_week acts
     * as a hard ceiling so a future misconfiguration can never
     * accidentally reintroduce 3+ meeting/week schedules until that
     * ceiling is deliberately raised.
     *
     * PREFER ONE STRAIGHT BLOCK — if the Subject's full weekly hours
     * already fit inside a single continuous meeting
     * (max_continuous_hours), it's scheduled as ONE meeting/week,
     * full stop, no matter what its Type's textbook default says.
     * A 2-hour Lecture subject does not need to be split MW/TTH just
     * because "Lecture" defaults to 2x/week — that default only
     * matters once the hours genuinely don't fit in one sitting.
     *
     * Hour-aware bump: once a Subject's total weekly hours (lecture +
     * laboratory) DON'T fit in a single reasonable block
     * (max_continuous_hours, default 3h), the frequency is bumped up
     * — starting from the Type's default — so the extra hours get
     * their own meeting instead of one unrealistically long block.
     * E.g. a "Special" (1x/week default) Capstone subject worth 4
     * hours/week becomes 2 meetings/week (2h + 2h) automatically,
     * still capped by max_meetings_per_week.
     */
    public function meetingsPerWeek(Subject $subject): int
    {
        $totalHours = (int) $subject->lecture_hours + (int) $subject->laboratory_hours;
        $maxContinuousHours = (float) config('scheduling.meeting_patterns.max_continuous_hours', 3);

        if ($totalHours > 0 && $maxContinuousHours > 0 && $totalHours <= $maxContinuousHours) {
            return 1;
        }

        $type = $this->classify($subject);

        $configured = config("scheduling.meeting_patterns.meetings_per_week.{$type}");
        $default = self::DEFAULT_MEETINGS_PER_WEEK[$type] ?? 1;
        $meetings = is_int($configured) && $configured > 0 ? $configured : $default;

        $max = (int) config('scheduling.meeting_patterns.max_meetings_per_week', 2);

        $meetings = $this->bumpForRequiredHours($subject, $meetings, $max);

        return max(1, min($meetings, $max));
    }

    /**
     * If the Subject's total weekly hours don't fit in
     * max_continuous_hours (config, default 3h) per meeting at the
     * currently-proposed frequency, raises the frequency just enough
     * to fit — never below the Type default, never above $max. This
     * is what lets a subject's *required hours* drive the meeting
     * count instead of a keyword-based Type guess alone.
     */
    private function bumpForRequiredHours(Subject $subject, int $meetings, int $max): int
    {
        $totalHours = (int) $subject->lecture_hours + (int) $subject->laboratory_hours;

        if ($totalHours <= 0) {
            return $meetings;
        }

        $maxContinuousHours = (float) config('scheduling.meeting_patterns.max_continuous_hours', 3);

        if ($maxContinuousHours <= 0) {
            return $meetings;
        }

        while ($meetings < $max && ($totalHours / $meetings) > $maxContinuousHours) {
            $meetings++;
        }

        return $meetings;
    }

    /**
     * Ordered list of Day-combination candidates to try for this
     * Subject, best/most-preferred first. Every entry's length equals
     * meetingsPerWeek($subject). RecommendationService::recommendTimes()
     * iterates these in order, trying each against every candidate
     * Start Time, and simply moves to the next pattern if a
     * combination turns out to conflict — the existing "Smart Search"
     * fallback behavior is unchanged, only which patterns are offered.
     *
     * Every combination is additionally filtered against the active
     * Academic Term's Available Class Days (Global Scheduling
     * Settings) — a Day combination is only offered if EVERY Day in
     * it is currently checked. If Saturday is unchecked, no
     * combination containing Sat is ever returned; if Sunday is
     * checked later, Sunday-containing combinations become available
     * automatically without any code change here.
     *
     * @return list<list<string>>
     */
    public function dayGroups(Subject $subject): array
    {
        return $this->dayGroupsForCount($this->meetingsPerWeek($subject));
    }

    /**
     * Same day-combination lookup as dayGroups(), but keyed directly
     * by a meeting COUNT rather than derived from a Subject's
     * textbook classification.
     *
     * This exists for callers like SiblingSectionPatternService that
     * need to match an already-scheduled donor's ACTUAL meeting
     * frequency — which the Registrar may have manually overridden
     * away from the Subject's normal pattern (e.g. a Lecture subject
     * trimmed from 2x/week down to a single Saturday session). Using
     * dayGroups($subject) there would only ever offer combinations
     * for the subject's "should be" frequency, so a donor that's
     * already an override could never be copied — this method lets
     * the copy honor whatever frequency the donor was actually saved
     * with, not what the subject's declared hours say it should be.
     *
     * @return list<list<string>>
     */
    public function dayGroupsForCount(int $meetings): array
    {
        $configured = config("scheduling.meeting_patterns.day_groups.{$meetings}");
        $candidates = (is_array($configured) && ! empty($configured))
            ? $configured
            : (self::defaultDayGroups()[$meetings] ?? [['Mon']]);

        $allowedDays = $this->allowedDays();

        return array_values(array_filter(
            $candidates,
            fn (array $days) => empty(array_diff($days, $allowedDays))
        ));
    }

    /**
     * The Day tokens the active School Year currently allows the
     * Auto Generate engine to schedule on (Scheduling Preferences ->
     * Class Days). Falls back to SchoolYear's built-in default when
     * no School Year is Active yet.
     *
     * @return list<string>
     */
    public function allowedDays(): array
    {
        return SchoolYear::active()?->allowedDays() ?? SchoolYear::DEFAULT_CLASS_DAYS;
    }

    /**
     * Human-readable label for the Subject's Type, surfaced in the
     * Auto Generate review panel / recommendation reasons so the
     * Registrar can see *why* a given meeting pattern was chosen.
     */
    public function label(Subject $subject): string
    {
        return match ($this->classify($subject)) {
            self::TYPE_LABORATORY => 'Laboratory',
            self::TYPE_SPECIAL => 'Special',
            default => 'Lecture',
        };
    }
}