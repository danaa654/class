<?php

namespace App\Models;

use App\Services\SettingsService;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Dean/OIC/Assistant Dean's request to raise a Faculty member's
 * teaching load ceiling (max_teaching_units or max_weekly_hours),
 * pending Administrator/Registrar approval.
 *
 * This table is the ONLY path for a College-scoped role to change a
 * faculty's load ceiling — see FacultyPolicy::changeMaxLoad() and
 * FacultyController::update() for the enforcement side, and
 * FacultyLoadRequestController for the request/approve workflow.
 */
class FacultyLoadRequest extends Model
{
    use HasFactory;

    /**
     * Institution-wide hard ceiling. Even an approved overload request
     * can never push a faculty member's load past this, regardless of
     * who approves it — this is what stands in for CHED-style overload
     * caps used when a College is short-staffed. Keep in sync with
     * UpdateFacultyRequest/StoreFacultyRequest.
     */
    public const HARD_CAP_UNITS = 42;

    /**
     * The actual ceiling a given user may request/approve up to right
     * now — Settings > Faculty & Workload > "Max Teaching Load" (see
     * SettingsService, key workload.max_teaching_load) rather than
     * always the hardcoded institution-wide overload maximum above.
     *
     * "Allow Administrator override above the maximum load"
     * (workload.allow_admin_override) is what actually lets anyone
     * exceed that configured value: when it's on, Admin/Registrar get
     * the full HARD_CAP_UNITS ceiling (they're the only ones who can
     * approve past the normal max in the first place); when it's off,
     * even Admin/Registrar are held to the configured max, same as
     * everyone else. HARD_CAP_UNITS itself never moves — it's the one
     * number nothing in the system can be pushed past, override or
     * not.
     */
    public static function effectiveCapFor(?User $user): int
    {
        $settings = app(SettingsService::class);
        $configuredMax = (int) $settings->get('workload.max_teaching_load', 24);
        $allowOverride = (bool) $settings->get('workload.allow_admin_override', true);

        if ($configuredMax <= 0) {
            $configuredMax = self::HARD_CAP_UNITS;
        }

        if ($user && $allowOverride && AccessScope::isUnrestricted($user)) {
            return self::HARD_CAP_UNITS;
        }

        return min($configuredMax, self::HARD_CAP_UNITS);
    }

    protected $fillable = [
        'faculty_id',
        'current_max_teaching_units',
        'requested_max_teaching_units',
        'current_max_weekly_hours',
        'requested_max_weekly_hours',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'current_max_teaching_units' => 'integer',
            'requested_max_teaching_units' => 'integer',
            'current_max_weekly_hours' => 'integer',
            'requested_max_weekly_hours' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Faculty, FacultyLoadRequest> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /** @return BelongsTo<User, FacultyLoadRequest> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, FacultyLoadRequest> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}