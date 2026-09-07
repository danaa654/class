<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use App\Services\PasswordPolicyService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SYSTEM SETTINGS — system-wide configuration only.
 *
 * Dedicated management pages (Faculty, Rooms, Subjects, Sections,
 * Programs, Colleges, Curriculum, Academic Calendar) are NOT touched
 * here and continue to own their own records. This controller only
 * reads/writes App\Services\SettingsService, plus links out to the
 * Academic Calendar for scheduling-window/lunch/working-day values
 * that already live on the Active School Year.
 *
 * Every save action is gated by role using the app's existing
 * Spatie roles (Administrator / Registrar / Assistant Dean /
 * Dean / OIC) — nobody bypasses the existing permission system here.
 */
class SettingsController extends Controller
{
    /** Roles allowed to change each settings group. Administrator can
     *  always edit every group regardless of what's listed here. */
    private const EDITABLE_BY = [
        'general' => ['Registrar'],
        'workload' => ['Registrar'],
        'rooms' => ['Registrar'],
        'autoschedule' => ['Registrar'],
        'irregular' => ['Registrar'],
        'notifications' => ['Registrar', 'Assistant Dean', 'Dean', 'OIC'],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PasswordPolicyService $passwordPolicy,
    ) {}

    /**
     * Display the Settings page. Only sends the groups + fields the
     * signed-in user's role is actually allowed to see, per the
     * "System Settings" acceptance criteria.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();
        $isAdministrator = $role === 'Administrator';
        $activeSchoolYear = SchoolYear::active();

        // Every role can see General + the read-only Academic Calendar
        // summary + their own Notification preferences. Everything else is Administrator/
        // Registrar territory (day-to-day scheduling policy), matching
        // "Dean/OIC: only settings relevant to their college" and
        // "Assistant Dean: only settings relevant to their assigned
        // responsibilities" — neither manages school-wide scheduling
        // configuration today.
        $visibleGroups = match (true) {
            $isAdministrator, $role === 'Registrar' => [
                'general', 'academic', 'workload', 'rooms',
                'autoschedule', 'irregular', 'notifications', 'system',
            ],
            default => ['general', 'academic', 'notifications'],
        };

        return Inertia::render('Settings/Index', [
            'visibleGroups' => $visibleGroups,
            'editableGroups' => $this->editableGroupsFor($user, $role, $isAdministrator, $visibleGroups),
            'settings' => $this->settings->all(),
            // Read by every role's Manage Account tab to render the
            // live password requirements checklist — no longer
            // Administrator-only now that the read-only Security tab
            // has been folded into Manage Account (see PasswordPolicyService).
            'passwordPolicy' => $this->passwordPolicy->requirements(),
            'schoolYear' => $activeSchoolYear ? [
                'name' => $activeSchoolYear->name,
                'class_start_time' => $activeSchoolYear->classStartTime(),
                'class_end_time' => $activeSchoolYear->classEndTime(),
                'time_interval' => $activeSchoolYear->intervalMinutes(),
                'available_days' => $activeSchoolYear->allowedDays(),
                'lunch_start' => SchoolYear::LUNCH_BREAK_START,
                'lunch_end' => SchoolYear::LUNCH_BREAK_END,
            ] : null,
            'system' => $isAdministrator ? [
                'app_version' => config('app.version', 'dev'),
                'laravel_version' => app()->version(),
                'database_status' => $this->databaseStatus(),
                'last_configuration_update' => $this->settings->all() !== [] ? optional(
                    \App\Models\SystemSetting::query()->latest('updated_at')->first()
                )->updated_at : null,
            ] : null,
            'activeSessions' => $isAdministrator ? ActiveSessionController::activeSessions($request) : [],
            // Lazily evaluated — only actually queried when the
            // Activity Log tab is open (initial load, or a
            // router.reload({ only: ['activityLog'] }) triggered by
            // paging/filtering), same reasoning as activeSessions but
            // via Inertia::optional() since this one also needs
            // pagination. (Inertia::optional() is this app's
            // inertiajs/inertia-laravel v3.2 name for what older
            // versions called Inertia::lazy() — same behavior.)
            'activityLog' => $isAdministrator
                ? Inertia::optional(fn () => ActivityLogController::activityLog($request))
                : [],
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $this->authorizeGroup($request, 'general');

        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'school_short_name' => ['nullable', 'string', 'max:50'],
            'school_address' => ['nullable', 'string', 'max:500'],
            'school_contact' => ['nullable', 'string', 'max:100'],
            'school_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $values = [
            'general.school_name' => $data['school_name'],
            'general.school_short_name' => $data['school_short_name'] ?? '',
            'general.school_address' => $data['school_address'] ?? '',
            'general.school_contact' => $data['school_contact'] ?? '',
            'general.school_email' => $data['school_email'] ?? '',
        ];

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('school-logo', 'public');
            $values['general.school_logo_path'] = Storage::url($path);
        }

        $this->settings->setMany($values, $request->user()->id);

        return back()->with('success', 'General settings updated.');
    }

    public function updateWorkload(Request $request): RedirectResponse
    {
        $this->authorizeGroup($request, 'workload');

        $data = $request->validate([
            'max_teaching_load' => ['required', 'integer', 'min:1', 'max:42'],
            'warning_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'overloaded_threshold' => ['required', 'integer', 'min:0', 'max:200'],
            'allow_admin_override' => ['required', 'boolean'],
            // Daily teaching-hours ceiling — independent of the
            // weekly/semester Max Teaching Load above. 0 = uncapped.
            // Enforced by ScheduleConflictService::findFacultyDailyHoursViolation()
            // on every manual/auto schedule placement.
            'max_daily_teaching_hours' => ['required', 'integer', 'min:0', 'max:16'],
        ]);

        if ($data['warning_threshold'] > $data['overloaded_threshold']) {
            return back()->withErrors(['warning_threshold' => 'The warning threshold cannot exceed the overloaded threshold.']);
        }

        $this->settings->setMany([
            'workload.max_teaching_load' => $data['max_teaching_load'],
            'workload.warning_threshold' => $data['warning_threshold'],
            'workload.overloaded_threshold' => $data['overloaded_threshold'],
            'workload.allow_admin_override' => $data['allow_admin_override'],
            'workload.max_daily_teaching_hours' => $data['max_daily_teaching_hours'],
        ], $request->user()->id);

        return back()->with('success', 'Faculty workload settings updated.');
    }

    public function updateRooms(Request $request): RedirectResponse
    {
        $this->authorizeGroup($request, 'rooms');

        $data = $request->validate([
            'enable_recommendations' => ['required', 'boolean'],
            'priority_order' => ['required', 'array', 'min:1'],
            'priority_order.*' => [Rule::in(['subject_requirement', 'college', 'department_program', 'capacity', 'availability'])],
        ]);

        $this->settings->setMany([
            'rooms.enable_recommendations' => $data['enable_recommendations'],
            'rooms.priority_order' => array_values($data['priority_order']),
        ], $request->user()->id);

        return back()->with('success', 'Room settings updated.');
    }

    public function updateAutoSchedule(Request $request): RedirectResponse
    {
        $this->authorizeGroup($request, 'autoschedule');

        $data = $request->validate([
            'mode' => ['required', Rule::in(['balanced', 'constraint_priority', 'optimization_priority'])],
            'priorities' => ['required', 'array'],
            'priorities.*' => [Rule::in(['high', 'medium', 'low'])],
            'enable_daily_load_optimization' => ['required', 'boolean'],
            'max_continuous_duration_hours' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $this->settings->setMany([
            'autoschedule.mode' => $data['mode'],
            'autoschedule.priorities' => $data['priorities'],
            'autoschedule.enable_daily_load_optimization' => $data['enable_daily_load_optimization'],
            'autoschedule.max_continuous_duration_hours' => $data['max_continuous_duration_hours'],
        ], $request->user()->id);

        return back()->with('success', 'Auto Schedule settings updated.');
    }

    public function updateIrregular(Request $request): RedirectResponse
    {
        $this->authorizeGroup($request, 'irregular');

        $data = $request->validate([
            'default_estimated_students' => ['required', 'integer', 'min:1', 'max:200'],
            'enable_merge_recommendations' => ['required', 'boolean'],
            'default_mode' => ['required', Rule::in(['auto_select', 'recommend_merge', 'independent_class'])],
        ]);

        $this->settings->setMany([
            'irregular.default_estimated_students' => $data['default_estimated_students'],
            'irregular.enable_merge_recommendations' => $data['enable_merge_recommendations'],
            'irregular.default_mode' => $data['default_mode'],
        ], $request->user()->id);

        return back()->with('success', 'Irregular scheduling settings updated.');
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $this->authorizeGroup($request, 'notifications');

        $data = $request->validate([
            'schedule_conflict' => ['required', 'boolean'],
            'workload_warning' => ['required', 'boolean'],
            'room_conflict' => ['required', 'boolean'],
            'unscheduled_subject' => ['required', 'boolean'],
            'merge_recommendation' => ['required', 'boolean'],
        ]);

        $this->settings->setMany([
            'notifications.schedule_conflict' => $data['schedule_conflict'],
            'notifications.workload_warning' => $data['workload_warning'],
            'notifications.room_conflict' => $data['room_conflict'],
            'notifications.unscheduled_subject' => $data['unscheduled_subject'],
            'notifications.merge_recommendation' => $data['merge_recommendation'],
        ], $request->user()->id);

        return back()->with('success', 'Notification preferences updated.');
    }

    /**
     * Non-destructive System / Maintenance action: drop the cached
     * settings snapshot so the very next read rebuilds it from the
     * database. Safe to run any time; never deletes data.
     */
    public function refreshCache(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Administrator'), 403);

        $this->settings->setMany([]);

        return back()->with('success', 'Configuration cache refreshed.');
    }

