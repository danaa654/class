<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ACTIVITY LOG — the single place that writes to `activity_logs`.
 * Every controller/service that wants an entry in the Settings >
 * Activity Log tab calls ActivityLogService::record() (a thin
 * wrapper around a single ActivityLog::create()) — nothing else
 * should ever touch that table directly, same convention
 * NotificationService already enforces for `schedule_audit_logs`.
 *
 * This deliberately does NOT replace ScheduleAuditLog — field-level
 * scheduling diffs stay exactly where they are. Where a scheduling
 * action already routes through NotificationService (Section
 * created/finalized/unlocked, Subject added/removed, Faculty
 * deactivated/deleted directly), the call into here lives inside
 * NotificationService itself so both tables stay in sync from one
 * call site. Everywhere else (Settings changes, Faculty create/
 * update, Section update/delete, forced logout, password reset
 * requests) the owning controller/service calls record() directly.
 */
class ActivityLogService
{
    // Canonical action codes — kept here so every caller (and the
    // Activity Log tab's filter dropdown) references the same list
    // instead of ad hoc strings drifting out of sync.
    public const SETTINGS_UPDATED = 'SETTINGS_UPDATED';

    public const FACULTY_CREATED = 'FACULTY_CREATED';

    public const FACULTY_UPDATED = 'FACULTY_UPDATED';

    public const FACULTY_DEACTIVATED = 'FACULTY_DEACTIVATED';

    public const FACULTY_DELETED = 'FACULTY_DELETED';

    public const SECTION_CREATED = 'SECTION_CREATED';

    // A new Academic Term (School Year + Semester) was created via
    // Term Setup — see NotificationService::termCreated(). Distinct
    // from TERM_ARCHIVED/TERM_REOPENED below, which cover the two
    // ways an existing Term's lifecycle changes.
    public const TERM_CREATED = 'TERM_CREATED';

    public const SECTION_UPDATED = 'SECTION_UPDATED';

    public const SECTION_DELETED = 'SECTION_DELETED';

    public const SECTION_FINALIZED = 'SECTION_FINALIZED';

    public const SECTION_UNLOCKED = 'SECTION_UNLOCKED';

    // A semester was ended ("End Semester"/archive) while at least one
    // currently-Active college/major had zero sections for that term —
    // see AcademicTermController::archive(). Only ever recorded in
    // that "something looks skipped" case, carrying the typed reason
    // and which offerings were flagged; the ordinary/expected archive
    // (nothing missing) isn't logged here at all.
    public const TERM_ARCHIVED = 'TERM_ARCHIVED';

    // The reverse of TERM_ARCHIVED — an Admin/Registrar reopened an
    // ended semester (back to Inactive) via the dedicated Reopen
    // action — see AcademicTermController::reopen(). Always recorded,
    // unlike TERM_ARCHIVED which only logs the "something looks
    // skipped" case — undoing an ended semester is noteworthy on its
    // own regardless of why.
    public const TERM_REOPENED = 'TERM_REOPENED';

    public const SCHEDULE_UPDATED = 'SCHEDULE_UPDATED';

    public const SUBJECT_ADDED_TO_SECTION = 'SUBJECT_ADDED_TO_SECTION';

    public const SUBJECT_REMOVED_FROM_SECTION = 'SUBJECT_REMOVED_FROM_SECTION';

    public const SESSION_FORCE_LOGOUT = 'SESSION_FORCE_LOGOUT';

    public const PASSWORD_RESET_REQUESTED = 'PASSWORD_RESET_REQUESTED';

    // Administrator toggled "require password change on next login"
    // for a specific user, on or off — see
    // UsersController::updateMustChangePassword().
    public const PASSWORD_CHANGE_REQUIRED = 'PASSWORD_CHANGE_REQUIRED';

    // The user actually completed a password change themselves (via
    // Settings/Manage Account -> Change Password) — distinct from
    // PASSWORD_CHANGE_REQUIRED above, which only records an
    // Administrator flagging that a change is needed, not the change
    // itself. See ChangePasswordController::update().
    public const PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    /**
     * Every action code above, for the Activity Log tab's filter
     * dropdown — kept in one place so it can never drift out of sync
     * with what's actually recorded.
     *
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::SETTINGS_UPDATED,
            self::FACULTY_CREATED,
            self::FACULTY_UPDATED,
            self::FACULTY_DEACTIVATED,
            self::FACULTY_DELETED,
            self::SECTION_CREATED,
            self::TERM_CREATED,
            self::SECTION_UPDATED,
            self::SECTION_DELETED,
            self::SECTION_FINALIZED,
            self::SECTION_UNLOCKED,
            self::TERM_ARCHIVED,
            self::TERM_REOPENED,
            self::SCHEDULE_UPDATED,
            self::SUBJECT_ADDED_TO_SECTION,
            self::SUBJECT_REMOVED_FROM_SECTION,
            self::SESSION_FORCE_LOGOUT,
            self::PASSWORD_RESET_REQUESTED,
            self::PASSWORD_CHANGE_REQUIRED,
            self::PASSWORD_CHANGED,
        ];
    }

    /**
     * Write one Activity Log row. $actor is nullable to cover the
     * rare system-initiated case (e.g. a password reset request made
     * by someone not yet authenticated) — the row is still written,
     * just with no attributable user.
     */
    public function record(string $action, string $description, ?Model $subject = null, ?User $actor = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'created_at' => now(),
        ]);
    }
}