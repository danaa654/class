<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFacultyRequest;
use App\Http\Requests\UpdateFacultyRequest;
use App\Models\College;
use App\Models\Faculty;
use App\Models\FacultyLoadRequest;
use App\Models\FacultyRequest;
use App\Models\Subject;
use App\Services\ActivityLogService;
use App\Services\FacultyScheduleEmailService;
use App\Services\FacultyWorkloadService;
use App\Services\NotificationService;
use App\Support\AccessScope;
use App\Support\ViewingTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FacultyController extends Controller
{
    public function __construct(
        private readonly FacultyWorkloadService $workloadService,
        private readonly NotificationService $notifications,
        private readonly ActivityLogService $activityLog,
        private readonly FacultyScheduleEmailService $facultyScheduleEmail,
    ) {
    }

    /**
     * Display the Faculty Master page.
     *
     * Faculty members here are NOT system users — they never log in.
     * This is purely a roster the registrar/admin maintains so the
     * scheduling module has faculty to draw from later. No subject,
     * schedule, room, or section assignment happens here.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Faculty::class);

        $search = trim((string) $request->query('faculty_search', ''));
        $category = $request->query('faculty_category', '');
        $category = in_array($category, ['Department Faculty', 'General Education Faculty'], true) ? $category : '';

        $faculties = Faculty::query()
            ->visibleTo($request->user())
            ->with(['college' => fn ($query) => $query->withTrashed()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('faculty_id', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereHas('college', function ($collegeQuery) use ($search) {
                            $collegeQuery->withTrashed()->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($category !== '', fn ($query) => $category === 'General Education Faculty'
                ? $query->whereNull('college_id')
                : $query->whereNotNull('college_id'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(10, ['*'], 'faculty_page')
            ->withQueryString();

        // FACULTY WORKLOAD VALIDATION — "Dashboard Indicators". Each
        // row gets its real current/max/remaining load (Scheduled +
        // Draft placements, active semester only) and a 🟢/🟡/🔴
        // status so the roster doubles as an at-a-glance overload
        // report, computed via FacultyWorkloadService — the same
        // engine Auto Generate/Recommend/Manual Assignment/Save
        // Schedule use — so this can never disagree with those.
        //
        // PERFORMANCE: evaluateMany() computes this for every row on
        // the page in one batched query, instead of the old
        // per-row transform() calling evaluate() once per Faculty
        // (which itself ran 2 fresh queries per row) — see
        // evaluateMany()'s doc comment on FacultyWorkloadService.
        $workloads = $this->workloadService->evaluateMany($faculties->getCollection());

        $faculties->getCollection()->transform(function (Faculty $faculty) use ($workloads) {
            $faculty->setAttribute('workload', $workloads[$faculty->id] ?? $this->workloadService->evaluate($faculty));

            return $faculty;
        });

        return Inertia::render('Scheduling/Faculty/Index', [
            'faculties' => $faculties,
            'filters' => ['faculty_search' => $search, 'faculty_category' => $category],
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'nextFacultyId' => $this->nextFacultyId(),

            // Faculty Load Requests — moved here from its own page
            // (formerly FacultyLoadRequestController@index) so it
            // renders as a section on the Faculty page itself.
            ...$this->loadRequestsProps($request),

            // Faculty Management requests (Creation/Deactivation) —
            // see FacultyRequestController.
            ...$this->facultyRequestsProps($request),
        ]);
    }

    /**
     * Props for the "Faculty Requests" section of the Faculty page.
     * Admin/Registrar see the full review queue; Dean/OIC/Assistant
     * Dean see only requests within their own scope (their own
     * submissions), so they can track status.
     */
    private function facultyRequestsProps(Request $request): array
    {
        $user = $request->user();

        $facultyRequests = FacultyRequest::query()
            ->with(['faculty:id,faculty_id,first_name,last_name,college_id,status', 'college:id,name', 'requestedBy:id,name', 'reviewedBy:id,name'])
            ->visibleTo($user)
            ->latest()
            ->paginate(10, ['*'], 'faculty_requests_page')
            ->withQueryString();

        $pendingFacultyRequestsCount = FacultyRequest::query()->visibleTo($user)->where('status', 'Pending')->count();

        return [
            'facultyRequests' => $facultyRequests,
            'pendingFacultyRequestsCount' => $pendingFacultyRequestsCount,
            'canReviewFacultyRequests' => $user->can('review', FacultyRequest::class),
            'canCreateFacultyDirectly' => $user->can('create', Faculty::class),
            'canRequestFacultyCreation' => $user->can('requestCreate', [Faculty::class, AccessScope::isAssistantDean($user) ? null : $user->college_id]),
        ];
    }

    /**
     * Props for the "Faculty Load Requests" section of the Faculty
     * page. Admin/Registrar see the full queue (for review); Dean/OIC/
     * Assistant Dean see only requests touching faculty in their own
     * scope, so they can track status of what they've submitted.
     */
    private function loadRequestsProps(Request $request): array
    {
        $user = $request->user();

        $loadRequests = FacultyLoadRequest::query()
            ->with(['faculty:id,faculty_id,first_name,last_name,college_id,max_teaching_units,max_weekly_hours', 'requestedBy:id,name', 'reviewedBy:id,name'])
            ->when(! AccessScope::isUnrestricted($user), function ($query) use ($user) {
                $query->whereHas('faculty', function ($facultyQuery) use ($user) {
                    if (AccessScope::isAssistantDean($user)) {
                        $facultyQuery->whereNull('college_id');

                        return;
                    }

                    $facultyQuery->where('college_id', $user->college_id);
                });
            });

        // Count BEFORE pagination — used for the reminder banner so it
        // reflects the whole scoped queue, not just whatever page of
        // the table happens to be loaded.
        $pendingCount = (clone $loadRequests)->where('status', 'Pending')->count();

        $loadRequests = $loadRequests
            ->latest()
            ->paginate(10, ['*'], 'load_requests_page')
            ->withQueryString();

        return [
            'loadRequests' => $loadRequests,
            'pendingLoadRequestsCount' => $pendingCount,
            'hardCapUnits' => FacultyLoadRequest::effectiveCapFor($user),
            // Faculty roster for the "New Request" dropdown, scoped the
            // same way the Faculty Master roster itself is — a Dean
            // can only request an increase for faculty they can
            // already see/manage.
            'loadRequestFaculties' => Faculty::query()
                ->visibleTo($user)
                ->where('status', 'Active')
                ->orderBy('last_name')
                ->get(['id', 'faculty_id', 'first_name', 'last_name', 'max_teaching_units', 'max_weekly_hours', 'workload_type']),
            'canReviewLoadRequests' => $user->can('review', FacultyLoadRequest::class),
        ];
    }

    /**
     * Display the Faculty Details page (Information, Teaching
     * Qualifications, and Workload tabs).
     */
    public function show(Faculty $faculty, Request $request): Response
    {
        $this->authorize('view', $faculty);

        $faculty->load([
            'college' => fn ($query) => $query->withTrashed(),
            'subjects' => fn ($query) => $query->orderBy('subject_code'),
        ]);

        // Real assigned workload (Scheduled + Draft placements, active
        // semester only) — see FacultyController@index docblock above.
        // includePlacements: true here (and only here) so the Workload
        // tab can list *which* subjects/sections make up the load
        // figure, not just the summary numbers.
        $faculty->setAttribute('workload', $this->workloadService->evaluate($faculty, includePlacements: true));

        $user = $request->user();

        return Inertia::render('Scheduling/Faculty/Details', [
            'faculty' => $faculty,
            // Deactivation-impact preview (spec Section 7/8) — lets
            // the page show the "⚠ Scheduled Assignments" / "🔒
            // Finalized Schedule Assignment" indicators and pre-fill
            // the confirmation dialog without a second round trip.
            'deactivationImpact' => $this->workloadService->deactivationImpact($faculty),
            'canDeactivateDirectly' => $user->can('delete', $faculty),
            'canRequestDeactivation' => $user->can('requestDeactivate', $faculty),
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'subjects' => Subject::query()
                ->where('is_active', true)
                ->orderBy('subject_code')
                ->get(['id', 'subject_code', 'subject_title', 'category', 'units']),
            // Same cap the Faculty Master (Index) edit modal uses — needed
            // here so the Details page's own Edit Faculty modal can bound
            // the Maximum Teaching Units field the same way instead of
            // leaving it uncapped and editable by every role.
            'hardCapUnits' => FacultyLoadRequest::effectiveCapFor($user),
            // Powers the Print / Send via Email buttons on the Workload
            // tab — same underlying data Reports > Schedule by Faculty
            // uses for its single-faculty flow (see ReportsService::
            // scheduleByFaculty()), just resolved for whichever term
            // this user is currently viewing rather than a report filter.
            'scheduleMeta' => (function () use ($faculty, $request) {
                $term = ViewingTerm::resolve($request);
                $term?->loadMissing('schoolYear:id,name');

                return [
                    'academic_term_id' => $term?->id,
                    'academic_year' => $term?->schoolYear?->name,
                    'semester' => $term?->sectionSemesterValue(),
                    'is_finalized' => $term ? $this->facultyScheduleEmail->isFinalized($faculty, $term) : false,
                ];
            })(),
        ]);
    }

    /**
     * Store a newly created faculty member in the Faculty Master.
     */
    public function store(StoreFacultyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // NEVER trust college_id from the payload (spec Section 23) —
        // it is already re-derived/validated in StoreFacultyRequest,
        // but the policy check here is the authoritative gate.
        $this->authorize('createForCollege', [Faculty::class, $data['college_id'] ?? null]);

        // Same rule as update(): only Admin/Registrar may set a load
        // ceiling above the system default when creating a new Faculty
        // record. Dean/OIC/Assistant Dean get the default regardless
        // of what they typed — they can submit a FacultyLoadRequest
        // afterward if this new hire genuinely needs a higher ceiling.
        if (! $request->user()->can('changeMaxLoad', Faculty::class)) {
            $data['max_teaching_units'] = 24;
            $data['max_weekly_hours'] = null;
            $data['workload_type'] = 'units';
        }

        $faculty = Faculty::create($data);

        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        $this->activityLog->record(
            ActivityLogService::FACULTY_CREATED,
            "{$request->user()->full_name} added faculty member {$facultyName}.",
            $faculty,
            $request->user(),
        );

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member added successfully.');
    }

    /**
     * Update an existing faculty member in the Faculty Master.
     */
    public function update(UpdateFacultyRequest $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('update', $faculty);

        $data = $request->validated();

        // Per spec Section 6/11: Dean/OIC/Assistant Dean may never
        // reassign a faculty member's College. Only Admin/Registrar
        // (already bypassed via Gate::before / isUnrestricted) may
        // change college_id; anyone else has it silently pinned back.
        if (array_key_exists('college_id', $data) && $data['college_id'] !== $faculty->college_id) {
            $this->authorize('reassignCollege', Faculty::class);
        }

        // Per the new Faculty Load Request workflow: Dean/OIC/
        // Assistant Dean have no direct write path to a faculty
        // member's load ceiling — only Admin/Registrar do (see
        // FacultyPolicy::changeMaxLoad()). Anyone else submitting this
        // form has those fields silently pinned back to their current
        // value, same pattern as college_id above. They must go
        // through FacultyLoadRequestController instead.
        if (! $request->user()->can('changeMaxLoad', Faculty::class)) {
            $data['max_teaching_units'] = $faculty->max_teaching_units;
            $data['max_weekly_hours'] = $faculty->max_weekly_hours;
            $data['workload_type'] = $faculty->workload_type;
        }

        // Capture before the write so we can tell whether the ceiling
        // actually moved — only notify on a real change, not on every
        // save of this form (e.g. editing the email shouldn't fire a
        // "load updated" notification).
        $oldMaxTeachingUnits = $faculty->max_teaching_units;

        $faculty->update($data);

        if (array_key_exists('max_teaching_units', $data) && $data['max_teaching_units'] !== $oldMaxTeachingUnits) {
            $this->notifications->facultyMaxLoadEditedDirectly(
                $faculty,
                $request->user(),
                $oldMaxTeachingUnits,
                $data['max_teaching_units'],
            );
        }

        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        $this->activityLog->record(
            ActivityLogService::FACULTY_UPDATED,
            "{$request->user()->full_name} updated faculty member {$facultyName}.",
            $faculty,
            $request->user(),
        );

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member updated successfully.');
    }

    /**
     * Permanently remove a faculty member from the Faculty Master
     * (Admin/Registrar only — Dean/OIC/Assistant Dean have no direct
     * delete path; they may still request deactivation instead, see
     * FacultyRequestController::storeDeactivation()).
     *
     * This is for faculty who don't belong on the roster at all (e.g.
     * added by mistake, never actually employed at the school) — not
     * for faculty who are simply no longer teaching this term. That
     * case is a manual status edit (Faculty Master → Edit → set
     * Status to "Inactive"), which keeps the row and its history
     * intact. This action instead soft-deletes the row (the
     * `faculties` table already carries `deleted_at` — see
     * Faculty::class's SoftDeletes trait): it disappears from the
     * roster and every listing immediately, while historical
     * schedule/qualification/workload records that reference it stay
     * intact for audit purposes and can be restored if deleted by
     * mistake.
     *
     * If the faculty has active scheduled assignments, the frontend
     * is expected to have already shown the two-step confirmation
     * (delete warning) and to resend with `confirmed=true` — but that
     * frontend flag is never trusted on its own: the backend
     * rechecks the live assignment/finalized-schedule state itself
     * before proceeding, so a stale confirmation dialog can never
     * push through an unsafe delete.
     */
    public function destroy(Request $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('delete', $faculty);

        $impact = $this->workloadService->deactivationImpact($faculty);

        // Finalized-schedule protection — never delete a faculty
        // member still tied to a finalized/locked Section, confirmed
        // or not. They must be unassigned first.
        if ($impact['has_finalized_assignment']) {
            return back()->with('error', 'This faculty member is assigned to a finalized schedule ('.implode(', ', $impact['finalized_section_codes']).'). Unlock the affected section(s) and reassign before deleting.');
        }

        // Double confirmation — required only when there are active
        // (non-finalized) assignments to warn about, i.e. the faculty
        // already has a subject scheduled.
        if ($impact['has_active_assignments'] && ! $request->boolean('confirmed')) {
            return back()->with('error', 'This faculty member has an active assigned subject. Confirm the warning to proceed.')
                ->with('facultyDeletionImpact', $impact);
        }

        DB::transaction(function () use ($faculty, $impact, $request) {
            $this->notifications->facultyDeletedDirectly($faculty, $request->user());

            if ($impact['has_active_assignments']) {
                $this->notifications->facultyAssignmentsNeedAttention($faculty, $impact, $request->user(), 'deleted');
            }

            $faculty->delete();
        });

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member deleted successfully.');
    }

    /**
     * Determine the next sequential Faculty ID, e.g. FAC-0019.
     *
     * This is only a suggestion pre-filled into the Add Faculty /
     * Request New Faculty forms — the registrar/admin can still edit
     * it freely before saving, and uniqueness is always re-checked
     * server-side on store. Matches the existing FAC-NNNN roster
     * numbering (see the seeded faculty_id values) rather than the
     * FAC-{year}-NNNN format previously suggested here, which never
     * matched what was actually on the roster.
     */
    private function nextFacultyId(): string
    {
        $prefix = 'FAC-';

        $lastId = Faculty::withTrashed()
            ->where('faculty_id', 'like', "{$prefix}%")
            // Numeric-aware sort, not lexical — lexical would rank
            // "FAC-100" before "FAC-99". CAST(... AS UNSIGNED) pulls
            // just the digits after the prefix.
            ->orderByRaw('CAST(SUBSTRING(faculty_id, ?) AS UNSIGNED) DESC', [strlen($prefix) + 1])
            ->value('faculty_id');

        $nextNumber = $lastId
            ? ((int) substr($lastId, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}