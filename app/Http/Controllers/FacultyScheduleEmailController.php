<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendFacultyScheduleEmailRequest;
use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\FacultyScheduleEmail;
use App\Services\FacultyScheduleEmailService;
use App\Support\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reports -> Faculty Schedule -> Send via Email (spec: Faculty Schedule
 * Email System). Sits alongside ReportsController rather than inside
 * it — this controller mutates (queues emails, writes history);
 * ReportsController stays read-only.
 */
class FacultyScheduleEmailController extends Controller
{
    public function __construct(private readonly FacultyScheduleEmailService $service) {}

    /**
     * Send (or version-bump / resend) a single faculty's finalized
     * schedule. Validation errors (missing/invalid email, not
     * finalized — spec sections 4/8/9) come back as normal Inertia
     * form errors for the modal to display.
     */
    public function send(SendFacultyScheduleEmailRequest $request): RedirectResponse
    {
        $faculty = Faculty::findOrFail($request->integer('faculty_id'));
        $term = AcademicTerm::findOrFail($request->integer('academic_term_id'));

        $record = $this->service->send($faculty, $term, $request->user());

        return back()->with('success', "Faculty schedule sent successfully to {$record->recipient_email}");
    }

    /**
     * Resend a specific past delivery (spec section 11 "Resend" /
     * section 17 "Retry" for failed sends).
     */
    public function resend(Request $request, FacultyScheduleEmail $facultyScheduleEmail): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['Administrator', 'Registrar']), 403);

        $record = $this->service->resend($facultyScheduleEmail, $request->user());

        return back()->with('success', "Faculty schedule re-sent to {$record->recipient_email}");
    }

    /**
     * "Send All Faculty Schedules" (spec section 15/16).
     *
     * ROLE + SCOPE: Administrator/Registrar may send school-wide, to
     * any College or an explicit cross-College faculty selection.
     * Dean/OIC (College-scoped) may also use Send All, but their scope
     * is hard-pinned to their own College — never trusted from the
     * request — same posture as ReportsController::buildFilters()
     * ("NEVER trust an arbitrary college_id filter from a College-
     * scoped Dean/OIC"). A Dean/OIC with no College assigned yet gets
     * a 403, per AccessScope::hasNoAssignedCollege()'s "never treat as
     * unrestricted" rule. Any other role (Faculty, Assistant Dean,
     * etc.) is forbidden outright — bulk-emailing isn't part of their
     * responsibilities even institution-wide for Assistant Dean.
     */
    public function bulkSend(Request $request): RedirectResponse
    {
        $user = $request->user();
        $isCollegeScoped = AccessScope::isCollegeScoped($user);

        abort_unless(AccessScope::isUnrestricted($user) || $isCollegeScoped, 403);
        abort_if($isCollegeScoped && AccessScope::hasNoAssignedCollege($user), 403);

        $term = AcademicTerm::findOrFail($request->integer('academic_term_id'));

        // faculty_ids is optional: omitted/empty means "every Active
        // faculty" (the original spec 15/16 behavior) UNLESS a
        // college_id scope was also sent (see below); present means
        // scope the send to whatever the Reports page currently has
        // filtered/selected (e.g. a specific multi-select of faculty,
        // which always takes priority over the College filter).
        $facultyIds = $request->input('faculty_ids');
        $facultyIds = is_array($facultyIds) && count($facultyIds) > 0
            ? array_map('intval', $facultyIds)
            : null;

        // college_id: threads the Reports page's College/Program
        // filter through to the actual send when no individual faculty
        // were hand-picked — 'gened' for the General Education Faculty
        // pseudo-option, a numeric college id, or omitted/null for no
        // College narrowing. Previously this filter only affected the
        // on-screen scope label, never the actual query, so picking a
        // College without also hand-picking faculty silently emailed
        // the entire school instead.
        $collegeId = $request->input('college_id');
        $collegeId = $collegeId === 'gened' ? 'gened' : ($collegeId !== null && $collegeId !== '' ? (int) $collegeId : null);

        if ($isCollegeScoped) {
            // Force back to the Dean/OIC's own College regardless of
            // what was submitted — mirrors ReportsController's own
            // "never trust the request" rule for this exact field.
            $collegeId = $user->college_id;

            // A hand-picked faculty multi-select could in principle
            // include faculty outside the Dean/OIC's College (e.g. a
            // stale filter, or a tampered request) — silently drop
            // anyone outside scope rather than trusting the client.
            // Matches the existing pattern of skipping out-of-scope
            // recipients rather than failing the whole send.
            if ($facultyIds !== null) {
                $facultyIds = Faculty::query()
                    ->whereIn('id', $facultyIds)
                    ->where('college_id', $collegeId)
                    ->pluck('id')
                    ->all();
            }
        }

        $result = $this->service->bulkSend($term, $request->user(), $facultyIds, $collegeId);

        return back()->with('success', "{$result['queued']} emails queued.")->with('bulkSendResult', $result);
    }

    /**
     * Email delivery history for one faculty + term (spec section 11).
     * Returned as JSON for the Email History panel on the Faculty
     * Schedule report.
     */
    public function history(Request $request, Faculty $faculty)
    {
        $termId = $request->integer('academic_term_id');

        $history = FacultyScheduleEmail::query()
            ->where('faculty_id', $faculty->id)
            ->when($termId, fn ($q) => $q->where('academic_term_id', $termId))
            ->with('sentBy:id,name')
            ->orderByDesc('created_at')
            ->get([
                'id', 'academic_term_id', 'recipient_email', 'schedule_version',
                'email_type', 'status', 'error_message', 'sent_by', 'sent_at', 'created_at',
            ]);

        return response()->json(['history' => $history]);
    }
}