<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * SYSTEM SETTINGS — single source of truth for system-wide
 * configuration (see Settings/Index.vue + SettingsController).
 *
 * This service intentionally does NOT own scheduling policy that
 * already lives on the Active School Year (class start/end time, time
 * interval, available days, lunch break — see App\Models\SchoolYear).
 * Those keep coming from SchoolYear::active() exactly as they do
 * today; Settings only links to the Academic Calendar page for them.
 *
 * Everything this service DOES own is genuinely new, system-wide
 * behavior: school identity, faculty workload thresholds, room
 * recommendation behavior, Auto Schedule optimization preferences,
 * irregular section defaults, and notification toggles.
 *
 * It does NOT own an "academic defaults" (default academic
 * year/semester) group or a "meeting frequency" enable/disable
 * policy — both were removed as redundant: the Academic Calendar
 * (SchoolYear) is the single source of truth for the active year and
 * semester, and 1x/2x-per-week meeting patterns are permanently
 * supported (no 3x option) via MeetingPatternService/config/scheduling.php,
 * not a toggle here.
 *
 * All values are cached under a single "system_settings" key and
 * invalidated automatically whenever set()/setMany() is called, so
 * the Auto Schedule engine and every other consumer always reads the
 * latest saved configuration without needing a server restart.
 */
class SettingsService
{
    private const CACHE_KEY = 'system_settings';

    public function __construct(private readonly ActivityLogService $activityLog = new ActivityLogService) {}

    /**
     * Every recognised setting key, grouped, with its default value
     * and scalar type. This is the single place new settings get
     * registered — SettingsController validates against this list and
     * the Settings page reads/writes exactly these keys.
     *
     * @return array<string, array{group: string, type: string, default: mixed}>
     */
    public static function schema(): array
    {
        return [
            // ---- General -------------------------------------------------
            'general.school_name' => ['group' => 'general', 'type' => 'string', 'default' => config('app.name', 'Classly')],
            'general.school_short_name' => ['group' => 'general', 'type' => 'string', 'default' => ''],
            'general.school_address' => ['group' => 'general', 'type' => 'string', 'default' => ''],
            'general.school_contact' => ['group' => 'general', 'type' => 'string', 'default' => ''],
            'general.school_email' => ['group' => 'general', 'type' => 'string', 'default' => ''],
            'general.school_logo_path' => ['group' => 'general', 'type' => 'string', 'default' => ''],

            // ---- Faculty & workload ---------------------------------------
            // Mirrors FacultyWorkloadService::WARNING_THRESHOLD /
            // OVERLOADED_THRESHOLD as *editable* defaults. The service
            // constants remain the fallback if no setting is saved yet.
            'workload.max_teaching_load' => ['group' => 'workload', 'type' => 'int', 'default' => 24],
            'workload.max_daily_teaching_hours' => ['group' => 'workload', 'type' => 'int', 'default' => 8],
            'workload.warning_threshold' => ['group' => 'workload', 'type' => 'int', 'default' => 85],
            'workload.overloaded_threshold' => ['group' => 'workload', 'type' => 'int', 'default' => 100],
            'workload.allow_admin_override' => ['group' => 'workload', 'type' => 'bool', 'default' => true],

            // ---- Rooms -----------------------------------------------------
            'rooms.enable_recommendations' => ['group' => 'rooms', 'type' => 'bool', 'default' => true],
            'rooms.priority_order' => [
                'group' => 'rooms',
                'type' => 'json',
                'default' => ['subject_requirement', 'college', 'department_program', 'capacity', 'availability'],
            ],

            // ---- Auto Schedule / AI ----------------------------------------
            'autoschedule.mode' => ['group' => 'autoschedule', 'type' => 'string', 'default' => 'balanced'], // balanced|constraint_priority|optimization_priority
            'autoschedule.priorities' => [
                'group' => 'autoschedule',
                'type' => 'json',
                'default' => [
                    'room_availability' => 'high',
                    'faculty_workload' => 'medium',
                    'section_daily_load' => 'medium',
                    'minimize_idle_gaps' => 'medium',
                    'room_suitability' => 'medium',
                    'preferred_meeting_frequency' => 'low',
                    'merge_irregular_classes' => 'medium',
                    'college_program_room_restrictions' => 'high',
                ],
            ],
            'autoschedule.enable_daily_load_optimization' => ['group' => 'autoschedule', 'type' => 'bool', 'default' => true],
            'autoschedule.max_continuous_duration_hours' => ['group' => 'autoschedule', 'type' => 'int', 'default' => 5],

            // ---- Irregular sections ------------------------------------------
            'irregular.default_estimated_students' => ['group' => 'irregular', 'type' => 'int', 'default' => 5],
            'irregular.enable_merge_recommendations' => ['group' => 'irregular', 'type' => 'bool', 'default' => true],
            'irregular.default_mode' => ['group' => 'irregular', 'type' => 'string', 'default' => 'auto_select'], // auto_select|recommend_merge|independent_class

            // ---- Security ---------------------------------------------------
            // Consumed by App\Services\PasswordPolicyService, which is the
            // ONLY place these turn into an actual validation rule chain.
            // Deliberately read-only from the Settings > Security tab for
            // now (see SettingsController — 'security' is in
            // visibleGroups but intentionally absent from EDITABLE_BY) —
            // this just makes the enforced policy visible, not editable.
            //
            // Scope reminder: this policy governs a USER SETTING THEIR
            // OWN PASSWORD (voluntary self-service change, forced change
            // via must_change_password, or an expired password). It is
            // deliberately NOT applied when an Administrator creates or
            // edits another user's account (UsersController::rules()) —
            // that stays a simple 'min:8' so an Administrator can set up
            // a new CCS Dean/Registrar/etc. account without friction.
            'security.min_password_length' => ['group' => 'security', 'type' => 'int', 'default' => 10],
            'security.require_uppercase' => ['group' => 'security', 'type' => 'bool', 'default' => true],
            'security.require_number' => ['group' => 'security', 'type' => 'bool', 'default' => true],
            'security.require_symbol' => ['group' => 'security', 'type' => 'bool', 'default' => true],
            // 0 = expiry disabled. See PasswordPolicyService::isExpired()
            // and App\Http\Middleware\EnsurePasswordIsCurrent.
            'security.password_expiry_days' => ['group' => 'security', 'type' => 'int', 'default' => 0],

            // ---- Notifications --------------------------------------------
            'notifications.schedule_conflict' => ['group' => 'notifications', 'type' => 'bool', 'default' => true],
            'notifications.workload_warning' => ['group' => 'notifications', 'type' => 'bool', 'default' => true],
            'notifications.room_conflict' => ['group' => 'notifications', 'type' => 'bool', 'default' => true],
            'notifications.unscheduled_subject' => ['group' => 'notifications', 'type' => 'bool', 'default' => true],
            'notifications.merge_recommendation' => ['group' => 'notifications', 'type' => 'bool', 'default' => true],
        ];
    }

