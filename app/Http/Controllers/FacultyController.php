<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFacultyRequest;
use App\Http\Requests\UpdateFacultyRequest;
use App\Models\College;
use App\Models\Faculty;
use App\Models\FacultyLoadRequest;
use App\Models\Subject;
use App\Models\User;
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
     * The Colleges selectable in the Add/Edit Faculty "College" dropdown.
     *
     * A College-scoped Dean/OIC may only ever place a Faculty member in
     * their own College (see FacultyPolicy::createForCollege() /
     * reassignCollege()), so their dropdown is narrowed to just that
     * one College — there's no point showing (or letting them pick)
     * options the backend would reject anyway. Admin/Registrar/
     * Assistant Dean still see the full active list.
     */
    private function selectableColleges(?User $user)
    {
        return College::query()
            ->where('status', 'Active')
            ->when(
                AccessScope::isCollegeScoped($user),
                fn ($query) => $query->where('id', $user->college_id),
            )
            ->orderBy('name')
            ->get(['id', 'name']);
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
            'colleges' => $this->selectableColleges($request->user()),
            'nextFacultyId' => $this->nextFacultyId(),

            // Faculty creation/load-edit are now direct actions for
            // every Scheduling-side role (Admin, Registrar, Dean/OIC,
            // Assistant Dean) — see FacultyPolicy::create()/
            // changeMaxLoad(). There is no longer a Faculty Load
            // Request or Faculty (Creation/Deletion) Request queue.
            'canCreateFacultyDirectly' => $request->user()->can('create', Faculty::class),
            'hardCapUnits' => FacultyLoadRequest::effectiveCapFor($request->user()),
        ]);
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
            'colleges' => $this->selectableColleges($user),
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

        // Same rule as update(): anyone with changeMaxLoad access
        // (Admin, Registrar, Dean, OIC, Assistant Dean) may set a load
        // ceiling above the system default when creating a new
        // Faculty record — see FacultyPolicy::changeMaxLoad(). Anyone
        // else gets the default regardless of what they typed, and
        // has no direct write path to raise it afterward except
        // through FacultyLoadRequestController's request/review queue.
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

        // Notify Administrator, Registrar, and Assistant Dean so the
        // institution-wide/GenEd side sees a new hire land on the
        // roster even when a College-scoped Dean/OIC added it
        // directly. See NotificationService::facultyCreatedDirectly().
        $this->notifications->facultyCreatedDirectly($faculty, $request->user());

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

        // Anyone with changeMaxLoad access (Admin, Registrar, Dean,
        // OIC, Assistant Dean — see FacultyPolicy::changeMaxLoad())
        // has a direct write path to a faculty member's load ceiling
        // right here. Anyone else submitting this form has those
        // fields silently pinned back to their current value, same
        // pattern as college_id above — they must go through
        // FacultyLoadRequestController's request/review queue instead.
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

        // Fill (don't save yet) so getDirty() tells us exactly which
        // columns actually changed value, not just which keys were
        // present in the form payload — e.g. re-submitting the same
        // status shouldn't count as a change. The load-ceiling fields
        // are excluded here: they already get their own, more
        // specific notification (facultyMaxLoadEditedDirectly() to
        // the College side) just below, so folding them into the
        // general "Faculty Information Updated" notification too
        // would double-notify for the same edit. Per spec Section 7
        // ("do not generate unnecessary notifications for
        // insignificant UI changes"), cosmetic fields (middle name,
        // suffix, remarks, contact info) are excluded too — a
        // notification-worthy edit is one that changes who/where the
        // faculty member is (name, College, status, employment type,
        // Faculty ID) or is caught by its own dedicated notification
        // (qualifications via TeachingQualificationController, load
        // via facultyMaxLoadEditedDirectly() below).
        $faculty->fill($data);
        $changedFields = array_intersect(
            array_keys($faculty->getDirty()),
            ['faculty_id', 'first_name', 'last_name', 'employment_type', 'college_id', 'status'],
        );
        $changes = array_map(fn (string $field) => [
            'field' => $field,
            'old' => $faculty->getOriginal($field),
            'new' => $faculty->{$field},
        ], $changedFields);

        $faculty->save();

        if (array_key_exists('max_teaching_units', $data) && $data['max_teaching_units'] !== $oldMaxTeachingUnits) {
            $this->notifications->facultyMaxLoadEditedDirectly(
                $faculty,
                $request->user(),
                $oldMaxTeachingUnits,
                $data['max_teaching_units'],
                $this->workloadService,
            );
        }

        // Any other significant field a Dean/OIC/Assistant Dean/
        // Admin/Registrar just changed directly (name, employment
        // type, College, status, Faculty ID) — notify Admin/
        // Registrar/the Faculty's own College Dean/OIC/Assistant Dean
        // so it doesn't go unnoticed. See
        // NotificationService::facultyUpdatedDirectly().
        if (! empty($changes)) {
            $this->notifications->facultyUpdatedDirectly($faculty, $request->user(), $changes);
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