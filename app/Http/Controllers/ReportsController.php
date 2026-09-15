<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Services\ReportsService;
use App\Services\SettingsService;
use App\Support\ViewingTerm;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportsService $reports,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Display the Reports page. This is a read-only page: it only
     * ever queries existing data through ReportsService, never
     * mutates anything.
     */
    public function index(Request $request): Response
    {
        $this->authorize('view-reports');

        [$filters, $cleanFilters] = $this->buildFilters($request);

        $reportType = (string) $request->query('report_type', '');

        return Inertia::render('Reports/Index', [
            'filterOptions' => $this->reports->filterOptions(),
            'filters' => array_merge($filters, [
                'term' => $this->resolveTermValue($filters['academic_year'], $filters['semester']),
            ]),
            'termOptions' => $this->termFilterOptions(),
            'reportType' => $reportType,
            'summary' => $this->reports->dashboardSummary($filters['academic_year'] ?: null, $filters['semester'] ?: null),
            'report' => $reportType !== '' ? $this->reports->generate($reportType, $cleanFilters) : null,
            // Grid view (Reports/Index.vue's Schedule by Room / Schedule by
            // Faculty toggle) lays classes out against the same 30-min-row
            // + fixed-lunch-break scheduling window RoomGrid.vue already
            // uses when actually placing schedules — matches whichever
            // School Year the selected Academic Year belongs to, so a
            // report scoped to a past/future term still shows that
            // term's own window rather than always the currently Active
            // one. Falls back to the Active School Year, then to the
            // model's built-in defaults, when nothing matches.
            'schedulingWindow' => $this->schedulingWindowFor($filters['academic_year'] ?: null),
            'generatedAt' => now()->toDateTimeString(),
        ]);
    }

    /**
     * The scheduling window (Class Start/End Time, Time Interval,
     * Available Days, fixed Lunch Break) for the given Academic Year's
     * School Year — or the Active School Year if no Academic Year is
     * selected/matched. Shared shape with RoomGrid.vue's own
     * `schedulingWindow` prop so the Reports grid view renders exactly
     * the same 30-min-row layout the scheduling screens already use.
     */
    private function schedulingWindowFor(?string $academicYear): array
    {
        $schoolYear = $academicYear
            ? \App\Models\SchoolYear::query()->where('name', $academicYear)->first()
            : null;
        $schoolYear ??= \App\Models\SchoolYear::active();

        return [
            'start_time' => $schoolYear?->classStartTime() ?? \App\Models\SchoolYear::DEFAULT_CLASS_START_TIME,
            'end_time' => $schoolYear?->classEndTime() ?? \App\Models\SchoolYear::DEFAULT_CLASS_END_TIME,
            'available_days' => $schoolYear?->allowedDays() ?? \App\Models\SchoolYear::DEFAULT_CLASS_DAYS,
            'interval_minutes' => $schoolYear?->intervalMinutes() ?? \App\Models\SchoolYear::DEFAULT_TIME_INTERVAL_MINUTES,
            'lunch_start' => \App\Models\SchoolYear::LUNCH_BREAK_START,
            'lunch_end' => \App\Models\SchoolYear::LUNCH_BREAK_END,
        ];
    }

    /**
     * Printable version of the current report — a plain server-rendered
     * Blade page (NOT an Inertia page: it's meant to open in its own
     * tab/window via Reports/Index.vue's printReport(), untouched by
     * the SPA's layout/chrome) branded dynamically with the configured
     * School Name/Logo from Settings → General, so what actually
     * prints looks like an official school document rather than a
     * screenshot of the web app.
     *
     * Reuses buildFilters() so a printed report is always scoped
     * identically to whatever the Reports page currently shows —
     * the print button can never silently print different data than
     * what's on screen.
     */
    public function print(Request $request): View
    {
        $this->authorize('view-reports');

        [$filters, $cleanFilters] = $this->buildFilters($request);

        $reportType = (string) $request->query('report_type', '');

        // section_id is a plain id for every other report, but Schedule by
        // Section supports picking several specific (possibly non-contiguous)
        // sections at once — in that case there's no single "Section:" label
        // to show in the header, so leave $section null and let the print
        // view fall back to its per-section group headings instead.
        $section = (! is_array($filters['section_id']) && $filters['section_id'])
            ? Section::query()->find($filters['section_id'], ['id', 'section_code'])
            : null;

        // Room label for the Schedule by Room grid print's heading (same
        // "name it once at the top" convention as $sectionLabel above) —
        // only ever a single Room, since Grid requires one specific Room
        // chosen (see gridEnabled's docblock in Index.vue).
        $room = ($reportType === 'schedule_by_room' && ! empty($filters['room_id']) && ! is_array($filters['room_id']))
            ? \App\Models\Room::query()->find($filters['room_id'], ['id', 'room_code'])
            : null;

        $general = $this->settings->group('general');

        $report = $reportType !== '' ? $this->reports->generate($reportType, $cleanFilters) : null;

        // "Study Load" is the school's own term for a per-section printout
        // (what the Reports page still calls "Schedule by Section" in its
        // filter dropdown and on-screen results) — swapped only on this
        // printable copy so the change doesn't ripple into the Reports
        // page's dropdown label, on-screen heading, or CSV export filename.
        if ($reportType === 'schedule_by_section' && $report) {
            $report['title'] = 'Study Load';
        }

        // Grid print — only when the on-screen Grid toggle itself would be
        // offered (Reports/Index.vue's gridEnabled): Schedule by Room
        // scoped to one specific Room, or Schedule by Faculty scoped to
        // exactly one Faculty member (never "All Rooms"/"All Faculty" or
        // several at once — see gridEnabled's docblock in Index.vue for
        // why mixing several into one grid would misattribute cells).
        // view_mode itself is just what the Print button happened to send
        // (whichever tab — Table/Grid — was active on screen); re-checking
        // eligibility here means a stale/hand-edited query string can
        // never force a grid print that wouldn't make sense.
        $gridData = null;
        if ($request->query('view_mode') === 'grid' && $report && ! empty($report['rows'])) {
            $gridEligible = ($reportType === 'schedule_by_room' && ! empty($cleanFilters['room_id']))
                || ($reportType === 'schedule_by_faculty' && ! empty($report['facultyMeta']));

            if ($gridEligible) {
                $gridData = $this->reports->buildGridData(
                    $report['rows']->all(),
                    $this->schedulingWindowFor($filters['academic_year'] ?: null),
                    $reportType,
                );
            }
        }

        return view('reports.print', [
            'report' => $report,
            'reportType' => $reportType,
            'gridData' => $gridData,
            'roomLabel' => $room?->room_code,
            'academicYear' => $filters['academic_year'],
            'semester' => $filters['semester'],
            'sectionLabel' => $section?->section_code,
            'generatedAt' => now(),
            // SCHOOL BRANDING — single source of truth (Settings →
            // General). Falls back to the app name / placeholder logo
            // if nothing has been configured yet, so the print view
            // never errors or shows a broken image.
            'schoolName' => $general['general.school_name'] ?: config('app.name', 'Classly'),
            'schoolLogoUrl' => $general['general.school_logo_path'] ?: null,
            // Whoever is actually printing this signs the document under
            // their own role label (Admin/Registrar/Dean/OIC can all reach
            // this page) rather than always "Registrar" — falls back to
            // "Admin" for the Administrator role's shorter on-screen name,
            // and is blank (never guessed) if somehow unauthenticated or
            // roleless.
            'signerRole' => match (true) {
                ! $request->user() => null,
                $request->user()->hasRole('Administrator') => 'Admin',
                default => $request->user()->getRoleNames()->first(),
            },
            'signerName' => $request->user()?->name,
        ]);
    }

    /**
     * Shared filter-resolution logic for index() and print() — see
     * index()'s original docblock (now here) for the full defaulting
     * rationale. Kept as one method so the two entry points can never
     * drift into scoping a printed report differently than the page
     * that linked to it.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}  [$filters, $cleanFilters]
     */
    private function buildFilters(Request $request): array
    {
        $user = $request->user();

        // Academic Term default — mirrors SectionController@index: on a
        // truly blank first visit (no academic_year/semester/term in
        // the querystring at all) default the Academic Year + Semester
        // filters to the Active Academic Term, so Reports opens scoped
        // to "this term" like the rest of Scheduling rather than mixing
        // every term's data by default. Falls back to no default (every
        // Section) when no term is Active, same as Sections.
        //
        // Distinguishing "no params sent" from "params explicitly sent
        // as empty" (e.g. the Reset button, which now always sends
        // term=all) is what lets Reset genuinely clear back to "All
        // Years" instead of re-defaulting to the Active term.
        $termGiven = $request->has('term') || $request->has('academic_year') || $request->has('semester');

        $defaultAcademicYear = '';
        $defaultSemester = '';

        if (! $termGiven) {
            // Defaults to THIS user's Viewing Term (their session
            // override if Admin/Registrar switched it, else the real
            // Active term) rather than always the system-wide Active
            // term — so Reports opens scoped to whatever term the
            // Admin/Registrar is currently viewing, while everyone
            // else keeps defaulting to the real Active term as before.
            $viewingTerm = ViewingTerm::resolve($request);

            if ($viewingTerm) {
                $viewingTerm->loadMissing('schoolYear:id,name');
                $viewingSemesterValue = $viewingTerm->sectionSemesterValue();

                if ($viewingTerm->schoolYear && $viewingSemesterValue) {
                    $defaultAcademicYear = $viewingTerm->schoolYear->name;
                    $defaultSemester = $viewingSemesterValue;
                }
            }
        }

        // The `term` querystring param itself (the combined dropdown's
        // value) is only ever used to derive academic_year/semester on
        // the frontend before the request is sent — see
        // Reports/Index.vue's onTermChange(). The backend doesn't need
        // to decode it separately; academic_year/semester below already
        // carry whatever the Term dropdown resolved to.
        $filters = [
            'academic_year' => $request->query('academic_year', $defaultAcademicYear),
            'semester' => $request->query('semester', $defaultSemester),
            // NEVER trust an arbitrary college_id filter from a
            // College-scoped Dean/OIC — pin it to their own College
            // regardless of what the request asked for (spec Section
            // 19, 20, 23). Admin/Registrar/Assistant Dean may filter
            // freely (Assistant Dean's report queries are further
            // restricted to GenEd/Minor data inside ReportsService).
            'college_id' => \App\Support\AccessScope::isCollegeScoped($user)
                ? (string) \App\Support\AccessScope::collegeId($user)
                : $request->query('college_id', ''),
            'major_id' => $request->query('major_id', ''),
            'year_level' => $request->query('year_level', ''),
            // Schedule by Section sends section_id[] (array) when the user
            // picked specific sections via the multi-select; every other
            // report still sends a plain string id (or '' for "all"). Laravel
            // already returns whichever shape the querystring used, so just
            // pass it through — buildFilters/sectionsQuery handle both.
            'section_id' => $request->query('section_id', ''),
            'section_type' => $request->query('section_type', ''),
            'faculty_id' => $request->query('faculty_id', ''),
            'room_id' => $request->query('room_id', ''),
        ];

        $cleanFilters = array_filter($filters, fn ($v) => $v !== '' && $v !== null);

        return [$filters, $cleanFilters];
    }

    /**
     * Build the Reports page's Academic Term quick-filter dropdown.
     * Deliberately mirrors SectionController@termFilterOptions()
     * exactly (same value shape, same "All Terms" leading option, same
     * Archived tagging) so the two pages behave consistently — see
     * that method's docblock for the full rationale.
     *
     * @return list<array{value: string, label: string, status: string|null}>
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
     * The Term dropdown's current value, derived from whatever
     * academic_year/semester ended up selected — "all" when either is
     * blank (no single term is selected) or doesn't match a real term.
     */
    private function resolveTermValue(string $academicYear, string $semester): string
    {
        if ($academicYear === '' || $semester === '') {
            return 'all';
        }

        return "{$academicYear}|{$semester}";
    }
}