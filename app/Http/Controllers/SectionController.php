<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewSectionBatchRequest;
use App\Http\Requests\StoreSectionBatchRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Major;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Services\ActivityLogService;
use App\Services\EDPCodeService;
use App\Services\NotificationService;
use App\Services\ScheduleConflictService;
use App\Services\SectionBatchGeneratorService;
use App\Support\AccessScope;
use App\Support\ViewingTerm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SectionController extends Controller
{
    /**
     * Section.year_level uses "First Year"… while CurriculumItem.year_level
     * uses "1st Year"… — mirrors SectionSubjectController::YEAR_LEVEL_MAP
     * so the Add Section modal's subject picker (curriculumSubjectsPreview())
     * maps between the two exactly the same way "Generate Curriculum
     * Subjects" and "Load From Curriculum" already do.
     *
     * @var array<string, string>
     */
    private const YEAR_LEVEL_MAP = [
        'First Year' => '1st Year',
        'Second Year' => '2nd Year',
        'Third Year' => '3rd Year',
        'Fourth Year' => '4th Year',
    ];

    /**
     * Display the Sections page.
     *
     * A Section represents a group of students, under a Major /
     * Curriculum / Year Level, that will later receive a class
     * schedule. This page only stores the section itself — subjects,
     * faculty, rooms, and schedules are assigned in later modules.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Section::class);

        $search = trim((string) $request->query('section_search', ''));

        // Term filter — "which Academic Term's Sections am I looking
        // at?" Value is either "all" (no filtering, the historical
        // behavior) or "{academic_year}|{semester}" matching Section's
        // own academic_year/semester columns directly (already in the
        // "First Semester"/"Second Semester"/"Summer" spelling, so no
        // AcademicTerm::sectionSemesterValue() translation is needed
        // here — that translation only matters when starting from an
        // AcademicTerm record, which the default below does).
        //
        // No explicit ?term= given: default to THIS user's Viewing
        // Term (their session override if Admin/Registrar switched
        // it, else the real Active term) if one exists, otherwise
        // fall back to "all" (nothing to default to — better to show
        // everything than silently show nothing).
        $viewingTerm = ViewingTerm::resolve($request);
        $defaultTerm = 'all';
        if ($viewingTerm) {
            $viewingTerm->loadMissing('schoolYear:id,name');
            $viewingSemesterValue = $viewingTerm->sectionSemesterValue();
            if ($viewingTerm->schoolYear && $viewingSemesterValue) {
                $defaultTerm = "{$viewingTerm->schoolYear->name}|{$viewingSemesterValue}";
            }
        }

        $term = trim((string) $request->query('term', $defaultTerm));

        // College filter — "college_id" must be one of the Colleges the
        // current user is actually authorized to see (AccessScope::
        // visibleCollegeIds()), same server-side authority the Program
        // dropdown already uses below. A Dean/OIC passing a foreign
        // college_id simply matches nothing rather than leaking another
        // College's Sections — never trust the raw query value alone.
        $collegeId = $request->query('college_id');
        $collegeId = ($collegeId !== null && $collegeId !== '' && $collegeId !== 'all')
            ? (int) $collegeId
            : null;

        if ($collegeId !== null) {
            $visibleCollegeIds = AccessScope::visibleCollegeIds($request->user());
            if ($visibleCollegeIds !== null && ! in_array($collegeId, $visibleCollegeIds, true)) {
                $collegeId = -1; // outside this user's scope — match nothing
            }
        }

        // Year Level filter — validated against the same YEAR_LEVELS
        // enum the Add/Edit Section form already uses, so an invalid
        // value is simply ignored rather than silently returning zero
        // rows or leaking a raw SQL value into the query.
        $yearLevel = trim((string) $request->query('year_level', ''));
        if ($yearLevel !== '' && ! in_array($yearLevel, StoreSectionRequest::YEAR_LEVELS, true)) {
            $yearLevel = '';
        }

        // Scheduling Status filter — derived purely from the same
        // database facts SectionController::finalize() and the
        // scheduling-progress withCount() below already use as the
        // single source of truth (no second/duplicate definition):
        //   - is_finalized                → Finalized / Locked
        //   - a section_subjects row with status = 'Conflict'         → Needs Attention
        //   - no section_subjects rows at all                         → No Subjects Yet
        //   - every row "assigned" (Faculty/Room/Days/Start/End, or
        //     Practicum) and none conflicting                         → Fully Scheduled
        //   - anything else (has subjects, not fully assigned, no
        //     conflicts, not finalized)                                → In Progress
        $schedulingStatus = trim((string) $request->query('scheduling_status', 'all'));
        if (! in_array($schedulingStatus, ['all', 'no_subjects', 'in_progress', 'fully_scheduled', 'finalized', 'needs_attention'], true)) {
            $schedulingStatus = 'all';
        }

        $sectionsQuery = Section::query()
            ->visibleTo($request->user())
            ->with(['major:id,name,code', 'curriculum:id,code,name,major_id'])
            ->when($term !== 'all', function ($query) use ($term) {
                [$termYear, $termSemester] = array_pad(explode('|', $term, 2), 2, null);

                if ($termYear && $termSemester) {
                    $query->where('academic_year', $termYear)
                        ->where('semester', $termSemester);
                }
            })
            ->when($collegeId !== null, function ($query) use ($collegeId) {
                $query->whereHas('major.department', function ($inner) use ($collegeId) {
                    $inner->where('college_id', $collegeId);
                });
            })
            ->when($yearLevel !== '', function ($query) use ($yearLevel) {
                $query->where('year_level', $yearLevel);
            })
            ->when($schedulingStatus !== 'all', function ($query) use ($schedulingStatus) {
                $this->applySchedulingStatusFilter($query, $schedulingStatus);
            });

        // Scheduling-progress indicator for the list ("Fully/Partially
        // Scheduled (x/y)") — counts distinct SUBJECTS, not
        // section_subjects rows, so a split Face-to-Face/Online subject
        // counts once, not twice. See Section::withSubjectProgressCounts().
        $sectionsQuery = Section::withSubjectProgressCounts($sectionsQuery);

        $sections = $sectionsQuery
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('section_code', 'like', "%{$search}%")
                        ->orWhere('section_name', 'like', "%{$search}%")
                        ->orWhere('academic_year', 'like', "%{$search}%")
                        ->orWhere('year_level', 'like', "%{$search}%")
                        ->orWhereHas('major', function ($majorQuery) use ($search) {
                            $majorQuery->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('curriculum', function ($curriculumQuery) use ($search) {
                            $curriculumQuery->where('code', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('academic_year')
            ->orderBy('section_code')
            ->paginate(10, ['*'], 'section_page')
            ->withQueryString();

        // Major dropdown for the Add/Edit dialog — Active majors only.
        //
        // SECURITY: scoped to the authenticated user's authorized
        // College(s). Never send every College's Majors to a
        // restricted (Dean/OIC) user just to hide them client-side —
        // that still lets the option be selected via devtools/a
        // scripted request. A Dean/OIC only ever sees their own
        // College's Majors here; Admin/Registrar/Assistant Dean see
        // all of them.
        $activeMajors = Major::query()
            ->where('status', 'Active')
            ->whereHas('department', function ($departmentQuery) use ($request) {
                $visibleCollegeIds = AccessScope::visibleCollegeIds($request->user());

                if ($visibleCollegeIds !== null) {
                    $departmentQuery->whereIn('college_id', $visibleCollegeIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'short_name', 'department_id']);

        // Curriculum dropdown data — the frontend filters this list down
        // to the curriculums belonging to the selected Major.
        $curriculums = Curriculum::query()
            ->where('status', 'Active')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'major_id']);

        // College filter dropdown — same visibility scope as the
        // Program dropdown above (AccessScope::visibleCollegeIds()): a
        // Dean/OIC only ever sees their own College here, never every
        // College in the database.
        $visibleCollegeIds = AccessScope::visibleCollegeIds($request->user());
        $colleges = College::query()
            ->when($visibleCollegeIds !== null, fn ($query) => $query->whereIn('id', $visibleCollegeIds))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('Scheduling/Sections/Index', [
            'sections' => $sections,
            'filters' => [
                'section_search' => $search,
                'term' => $term,
                'college_id' => $collegeId,
                'year_level' => $yearLevel,
                'scheduling_status' => $schedulingStatus,
            ],
            'termOptions' => $this->termFilterOptions(),
            'activeMajors' => $activeMajors,
            'curriculums' => $curriculums,
            'colleges' => $colleges,
            'yearLevels' => StoreSectionRequest::YEAR_LEVELS,
            'sectionTypes' => StoreSectionRequest::SECTION_TYPES,
            'academicTermOptions' => $this->academicTermSectionOptions(),
        ]);
    }

    /**
     * Store a newly created section.
     */
    public function store(
        StoreSectionRequest $request,
        NotificationService $notifications,
        EDPCodeService $edpCodeService,
        ScheduleConflictService $conflictService
    ): RedirectResponse {
        // NEVER trust an implicit College from the frontend — derive it
        // from the Major record server-side (spec Section 23).
        $this->authorizeSectionCollege($request, (int) $request->validated('major_id'));

        $data = $request->validated();
        $subjectIds = collect($data['subject_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        unset($data['subject_ids']);

        // The StoreSectionRequest's "unique" rule already checks
        // section_code, but that check and this insert aren't atomic —
        // a double-submit (double-click, or a slow request the user
        // retries) can fire two requests that both pass validation
        // before either one has inserted. Catch that race here instead
        // of letting it surface as a raw 500 error page.
        //
        // TRANSACTIONAL CREATE + NOTIFY — same reasoning as finalize()/
        // unlock() below: the insert, its audit row, and the Dean/OIC
        // "Section Created" notification all commit together or not
        // at all.
        try {
            DB::transaction(function () use ($data, $request, $notifications, $edpCodeService, $conflictService, $subjectIds) {
                $section = Section::create($data);
                $notifications->created($section, $request->user());

                // Optional up-front Subjects step (Add Section modal) —
                // see storeBatch()'s matching block for the full
                // reasoning: this is what lets a section created here
                // start out with the same subject list the admin picked
                // in the modal, instead of having to add them
                // afterward on the Section Subjects page.
                $this->attachSubjectsToSection($section, $subjectIds, $edpCodeService, $conflictService, $notifications, $request);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'section_code' => 'This section code was just taken by another request. Please use a different code.',
            ]);
        }

        return redirect()->route('scheduling.sections')->with('success', 'Section added successfully.');
    }

    /**
     * Live preview for the Add Section modal: given a Section Prefix
     * and Number of Blocks, return the next available block names
     * (e.g. BSIT-1A, BSIT-1B), skipping any letters already used by
     * existing sections in the same Academic Year / Semester / Program.
     *
     * Read-only — nothing is created here.
     */
    public function previewBatch(PreviewSectionBatchRequest $request, SectionBatchGeneratorService $generator): JsonResponse
    {
        $data = $request->validated();

        // SECURITY: this endpoint is read-only (no rows are created),
        // but it still must not let a restricted user probe/preview
        // block names for a program outside their College — same
        // scope check as storeBatch() below, server-side only (spec
        // Section 23).
        $this->authorizeSectionCollege($request, (int) $data['major_id']);

        // Irregular sections are a single scheduling group, not a set
        // of A/B/C blocks — see nextIrregularName()'s docblock. This
        // keeps that branch entirely separate from the Regular
        // block-generation path below rather than forcing
        // number_of_blocks=1 through the same letter-suffixing logic.
        if ($data['section_type'] === 'Irregular') {
            $names = $generator->nextIrregularName($data['section_prefix']);

            $sections = collect($names)->map(fn (string $name) => [
                'section_code' => $name,
                'estimated_students' => $data['estimated_students'],
            ])->values();

            return response()->json(['sections' => $sections]);
        }

        $names = $generator->nextBlockNames(
            prefix: $data['section_prefix'],
            numberOfBlocks: $data['number_of_blocks'],
            academicYear: $data['academic_year'],
            semester: $data['semester'],
            yearLevel: $data['year_level'],
            majorId: (int) $data['major_id'],
            excludeSectionId: $data['exclude_section_id'] ?? null,
        );

        $sections = collect($names)->map(fn (string $name) => [
            'section_code' => $name,
            'estimated_students' => $data['estimated_students_per_block'],
        ])->values();

        return response()->json(['sections' => $sections]);
    }

    /**
     * Add Section modal — "Subjects" step preview.
     *
     * Given a Curriculum + Year Level + Semester (the same three
     * fields the modal already collects before this point), returns
     * every Subject that Curriculum offers for that Year Level and
     * Semester — so the admin can pick, up front, exactly which
     * subjects every block being created (BSIT-1A, BSIT-1B, ...) will
     * share, instead of having to open each section afterward and
     * run "Generate Curriculum Subjects" or "Manual Selection"
     * separately per section.
     *
     * Deliberately NOT scoped to any one Section (unlike
     * SectionSubjectController::curriculumPreview(), which excludes
     * subjects already placed on a specific, already-existing
     * Section) — at this point in the Add Section flow no Section
     * exists yet, and the same subject list is meant to apply
     * identically to every block in the batch.
     *
     * Read-only — nothing is created here.
     */
    public function curriculumSubjectsPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'integer', 'exists:curriculums,id'],
            'year_level' => ['required', Rule::in(StoreSectionRequest::YEAR_LEVELS)],
            'semester' => ['required', Rule::in(StoreSectionRequest::SEMESTERS)],
        ]);

        $mappedYearLevel = self::YEAR_LEVEL_MAP[$validated['year_level']] ?? null;

        if (! $mappedYearLevel) {
            return response()->json(['subjects' => []]);
        }

        $subjects = CurriculumItem::query()
            ->where('curriculum_id', $validated['curriculum_id'])
            ->where('year_level', $mappedYearLevel)
            ->where('semester', $validated['semester'])
            ->with('subject:id,subject_code,subject_title,category,units')
            ->get()
            ->pluck('subject')
            ->filter()
            ->map(fn (Subject $subject) => [
                'id' => $subject->id,
                'subject_code' => $subject->subject_code,
                'subject_title' => $subject->subject_title,
                'category' => $subject->category,
                'units' => $subject->units,
            ])
            ->sortBy('subject_code')
            ->values();

        return response()->json(['subjects' => $subjects]);
    }

    /**
     * Add Section modal — "Subjects" step, Manual Selection tab.
     *
     * Every Active Subject belonging to the given Major, plus true
     * General Education subjects (shared by every Major) — the exact
     * same scoping SectionSubjectController::show() already uses for
     * its own Manual Selection tab (see that method's docblock for
     * why Major-category subjects with a null major_id, e.g. the
     * BSCRIM shared core, are deliberately excluded here).
     *
     * Unlike curriculumSubjectsPreview() above, this is NOT scoped to
     * a Curriculum/Year Level/Semester at all — it's the full
     * "search any subject" pool, since Manual Selection exists
     * precisely for subjects that don't come from a Curriculum (a
     * bridging subject, a replacement, a cross-enrolled subject, or
     * every subject an Irregular section needs, since Irregular
     * sections don't follow one Prospectus).
     *
     * Read-only — nothing is created here.
     */
    public function manualSubjectsPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'major_id' => ['required', 'integer', 'exists:majors,id'],
        ]);

        $subjects = Subject::query()
            ->where('is_active', true)
            ->where(function ($query) use ($validated) {
                $query->where('major_id', $validated['major_id'])
                    ->orWhere('category', 'General Education');
            })
            ->orderBy('subject_code')
            ->get(['id', 'subject_code', 'subject_title', 'category', 'units']);

        return response()->json(['subjects' => $subjects]);
    }

    /**
     * Store a batch of sections generated from the Add Section flow
     * (Section Prefix + Number of Blocks → BSIT-1A, BSIT-1B, ...).
     *
     * Each row in the editable preview becomes its own real Section
     * record — no "parent section" grouping is introduced — sharing
     * the common Academic Year / Semester / Program / Year Level /
     * Prospectus (Curriculum) / Status / Remarks.
     *
     * Also sharing, when the admin used the modal's optional Subjects
     * step: the exact same set of Subjects (via subject_ids) — so
     * BSIT-1A and BSIT-1B, created in the same batch, walk away with
     * an identical subject list already placed, rather than each
     * needing "Generate Curriculum Subjects" or Manual Selection run
     * separately afterward on every individual Section.
     */
    public function storeBatch(
        StoreSectionBatchRequest $request,
        NotificationService $notifications,
        EDPCodeService $edpCodeService,
        ScheduleConflictService $conflictService
    ): RedirectResponse {
        $data = $request->validated();
        $subjectIds = collect($data['subject_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        // SECURITY: StoreSectionBatchRequest::authorize() intentionally
        // just returns true (validation-only, per Laravel FormRequest
        // convention used across this codebase) — the real check
        // belongs here, mirroring store()'s createForCollege check.
        // Without this, a restricted user could bypass the single
        // Add Section form entirely by hitting the batch endpoint
        // directly with a program outside their College (spec
        // Section 23).
        $this->authorizeSectionCollege($request, (int) $data['major_id']);

        try {
            DB::transaction(function () use ($data, $request, $notifications, $edpCodeService, $conflictService, $subjectIds) {
                foreach ($data['sections'] as $row) {
                    $section = Section::create([
                        'section_code' => $row['section_code'],
                        'section_name' => $row['section_code'],
                        'section_type' => $data['section_type'],
                        'major_id' => $data['major_id'],
                        'curriculum_id' => $data['curriculum_id'] ?? null,
                        'year_level' => $data['year_level'],
                        'academic_year' => $data['academic_year'],
                        'semester' => $data['semester'],
                        'estimated_students' => $row['estimated_students'],
                        'status' => $data['status'],
                        'remarks' => $data['remarks'] ?? null,
                    ]);

                    // One "Section Created" notification per row — a
                    // batch of BSIT-1A/1B/1C is still each its own
                    // real Section (see this method's docblock), so
                    // each gets its own notification rather than one
                    // rolled-up "3 sections created" message.
                    $notifications->created($section, $request->user());

                    // Same Subjects picked once in the modal, applied
                    // identically to every block this batch creates —
                    // see attachSubjectsToSection()'s docblock. A no-op
                    // when the admin left the Subjects step empty.
                    $this->attachSubjectsToSection($section, $subjectIds, $edpCodeService, $conflictService, $notifications, $request);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'sections' => 'One of these section names was just taken by another request. Please refresh and try again.',
            ]);
        }

        $count = count($data['sections']);

        return redirect()->route('scheduling.sections')->with(
            'success',
            $count === 1 ? '1 section was created successfully.' : "{$count} sections were created successfully."
        );
    }

    /**
     * Update an existing section.
     *
     * Redirects back to wherever the request came from — the quick-edit
     * dialog on the Sections list, or the Section Information tab of
     * the Edit Section workspace — instead of always bouncing to the
     * Sections list.
     */
    public function update(UpdateSectionRequest $request, Section $section, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorize('update', $section);

        $data = $request->validated();

        // KEEP CODE/NAME IN SYNC — a Section (Regular or Irregular) has
        // exactly one name in the eyes of the rest of the app; the Edit
        // dialog only ever exposes a single "Section Name" field, which
        // is actually bound to section_code — there's no separate input
        // anywhere for section_name. Left alone, an edit that changes
        // the code (e.g. "IT-4A" -> "BSIT-4A") silently leaves
        // section_name stale at its old value, producing a header like
        // "BSIT-4A — IT-4A" that looks like two different sections got
        // merged together, and a Sections list ("Section Name" column,
        // which reads section_name) that appears not to have saved the
        // edit at all. Previously this sync only ran for Irregular
        // sections; broadened here since Regular sections are edited
        // through the exact same single-field dialog and drift the
        // same way. See SectionBatchGeneratorService's
        // nextIrregularName() docblock for why Irregular sections in
        // particular can never be a set of A/B/C blocks — both
        // storeBatch() and store() already set section_code ===
        // section_name for every Section at creation time regardless
        // of type, so this just keeps that invariant true on update too.
        $data['section_name'] = $data['section_code'];

        try {
            $section->update($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'section_code' => 'This section code was just taken by another request. Please use a different code.',
            ]);
        }

        $activityLog->record(
            ActivityLogService::SECTION_UPDATED,
            "{$request->user()->full_name} updated section {$section->section_code}.",
            $section,
            $request->user(),
        );

        return back()->with('success', 'Section updated successfully.');
    }

    /**
     * Delete a section.
     *
     * ARCHIVE, NOT DESTROY — Section already uses SoftDeletes, so this
     * is a soft delete: the row and its EDP-code history stay in the
     * database (only `deleted_at` is set), and its SectionSubject
     * rows are left completely untouched (SectionSubject has no
     * cascadeOnDelete trigger on a soft delete — only on a real one),
     * so every historical edp_code on this Section survives intact
     * for audit purposes. See checkArchived()/restore() below for how
     * the Add Section modal detects and reopens this later.
     *
     * FINALIZED SECTIONS CANNOT BE DELETED — mirrors the frontend
     * guard in onDeleteSection() (both Sections/Index.vue and
     * SectionSubjects/Index.vue), but enforced here too since the
     * frontend check alone can't be trusted against a direct/scripted
     * request. A finalized schedule represents work the Dean/OIC
     * signed off on; it must be explicitly unlocked (see unlock()
     * above) before it's eligible for deletion at all.
     */
    public function destroy(Request $request, Section $section, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorize('delete', $section);

        if ($section->is_finalized) {
            return back()->with('error', "Section {$section->section_code}'s schedule is finalized — unlock it first before it can be deleted.");
        }

        $sectionCode = $section->section_code;

        $section->delete();

        $activityLog->record(
            ActivityLogService::SECTION_DELETED,
            "{$request->user()->full_name} deleted section {$sectionCode}.",
            $section,
            $request->user(),
        );

        return redirect()->route('scheduling.sections')->with('success', 'Section archived successfully.');
    }

    /**
     * Add Section modal — "does an archived Section already exist for
     * this exact Department/Major/Year Level/Section Name/Academic
     * Year/Semester?" (Rule 10). Called right before the modal's
     * Save, once per section name about to be created, so the admin
     * can be offered Restore Existing Section / Create New Section
     * Instance / Cancel instead of silently spawning a second
     * instance next to a section they may have only meant to bring
     * back.
     *
     * Matches on section_code, not section_name — section_code is
     * what's actually unique-per-term (see the sections migration's
     * section_code_active generated column) and what the modal's
     * preview list edits; section_name mirrors section_code at
     * creation time today but isn't the identity key.
     *
     * Read-only — nothing is created, restored, or modified here.
     */
    public function checkArchived(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'major_id' => ['required', 'integer', 'exists:majors,id'],
            'section_code' => ['required', 'string', 'max:20'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => [Rule::in(StoreSectionRequest::SEMESTERS)],
            'year_level' => [Rule::in(StoreSectionRequest::YEAR_LEVELS)],
        ]);

        $this->authorizeSectionCollege($request, (int) $validated['major_id']);

        $archived = Section::onlyTrashed()
            ->where('major_id', $validated['major_id'])
            ->where('section_code', $validated['section_code'])
            ->where('academic_year', $validated['academic_year'])
            ->where('semester', $validated['semester'])
            ->where('year_level', $validated['year_level'])
            ->withCount('sectionSubjects')
            ->orderByDesc('deleted_at')
            ->first();

        if (! $archived) {
            return response()->json(['archived' => null]);
        }

        return response()->json([
            'archived' => [
                'id' => $archived->id,
                'section_code' => $archived->section_code,
                'deleted_at' => $archived->deleted_at?->toIso8601String(),
                'section_subjects_count' => $archived->section_subjects_count,
            ],
        ]);
    }

    /**
     * Restore a previously archived (soft-deleted) Section — Rule 3.
     *
     * Deliberately just `$section->restore()`: because destroy() above
     * never touched SectionSubject rows in the first place, restoring
     * the Section is all that's needed to bring its original subject
     * list and every original edp_code straight back exactly as they
     * were — no separate "restore the subjects too" step, no
     * regeneration of EDP Codes (EDPCodeService::generateForSection
     * Subject() is a no-op once edp_code is already set, and it's
     * never called here at all).
     *
     * $sectionId is resolved manually (not route-model-bound) since
     * implicit binding only ever finds non-trashed rows.
     */
    public function restore(Request $request, int $section): RedirectResponse
    {
        $trashedSection = Section::onlyTrashed()->findOrFail($section);

        $this->authorize('restore', $trashedSection);

        $trashedSection->restore();

        return redirect()->route('scheduling.sections')->with(
            'success',
            "Section {$trashedSection->section_code} restored, with its original EDP codes intact."
        );
    }

    /**
     * SECTION-LEVEL SCHEDULE FINALIZATION.
     *
     * Locks this Section's schedule so no further writes (Room Grid
     * drag, manual cell edit, Save Schedule, Auto Generate) can touch
     * it — see ScheduleConflictService::lockResources(), which is the
     * actual runtime-enforced gate this flag controls. This endpoint
     * only flips the flag; it does not itself validate that the
     * schedule is "complete" — a Dean/Registrar can finalize a
     * partially-scheduled Section deliberately (e.g. remaining rows
     * are intentionally TBA), same as they can Save Schedule with
     * gaps today.
     */
    public function finalize(Request $request, Section $section, NotificationService $notifications): RedirectResponse
    {
        $this->authorize('finalize', $section);

        // Only a FULLY scheduled Section may be finalized — every
        // SectionSubject must have Faculty/Room/Days/Start/End all
        // filled in (or be a Practicum/OJT row, which never needs
        // those — see SectionController::index()'s assigned_subjects_
        // count withCount() for the exact same rule applied there).
        $totalCount = $section->sectionSubjects()->count();
        $assignedCount = $section->sectionSubjects()
            ->where(function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNotNull('faculty_id')
                        ->whereNotNull('days')
                        ->whereNotNull('start_time')
                        ->whereNotNull('end_time')
                        // Online rows are intentionally saved with
                        // room_id = null ("no room needed") — only
                        // require a Room for Face-to-Face rows, matching
                        // the same rule in index()'s assigned_subjects_count.
                        ->where(function ($roomCheck) {
                            $roomCheck->whereNotNull('room_id')
                                ->orWhere('delivery_mode', 'online');
                        });
                })->orWhereHas('subject', function ($subjectQuery) {
                    $subjectQuery->where('subject_type', 'practicum');
                });
            })
            ->count();

        if ($totalCount === 0 || $assignedCount < $totalCount) {
            return back()->with('error', "Section {$section->section_code} isn't fully scheduled yet — every subject needs Faculty, Room, Days, and Time before its schedule can be finalized.");
        }

        // TRANSACTIONAL FINALIZE + NOTIFY (spec Section 10) — the lock
        // write, the audit row, and the Dean/OIC notification all
        // commit together or not at all. If anything inside throws,
        // the whole thing rolls back: the Section is never left
        // locked without its audit/notification, and a notification
        // is never created for a finalize that didn't actually stick.
        DB::transaction(function () use ($request, $section, $notifications) {
            // NOTE: is_finalized/finalized_at/finalized_by are
            // deliberately excluded from Section::$fillable (see the
            // model's docblock) so a generic UpdateSectionRequest can
            // never flip them — but that same guard means a plain
            // ->update() here would silently discard all three
            // (Laravel drops non-fillable attributes on mass
            // assignment without erroring). forceFill() is the
            // correct escape hatch for this one legitimate,
            // controller-owned write path — same reasoning as
            // schedule_version being off-limits to mass assignment
            // (ScheduleConflictService::bumpScheduleVersion() instead
            // uses increment(), which bypasses $fillable the same way).
            $section->forceFill([
                'is_finalized' => true,
                'finalized_at' => now(),
                'finalized_by' => $request->user()->id,
            ])->save();

            $notifications->finalized($section, $request->user());
        });

        return back()->with('success', "Section {$section->section_code}'s schedule has been finalized.");
    }

    /**
     * SECTION-LEVEL SCHEDULE FINALIZATION.
     *
     * Reverses finalize() — Registrar/Admin, plus a Dean/OIC for
     * Sections within their own College/Department scope
     * (SectionPolicy::unlockSchedule()), same scope rule as
     * finalize() itself. Requires a short reason so there's an audit
     * trail of why a "done" schedule was reopened; this becomes part
     * of the flash-message success text today, and is the natural place to
     * hook in an activity log entry later using the same pattern as
     * term_college_finalizations.
     */
    public function unlock(Request $request, Section $section, NotificationService $notifications): RedirectResponse
    {
        $this->authorize('unlockSchedule', $section);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // TRANSACTIONAL UNLOCK + NOTIFY — same reasoning as finalize()
        // above (spec Section 10): unlock write, audit row, and
        // Dean/OIC notification all commit together or not at all.
        DB::transaction(function () use ($request, $section, $notifications, $validated) {
            // Same forceFill() reasoning as finalize() above —
            // is_finalized/finalized_at/finalized_by are guarded out
            // of $fillable, so a plain ->update() here would silently
            // no-op instead of unlocking the section.
            $section->forceFill([
                'is_finalized' => false,
                'finalized_at' => null,
                'finalized_by' => null,
            ])->save();

            $notifications->unlocked($section, $request->user(), $validated['reason']);
        });

        return back()->with(
            'success',
            "Section {$section->section_code}'s schedule has been unlocked. Reason: {$validated['reason']}"
        );
    }

    /**
     * Applies the "Scheduling Status" filter (spec: College/Year
     * Level/Scheduling Status filters on the Sections page) directly
     * against the database — never against frontend-computed badges.
     *
     * Reuses the exact "assigned" definition already used by
     * index()'s assigned_subjects_count withCount() and by
     * finalize()'s completeness check, and the 'Conflict' status
     * SectionSubject rows already carry (see the section_subjects
     * migration) — no second/competing definition of these states is
     * introduced here.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Section>  $query
     */
    private function applySchedulingStatusFilter($query, string $status): void
    {
        $hasConflict = fn ($inner) => $inner->where('status', 'Conflict');

        switch ($status) {
            case 'no_subjects':
                $query->whereDoesntHave('sectionSubjects');
                break;

            case 'finalized':
                $query->where('is_finalized', true);
                break;

            case 'needs_attention':
                $query->where('is_finalized', false)
                    ->whereHas('sectionSubjects', $hasConflict);
                break;

            case 'fully_scheduled':
                $query->where('is_finalized', false)
                    ->whereHas('sectionSubjects')
                    ->whereDoesntHave('sectionSubjects', $hasConflict)
                    ->whereDoesntHave('sectionSubjects', fn ($inner) => $this->unassignedSectionSubjectQuery($inner));
                break;

            case 'in_progress':
            default:
                $query->where('is_finalized', false)
                    ->whereHas('sectionSubjects')
                    ->whereDoesntHave('sectionSubjects', $hasConflict)
                    ->whereHas('sectionSubjects', fn ($inner) => $this->unassignedSectionSubjectQuery($inner));
                break;
        }
    }

    /**
     * The inverse of index()'s assigned_subjects_count condition: a
     * SectionSubject row that is NOT fully assigned (missing Faculty/
     * Room/Days/Start/End) and is NOT a Practicum/OJT row (which never
     * needs those fields — see Subject::isPracticum()).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\SectionSubject>  $query
     */
    private function unassignedSectionSubjectQuery($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('faculty_id')
                ->orWhereNull('days')
                ->orWhereNull('start_time')
                ->orWhereNull('end_time')
                // Online rows are intentionally saved with room_id =
                // null ("no room needed") — only require a Room for
                // Face-to-Face rows, matching the same rule
                // Section::withSubjectProgressCounts() and
                // SectionSubjectController::index() use.
                ->orWhere(function ($needsRoom) {
                    $needsRoom->whereNull('room_id')
                        ->where('delivery_mode', '!=', 'online');
                });
        })->whereDoesntHave('subject', function ($subjectQuery) {
            $subjectQuery->where('subject_type', 'practicum');
        });
    }

    /**
     * Places the given Subjects onto a just-created Section — the Add
     * Section modal's optional "Subjects" step. Mirrors
     * SectionSubjectController::store()'s Manual Selection write path
     * exactly (same EDP Code minting via EDPCodeService, same
     * schedule_version bump via ScheduleConflictService, same
     * "Subject Added" notification per subject) so a subject placed
     * here is indistinguishable from one added afterward on the
     * Section Subjects page — this is only a convenience for doing it
     * up front, never a second/parallel code path.
     *
     * Deliberately does its own lockResources()/bumpScheduleVersion()
     * call rather than reusing one taken by the caller — this always
     * runs immediately after Section::create(), so the Section row
     * being locked here is brand new and was never at risk of being
     * concurrently modified by anything else.
     *
     * A no-op when $subjectIds is empty (the admin skipped the
     * Subjects step) — a Section is still perfectly valid with zero
     * subjects, exactly as before this feature existed.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $subjectIds
     */
    private function attachSubjectsToSection(
        Section $section,
        \Illuminate\Support\Collection $subjectIds,
        EDPCodeService $edpCodeService,
        ScheduleConflictService $conflictService,
        NotificationService $notifications,
        Request $request
    ): void {
        if ($subjectIds->isEmpty()) {
            return;
        }

        $lockedSection = $conflictService->lockResources(null, null, $section->id);

        foreach ($subjectIds as $subjectId) {
            $sectionSubject = SectionSubject::create([
                'section_id' => $section->id,
                'subject_id' => $subjectId,
                'source' => 'Curriculum',
                'capacity' => $section->estimated_students,
                'status' => 'Draft',
            ]);

            $sectionSubject->setRelation('section', $section->loadMissing('major'));
            $edpCodeService->generateForSectionSubject($sectionSubject);

            // A single Subject gets its own notification (names it
            // specifically); several at once are summarized into one
            // "N subjects added" notification instead of one per
            // Subject — see subjectsAddedBatch()'s docblock.
            if ($subjectIds->count() === 1) {
                $notifications->subjectAdded($section, $sectionSubject, $request->user());
            }
        }

        if ($subjectIds->count() > 1) {
            $notifications->subjectsAddedBatch($section, $subjectIds->count(), $request->user());
        }

        $conflictService->bumpScheduleVersion($lockedSection);
    }

    /**
     * Central "can this user create/preview a section for this Major's
     * College?" check, shared by store(), previewBatch(), and
     * storeBatch() so all three entry points into Section creation
     * enforce the exact same rule (spec Section 23).
     *
     * Resolves the owning College strictly from the Major record in
     * the database — a college_id/major_id supplied by the frontend
     * is never trusted on its own. Uses SectionPolicy::createForCollege,
     * which already encodes: Admin/Registrar bypass; Dean/OIC must
     * match their own college_id; Assistant Dean is never allowed
     * full Section CRUD (spec §8).
     *
     * @throws AuthorizationException
     */
    private function authorizeSectionCollege(Request $request, ?int $majorId): void
    {
        $ownerCollegeId = $majorId
            ? Major::with('department')->find($majorId)?->department?->college_id
            : null;

        $this->authorize('createForCollege', [Section::class, $ownerCollegeId]);
    }

    /**
     * Build the Sections page's Academic Term filter dropdown: every
     * real Academic Term on record (Active, Inactive, or Archived),
     * each resolved to the "{academic_year}|{semester}" value used to
     * filter the Sections query, plus a leading "All Terms" option.
     *
     * Terms that can't be resolved to a Section-matching value (see
     * AcademicTerm::sectionSemesterValue()) are skipped — they'd never
     * match any Section anyway, so listing them would just be a dead
     * filter option.
     *
     * @return list<array{value: string, label: string, status: string}>
     */
    private function termFilterOptions(): array
    {
        $options = [
            ['value' => 'all', 'label' => 'All Terms', 'status' => null],
        ];

        AcademicTerm::query()
            ->with(['schoolYear:id,name', 'semester:id,name'])
            ->orderByDesc('id')
            ->get()
            ->each(function (AcademicTerm $term) use (&$options) {
                $semesterValue = $term->sectionSemesterValue();

                if (! $term->schoolYear || ! $semesterValue) {
                    return;
                }

                $options[] = [
                    'value' => "{$term->schoolYear->name}|{$semesterValue}",
                    'label' => "{$term->schoolYear->name} · {$term->semester->name}",
                    'status' => $term->status,
                ];
            });

        return $options;
    }

    /**
     * Build the Add/Edit Section form's Academic Year + Semester
     * options from real AcademicTerm records — replaces the old
     * rolling-range generator, which let a Section be created for a
     * School Year/Semester combination with no AcademicTerm at all
     * (no Scheduling Preferences, no class hours/days configured),
     * orphaned from the Sections page's own term filter
     * (termFilterOptions() above, which only ever lists real
     * AcademicTerm records).
     *
     * Shaped as a flat list of { academic_year, semester, status }
     * pairs (via AcademicTerm::sectionSemesterValue(), never a raw
     * string compare — see that method's docblock) rather than a
     * nested structure, mirroring how this page already hands the
     * frontend a flat `curriculums` list (each row carrying its own
     * major_id) for the Vue side to filter client-side — see
     * RoomController's `departments` (each carrying college_id) for
     * the same pattern elsewhere in the app. Vue derives both the
     * Academic Year dropdown (unique academic_year values) and the
     * Semester dropdown (filtered to the selected Academic Year) from
     * this one list.
     *
     * Archived terms are excluded — creating a brand-new Section under
     * an Archived term doesn't make sense, since that term is done and
     * no longer meant to receive new scheduling data. A Section that
     * already exists under a term which has since been Archived is
     * unaffected by this exclusion — see the frontend's
     * currentTermOption fallback, which keeps that Section's own
     * Academic Year/Semester selectable on its own Edit form even
     * though it won't appear when creating a new one.
     *
     * Terms that can't be resolved to a Section-matching semester
     * value (see sectionSemesterValue()'s docblock) are skipped, same
     * as termFilterOptions() — they'd never be selectable anyway.
     *
     * @return list<array{academic_year: string, semester: string, status: string}>
     */
    private function academicTermSectionOptions(): array
    {
        $options = [];

        AcademicTerm::query()
            ->where('status', '!=', 'Archived')
            ->with(['schoolYear:id,name', 'semester:id,name'])
            ->orderByDesc('id')
            ->get()
            ->each(function (AcademicTerm $term) use (&$options) {
                $semesterValue = $term->sectionSemesterValue();

                if (! $term->schoolYear || ! $semesterValue) {
                    return;
                }

                $options[] = [
                    'academic_year' => $term->schoolYear->name,
                    'semester' => $semesterValue,
                    'status' => $term->status,
                ];
            });

        return $options;
    }
}