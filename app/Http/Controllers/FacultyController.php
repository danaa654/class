<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportFacultyRequest;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacultyController extends Controller
{
    /** Shared by nextFacultyId()/nextFacultyIdNumber() and import()'s batch auto-numbering. */
    private const FACULTY_ID_PREFIX = 'FAC-';

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
     * Colleges the "Department Faculty"/"All Faculty" college
     * sub-filter should offer. Distinct from selectableColleges()
     * above (which gates where a NEW faculty record may be created) —
     * this instead mirrors what Faculty::scopeVisibleTo() actually
     * lets this user SEE: every active College for
     * Admin/Registrar/Assistant Dean (including a Dean/OIC additionally
     * flagged with GenEd/Minor authority), or just their own single
     * College otherwise. The frontend only renders the dropdown when
     * this list has more than one entry — for a single-College
     * viewer it would be a no-op filter.
     */
    private function viewableCollegesForFilter(?User $user)
    {
        return College::query()
            ->where('status', 'Active')
            ->when(
                ! AccessScope::isUnrestricted($user) && ! AccessScope::isAssistantDean($user) && AccessScope::isCollegeScoped($user),
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
        $category = in_array($category, ['Department Faculty', 'General Education Faculty', 'All Faculty'], true) ? $category : '';

        // "All Faculty" is a SCOPE toggle, not a category filter — it
        // lifts the College restriction for a Dean/OIC so they can
        // browse the institution-wide roster (read-mostly; edit/delete
        // on rows outside their own College is still blocked by
        // FacultyPolicy — see canEdit/canDelete below). For every
        // other role this changes nothing since they already see the
        // full roster regardless.
        $viewAllColleges = $category === 'All Faculty';

        // Secondary "College" narrowing — only meaningful under
        // "Department Faculty"/"All Faculty" (GenEd has no college_id
        // to filter by). Only ever effective for a viewer who can see
        // more than one College under the current scope (Admin,
        // Registrar, Assistant Dean, or a Dean/OIC additionally
        // flagged with GenEd/Minor authority — see
        // viewableCollegesForFilter()); a plain single-College Dean/OIC
        // sending this is a no-op since scopeVisibleTo already narrows
        // them to their own College regardless.
        $collegeFilter = $request->query('faculty_college_id');
        $collegeFilter = is_numeric($collegeFilter) ? (int) $collegeFilter : null;

        $faculties = Faculty::query()
            ->visibleTo($request->user(), $viewAllColleges)
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
            ->when(in_array($category, ['Department Faculty', 'General Education Faculty'], true), fn ($query) => $category === 'General Education Faculty'
                ? $query->whereNull('college_id')
                : $query->whereNotNull('college_id'))
            ->when($collegeFilter !== null && $category !== 'General Education Faculty', fn ($query) => $query->where('college_id', $collegeFilter))
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

        $faculties->getCollection()->transform(function (Faculty $faculty) use ($workloads, $request) {
            $faculty->setAttribute('workload', $workloads[$faculty->id] ?? $this->workloadService->evaluate($faculty));

            // Row-level write permissions — needed because the "All
            // Faculty" scope above can now surface rows outside the
            // viewer's own College (read-only for those rows). Kept
            // per-row (not a single page-wide flag) since a Dean/OIC
            // browsing "All Faculty" CAN edit their own College's rows
            // and CANNOT edit everyone else's in the same table.
            $faculty->setAttribute('canEdit', $request->user()->can('update', $faculty));
            $faculty->setAttribute('canDelete', $request->user()->can('delete', $faculty));

            return $faculty;
        });

        return Inertia::render('Scheduling/Faculty/Index', [
            'faculties' => $faculties,
            'filters' => [
                'faculty_search' => $search,
                'faculty_category' => $category,
                'faculty_college_id' => $collegeFilter,
            ],
            'colleges' => $this->selectableColleges($request->user()),
            // Colleges the viewer may narrow the "Department Faculty"/
            // "All Faculty" list down to — see viewableCollegesForFilter().
            // Deliberately separate from 'colleges' above (which is the
            // narrower "what College may THIS user create a faculty
            // record under" list) since filtering-to-view and
            // creating-into are different questions with different scopes.
            'filterColleges' => $this->viewableCollegesForFilter($request->user()),
            'nextFacultyId' => $this->nextFacultyId(),

            // Lets the frontend offer the "All Faculty" filter option
            // only to Dean/OIC — Admin/Registrar/Assistant Dean already
            // see the full roster by default, so the option would be
            // redundant (and slightly misleading) for them.
            'isCollegeScopedViewer' => AccessScope::isCollegeScoped($request->user()),

            // Faculty creation/load-edit are now direct actions for
            // every Scheduling-side role (Admin, Registrar, Dean/OIC,
            // Assistant Dean) — see FacultyPolicy::create()/
            // changeMaxLoad(). There is no longer a Faculty Load
            // Request or Faculty (Creation/Deletion) Request queue.
            'canCreateFacultyDirectly' => $request->user()->can('create', Faculty::class),
            // Gates the Bulk Import button/dialog — same underlying
            // ability as manually adding one Faculty member
            // (FacultyPolicy::create()), since Import is just a
            // faster way to do the same thing many rows at a time.
            'canImportFacultyDirectly' => $request->user()->can('create', Faculty::class),
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
            // Gates the "Edit Information" button — needed now that a
            // Dean/OIC can open another College's faculty profile from
            // the "All Faculty" filter (FacultyController::index) and
            // land here read-only. See FacultyPolicy::canAccess().
            'canEdit' => $user->can('update', $faculty),
            'colleges' => $this->selectableColleges($user),
            // Ordered by RELEVANCE to this faculty member, not just
            // alphabetically — a Dean qualifying their own faculty
            // shouldn't have to scroll past every other College's
            // subjects to reach their own. Tiers: (1) this faculty's
            // own College's Major subjects, (2) shared GenEd/Minor
            // subjects, (3) every other College's Major subjects.
            // subject_code order is preserved within each tier since
            // sortBy() is a stable sort over an already-ordered list.
            // This is purely a DISPLAY convenience — selection is not
            // restricted here; write-time authorization still happens
            // in updateQualifications() via SubjectPolicy/manageQualification.
            'subjects' => Subject::query()
                ->where('is_active', true)
                ->orderBy('subject_code')
                ->get(['id', 'subject_code', 'subject_title', 'category', 'units', 'college_id'])
                ->sortBy(fn (Subject $subject) => match (true) {
                    $subject->category === 'Major' && $subject->college_id === $faculty->college_id => 0,
                    AccessScope::isSharedCategory($subject->category) => 1,
                    default => 2,
                })
                ->values(),
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
     * QUICK "ADD UNITS" — a lightweight, single-field counterpart to
     * update() for the one field the Scheduling Dashboard's Faculty
     * Utilization widget needs to edit in place: the load ceiling
     * (max_teaching_units, or max_weekly_hours for an hours-based
     * faculty member). Deliberately doesn't touch update()'s full
     * FormRequest (name/status/college/etc.) — the Dashboard widget
     * only ever has a faculty's name and current load in view, not
     * the rest of their profile, so re-sending the whole record isn't
     * possible from there. Same authorization gate, same cap, and the
     * same notification as a direct max-load edit from the Faculty
     * Details page, so this can never grant a wider write path than
     * update() already does — see FacultyPolicy::changeMaxLoad() and
     * FacultyLoadRequest::effectiveCapFor().
     */
    public function updateMaxLoad(Request $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('changeMaxLoad', Faculty::class);

        $usesHours = $faculty->workload_type === 'hours';
        $cap = $usesHours ? 168 : FacultyLoadRequest::effectiveCapFor($request->user());

        $data = $request->validate([
            'value' => ['required', 'integer', 'min:0', "max:{$cap}"],
        ]);

        // Never lower the ceiling below the load already scheduled.
        $currentLoad = $this->workloadService->currentLoad($faculty);
        $existingMax = $usesHours ? (int) $faculty->max_weekly_hours : (int) $faculty->max_teaching_units;

        if ($data['value'] < $existingMax && $data['value'] < $currentLoad) {
            $unit = $usesHours ? 'hour(s)' : 'unit(s)';

            return back()->withErrors([
                'value' => "This faculty member already has {$currentLoad} {$unit} of scheduled subjects. The maximum cannot be set below {$currentLoad}.",
            ]);
        }

        $oldMaxTeachingUnits = $faculty->max_teaching_units;

        if ($usesHours) {
            $faculty->max_weekly_hours = $data['value'];
        } else {
            $faculty->max_teaching_units = $data['value'];
        }

        $faculty->save();

        if (! $usesHours && $data['value'] !== $oldMaxTeachingUnits) {
            $this->notifications->facultyMaxLoadEditedDirectly(
                $faculty,
                $request->user(),
                $oldMaxTeachingUnits,
                $data['value'],
                $this->workloadService,
            );
        }

        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        $this->activityLog->record(
            ActivityLogService::FACULTY_UPDATED,
            "{$request->user()->full_name} updated {$facultyName}'s maximum load to {$data['value']} ".($usesHours ? 'hour(s).' : 'unit(s).'),
            $faculty,
            $request->user(),
        );

        return back()->with('success', "Updated {$facultyName}'s maximum load.");
    }

    /**
     * Permanently remove a faculty member from the Faculty Master.
     * Admin/Registrar may do this for any Faculty; a Dean/OIC may
     * also delete directly, but only a Faculty member within their
     * own College (see FacultyPolicy::delete()) — GenEd/Minor faculty
     * and other Colleges' faculty still have no direct delete path
     * for them; they submit a deactivation request instead (see
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
        return $this->formatFacultyId($this->nextFacultyIdNumber());
    }

    /**
     * The next sequential number after whatever is currently the
     * highest FAC-NNNN on the roster (including soft-deleted rows, so
     * a deleted faculty's ID is never reissued). Split out from
     * nextFacultyId() so import() can seed its own running counter
     * from the same starting point without duplicating the
     * numeric-aware lookup query.
     */
    private function nextFacultyIdNumber(): int
    {
        $prefix = self::FACULTY_ID_PREFIX;

        $lastId = Faculty::withTrashed()
            ->where('faculty_id', 'like', "{$prefix}%")
            // Numeric-aware sort, not lexical — lexical would rank
            // "FAC-100" before "FAC-99". CAST(... AS UNSIGNED) pulls
            // just the digits after the prefix.
            ->orderByRaw('CAST(SUBSTRING(faculty_id, ?) AS UNSIGNED) DESC', [strlen($prefix) + 1])
            ->value('faculty_id');

        return $lastId
            ? ((int) substr($lastId, strlen($prefix))) + 1
            : 1;
    }

    private function formatFacultyId(int $number): string
    {
        return self::FACULTY_ID_PREFIX.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Human-readable College label for a CSV row, for display purposes
     * only (never used for validation — validateImportRow()/
     * duplicateFacultyMessage() resolve the actual college_id
     * separately). Blank column = General Education (no College); an
     * unresolvable code is returned upper-cased as-typed so a reviewer
     * can immediately see what they entered even when it's invalid.
     */
    private function resolveCollegeLabel(array $data, $colleges): ?string
    {
        $code = trim((string) ($data['college'] ?? ''));
        if ($code === '') {
            return null;
        }

        $college = $colleges->get(Str::lower($code));

        return $college ? $college->name : Str::upper($code);
    }

    /**
     * Downloadable CSV template for the Faculty Master's Bulk Import —
     * column headers plus one example row, so a registrar spreadsheet
     * has an exact target shape to copy into rather than guessing
     * column names from the docs. Mirrors
     * RoomController::importTemplate() / SubjectController::importTemplate().
     */
    public function importTemplate(): StreamedResponse
    {
        $this->authorize('create', Faculty::class);

        $columns = [
            'faculty_id', 'first_name', 'middle_name', 'last_name', 'suffix',
            'employment_type', 'college', 'max_teaching_units', 'workload_type',
            'max_weekly_hours', 'status', 'email', 'contact_number', 'remarks',
        ];

        $example = [
            '', 'Juan', '', 'Dela Cruz', '',
            'Full-time', 'CCS', '24', 'units',
            '', 'Active', 'juan.delacruz@example.edu', '09171234567', '',
        ];

        return response()->streamDownload(function () use ($columns, $example) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $example);
            fclose($handle);
        }, 'classly-faculty-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk Import — Faculty Master.
     *
     * Adding a whole department's worth of Faculty one at a time
     * through the Add Faculty dialog doesn't scale. This reads a CSV
     * (template above), validates each row independently — mirroring
     * StoreFacultyRequest's rules exactly, so an imported row can
     * never end up with looser validation than a manually-added one —
     * and creates whichever rows pass. Rows that fail are reported
     * back with their line number and reason; they never block the
     * rows that DID pass. Same created/skipped/error shape as
     * RoomController::import() / SubjectController::import().
     */
    public function import(ImportFacultyRequest $request): RedirectResponse
    {
        $this->authorize('create', Faculty::class);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return redirect()->route('scheduling.faculty')->with('error', 'Could not read the uploaded file. Please try again.');
        }

        $header = $this->readImportHeader($handle);
        if ($header === null) {
            fclose($handle);

            return redirect()->route('scheduling.faculty')->with('error', 'The uploaded file is empty.');
        }
        if (is_array($header) && isset($header['error'])) {
            fclose($handle);

            return redirect()->route('scheduling.faculty')->with('error', $header['error']);
        }

        $colleges = College::query()->get(['id', 'code', 'name'])->keyBy(fn ($c) => Str::lower($c->code));

        // Same rule as store()/update(): only a viewer with
        // changeMaxLoad access (Admin, Registrar, Dean, OIC, Assistant
        // Dean) may set a load ceiling above the system default via
        // Import — anyone else has every imported row silently pinned
        // to the default regardless of what the CSV says.
        $canChangeMaxLoad = $request->user()->can('changeMaxLoad', Faculty::class);
        $hardCapUnits = FacultyLoadRequest::effectiveCapFor($request->user());

        // faculty_id is optional in the CSV — most bulk adds won't
        // supply one, same as the Add Faculty form pre-filling (but
        // allowing override of) a suggested ID. $usedIds seeds that
        // auto-numbering with every ID already in the database
        // (including soft-deleted, so a deleted faculty's ID is never
        // reissued) so an auto-generated ID can never collide with an
        // existing one, and also tracks every row already created
        // earlier in this same import.
        $usedIds = Faculty::withTrashed()->pluck('faculty_id')
            ->map(fn ($id) => Str::lower($id))
            ->flip()
            ->map(fn () => true)
            ->all();
        $nextIdNumber = $this->nextFacultyIdNumber();

        // "Already exists" for import purposes means either an
        // explicit faculty_id in the CSV that's already on the
        // roster, or a Faculty Name + College combination that's
        // already on the roster — the same near-duplicate signal a
        // registrar would use to spot a re-added row by eye.
        $existingByName = Faculty::withTrashed()->get(['first_name', 'last_name', 'college_id'])
            ->map(fn (Faculty $f) => Str::lower(trim($f->first_name)).'|'.Str::lower(trim($f->last_name)).'|'.($f->college_id ?? 'none'))
            ->flip()
            ->map(fn () => true)
            ->all();

        $created = [];
        $skipped = [];
        $errors = [];
        $rowNumber = 1; // header is row 1; data starts at row 2

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);

            $explicitId = trim((string) ($data['faculty_id'] ?? ''));
            if ($explicitId !== '' && isset($usedIds[Str::lower($explicitId)])) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'faculty_id' => $explicitId,
                    'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
                    'college' => $this->resolveCollegeLabel($data, $colleges),
                    'reason' => 'A faculty member with this Faculty ID already exists — it will be skipped.',
                ];

                continue;
            }

            $duplicateReason = $this->duplicateFacultyMessage($data, $colleges, $existingByName);
            if ($duplicateReason !== null) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'faculty_id' => $explicitId ?: null,
                    'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
                    'college' => $this->resolveCollegeLabel($data, $colleges),
                    'reason' => $duplicateReason,
                ];

                continue;
            }

            try {
                $attributes = $this->validateImportRow($data, $colleges, $canChangeMaxLoad, $hardCapUnits, $request->user());

                if ($explicitId !== '') {
                    $attributes['faculty_id'] = $explicitId;
                    $usedIds[Str::lower($explicitId)] = true;
                } else {
                    $attributes['faculty_id'] = $this->formatFacultyId($nextIdNumber);
                    while (isset($usedIds[Str::lower($attributes['faculty_id'])])) {
                        $nextIdNumber++;
                        $attributes['faculty_id'] = $this->formatFacultyId($nextIdNumber);
                    }
                    $usedIds[Str::lower($attributes['faculty_id'])] = true;
                    $nextIdNumber++;
                }

                $faculty = Faculty::create($attributes);
                $created[] = $faculty;

                $nameKey = Str::lower(trim($attributes['first_name'])).'|'.Str::lower(trim($attributes['last_name'])).'|'.($attributes['college_id'] ?? 'none');
                $existingByName[$nameKey] = true;
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'faculty_id' => $explicitId ?: null,
                    'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
                    'college' => $this->resolveCollegeLabel($data, $colleges),
                    'message' => $e->getMessage(),
                ];
            }
        }

        fclose($handle);

        // Every created row goes through the same "new hire" notify
        // as a manual Add Faculty (facultyCreatedDirectly()), and the
        // same Activity Log entry — the roster/notification/audit
        // history should never be able to tell the difference between
        // a faculty member added via Import vs. the Add Faculty
        // dialog.
        foreach ($created as $faculty) {
            $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

            $this->activityLog->record(
                ActivityLogService::FACULTY_CREATED,
                "{$request->user()->full_name} added faculty member {$facultyName} (bulk import).",
                $faculty,
                $request->user(),
            );

            $this->notifications->facultyCreatedDirectly($faculty, $request->user());
        }

        $createdCount = count($created);
        $skippedCount = count($skipped);
        $errorCount = count($errors);

        $flash = [];
        if ($createdCount > 0) {
            $flash['success'] = $createdCount === 1
                ? '1 faculty member imported successfully.'
                : "{$createdCount} faculty members imported successfully.";
        }
        if ($skippedCount > 0) {
            $flash['success'] = trim(($flash['success'] ?? '').' '.(
                $skippedCount === 1
                    ? '1 faculty member already existed and was skipped.'
                    : "{$skippedCount} faculty members already existed and were skipped."
            ));
        }
        if ($errorCount > 0) {
            $flash['error'] = $createdCount > 0 || $skippedCount > 0
                ? "{$errorCount} row(s) could not be imported — see details below."
                : "None of the {$errorCount} row(s) could be imported — see details below.";
        }
        if ($createdCount === 0 && $skippedCount === 0 && $errorCount === 0) {
            $flash['error'] = 'The file had no data rows to import.';
        }

        $flash['facultyImportErrors'] = $errors;
        $flash['facultyImportSkipped'] = $skipped;

        return redirect()->route('scheduling.faculty')->with($flash);
    }

    /**
     * Read-only overview of a CSV before anything is saved — same
     * "New / Already Exists / Invalid" preview the Room Master's/
     * Subject Library's Bulk Import gives, run through the exact same
     * per-row validation as import() but never persisting anything.
     */
    public function preview(ImportFacultyRequest $request): JsonResponse
    {
        $this->authorize('create', Faculty::class);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return response()->json(['error' => 'Could not read the uploaded file. Please try again.'], 422);
        }

        $header = $this->readImportHeader($handle);
        if ($header === null) {
            fclose($handle);

            return response()->json(['error' => 'The uploaded file is empty.'], 422);
        }
        if (is_array($header) && isset($header['error'])) {
            fclose($handle);

            return response()->json(['error' => $header['error']], 422);
        }

        $colleges = College::query()->get(['id', 'code', 'name'])->keyBy(fn ($c) => Str::lower($c->code));
        $canChangeMaxLoad = $request->user()->can('changeMaxLoad', Faculty::class);
        $hardCapUnits = FacultyLoadRequest::effectiveCapFor($request->user());

        $usedIds = Faculty::withTrashed()->pluck('faculty_id')
            ->map(fn ($id) => Str::lower($id))
            ->flip()
            ->map(fn () => true)
            ->all();

        $existingByName = Faculty::withTrashed()->get(['first_name', 'last_name', 'college_id'])
            ->map(fn (Faculty $f) => Str::lower(trim($f->first_name)).'|'.Str::lower(trim($f->last_name)).'|'.($f->college_id ?? 'none'))
            ->flip()
            ->map(fn () => true)
            ->all();

        $rows = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
            $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            $collegeLabel = $this->resolveCollegeLabel($data, $colleges);

            $explicitId = trim((string) ($data['faculty_id'] ?? ''));
            if ($explicitId !== '' && isset($usedIds[Str::lower($explicitId)])) {
                $rows[] = [
                    'row' => $rowNumber,
                    'faculty_id' => $explicitId,
                    'name' => $name,
                    'college' => $collegeLabel,
                    'status' => 'exists',
                    'message' => 'A faculty member with this Faculty ID already exists — it will be skipped.',
                ];

                continue;
            }

            $duplicateReason = $this->duplicateFacultyMessage($data, $colleges, $existingByName);
            if ($duplicateReason !== null) {
                $rows[] = [
                    'row' => $rowNumber,
                    'faculty_id' => $explicitId ?: null,
                    'name' => $name,
                    'college' => $collegeLabel,
                    'status' => 'exists',
                    'message' => $duplicateReason,
                ];

                continue;
            }

            try {
                $attributes = $this->validateImportRow($data, $colleges, $canChangeMaxLoad, $hardCapUnits, $request->user());

                $rows[] = [
                    'row' => $rowNumber,
                    'faculty_id' => $explicitId ?: null,
                    'name' => $name,
                    'college' => $collegeLabel,
                    'status' => 'new',
                    'message' => null,
                ];

                $nameKey = Str::lower(trim($attributes['first_name'])).'|'.Str::lower(trim($attributes['last_name'])).'|'.($attributes['college_id'] ?? 'none');
                $existingByName[$nameKey] = true;
            } catch (\InvalidArgumentException $e) {
                $rows[] = [
                    'row' => $rowNumber,
                    'faculty_id' => $explicitId ?: null,
                    'name' => $name,
                    'college' => $collegeLabel,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }

            if ($explicitId !== '') {
                $usedIds[Str::lower($explicitId)] = true;
            }
        }

        fclose($handle);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'new' => count(array_filter($rows, fn ($r) => $r['status'] === 'new')),
                'exists' => count(array_filter($rows, fn ($r) => $r['status'] === 'exists')),
                'error' => count(array_filter($rows, fn ($r) => $r['status'] === 'error')),
            ],
        ]);
    }

    /**
     * Parse and validate the CSV header row, shared by import() and
     * preview() so the two never drift apart on what counts as a
     * readable file.
     *
     * @return array<int, string>|array{error: string}|null null = empty file, ['error' => ...] = bad header, otherwise the normalized header
     */
    private function readImportHeader($handle): array|null
    {
        $header = fgetcsv($handle);
        if ($header === false) {
            return null;
        }

        $header = array_map(fn ($col) => Str::slug((string) $col, '_'), $header);

        $required = ['first_name', 'last_name', 'employment_type'];
        $missing = array_diff($required, $header);

        if (! empty($missing)) {
            // Columns that only ever appear in the Room Master's or
            // Subject Library's Bulk Import templates — if any show
            // up here, the person almost certainly picked the wrong
            // file rather than mistyped a Faculty column, so say that
            // plainly instead of just listing what's "missing" from a
            // file that was never meant to be a Faculty CSV.
            $roomOnlyColumns = ['room_name', 'building', 'room_type', 'capacity'];
            if (! empty(array_intersect($roomOnlyColumns, $header))) {
                return ['error' => 'This looks like a Rooms CSV, not a Faculty CSV. Please upload a file exported for Faculty import — download the template below for the exact expected format.'];
            }
            $subjectOnlyColumns = ['subject_code', 'subject_title', 'lecture_hours', 'laboratory_hours', 'subject_type'];
            if (! empty(array_intersect($subjectOnlyColumns, $header))) {
                return ['error' => 'This looks like a Subjects CSV, not a Faculty CSV. Please upload a file exported for Faculty import — download the template below for the exact expected format.'];
            }

            return ['error' => 'The CSV is missing required column(s): '.implode(', ', $missing).'. Download the template for the exact expected format.'];
        }

        return $header;
    }

    /**
     * Validate + normalize one CSV row into Faculty::create()-ready
     * attributes, mirroring StoreFacultyRequest's rules exactly (minus
     * faculty_id, which import handles separately — see import()'s
     * doc comment) so an imported row is never held to looser
     * standards than a manually added one. Throws
     * InvalidArgumentException with a human-readable reason on any
     * failure — caught by both import() and preview().
     *
     * College scope is enforced here too, via the same
     * FacultyPolicy::createForCollege() a manual Add Faculty already
     * goes through — a College-scoped Dean/OIC can only import rows
     * for their own College (plus GenEd/Minor if additionally flagged
     * is_gened_assistant_dean), and a plain Assistant Dean can only
     * import GenEd/Minor (blank-college) rows. Import was previously
     * only gated by the coarse `create` ability, which let a
     * CSV's `college` column place rows outside a scoped viewer's
     * own College — something the Add Faculty form never allowed.
     *
     * @return array<string, mixed>
     */
    private function validateImportRow(array $data, $colleges, bool $canChangeMaxLoad, int $hardCapUnits, User $user): array
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $employmentType = trim((string) ($data['employment_type'] ?? ''));
        $status = trim((string) ($data['status'] ?? '')) ?: 'Active';

        $validator = Validator::make(
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'employment_type' => $employmentType,
                'status' => $status,
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'max_weekly_hours' => trim((string) ($data['max_weekly_hours'] ?? '')) !== '' ? $data['max_weekly_hours'] : null,
            ],
            [
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'employment_type' => ['required', Rule::in(['Full-time', 'Part-time'])],
                'status' => ['required', Rule::in(['Active', 'Inactive'])],
                'email' => ['nullable', 'email', 'max:255'],
                'max_weekly_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        // college_id = null means General Education Faculty (no
        // Department), same meaning as leaving the College field
        // blank on the Add/Edit Faculty form.
        $collegeId = null;
        $collegeCode = (string) ($data['college'] ?? '');
        if (trim($collegeCode) !== '') {
            $college = $colleges->get(Str::lower(trim($collegeCode)));
            if (! $college) {
                throw new \InvalidArgumentException("Unknown college code \"{$collegeCode}\".");
            }
            $collegeId = $college->id;
        }

        if (! $user->can('createForCollege', [Faculty::class, $collegeId])) {
            $collegeLabel = $collegeId === null ? 'General Education (no College)' : ($colleges->first(fn ($c) => $c->id === $collegeId)?->name ?? $collegeCode);
            throw new \InvalidArgumentException("You don't have permission to add faculty to {$collegeLabel}.");
        }

        $workloadType = Str::lower(trim((string) ($data['workload_type'] ?? 'units')));
        if ($workloadType !== '' && ! in_array($workloadType, ['units', 'hours'], true)) {
            throw new \InvalidArgumentException("Invalid workload_type \"{$data['workload_type']}\" — must be units or hours.");
        }
        $workloadType = $workloadType ?: 'units';

        $maxTeachingUnitsRaw = trim((string) ($data['max_teaching_units'] ?? ''));
        if ($maxTeachingUnitsRaw !== '' && ! ctype_digit($maxTeachingUnitsRaw)) {
            throw new \InvalidArgumentException("Invalid max_teaching_units \"{$data['max_teaching_units']}\" — must be a whole number.");
        }
        $maxTeachingUnits = $maxTeachingUnitsRaw !== '' ? (int) $maxTeachingUnitsRaw : 24;
        if ($maxTeachingUnits > $hardCapUnits) {
            throw new \InvalidArgumentException("max_teaching_units ({$maxTeachingUnits}) exceeds the current ceiling of {$hardCapUnits} units.");
        }

        $maxWeeklyHours = trim((string) ($data['max_weekly_hours'] ?? '')) !== '' ? (int) $data['max_weekly_hours'] : null;

        // Same rule as store()/update(): only a changeMaxLoad-capable
        // importer may raise the ceiling above the system default —
        // everyone else has it silently pinned back, regardless of
        // what the CSV asked for.
        if (! $canChangeMaxLoad) {
            $maxTeachingUnits = 24;
            $maxWeeklyHours = null;
            $workloadType = 'units';
        }

        return [
            'first_name' => $firstName,
            'middle_name' => trim((string) ($data['middle_name'] ?? '')) ?: null,
            'last_name' => $lastName,
            'suffix' => trim((string) ($data['suffix'] ?? '')) ?: null,
            'employment_type' => $employmentType,
            'college_id' => $collegeId,
            'max_teaching_units' => $maxTeachingUnits,
            'workload_type' => $workloadType,
            'max_weekly_hours' => $maxWeeklyHours,
            'status' => $status,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'contact_number' => trim((string) ($data['contact_number'] ?? '')) ?: null,
            'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
        ];
    }

    /**
     * Whether a CSV row looks like a duplicate of a Faculty member
     * already on the roster — either an explicit faculty_id collision
     * (handled separately by the caller before this runs) or the same
     * First Name + Last Name + College already existing, which is the
     * realistic near-duplicate signal for a roster that doesn't
     * require the CSV to supply an ID at all. Returns the reason to
     * skip with, or null if it's genuinely new. Shared by import() and
     * preview() so they can never disagree on what counts as a
     * duplicate.
     */
    private function duplicateFacultyMessage(array $data, $colleges, array $existingByName): ?string
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));

        $collegeId = null;
        $collegeCode = trim((string) ($data['college'] ?? ''));
        if ($collegeCode !== '') {
            $college = $colleges->get(Str::lower($collegeCode));
            $collegeId = $college?->id;
        }

        $key = Str::lower($firstName).'|'.Str::lower($lastName).'|'.($collegeId ?? 'none');

        if ($firstName !== '' && $lastName !== '' && isset($existingByName[$key])) {
            return 'A faculty member with this same Name and College already exists — it will be skipped.';
        }

        return null;
    }
}