    /**
     * Every key that belongs to a given group (e.g. "general").
     *
     * @return list<string>
     */
    public static function keysForGroup(string $group): array
    {
        return array_keys(array_filter(
            self::schema(),
            fn (array $definition) => $definition['group'] === $group,
        ));
    }

    /**
     * Every setting, keyed by dotted key, cast to its real type and
     * falling back to its default when never saved. Cached until the
     * next set()/setMany() call.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = SystemSetting::query()->pluck('value', 'key');

            $resolved = [];

            foreach (self::schema() as $key => $definition) {
                $resolved[$key] = $stored->has($key)
                    ? $this->cast($stored->get($key), $definition['type'])
                    : $definition['default'];
            }

            return $resolved;
        });
    }

    /**
     * Every setting for one group only, e.g. all("rooms").
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $all = $this->all();

        return array_intersect_key($all, array_flip(self::keysForGroup($group)));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Persist one setting and invalidate the cache so every consumer
     * (including the Auto Schedule engine) sees it immediately.
     */
    public function set(string $key, mixed $value, ?int $updatedByUserId = null): void
    {
        $this->setMany([$key => $value], $updatedByUserId);
    }

    /**
     * Persist several settings at once (one Settings-tab "Save
     * Changes" click) and invalidate the cache exactly once.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?int $updatedByUserId = null): void
    {
        if (empty($values)) {
            Cache::forget(self::CACHE_KEY);

            return;
        }

        $schema = self::schema();

        foreach ($values as $key => $value) {
            $type = $schema[$key]['type'] ?? 'string';

            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'group' => $schema[$key]['group'] ?? 'general',
                    'value' => $this->encode($value, $type),
                    'updated_at' => now(),
                    'updated_by' => $updatedByUserId,
                ],
            );
        }

        Cache::forget(self::CACHE_KEY);

        // Single choke point for every Settings save (updateGeneral,
        // updateWorkload, updateRooms, updateAutoSchedule,
        // updateIrregular, updateNotifications all funnel through
        // here) — one Activity Log row per save, naming the group(s)
        // touched rather than every individual key, so a multi-field
        // save doesn't spam the log with one line per field.
        if ($updatedByUserId) {
            $groups = collect($values)
                ->keys()
                ->map(fn (string $key) => $schema[$key]['group'] ?? 'general')
                ->unique()
                ->values()
                ->implode(', ');

            $actor = User::find($updatedByUserId);

            $this->activityLog->record(
                ActivityLogService::SETTINGS_UPDATED,
                trim(($actor?->full_name ?? 'A user')." updated the {$groups} settings."),
                actor: $actor,
            );
        }
    }

    private function cast(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            'json' => json_decode($raw, true) ?? [],
            default => $raw,
        };
    }

    private function encode(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}