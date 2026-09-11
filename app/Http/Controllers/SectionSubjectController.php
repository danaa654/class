<?php

namespace App\Http\Controllers;

use App\Exceptions\ScheduleConflictAbort;
use App\Exceptions\ScheduleVersionConflictException;
use App\Exceptions\SectionFinalizedException;
use App\Http\Requests\BatchUpdateSectionSubjectScheduleRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\StoreSectionSubjectRequest;
use App\Http\Requests\StoreSectionSubjectSplitRequest;
use App\Http\Requests\UpdateSectionSubjectScheduleRequest;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Faculty;
use App\Models\FacultyLoadRequest;
use App\Models\Major;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\SchoolYear;
use App\Models\Subject;
use App\Services\AutoScheduleService;
use App\Services\EDPCodeService;
use App\Services\FacultyWorkloadService;
use App\Services\IrregularSectionMergeService;
use App\Services\RecommendationService;
use App\Services\RoomUtilizationService;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Services\ScheduleConflictService;
use App\Support\AccessScope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SectionSubjectController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly EDPCodeService $edpCodeService,
        private readonly ScheduleConflictService $conflictService,
        private readonly RecommendationService $recommendationService,
        private readonly AutoScheduleService $autoScheduleService,
        private readonly FacultyWorkloadService $workloadService,
        private readonly RoomUtilizationService $roomUtilizationService,
        private readonly IrregularSectionMergeService $mergeService,
        private readonly NotificationService $notifications,
        private readonly ActivityLogService $activityLog
    ) {
    }

    /**
     * SECURITY (spec Section 23): every action below except index()
     * is bound to a {section} route parameter — viewing subjects,
     * assigning Faculty/Room/Time, Auto Generate, and every other
     * per-Section action. None of these previously re-checked that
     * the resolved Section actually belongs to a College the
     * authenticated user is authorized for, which meant a CCS OIC
     * could reach another College's Section entirely by editing the
     * {section} id in the URL/request — the listing being scoped
     * only hid it from the UI, it did not block direct access.
     *
     * This single middleware re-validates the *route-bound* Section
     * (never a raw id from the request body) against
     * SectionPolicy::manageScheduling on every request to this
     * controller, so the check can't be missed on any individual
     * method and can't be bypassed by manipulating request payload
     * fields — only the resolved Eloquent model, from the URL
     * segment Laravel itself bound and loaded, is trusted.
     *
     * @return array<int, Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next) {
                $section = $request->route('section');

                if ($section instanceof Section) {
                    Gate::authorize('manageScheduling', $section);
                }

                return $next($request);
            },
        ];
    }

    /**
     * FACULTY WORKLOAD VALIDATION — "Manual Assignment Validation" /
     * "Save Schedule Validation".
     *
     * Evaluates one row's Faculty assignment against
     * FacultyWorkloadService and returns a "⚠ Teaching Load Limit
     * Exceeded" warning payload when it would push the Faculty member
     * past their Maximum Teaching Load — or null when the assignment
     * is within limits (nothing to warn about).
     *
     * This is deliberately NOT folded into ScheduleConflictService::validate():
     * a workload overage is not a hard, non-negotiable conflict the
     * way a double-booked Faculty/Room/Section is — per spec, an
     * Administrator may explicitly "Override & Save" it. Hard
     * conflicts can never be overridden; this can, and only by an
     * Administrator (see UsersController's use of the same role gate).
     *
     * SPLIT-DELIVERY SCHEDULING — $row is the SectionSubject actually
     * being saved. When it's one half of a Face-to-Face/Online split
     * pair (same section_id+subject_id, different `component` — see
     * SectionSubject::isSplitComponent()), its sibling row is excluded
     * from the "current load" lookup alongside $row itself. Otherwise
     * FacultyWorkloadService::currentLoad() would still count the
     * sibling's placement AND this call's own `additional` would add
     * the Subject's full units again — double-charging a single
     * 3-unit Subject as 6 units just because it's delivered across two
     * rows. Excluding both and adding the Subject's units back once
     * (via `additional`) prices the pair correctly regardless of which
     * half is saved first.
     */
    private function workloadWarningFor(?int $facultyId, ?\App\Models\Subject $subject, SectionSubject $row): ?array
    {
        if (! $facultyId || ! $subject) {
            return null;
        }

        $faculty = \App\Models\Faculty::find($facultyId);
        if (! $faculty) {
            return null;
        }

        $excludingIds = SectionSubject::query()
            ->where('section_id', $row->section_id)
            ->where('subject_id', $row->subject_id)
            ->pluck('id')
            ->push($row->id)
            ->unique()
            ->all();

        $evaluation = $this->workloadService->evaluate($faculty, $subject, $excludingIds);

        if (! $evaluation['exceeds']) {
            return null;
        }

        return [
            'faculty_id' => $faculty->id,
            'faculty_name' => $faculty->full_name,
            'subject_code' => $subject->subject_code,
            'unit_label' => $evaluation['unit_label'],
            'current' => $evaluation['current'],
            'additional' => $evaluation['additional'],
            'projected' => $evaluation['projected'],
            'max' => $evaluation['max'],
            'message' => "{$faculty->full_name} is currently at {$evaluation['current']} / {$evaluation['max']} "
                .strtolower($evaluation['unit_label'])." — assigning {$subject->subject_code} "
                ."({$evaluation['additional']} {$evaluation['unit_label']}) would bring their load to "
                ."{$evaluation['projected']} / {$evaluation['max']}, exceeding their allowable teaching load.",
        ];
    }

    /**
     * Section.year_level uses "First Year"… while CurriculumItem.year_level
     * uses "1st Year"… — this maps between the two so a Section's own
     * Year Level can be pre-selected when loading from a Curriculum.
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
     * Matches curriculum_items.semester's enum exactly. Sent to the
     * frontend so the "Load From Curriculum" Semester dropdown doesn't
     * hardcode a copy of this list.
     *
     * @var list<string>
     */
    private const SEMESTER_OPTIONS = ['First Semester', 'Second Semester', 'Summer'];

    /**
     * Display the "Section Subjects" landing page — a searchable list of
     * Sections, each linking into its own subject-assignment page.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('section_search', ''));

        // SECURITY: this listing must use the same RBAC scope as the
        // Sections module itself (spec Section 23/24) — otherwise a
        // Dean/OIC could see (and click into) every other College's
        // Sections from this entry point even though the main
        // Sections list correctly hides them.
        $sections = Section::query()
            ->visibleTo($request->user())
            ->with(['major:id,name,code', 'curriculum:id,code,name,major_id'])
            ->withCount('subjects')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('section_code', 'like', "%{$search}%")
                        ->orWhere('section_name', 'like', "%{$search}%")
                        ->orWhere('academic_year', 'like', "%{$search}%")
                        ->orWhereHas('major', function ($majorQuery) use ($search) {
                            $majorQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('academic_year')
            ->orderBy('section_code')
            ->paginate(10, ['*'], 'section_page')
            ->withQueryString();

        return Inertia::render('Scheduling/SectionSubjects/Index', [
            'sections' => $sections,
            'filters' => ['section_search' => $search],
        ]);
    }

    /**
     * REAL-TIME SCHEDULE CHANGE DETECTION — lightweight polling
     * endpoint.
     *
     * Returns ONLY the Section's current `schedule_version` (plus
     * `updated_at`) — never the schedule itself — so the scheduling
     * workspace's frontend can cheaply poll "has this changed?"
     * without re-fetching Faculty/Room/Subject option lists or
     * re-running any scheduling computation on every tick.
     *
     * This is an early-warning/UX signal only. It does NOT replace
     * the authoritative optimistic-concurrency check already
     * performed inside a locked transaction by
     * ScheduleConflictService::checkSectionVersion() for every actual
     * write (updateSchedule(), moveRoomGridAssignment(),
     * batchUpdateSchedule(), autoGenerate()) — a stale write is still
     * rejected with HTTP 409 there regardless of what this endpoint
     * last reported.
     *
     * ACTOR-AWARE VERSION — also returns `schedule_version_updated_by`
     * (the user id recorded by ScheduleConflictService::
     * bumpScheduleVersion()) so useSchedulePolling() can tell "the
     * currently-logged-in user's own write" apart from "a genuinely
     * different user's write" instead of treating every version bump
     * as someone else's change. Only the id (plus a display name for
     * the banner text) is returned — no other user data.
     *
     * Authorization: SectionPolicy::manageScheduling is already
     * enforced for every action on this controller by
     * self::middleware() above (route-bound {section}), so this
     * method never exposes a Section's version to a user who
     * couldn't already view that Section's schedule.
     */
    public function scheduleVersion(Section $section): JsonResponse
    {
        $section->loadMissing('scheduleVersionUpdatedBy:id,name');

        return response()->json([
            'section_id' => $section->id,
            'schedule_version' => $section->schedule_version,
            'schedule_version_updated_by' => $section->schedule_version_updated_by,
            'schedule_version_updated_by_name' => $section->scheduleVersionUpdatedBy?->name,
            'updated_at' => optional($section->updated_at)->toIso8601String(),
        ]);
    }

    /**
     * Display the Section Subjects page for a single Section — this IS
     * the Registrar's scheduling workspace. Every subject assigned to
     * the section is shown with its schedule slot (Faculty, Room,
     * Days, Start/End Time) editable inline; nothing is auto-assigned,
     * those fields stay empty/Draft until the Registrar fills them in
     * (manually, or later via AI recommendations) — see
     * updateSchedule().
     */
    public function show(Request $request, Section $section): Response
    {
        $section->load(['major.department.college', 'curriculum:id,code,name,major_id']);

        $search = trim((string) $request->query('subject_search', ''));

        $sectionSubjects = $section->sectionSubjects()
            ->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('subject', function ($subjectQuery) use ($search) {
                    $subjectQuery->where('subject_code', 'like', "%{$search}%")
                        ->orWhere('subject_title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->get()
            ->sortBy(fn ($item) => $item->subject?->subject_code)
            ->values();

        // SPLIT-DELIVERY SCHEDULING — SAME-FACULTY BACKFILL. An Online
        // row is meant to always carry the same Faculty as its
        // Face-to-Face sibling (splitSchedule() sets this at the
        // moment of the split — see its docblock; onFacultyChange() in
        // Show.vue and AutoScheduleService::resyncSplitFacultyPairs()
        // keep them in sync afterward too) — there's no supported way
        // for the two to deliberately disagree, so ANY drift between
        // them is a bug to self-heal, not a Registrar's deliberate
        // choice to preserve. This used to only fill an Online row
        // left completely blank (e.g. a split made before the
        // Face-to-Face half had a Faculty assigned yet), which missed
        // the more common drift: Auto Generate assigning the
        // Face-to-Face half a DIFFERENT Faculty than whatever the
        // Online half already had (see AutoScheduleService's own
        // per-run resync for why that still happened even there) —
        // that left BOTH rows non-null but disagreeing, which the old
        // whereNull() filter here never caught. Now corrects that case
        // on every page load too, not just a blank Online row.
        //
        // CONFLICT-SAFE SYNC — this self-heal used to
        // forceFill()->save() the inherited Faculty unconditionally,
        // completely bypassing ScheduleConflictService. An Online row
        // already carries its own Days/Start/End Time (unlike a
        // brand-new split half), so blindly swapping its Faculty here
        // can silently double-book that inherited Faculty against
        // whatever ELSE they teach at that same Day/Time in another
        // Section — a real Faculty conflict created by a page load,
        // never surfaced anywhere, and never caught by the "Save
        // Schedule"/manual-edit conflict checks because no save
        // request was ever made for the Online row itself. Now the
        // same findFacultyConflict() check every manual save already
        // goes through runs here first: a clean inheritance still
        // self-heals exactly as before; one that would create a
        // Faculty double-booking is left alone (Faculty NOT swapped)
        // and the row is flagged 'Conflict' instead, so it surfaces on
        // the Sections list / Scheduling Dashboard's "Needs Attention"
        // indicator for the Registrar to resolve by hand (re-time or
        // reassign) rather than being silently created.
        $facultyBySubjectId = $sectionSubjects
            ->where('delivery_mode', 'face_to_face')
            ->whereNotNull('faculty_id')
            ->pluck('faculty_id', 'subject_id');

        $sectionSubjects
            ->where('delivery_mode', 'online')
            ->each(function (SectionSubject $onlineRow) use ($facultyBySubjectId) {
                $facultyId = $facultyBySubjectId->get($onlineRow->subject_id);

                if (! $facultyId) {
                    return;
                }

                $dayTokens = array_filter(explode(',', (string) $onlineRow->days));
                $hasWindow = ! empty($dayTokens) && $onlineRow->start_time && $onlineRow->end_time;

                // Checked whenever this row already has (or would end
                // up with) the inherited Faculty AND its own Day/Time
                // window — covers both a fresh drift about to be
                // synced below, and a pair that was already
                // (unsafely) synced by an older build of this method
                // before this check existed, which otherwise would
                // never get re-evaluated once faculty_id already
                // matches.
                // FIX (merged-row false positive): must exclude the
                // FULL merge group (self + host + riders), exactly
                // like every other write path does via
                // mergeExclusionIds() — not just $onlineRow->id. A
                // merged Online row is DELIBERATELY sharing its
                // Faculty/Day/Time with its merge partner in another
                // Section (that overlap is the entire point of the
                // merge — see mergeExclusionIds()'s docblock), so
                // excluding only itself made this self-heal re-detect
                // that intentional overlap as a fresh 'Conflict' on
                // every single page load/refresh, immediately undoing
                // whatever clean 'Scheduled' status Save Schedule had
                // just set (Save Schedule already calls
                // mergeExclusionIds() and never saw a conflict here).
                $conflict = $hasWindow
                    ? $this->conflictService->findFacultyConflict(
                        $facultyId,
                        $this->conflictService->mergeExclusionIds($onlineRow),
                        $dayTokens,
                        $onlineRow->start_time,
                        $onlineRow->end_time,
                        $onlineRow->section_id
                    )
                    : null;

                if ($conflict) {
                    if ($onlineRow->status !== 'Conflict') {
                        $onlineRow->forceFill(['status' => 'Conflict'])->save();
                    }

                    return;
                }

                // No conflict — proceed with the normal same-faculty
                // sync. Deliberately never clears an existing
                // 'Conflict' status here: that status may have been
                // set for a different reason entirely (Room/Section
                // conflict, etc.), and this method only ever knows
                // about the one Faculty-overlap case it just checked.
                if ($onlineRow->faculty_id !== $facultyId) {
                    $onlineRow->forceFill(['faculty_id' => $facultyId])->save();
                    $onlineRow->setRelation('faculty', $onlineRow->faculty()->first());
                }
            });

        // PERFORMANCE — wrapped in closures below (not run eagerly here)
        // so a partial Inertia reload that doesn't ask for these props
        // (see refreshSchedule() in Show.vue) skips the underlying
        // queries entirely instead of just trimming them from the
        // response payload. A plain PHP closure IS still invoked on a
        // normal/initial page visit — this only changes what a
        // *partial* reload after a save (Split, Save Schedule, etc.)
        // has to recompute. See PropsResolver::resolveProps() in
        // inertiajs/inertia-laravel: an unresolved prop's closure is
        // simply never called when its path isn't in that request's
        // `only` list.
        //
        // Every Active Curriculum belonging to this Section's own Major,
        // for the "Load From Curriculum" tab's Curriculum dropdown.
        // Curriculum.major_id is required (one Curriculum = one Major),
        // so this is a hard restriction, not a client-side filter —
        // a BSIT section will never see a BSED curriculum to pick from.
        $curriculums = fn () => Curriculum::query()
            ->where('status', 'Active')
            ->where('major_id', $section->major_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'major_id']);

        // Active Subjects for "Manual Selection" — restricted to the
        // Section's own Major, plus true General Education subjects
        // (category = 'General Education', shared by every Major).
        // NOTE: Major-category subjects with a null major_id (e.g. the
        // BSCRIM shared core — FORENSIC1, ENHANCE2, CDI1, etc.) are
        // deliberately excluded here, not treated as universal — they
        // only apply to a *subset* of Majors (the 4 BSCRIM
        // specializations), which this schema's single major_id column
        // can't express. Including them here would leak them into
        // every other Major's picker (e.g. BSIT), which is the bug
        // being fixed. They're still reachable via "Load From
        // Curriculum" for BSCRIM sections, since CurriculumItem
        // references them directly regardless of major_id.
        $placedSubjectIds = $sectionSubjects->pluck('subject_id');

        $availableSubjects = fn () => Subject::query()
            ->where('is_active', true)
            ->where(function ($query) use ($section) {
                $query->where('major_id', $section->major_id)
                    ->orWhere('category', 'General Education');
            })
            ->whereNotIn('id', $placedSubjectIds)
            ->orderBy('subject_code')
            ->get(['id', 'subject_code', 'subject_title', 'category', 'units']);

        // Every active Faculty member, with the Subjects they're
        // qualified to teach (Teaching Qualifications module). Sent in
        // full so the scheduling table can filter the Faculty dropdown
        // per-row client-side without a round trip per cell. General
        // Education Faculty are qualified for every "General Education"
        // subject regardless of their explicit pivot rows.
        //
        // PERFORMANCE: current_load for every Active Faculty member,
        // computed in ONE batched query (currentLoadsFor()) instead of
        // one query per Faculty member. Calling
        // FacultyWorkloadService::currentLoad($faculty) inside the
        // ->map() below — as this used to do — re-runs
        // activePlacements()'s SectionSubject query separately for
        // each Faculty member, which on a full Active roster meant a
        // fresh DB round trip per Faculty every time this page loaded.
        // That N+1 was the actual cause of Section pages taking a
        // while to open. Mirrors how $roomUtilization below already
        // batches per-Room figures via summarizeRooms() instead of
        // calling into RoomUtilizationService per Room.
        $activeFaculty = function () {
            $activeFacultyList = Faculty::query()
                ->where('status', 'Active')
                ->with(['subjects:id', 'college:id,name,short_name,counts_as_gened'])
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'college_id', 'max_teaching_units']);

            $facultyCurrentLoads = $this->workloadService->currentLoadsFor($activeFacultyList);

            return $activeFacultyList->map(fn (Faculty $faculty) => [
                'id' => $faculty->id,
                'full_name' => $faculty->full_name,
                'faculty_category' => $faculty->faculty_category,
                'qualified_subject_ids' => $faculty->subjects->pluck('id'),
                // college_id/college_name — MUST be sent so the
                // scheduling table's client-side grouping
                // (facultyGroupsFor in Show.vue) can tell which
                // College a Faculty member belongs to and group them
                // the same way Rooms are grouped by type. This was
                // previously queried but dropped before being handed
                // to the frontend, which silently broke College-based
                // grouping (every faculty landed in one flat "Other
                // Active Faculty" bucket regardless of College).
                'college_id' => $faculty->college_id,
                'college_name' => $faculty->college?->short_name ?? $faculty->college?->name ?? 'General Education',
                // Whether this Faculty's own College has opted in as a
                // General Education/Minor faculty pool (e.g. College of
                // Teacher Education) — sent so the Show.vue's
                // isQualifiedFor() (Faculty dropdown grouping + the
                // client-side "Scheduling Issues" panel) can mirror
                // SectionSubject::getFacultyMismatchAttribute()'s
                // counts_as_gened branch exactly instead of only ever
                // recognizing a null college_id as GenEd-eligible.
                'college_counts_as_gened' => (bool) $faculty->college?->counts_as_gened,
                // Teaching Load, same source (FacultyWorkloadService)
                // the recommendation ranking and Save Schedule's
                // workload guard already use — shown next to every
                // Faculty option (not just recommended ones) so the
                // Registrar sees at a glance how close to capacity a
                // manual pick is before assigning them.
                'current_load' => $facultyCurrentLoads[$faculty->id] ?? 0,
                'max_teaching_units' => $faculty->max_teaching_units,
            ]);
        };

        // Same source (RoomUtilizationService) the Rooms page and the
        // recommendation ranking already read — shown next to every
        // Room option (not just recommended ones), mirroring how
        // activeFaculty above carries current_load/max_teaching_units
        // for every Faculty option.
        $activeRooms = function () {
            $activeRoomsList = Room::query()
                ->where('status', 'Active')
                ->with('college:id,name,short_name')
                ->orderBy('room_code')
                ->get(['id', 'room_code', 'room_name', 'room_type', 'capacity', 'college_id']);

            $roomUtilization = $this->roomUtilizationService->summarizeRooms($activeRoomsList);

            return $activeRoomsList->map(fn (Room $room) => [
                'id' => $room->id,
                'room_code' => $room->room_code,
                'room_name' => $room->room_name,
                'room_type' => $room->room_type,
                'capacity' => $room->capacity,
                // college_id/college_name — a null college_id means a
                // Shared room usable by any College (mirrors Faculty's
                // null-college_id = General Education pool above). MUST
                // be sent so the scheduling table's client-side Room
                // College Mismatch check (Show.vue's tableConflicts) can
                // flag a room that belongs to a DIFFERENT College than
                // this Section's own — e.g. an SHTM Lab picked for a
                // BSIT class — the same way it already flags a Room Type
                // Mismatch, before the Registrar saves.
                'college_id' => $room->college_id,
                'college_name' => $room->college?->short_name ?? $room->college?->name ?? null,
                'scheduled_hours' => $roomUtilization[$room->id]['scheduled_hours'] ?? 0,
                'max_hours' => $roomUtilization[$room->id]['max_hours'] ?? 0,
            ]);
        };

        // Active School Year's Scheduling Window — the hard 8:00 AM–7:00 PM
        // (or whatever's configured) boundary the manual Day & Time editor
        // must never let the Registrar pick outside of. Same source the
        // scheduling engine's own candidateStartTimes() reads from, so the
        // frontend and the overrideTime() server check never disagree.
        $activeSchoolYear = SchoolYear::active();
        $schedulingWindow = [
            'start_time' => $activeSchoolYear?->classStartTime() ?? SchoolYear::DEFAULT_CLASS_START_TIME,
            'end_time' => $activeSchoolYear?->classEndTime() ?? SchoolYear::DEFAULT_CLASS_END_TIME,
            'available_days' => $activeSchoolYear?->allowedDays() ?? SchoolYear::DEFAULT_CLASS_DAYS,
            // Room Grid's hour-row granularity — same 30-minute slicing
            // the Auto Schedule AI itself uses (SchoolYear::candidateStartTimes()),
            // so a manual drag-and-drop placement can never land on a
            // slot boundary the engine wouldn't also offer.
            'interval_minutes' => $activeSchoolYear?->intervalMinutes() ?? SchoolYear::DEFAULT_TIME_INTERVAL_MINUTES,
            // Fixed Lunch Break window (never editable — see SchoolYear's
            // class docblock) so the Room Grid can visually block it out
            // the same way the Auto Schedule AI already refuses to place
            // anything across it (SchoolYear::overlapsLunchBreak()).
            'lunch_start' => SchoolYear::LUNCH_BREAK_START,
            'lunch_end' => SchoolYear::LUNCH_BREAK_END,
        ];

        // Sibling Sections — every other Section the current user can
        // already see (Section::visibleTo(), the same College/Department
        // RBAC scope the Sections list itself uses — Dean/OIC only ever
        // gets their own College's Sections, Assistant Dean and
        // Admin/Registrar get everything), restricted to this Section's
        // Sibling Sections — every other Section the current user can
        // already see (Section::visibleTo(), the same College/Department
        // RBAC scope the Sections list itself uses — Dean/OIC only ever
        // gets their own College's Sections, Assistant Dean and
        // Admin/Registrar get everything), further narrowed to THIS
        // Section's own College (e.g. viewing a BSHM section only offers
        // other SHTM programs like BSTM — never BSIT or BSCRIM) and
        // restricted to this Section's own Academic Year + Semester so
        // switching via the dropdown never silently jumps into a
        // different, unrelated term. Powers the header's section-switcher
        // so a Dean/OIC (or Admin/Registrar) managing an entire College's
        // schedule doesn't have to bounce back to the Sections list
        // between every Section within that College.
        $sectionCollegeId = $section->major?->department?->college_id;

        $siblingSections = fn () => Section::withSubjectProgressCounts(
            Section::query()
                ->visibleTo($request->user())
                ->where('academic_year', $section->academic_year)
                ->where('semester', $section->semester)
                ->when(
                    $sectionCollegeId,
                    fn ($query) => $query->whereHas(
                        'major.department',
                        fn ($inner) => $inner->where('college_id', $sectionCollegeId),
                    ),
                )
                ->with('major:id,name,code')
        )
            // Same "how far along is scheduling?" counts the Sections
            // list (SectionController::index()) already computes — see
            // Section::withSubjectProgressCounts() — so the dropdown can
            // show a status dot (green = Fully Scheduled, amber =
            // Partially Scheduled, gray = Not Scheduled/No Subjects Yet)
            // without a second round trip per Section.
            ->orderBy('section_code')
            ->get(['id', 'section_code', 'section_name', 'major_id']);

        return Inertia::render('Scheduling/SectionSubjects/Show', [
            'section' => $section,
            // CONCURRENCY HARDENING — the Section's schedule_version
            // at page-load time. The frontend should carry this
            // forward as `expected_schedule_version` on every
            // scheduling write it makes for this Section (single-cell
            // edit, Room Grid move, Auto Generate, Save Schedule) so
            // the backend can detect a save made against stale data.
            // Also present on `section.schedule_version` itself since
            // Section is already serialized above — surfaced
            // top-level too for a frontend that doesn't want to dig
            // into the nested resource.
            'scheduleVersion' => $section->schedule_version,
            'siblingSections' => $siblingSections,
            'sectionSubjects' => $sectionSubjects,
            'filters' => ['subject_search' => $search],
            'availableSubjects' => $availableSubjects,
            // Scheduling table (Faculty/Room/Days/Time cells).
            'activeFaculty' => $activeFaculty,
            'activeRooms' => $activeRooms,
            'curriculums' => $curriculums,
            'yearLevelMap' => self::YEAR_LEVEL_MAP,
            'sectionYearLevel' => self::YEAR_LEVEL_MAP[$section->year_level] ?? null,
            'sectionSemester' => $section->semester,
            'schedulingWindow' => $schedulingWindow,
            // Institution-wide Teaching Load ceiling — same value
            // FacultyController already sends to the Faculty Master
            // page (FacultyLoadRequest::effectiveCapFor()) — passed
            // through to RoomGrid.vue so its inline "Request more
            // units"/"Add Units" shortcut can cap the number field at
            // exactly the same limit StoreFacultyLoadRequestRequest
            // enforces server-side, instead of guessing a value here.
            'hardCapUnits' => FacultyLoadRequest::effectiveCapFor($request->user()),
            // Section Information tab (Tab 1) options.
            'activeMajors' => fn () => Major::query()->where('status', 'Active')->orderBy('name')->get(['id', 'name', 'code']),
            'yearLevels' => StoreSectionRequest::YEAR_LEVELS,
            'semesterOptions' => StoreSectionRequest::SEMESTERS,
            'academicYears' => $this->academicYearOptions(),
        ]);
    }

    /**
     * Inline "spreadsheet" schedule update for a single Subject row on
     * the workspace — Faculty, Room, Days, Start/End Time, or Capacity.
     * The frontend auto-saves one field at a time as the user edits a
     * cell.
     *
     * Runs the same conflict checks the scheduling engine relies on:
     * a Faculty or Room already booked on an overlapping Day/Time slot
     * elsewhere blocks the save. On success the row's Status is
     * recomputed (Draft / Scheduled / Conflict) and the fresh row is
     * returned so the frontend can update in place without a full
     * page reload.
     */
    public function updateSchedule(
        UpdateSectionSubjectScheduleRequest $request,
        Section $section,
        SectionSubject $subject
    ): \Illuminate\Http\JsonResponse {
        abort_unless($subject->section_id === $section->id, 404);

        return $this->performScheduleAssignmentUpdate($request, $subject);
    }

    /**
     * Room Grid — move an existing schedule block to another Day/Time
     * and/or Room, INCLUDING a block that belongs to a different
     * Section than the one currently open on the page (spec:
     * "current section = UI context, scheduling scope = permission").
     *
     * Deliberately NOT nested under {section} — the Room Grid is
     * room-centric and shows every Section's bookings in that Room.
     * Authorization is never "does the URL's Section belong to me?"
     * nor "does it match the Section currently open?" — it's
     * evaluated purely against whether the SCHEDULE ASSIGNMENT'S OWN
     * Section is within the authenticated user's authorized
     * scheduling scope (SectionPolicy::moveScheduleAssignment() ->
     * manageScheduling()/College-Department scope). A CCS-scoped user
     * can move any BSIT-* Section's block regardless of which BSIT-*
     * Section's Room Grid they currently have open — they are never
     * required to switch sections first. $currentSectionId is used
     * ONLY to decide whether the "Move Schedule Assignment?"
     * cross-section confirmation applies, never as an authorization
     * input itself. Frontend visibility/lock state must never be
     * relied on alone — this check is the actual authorization
     * boundary; a bypassed or hand-crafted request hits it exactly
     * the same way and gets a 403 when the Section is outside scope.
     *
     * Reuses the exact same validation + conflict-check + save path as
     * the Subjects tab's inline editor (performScheduleAssignmentUpdate())
     * — never a second, parallel scheduling write path — so Room
     * Grid drags and Subjects-tab edits can never disagree about what
     * counts as a conflict.
     */
    public function moveRoomGridAssignment(UpdateSectionSubjectScheduleRequest $request, SectionSubject $subject): JsonResponse
    {
        $subject->loadMissing(['section.major.department', 'subject']);

        // The single, real authorization boundary: is this schedule's
        // OWN Section within the user's authorized scheduling scope?
        // Aborts with 403 automatically when it isn't — regardless of
        // which Section is currently selected in the UI.
        Gate::authorize('moveScheduleAssignment', $subject->section);

        $currentSectionId = $request->validated('current_section_id');
        $isCrossSection = $currentSectionId && (int) $currentSectionId !== $subject->section_id;

        // A cross-section move is never saved on the strength of the
        // frontend's own "Move Schedule Assignment?" dialog alone —
        // the backend independently requires the acknowledgement flag
        // before writing, so a request that skips/tampers with the UI
        // still can't silently move another Section's schedule.
        if ($isCrossSection && ! $request->boolean('cross_section_confirmed')) {
            return response()->json([
                'message' => "This schedule belongs to {$subject->section->section_code}, a section within your authorized scheduling scope. Moving it will modify {$subject->section->section_code}'s schedule. Confirm to proceed.",
                'requires_cross_section_confirmation' => true,
            ], 409);
        }

        return $this->performScheduleAssignmentUpdate($request, $subject);
    }

    /**
     * Shared write path behind both updateSchedule() (Subjects tab,
     * always the currently-open Section) and moveRoomGridAssignment()
     * (Room Grid, potentially a DIFFERENT authorized Section) — see
     * moveRoomGridAssignment()'s docblock for why these must never be
     * two separate implementations of the same conflict rules.
     */
    private function performScheduleAssignmentUpdate(
        UpdateSectionSubjectScheduleRequest $request,
        SectionSubject $subject
    ): JsonResponse {
        // CATEGORY SCOPE (Assistant Dean = GenEd/Minor only, spec §8) —
        // the shared write path behind BOTH updateSchedule() (Subjects
        // tab inline editor) and moveRoomGridAssignment() (Room Grid
        // drag/drop), so this is the one place that has to catch every
        // way a schedule assignment gets written, regardless of which
        // tab it came from. Section-level scope is already enforced
        // upstream (the controller-wide manageScheduling middleware
        // for updateSchedule(), moveScheduleAssignment's own Gate call
        // for moveRoomGridAssignment()) — neither of those checks the
        // SUBJECT's category, only the SECTION, which is why an
        // Assistant Dean could previously drag/drop or manually assign
        // a Major subject despite Auto Generate already excluding
        // Major subjects for them (AutoScheduleService::unscheduledRows()).
        // This closes that gap at the one shared write path rather
        // than duplicating the check in both callers.
        $subject->loadMissing('subject');
        if (AccessScope::isAssistantDean($request->user()) && $subject->subject && ! $subject->subject->isSharedResource()) {
            abort(403, "{$subject->subject->subject_code} is a Major subject — outside your Assistant Dean scheduling scope (GenEd/Minor only). The Dean/OIC manages this subject's schedule.");
        }

        $validated = $request->validated();

        // Merge the incoming (possibly partial) edit onto the row's
        // current values so conflict-checking always evaluates the
        // row's *resulting* Day/Time/Faculty/Room, not just the one
        // field that changed.
        $facultyId = array_key_exists('faculty_id', $validated) ? $validated['faculty_id'] : $subject->faculty_id;
        $roomId = array_key_exists('room_id', $validated) ? $validated['room_id'] : $subject->room_id;
        $days = array_key_exists('days', $validated) ? implode(',', $validated['days'] ?? []) : $subject->days;
        $startTime = array_key_exists('start_time', $validated) ? $validated['start_time'] : $subject->start_time;
        $endTime = array_key_exists('end_time', $validated) ? $validated['end_time'] : $subject->end_time;
        $capacity = array_key_exists('capacity', $validated) ? $validated['capacity'] : $subject->capacity;

        // SHARED CLASS (reverse merge) — re-validated fresh here,
        // never trusted from whatever the client's earlier
        // reverseMergeRecommendation() call saw, since the target row
        // (or this row's own proposed slot) may have changed since.
        // A no-longer-compatible target simply falls through to the
        // normal conflict check below, which will report the real
        // Faculty/Room conflict exactly as it would without this
        // feature — never a silent, unconfirmed merge.
        $mergeTarget = null;
        if (! empty($validated['merge_target_section_subject_id'])) {
            $mergeTarget = SectionSubject::find($validated['merge_target_section_subject_id']);
            if ($mergeTarget) {
                // $dayTokens proper isn't computed until below (it's
                // derived together with the Weekly Hours Mismatch
                // warning) — this block runs earlier, so it derives
                // its own copy from the same $days value rather than
                // reordering the rest of the method around it.
                $mergeCheckDayTokens = array_values(array_filter(explode(',', (string) $days)));
                $reverseEvaluation = $this->mergeService->evaluateReversePlacement($subject, [
                    'room_id' => $roomId,
                    'faculty_id' => $facultyId,
                    'days' => $mergeCheckDayTokens,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ], $mergeTarget);

                if (! $reverseEvaluation['compatible']) {
                    return response()->json([
                        'message' => $reverseEvaluation['blocking_reason'] ?? 'This class is no longer a compatible Shared Class target. Please refresh and try again.',
                    ], 422);
                }
            }
        }

        $errors = [];

        // ROOM GRID DEFERRED CONFIRMATION — see
        // UpdateSectionSubjectScheduleRequest's docblock. When set, the
        // Room/Hours mismatch checks below are allowed to save WITHOUT
        // being blocked, but — critically — WITHOUT being treated as
        // confirmed either: room_type_confirmed/room_college_confirmed/
        // hours_confirmed still get whatever was actually passed in the
        // request (false, since Room Grid never sends true anymore), so
        // the mismatch stays a live "Scheduling Issue" until the
        // Registrar confirms it for real via the Subjects tab's batch
        // "Save Schedule". Room Capacity is deliberately NOT covered —
        // capacity_confirmed keeps its original immediate-confirm
        // behavior, unaffected by this flag.
        $deferMismatchConfirmation = $request->boolean('defer_mismatch_confirmation');

        // Room Capacity Warning — Section Capacity > Room Capacity is not
        // a hard block, but the Registrar must explicitly confirm before
        // it's allowed to save (see UpdateSectionSubjectScheduleRequest).
        $room = null;
        if ($roomId) {
            $room = Room::find($roomId);
        }

        if ($room && $capacity && $capacity > $room->capacity && ! $request->boolean('capacity_confirmed')) {
            $errors['capacity'] = "Section Capacity ({$capacity}) exceeds this room's capacity ({$room->capacity}). "
                .'Confirm to save anyway.';
        }

        // Room Type Mismatch Warning — a Minor/GenEd (or any Lecture-only)
        // subject dropped into a Laboratory room, or a subject that needs
        // lab time booked into a plain Lecture room. Not a hard block —
        // some rooms legitimately double up — so this follows the exact
        // same "flagged, not blocked" pattern as Room Capacity above:
        // needs an explicit room_type_confirmed=true to save anyway, OR
        // $deferMismatchConfirmation (Room Grid placing without
        // permanently confirming — see above). See
        // RoomGrid.vue's confirmAssign()/writeSchedule() and
        // SectionSubjects/Show.vue's tableConflicts() for where the
        // Registrar is prompted to confirm.
        $subject->loadMissing('subject');
        if ($room && $room->room_type && ! $request->boolean('room_type_confirmed') && ! $deferMismatchConfirmation) {
            $wantsLaboratory = (int) $subject->subject->laboratory_hours > 0;
            $typeMismatch = $wantsLaboratory
                ? $room->room_type !== 'Laboratory'
                : $room->room_type === 'Laboratory';

            if ($typeMismatch) {
                $errors['room_type'] = "{$subject->subject->subject_code} is a "
                    .($wantsLaboratory ? 'Laboratory' : 'Lecture')
                    ." subject, but {$room->room_name} is a {$room->room_type} room. "
                    .'Confirm to save anyway.';
            }
        }

        // Room College Mismatch Warning — a Room owned by a DIFFERENT
        // College than this Subject's own (e.g. a BSIT/Major subject
        // dropped into an SHTM Laboratory). Not a hard block — a Minor
        // subject legitimately borrowing another College's lab, or an
        // Administrator/Registrar override, is allowed — but it needs
        // an explicit room_college_confirmed=true first (or
        // $deferMismatchConfirmation), exactly the
        // same "flagged, not blocked" pattern as Room Type above.
        // Shared rooms (no college_id at all) and General Education
        // subjects (which have no single owning College) are never
        // flagged, matching resolveRoomScopeTier()'s 'shared' tier in
        // RecommendationService.
        $subject->loadMissing('section.major.department');
        if (
            $room
            && $room->college_id
            && $subject->subject->category !== 'General Education'
            && ! $request->boolean('room_college_confirmed')
            && ! $deferMismatchConfirmation
        ) {
            $sectionCollegeId = $subject->section->major?->department?->college_id;
            if ($sectionCollegeId && (int) $room->college_id !== (int) $sectionCollegeId) {
                $room->loadMissing('college');
                $roomCollegeName = $room->college?->short_name ?? $room->college?->name ?? 'another college';
                $errors['room_college'] = "{$subject->subject->subject_code} belongs to this section's own college, but "
                    ."{$room->room_name} belongs to {$roomCollegeName}. Confirm to save anyway.";
            }
        }

        $dayTokens = array_filter(explode(',', (string) $days));

        // Faculty Mismatch Warning — mirrors Room Type/Room College
        // Mismatch's exact "flagged, not blocked" pattern. The
        // assigned Faculty carries no Teaching Qualification for this
        // Subject and isn't from its academic home College/GenEd
        // pool (see SectionSubject::getFacultyMismatchAttribute()) —
        // e.g. a CCS faculty member manually placed on a BSED Minor
        // subject. Not a hard block — Manual Override is a
        // deliberately supported path (see the "Other Active Faculty
        // (Manual Override)" group in RoomGrid.vue's facultyOptions)
        // — but needs an explicit faculty_mismatch_confirmed=true to
        // save anyway, OR $deferMismatchConfirmation.
        if ($facultyId && ! $request->boolean('faculty_mismatch_confirmed') && ! $deferMismatchConfirmation) {
            $candidateFaculty = Faculty::find($facultyId);
            $subject->loadMissing('subject.major.department');
            if ($candidateFaculty && $subject->subject) {
                $candidateFaculty->loadMissing('subjects:id');
                $isQualified = $candidateFaculty->subjects->contains('id', $subject->subject->id);
                if (! $isQualified) {
                    $candidateFaculty->loadMissing('college');
                    $subjectCollegeId = $subject->subject->major?->department?->college_id;
                    // Mirrors SectionSubject::getFacultyMismatchAttribute()'s
                    // GenEd pool: "no College" OR a College that has opted
                    // in via counts_as_gened (e.g. College of Teacher
                    // Education supplying most GenEd/Minor faculty).
                    $isHomeMatch = $subjectCollegeId === null
                        ? ($candidateFaculty->college_id === null || (bool) $candidateFaculty->college?->counts_as_gened)
                        : $candidateFaculty->college_id === $subjectCollegeId;

                    if (! $isHomeMatch) {
                        $errors['faculty_mismatch'] = "{$candidateFaculty->full_name} is not on file as qualified or from the academic home for "
                            ."{$subject->subject->subject_code}. Confirm to save anyway.";
                    }
                }
            }
        }

        // Weekly Hours Mismatch Warning — the scheduled Days x
        // (End-Start) doesn't add up to what the Subject's curriculum
        // hours require (e.g. curriculum needs 5 hrs/week, the
        // Registrar could only fit 4 because of Room/Faculty
        // availability). Same "flagged, not blocked" pattern as
        // Room Capacity above — needs an explicit hours_confirmed=true
        // to save anyway, OR $deferMismatchConfirmation. Soft warnings
        // (Capacity/Hours) are read-only
        // checks against data that can't be raced the way a Room/
        // Faculty/Section booking can, so these stay OUTSIDE the
        // locked transaction below — only the actual booking conflict
        // check + write needs the lock.
        $subject->loadMissing('subject');
        if (! empty($dayTokens) && $startTime && $endTime) {
            // SPLIT-DELIVERY SCHEDULING — a split row (component !==
            // 'combined') only needs to add up to ITS OWN split_hours
            // share, not the subject's full weekly total — e.g. the
            // Online half of a 5-hr subject split into 4 F2F + 1
            // Online should compare against 1, not 5. An ordinary,
            // never-split row is unaffected (split_hours is null).
            $requiredHours = $subject->split_hours
                ?? (((int) $subject->subject->lecture_hours) + ((int) $subject->subject->laboratory_hours));
            if ($requiredHours <= 0) {
                $requiredHours = 3; // matches RecommendationService::scoreArbitraryTime()'s fallback
            }

            $actualMinutes = $this->minutesBetween($startTime, $endTime) * count($dayTokens);
            $actualHours = round($actualMinutes / 60, 2);

            if ($actualHours !== (float) $requiredHours && ! $request->boolean('hours_confirmed') && ! $deferMismatchConfirmation) {
                $errors['hours'] = "This schedule totals {$actualHours} hrs/week, but {$subject->subject->subject_code} requires {$requiredHours} hrs/week. "
                    .'Confirm to save anyway.';
            }
        }

        if (! empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        // SCHEDULING NOTIFICATION SYSTEM (spec Section 7) — snapshot
        // the row's CURRENT values before the write below so they can
        // be diffed against what actually got saved. Taken outside
        // the locked transaction (read-only, and the values being
        // compared are the same ones already merged above), but the
        // notification itself is only ever fired after the
        // transaction below commits successfully — see the dispatch
        // call after DB::transaction().
        $beforeSnapshot = [
            'faculty_id' => $subject->faculty_id,
            'room_id' => $subject->room_id,
            'days' => $subject->days,
            'start_time' => $subject->start_time,
            'end_time' => $subject->end_time,
        ];

        // MANUAL ASSIGNMENT VALIDATION — Faculty Workload. Not a hard
        // conflict (see workloadWarningFor()'s docblock): the Registrar
        // gets a 409 "Teaching Load Limit Exceeded" warning the first
        // time, and anyone who can also raise this Faculty member's
        // load ceiling (FacultyPolicy::changeMaxLoad() — Admin,
        // Registrar, Dean, OIC, Assistant Dean) can resubmit with
        // workload_confirmed=true to Override & Save. This mirrors
        // changeMaxLoad() deliberately: overriding the cap on Save and
        // raising the cap itself are the same underlying trust
        // decision, so a role that already holds one shouldn't be
        // blocked from the other.
        $workloadWarning = $this->workloadWarningFor($facultyId, $subject->subject, $subject);

        if ($workloadWarning) {
            $canOverride = (bool) $request->user()?->can('changeMaxLoad', Faculty::class);
            $confirmed = $request->boolean('workload_confirmed');

            if (! $canOverride || ! $confirmed) {
                return response()->json([
                    'workload_warning' => $workloadWarning,
                    'can_override' => $canOverride,
                    'message' => $canOverride
                        ? 'This assignment exceeds the faculty\'s allowable workload. Proceed anyway?'
                        : 'This assignment exceeds the faculty\'s allowable workload. Only an Administrator, Registrar, Dean, OIC, or Assistant Dean may override this validation.',
                ], 409);
            }
        }

        // CONCURRENCY-SAFE FROM HERE ON — the authoritative Room/
        // Faculty/Section conflict re-check AND the write happen
        // inside ONE locked transaction (see ScheduleConflictService::
        // lockResources()'s docblock for why fixed lock order matters
        // and why per-resource locks are enough). A conflict found
        // under lock throws ScheduleConflictAbort, which rolls the
        // transaction back with NO partial write and is caught below
        // to preserve the exact same 422 {errors} response shape this
        // endpoint already returned before this hardening — the
        // response contract doesn't change, only the guarantee behind
        // it does. Without this, two requests can both read "this
        // room/faculty is free" before either has saved, and both
        // pass validate() — see the concurrency hardening spec.
        // Populated inside the transaction below only when the
        // AUTO-RAISE CEILING block actually raises max_teaching_units
        // — passed back to the caller so the frontend can patch its
        // own local copy of this Faculty's max_teaching_units/
        // current_load (e.g. Room Grid's activeFaculty) in place,
        // exactly like the "Add Units" shortcut already does, instead
        // of showing a stale ceiling until the next full page load.
        $raisedFacultyLoad = null;

        try {
            $subject = DB::transaction(function () use (
                $subject, $facultyId, $roomId, $dayTokens, $startTime, $endTime,
                $days, $capacity, $request, $validated, $workloadWarning, $mergeTarget,
                &$raisedFacultyLoad,
            ) {
                $lockedSection = $this->conflictService->lockResources($roomId, $facultyId, $subject->section_id);

                // CONCURRENCY HARDENING — Optimistic Concurrency
                // Control (spec Section 3/4/23). Re-read under the
                // same lock as the write below: if another request
                // already saved a change to this Section's schedule
                // since the caller's `expected_schedule_version` was
                // loaded, abort with no write rather than silently
                // overwriting it. Throwing here rolls the whole
                // transaction back before any conflict check or
                // update runs.
                $this->conflictService->checkSectionVersion(
                    $lockedSection,
                    $request->filled('expected_schedule_version') ? (int) $request->input('expected_schedule_version') : null
                );

                // Section / Faculty / Room / Time-overlap conflict checks all
                // live in ScheduleConflictService — never duplicated here — so
                // the manual workspace and the future Genetic Algorithm scheduler
                // both validate against the exact same rules.
                $conflictErrors = $this->conflictService->validate(
                    [
                        'section_id' => $subject->section_id,
                        'faculty_id' => $facultyId,
                        'room_id' => $roomId,
                        'days' => $dayTokens,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ],
                    // Merge-aware — a merged row sharing its host's exact
                    // Faculty/Room/Time is by design, never a conflict to
                    // flag against itself. See ScheduleConflictService::
                    // mergeExclusionIds(). SHARED CLASS (reverse merge):
                    // also exclude the confirmed merge target itself —
                    // this row is intentionally taking its exact same
                    // slot, re-evaluated above via evaluateReversePlacement()
                    // rather than a plain conflict.
                    array_values(array_unique([
                        ...$this->conflictService->mergeExclusionIds($subject),
                        ...($mergeTarget ? [$mergeTarget->id] : []),
                    ]))
                );

                if (! empty($conflictErrors)) {
                    throw new ScheduleConflictAbort($conflictErrors);
                }

                // Practicum/OJT subjects have no Room, Days, or Time — the
                // row is complete the moment it exists (Faculty here is a
                // Coordinator/Adviser, and is optional per
                // resolvePracticum()'s docblock), so it's never held at
                // Draft waiting on fields that will never be filled.
                //
                // SPLIT-DELIVERY SCHEDULING — an 'online' row DOES still
                // need Days/Start/End (it meets at a real time, just with
                // no physical Room), so it's only exempt from the Room
                // requirement, not the whole schedule — unlike Practicum.
                $status = 'Draft';
                if ($subject->subject->isPracticum()) {
                    $status = 'Scheduled';
                } elseif ($subject->delivery_mode === 'online') {
                    if ($facultyId && ! empty($dayTokens) && $startTime && $endTime) {
                        $status = 'Scheduled';
                    }
                } elseif ($facultyId && $roomId && ! empty($dayTokens) && $startTime && $endTime) {
                    $status = 'Scheduled';
                }

                // Manual Override flag — a Room outside this Section's own
                // Department/College scope tier (same "program"/"college"/
                // "shared" scope resolveRoomScopeTier() in RecommendationService
                // uses) is still allowed per spec ("do not block the move
                // simply because the room belongs to another department"), but
                // gets flagged so Reports/Room Grid can visually call it out,
                // same convention overrideRoom() already uses for the Subjects
                // tab's Room picker. Only re-evaluated when room_id actually
                // changed in this request — an unrelated Day/Time-only move
                // must never silently clear an override flag set earlier.
                $roomIsManualOverride = $subject->room_is_manual_override;
                if (array_key_exists('room_id', $validated) && $roomId) {
                    $subject->loadMissing('section.major.department');
                    $newRoom = Room::find($roomId);
                    $sectionDepartmentId = $subject->section?->major?->department_id;
                    $sectionCollegeId = $subject->section?->major?->department?->college_id;
                    $roomIsManualOverride = (bool) $newRoom
                        && $newRoom->department_id !== $sectionDepartmentId
                        && $newRoom->college_id !== $sectionCollegeId
                        && ($newRoom->department_id !== null || $newRoom->college_id !== null);
                }

                $subject->update([
                    'faculty_id' => $facultyId,
                    'room_id' => ($subject->subject->isPracticum() || $subject->delivery_mode === 'online') ? null : $roomId,
                    'days' => $subject->subject->isPracticum() ? null : ($days ?: null),
                    'start_time' => $subject->subject->isPracticum() ? null : $startTime,
                    'end_time' => $subject->subject->isPracticum() ? null : $endTime,
                    'capacity' => $capacity,
                    'status' => $status,
                    'room_is_manual_override' => $roomIsManualOverride,
                    // Persist the Registrar's confirmation so an acknowledged
                    // Capacity/Hours warning stays acknowledged (doesn't turn
                    // yellow again) the next time this page loads — see the
                    // 2026_08_13_120000 migration's docblock. Both simply
                    // mirror whatever was just confirmed/re-validated above;
                    // if this save didn't need a confirmation (no mismatch),
                    // the boolean falls back to false, which is correct too.
                    'capacity_confirmed' => $request->boolean('capacity_confirmed'),
                    'hours_confirmed' => $request->boolean('hours_confirmed'),
                    // Room Type Mismatch / Room College Mismatch — same
                    // persisted-confirmation pattern as capacity_confirmed/
                    // hours_confirmed above (previously read off the
                    // request for validation only, then discarded, which
                    // is why "Save Anyway" on these two kept coming back
                    // after every reload — see the 2026_08_24_180000
                    // migration's docblock).
                    'room_type_confirmed' => $request->boolean('room_type_confirmed'),
                    'room_college_confirmed' => $request->boolean('room_college_confirmed'),
                    // Faculty Mismatch — same persisted-confirmation
                    // pattern as room_type_confirmed/room_college_confirmed
                    // above. See the 2026_08_30_090000 migration's
                    // docblock.
                    'faculty_mismatch_confirmed' => $request->boolean('faculty_mismatch_confirmed'),
                    // A hand-edited row is no longer purely "Auto Generated" —
                    // untag it so Clear Generated Schedule / Regenerate never
                    // touch what the Registrar just chose themselves.
                    'is_auto_generated' => false,
                    'auto_generated_meta' => null,
                    'is_workload_override' => (bool) $workloadWarning,
                    'workload_override_by' => $workloadWarning ? $request->user()?->id : null,
                    // Room Grid "Move only <this section>" on a merged block —
                    // this row is being placed at a slot that may no longer
                    // match its former merge partner's, so the merge link is
                    // dropped WITHOUT touching the Faculty/Room/Days/Time this
                    // same update just set (unlike IrregularSectionMergeService::
                    // unmerge(), which wipes the row back to Draft/unscheduled —
                    // wrong here, since the row keeps a real, just-chosen slot).
                    // A no-op for a row that was never merged.
                    ...($request->boolean('clear_merge_link') ? [
                        'is_merged' => false,
                        'merged_into_section_subject_id' => null,
                    ] : []),
                ]);

                // SHARED CLASS (reverse merge) — this row just saved
                // its OWN real Faculty/Room booking above, making it
                // the host of record; now re-point the confirmed
                // target's row onto it as a merged rider, inside this
                // same locked transaction, so the two writes can never
                // land only-one-succeeded.
                if ($mergeTarget) {
                    $this->mergeService->applyReverseMerge($subject, $mergeTarget);
                }

                // AUTO-RAISE CEILING — a "Proceed Anyway" override
                // above (now available to anyone with changeMaxLoad
                // access — Admin, Registrar, Dean, OIC, Assistant Dean,
                // not just Administrator) intentionally only writes the
                // schedule; workload_confirmed is a one-time "yes, I
                // know this exceeds their cap, schedule it anyway"
                // override, NOT a request to raise max_teaching_units
                // (only FacultyLoadRequestController and
                // FacultyController@update may mutate that field —
                // see FacultyLoadRequestController's class docblock —
                // so every change to it leaves an audit trail). Left
                // untouched, though, the Faculty Master page kept
                // showing the faculty over-capacity (e.g. "15 / 12
                // Units") until someone separately remembered to open
                // "Add Units" for them — easy to miss, and the
                // Administrator's override already decided this
                // faculty member should be allowed to carry the extra
                // load. So this raises max_teaching_units to cover the
                // load this save just produced (never lowers it, and
                // never exceeds the same institution-wide cap
                // FacultyLoadRequestController::store() enforces via
                // effectiveCapFor()), and records a self-approved
                // FacultyLoadRequest — same shape store() creates for
                // an Admin/Registrar's own direct submission — so the
                // change is still visible in the Faculty Load Requests
                // audit trail, exactly as if the Administrator had
                // used "Add Units" themselves. Locked (lockForUpdate)
                // under this same transaction since lockResources()
                // above already took this same Faculty row's lock —
                // re-reading it here just gets the freshest values
                // under a lock we're already holding, not a second one.
                if ($workloadWarning) {
                    $lockedFaculty = \App\Models\Faculty::whereKey($workloadWarning['faculty_id'])->lockForUpdate()->first();

                    if ($lockedFaculty) {
                        $cap = FacultyLoadRequest::effectiveCapFor($request->user());
                        $newCeiling = min(max($lockedFaculty->max_teaching_units, (int) $workloadWarning['projected']), $cap);

                        if ($newCeiling > $lockedFaculty->max_teaching_units) {
                            $autoLoadRequest = FacultyLoadRequest::create([
                                'faculty_id' => $lockedFaculty->id,
                                'current_max_teaching_units' => $lockedFaculty->max_teaching_units,
                                'requested_max_teaching_units' => $newCeiling,
                                'current_max_weekly_hours' => $lockedFaculty->max_weekly_hours,
                                'requested_max_weekly_hours' => $lockedFaculty->max_weekly_hours,
                                'reason' => "Auto-raised: {$request->user()->full_name} overrode the Teaching Load Limit warning while scheduling {$workloadWarning['subject_code']}.",
                                'status' => 'Approved',
                                'requested_by' => $request->user()->id,
                                'reviewed_by' => $request->user()->id,
                                'reviewed_at' => now(),
                            ]);

                            $lockedFaculty->update(['max_teaching_units' => $newCeiling]);

                            $this->notifications->facultyLoadUpdatedDirectly($autoLoadRequest, $request->user());

                            // See the $raisedFacultyLoad declaration
                            // above this transaction — current_load is
                            // the same figure the "X units left (Y/Z)"
                            // label everywhere else reads as "Y", so
                            // the frontend can patch its local Faculty
                            // entry with numbers that match exactly.
                            $raisedFacultyLoad = [
                                'id' => $lockedFaculty->id,
                                'max_teaching_units' => $newCeiling,
                                'current_load' => (int) $workloadWarning['projected'],
                            ];
                        }
                    }
                }

                // Only reached after a successful, conflict-free
                // write — the version is never advanced on a rolled
                // back transaction (spec Section 20).
                $this->conflictService->bumpScheduleVersion($lockedSection);

                return $subject;
            });
        } catch (ScheduleConflictAbort $abort) {
            return response()->json(['errors' => $abort->errors], 422);
        } catch (ScheduleVersionConflictException $conflict) {
            // SCHEDULING NOTIFICATION SYSTEM (audit spec Section 4) —
            // a genuine race: another user's save committed between
            // this request loading the row and it trying to write.
            // Admin/Registrar only, deduplicated per Section by the
            // same 5s window every other notification uses, so a
            // burst of retries against the same busy row doesn't fan
            // out into a wall of alerts.
            $this->notifications->concurrencyConflict(
                $subject->section ?? $subject->section()->first(),
                $request->user(),
                "Schedule conflict on {$subject->section?->section_code}: another user saved a change to this section's schedule first."
            );

            return response()->json([
                'message' => 'Schedule has changed since it was loaded. Please refresh the schedule and try again.',
                'code' => 'SCHEDULE_VERSION_CONFLICT',
                'current_version' => $conflict->currentVersion,
            ], 409);
        } catch (SectionFinalizedException $finalized) {
            return response()->json([
                'message' => "This section's schedule is finalized and can no longer be edited.",
                'code' => 'SECTION_FINALIZED',
            ], 423);
        }

        // SCHEDULING NOTIFICATION SYSTEM (spec Section 7) — only
        // reached after the transaction above committed a real,
        // conflict-free write (a rolled-back attempt returns early
        // via one of the catch blocks above and never reaches here,
        // so a failed save never produces a notification — spec
        // Section 17). Diff the before/after snapshot into one
        // notification covering every field that actually changed in
        // this single save, never one notification per field.
        $subject->loadMissing('section', 'faculty', 'room');
        $changes = $this->diffScheduleSnapshot($beforeSnapshot, $subject);
        if (! empty($changes) && $subject->section) {
            $this->notifications->scheduleUpdated($subject->section, $subject, $request->user(), $changes);
        }

        // WORKLOAD OVERRIDE AUDIT — someone with changeMaxLoad access
        // (Admin, Registrar, Dean, OIC, or Assistant Dean) just
        // confirmed past the "exceeds allowable workload" warning
        // above. This is distinct from the AUTO-RAISE CEILING
        // notification a few lines up (which only fires when the
        // ceiling itself actually moved) — this one fires every time
        // the override is used, logged and notified to the full
        // facultyRecipients() audience (Admin/Registrar + this
        // Faculty's own College Dean/OIC + Assistant Dean, actor
        // excluded) so the override itself is never invisible even on
        // the (rare) save where the ceiling didn't need to move.
        if ($workloadWarning) {
            $facultyForOverride = \App\Models\Faculty::find($workloadWarning['faculty_id']);

            if ($facultyForOverride) {
                $facultyName = trim(($facultyForOverride->first_name ?? '').' '.($facultyForOverride->last_name ?? ''));

                $this->activityLog->record(
                    ActivityLogService::SCHEDULE_UPDATED,
                    "{$request->user()->full_name} overrode the teaching load limit warning to schedule {$facultyName} for {$workloadWarning['subject_code']}.",
                    $subject,
                    $request->user(),
                );

                $this->notifications->facultyWorkloadOverridden($facultyForOverride, $subject, $request->user(), $workloadWarning);
            }
        }

        return response()->json([
            'sectionSubject' => $subject->fresh(['subject', 'faculty', 'room']),
            'schedule_version' => $subject->section()->value('schedule_version'),
            'message' => 'Schedule updated.',
            // Only non-null when the AUTO-RAISE CEILING block above
            // actually raised this Faculty's max_teaching_units — lets
            // the frontend patch its own local Faculty snapshot (Room
            // Grid's activeFaculty, the Subjects tab's cached faculty
            // options, etc.) in place, the same way the "Add Units"
            // shortcut's response already does, instead of the
            // ceiling looking stale until the next full page load.
            'updated_faculty_load' => $raisedFacultyLoad,
        ]);
    }

    /**
     * Turns a before/after SectionSubject schedule snapshot into the
     * list of human-readable field changes NotificationService::
     * scheduleUpdated() needs (spec Section 7's Faculty/Room/Time/Day
     * examples). Only fields that actually changed are included.
     *
     * @param  array{faculty_id: int|null, room_id: int|null, days: string|null, start_time: string|null, end_time: string|null}  $before
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private function diffScheduleSnapshot(array $before, SectionSubject $after): array
    {
        $changes = [];

        if ($before['faculty_id'] !== $after->faculty_id) {
            $changes[] = [
                'field' => 'Faculty',
                'old' => $before['faculty_id'] ? Faculty::find($before['faculty_id'])?->full_name : null,
                'new' => $after->faculty?->full_name,
            ];
        }

        if ($before['room_id'] !== $after->room_id) {
            $changes[] = [
                'field' => 'Room',
                'old' => $before['room_id'] ? Room::find($before['room_id'])?->room_name : null,
                'new' => $after->room?->room_name,
            ];
        }

        if ($before['days'] !== $after->days) {
            $changes[] = [
                'field' => 'Day',
                'old' => $before['days'],
                'new' => $after->days,
            ];
        }

        if ($before['start_time'] !== $after->start_time || $before['end_time'] !== $after->end_time) {
            $formatTime = fn (?string $start, ?string $end) => $start && $end
                ? date('g:i A', strtotime($start)).' – '.date('g:i A', strtotime($end))
                : null;

            $changes[] = [
                'field' => 'Time',
                'old' => $formatTime($before['start_time'], $before['end_time']),
                'new' => $formatTime($after->start_time, $after->end_time),
            ];
        }

        return $changes;
    }

    /**
     * Smart Assignment Recommendation Engine (Prompt 8.6).
     *
     * Returns ranked Faculty, Room, and Time suggestions for one
     * scheduling workspace row — never assigns anything itself. All
     * of the actual recommendation logic lives in RecommendationService
     * (reused later by the Genetic Algorithm), never here.
     */
    public function recommend(Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        return response()->json($this->recommendationService->recommend($subject));
    }

    /**
     * Faculty Recommendation Selector — Prompt 8.11.
     *
     * Backs the interactive dropdown on the Auto Generate review
     * panel. With no `search` query param it just returns the
     * recommended pool (identical to what Auto Generate itself
     * picked from); with one, it also returns a global search across
     * every Active faculty member so the Registrar can intentionally
     * pick someone outside the recommended list.
     */
    public function facultyOptions(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $subject->loadMissing(['subject', 'section.major.department']);

        return response()->json($this->recommendationService->facultyOptionsForSelector(
            $subject->subject,
            $subject->section,
            $subject,
            $request->query('search')
        ));
    }

    /**
     * Faculty Recommendation Selector — Manual Override (Prompt 8.11).
     *
     * Applies the Registrar's faculty pick to this row IMMEDIATELY
     * (no need to leave or close the Auto Generate modal) and returns
     * the recomputed Live Score/badge/reasons for it — including a
     * "Manual Override" explanation when the pick falls outside every
     * recommended tier. Room/Days/Time are left exactly as Auto
     * Generate produced them; only Faculty and its scoring metadata
     * change. The row keeps is_auto_generated = true / Status
     * 'Draft' — it's still reviewed by "Accept All & Save" like the
     * rest of the panel, it just now reflects the Registrar's choice.
     */
    public function overrideFaculty(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $validated = $request->validate([
            'faculty_id' => ['required', 'integer', 'exists:faculties,id'],
        ]);

        $subject->loadMissing(['subject', 'section.major.department']);

        $faculty = Faculty::query()->where('status', 'Active')->findOrFail($validated['faculty_id']);

        // HARD CONFLICT CHECK — this endpoint previously wrote
        // faculty_id straight to the row with no validation at all,
        // so picking a faculty member from this dropdown could save a
        // genuine double-booking (same faculty, overlapping Day/Time,
        // different Section) with nothing to stop it. The Subjects
        // tab happens to re-validate live when it renders and shows a
        // red warning, but that was purely cosmetic — the bad row was
        // already persisted, and the Room Grid (which does not
        // re-validate) would render it with no warning at all. Run it
        // through the exact same ScheduleConflictService the manual
        // Save Schedule button and Auto Generate use, against this
        // row's CURRENT Room/Days/Time, before ever touching the DB.
        $dayTokens = array_values(array_filter(explode(',', (string) $subject->days)));

        $conflictErrors = $this->conflictService->validate([
            'section_id' => $subject->section_id,
            'faculty_id' => $faculty->id,
            'room_id' => $subject->room_id,
            'days' => $dayTokens,
            'start_time' => $subject->start_time,
            'end_time' => $subject->end_time,
        ], $this->conflictService->mergeExclusionIds($subject));

        if (! empty($conflictErrors)) {
            return response()->json([
                'message' => $conflictErrors['faculty_id'] ?? reset($conflictErrors),
                'errors' => $conflictErrors,
            ], 422);
        }

        $scored = $this->recommendationService->scoreArbitraryFaculty(
            $faculty,
            $subject->subject,
            $subject->section,
            $subject
        );

        $meta = $subject->auto_generated_meta ?? [];
        $meta['faculty'] = [
            'id' => $scored['id'],
            'name' => $scored['name'],
            'score' => $scored['score'],
            'confidence' => $scored['confidence'],
            'reasons' => $scored['reasons'],
            'tier' => $scored['tier'],
            'badge' => $scored['badge'],
            'manual_override' => $scored['manual_override'],
            'override_reason' => $scored['override_reason'],
            'selected_by_college_match' => $scored['selected_by_college_match'],
        ];

        if (isset($meta['room']['score'], $meta['time']['score'])) {
            $meta['overall_score'] = (int) round(($scored['score'] + $meta['room']['score'] + $meta['time']['score']) / 3);
        }

        try {
            DB::transaction(function () use ($subject, $faculty, $meta) {
                // Re-lock and re-check under the same convention as
                // Save Schedule / Auto Generate (see ScheduleConflictService::
                // lockResources()) so two overlapping override requests
                // in flight at once can't both pass validate() above
                // and both write.
                $this->conflictService->lockResources($subject->room_id, $faculty->id, $subject->section_id);

                $dayTokens = array_values(array_filter(explode(',', (string) $subject->days)));
                $recheck = $this->conflictService->validate([
                    'section_id' => $subject->section_id,
                    'faculty_id' => $faculty->id,
                    'room_id' => $subject->room_id,
                    'days' => $dayTokens,
                    'start_time' => $subject->start_time,
                    'end_time' => $subject->end_time,
                ], $this->conflictService->mergeExclusionIds($subject));

                if (! empty($recheck)) {
                    throw new ScheduleConflictAbort($recheck);
                }

                $subject->update([
                    'faculty_id' => $faculty->id,
                    'auto_generated_meta' => $meta,
                ]);

                // SPLIT-DELIVERY SAFE SYNC — mirrors
                // AutoScheduleService::resyncSplitFacultyPairs(). That
                // resync only runs at the end of a full Auto Generate
                // run, but this endpoint is what actually fires when
                // an Administrator edits the Faculty dropdown on a
                // Face-to-Face row INSIDE the "Auto Schedule Complete"
                // review panel, before ever clicking Accept All &
                // Save — so without this, overriding Face-to-Face's
                // Faculty here silently leaves its Online sibling
                // pointed at whichever Faculty Auto Generate originally
                // picked, splitting one Subject across two different
                // instructors. Face-to-Face stays the authoritative
                // half (same convention as the batch resync), so only
                // a Face-to-Face override ever propagates — an edit
                // made directly on an Online row here never pushes
                // back onto its Face-to-Face sibling.
                if ($subject->delivery_mode === 'face_to_face') {
                    SectionSubject::query()
                        ->where('section_id', $subject->section_id)
                        ->where('subject_id', $subject->subject_id)
                        ->where('delivery_mode', 'online')
                        ->where('faculty_id', '!=', $faculty->id)
                        ->update(['faculty_id' => $faculty->id]);
                }
            });
        } catch (ScheduleConflictAbort $abort) {
            return response()->json([
                'message' => $abort->errors['faculty_id'] ?? reset($abort->errors),
                'errors' => $abort->errors,
            ], 422);
        } catch (SectionFinalizedException $finalized) {
            return response()->json([
                'message' => "This section's schedule is finalized and can no longer be edited.",
                'code' => 'SECTION_FINALIZED',
            ], 423);
        }

        return response()->json([
            'section_subject_id' => $subject->id,
            'faculty' => $scored,
            'overall_score' => $meta['overall_score'] ?? $scored['score'],
        ]);
    }

    /**
     * Room Recommendation Selector — options list (recommended pool +
     * global search, scored via RecommendationService::roomOptionsForSelector()).
     */
    /**
     * Busy Time Ranges — for the row's currently selected Room and/or
     * Faculty, on whichever Days are currently selected on the row
     * (or passed via ?days=), every OTHER SectionSubject's Start/End
     * Time already booked against either resource. Powers the Start
     * Time / End Time dropdowns: rather than only rejecting a
     * conflicting pick after the fact (validate()/overrideTime()),
     * the frontend can grey out every time slot that would overlap
     * one of these ranges before the Registrar even picks it — e.g.
     * Room 306 already booked 1:00 PM-5:00 PM and 5:00 PM-7:00 PM on
     * Sat means those slots simply aren't offered.
     *
     * Reuses sectionTermSectionIds()/mergeExclusionIds() — the
     * exact same scoping ScheduleConflictService::findRoomConflict()/
     * findFacultyConflict() already use (this Section's OWN Academic
     * Year + Semester, not whichever term happens to be globally
     * Active — see ScheduleConflictService's class docblock) — so
     * "busy" here can never disagree with what Save/Apply would
     * actually reject.
     */
    public function busyTimes(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $roomId = $request->query('room_id', $subject->room_id);
        $facultyId = $request->query('faculty_id', $subject->faculty_id);
        $days = array_values(array_filter(explode(',', (string) $request->query('days', $subject->days))));

        if (empty($days) || (! $roomId && ! $facultyId)) {
            return response()->json(['busy' => []]);
        }

        $excluding = $this->conflictService->mergeExclusionIds($subject);

        $rows = SectionSubject::query()
            ->whereIn('section_id', $this->conflictService->sectionTermSectionIds($section))
            ->whereNotIn('id', $excluding)
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where(function ($query) use ($roomId, $facultyId) {
                $query->when($roomId, fn ($q) => $q->orWhere('room_id', $roomId))
                    ->when($facultyId, fn ($q) => $q->orWhere('faculty_id', $facultyId));
            })
            ->get(['id', 'room_id', 'faculty_id', 'days', 'start_time', 'end_time']);

        $busy = [];
        foreach ($rows as $row) {
            $rowDays = array_values(array_filter(explode(',', (string) $row->days)));
            if (empty(array_intersect($rowDays, $days))) {
                continue;
            }

            $busy[] = [
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'resource' => (int) $row->room_id === (int) $roomId ? 'room' : 'faculty',
            ];
        }

        return response()->json(['busy' => $busy]);
    }

    public function roomOptions(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $subject->loadMissing(['subject', 'section.major.department']);

        return response()->json($this->recommendationService->roomOptionsForSelector(
            $subject->subject,
            $subject->section,
            $subject,
            $request->query('search')
        ));
    }

    /**
     * Room Recommendation Selector — Manual Override.
     *
     * Applies the Registrar's room pick to this row IMMEDIATELY (no
     * need to leave or close the Auto Generate modal) and returns the
     * recomputed Live Score/badge/reasons for it — including a
     * "Manual Override" explanation when the pick falls outside the
     * hard-filtered recommended pool (wrong type, too small, occupied,
     * or a different College). Faculty/Days/Time are left exactly as
     * Auto Generate produced them; only Room and its scoring metadata
     * change. The row keeps is_auto_generated = true / Status 'Draft'
     * — it's still reviewed by "Accept All & Save" like the rest of
     * the panel, it just now reflects the Registrar's choice.
     */
    public function overrideRoom(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ]);

        $subject->loadMissing(['subject', 'section.major.department']);

        $room = Room::query()->where('status', 'Active')->findOrFail($validated['room_id']);

        // HARD CONFLICT CHECK — same gap as overrideFaculty() above:
        // this endpoint wrote room_id straight to the row with no
        // validation, so picking a room here could save a genuine
        // double-booking (same room, overlapping Day/Time, different
        // Section) that the Room Grid would then render with no
        // warning at all. See overrideFaculty()'s comment for the
        // full explanation.
        $dayTokens = array_values(array_filter(explode(',', (string) $subject->days)));

        $conflictErrors = $this->conflictService->validate([
            'section_id' => $subject->section_id,
            'faculty_id' => $subject->faculty_id,
            'room_id' => $room->id,
            'days' => $dayTokens,
            'start_time' => $subject->start_time,
            'end_time' => $subject->end_time,
        ], $this->conflictService->mergeExclusionIds($subject));

        if (! empty($conflictErrors)) {
            return response()->json([
                'message' => $conflictErrors['room_id'] ?? reset($conflictErrors),
                'errors' => $conflictErrors,
            ], 422);
        }

        $scored = $this->recommendationService->scoreArbitraryRoom(
            $room,
            $subject->subject,
            $subject->section,
            $subject
        );

        $meta = $subject->auto_generated_meta ?? [];
        $meta['room'] = [
            'id' => $scored['id'],
            'name' => $scored['name'],
            'score' => $scored['score'],
            'confidence' => $scored['confidence'],
            'reasons' => $scored['reasons'],
            'match_tier' => $scored['match_tier'],
            'badge' => $scored['badge'],
            'manual_override' => $scored['manual_override'],
            'override_reason' => $scored['override_reason'],
            'utilization_percent' => $scored['utilization_percent'],
            'scheduled_hours' => $scored['scheduled_hours'] ?? null,
            'max_hours' => $scored['max_hours'] ?? null,
            'status_color' => $scored['status_color'],
            'explanation' => $scored['explanation'],
        ];

        if (isset($meta['faculty']['score'], $meta['time']['score'])) {
            $meta['overall_score'] = (int) round(($meta['faculty']['score'] + $scored['score'] + $meta['time']['score']) / 3);
        }

        try {
            DB::transaction(function () use ($subject, $room, $meta) {
                $this->conflictService->lockResources($room->id, $subject->faculty_id, $subject->section_id);

                $dayTokens = array_values(array_filter(explode(',', (string) $subject->days)));
                $recheck = $this->conflictService->validate([
                    'section_id' => $subject->section_id,
                    'faculty_id' => $subject->faculty_id,
                    'room_id' => $room->id,
                    'days' => $dayTokens,
                    'start_time' => $subject->start_time,
                    'end_time' => $subject->end_time,
                ], $this->conflictService->mergeExclusionIds($subject));

                if (! empty($recheck)) {
                    throw new ScheduleConflictAbort($recheck);
                }

                $subject->update([
                    'room_id' => $room->id,
                    'auto_generated_meta' => $meta,
                    // A changed Room may or may not still fit the
                    // Section's Capacity — re-flag rather than
                    // carrying over a confirmation that applied to
                    // the previous Room.
                    'capacity_confirmed' => false,
                ]);
            });
        } catch (ScheduleConflictAbort $abort) {
            return response()->json([
                'message' => $abort->errors['room_id'] ?? reset($abort->errors),
                'errors' => $abort->errors,
            ], 422);
        } catch (SectionFinalizedException $finalized) {
            return response()->json([
                'message' => "This section's schedule is finalized and can no longer be edited.",
                'code' => 'SECTION_FINALIZED',
            ], 423);
        }

        return response()->json([
            'section_subject_id' => $subject->id,
            'room' => $scored,
            'overall_score' => $meta['overall_score'] ?? $scored['score'],
        ]);
    }

    /**
     * Room Scheduler — Room Selector (spec Sections 2 & 11).
     *
     * Returns a room list for the Room Grid's room picker:
     *   - Recommended: Active rooms belonging to the current Section's
     *     own College/Department (via Major -> Department -> College),
     *     same scoping the Subjects List's per-subject recommender
     *     narrows from — but here it's Section-level, not tied to one
     *     specific Subject's requirements, since the Registrar is
     *     choosing a room to browse before picking what goes in it.
     *   - Search results: when `?search=` is given, ANY Active room
     *     institution-wide, department included as a plain label —
     *     deliberately NOT filtered to the Section's own
     *     College/Department. Per spec Section 2, a room belonging to
     *     another department must remain selectable as a manual
     *     override, so this endpoint never hides it; only the
     *     room_is_manual_override flag set by roomSchedule()'s writer
     *     (a later slice) records that it was an intentional override.
     *
     * Read-only — no RBAC narrowing beyond the controller-wide
     * manageScheduling check on {section} itself (see middleware()):
     * once a Dean/OIC is authorized for a Section at all, browsing
     * (not assigning) any room's basic info is not restricted, matching
     * spec Section 2's "do not prevent this solely because the room
     * belongs to another department."
     */
    public function roomOptionsForGrid(Request $request, Section $section): JsonResponse
    {
        $section->loadMissing('major.department');

        $departmentId = $section->major?->department_id;
        $collegeId = $section->major?->department?->college_id;

        // Recommended = same scope tiers RecommendationService::recommendRooms()
        // treats as eligible (resolveRoomScopeTier()): a Room scoped to this
        // Section's own Department ("program" tier), a Room scoped only to
        // this Section's College with no specific Department ("college"
        // tier — e.g. "Ground Zero" under College of Criminology / All
        // Programs), or a Room with no College/Department at all ("shared"
        // tier — e.g. "All Colleges" rooms). Previously this only matched
        // an exact department_id, so college-scoped rooms silently never
        // appeared here even though the scoring engine elsewhere considers
        // them valid — hence "No department rooms found." for sections
        // whose only local options are college-scoped.
        $recommended = Room::query()
            ->where('status', 'Active')
            ->where(function ($query) use ($departmentId, $collegeId) {
                $query->whereNull('college_id')->whereNull('department_id');

                if ($departmentId) {
                    $query->orWhere('department_id', $departmentId);
                }

                if ($collegeId) {
                    $query->orWhere(function ($q) use ($collegeId) {
                        $q->where('college_id', $collegeId)->whereNull('department_id');
                    });
                }
            })
            ->orderBy('room_name')
            ->get(['id', 'room_code', 'room_name', 'room_type', 'room_category', 'capacity', 'department_id', 'college_id']);

        $search = trim((string) $request->query('search', ''));
        $searchResults = [];

        if ($search !== '') {
            $searchResults = Room::query()
                ->where('status', 'Active')
                ->where(function ($query) use ($search) {
                    $query->where('room_code', 'like', "%{$search}%")
                        ->orWhere('room_name', 'like', "%{$search}%");
                })
                ->with(['department:id,name', 'college:id,name'])
                ->orderBy('room_name')
                ->limit(25)
                ->get(['id', 'room_code', 'room_name', 'room_type', 'room_category', 'capacity', 'department_id', 'college_id'])
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'room_code' => $room->room_code,
                    'room_name' => $room->room_name,
                    'room_type' => $room->room_type,
                    'room_category' => $room->room_category,
                    'capacity' => $room->capacity,
                    'department_name' => $room->department?->name,
                    'college_id' => $room->college_id,
                    'college_name' => $room->college?->name,
                    // Whether this search result is outside the current
                    // Section's own Department — the frontend uses this
                    // to label it "outside recommended rooms" before
                    // the Registrar even selects it, per spec Section 2.
                    'is_outside_department' => $departmentId !== null && $room->department_id !== $departmentId,
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'recommended' => $recommended,
            'search_results' => $searchResults,
        ]);
    }

    /**
     * Room Scheduler — Weekly Room Timetable (spec Sections 3 & 13).
     *
     * The Room Grid's core read: every SectionSubject currently
     * assigned to this Room, ACROSS EVERY SECTION (not just the
     * current one — a Room's weekly timetable is institution-wide
     * occupancy, not per-Section). Every Section is scoped to THIS
     * Section's own Academic Year + Semester
     * (ScheduleConflictService::sectionTermSectionIds() — the same
     * convention findRoomConflict()/Save now use, see that service's
     * class docblock), NOT the single institution-wide "Active
     * Academic Term". This is what makes the Room Grid correct when
     * planning ahead: opening a 2nd-Semester Section's Room Grid shows
     * every OTHER 2nd-Semester Section's bookings for that Room (real
     * conflicts you need to see) and hides 1st-Semester bookings
     * (irrelevant noise, even while 1st Semester is still the globally
     * Active term) — the CURRENT Section's own bookings are included
     * as part of that same term-scoped set (it always shares its own
     * term with itself), so the Grid never disagrees with the Subjects
     * tab for the very Section it's being viewed for.
     *
     * Deliberately reads straight from SectionSubject — no separate
     * "room schedule" table exists or should exist (spec Section 13,
     * Single Source of Truth). Editing a block here, once the write
     * path is built, will update the very same row the Subjects List
     * reads.
     *
     * Practicum/OJT subjects are excluded implicitly: they're never
     * assigned a room_id in the first place (spec Section 12), so a
     * plain `where('room_id', $room->id)` already leaves them out
     * without any extra filtering.
     */
    public function roomSchedule(Request $request, Section $section, Room $room): JsonResponse
    {
        $user = $request->user();

        $assignments = SectionSubject::query()
            ->where('room_id', $room->id)
            ->whereIn('section_id', $this->conflictService->sectionTermSectionIds($section))
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->with([
                // faculty_mismatch (see SectionSubject::getFacultyMismatchAttribute())
                // needs subject.major.department (the Subject's academic
                // home) and faculty.subjects (Teaching Qualifications) —
                // both eager-loaded here alongside the columns the rest
                // of this map() already needed, so computing the flag
                // below never triggers a fresh per-row query.
                // 'category' must be selected here too — is_out_of_category
                // below calls $assignment->subject->isSharedResource(),
                // which reads $this->category. A column-limited eager
                // load that omits it silently returns null, which
                // isSharedCategory() treats as "not shared" — so every
                // Subject (including actual GenEd/Minor ones) looked
                // exactly like a Major subject to an Assistant Dean,
                // locking blocks that should have stayed draggable.
                'subject:id,subject_code,subject_title,major_id,category',
                'subject.major:id,department_id',
                'subject.major.department:id,college_id',
                'faculty:id,first_name,last_name,college_id',
                'faculty.subjects:id',
                // is_finalized must be selected here — the map() below
                // reads $assignment->section->is_finalized to drive the
                // Room Grid's locked/padlock rendering. A column-limited
                // eager load that omits it silently returns null (cast to
                // false), so the block would never show as locked even on
                // a genuinely finalized Section.
                'section:id,section_code,section_name,section_type,major_id,is_finalized',
                'section.major:id,name,code,department_id',
                'section.major.department:id,name,college_id',
                // PERFORMANCE FIX — every row's authorization check below
                // (Gate::allows('moveScheduleAssignment', ...) ->
                // SectionPolicy::canAccess() -> Major::college() ->
                // $this->department?->college) reads the Department's
                // College. Without eager-loading it here, that single
                // property access lazy-loads a fresh `colleges` query for
                // EVERY assignment row in this room — an N+1 that's the
                // actual cause of Room Grid taking noticeably longer to
                // load for busy rooms (more scheduled subjects = more
                // queries = more time). Eager-loading it once up front
                // turns that into a single extra query no matter how many
                // rows this room has.
                'section.major.department.college:id',
            ])
            ->get()
            ->map(function (SectionSubject $assignment) use ($section, $user) {
                // EDIT authorization = SCHEDULING SCOPE, not "is this
                // the currently selected Section". A user with
                // scheduling authority over the assignment's OWN
                // Section (their authorized College/Department) can
                // move it regardless of which Section's Room Grid is
                // currently open — $section (currently selected) is
                // used below only to label is_current_section for the
                // UI/confirmation-modal decision, never for this
                // authorization check. See
                // SectionPolicy::moveScheduleAssignment().
                $canEdit = $user ? Gate::forUser($user)->allows('moveScheduleAssignment', $assignment->section) : false;

                // CATEGORY SCOPE — SectionPolicy::canAccess() grants an
                // Assistant Dean section-level reach identical to
                // Admin/Registrar's (they need to see/schedule GenEd/
                // Minor subjects across every College), but their WRITE
                // authority never extends to Major subjects — that's
                // the Dean/OIC's territory (spec §8, AccessScope's
                // ASSISTANT_DEAN_ROLE docblock). A Major subject's block
                // is therefore never draggable/editable for them, even
                // inside their own "current" section. This is the
                // manual-drag counterpart to Auto Generate's
                // unscheduledRows() category filter — same rule,
                // enforced on both the automated and manual write paths.
                $outOfCategory = AccessScope::isAssistantDean($user)
                    && $assignment->subject
                    && ! $assignment->subject->isSharedResource();

                if ($outOfCategory) {
                    $canEdit = false;
                }

                return [
                    'section_subject_id' => $assignment->id,
                    'subject_code' => $assignment->subject?->subject_code,
                    'subject_title' => $assignment->subject?->subject_title,
                    'edp_code' => $assignment->edp_code,
                    'section_id' => $assignment->section_id,
                    'section_code' => $assignment->section?->section_code,
                    'section_name' => $assignment->section?->section_name,
                    'section_type' => $assignment->section?->section_type,
                    'is_current_section' => $assignment->section_id === $section->id,
                    // Whether the logged-in user is authorized to move/edit
                    // THIS assignment, regardless of which Section's Room
                    // Grid is currently open. Frontend uses this — never
                    // is_current_section alone — to decide draggability.
                    // Also false whenever the assignment's own Section is
                    // finalized (see is_finalized below) — a finalized
                    // Section's blocks are never draggable no matter how
                    // wide the user's scheduling scope is.
                    'can_edit' => $canEdit && ! $assignment->section?->is_finalized,
                    // True only for an Assistant Dean looking at a Major
                    // subject's block — lets the Room Grid render a 5th
                    // visual state distinct from "locked" (out-of-college)
                    // and "finalized", so they can tell "this is the
                    // Dean's subject" apart from "this isn't my college"
                    // or "this is done." See RoomGrid.vue's
                    // blockAuthState().
                    'is_out_of_category' => $outOfCategory,
                    // SECTION-LEVEL SCHEDULE FINALIZATION — lets the Room
                    // Grid render a distinct 4th visual state (locked
                    // padlock, amber) for a finalized Section's blocks,
                    // separate from "outside your scope" locked blocks.
                    'is_finalized' => (bool) $assignment->section?->is_finalized,
                    'faculty_id' => $assignment->faculty_id,
                    'faculty_name' => $assignment->faculty
                        ? trim("{$assignment->faculty->first_name} {$assignment->faculty->last_name}")
                        : null,
                    // FACULTY MISMATCH — see SectionSubject::getFacultyMismatchAttribute().
                    // Advisory only (never blocks the drag/drop write
                    // path below); the Room Grid renders it as a badge
                    // on the block so a manually-assigned faculty who
                    // isn't Teaching-Qualification-linked or from the
                    // Subject's own academic home (e.g. a CCS faculty
                    // placed on a BSED Minor subject) stays visible
                    // after the fact, not just at selection time.
                    'faculty_mismatch' => (bool) $assignment->faculty_mismatch && ! $assignment->faculty_mismatch_confirmed,
                    'days' => $assignment->days,
                    'start_time' => $assignment->start_time,
                    'end_time' => $assignment->end_time,
                    'status' => $assignment->status,
                    'is_auto_generated' => $assignment->is_auto_generated,
                    'is_manually_modified' => $assignment->is_manually_modified,
                    'room_is_manual_override' => $assignment->room_is_manual_override,
                    'is_merged' => $assignment->is_merged,
                ];
            })
            ->values();

        return response()->json([
            'room' => [
                'id' => $room->id,
                'room_code' => $room->room_code,
                'room_name' => $room->room_name,
                'room_type' => $room->room_type,
                'room_category' => $room->room_category,
                'capacity' => $room->capacity,
            ],
            'assignments' => $assignments,
        ]);
    }

    /**
     * Time Recommendation Selector — Manual Override.
     *
     * Applies the Registrar's Days/Start/End pick to this row
     * IMMEDIATELY (no need to leave or close the Auto Generate modal)
     * and returns the recomputed Live Score/reasons for it — same
     * "click to edit, apply instantly" flow Faculty/Room already have.
     * A conflicting or off-pattern (e.g. 3x/week when the subject
     * expects 2x/week) time is never silently rejected here — it's
     * still applied and flagged as a Manual Override, exactly like an
     * out-of-pool Faculty/Room pick, so the Registrar stays in
     * control and can see why it's flagged before deciding to keep it.
     * Faculty/Room are left exactly as Auto Generate produced them;
     * only Days/Start/End and the Time scoring metadata change.
     */
    /**
     * "H:i" (24-hour) -> "g:i A" (e.g. "08:00" -> "8:00 AM"), for the
     * Scheduling Window message in overrideTime().
     */
    /**
     * Minutes between two "H:i" times — shared by the Weekly Hours
     * Mismatch check in updateSchedule() and batchUpdateSchedule().
     */
    private function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }

    private function formatWindowTime(string $time): string
    {
        return \Carbon\Carbon::createFromFormat('H:i', $time)->format('g:i A');
    }

    public function overrideTime(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $schoolYear = SchoolYear::active();

        $validated = $request->validate([
            'days' => ['required', 'array', 'min:1', 'max:3'],
            'days.*' => ['required', 'string', 'in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        // Hard institutional boundary — unlike a Faculty/Room/Section
        // conflict (flagged as a Manual Override and still saved), a
        // time outside the Active School Year's Class Start/End Time
        // is never savable at all. This mirrors the same window the
        // scheduling engine's own candidateStartTimes() is built from,
        // so a manual pick can never end up somewhere Auto Generate
        // itself would never have offered.
        if ($schoolYear) {
            $windowStart = $schoolYear->classStartTime();
            $windowEnd = $schoolYear->classEndTime();

            if ($validated['start_time'] < $windowStart || $validated['end_time'] > $windowEnd) {
                throw ValidationException::withMessages([
                    'start_time' => "Class time must fall within this School Year's Scheduling Window ({$this->formatWindowTime($windowStart)} – {$this->formatWindowTime($windowEnd)}).",
                ]);
            }
        }

        $subject->loadMissing(['subject', 'section.major.department']);

        $scored = $this->recommendationService->scoreArbitraryTime(
            $validated['days'],
            $validated['start_time'],
            $validated['end_time'],
            $subject->subject,
            $subject->section,
            $subject->faculty_id,
            $subject->room_id,
            $subject
        );

        $meta = $subject->auto_generated_meta ?? [];
        $meta['time'] = [
            'days' => $scored['days'],
            'start_time' => $scored['start_time'],
            'end_time' => $scored['end_time'],
            'score' => $scored['score'],
            'confidence' => $scored['confidence'],
            'reasons' => $scored['reasons'],
            'manual_override' => $scored['manual_override'],
            'override_reason' => $scored['override_reason'],
            // See scoreArbitraryTime()'s docblock — persisted here too
            // so a genuine Faculty/Room/Section double-booking is
            // still recognized as blocking after a page reload, not
            // just in the moment Apply is clicked.
            'hard_conflict' => $scored['hard_conflict'],
            // Which existing Section/Subject the conflict(s) above
            // belong to — lets the UI name exactly what's already
            // occupying the slot instead of a generic "already booked".
            'conflict_details' => $scored['conflict_details'] ?? [],
        ];

        if (isset($meta['faculty']['score'], $meta['room']['score'])) {
            $meta['overall_score'] = (int) round(($meta['faculty']['score'] + $meta['room']['score'] + $scored['score']) / 3);
        }

        $subject->update([
            'days' => implode(',', $scored['days']),
            'start_time' => $scored['start_time'],
            'end_time' => $scored['end_time'],
            'auto_generated_meta' => $meta,
            // A changed Day/Time may or may not still add up to the
            // Subject's required weekly hours — re-flag it rather than
            // silently carrying over a confirmation that applied to
            // the previous Day/Time.
            'hours_confirmed' => false,
        ]);

        return response()->json([
            'section_subject_id' => $subject->id,
            'time' => $scored,
            'overall_score' => $meta['overall_score'] ?? $scored['score'],
        ]);
    }

    /**
     * Smart Day & Time Recommendation modal — "Find Recommended Day & Time".
     *
     * Backs the TimeRecommendationSelector's recovery flow: when the
     * Registrar's manually-picked Day/Time fails validation (lunch
     * break overlap, Faculty/Room/Section conflict, etc.), this
     * returns a ranked list of ACTUALLY SCHEDULABLE alternatives
     * instead of leaving the Registrar to guess-and-check.
     *
     * Deliberately calls RecommendationService::recommendTimes() —
     * the exact same candidate-generation/scoring/ranking engine
     * Auto Generate itself uses (Faculty/Room/Section conflict
     * checks via ScheduleConflictService, lunch break via
     * SchoolYear::overlapsLunchBreak(), meeting-count/day-pattern
     * rules via MeetingPatternService, Section Daily Load
     * Optimization scoring) — never a separate, simplified
     * recommendation algorithm that Auto Generate itself would
     * reject. Faculty/Room stay exactly as already assigned on this
     * row; only Days/Start/End are searched.
     *
     * Optional `preferred_days` query param (comma-separated Day
     * tokens, e.g. "Sat") is presentation-only — it never changes
     * which candidates are valid or how they're scored, it just lets
     * the frontend split the ranked list into "<Day> alternatives"
     * vs "Other recommended days" the way the spec's modal groups
     * them (see item 9, "Support Keep Current Day").
     */
    public function timeRecommendations(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $subject->loadMissing(['subject', 'section.major.department']);

        $preferredDays = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('preferred_days', ''))
        )));

        // Days to never re-offer as a "new" single-day option — the
        // row's OTHER already-selected meetings (passed by the
        // frontend as whatever the editor currently holds, minus the
        // one being replaced), so a Tue/Thu subject being fixed on
        // Thursday never gets handed "Tuesday" back as if it were new.
        $excludeDays = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('exclude_days', ''))
        )));

        // Duration of the specific occurrence being replaced (the
        // Registrar's current Start/End in the editor), so single-day
        // alternatives keep the same session length instead of
        // silently changing it. Falls back to the Subject's usual
        // per-meeting length inside recommendSingleDaySlots() when omitted.
        $sessionMinutes = $request->query('session_minutes');
        $sessionMinutes = is_numeric($sessionMinutes) ? (int) $sessionMinutes : null;

        $multiDay = $this->recommendationService->recommendTimes(
            $subject->subject,
            $subject->section,
            $subject->faculty_id,
            $subject->room_id,
            $subject
        );

        // SINGLE-DAY SLOTS ARE A "FIX ONE OCCURRENCE" TOOL, NOT A
        // "SCHEDULE FROM SCRATCH" TOOL — recommendSingleDaySlots()
        // returns fragments sized to ONE meeting of the Subject's
        // pattern (e.g. a single 1h slot for a Subject that meets
        // twice a week for 1h each). That's exactly right when the
        // Registrar is replacing one already-scheduled occurrence
        // (the row has other Days already set, passed here via
        // exclude_days), but wrong for a still-fully-unscheduled row:
        // merging those fragments in let a lone 1h "Fri" suggestion
        // outscore and outrank the full 2-meeting pattern, and
        // clicking it silently left the Subject scheduled for only
        // half its required weekly hours. Only search single-day
        // slots once this row already has at least one real meeting
        // to patch around.
        $singleDay = ['recommendations' => [], 'message' => null];

        if (! empty($excludeDays)) {
            $singleDay = $this->recommendationService->recommendSingleDaySlots(
                $subject->subject,
                $subject->section,
                $subject->faculty_id,
                $subject->room_id,
                $subject,
                $sessionMinutes,
                $excludeDays
            );
        }

        // SHARED ONLINE SESSION SUGGESTIONS — kept as a separate list
        // from $recommendations rather than merged/scored alongside
        // them: this isn't "a good conflict-free time slot", it's "an
        // entirely different action" (ride along on another Section's
        // already-scheduled class instead of booking a new one), and
        // it only ever makes sense for a still-unscheduled Online row
        // (room_id null) — never for a row being fixed in place with
        // a Room already assigned.
        $mergeSuggestions = $subject->room_id === null
            ? $this->recommendationService->findOnlineMergeCandidates($subject)
            : [];

        $recommendations = array_merge($multiDay['recommendations'], $singleDay['recommendations']);

        foreach ($recommendations as &$candidate) {
            $candidate['matches_preferred_day'] = ! empty($preferredDays)
                && empty(array_diff($candidate['days'], $preferredDays))
                && empty(array_diff($preferredDays, $candidate['days']));
        }
        unset($candidate);

        // Best-first within each group: preferred-day matches surface
        // first (still ranked by score among themselves), everything
        // else follows — the ranking itself is untouched. Single-day
        // and multi-day candidates are merged into one ranked pool
        // rather than kept as separate lists, since they're scored by
        // the exact same buildTimeCandidate() logic and are directly
        // comparable.
        usort($recommendations, function (array $a, array $b) {
            return (int) $b['matches_preferred_day'] <=> (int) $a['matches_preferred_day']
                ?: $b['score'] <=> $a['score'];
        });

        $message = $multiDay['message'] ?? $singleDay['message'];

        return response()->json([
            'recommendations' => $recommendations,
            'message' => empty($recommendations) ? $message : null,
            'merge_suggestions' => $mergeSuggestions,
            'preferred_days' => $preferredDays,
            'current' => [
                'days' => array_values(array_filter(explode(',', (string) $subject->days))),
                'start_time' => $subject->start_time,
                'end_time' => $subject->end_time,
            ],
        ]);
    }

    /**
     * INTELLIGENT IRREGULAR SECTION SCHEDULING — "Merge Recommendation"
     * modal data. Always runs IrregularSectionMergeService::recommend()
     * fresh (never just returns the row's stored merge_recommendation
     * snapshot from the last Auto Generate) so the candidate list, and
     * every candidate's compatibility, reflects the section's CURRENT
     * data — another class's schedule may have changed since this row
     * was last generated.
     */
    public function mergeRecommendation(Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);
        abort_unless($section->isIrregular(), 422, 'Merge recommendations only apply to Irregular sections.');

        $subject->loadMissing('subject');

        return response()->json($this->mergeService->recommend($subject));
    }

    /**
     * SHARED CLASS — REVERSE DIRECTION compatibility check. Used
     * before $subject has been saved at all: the Registrar just
     * dropped/configured $subject at a slot ($room_id/$faculty_id/
     * $days/$start_time/$end_time) that an existing, DIFFERENT
     * section's class ($target_section_subject_id) already occupies.
     * Read-only — see IrregularSectionMergeService::
     * evaluateReversePlacement(). The actual write (and a second,
     * authoritative re-check) happens via updateSchedule() with
     * merge_target_section_subject_id set, once the Registrar
     * confirms the "Combine Sections?" dialog this powers.
     */
    public function reverseMergeRecommendation(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $validated = $request->validate([
            'target_section_subject_id' => ['required', 'integer', 'exists:section_subjects,id'],
            'room_id' => ['required', 'integer'],
            'faculty_id' => ['nullable', 'integer'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string'],
            'start_time' => ['required'],
            'end_time' => ['required'],
        ]);

        $target = SectionSubject::findOrFail($validated['target_section_subject_id']);

        return response()->json($this->mergeService->evaluateReversePlacement($subject, [
            'room_id' => $validated['room_id'],
            'faculty_id' => $validated['faculty_id'] ?? null,
            'days' => $validated['days'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ], $target));
    }

    /**
     * "Merge Into This Section" — the Administrator picks one of the
     * candidates IrregularSectionMergeService::recommend() offered.
     * Re-runs recommend() server-side (never trusts the candidate the
     * client already had in memory) and only proceeds if that exact
     * target is still a COMPATIBLE candidate — closes the same race a
     * stale modal could otherwise slip through (e.g. the host class's
     * room capacity filled up from another merge in between).
     */
    public function applyMerge(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);
        abort_unless($section->isIrregular(), 422, 'Merge recommendations only apply to Irregular sections.');

        $subject->loadMissing('subject');

        $validated = $request->validate([
            'target_section_subject_id' => ['required', 'integer', 'exists:section_subjects,id'],
        ]);

        $outcome = $this->mergeService->recommend($subject);
        $candidate = collect($outcome['candidates'])
            ->firstWhere('section_subject_id', (int) $validated['target_section_subject_id']);

        if (! $candidate) {
            return response()->json([
                'message' => 'That class is no longer a recognized merge candidate for this subject. Please refresh and try again.',
            ], 422);
        }

        if (! $candidate['compatible']) {
            return response()->json([
                'message' => $candidate['blocking_reason'] ?? 'That class is no longer a compatible merge target.',
            ], 422);
        }

        $host = SectionSubject::find($candidate['section_subject_id']);
        abort_unless($host, 404);
        $host->loadMissing('section');

        $this->mergeService->applyMerge($subject, $host, $outcome);

        return response()->json([
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])->get(),
            'message' => "Merged {$subject->subject->subject_code} into {$host->section->section_code}.",
        ]);
    }

    /**
     * "Create Independent Schedule Instead" — the Administrator
     * declines every merge candidate offered and asks Classly to give
     * this subject its own independent Faculty/Room/Time instead. Runs
     * the same search AutoScheduleService's Auto Generate uses, just
     * with the merge evaluation itself skipped since that decision has
     * already been made here.
     */
    public function scheduleIndependently(Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);
        abort_unless($section->isIrregular(), 422, 'This action only applies to Irregular sections.');

        $subject->loadMissing('subject');

        $outcome = $this->autoScheduleService->scheduleIndependently($section, $subject);

        return response()->json([
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])->get(),
            'message' => $outcome['success']
                ? "{$subject->subject->subject_code} was scheduled independently."
                : ($outcome['result']['reason'] ?? 'Could not find a conflict-free independent schedule for this subject.'),
        ], $outcome['success'] ? 200 : 422);
    }

    /**
     * ⚡ Auto Generate Schedule (Prompt 8.9).
     *
     * Runs AutoScheduleService for every currently unscheduled subject
     * in this Section — Faculty, Room, and Time are chosen and WRITTEN
     * immediately (Status stays 'Draft', is_auto_generated = true) so
     * later subjects in the same run correctly see earlier ones as
     * conflicts via the same ScheduleConflictService the manual
     * workspace uses. Nothing here ever touches a row that already has
     * a Faculty/Room/Days/Time — those are left completely alone.
     *
     * The Registrar still reviews the result and must click
     * "Save Schedule" to finalize it (or "Clear Generated Schedule" /
     * "Regenerate" to discard/retry) — see updateSchedule() and
     * batchUpdateSchedule(), which strip the is_auto_generated flag the
     * moment a row is actually saved by hand.
     */
    public function autoGenerate(Request $request, Section $section): JsonResponse
    {
        // CONCURRENCY HARDENING (spec Section 15) — optional
        // `expected_schedule_version`: the Section's schedule_version
        // the frontend had loaded right before clicking "Auto
        // Generate". Rejected with 409 before any row is touched if
        // stale — see AutoScheduleService::generate()'s docblock.
        try {
            $summary = $this->autoScheduleService->generate(
                $section,
                $request->filled('expected_schedule_version') ? (int) $request->input('expected_schedule_version') : null,
                $request->user()
            );
        } catch (ScheduleVersionConflictException $conflict) {
            return response()->json([
                'message' => 'Schedule has changed since it was loaded. Please refresh the schedule and try again.',
                'code' => 'SCHEDULE_VERSION_CONFLICT',
                'current_version' => $conflict->currentVersion,
            ], 409);
        } catch (SectionFinalizedException $finalized) {
            return response()->json([
                'message' => "This section's schedule is finalized — Auto Generate can't run against it.",
                'code' => 'SECTION_FINALIZED',
            ], 423);
        }

        // SCHEDULING NOTIFICATION SYSTEM (audit spec Section 5) — one
        // notification for the whole run, COMPLETED or NEEDS_ATTENTION
        // depending on whether every subject was placed. Deliberately
        // outside AutoScheduleService::generate() itself (that method
        // runs several short-lived transactions internally, one per
        // placement, rather than one long one — see its docblock), so
        // this fires once the whole run (not any single placement) is
        // known to have finished.
        $this->notifications->autoScheduleFinished(
            $section,
            $request->user(),
            (int) $summary['scheduled'],
            (int) $summary['total'],
            $summary['unresolved'] ?? []
        );

        return response()->json([
            ...$summary,
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])->get(),
            // Every result row this run wrote carries this as its
            // `generated_from_version` — the frontend should send it
            // back as `expected_schedule_version` on the eventual
            // "Save Schedule" / batchUpdateSchedule() submit that
            // finalizes the review panel (spec Section 3/12).
            'schedule_version' => $section->fresh()->schedule_version,
        ]);
    }

    /**
     * "Regenerate" — discards every previously Auto Generated row
     * (never manually-assigned ones) and runs Auto Generate again from
     * a clean slate.
     */
    public function regenerateSchedule(Request $request, Section $section): JsonResponse
    {
        $summary = $this->autoScheduleService->regenerate($section, $request->user());

        // SCHEDULING NOTIFICATION SYSTEM (audit spec Section 5) — same
        // reasoning as autoGenerate() above. A regenerate that
        // replaces an existing (possibly previously-saved) schedule
        // is exactly the "Auto schedule overwritten/replaced" case
        // the spec calls out as worth treating as an important event
        // in its own right — covered here by the same COMPLETED/
        // NEEDS_ATTENTION notification, since the recipient cares
        // about the resulting state either way, not the mechanism.
        $this->notifications->autoScheduleFinished(
            $section,
            $request->user(),
            (int) $summary['scheduled'],
            (int) $summary['total'],
            $summary['unresolved'] ?? []
        );

        return response()->json([
            ...$summary,
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])->get(),
            'schedule_version' => $section->fresh()->schedule_version,
        ]);
    }

    /**
     * "Clear Generated Schedule" — reverts every Auto Generated row
     * back to an empty schedule slot. Manually assigned rows are never
     * touched.
     */
    public function clearAutoGenerated(Section $section): JsonResponse
    {
        $cleared = $this->autoScheduleService->clear($section);

        return response()->json([
            'cleared' => $cleared,
            'message' => $cleared > 0
                ? "{$cleared} auto-generated ".($cleared === 1 ? 'schedule was' : 'schedules were')." cleared."
                : 'No auto-generated schedules to clear.',
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])->get(),
            'schedule_version' => $section->fresh()->schedule_version,
        ]);
    }

    /**
     * "Clear Schedule" — wipes Faculty/Room/Days/Start/End Time back to
     * empty for every subject in this Section (status back to 'Draft'),
     * so the Registrar can start scheduling over from a blank slate.
     *
     * Distinct from clearAutoGenerated() above, which only removes
     * *pending, unsaved* Auto Generate suggestions — this clears
     * everything, including rows that were already Saved/Scheduled.
     * Only ever allowed while the Section is NOT finalized; the
     * frontend also gates the button on this, but the real guard is
     * here, under the same row lock ScheduleConflictService uses
     * everywhere else, so a finalize that races this request can never
     * be silently wiped.
     */
    public function clearSchedule(Section $section): JsonResponse
    {
        DB::beginTransaction();

        try {
            $lockedSection = Section::whereKey($section->id)->lockForUpdate()->first();

            if ($lockedSection->is_finalized) {
                DB::rollBack();

                return response()->json([
                    'message' => "This section's schedule is finalized and can no longer be cleared.",
                    'code' => 'SECTION_FINALIZED',
                ], 423);
            }

            // Practicum/OJT rows have no Faculty/Room/Days/Time to begin
            // with (see isPracticum() exemption elsewhere) — leave them
            // alone rather than "clearing" fields that were never set.
            // PERFORMANCE — this used to load every row into memory and
            // ->update() them one at a time (N sequential UPDATE queries
            // + Eloquent model events, all inside the same transaction),
            // which is what actually made "Clear Schedule" feel slow on
            // sections with a lot of subjects. Practicum and merged rows
            // are excluded via the WHERE clause itself instead of a PHP
            // loop, so the whole clear is now a single bulk UPDATE.
            $practicumSubjectIds = Subject::query()
                ->where('subject_type', 'practicum')
                ->pluck('id');

            $cleared = $lockedSection->sectionSubjects()
                ->whereNull('merged_into_section_subject_id')
                ->when($practicumSubjectIds->isNotEmpty(), fn ($q) => $q->whereNotIn('subject_id', $practicumSubjectIds))
                ->update([
                    'faculty_id' => null,
                    'room_id' => null,
                    'days' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'status' => 'Draft',
                    'capacity_confirmed' => false,
                    'hours_confirmed' => false,
                    // room_id is being wiped above, so any Room Type/
                    // College Mismatch confirmation tied to that room no
                    // longer applies either.
                    'room_type_confirmed' => false,
                    'room_college_confirmed' => false,
                    // faculty_id is being wiped above too, so any
                    // Faculty Mismatch confirmation tied to that
                    // faculty no longer applies either.
                    'faculty_mismatch_confirmed' => false,
                    'is_auto_generated' => false,
                    'auto_generated_meta' => null,
                    'is_workload_override' => false,
                    'workload_override_by' => null,
                ]);

            $this->conflictService->bumpScheduleVersion($lockedSection);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to clear the schedule. Please try again.',
            ], 500);
        }

        return response()->json([
            'cleared' => $cleared,
            'message' => $cleared > 0
                ? "{$cleared} ".($cleared === 1 ? 'subject was' : 'subjects were')." cleared back to blank."
                : 'Nothing to clear.',
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])->get(),
            'schedule_version' => $section->fresh()->schedule_version,
        ]);
    }

    /**
     * "Save Schedule" — the manual scheduling workspace's batch save.
     * The Registrar edits Faculty/Room/Days/Start/End Time across as
     * many subject rows as they like *locally* in the table (no
     * per-cell auto-save), then submits everything at once here.
     *
     * Every row is validated with the same Faculty/Room conflict
     * checks updateSchedule() runs for a single cell — including
     * against other rows in this same batch, since rows are saved
     * one at a time inside the transaction and later rows can see
     * earlier rows' just-saved values on the same connection. A row
     * with a conflict is skipped (never written) but does NOT block
     * the rest of the batch — every other row still saves. Only when
     * every single row in the batch has a conflict does the whole
     * request roll back with nothing saved.
     */
    public function batchUpdateSchedule(
        BatchUpdateSectionSubjectScheduleRequest $request,
        Section $section
    ): \Illuminate\Http\JsonResponse {
        $rows = $request->validated()['rows'];

        // Guard against rows that belong to a different Section
        // slipping in (e.g. a stale tab); exists:section_subjects,id
        // in the FormRequest only checks the row exists at all.
        $rowIds = collect($rows)->pluck('id');
        $validRowIds = $section->sectionSubjects()->whereIn('id', $rowIds)->pluck('id');
        $foreignIds = $rowIds->diff($validRowIds);

        if ($foreignIds->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more rows no longer belong to this section. Please refresh and try again.',
            ], 422);
        }

        $errors = [];
        $workloadWarnings = [];
        $savedIds = [];
        $skippedIds = [];
        // See performScheduleAssignmentUpdate()'s equivalent — one
        // entry per distinct Faculty this batch actually raised the
        // ceiling for (a batch can Override & Save more than one
        // over-capacity row, possibly for the same Faculty more than
        // once), keyed by faculty id so a later row's raise in the
        // same batch simply overwrites an earlier one's rather than
        // sending the frontend two conflicting patches for the same
        // Faculty.
        $raisedFacultyLoads = [];
        // WORKLOAD OVERRIDE AUDIT — one entry per row this batch
        // overrode, collected here (inside the transaction, alongside
        // $raisedFacultyLoads above) and logged/notified only after
        // DB::commit() succeeds — see the block right after the
        // try/catch below, mirroring updateSchedule()'s equivalent.
        $workloadOverrides = [];
        // Same underlying trust decision as updateSchedule()'s equivalent
        // — anyone who can raise this Faculty's load ceiling
        // (FacultyPolicy::changeMaxLoad() — Admin, Registrar, Dean, OIC,
        // Assistant Dean) can also confirm past the warning here.
        $canOverrideWorkload = (bool) $request->user()?->can('changeMaxLoad', Faculty::class);
        $expectedVersion = $request->filled('expected_schedule_version')
            ? (int) $request->input('expected_schedule_version')
            : null;

        DB::beginTransaction();

        try {
            // CONCURRENCY HARDENING — Optimistic Concurrency Control
            // (spec Section 3/4/13/23), "Save Schedule" / "Accept All
            // & Save". Locking the Section row FIRST (before any
            // per-row Room/Faculty lock below) matches the fixed lock
            // order ScheduleConflictService::lockResources() already
            // documents (Room, Faculty, Section) is required
            // everywhere else — re-locking the already-held Section
            // row per row below is a cheap no-op, not a second
            // distinct lock acquisition. One version check covers the
            // whole batch: if the Section changed since this batch's
            // `expected_schedule_version` was captured (another
            // request already committed), abort before touching any
            // row.
            $lockedSection = Section::whereKey($section->id)->lockForUpdate()->first();
            $this->conflictService->checkSectionVersion($lockedSection, $expectedVersion);

            // Resolved once for the whole batch (not per row) — every
            // row in this request belongs to the same Section, so its
            // owning College never changes row to row. Feeds the Room
            // College Mismatch Warning check below.
            $lockedSection->loadMissing('major.department');
            $sectionCollegeId = $lockedSection->major?->department?->college_id;

            foreach ($rows as $rowData) {
                $subject = SectionSubject::query()->where('id', $rowData['id'])->lockForUpdate()->first();
                $subject->loadMissing('subject');

                $facultyId = $rowData['faculty_id'] ?? null;
                $roomId = $rowData['room_id'] ?? null;
                $days = ! empty($rowData['days']) ? implode(',', $rowData['days']) : null;
                $startTime = $rowData['start_time'] ?? null;
                $endTime = $rowData['end_time'] ?? null;
                $capacity = $rowData['capacity'] ?? $subject->capacity;

                $rowErrors = [];

                // CATEGORY SCOPE (Assistant Dean = GenEd/Minor only) —
                // same rule as performScheduleAssignmentUpdate() above,
                // enforced here too since Save Schedule is a separate
                // write path (doesn't route through that shared
                // method) that can otherwise persist a Major subject's
                // Faculty/Room/Time for an Assistant Dean — e.g. rows
                // they edited inline on the Subjects tab before
                // clicking Save, or an Auto Generate result they
                // accepted as-is. Unlike the single-cell endpoint this
                // can't abort(403) the whole request (a batch mixes
                // in-scope and out-of-scope rows), so it's reported as
                // a per-row error and simply not saved instead.
                if (AccessScope::isAssistantDean($request->user()) && $subject->subject && ! $subject->subject->isSharedResource()) {
                    $rowErrors['category'] = "{$subject->subject->subject_code} is a Major subject — outside your Assistant Dean scheduling scope (GenEd/Minor only). The Dean/OIC manages this subject's schedule.";
                    $errors[$subject->id] = $rowErrors;
                    $skippedIds[] = $subject->id;

                    continue;
                }

                // Room Capacity Warning — not a hard block, but the
                // Registrar must explicitly confirm this row before it's
                // allowed to save (see BatchUpdateSectionSubjectScheduleRequest).
                $room = $roomId ? Room::find($roomId) : null;

                if ($room && $capacity && $capacity > $room->capacity && empty($rowData['capacity_confirmed'])) {
                    $rowErrors['capacity'] = "Section Capacity ({$capacity}) exceeds this room's capacity ({$room->capacity}). "
                        .'Confirm to save anyway.';
                }

                // Room Type Mismatch Warning — same rule/gate as
                // performScheduleAssignmentUpdate() above, run here too
                // since a batch save can persist rows that never went
                // through the single-cell endpoint (e.g. Auto Generate
                // results accepted as-is).
                if ($room && $room->room_type && empty($rowData['room_type_confirmed'])) {
                    $wantsLaboratory = (int) $subject->subject->laboratory_hours > 0;
                    $typeMismatch = $wantsLaboratory
                        ? $room->room_type !== 'Laboratory'
                        : $room->room_type === 'Laboratory';

                    if ($typeMismatch) {
                        $rowErrors['room_type'] = "{$subject->subject->subject_code} is a "
                            .($wantsLaboratory ? 'Laboratory' : 'Lecture')
                            ." subject, but {$room->room_name} is a {$room->room_type} room. "
                            .'Confirm to save anyway.';
                    }
                }

                // Room College Mismatch Warning — same rule/gate as
                // performScheduleAssignmentUpdate() above, run here too
                // since a batch save can persist rows that never went
                // through the single-cell endpoint (e.g. Auto Generate
                // results accepted as-is). $sectionCollegeId is
                // resolved once outside this loop, right after the
                // Section is locked, below.
                if (
                    $room
                    && $room->college_id
                    && $subject->subject->category !== 'General Education'
                    && empty($rowData['room_college_confirmed'])
                    && $sectionCollegeId
                    && (int) $room->college_id !== (int) $sectionCollegeId
                ) {
                    $room->loadMissing('college');
                    $roomCollegeName = $room->college?->short_name ?? $room->college?->name ?? 'another college';
                    $rowErrors['room_college'] = "{$subject->subject->subject_code} belongs to this section's own college, but "
                        ."{$room->room_name} belongs to {$roomCollegeName}. Confirm to save anyway.";
                }

                // Faculty Mismatch Warning — same rule/gate as
                // performScheduleAssignmentUpdate() above, run here too
                // since a batch save can persist rows that never went
                // through the single-cell endpoint (e.g. Auto Generate
                // results accepted as-is, or Auto Generate's own
                // "Manual Scheduling" fallback rows the Registrar
                // hand-assigned before Save).
                if ($facultyId && empty($rowData['faculty_mismatch_confirmed'])) {
                    $candidateFaculty = Faculty::find($facultyId);
                    $subject->loadMissing('subject.major.department');
                    if ($candidateFaculty && $subject->subject) {
                        $candidateFaculty->loadMissing('subjects:id');
                        $isQualified = $candidateFaculty->subjects->contains('id', $subject->subject->id);
                        if (! $isQualified) {
                            $candidateFaculty->loadMissing('college');
                            $subjectCollegeId = $subject->subject->major?->department?->college_id;
                            // Mirrors SectionSubject::getFacultyMismatchAttribute()'s
                            // GenEd pool: "no College" OR a College that has
                            // opted in via counts_as_gened.
                            $isHomeMatch = $subjectCollegeId === null
                                ? ($candidateFaculty->college_id === null || (bool) $candidateFaculty->college?->counts_as_gened)
                                : $candidateFaculty->college_id === $subjectCollegeId;

                            if (! $isHomeMatch) {
                                $rowErrors['faculty_mismatch'] = "{$candidateFaculty->full_name} is not on file as qualified or from the academic home for "
                                    ."{$subject->subject->subject_code}. Confirm to save anyway.";
                            }
                        }
                    }
                }

                // Weekly Hours Mismatch Warning — same rule/gate as
                // updateSchedule() above, run here too since a batch
                // save can persist rows that never went through the
                // single-cell endpoint (e.g. Auto Generate results
                // accepted as-is).
                // SPLIT-DELIVERY SCHEDULING — compare against this
                // row's own split_hours share when it has one (see
                // matching comment in updateSchedule() above).
                if (! empty($days) && $startTime && $endTime) {
                    $requiredHours = $subject->split_hours
                        ?? (((int) $subject->subject->lecture_hours) + ((int) $subject->subject->laboratory_hours));
                    if ($requiredHours <= 0) {
                        $requiredHours = 3;
                    }

                    $dayCount = count(array_filter(explode(',', (string) $days)));
                    $actualHours = round(($this->minutesBetween($startTime, $endTime) * $dayCount) / 60, 2);

                    if ($actualHours !== (float) $requiredHours && empty($rowData['hours_confirmed'])) {
                        $rowErrors['hours'] = "This schedule totals {$actualHours} hrs/week, but {$subject->subject->subject_code} requires {$requiredHours} hrs/week. "
                            .'Confirm to save anyway.';
                    }
                }

                $dayTokens = array_filter(explode(',', (string) $days));

                // Lock this row's Room/Faculty too (Section is
                // already locked above) so a concurrent request
                // writing to the SAME Room/Faculty from a different
                // Section — which this row's own lockForUpdate() a
                // few lines up can't catch — is forced to wait until
                // this transaction commits or rolls back before its
                // own conflict check can run. See
                // ScheduleConflictService::lockResources()'s docblock.
                $this->conflictService->lockResources($roomId, $facultyId, null);

                // Same shared ScheduleConflictService the single-cell
                // save uses above — kept identical so batch saves can
                // never disagree with per-cell saves about what counts
                // as a conflict.
                $rowErrors = array_merge($rowErrors, $this->conflictService->validate(
                    [
                        'section_id' => $section->id,
                        'faculty_id' => $facultyId,
                        'room_id' => $roomId,
                        'days' => $dayTokens,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ],
                    // Merge-aware — see ScheduleConflictService::
                    // mergeExclusionIds(): a merged Irregular-section
                    // row intentionally shares its host's exact
                    // Faculty/Room/Time, which must never be reported
                    // as a Faculty/Room/Section conflict against
                    // itself.
                    $this->conflictService->mergeExclusionIds($subject)
                ));

                if (! empty($rowErrors)) {
                    // PARTIAL SAVE — a subject with an overlap/conflict
                    // error is skipped (never written), but every OTHER
                    // subject in the same batch that has no error still
                    // gets saved below. Keyed by SectionSubject id so the
                    // frontend can map each error back to the exact row.
                    $errors[$subject->id] = $rowErrors;
                    $skippedIds[] = $subject->id;

                    continue;
                }

                // SAVE SCHEDULE VALIDATION — Faculty Workload. A final
                // pass for every assigned faculty before this batch
                // commits. Not folded into the hard-conflict $errors
                // list above — anyone with changeMaxLoad access (Admin,
                // Registrar, Dean, OIC, Assistant Dean) may "Override &
                // Save" — so it's tracked separately and only blocks
                // the row when nobody has confirmed it (or the
                // confirming user doesn't hold changeMaxLoad).
                $workloadWarning = $this->workloadWarningFor($facultyId, $subject->subject, $subject);
                $isWorkloadOverride = false;

                if ($workloadWarning) {
                    $confirmed = ! empty($rowData['workload_confirmed']);

                    if (! $canOverrideWorkload || ! $confirmed) {
                        // Same PARTIAL SAVE rule as overlap errors above —
                        // this subject is skipped, everyone else in the
                        // batch still saves.
                        $workloadWarnings[$subject->id] = $workloadWarning;
                        $skippedIds[] = $subject->id;

                        continue;
                    }

                    $isWorkloadOverride = true;
                    $workloadOverrides[] = [
                        'faculty_id' => $workloadWarning['faculty_id'],
                        'subject_code' => $workloadWarning['subject_code'],
                        'section_subject_id' => $subject->id,
                        'projected' => $workloadWarning['projected'] ?? null,
                    ];
                }

                // Same Practicum/OJT exemption as updateSchedule() above
                // — no Room/Days/Time is ever required for these rows.
                // SPLIT-DELIVERY SCHEDULING — an 'online' row still needs
                // Days/Start/End, just never a Room (see updateSchedule()'s
                // matching comment above for why this differs from Practicum).
                $status = 'Draft';
                if ($subject->subject->isPracticum()) {
                    $status = 'Scheduled';
                } elseif ($subject->delivery_mode === 'online') {
                    if ($facultyId && ! empty($dayTokens) && $startTime && $endTime) {
                        $status = 'Scheduled';
                    }
                } elseif ($facultyId && $roomId && ! empty($dayTokens) && $startTime && $endTime) {
                    $status = 'Scheduled';
                }

                $subject->update([
                    'faculty_id' => $facultyId,
                    'room_id' => ($subject->subject->isPracticum() || $subject->delivery_mode === 'online') ? null : $roomId,
                    'days' => $subject->subject->isPracticum() ? null : ($days ?: null),
                    'start_time' => $subject->subject->isPracticum() ? null : $startTime,
                    'end_time' => $subject->subject->isPracticum() ? null : $endTime,
                    'capacity' => $capacity,
                    'status' => $status,
                    // Same persisted-confirmation pattern as
                    // updateSchedule() above — see the
                    // 2026_08_13_120000 migration's docblock.
                    'capacity_confirmed' => ! empty($rowData['capacity_confirmed']),
                    'hours_confirmed' => ! empty($rowData['hours_confirmed']),
                    // Same persisted-confirmation pattern as
                    // capacity_confirmed/hours_confirmed above — see the
                    // 2026_08_24_180000 migration's docblock. Previously
                    // missing here, so a Room Type/College Mismatch
                    // confirmed via batch Save Schedule still reappeared
                    // on the next reload.
                    'room_type_confirmed' => ! empty($rowData['room_type_confirmed']),
                    'room_college_confirmed' => ! empty($rowData['room_college_confirmed']),
                    // Faculty Mismatch — same persisted-confirmation
                    // pattern as the room flags above. See the
                    // 2026_08_30_090000 migration's docblock.
                    'faculty_mismatch_confirmed' => ! empty($rowData['faculty_mismatch_confirmed']),
                    // "Save Schedule" finalizes any Auto Generated rows
                    // it saves — once saved they're a normal schedule,
                    // no longer a pending suggestion to clear/regenerate.
                    'is_auto_generated' => false,
                    'auto_generated_meta' => null,
                    'is_workload_override' => $isWorkloadOverride,
                    'workload_override_by' => $isWorkloadOverride ? $request->user()?->id : null,
                ]);

                // AUTO-RAISE CEILING — same fix as updateSchedule()
                // above, applied here too since a batch Save Schedule
                // can Override & Save a workload warning exactly the
                // same way the single-cell endpoint can (see the
                // block comment above updateSchedule()'s equivalent
                // for the full rationale).
                if ($isWorkloadOverride) {
                    $lockedFaculty = \App\Models\Faculty::whereKey($workloadWarning['faculty_id'])->lockForUpdate()->first();

                    if ($lockedFaculty) {
                        $cap = FacultyLoadRequest::effectiveCapFor($request->user());
                        $newCeiling = min(max($lockedFaculty->max_teaching_units, (int) $workloadWarning['projected']), $cap);

                        if ($newCeiling > $lockedFaculty->max_teaching_units) {
                            $autoLoadRequest = FacultyLoadRequest::create([
                                'faculty_id' => $lockedFaculty->id,
                                'current_max_teaching_units' => $lockedFaculty->max_teaching_units,
                                'requested_max_teaching_units' => $newCeiling,
                                'current_max_weekly_hours' => $lockedFaculty->max_weekly_hours,
                                'requested_max_weekly_hours' => $lockedFaculty->max_weekly_hours,
                                'reason' => "Auto-raised: {$request->user()->full_name} overrode the Teaching Load Limit warning while batch-scheduling {$workloadWarning['subject_code']}.",
                                'status' => 'Approved',
                                'requested_by' => $request->user()->id,
                                'reviewed_by' => $request->user()->id,
                                'reviewed_at' => now(),
                            ]);

                            $lockedFaculty->update(['max_teaching_units' => $newCeiling]);

                            $this->notifications->facultyLoadUpdatedDirectly($autoLoadRequest, $request->user());

                            // See $raisedFacultyLoads' declaration
                            // above this batch loop.
                            $raisedFacultyLoads[$lockedFaculty->id] = [
                                'id' => $lockedFaculty->id,
                                'max_teaching_units' => $newCeiling,
                                'current_load' => (int) $workloadWarning['projected'],
                            ];
                        }
                    }
                }

                $savedIds[] = $subject->id;
            }

            // PARTIAL SAVE — a row with an overlap/conflict error or an
            // unconfirmed workload warning is skipped above ($skippedIds)
            // but never blocks the rest of the batch. Only a batch that
            // saved NOTHING at all (every row was skipped) is rolled back
            // whole, since there'd be nothing to commit or bump the
            // version for anyway.
            if (empty($savedIds)) {
                DB::rollBack();

                return response()->json([
                    'errors' => $errors,
                    'workload_warnings' => $workloadWarnings,
                    'can_override' => $canOverrideWorkload,
                    'message' => 'Every row in this batch has a scheduling conflict. Nothing was saved.',
                ], 422);
            }

            // Version is bumped whenever at least one row actually
            // changed, matching how a partial save is still a real write.
            $this->conflictService->bumpScheduleVersion($lockedSection);

            DB::commit();
        } catch (ScheduleVersionConflictException $conflict) {
            DB::rollBack();

            return response()->json([
                'message' => 'Schedule has changed since it was loaded. Please refresh the schedule and try again.',
                'code' => 'SCHEDULE_VERSION_CONFLICT',
                'current_version' => $conflict->currentVersion,
            ], 409);
        } catch (SectionFinalizedException $finalized) {
            DB::rollBack();

            return response()->json([
                'message' => "This section's schedule is finalized and can no longer be edited.",
                'code' => 'SECTION_FINALIZED',
            ], 423);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to save the schedule. Please try again.',
            ], 500);
        }

        $fresh = $section->sectionSubjects()
            ->with(['subject', 'faculty', 'room', 'mergedInto.section:id,section_code'])
            ->whereIn('id', $rowIds)
            ->get();

        // WORKLOAD OVERRIDE AUDIT — same event as updateSchedule()'s
        // equivalent, logged/notified once per overridden row now that
        // the batch has actually committed. Faculty rows are fetched
        // once and reused across duplicate overrides of the same
        // Faculty within this batch, rather than re-querying per row.
        if (! empty($workloadOverrides)) {
            $overriddenFaculty = \App\Models\Faculty::whereIn(
                'id',
                collect($workloadOverrides)->pluck('faculty_id')->unique()
            )->get()->keyBy('id');

            foreach ($workloadOverrides as $override) {
                $facultyForOverride = $overriddenFaculty->get($override['faculty_id']);

                if (! $facultyForOverride) {
                    continue;
                }

                $facultyName = trim(($facultyForOverride->first_name ?? '').' '.($facultyForOverride->last_name ?? ''));
                $overriddenSubject = $fresh->firstWhere('id', $override['section_subject_id']);

                $this->activityLog->record(
                    ActivityLogService::SCHEDULE_UPDATED,
                    "{$request->user()->full_name} overrode the teaching load limit warning to schedule {$facultyName} for {$override['subject_code']}.",
                    $overriddenSubject,
                    $request->user(),
                );

                if ($overriddenSubject) {
                    $this->notifications->facultyWorkloadOverridden($facultyForOverride, $overriddenSubject, $request->user(), $override);
                }
            }
        }

        $message = empty($skippedIds)
            ? 'Schedule saved successfully.'
            : count($savedIds).' of '.count($rowIds).' subjects saved. '
                .count($skippedIds).' '.(count($skippedIds) === 1 ? 'subject was' : 'subjects were')
                .' skipped due to a scheduling conflict — see the highlighted rows.';

        return response()->json([
            'sectionSubjects' => $fresh,
            'schedule_version' => $lockedSection->fresh()->schedule_version,
            // Non-empty only on a partial save — the frontend can use
            // these to keep the skipped rows' conflict highlights visible
            // even though the request itself succeeded (200), since some
            // rows genuinely weren't written.
            'errors' => $errors,
            'workload_warnings' => $workloadWarnings,
            // Was previously only sent on the "everything skipped" 422
            // below — meaning a PARTIAL save (some rows saved, others
            // skipped for exceeding workload) left the Subjects tab with
            // no way to know an override was even available, unlike the
            // Room Grid's equivalent 409 flow which always includes it.
            // The frontend needs this on every response carrying
            // workload_warnings, not just the all-skipped case.
            'can_override' => $canOverrideWorkload,
            'saved_ids' => $savedIds,
            'skipped_ids' => $skippedIds,
            'message' => $message,
            // See performScheduleAssignmentUpdate()'s
            // updated_faculty_load — same idea, list-shaped since a
            // batch can raise more than one Faculty's ceiling in one
            // Save Schedule. Empty when nothing was raised.
            'updated_faculty_loads' => array_values($raisedFacultyLoads),
        ]);
    }

    /**
     * Build a rolling list of Academic Year options (e.g. "2026-2027"),
     * matching SectionController's own generator so both places offer
     * the same choices.
     *
     * @return list<string>
     */
    private function academicYearOptions(): array
    {
        $currentYear = (int) now()->format('Y');
        $startYear = $currentYear - 1;

        return collect(range($startYear, $startYear + 6))
            ->map(fn (int $year) => "{$year}-" . ($year + 1))
            ->values()
            ->all();
    }

    /**
     * "Generate Curriculum Subjects" (Tab 2) — automatically loads every
     * Subject from the Section's own Curriculum, Year Level, and
     * Semester. Subjects already assigned to the Section are skipped,
     * so this is safe to click repeatedly (additive, never destructive,
     * never duplicates).
     */
    public function generateCurriculumSubjects(Section $section): RedirectResponse
    {
        $mappedYearLevel = self::YEAR_LEVEL_MAP[$section->year_level] ?? null;

        if (! $mappedYearLevel) {
            return back()->with('error', 'This section has no recognized year level to generate subjects from.');
        }

        $placedSubjectIds = $section->sectionSubjects()->pluck('subject_id');

        $subjectIds = CurriculumItem::query()
            ->where('curriculum_id', $section->curriculum_id)
            ->where('year_level', $mappedYearLevel)
            ->where('semester', $section->semester)
            ->whereNotIn('subject_id', $placedSubjectIds)
            ->pluck('subject_id');

        if ($subjectIds->isEmpty()) {
            return back()->with('error', 'No new subjects found for this section\'s curriculum, year level, and semester.');
        }

        $section->loadMissing('major');

        // CONCURRENCY HARDENING — bumping the Section's schedule_version
        // here (same counter used by updateSchedule()/autoGenerate())
        // is what lets the existing useSchedulePolling composable on
        // every OTHER open tab detect this change. Without it, a
        // second OIC/Registrar already viewing this Section's (empty)
        // Subjects tab has no signal that subjects now exist here —
        // the "Schedule Updated" banner simply never fires. See
        // ScheduleConflictService::bumpScheduleVersion() docblock for
        // why this must happen inside the same locked transaction as
        // the writes it's meant to announce.
        try {
            DB::transaction(function () use ($section, $subjectIds) {
                $lockedSection = $this->conflictService->lockResources(null, null, $section->id);

                foreach ($subjectIds as $subjectId) {
                    $sectionSubject = SectionSubject::create([
                        'section_id' => $section->id,
                        'subject_id' => $subjectId,
                        'source' => 'Curriculum',
                        'capacity' => $section->estimated_students,
                    ]);

                    // EDP Code is minted the moment a subject is placed into the
                    // section — it doesn't wait for scheduling. No-op if the row
                    // somehow already has one.
                    $sectionSubject->setRelation('section', $section);
                    $this->edpCodeService->generateForSectionSubject($sectionSubject);
                }

                $this->conflictService->bumpScheduleVersion($lockedSection);
            });
        } catch (SectionFinalizedException $finalized) {
            return back()->with('error', "This section's schedule is finalized — subjects can't be added until it's unlocked.");
        }

        $count = $subjectIds->count();

        return back()->with('success', "{$count} " . ($count === 1 ? 'subject' : 'subjects') . ' generated from the curriculum.');
    }

    /**
     * "Preview Subjects" (Load From Curriculum tab) — every Subject on
     * the given Curriculum for the given Year Level and Semester that
     * isn't already assigned to this Section. Curriculum, Year Level,
     * and Semester together pin the preview to one specific
     * major/year/semester offering, since Curriculum.major_id fixes
     * the major and CurriculumItem.year_level/semester fix the rest.
     */
    public function curriculumPreview(Request $request, Section $section): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'integer', 'exists:curriculums,id'],
            'year_level' => ['required', 'string'],
            'semester' => ['required', 'string'],
        ]);

        $placedSubjectIds = $section->sectionSubjects()->pluck('subject_id');

        $subjects = CurriculumItem::query()
            ->where('curriculum_id', $validated['curriculum_id'])
            ->where('year_level', $validated['year_level'])
            ->where('semester', $validated['semester'])
            ->whereNotIn('subject_id', $placedSubjectIds)
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
     * Add one or more Subjects to the Section manually — for irregular
     * students, bridging subjects, replacement subjects, or
     * cross-enrolled subjects. Duplicate subjects within the Section
     * are rejected by StoreSectionSubjectRequest.
     */
    public function store(StoreSectionSubjectRequest $request, Section $section): RedirectResponse
    {
        $validated = $request->validated();

        // CONCURRENCY HARDENING — see the matching comment in
        // generateCurriculumSubjects() above. Adding subjects manually
        // (this exact action — an OIC populating a just-created,
        // previously-empty Section) is the case that was silently
        // invisible to any other tab already sitting on this Section's
        // Subjects view: schedule_version never moved, so
        // useSchedulePolling() had nothing to detect and the "No
        // subjects assigned yet." view never learned it was stale.
        try {
            DB::transaction(function () use ($section, $validated, $request) {
            $lockedSection = $this->conflictService->lockResources(null, null, $section->id);

            foreach ($validated['subject_ids'] as $subjectId) {
                $sectionSubject = SectionSubject::create([
                    'section_id' => $section->id,
                    'subject_id' => $subjectId,
                    'source' => $validated['source'],
                    // New subjects always start with an empty schedule slot.
                    // Faculty, Room, Days, and Time are assigned later by the
                    // scheduling engine — never automatically here. Capacity
                    // defaults to the Section's own Estimated Students unless
                    // the caller explicitly set one.
                    'capacity' => $validated['capacity'] ?? $section->estimated_students,
                    'status' => 'Draft',
                ]);

                // EDP Code is minted the moment a subject is placed into the
                // section — it doesn't wait for scheduling. No-op if the row
                // somehow already has one.
                $sectionSubject->setRelation('section', $section->loadMissing('major'));
                $this->edpCodeService->generateForSectionSubject($sectionSubject);

                // SCHEDULING NOTIFICATION SYSTEM (audit spec Section
                // 6) — one notification per subject added, matching
                // the "CAP102 was added to BSIT-4B" example. Fired
                // inside this same transaction so a rolled-back add
                // never produces a notification.
                $this->notifications->subjectAdded($section, $sectionSubject, $request->user());
            }

            $this->conflictService->bumpScheduleVersion($lockedSection);
            });
        } catch (SectionFinalizedException $finalized) {
            return back()->with('error', "This section's schedule is finalized — subjects can't be added until it's unlocked.");
        }

        $count = count($validated['subject_ids']);

        return back()->with('success', $count === 1 ? 'Subject added to the section.' : "{$count} subjects added to the section.");
    }

    /**
     * SPLIT-DELIVERY SCHEDULING — turn one 'combined' SectionSubject
     * row into two rows: a Face-to-Face row (keeps a Room, still
     * subject to Room conflict detection) and an Online row (never
     * gets a Room, skipped by Room conflict detection — see
     * SectionSubject::requiresRoom()).
     *
     * Only ever called on a row that is still 'combined' — a subject
     * already split can't be split again (unsplitSchedule() first).
     * The two resulting rows share the same edp_code prefix concept
     * as everything else here but each keeps ITS OWN faculty/room/
     * days/time — they are independently scheduled from this point
     * on, exactly like two ordinary SectionSubject rows.
     *
     * SIBLING-SECTION PROPAGATION — splitting CC101 in BSIT-1A also
     * splits CC101 in every OTHER section of the same Major and same
     * Academic Year/Semester (BSIT-1B, 1C, etc.), using the SAME
     * f2f_hours/online_hours breakdown, so the Registrar doesn't have
     * to repeat "Split Hours" by hand for every section offering the
     * same subject. This propagates the HOURS BREAKDOWN only — each
     * sibling section still keeps (or, for its new Online row, starts
     * with) its OWN Faculty, and still needs its OWN Room/Days/Time
     * assigned separately; two different sections' worth of students
     * can never share one Room/Time slot, so that part is deliberately
     * NOT copied. Siblings that are finalized (locked) or whose CC101
     * row is already split are skipped rather than failing the whole
     * request.
     */
    public function splitSchedule(StoreSectionSubjectSplitRequest $request, Section $section, SectionSubject $subject): JsonResponse
    {
        $validated = $request->validated();

        $updated = DB::transaction(function () use ($section, $subject, $validated) {
            $lockedSection = $this->conflictService->lockResources(null, null, $section->id);

            // The existing row becomes the Face-to-Face half. It keeps
            // its own id, faculty_id, room_id, days, start_time, and
            // end_time exactly as they were — the Registrar re-checks
            // those afterward on the (now shorter) hours this row
            // covers, same as any other manual schedule edit.
            $subject->update([
                'component' => 'laboratory',
                'delivery_mode' => 'face_to_face',
                'split_hours' => $validated['f2f_hours'],
            ]);

            // The Online half is a brand-new row. Days/Time are left
            // empty for the Registrar to assign — Room is
            // intentionally never set here and never should be for an
            // 'online' delivery_mode row (see requiresRoom()).
            //
            // SAME-FACULTY RULE — a split subject is still taught by
            // ONE faculty member across both its F2F and Online
            // halves, so the Online row starts out with whatever
            // Faculty the original row already had (if any) rather
            // than an empty dropdown. onFacultyChange() in Show.vue
            // keeps the two rows in sync afterward if the Registrar
            // changes it on either one.
            // SHARED EDP CODE — the Online half is the same subject
            // offering as the Face-to-Face half above, just broken
            // into a second schedule component. It must carry the
            // ORIGINAL row's edp_code, not a freshly minted one — two
            // codes for one subject/section made it look like the
            // Registrar was offering CC101 twice (see the print
            // report). Set edp_code directly here instead of calling
            // edpCodeService->generateForSectionSubject(), which only
            // mints a NEW code and is a no-op once edp_code is already
            // set — calling it here would defeat the purpose.
            $onlineRow = SectionSubject::create([
                'section_id' => $section->id,
                'subject_id' => $subject->subject_id,
                'component' => 'lecture',
                'delivery_mode' => 'online',
                'split_hours' => $validated['online_hours'],
                'faculty_id' => $subject->faculty_id,
                'source' => $subject->source,
                'capacity' => $subject->capacity,
                'status' => 'Draft',
                'edp_code' => $subject->edp_code,
            ]);

            $onlineRow->setRelation('section', $section->loadMissing('major'));

            $this->splitSiblingSections($section, $subject, $validated);

            $this->conflictService->bumpScheduleVersion($lockedSection);

            return [$subject->fresh(), $onlineRow->fresh()];
        });

        $siblingCount = $this->lastSiblingSplitCount ?? 0;
        $this->lastSiblingSplitCount = null;

        return response()->json([
            'message' => $siblingCount > 0
                ? "Subject split into Face-to-Face and Online schedules — also split the same way in {$siblingCount} other section(s)."
                : 'Subject split into Face-to-Face and Online schedules.',
            'section_subjects' => $updated,
        ]);
    }

    /**
     * The count set by splitSiblingSections() for the current request,
     * purely so splitSchedule() can report it in its response message
     * without changing that method's return shape.
     */
    private ?int $lastSiblingSplitCount = null;

    /**
     * Applies the same f2f_hours/online_hours split to every OTHER
     * section's still-'combined' row for this subject, within the
     * same Major + Academic Year + Semester as $section. See
     * splitSchedule()'s docblock for why this exists and what it does
     * (and does not) copy. Must run inside splitSchedule()'s
     * transaction, since a failure partway through should roll back
     * the original section's split too.
     */
    private function splitSiblingSections(Section $section, SectionSubject $subject, array $validated): void
    {
        $siblingRows = SectionSubject::query()
            ->where('subject_id', $subject->subject_id)
            ->where('component', 'combined')
            ->where('section_id', '!=', $section->id)
            ->whereHas('section', function ($query) use ($section) {
                $query->where('major_id', $section->major_id)
                    ->where('academic_year', $section->academic_year)
                    ->where('semester', $section->semester);
            })
            ->with('section.major')
            ->get();

        $splitCount = 0;

        foreach ($siblingRows as $siblingRow) {
            try {
                // Locks (and re-checks is_finalized on) the SIBLING
                // section specifically — a finalized sibling is
                // skipped, not a reason to fail BSIT-1A's own split.
                $this->conflictService->lockResources(null, null, $siblingRow->section_id);
            } catch (SectionFinalizedException) {
                continue;
            }

            $siblingRow->update([
                'component' => 'laboratory',
                'delivery_mode' => 'face_to_face',
                'split_hours' => $validated['f2f_hours'],
            ]);

            // SHARED EDP CODE — same reasoning as the primary section's
            // split above: this sibling's Online row is that SAME
            // sibling section's SAME subject offering, so it takes
            // $siblingRow's own edp_code rather than generating a new
            // one.
            $siblingOnlineRow = SectionSubject::create([
                'section_id' => $siblingRow->section_id,
                'subject_id' => $siblingRow->subject_id,
                'component' => 'lecture',
                'delivery_mode' => 'online',
                'split_hours' => $validated['online_hours'],
                // The sibling section's OWN faculty (if it already had
                // one assigned), never $section's — see this method's
                // docblock. A sibling with no faculty yet gets an
                // empty dropdown on both its halves, same as before.
                'faculty_id' => $siblingRow->faculty_id,
                'source' => $siblingRow->source,
                'capacity' => $siblingRow->capacity,
                'status' => 'Draft',
                'edp_code' => $siblingRow->edp_code,
            ]);

            $siblingOnlineRow->setRelation('section', $siblingRow->section);

            $splitCount++;
        }

        $this->lastSiblingSplitCount = $splitCount;
    }

    /**
     * Reverse a split — collapses the Online row back into its
     * Face-to-Face sibling, which becomes an ordinary 'combined' row
     * again. The Online row's own Faculty/Days/Time (if any were set)
     * are discarded; only the Face-to-Face row survives, matching
     * how the row looked immediately before it was split.
     */
    public function unsplitSchedule(Section $section, SectionSubject $subject): JsonResponse
    {
        $sibling = SectionSubject::where('section_id', $section->id)
            ->where('subject_id', $subject->subject_id)
            ->where('id', '!=', $subject->id)
            ->first();

        $updated = DB::transaction(function () use ($section, $subject, $sibling) {
            $lockedSection = $this->conflictService->lockResources(null, null, $section->id);

            $survivor = $subject->delivery_mode === 'face_to_face' ? $subject : $sibling;
            $toDelete = $survivor?->id === $subject->id ? $sibling : $subject;

            $toDelete?->delete();

            $survivor?->update([
                'component' => 'combined',
                'delivery_mode' => 'face_to_face',
                'split_hours' => null,
            ]);

            $this->conflictService->bumpScheduleVersion($lockedSection);

            return $survivor?->fresh();
        });

        return response()->json([
            'message' => 'Split undone — subject merged back into a single schedule.',
            'section_subject' => $updated,
        ]);
    }

    /**
     * Remove a Subject from the Section. Only the placement
     * (SectionSubject) is deleted — the master Subject record, and any
     * other Section it belongs to, is untouched.
     */
    public function destroy(Section $section, Subject $subject): RedirectResponse
    {
        // CONCURRENCY HARDENING — same reasoning as store()/
        // generateCurriculumSubjects(): removal must also bump
        // schedule_version so any other tab already viewing this
        // Section's subject list is told, via the existing polling
        // banner, that what it's showing is now stale.
        try {
            DB::transaction(function () use ($section, $subject) {
                $lockedSection = $this->conflictService->lockResources(null, null, $section->id);

                // Capture the code before the delete — the row (and
                // its ->subject relation) won't exist to load from
                // afterward. See NotificationService::subjectRemoved()
                // docblock.
                $subjectCode = $subject->subject_code;

                $section->sectionSubjects()->where('subject_id', $subject->id)->delete();

                $this->conflictService->bumpScheduleVersion($lockedSection);

                // SCHEDULING NOTIFICATION SYSTEM (audit spec Section
                // 6) — fired inside this same transaction so a
                // rolled-back removal never produces a notification.
                $this->notifications->subjectRemoved($section, $subjectCode, request()->user());
            });
        } catch (SectionFinalizedException $finalized) {
            return back()->with('error', "This section's schedule is finalized — subjects can't be removed until it's unlocked.");
        }

        return back()->with('success', 'Subject removed from the section.');
    }
}