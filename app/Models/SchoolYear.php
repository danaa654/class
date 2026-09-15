<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A School Year also carries the Scheduling Preferences the Auto
 * Schedule AI reads before it ever suggests a Faculty/Room/Time
 * combination:
 *
 *   - Class Start Time / Class End Time — the earliest/latest a class
 *     may be scheduled.
 *   - Time Interval — the increment (in minutes) the engine slices
 *     the day into when searching for candidate start times.
 *   - Available Class Days — which days of the week the engine is
 *     allowed to generate schedules on.
 *   - Lunch Break (12:00 PM - 1:00 PM) — always excluded from Auto
 *     Schedule/Regenerate and every "Recommend Day & Time" suggestion,
 *     never editable. `lunch_start`/`lunch_end` columns exist for the
 *     record, but the scheduling engine always uses the fixed
 *     LUNCH_BREAK_START/END constants below, never the column values.
 *     This is a SOFT exclusion from the automated engine only — a
 *     Registrar/Dean/OIC may still manually place a class across
 *     lunch when they genuinely need to; ScheduleConflictService::
 *     validate() does not reject it. See overlapsLunchBreak()'s
 *     docblock.
 *
 * These settings apply to the ACTIVE School Year only — see active().
 * RecommendationService/MeetingPatternService pull from here (falling
 * back to sensible defaults if no School Year is Active yet) so
 * nothing in the scheduling engine hardcodes these values.
 */