    private function authorizeGroup(Request $request, string $group): void
    {
        $user = $request->user();

        if ($user->hasRole('Administrator')) {
            return;
        }

        $allowedRoles = self::EDITABLE_BY[$group] ?? [];

        abort_unless($user->hasAnyRole($allowedRoles), 403);
    }

    /**
     * @param  list<string>  $visibleGroups
     * @return list<string>
     */
    private function editableGroupsFor($user, ?string $role, bool $isAdministrator, array $visibleGroups): array
    {
        // 'system' is informational for everyone (including
        // Administrator), and 'security' is deliberately read-only for
        // now (see index() — no updateSecurity() action exists), so
        // both are excluded from editableGroups regardless of role.
        $readOnlyGroups = ['system', 'security'];

        if ($isAdministrator) {
            return array_values(array_diff($visibleGroups, $readOnlyGroups));
        }

        return array_values(array_filter($visibleGroups, function (string $group) use ($role, $readOnlyGroups) {
            if (in_array($group, $readOnlyGroups, true)) {
                return false;
            }

            return in_array($role, self::EDITABLE_BY[$group] ?? [], true);
        }));
    }

    private function databaseStatus(): string
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();

            return 'Connected';
        } catch (\Throwable) {
            return 'Unavailable';
        }
    }
}