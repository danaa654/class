<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\FacultyRequest;
use App\Models\Notification;
use App\Models\ScheduleAuditLog;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\FacultyLoadRequest;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * SCHEDULING NOTIFICATION SYSTEM — central service.
 *
 * The single place that creates Notification + ScheduleAuditLog rows.
 * Scheduling controllers/services call these methods; nothing else
 * should ever `Notification::create()` or `ScheduleAuditLog::create()`
 * directly, so recipient rules, priority, idempotency, and the
 * notification/audit split all stay consistent everywhere.
 *
 * TRANSACTION CONTRACT: every public method here must be called from
 * INSIDE the same DB::transaction() as the operation it reports on —
 * never before the write is known to succeed, never after commit. If
 * the outer transaction rolls back, these rows roll back with it for
 * free. Callers that are not already inside a transaction must wrap
 * the whole operation in DB::transaction() themselves — see
 * SectionController::finalize()/unlock()/store() for the pattern.
 *
 * ROLE AUDIT NOTE (2026-08-20 pass): the spec this pass implements
 * asks for a "Faculty" notification recipient (their own assignment/
 * schedule/availability changes). CLASSLY's Faculty records
 * (app/Models/Faculty.php) are NOT linked to a User account — there
 * is no `user_id` on Faculty, and RoleSeeder::ROLES has no "Faculty"
 * role. Faculty members don't log in to this system today, so there
 * is no recipient to notify. This service deliberately does not
 * invent a Faculty-facing notification path; if/when Faculty gets a
 * portal login, add a `faculty.user_id` FK and a recipientsForFaculty()
 * helper alongside recipientsFor() below.
 */
class NotificationService
{
    public function __construct(private readonly ActivityLogService $activityLog = new ActivityLogService) {}

    // Event types.
    public const TYPE_FINALIZED = 'SCHEDULE_FINALIZED';

    public const TYPE_UNLOCKED = 'SCHEDULE_UNLOCKED';

    public const TYPE_SCHEDULE_UPDATED = 'SCHEDULE_UPDATED';

    public const TYPE_CONFLICT = 'SCHEDULE_CONFLICT';

    public const TYPE_CONCURRENCY_CONFLICT = 'CONCURRENCY_CONFLICT';

    public const TYPE_SECTION_CREATED = 'SECTION_CREATED';

    // A new Academic Term (School Year + Semester) was created — see
    // termCreated(). Institution-wide, not Section-scoped, so every
    // College's Dean/OIC/Assistant Dean can start planning ahead of
    // the term going Active.
    public const TYPE_TERM_CREATED = 'TERM_CREATED';

    public const TYPE_SUBJECT_ADDED = 'SUBJECT_ADDED';

    // Multiple Subjects attached to a Section in one operation (e.g.
    // the Add Section modal's up-front Subjects step, or curriculum
    // generation) — one summary notification instead of one per
    // Subject. See subjectsAddedBatch().
    public const TYPE_SUBJECTS_ADDED_BATCH = 'SUBJECTS_ADDED_BATCH';

    public const TYPE_SUBJECT_REMOVED = 'SUBJECT_REMOVED';

    public const TYPE_AUTO_SCHEDULE_COMPLETED = 'AUTO_SCHEDULE_COMPLETED';

    public const TYPE_AUTO_SCHEDULE_NEEDS_ATTENTION = 'AUTO_SCHEDULE_NEEDS_ATTENTION';

    // A Dean/OIC/Assistant Dean submitted (or Admin/Registrar decided)
    // a Faculty Load Request — see FacultyLoadRequestController. Not
    // Section-scoped like everything else above, so these two use the
    // lighter writeNotification() helper instead of dispatch().
    public const TYPE_FACULTY_LOAD_REQUEST_SUBMITTED = 'FACULTY_LOAD_REQUEST_SUBMITTED';

    public const TYPE_FACULTY_LOAD_REQUEST_REVIEWED = 'FACULTY_LOAD_REQUEST_REVIEWED';

    // Faculty Management request workflow (Creation/Deactivation
    // requests) — see FacultyRequestController. Same lighter
    // writeNotification() path as the Load Request pair above.
    public const TYPE_FACULTY_REQUEST_SUBMITTED = 'FACULTY_REQUEST_SUBMITTED';

    public const TYPE_FACULTY_REQUEST_REVIEWED = 'FACULTY_REQUEST_REVIEWED';

    public const TYPE_FACULTY_ASSIGNMENTS_NEED_ATTENTION = 'FACULTY_ASSIGNMENTS_NEED_ATTENTION';

    public const TYPE_FACULTY_CREATED_DIRECTLY = 'FACULTY_CREATED_DIRECTLY';

    public const TYPE_FACULTY_UPDATED_DIRECTLY = 'FACULTY_UPDATED_DIRECTLY';

    public const TYPE_FACULTY_DEACTIVATED_DIRECTLY = 'FACULTY_DEACTIVATED_DIRECTLY';

    public const TYPE_FACULTY_DELETED_DIRECTLY = 'FACULTY_DELETED_DIRECTLY';

    public const TYPE_FACULTY_WORKLOAD_UPDATED = 'FACULTY_WORKLOAD_UPDATED';

    public const TYPE_FACULTY_WORKLOAD_OVERRIDDEN = 'FACULTY_WORKLOAD_OVERRIDDEN';

    public const TYPE_FACULTY_OVERLOAD = 'FACULTY_OVERLOAD';

    public const TYPE_FACULTY_QUALIFICATIONS_UPDATED = 'FACULTY_QUALIFICATIONS_UPDATED';

    // An Administrator flipped "Require password change on next
    // login" for a user in User Management — see
    // UsersController::updateMustChangePassword(). Sent to the
    // affected user only, so they know to go change it; never sent
    // when the requirement is being cancelled.
    public const TYPE_PASSWORD_CHANGE_REQUIRED = 'PASSWORD_CHANGE_REQUIRED';

    // Priority levels (spec Section 13). Never escalate to CRITICAL
    // for routine events — nothing in this service currently uses it;
    // it's reserved for a finalized schedule found invalid or a data
    // integrity problem, neither of which this pass introduces a
    // detector for.
    public const PRIORITY_INFO = 'INFO';

    public const PRIORITY_IMPORTANT = 'IMPORTANT';

    public const PRIORITY_WARNING = 'WARNING';

    public const PRIORITY_CRITICAL = 'CRITICAL';