class SchoolYear extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The Lunch Break window. Hardcoded and never user-editable — the
     * Auto Schedule AI and every Recommend Day/Time suggestion must
     * never propose a class that overlaps this period, regardless of
     * what else is configured. NOT a hard save-time block, though: a
     * Registrar/Dean/OIC may still manually assign a class across
     * lunch if they genuinely need to — see ScheduleConflictService::
     * validate(), which deliberately does not check this.
     */
    public const LUNCH_BREAK_START = '12:00';

    public const LUNCH_BREAK_END = '13:00';

    /**
     * Every Day token the "Class Days" checkboxes can offer, in
     * calendar order. Sunday is included (unchecked by default) so it
     * becomes available automatically the moment it's checked —
     * nothing else needs to change for that to work.
     *
     * @var list<string>
     */
    public const ALL_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * Class Days default — Monday through Friday.
     *
     * @var list<string>
     */
    public const DEFAULT_CLASS_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

    /**
     * Every Time Interval value considered valid. Fixed at 30 Minutes
     * only — the Academic Term form no longer exposes a Time Interval
     * choice; it's shown as a locked information row and always saved
     * as DEFAULT_TIME_INTERVAL_MINUTES (see
     * AcademicTermController@resolveSchoolYear).
     *
     * @var list<int>
     */
    public const AVAILABLE_TIME_INTERVALS = [30];

    public const DEFAULT_TIME_INTERVAL_MINUTES = 30;

    public const DEFAULT_CLASS_START_TIME = '07:00';

    public const DEFAULT_CLASS_END_TIME = '17:00';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'start_year',
        'end_year',
        'status',
        'class_start_time',
        'class_end_time',
        'time_interval',
        'available_days',
        'lunch_start',
        'lunch_end',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_year' => 'integer',
        'end_year' => 'integer',
        'time_interval' => 'integer',
        'available_days' => 'array',
    ];

    /**
     * The Academic Terms built on this School Year.
     */
    public function academicTerms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }

    /**
     * The single Active School Year, if one exists. This is the
     * global scheduling policy source for the Auto Schedule AI —
     * RecommendationService/MeetingPatternService call this (rather
     * than reading columns directly) so a missing Active School Year
     * always degrades gracefully to the built-in defaults above.
     */
    public static function active(): ?self
    {
        return static::query()->where('status', 'Active')->first();
    }

    /**
     * Class Start Time as "H:i" (e.g. "07:00"), always present even
     * when no School Year is Active yet.
     */
    public function classStartTime(): string
    {
        return $this->formatTime($this->class_start_time) ?? self::DEFAULT_CLASS_START_TIME;
    }

    /**
     * Class End Time as "H:i".
     */
    public function classEndTime(): string
    {
        return $this->formatTime($this->class_end_time) ?? self::DEFAULT_CLASS_END_TIME;
    }

    /**
     * Scheduling Time Interval, in minutes.
     */
    public function intervalMinutes(): int
    {
        $interval = (int) ($this->time_interval ?: self::DEFAULT_TIME_INTERVAL_MINUTES);

        return in_array($interval, self::AVAILABLE_TIME_INTERVALS, true) ? $interval : self::DEFAULT_TIME_INTERVAL_MINUTES;
    }

    /**
     * The Day tokens (Mon/Tue/.../Sun) the Auto Schedule AI is allowed
     * to schedule on. Falls back to DEFAULT_CLASS_DAYS if the School
     * Year has no days configured (e.g. legacy rows created before
     * this feature existed).
     *
     * @return list<string>
     */
    public function allowedDays(): array
    {
        $days = is_array($this->available_days) ? array_values(array_intersect(self::ALL_DAYS, $this->available_days)) : [];

        return ! empty($days) ? $days : self::DEFAULT_CLASS_DAYS;
    }

    /**
     * Whether a given Day token is currently schedulable.
     */
    public function isDayAllowed(string $day): bool
    {
        return in_array($day, $this->allowedDays(), true);
    }

    /**
     * Every candidate start time (sliced at the configured Time
     * Interval) between Class Start Time and Class End Time, as "H:i"
     * strings, earliest first. This is the single source of truth for
     * "what times can a class even start at" — RecommendationService
     * no longer hardcodes a start-time list.
     *
     * @return list<string>
     */
    public function candidateStartTimes(): array
    {
        $interval = $this->intervalMinutes();
        $startMinutes = $this->toMinutes($this->classStartTime());
        $endMinutes = $this->toMinutes($this->classEndTime());

        $times = [];
        for ($minutes = $startMinutes; $minutes < $endMinutes; $minutes += $interval) {
            $times[] = $this->fromMinutes($minutes);
        }

        return $times;
    }

    /**
     * Whether a proposed Start/End Time falls entirely within
     * [Class Start Time, Class End Time]. Lunch Break is deliberately
     * NOT checked here — this is the hard gate ScheduleConflictService::
     * validate() enforces on every save (manual or automated), and a
     * Registrar/Dean/OIC must remain free to manually schedule across
     * lunch when they need to. See isWithinSchedulingPolicy() for the
     * combined (class hours + Lunch Break) check the automated
     * recommendation engine uses instead, purely for scoring/
     * candidate-filtering, never as a save-time block.
     */
    public function isWithinClassHours(string $startTime, string $endTime): bool
    {
        $start = $this->toMinutes($startTime);
        $end = $this->toMinutes($endTime);

        if ($start >= $end) {
            return false;
        }

        return $start >= $this->toMinutes($this->classStartTime()) && $end <= $this->toMinutes($this->classEndTime());
    }

    /**
     * Whether a proposed Start/End Time falls entirely within
     * [Class Start Time, Class End Time] AND does NOT overlap the
     * fixed Lunch Break window. This is the rule Auto Schedule/
     * Regenerate and every "Recommend Day & Time" suggestion are
     * built on — RecommendationService uses this (and
     * overlapsLunchBreak() directly) to keep the automated engine
     * from ever proposing a lunch-hour slot. It is a SOFT rule only:
     * ScheduleConflictService::validate() uses isWithinClassHours()
     * above instead, specifically so this method's Lunch Break
     * portion never blocks a manual save.
     */
    public function isWithinSchedulingPolicy(string $startTime, string $endTime): bool
    {
        return $this->isWithinClassHours($startTime, $endTime)
            && ! self::overlapsLunchBreak($startTime, $endTime);
    }

    /**
     * Whether a Start/End Time window overlaps the fixed Lunch Break
     * (12:00 PM - 1:00 PM). Used by RecommendationService to exclude
     * lunch-hour candidates from Auto Schedule/Regenerate and every
     * "Recommend Day & Time" suggestion (a SOFT exclusion from the
     * automated engine's own output), and by RoomUtilizationService
     * for accurate utilization accounting. Deliberately NOT called by
     * ScheduleConflictService::validate() — see isWithinClassHours()
     * above — so a Registrar/Dean/OIC can still manually place a
     * class across lunch when they genuinely need to; it is never a
     * hard save-time block.
     */
    public static function overlapsLunchBreak(string $startTime, string $endTime): bool
    {
        $start = self::minutesFromTime($startTime);
        $end = self::minutesFromTime($endTime);
        $lunchStart = self::minutesFromTime(self::LUNCH_BREAK_START);
        $lunchEnd = self::minutesFromTime(self::LUNCH_BREAK_END);

        return $start < $lunchEnd && $end > $lunchStart;
    }


    private function formatTime(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return substr($value, 0, 5);
    }

    /**
     * "19:00" -> "7:00 PM" — shared 12-hour display helper for
     * user-facing validation/conflict messages that quote a Class
     * Start/End Time (e.g. ScheduleConflictService, the schedule
     * update FormRequest), so times are never surfaced to the
     * Registrar in raw 24-hour form.
     */
    public static function to12Hour(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        $period = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%d:%02d %s', $hour12, $minute, $period);
    }

    private function toMinutes(string $time): int
    {
        return self::minutesFromTime($time);
    }

    private static function minutesFromTime(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }

    private function fromMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Enforce the "only one Active School Year at a time" rule.
     *
     * Whenever a School Year is saved with status Active, every other
     * School Year is flipped to Inactive. Runs on `saved` (after the
     * triggering record is persisted) so it doesn't clobber its own row,
     * and is scoped to `withTrashed()` so a soft-deleted School Year left
     * behind as Active doesn't stick around as a second "active" record.
     */
    protected static function booted(): void
    {
        static::saved(function (SchoolYear $schoolYear) {
            if ($schoolYear->status === 'Active') {
                static::withTrashed()
                    ->where('id', '!=', $schoolYear->id)
                    ->where('status', 'Active')
                    ->update(['status' => 'Inactive']);
            }
        });
    }
}