    /**
     * A new Section was added to the schedule. Notifies the Dean/OIC
     * of the Section's College so they know a new block/section now
     * exists under their program before anyone starts scheduling it.
     */
    public function created(Section $section, User $actor): void
    {
        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SECTION_CREATED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Section Created',
            message: "{$section->section_code} was added by {$actor->full_name}.",
            data: [
                'section_code' => $section->section_code,
                'section_type' => $section->section_type,
                'academic_year' => $section->academic_year,
                'semester' => $section->semester,
                'year_level' => $section->year_level,
            ],
            auditAction: 'SECTION_CREATED',
        );
    }

    /**
     * A finalized Section's schedule was locked. Notifies the
     * Dean/OIC of the Section's College. Spec Section 2A.
     */
    public function finalized(Section $section, User $actor): void
    {
        $subjectCount = $section->sectionSubjects()->count();

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_FINALIZED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Schedule Finalized',
            message: "{$section->section_code} schedule finalized by {$actor->full_name}.",
            data: [
                'section_code' => $section->section_code,
                'academic_year' => $section->academic_year,
                'semester' => $section->semester,
                'finalized_by' => $actor->full_name,
                'subjects_scheduled' => $subjectCount,
                'finalized_at' => now()->toIso8601String(),
            ],
            auditAction: 'FINALIZED',
        );
    }

    /**
     * A finalized Section's schedule was unlocked, re-opening it for
     * editing. Notifies the Dean/OIC of the Section's College. Spec
     * Section 2B.
     */
    public function unlocked(Section $section, User $actor, string $reason): void
    {
        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_UNLOCKED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Schedule Unlocked',
            message: "{$section->section_code} schedule was unlocked by {$actor->full_name}. Reason: {$reason}",
            data: [
                'section_code' => $section->section_code,
                'unlocked_by' => $actor->full_name,
                'reason' => $reason,
            ],
            auditAction: 'UNLOCKED',
        );
    }

    /**
     * One or more fields on a SectionSubject's schedule changed in a
     * single save. Collects the field-level diffs into ONE
     * notification per save operation (spec Section 2C — never one
     * notification per field) and writes one audit row per changed
     * field (spec Section 15 — audit stays field-granular even though
     * the notification doesn't).
     *
     * @param  list<array{field: string, old: string|null, new: string|null}>  $changes
     */
    public function scheduleUpdated(Section $section, SectionSubject $subject, User $actor, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $subject->loadMissing('subject');
        $subjectCode = $subject->subject?->subject_code ?? "Subject #{$subject->subject_id}";

        $summaryLines = collect($changes)
            ->map(fn (array $change) => "{$change['field']}:\n".($change['old'] ?? '—').' → '.($change['new'] ?? '—'))
            ->all();

        $message = "{$section->section_code} schedule was modified.\n\n{$subjectCode}\n".implode("\n\n", $summaryLines);

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SCHEDULE_UPDATED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Schedule Modified',
            message: $message,
            data: [
                'section_code' => $section->section_code,
                'subject_code' => $subjectCode,
                'changes' => $changes,
            ],
            auditAction: 'SCHEDULE_UPDATED',
            sectionSubject: $subject,
            // One audit row per field changed — see docblock.
            auditFieldRows: $changes,
        );
    }

    /**
     * A new Academic Term (School Year + Semester) was created via
     * Term Setup. Unlike Section-scoped notifications (recipientsFor()),
     * a Term isn't tied to one College — every College's Dean/OIC/
     * Assistant Dean is affected, since it's what they'll eventually
     * schedule against. Notifies them plus Admin/Registrar (in case
     * the Term was created by an Assistant Dean or similar) so
     * everyone can start planning ahead of the Term going Active.
     */
    public function termCreated(AcademicTerm $term, User $actor): void
    {
        $term->loadMissing('schoolYear', 'semester');
        $label = "{$term->schoolYear?->start_year}-{$term->schoolYear?->end_year} • {$term->semester?->name}";

        $recipients = User::query()
            ->role([...AccessScope::COLLEGE_SCOPED_ROLES, AccessScope::ASSISTANT_DEAN_ROLE])
            ->get()
            ->concat($this->adminRecipients())
            ->unique('id')
            ->reject(fn (User $u) => $u->is($actor))
            ->values();

        foreach ($recipients as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_TERM_CREATED,
                priority: self::PRIORITY_INFO,
                title: 'Academic Term Created',
                message: "{$label} was created by {$actor->full_name}.",
                data: [
                    'academic_term_id' => $term->id,
                    'label' => $label,
                    'status' => $term->status,
                ],
            );
        }

        $this->activityLog->record(
            ActivityLogService::TERM_CREATED,
            "Academic Term Created: {$label} was added by {$actor->full_name}.",
            $term,
            $actor,
        );
    }

    /**
     * A Subject was added to a Section (manually, or via curriculum
     * generation). Spec Section 6. Notifies Dean/OIC.
     */
    public function subjectAdded(Section $section, SectionSubject $sectionSubject, User $actor): void
    {
        $sectionSubject->loadMissing('subject');
        $subjectCode = $sectionSubject->subject?->subject_code ?? "Subject #{$sectionSubject->subject_id}";

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SUBJECT_ADDED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Subject Added',
            message: "{$subjectCode} was added to {$section->section_code} by {$actor->full_name}.",
            data: ['section_code' => $section->section_code, 'subject_code' => $subjectCode],
            auditAction: 'SUBJECT_ADDED',
            sectionSubject: $sectionSubject,
        );
    }

    /**
     * Several Subjects were added to a Section in a single operation
     * (e.g. the Add Section modal's up-front Subjects step). Fires
     * one summary notification/audit row instead of one per Subject,
     * so attaching a full curriculum's worth of subjects doesn't
     * flood the Dean/OIC's notification bell. Callers with exactly
     * one Subject should use subjectAdded() instead so the recipient
     * sees which specific subject it was.
     */
    public function subjectsAddedBatch(Section $section, int $count, User $actor): void
    {
        if ($count < 1) {
            // Nothing to report — mirrors autoScheduleFinished()'s
            // no-op guard above (spec Section 1).
            return;
        }

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SUBJECTS_ADDED_BATCH,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Subjects Added',
            message: "{$count} subjects were added to {$section->section_code} by {$actor->full_name}.",
            data: ['section_code' => $section->section_code, 'subject_count' => $count],
            auditAction: 'SUBJECT_ADDED',
        );
    }

    /**
     * A Subject was removed from a Section. Spec Section 6. Notifies
     * Dean/OIC. $subjectCode is passed in (rather than loaded from
     * $sectionSubject) because the row is already deleted by the time
     * this is called — see SectionSubjectController::destroy().
     */
    public function subjectRemoved(Section $section, string $subjectCode, User $actor): void
    {
        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SUBJECT_REMOVED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Subject Removed',
            message: "{$subjectCode} was removed from {$section->section_code} by {$actor->full_name}.",
            data: ['section_code' => $section->section_code, 'subject_code' => $subjectCode],
            auditAction: 'SUBJECT_REMOVED',
        );
    }

    /**
     * An Auto Generate run finished. Spec Section 5 — COMPLETED
     * (IMPORTANT) when every subject was placed, NEEDS_ATTENTION
     * (WARNING) when some subjects couldn't be scheduled and need
     * manual attention. One notification per run, never per subject.
     *
     * @param  list<array{subject_code: string, reason: string}>  $unresolved
     */
    public function autoScheduleFinished(Section $section, User $actor, int $scheduled, int $total, array $unresolved): void
    {
        if ($total === 0) {
            // Nothing to schedule — not an event worth a notification
            // (spec Section 1: no notifications for insignificant
            // no-op actions).
            return;
        }

        $needsAttention = ! empty($unresolved);

        if ($needsAttention) {
            $unresolvedList = collect($unresolved)
                ->map(fn (array $row) => "• {$row['subject_code']}: {$row['reason']}")
                ->implode("\n");

            $this->dispatch(
                section: $section,
                actor: $actor,
                type: self::TYPE_AUTO_SCHEDULE_NEEDS_ATTENTION,
                priority: self::PRIORITY_WARNING,
                title: 'Auto Schedule Requires Attention',
                message: "Auto schedule for {$section->section_code} requires attention — {$scheduled} of {$total} subjects scheduled.\n\n{$unresolvedList}",
                data: [
                    'section_code' => $section->section_code,
                    'scheduled' => $scheduled,
                    'total' => $total,
                    'unresolved' => $unresolved,
                    'generated_by' => $actor->full_name,
                ],
                auditAction: 'AUTO_SCHEDULE_NEEDS_ATTENTION',
            );

            return;
        }

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_AUTO_SCHEDULE_COMPLETED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Auto Schedule Completed',
            message: "Auto schedule completed for {$section->section_code} — {$scheduled} of {$total} subjects scheduled by {$actor->full_name}.",
            data: [
                'section_code' => $section->section_code,
                'scheduled' => $scheduled,
                'total' => $total,
                'generated_by' => $actor->full_name,
            ],
            auditAction: 'AUTO_SCHEDULE_COMPLETED',
        );
    }

    /**
     * A Dean/OIC/Assistant Dean submitted a Faculty Load Request.
     * Notifies every Administrator/Registrar (they're the only ones
     * who can review it — see FacultyLoadRequestPolicy::review()) so
     * it shows up in the bell dropdown, not just when someone happens
     * to open the Faculty Load Requests modal.
     */
    public function facultyLoadRequestSubmitted(FacultyLoadRequest $loadRequest, User $actor): void
    {
        $loadRequest->loadMissing('faculty');
        $facultyName = trim(($loadRequest->faculty->first_name ?? '').' '.($loadRequest->faculty->last_name ?? ''));

        $recipients = $this->adminRecipients()->reject(fn (User $u) => $u->is($actor));

        foreach ($recipients as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_LOAD_REQUEST_SUBMITTED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Load Request Submitted',
                message: "{$actor->full_name} requested a load increase for {$facultyName} ({$loadRequest->current_max_teaching_units} → {$loadRequest->requested_max_teaching_units} units).",
                data: [
                    'faculty_load_request_id' => $loadRequest->id,
                    'faculty_name' => $facultyName,
                    'current_max_teaching_units' => $loadRequest->current_max_teaching_units,
                    'requested_max_teaching_units' => $loadRequest->requested_max_teaching_units,
                ],
            );
        }
    }

    /**
     * Admin/Registrar approved or denied a Faculty Load Request.
     * Notifies whoever originally submitted it (requested_by) so
     * they're not left checking the modal to find out.
     */
    public function facultyLoadRequestReviewed(FacultyLoadRequest $loadRequest, User $actor): void
    {
        $loadRequest->loadMissing('faculty', 'requestedBy');
        $recipient = $loadRequest->requestedBy;

        // Admin/Registrar reviewing their own submission (they're
        // also allowed to create requests, see
        // FacultyLoadRequestPolicy::create()) — no self-notification.
        if (! $recipient || $recipient->is($actor)) {
            return;
        }

        $facultyName = trim(($loadRequest->faculty->first_name ?? '').' '.($loadRequest->faculty->last_name ?? ''));
        $decision = $loadRequest->status; // 'Approved' | 'Denied'

        $message = "Your load increase request for {$facultyName} was {$decision} by {$actor->full_name}.";
        if ($loadRequest->decision_note) {
            $message .= " Note: {$loadRequest->decision_note}";
        }

        $this->writeNotification(
            recipient: $recipient,
            actor: $actor,
            type: self::TYPE_FACULTY_LOAD_REQUEST_REVIEWED,
            priority: self::PRIORITY_IMPORTANT,
            title: "Faculty Load Request {$decision}",
            message: $message,
            data: [
                'faculty_load_request_id' => $loadRequest->id,
                'faculty_name' => $facultyName,
                'status' => $decision,
                'decision_note' => $loadRequest->decision_note,
            ],
        );
    }

    /**
     * Admin/Registrar applied a load change directly, with no Pending
     * step for anyone to review (see FacultyLoadRequestController::
     * store()'s actorIsReviewer branch — they already have direct
     * edit rights, so there's no separate approval to notify anyone
     * about). The Dean/OIC of the Faculty's College (or Assistant
     * Dean, if the Faculty has no College — General Education) still
     * needs to know their faculty member's ceiling changed, even
     * though nobody on their end had to request it — same courtesy
     * facultyLoadRequestReviewed() gives a Dean/OIC whose own
     * submission was decided, just for the case where nothing was
     * submitted in the first place.
     */
    public function facultyLoadUpdatedDirectly(FacultyLoadRequest $loadRequest, User $actor): void
    {
        $loadRequest->loadMissing('faculty');
        $faculty = $loadRequest->faculty;
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->collegeRecipientsForFaculty($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_LOAD_REQUEST_REVIEWED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Load Updated',
                message: "{$actor->full_name} updated {$facultyName}'s teaching load ceiling ({$loadRequest->current_max_teaching_units} → {$loadRequest->requested_max_teaching_units} units).",
                data: [
                    'faculty_load_request_id' => $loadRequest->id,
                    'faculty_name' => $facultyName,
                    'current_max_teaching_units' => $loadRequest->current_max_teaching_units,
                    'requested_max_teaching_units' => $loadRequest->requested_max_teaching_units,
                ],
            );
        }
    }

    /**
     * Admin/Registrar changed a faculty member's Maximum Teaching
     * Units straight from the Edit Faculty form (FacultyController::
     * update()) — a plain roster edit, not the Faculty Load Request
     * workflow, so there's no FacultyLoadRequest row to attach this
     * to. Notifies the full facultyRecipients() set (Admin/Registrar +
     * the Faculty's own College Dean/OIC + Assistant Dean, actor
     * excluded) per spec Section 4 ("Registrar adds workload to CTE
     * faculty → notify Admin + CTE Dean/OIC/Assistant Dean, not
     * Registrar"). Only call this when the value actually changed —
     * see FacultyController::update().
     *
     * If the new ceiling leaves the Faculty member's actual assigned
     * load ($workloadService->evaluate()'s 'current') ABOVE it, this
     * also fires a separate, higher-priority overload() notification
     * (spec Section 5) — pass $workloadService so this method can
     * check without every caller having to remember to.
     */
    public function facultyMaxLoadEditedDirectly(Faculty $faculty, User $actor, int $oldUnits, int $newUnits, ?\App\Services\FacultyWorkloadService $workloadService = null): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_WORKLOAD_UPDATED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Workload Updated',
                message: "{$facultyName}'s teaching workload has been updated by {$actor->full_name}.",
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'college_id' => $faculty->college_id,
                    'previous_max_teaching_units' => $oldUnits,
                    'new_max_teaching_units' => $newUnits,
                    'added_units' => $newUnits - $oldUnits,
                ],
            );
        }

        if ($workloadService) {
            $evaluation = $workloadService->evaluate($faculty);

            if ($evaluation['max'] > 0 && $evaluation['current'] > $evaluation['max']) {
                $this->facultyOverload($faculty, $actor, $evaluation, $oldUnits, $newUnits);
            }
        }
    }

    /**
     * A Faculty member's actual assigned teaching load now exceeds
     * their (possibly just-lowered) ceiling — spec Section 5. Higher
     * priority than the plain workload-updated notice above, and
     * fired IN ADDITION to it, not instead of it, so recipients see
     * both "what changed" and "why it now needs attention". Same
     * facultyRecipients() audience.
     *
     * @param  array<string, mixed>  $evaluation  FacultyWorkloadService::evaluate() output.
     */
    private function facultyOverload(Faculty $faculty, User $actor, array $evaluation, int $previousMax, int $newMax): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_OVERLOAD,
                priority: self::PRIORITY_WARNING,
                title: 'Faculty Workload Requires Attention',
                message: "{$facultyName} has been assigned additional workload and is now above the standard teaching load.",
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'college_id' => $faculty->college_id,
                    'previous_max_teaching_units' => $previousMax,
                    'new_max_teaching_units' => $newMax,
                    'current_assigned_units' => $evaluation['current'],
                    'assigned_by' => $actor->full_name,
                ],
            );
        }
    }

    /**
     * Someone with changeMaxLoad access (Admin, Registrar, Dean, OIC,
     * or Assistant Dean) confirmed past the "exceeds allowable
     * workload" warning in SectionSubjectController@update to schedule
     * a Faculty member anyway. Fired on every override, independent of
     * whether the AUTO-RAISE CEILING block in that same method also
     * happened to move max_teaching_units — the override itself is the
     * event worth surfacing, not just its side effect on the ceiling.
     * Same facultyRecipients() audience as the rest of this section:
     * Admin/Registrar + the Faculty's own College Dean/OIC + Assistant
     * Dean, actor excluded.
     *
     * @param  array<string, mixed>  $workloadWarning  FacultyWorkloadService's warning payload — carries faculty_id/subject_code/projected.
     */
    public function facultyWorkloadOverridden(Faculty $faculty, SectionSubject $sectionSubject, User $actor, array $workloadWarning): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));
        $sectionSubject->loadMissing('section', 'subject');

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_WORKLOAD_OVERRIDDEN,
                priority: self::PRIORITY_WARNING,
                title: 'Teaching Load Limit Overridden',
                message: "{$actor->full_name} overrode the teaching load limit warning to schedule {$facultyName} for {$sectionSubject->subject?->subject_code}".
                    ($sectionSubject->section?->section_code ? " ({$sectionSubject->section->section_code})" : '').'.',
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'college_id' => $faculty->college_id,
                    'section_subject_id' => $sectionSubject->id,
                    'section_id' => $sectionSubject->section_id,
                    'subject_code' => $workloadWarning['subject_code'] ?? $sectionSubject->subject?->subject_code,
                    'projected_units' => $workloadWarning['projected'] ?? null,
                ],
            );
        }
    }

    /**
     * A Scheduling operation failed because of a Room/Faculty/Section
     * conflict rejected by validate() before any write. Optional per
     * spec Section 3 — notifies Admin/Registrar only, never the
     * Dean/OIC (a failed operation isn't "their" event), and only for
     * conflicts a caller judges worth surfacing (spec explicitly says
     * not to fire this on every failed drag/drop — see
     * SectionSubjectController, which does NOT call this from the
     * routine `$conflictErrors` 422 path, only from concurrency
     * rejections via concurrencyConflict() below). No audit row: a
     * failed/rolled-back operation never happened as far as the audit
     * trail is concerned.
     */
    public function conflict(Section $section, User $actor, string $summary): void
    {
        $this->dispatchToAdmins(
            section: $section,
            actor: $actor,
            type: self::TYPE_CONFLICT,
            priority: self::PRIORITY_WARNING,
            title: 'Schedule Conflict',
            message: $summary,
        );
    }

    /**
     * Two users raced to write the same Section's schedule and the
     * second (this) request lost — rejected under
     * ScheduleConflictService::checkSectionVersion()'s optimistic
     * lock. Spec Section 4: "do not create duplicate notifications
     * for the same concurrency conflict" — the existing 5s dedup
     * window in dispatchToAdmins() covers a retry storm from the same
     * losing actor; a genuinely new race a few seconds later is rare
     * enough to be worth its own notification.
     */
    public function concurrencyConflict(Section $section, User $actor, string $summary): void
    {
        $this->dispatchToAdmins(
            section: $section,
            actor: $actor,
            type: self::TYPE_CONCURRENCY_CONFLICT,
            priority: self::PRIORITY_WARNING,
            title: 'Concurrent Scheduling Conflict',
            message: $summary,
        );
    }

    /**
     * Shared plumbing behind finalized()/unlocked()/scheduleUpdated()/
     * subjectAdded()/subjectRemoved()/autoScheduleFinished(): resolve
     * recipients, write one Notification per recipient, write the
     * audit row(s), all idempotency-guarded against duplicate
     * double-click/retry submissions of the same logical operation.
     *
     * @param  list<array{field: string, old: string|null, new: string|null}>|null  $auditFieldRows
     */
    /**
     * A Dean/OIC/Assistant Dean submitted a Faculty Creation or
     * Deletion request. Notifies every Administrator/Registrar
     * (they're the only ones who can review it — see
     * FacultyRequestPolicy::review()).
     */
    public function facultyRequestSubmitted(FacultyRequest $facultyRequest, User $actor): void
    {
        $label = $facultyRequest->request_type === 'Creation' ? 'creation' : 'deletion';
        $subject = $facultyRequest->request_type === 'Creation'
            ? trim(($facultyRequest->payload['first_name'] ?? '').' '.($facultyRequest->payload['last_name'] ?? ''))
            : trim(($facultyRequest->faculty?->first_name ?? '').' '.($facultyRequest->faculty?->last_name ?? ''));

        foreach ($this->adminRecipients()->reject(fn (User $u) => $u->is($actor)) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_REQUEST_SUBMITTED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty '.ucfirst($label).' Request Submitted',
                message: "{$actor->full_name} requested a faculty {$label} for {$subject}.",
                data: [
                    'faculty_request_id' => $facultyRequest->id,
                    'request_type' => $facultyRequest->request_type,
                    'faculty_name' => $subject,
                ],
            );
        }

        $this->auditFacultyRequest($actor, 'FACULTY_'.strtoupper($label).'_REQUEST_SUBMITTED', $facultyRequest);
    }

    /**
     * Admin/Registrar approved or rejected a Faculty Creation or
     * Deletion request. Notifies whoever originally submitted it.
     */
    public function facultyRequestReviewed(FacultyRequest $facultyRequest, User $actor): void
    {
        $facultyRequest->loadMissing('requestedBy', 'faculty');
        $recipient = $facultyRequest->requestedBy;

        $label = $facultyRequest->request_type === 'Creation' ? 'creation' : 'deletion';
        $subject = $facultyRequest->request_type === 'Creation'
            ? trim(($facultyRequest->payload['first_name'] ?? '').' '.($facultyRequest->payload['last_name'] ?? ''))
            : trim(($facultyRequest->faculty?->first_name ?? '').' '.($facultyRequest->faculty?->last_name ?? ''));

        $decision = $facultyRequest->status; // 'Approved' | 'Rejected'
        $message = "Your faculty {$label} request for {$subject} was {$decision} by {$actor->full_name}.";
        if ($facultyRequest->decision_note) {
            $message .= " Note: {$facultyRequest->decision_note}";
        }

        if ($recipient && ! $recipient->is($actor)) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_REQUEST_REVIEWED,
                priority: self::PRIORITY_IMPORTANT,
                title: "Faculty ".ucfirst($label)." Request {$decision}",
                message: $message,
                data: [
                    'faculty_request_id' => $facultyRequest->id,
                    'request_type' => $facultyRequest->request_type,
                    'faculty_name' => $subject,
                    'status' => $decision,
                    'decision_note' => $facultyRequest->decision_note,
                ],
            );
        }

        $this->auditFacultyRequest($actor, 'FACULTY_'.strtoupper($label).'_REQUEST_'.strtoupper($decision), $facultyRequest);
    }

    /**
     * A Faculty member with active assignments was just deactivated
     * or deleted (via an approved request OR a direct Admin/Registrar
     * action) and those assignments now need manual attention — no
     * automatic reassignment happens (spec Section 11). Notifies the
     * full facultyRecipients() set (Admin/Registrar + Dean/OIC/
     * Assistant Dean of the Faculty's College) so the vacancy doesn't
     * go unnoticed until the next schedule run.
     *
     * Priority is CRITICAL for a deletion (spec Section 6: existing
     * schedules may be affected and there's no going back), WARNING
     * for a deactivation (still reversible by re-activating the
     * Faculty record).
     *
     * @param  array<string, mixed>  $impact  FacultyWorkloadService::deactivationImpact() output.
     * @param  string  $action  Past-tense verb describing what just happened to the faculty ('deactivated' or 'deleted').
     */
    public function facultyAssignmentsNeedAttention(Faculty $faculty, array $impact, User $actor, string $action = 'deactivated'): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));
        $priority = $action === 'deleted' ? self::PRIORITY_CRITICAL : self::PRIORITY_WARNING;
        $title = $action === 'deleted' ? 'Faculty Deletion Requires Attention' : 'Faculty Assignment Requires Attention';
        $message = $action === 'deleted'
            ? "{$facultyName} is scheduled to teach existing subjects. Faculty deletion requires review because existing schedules may be affected ({$impact['subject_count']} subject(s) across {$impact['section_count']} section(s))."
            : "{$facultyName} was {$action} with {$impact['subject_count']} active subject(s) across {$impact['section_count']} section(s) — these are now vacant and need reassignment.";

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_ASSIGNMENTS_NEED_ATTENTION,
                priority: $priority,
                title: $title,
                message: $message,
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'college_id' => $faculty->college_id,
                    'subject_codes' => $impact['subject_codes'],
                    'section_codes' => $impact['section_codes'],
                ],
            );
        }
    }

    /**
     * A Dean/OIC/Assistant Dean/Admin/Registrar added a new Faculty
     * member directly (FacultyController::store() — Faculty creation
     * is now a direct action for every Scheduling-side role, see that
     * controller's docblock). Notifies Administrator, Registrar, and
     * Assistant Dean so the institution-wide/GenEd side isn't
     * blindsided by a new hire landing on the roster with no
     * request/approval trail — the creation-side counterpart to the
     * courtesy facultyDeactivatedDirectly()/facultyDeletedDirectly()
     * already give the College side for the opposite action.
     *
     * Recipients are Admin+Registrar+Assistant Dean (always) PLUS the
     * Dean/OIC of the Faculty's own College when it has one — so
     * whichever of these roles actually performed the action, every
     * *other* stakeholder still hears about it: an Admin adding
     * someone straight into CTE still reaches CTE's own Dean/OIC, not
     * just the institution-wide side. Deduped and with the actor
     * excluded, so nobody notifies themselves of their own action.
     */
    public function facultyCreatedDirectly(Faculty $faculty, User $actor): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));
        $collegeName = $faculty->college_id ? $faculty->loadMissing('college')->college?->name : null;
        $location = $collegeName ? " to {$collegeName}" : '';

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_CREATED_DIRECTLY,
                priority: self::PRIORITY_IMPORTANT,
                title: 'New Faculty Added',
                message: "New faculty member {$facultyName} has been added{$location} by {$actor->full_name}.",
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'college_id' => $faculty->college_id,
                    'college_name' => $collegeName,
                    'added_by' => $actor->full_name,
                ],
            );
        }
    }

    /**
     * A Dean/OIC/Assistant Dean/Admin/Registrar edited a Faculty
     * member's details directly (FacultyController::update()) — any
     * SIGNIFICANT field except the teaching-load ceiling (max_
     * teaching_units/max_weekly_hours/workload_type, which has its
     * own targeted facultyMaxLoadEditedDirectly()/facultyOverload()
     * notifications). Per spec Section 7 ("Do NOT generate
     * unnecessary notifications for insignificant UI changes"), the
     * caller (FacultyController::update()) is expected to have
     * already filtered $changes down to the significant fields
     * (College, status, employment type, name, faculty id) before
     * calling this — trivial cosmetic fields (middle name, suffix,
     * remarks, contact info) are left out there, not here, so this
     * method's own contract stays simple: notify on whatever it's
     * given, skip only when nothing is given at all.
     *
     * Same recipient set as facultyCreatedDirectly() — the central
     * facultyRecipients() resolver: Administrator + Registrar + the
     * Faculty's own College Dean/OIC + Assistant Dean, actor excluded.
     *
     * @param  list<array{field: string, old: mixed, new: mixed}>  $changes
     */
    public function facultyUpdatedDirectly(Faculty $faculty, User $actor, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        $fieldLabels = [
            'faculty_id' => 'Faculty ID',
            'first_name' => 'First Name',
            'middle_name' => 'Middle Name',
            'last_name' => 'Last Name',
            'suffix' => 'Suffix',
            'employment_type' => 'Employment Type',
            'college_id' => 'College',
            'status' => 'Status',
            'email' => 'Email',
            'contact_number' => 'Contact Number',
            'remarks' => 'Remarks',
        ];

        $summary = collect($changes)
            ->pluck('field')
            ->map(fn (string $field) => $fieldLabels[$field] ?? Str::headline($field))
            ->implode(', ');

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_UPDATED_DIRECTLY,
                priority: self::PRIORITY_INFO,
                title: 'Faculty Information Updated',
                message: "{$facultyName}'s {$summary} ".(count($changes) === 1 ? 'has' : 'have')." been updated by {$actor->full_name}.",
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'college_id' => $faculty->college_id,
                    'changes' => $changes,
                ],
            );
        }
    }

    /**
     * A Dean/OIC/Assistant Dean/Admin/Registrar added or removed
     * Subject qualifications for a Faculty member
     * (TeachingQualificationController::update()). Spec Section 8.
     * Same facultyRecipients() audience as the other direct-edit
     * notifications. Only call when $added or $removed is non-empty.
     *
     * @param  list<string>  $added    Subject codes newly qualified.
     * @param  list<string>  $removed  Subject codes no longer qualified.
     */
    public function facultyQualificationsUpdated(Faculty $faculty, User $actor, array $added, array $removed): void
    {
        if (empty($added) && empty($removed)) {
            return;
        }

        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_QUALIFICATIONS_UPDATED,
                priority: self::PRIORITY_INFO,
                title: 'Faculty Teaching Qualifications Updated',
                message: "{$facultyName}'s teaching qualifications were updated by {$actor->full_name}.",
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'college_id' => $faculty->college_id,
                    'added_subjects' => $added,
                    'removed_subjects' => $removed,
                ],
            );
        }
    }

    /**
     * Admin/Registrar deactivated a Faculty member directly
     * (FacultyController@destroy), bypassing the request workflow
     * entirely since they're already authorized to. Notifies the
     * Dean/OIC/Assistant Dean of that Faculty's College so they're
     * not blindsided, same courtesy facultyMaxLoadEditedDirectly()
     * gives for direct load-ceiling edits.
     */
    public function facultyDeactivatedDirectly(Faculty $faculty, User $actor): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_DEACTIVATED_DIRECTLY,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Deactivated',
                message: "{$actor->full_name} deactivated {$facultyName}.",
                data: ['faculty_id' => $faculty->id, 'faculty_name' => $facultyName, 'college_id' => $faculty->college_id],
            );
        }

        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => 'FACULTY_DEACTIVATED_DIRECTLY',
            'section_id' => null,
            'section_subject_id' => null,
            'field' => 'status',
            'old_value' => 'Active',
            'new_value' => 'Inactive',
            'created_at' => now(),
        ]);

        $this->activityLog->record(
            ActivityLogService::FACULTY_DEACTIVATED,
            "{$actor->full_name} deactivated faculty member {$facultyName}.",
            $faculty,
            $actor,
        );
    }

    /**
     * Admin/Registrar permanently deleted a Faculty member from the
     * roster (FacultyController@destroy — a soft delete, see that
     * method's docblock). Notifies the full facultyRecipients() set
     * (Registrar/Admin + that Faculty's own College Dean/OIC +
     * Assistant Dean, actor excluded — spec Section 6/20 Example 5)
     * so nobody on either the institution-wide or College side is
     * blindsided, and leaves an audit trail distinct from a plain
     * deactivation.
     *
     * If the Faculty still had active scheduled assignments at the
     * time of deletion, the separate facultyAssignmentsNeedAttention()
     * call right after this one in FacultyController@destroy escalates
     * to CRITICAL for exactly that reason (spec Section 6: "mark the
     * notification as HIGH PRIORITY" when existing schedules may be
     * affected) — this notification itself stays at IMPORTANT since it
     * is just "a Faculty record was deleted", not the schedule-impact
     * warning.
     */
    public function facultyDeletedDirectly(Faculty $faculty, User $actor): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->facultyRecipients($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_DELETED_DIRECTLY,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Deleted',
                message: "{$actor->full_name} deleted {$facultyName} from the Faculty Master.",
                data: ['faculty_id' => $faculty->id, 'faculty_name' => $facultyName, 'college_id' => $faculty->college_id],
            );
        }

        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => 'FACULTY_DELETED_DIRECTLY',
            'section_id' => null,
            'section_subject_id' => null,
            'field' => 'status',
            'old_value' => $faculty->status,
            'new_value' => 'Deleted',
            'created_at' => now(),
        ]);

        $this->activityLog->record(
            ActivityLogService::FACULTY_DELETED,
            "{$actor->full_name} removed faculty member {$facultyName} from the Faculty Master.",
            $faculty,
            $actor,
        );
    }

    /**
     * Generic (non-Section-scoped) audit row for the Faculty
     * Management request workflow — same table as audit(), just
     * without a Section to attach to (schedule_audit_logs.section_id
     * is nullable for exactly this reason).
     */
    private function auditFacultyRequest(User $actor, string $action, FacultyRequest $facultyRequest): void
    {
        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'section_id' => null,
            'section_subject_id' => null,
            'field' => 'faculty_request_id',
            'old_value' => null,
            'new_value' => (string) $facultyRequest->id,
            'created_at' => now(),
        ]);
    }

    private function dispatch(
        Section $section,
        User $actor,
        string $type,
        string $priority,
        string $title,
        string $message,
        array $data,
        string $auditAction,
        ?SectionSubject $sectionSubject = null,
        ?array $auditFieldRows = null,
    ): void {
        // DUPLICATE-NOTIFICATION GUARD (spec Section 17) — a
        // double-click, network retry, or duplicate frontend request
        // for the exact same logical operation (same Section, same
        // type, same actor, same SectionSubject when one applies)
        // within a short window must not fan out into repeated
        // notifications/audit rows. A real second finalize/unlock/
        // update a few seconds later is vanishingly unlikely and, if
        // it happens, is itself worth deduping.
        //
        // $sectionSubject is included in the match so that looping
        // over several subjects (e.g. attachSubjectsToSection()
        // calling subjectAdded() once per subject) doesn't have every
        // subject after the first silently dropped as a "duplicate"
        // of the same section+type+actor within the 5-second window.
        if ($this->isRecentDuplicate($section, $type, $actor, $sectionSubject)) {
            return;
        }

        $recipients = $this->recipientsFor($section, $actor);

        foreach ($recipients as $recipient) {
            $this->create($recipient, $actor, $section, $sectionSubject, $type, $priority, $title, $message, $data);
        }

        if ($auditFieldRows !== null) {
            foreach ($auditFieldRows as $change) {
                $this->audit($actor, $auditAction, $section, $sectionSubject, $change['field'], $change['old'], $change['new']);
            }
        } else {
            $this->audit($actor, $auditAction, $section, $sectionSubject);
        }

        // One Activity Log row per save (never per changed field —
        // that finer granularity already lives in schedule_audit_logs
        // above). Only the first line of $message is used — for
        // scheduleUpdated() specifically, $message spans several
        // lines listing every field diff, which belongs in
        // schedule_audit_logs, not in this general-purpose log line.
        $this->activityLog->record(
            $this->activityLogActionFor($auditAction),
            "{$title}: ".strtok($message, "\n"),
            $section,
            $actor,
        );
    }

    /**
     * Maps a ScheduleAuditLog action code (this service's internal
     * vocabulary) to the corresponding App\Services\ActivityLogService
     * action code (the Activity Log tab's vocabulary). Falls back to
     * the original code unchanged for anything not explicitly listed.
     */
    private function activityLogActionFor(string $auditAction): string
    {
        return match ($auditAction) {
            'SECTION_CREATED' => ActivityLogService::SECTION_CREATED,
            'FINALIZED' => ActivityLogService::SECTION_FINALIZED,
            'UNLOCKED' => ActivityLogService::SECTION_UNLOCKED,
            'SCHEDULE_UPDATED' => ActivityLogService::SCHEDULE_UPDATED,
            'SUBJECT_ADDED' => ActivityLogService::SUBJECT_ADDED_TO_SECTION,
            'SUBJECT_REMOVED' => ActivityLogService::SUBJECT_REMOVED_FROM_SECTION,
            default => $auditAction,
        };
    }

    /**
     * Same dedup + audit-free plumbing as dispatch(), but for
     * Admin/Registrar-only events (conflict()/concurrencyConflict())
     * that never write an audit row — a rejected operation didn't
     * change anything, so there's nothing to audit.
     */
    private function dispatchToAdmins(
        Section $section,
        User $actor,
        string $type,
        string $priority,
        string $title,
        string $message,
    ): void {
        if ($this->isRecentDuplicate($section, $type, $actor)) {
            return;
        }

        $recipients = $this->adminRecipients()->reject(fn (User $u) => $u->is($actor));

        foreach ($recipients as $recipient) {
            $this->create($recipient, $actor, $section, null, $type, $priority, $title, $message, [
                'section_code' => $section->section_code,
            ]);
        }
    }

    private function isRecentDuplicate(Section $section, string $type, User $actor, ?SectionSubject $sectionSubject = null): bool
    {
        return Notification::query()
            ->where('section_id', $section->id)
            ->where('type', $type)
            ->where('actor_user_id', $actor->id)
            ->where('section_subject_id', $sectionSubject?->id)
            ->where('created_at', '>=', now()->subSeconds(5))
            ->exists();
    }

    private function create(
        User $recipient,
        User $actor,
        Section $section,
        ?SectionSubject $sectionSubject,
        string $type,
        string $priority,
        string $title,
        string $message,
        array $data,
    ): Notification {
        return Notification::create([
            'recipient_user_id' => $recipient->id,
            'actor_user_id' => $actor->id,
            'type' => $type,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'section_id' => $section->id,
            'section_subject_id' => $sectionSubject?->id,
            'is_read' => false,
        ]);
    }

    /**
     * Same write as create(), but for notifications with no Section
     * to attach to (facultyLoadRequestSubmitted()/Reviewed() above —
     * `section_id`/`section_subject_id` are nullable in the schema
     * precisely for this case). No dedup guard here since these two
     * callers each only fire once per store()/review() request.
     */
    private function writeNotification(
        User $recipient,
        User $actor,
        string $type,
        string $priority,
        string $title,
        string $message,
        array $data,
    ): Notification {
        return Notification::create([
            'recipient_user_id' => $recipient->id,
            'actor_user_id' => $actor->id,
            'type' => $type,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'is_read' => false,
        ]);
    }

    private function audit(
        User $actor,
        string $action,
        Section $section,
        ?SectionSubject $sectionSubject,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'section_id' => $section->id,
            'section_subject_id' => $sectionSubject?->id,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'created_at' => now(),
        ]);
    }

    /**
     * An Administrator required a password change for `$user`. Sent to
     * that user only (not the whole college/institution — this is a
     * personal account action, not a scheduling event), so they see it
     * next time they check notifications and can jump straight to
     * Manage Account. See NotificationController::routeFor() for the
     * Manage Account redirect this resolves to.
     */
    public function passwordChangeRequired(User $user, User $actor): void
    {
        $this->writeNotification(
            recipient: $user,
            actor: $actor,
            type: self::TYPE_PASSWORD_CHANGE_REQUIRED,
            priority: self::PRIORITY_WARNING,
            title: 'Password Change Required',
            message: "{$actor->full_name} requires you to change your password on your next action. Go to Manage Account to set a new one.",
            data: [],
        );
    }

    /**
     * RECIPIENT RULES (role + College scoping, spec Sections 2, 9,
     * 18) — every finalize/unlock/schedule-update/subject/section/
     * auto-schedule notification is resolved from TWO recipient
     * groups, combined and deduplicated:
     *
     *   1. College-scoped: Dean/OIC of the Section's own College
     *      (AccessScope::COLLEGE_SCOPED_ROLES, filtered by
     *      users.college_id). A Dean of CTE never sees a CCS event.
     *   2. Institution-wide: Administrator + Registrar
     *      (AccessScope::UNRESTRICTED_ROLES) — always included,
     *      regardless of which College the Section belongs to,
     *      because both roles operate institution-wide (spec
     *      Section 9).
     *
     * Never hardcoded ids, never "notify every user" — both groups
     * are resolved live from AccessScope's role/scope model. The two
     * groups are concatenated and deduped by user id (spec Section
     * 11 — a user who happens to qualify through both a College role
     * and an institution-wide role still gets exactly one
     * notification), then the actor is excluded (spec Section 10 —
     * enforced here at the backend, never left to the frontend to
     * hide).
     *
     * Assistant Dean is deliberately NOT included: per
     * RoleSeeder/AccessScope, Assistant Dean is an institution-wide
     * GenEd/Minor role, not bound to a single College the way
     * Dean/OIC are — routing Section-level events to them would be
     * "notify everyone" by another name. If GenEd/Minor-specific
     * notifications are added later, give them their own resolver
     * rather than folding them in here.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(Section $section, User $actor): Collection
    {
        $section->loadMissing('major.department.college');
        $collegeId = $section->major?->college()?->id;

        $collegeScoped = $collegeId
            ? User::query()
                ->role(AccessScope::COLLEGE_SCOPED_ROLES)
                ->where('college_id', $collegeId)
                ->get()
            : collect();

        $institutionWide = User::query()->role(AccessScope::UNRESTRICTED_ROLES)->get();

        return $collegeScoped
            ->concat($institutionWide)
            // Same user reachable through both groups (e.g. an
            // Administrator who is also somehow College-scoped) —
            // exactly one notification, not two (spec Section 11).
            ->unique('id')
            // Don't notify someone of their own action (spec
            // Section 10).
            ->reject(fn (User $u) => $u->is($actor))
            ->values();
    }

    /**
     * CENTRAL recipient resolver for every Faculty-scoped notification
     * (create/update/workload/overload/qualifications/deactivate/
     * delete). This is the ONE place that decides "who is relevant to
     * this Faculty member" — every facultyXxx() method below must
     * route through this instead of assembling its own recipient list,
     * so the rule never drifts between event types.
     *
     * College is the routing key (never the actor's role): recipients
     * are resolved from `$faculty->college_id`, not from who performed
     * the action. Result is always:
     *
     *   1. Administrator + Registrar (AccessScope::UNRESTRICTED_ROLES)
     *      — institution-wide, every Faculty event.
     *   2. Dean + OIC of the Faculty's own College
     *      (AccessScope::COLLEGE_SCOPED_ROLES, filtered by
     *      college_id) — only when the Faculty has a College. A CCS
     *      Dean is never in this list for a CTE Faculty, and vice
     *      versa (spec Sections 3/9/18/21).
     *   3. Assistant Dean (AccessScope::ASSISTANT_DEAN_ROLE).
     *
     * NOTE on (3): the spec this resolver was written against
     * describes a per-College "CTE Assistant Dean" / "CCS Assistant
     * Dean". CLASSLY's actual schema has no such thing — Assistant
     * Dean is a single institution-wide role with no `college_id`
     * (see AccessScope::ASSISTANT_DEAN_ROLE and its class docblock:
     * "the role limited to GenEd/Minor resources across all
     * Colleges"). Per this method's own instruction to reuse the
     * existing architecture rather than invent relationships, every
     * Assistant Dean is included here regardless of the Faculty's
     * College, exactly as the rest of this service already treats
     * them (see collegeRecipientsForFaculty()). If CLASSLY later adds
     * a `college_id` to the Assistant Dean role, narrow step 3 to
     * match step 2's college_id filter.
     *
     * Deduped by user id, then the actor is always excluded (spec
     * Section 11) — enforced here, once, so no caller can forget it.
     *
     * @return Collection<int, User>
     */
    private function facultyRecipients(Faculty $faculty, User $actor): Collection
    {
        $collegeScoped = $faculty->college_id
            ? User::query()->role(AccessScope::COLLEGE_SCOPED_ROLES)->where('college_id', $faculty->college_id)->get()
            : collect();

        return $this->adminRecipients()
            ->concat($collegeScoped)
            ->concat(User::query()->role(AccessScope::ASSISTANT_DEAN_ROLE)->get())
            ->unique('id')
            ->reject(fn (User $u) => $u->is($actor))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function adminRecipients(): Collection
    {
        return User::query()->role(AccessScope::UNRESTRICTED_ROLES)->get();
    }

    /**
     * Dean/OIC of the Faculty's College — or Assistant Dean, when the
     * Faculty has no College (General Education/Minor, same scoping
     * FacultyLoadRequestPolicy::view() uses). Deliberately NOT
     * concatenated with institution-wide Admin/Registrar the way
     * recipientsFor() does for Sections — the actor here already IS
     * Admin/Registrar, so notifying "every Admin/Registrar" would
     * mostly just be notifying the actor's own peers about the
     * actor's own action; this is specifically about reaching the
     * College-side people who had no part in it.
     *
     * Kept distinct from facultyRecipients() above: this one is for
     * the narrower "just the College side" courtesy notices
     * (facultyDeactivatedDirectly/facultyDeletedDirectly did NOT ask
     * to be widened to full facultyRecipients() scope in this pass —
     * only create/update/workload/overload/qualifications did).
     *
     * @return Collection<int, User>
     */
    private function collegeRecipientsForFaculty(Faculty $faculty, User $actor): Collection
    {
        $recipients = $faculty->college_id
            ? User::query()->role(AccessScope::COLLEGE_SCOPED_ROLES)->where('college_id', $faculty->college_id)->get()
            : User::query()->role(AccessScope::ASSISTANT_DEAN_ROLE)->get();

        return $recipients->reject(fn (User $u) => $u->is($actor))->values();
    }
}