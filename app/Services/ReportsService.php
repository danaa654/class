<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\Curriculum;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Services\FacultyWorkloadService;
use App\Services\RoomUtilizationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REPORTS — read-only summarization layer for the Reports page.
 *
 * This service never writes to the database and never re-implements
 * scheduling/conflict logic that already exists elsewhere. It only
 * queries the existing Section / SectionSubject / Faculty / Room /
 * Curriculum tables (and, for conflicts, calls back into
 * ScheduleConflictService — the single source of truth for what a
 * "conflict" is) and shapes the results into generic
 * {columns, rows} tables the Reports page can render with one
 * component, regardless of report type.
 */
class ReportsService
{
    /** Every report type this service knows how to generate, grouped for the sidebar/select. */
    public const REPORT_GROUPS = [
        // Plain master-data rosters — no scheduling/term data at all,
        // unlike every other group below. Just "everyone/everything
        // currently on file", optionally narrowed by College/Program.
        'Lists' => [
            'faculty_list' => 'Faculty List',
            'room_list' => 'Room List',
            'subject_list' => 'Subject List',
        ],
        'Scheduling' => [
            'master_schedule' => 'Master Schedule',
            'schedule_by_section' => 'Schedule by Section',
            'schedule_by_faculty' => 'Schedule by Faculty',
            'schedule_by_room' => 'Schedule by Room',
            'unscheduled_subjects' => 'Unscheduled Subjects',
            'scheduling_conflicts' => 'Scheduling Conflicts',
        ],
        'Faculty' => [
            'faculty_teaching_load' => 'Faculty Teaching Load',
        ],
        'Rooms' => [
            'room_utilization' => 'Room Utilization',
            'room_conflicts' => 'Room Conflicts',
        ],
        'Academic' => [
            'sections_overview' => 'Sections Overview',
            'program_year_summary' => 'Program / Year Level Summary',
            'curriculum_report' => 'Curriculum / Prospectus Report',
            'section_subjects' => 'Section Subjects',
        ],
        'Irregular' => [
            'irregular_sections' => 'Irregular Sections',
            'irregular_merge_report' => 'Irregular Merge Report',
        ],
    ];

    public function __construct(
        private readonly ScheduleConflictService $conflicts,
        private readonly FacultyScheduleEmailService $facultyScheduleEmail,
        private readonly SignoffService $signoff,
        // Only for facultyList()/roomList() below — reused rather than
        // re-derived so those two plain rosters show the exact same
        // Teaching Load / Utilization/Availability figures as the
        // Faculty Master and Rooms pages themselves, never a
        // second, possibly-drifting computation of the same numbers.
        private readonly FacultyWorkloadService $workload,
        private readonly RoomUtilizationService $roomUtilization,
    ) {}

    /**
     * Options every global filter dropdown needs, independent of any
     * selected report.
     */
    public function filterOptions(): array
    {
        return [
            'academicYears' => Section::query()
                ->whereNotNull('academic_year')
                ->distinct()
                ->orderByDesc('academic_year')
                ->pluck('academic_year'),
            'semesters' => ['First Semester', 'Second Semester', 'Summer'],
            'colleges' => College::query()
                ->orderBy('name')
                ->with(['departments.majors' => fn ($q) => $q->orderBy('name')])
                ->get()
                ->map(fn (College $college) => [
                    'id' => $college->id,
                    'name' => $college->short_name ?: $college->name,
                    'majors' => $college->departments->flatMap->majors->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                    ])->values(),
                ]),
            'sections' => Section::query()
                ->orderBy('section_code')
                ->get(['id', 'section_code', 'academic_year', 'semester', 'major_id', 'year_level', 'section_type']),
            'yearLevels' => Section::query()->whereNotNull('year_level')->distinct()->orderBy('year_level')->pluck('year_level'),
            'faculty' => Faculty::query()->orderBy('last_name')->get()->map(fn (Faculty $f) => ['id' => $f->id, 'name' => $f->full_name, 'college_id' => $f->college_id]),
            'rooms' => Room::query()->orderBy('room_code')->get(['id', 'room_code', 'room_name', 'college_id']),
            'reportGroups' => self::REPORT_GROUPS,
        ];
    }

    /**
     * The dashboard summary shown before a report is generated,
     * scoped to the selected Academic Year + Semester (defaults to
     * every Section when neither is selected).
     */
    public function dashboardSummary(?string $academicYear, ?string $semester): array
    {
        $sections = Section::query()
            ->when($academicYear, fn ($q, $v) => $q->where('academic_year', $v))
            ->when($semester, fn ($q, $v) => $q->where('semester', $v))
            ->get(['id', 'section_type', 'major_id']);

        $sectionIds = $sections->pluck('id');

        $sectionSubjects = SectionSubject::query()->whereIn('section_id', $sectionIds)->get();
        $scheduled = $sectionSubjects->where('status', 'Scheduled')->count();
        $total = $sectionSubjects->count();

        return [
            'total_programs' => \App\Models\Major::query()->count(),
            'total_sections' => $sections->count(),
            'regular_sections' => $sections->where('section_type', 'Regular')->count(),
            'irregular_sections' => $sections->where('section_type', 'Irregular')->count(),
            'total_subjects' => $total,
            'scheduled_subjects' => $scheduled,
            'unscheduled_subjects' => $total - $scheduled,
            'total_faculty' => Faculty::query()->count(),
            'total_rooms' => Room::query()->count(),
            'completion_percent' => $total > 0 ? round(($scheduled / $total) * 100) : 0,
        ];
    }

    /**
     * Dispatches to the requested report and returns a generic
     * {title, columns, rows} shape, plus any chart-ready series.
     */
    public function generate(string $reportType, array $filters): array
    {
        $result = match ($reportType) {
            'faculty_list' => $this->facultyList($filters),
            'room_list' => $this->roomList($filters),
            'subject_list' => $this->subjectList($filters),
            'master_schedule' => $this->masterSchedule($filters),
            'schedule_by_section' => $this->scheduleBySection($filters),
            'schedule_by_faculty' => $this->scheduleByFaculty($filters),
            'schedule_by_room' => $this->scheduleByRoom($filters),
            'unscheduled_subjects' => $this->unscheduledSubjects($filters),
            'scheduling_conflicts' => $this->schedulingConflicts($filters),
            'faculty_teaching_load' => $this->facultyTeachingLoad($filters),
            'room_utilization' => $this->roomUtilization($filters),
            'room_conflicts' => $this->schedulingConflicts($filters, onlyType: 'Room'),
            'sections_overview' => $this->sectionsOverview($filters),
            'program_year_summary' => $this->programYearSummary($filters),
            'curriculum_report' => $this->curriculumReport($filters),
            'section_subjects' => $this->sectionSubjectsReport($filters),
            'irregular_sections' => $this->irregularSections($filters),
            'irregular_merge_report' => $this->irregularMergeReport($filters),
            default => ['title' => 'Unknown Report', 'columns' => [], 'rows' => [], 'note' => 'This report type does not exist.'],
        };

        // Exposed at the top level (not just inside facultyMeta, which
        // only gets set when exactly one faculty is selected) so the
        // frontend can still resolve which AcademicTerm to bulk-send
        // against when zero or multiple faculty are selected on the
        // Schedule by Faculty report.
        if ($reportType === 'schedule_by_faculty') {
            $result['termId'] = $this->resolveAcademicTerm($filters)?->id;
        }

        return $result;
    }

    // ------------------------------------------------------------
    // Shared query builders
    // ------------------------------------------------------------

    /**
     * Base Section query with every global filter applied.
     */
    private function sectionsQuery(array $filters)
    {
        return Section::query()
            ->with(['major.department.college'])
            ->when($filters['academic_year'] ?? null, fn ($q, $v) => $q->where('academic_year', $v))
            ->when($filters['semester'] ?? null, fn ($q, $v) => $q->where('semester', $v))
            ->when($filters['major_id'] ?? null, fn ($q, $v) => $q->where('major_id', $v))
            ->when($filters['year_level'] ?? null, fn ($q, $v) => $q->where('year_level', $v))
            // section_id may now be a single id (most reports) or an array of
            // ids (Schedule by Section's "pick specific sections" multi-select,
            // e.g. BSIT-1 + BSIT-3 + BSIT-4 with no BSIT-2) — support both so
            // every other report type that still sends a plain id is untouched.
            ->when($filters['section_id'] ?? null, fn ($q, $v) => is_array($v) ? $q->whereIn('id', $v) : $q->where('id', $v))
            ->when($filters['section_type'] ?? null, fn ($q, $v) => $q->where('section_type', $v))
            ->when($filters['college_id'] ?? null, function ($q, $collegeId) {
                $q->whereHas('major.department', fn ($dq) => $dq->where('college_id', $collegeId));
            });
    }

    /**
     * Base SectionSubject query, scoped through sectionsQuery() so
     * every report shares one definition of "which Sections are in
     * scope right now".
     */
    private function sectionSubjectsQuery(array $filters)
    {
        $sectionIds = $this->sectionsQuery($filters)->pluck('id');

        return SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->with(['section.major', 'subject', 'faculty', 'room']);
    }

    private function programName(SectionSubject|Section $row): string
    {
        $section = $row instanceof Section ? $row : $row->section;

        return $section?->major?->short_name ?? $section?->major?->name ?? '—';
    }

    // ------------------------------------------------------------
    // A. Scheduling Reports
    // ------------------------------------------------------------

    private function masterSchedule(array $filters): array
    {
        $rows = $this->sectionSubjectsQuery($filters)->get()->map(function (SectionSubject $ss) {
            $mergeInfo = '—';
            if ($ss->section?->isIrregular()) {
                $mergeInfo = $ss->is_merged
                    ? 'Merged with '.($ss->mergedInto?->section?->section_code ?? '—')
                    : 'Independent';
            }

            return [
                'Section' => $ss->section?->section_code,
                'Section Type' => $ss->section?->section_type,
                'Program' => $this->programName($ss),
                'Year Level' => $ss->section?->year_level,
                'Subject Code' => $ss->subject?->subject_code,
                'Subject' => $ss->subject?->subject_title,
                'Units' => $ss->subject?->units,
                'Faculty' => $ss->faculty?->full_name,
                'Room' => $ss->room?->room_code,
                'Day' => $ss->days,
                'Start Time' => $this->formatTime12h($ss->start_time),
                'End Time' => $this->formatTime12h($ss->end_time),
                'Schedule Status' => $ss->status,
                'Irregular Handling' => $mergeInfo,
            ];
        });

        return $this->table('Master Schedule', $rows);
    }

    private function scheduleBySection(array $filters): array
    {
        // SPLIT-DELIVERY / MULTI-SESSION SCHEDULING — same "one class,
        // one row" rule scheduleByFaculty() already applies: a
        // Face-to-Face/Online split (or a Lecture/Lab pair) is ONE
        // subject offering in this section, sharing one EDP Code, just
        // with more than one Schedule/Room line. Without this, the
        // same EDP Code printed twice on the Study Load report (once
        // per component) even after the EDP Code itself was fixed to
        // be shared — this groups those rows back into one before the
        // print view ever sees them.
        $sectionSubjects = $this->sectionSubjectsQuery($filters)->get();

        $rows = $sectionSubjects
            ->groupBy(fn (SectionSubject $ss) => $ss->section_id.'-'.$ss->subject_id)
            ->map(function ($group) {
                // The Face-to-Face half (or the only row, for a class
                // that was never split) is the group's "primary" row —
                // its EDP Code/Faculty/Section Type identify the whole
                // group, same convention as scheduleByFaculty()'s
                // primary row.
                $primary = $group->first(fn (SectionSubject $ss) => $ss->delivery_mode !== 'online') ?? $group->first();

                // One entry per Schedule line in the group — a
                // never-split class has exactly one; a split class has
                // two (Face-to-Face, then Online), rendered as two
                // Room/Day-Time lines under the SAME EDP Code row
                // instead of two separate rows.
                $schedules = $group
                    ->sortBy(fn (SectionSubject $ss) => $ss->delivery_mode === 'online' ? 1 : 0)
                    ->map(fn (SectionSubject $ss) => [
                        'Room' => $ss->delivery_mode === 'online' ? 'Online' : $ss->room?->room_code,
                        'Day' => $ss->days,
                        'Start' => $this->formatTime12h($ss->start_time),
                        'End' => $this->formatTime12h($ss->end_time),
                    ])
                    ->values();

                $first = $schedules->first() ?? ['Room' => null, 'Day' => null, 'Start' => null, 'End' => null];

                return [
                    'EDP Code' => $primary->edp_code,
                    'Section' => $primary->section?->section_code,
                    'Subject Code' => $primary->subject?->subject_code,
                    'Subject' => $primary->subject?->subject_title,
                    'Faculty' => $primary->faculty?->full_name,
                    // Kept flat (first Schedule line only) for the
                    // generic multi-column dump used when no specific
                    // section is picked — see 'Schedules' below for
                    // the full per-EDP-Code breakdown the dedicated
                    // single/multi-section print layouts use instead.
                    'Room' => $first['Room'],
                    'Day' => $first['Day'],
                    'Start' => $first['Start'],
                    'End' => $first['End'],
                    'Type' => $primary->section?->section_type,
                    'Schedules' => $schedules->all(),
                ];
            })
            ->values();

        $result = $this->table('Schedule by Section', $rows);
        // 'Schedules' rides alongside each row for the dedicated
        // single/multi-section print layouts below — never a real
        // column in the generic multi-report dump (which would try to
        // render its nested array as a table cell).
        $result['columns'] = array_values(array_diff($result['columns'], ['Schedules']));

        // Multiple, explicitly-picked (possibly non-contiguous — e.g.
        // BSIT-1, BSIT-3, BSIT-4, skipping BSIT-2) sections: also hand
        // the print view a per-section breakdown, ordered to match the
        // order the sections were actually selected in, so it can print
        // each one as its own separate block/page instead of one
        // continuous merged table.
        $sectionIds = $filters['section_id'] ?? null;

        if (is_array($sectionIds) && count($sectionIds) > 1) {
            $bySection = $rows->groupBy('Section');

            $result['groups'] = collect($sectionIds)
                ->map(fn ($id) => Section::query()->with('major')->find($id, ['id', 'section_code', 'major_id', 'academic_year', 'semester']))
                ->filter()
                ->map(fn (Section $section) => [
                    'section_code' => $section->section_code,
                    'program' => $section->major?->name ?? $this->programName($section),
                    'academic_year' => $section->academic_year,
                    'semester' => $section->semester,
                    'rows' => $bySection->get($section->section_code, collect())->values()->all(),
                ])
                ->values()
                ->all();
        }

        return $result;
    }

    private function scheduleByFaculty(array $filters): array
    {
        $facultyIds = $filters['faculty_id'] ?? null;

        // FIX (cross-College faculty schedule truncated for a
        // College-scoped Dean/OIC) — one or more SPECIFIC faculty
        // members explicitly picked here means "print/email THIS
        // person's (or these people's) complete schedule", the exact
        // same full picture their own Faculty Workload tab already
        // shows a Dean/OIC for their own faculty — including subjects
        // taught outside the Dean's own College (e.g. a CCS-home
        // faculty also carrying a GenEd/minor load under CTE).
        // ReportsController::buildFilters() forcibly pins college_id
        // to a College-scoped Dean/OIC's own College for every report
        // type (correct for the "browse everyone's schedule, no
        // faculty picked" flavor of this same report, where college_id
        // legitimately means "sections belonging to my College" via
        // sectionsQuery()). But sectionSubjectsQuery() applies that
        // same college_id as a SECTION filter, so once a specific
        // faculty is picked it was silently discarding that faculty's
        // own sections that happen to sit in a different College —
        // dropping rows from the printed table AND (since deansForColleges()
        // below is derived from this exact same $sectionSubjects
        // collection) collapsing "Noted by" down to only the Dean of
        // the still-visible College. Stripping college_id from the
        // section query whenever specific faculty are named fixes both
        // at once, without touching the "no faculty picked" browsing
        // mode's existing College scoping.
        $hasExplicitFaculty = (is_array($facultyIds) && ! empty($facultyIds)) || (! is_array($facultyIds) && ! empty($facultyIds));
        $sectionQueryFilters = $hasExplicitFaculty ? Arr::except($filters, ['college_id']) : $filters;

        $query = $this->sectionSubjectsQuery($sectionQueryFilters)
            // FIX (cross-section merge visibility) — same fix already
            // applied in FacultyWorkloadService::assignedPlacements():
            // whereNull('merged_into_section_subject_id') used to be
            // applied here too, which is fine for the "one Faculty, same
            // Section family" Irregular-section case (the rider Section
            // has no schedule of its own that would go missing), but
            // breaks the moment two INDEPENDENT Sections (e.g. BSIT-1A
            // and BSIT-1B) merge a shared Online slot: the rider row
            // still belongs to ITS OWN Section (section_id is unchanged
            // by merging — only merged_into_section_subject_id is set),
            // so excluding it from the query dropped it from its own
            // Section's group too, making that Section's printed/grid
            // schedule silently miss its Online meeting even though the
            // class still happens. Riders are no longer excluded —
            // grouping below is keyed by faculty_id+section_id+subject_id,
            // so a rider simply reappears as an extra Schedule line
            // inside its OWN Section's group, same as a normal
            // Face-to-Face/Online split. This does not risk
            // double-counting: host and rider always have different
            // section_id, so they never land in the same group.
            ->with(['mergedPlacements.section:id,section_code', 'section.major.department.college'])
            // The College/Program filter on this report means "faculty
            // whose own home college/department is this one" — NOT
            // "sections belonging to this college". Without this, a CCS
            // filter still pulled in GenEd/Minor faculty (e.g. NSTP,
            // Understanding the Self) who personally belong to a
            // different college but happen to teach a CCS section.
            // sectionSubjectsQuery() already scopes to CCS *sections*
            // via sectionsQuery()'s college_id clause; this adds the
            // faculty-side constraint on top of that. Only meaningful
            // in the "no specific faculty picked" browsing mode — see
            // $hasExplicitFaculty above — since college_id is stripped
            // from $sectionQueryFilters (and therefore has no effect
            // here) whenever specific faculty are named.
            ->when($filters['college_id'] ?? null, function ($q, $collegeId) {
                $q->whereHas('faculty', fn ($fq) => $fq->where('college_id', $collegeId));
            });

        if (is_array($facultyIds) && ! empty($facultyIds)) {
            $query->whereIn('faculty_id', $facultyIds);
        } elseif (! empty($facultyIds)) {
            $query->where('faculty_id', $facultyIds);
        } else {
            $query->whereNotNull('faculty_id');
        }

        // Captured once so both the printed table rows AND the "Noted
        // by" Dean signatories (below) are derived from the exact same
        // matched set — never two separate queries that could drift
        // apart if run at slightly different times.
        $sectionSubjects = $query->get();

        $rows = $sectionSubjects
            // SPLIT-DELIVERY / MULTI-SESSION SCHEDULING — same grouping
            // rule FacultyWorkloadService::assignedPlacements() applies
            // on the Faculty Workload tab: every SectionSubject row that
            // shares the same Faculty+Section+Subject is the SAME
            // assigned class (e.g. a Face-to-Face/Online split, or a
            // Lecture/Lab pair meeting on different days), just with
            // more than one Schedule line — not a second class the
            // faculty teaches. Without this, one EDP Code could print
            // twice with its Units counted twice, disagreeing with the
            // Workload tab's "Assigned Subjects" table for the exact
            // same faculty/term.
            ->groupBy(fn (SectionSubject $ss) => $ss->faculty_id.'-'.$ss->section_id.'-'.$ss->subject_id)
            ->map(function ($group) {
                // The Face-to-Face half (or the only row, for a class
                // that was never split) is the group's "primary" row —
                // its EDP Code identifies the whole group, matching how
                // the Workload tab picks its primary row.
                $primary = $group->first(fn (SectionSubject $ss) => $ss->delivery_mode !== 'online') ?? $group->first();

                $sectionCodes = collect([$primary->section?->section_code])
                    ->merge($primary->mergedPlacements->pluck('section.section_code'))
                    ->filter()
                    ->unique()
                    ->values();

                // One entry per Schedule line in the group — a
                // never-split class has exactly one; a split class has
                // two (Face-to-Face, then Online), rendered as two
                // Schedule/Room lines under the SAME EDP Code/Subject
                // row instead of two separate rows.
                $schedules = $group
                    ->sortBy(fn (SectionSubject $ss) => $ss->delivery_mode === 'online' ? 1 : 0)
                    ->map(fn (SectionSubject $ss) => [
                        'Room' => $ss->delivery_mode === 'online' ? 'Online' : $ss->room?->room_code,
                        'Day' => $ss->days,
                        'Start' => $this->formatTime12h($ss->start_time),
                        'End' => $this->formatTime12h($ss->end_time),
                    ])
                    ->values();

                $first = $schedules->first() ?? ['Room' => null, 'Day' => null, 'Start' => null, 'End' => null];

                return [
                    'EDP Code' => $primary->edp_code,
                    'Faculty' => $primary->faculty?->full_name,
                    'Subject Code' => $primary->subject?->subject_code,
                    'Subject' => $primary->subject?->subject_title,
                    // Host + every merged rider's Section Code, joined with
                    // " & " (e.g. "BSIT-4A & BSIT-4A-IRREG") — same format
                    // as the Workload tab's Assigned Subjects list.
                    'Section' => $sectionCodes->implode(' & '),
                    // Kept flat (first Schedule line only) for the
                    // generic multi-column dump used when no specific
                    // faculty is picked — see 'Schedules' below for the
                    // full per-EDP-Code breakdown the dedicated
                    // single/multi-faculty print layouts use instead.
                    'Room' => $first['Room'],
                    'Day' => $first['Day'],
                    'Start' => $first['Start'],
                    'End' => $first['End'],
                    'Units' => $primary->subject?->units,
                    'Schedules' => $schedules->all(),
                ];
            })
            ->values();

        $result = $this->table('Schedule by Faculty', $rows);
        // 'Schedules' rides alongside each row for the dedicated
        // single/multi-faculty print layouts below — never a real
        // column in the generic multi-report dump (which would try to
        // render its nested array as a table cell).
        $result['columns'] = array_values(array_diff($result['columns'], ['Schedules']));

        // Single, explicitly-picked faculty: hand the Vue page enough to
        // drive the "Send via Email" button (Faculty Schedule Email
        // System) without polluting report.columns — this rides alongside
        // the generic {columns, rows} table rather than inside it, since
        // table() derives columns from array_keys of the first row.
        $singleFacultyId = is_array($facultyIds)
            ? (count($facultyIds) === 1 ? $facultyIds[0] : null)
            : $facultyIds;

        if ($singleFacultyId) {
            $faculty = Faculty::query()->find($singleFacultyId);
            $term = $this->resolveAcademicTerm($filters);

            if ($faculty) {
                $result['facultyMeta'] = [
                    'id' => $faculty->id,
                    'faculty_id' => $faculty->faculty_id,
                    'full_name' => $faculty->full_name,
                    'email' => $faculty->email,
                    'college' => $faculty->college?->name,
                    'academic_term_id' => $term?->id,
                    'is_finalized' => $term ? $this->facultyScheduleEmail->isFinalized($faculty, $term) : false,
                    // "Noted by" signatories for the printed schedule —
                    // the Dean/OIC of every College this faculty member
                    // actually has a subject under this term (e.g. a
                    // CCS-home faculty teaching a GenEd load for CTE
                    // still gets CTE's Dean listed alongside CCS's).
                    'deans' => $this->signoff->deansForColleges($sectionSubjects),
                    // "Approved by" — institution-wide, not College-
                    // scoped like Dean/OIC above, so this is the same
                    // list regardless of which College(s) the faculty
                    // teaches under.
                    'approvers' => $this->signoff->approvers(),
                ];
            }
        }

        // Multiple, explicitly-picked faculty: also hand the print view a
        // per-faculty breakdown, ordered to match selection order, so it
        // can print each faculty member's load as its own separate
        // block/page — same "print each one separately" flow as Schedule
        // by Section.
        if (is_array($facultyIds) && count($facultyIds) > 1) {
            $byFaculty = $rows->groupBy('Faculty');
            $sectionSubjectsByFacultyId = $sectionSubjects->groupBy('faculty_id');

            $result['groups'] = collect($facultyIds)
                ->map(fn ($id) => Faculty::query()->find($id, ['id', 'first_name', 'middle_name', 'last_name', 'suffix']))
                ->filter()
                ->map(fn (Faculty $faculty) => [
                    'label' => $faculty->full_name,
                    'academic_year' => $filters['academic_year'] ?? null,
                    'semester' => $filters['semester'] ?? null,
                    'rows' => $byFaculty->get($faculty->full_name, collect())->values()->all(),
                    'deans' => $this->signoff->deansForColleges($sectionSubjectsByFacultyId->get($faculty->id, collect())),
                    'approvers' => $this->signoff->approvers(),
                ])
                ->values()
                ->all();
        }

        return $result;
    }

    private function scheduleByRoom(array $filters): array
    {
        $query = $this->sectionSubjectsQuery($filters)
            // Same "one class session, one row" rule as scheduleByFaculty()
            // — a merged Irregular-section rider row is the same session
            // as its host row, just ridden along on by another Section,
            // never a second class actually meeting in this room.
            ->whereNull('merged_into_section_subject_id')
            ->with('mergedPlacements.section:id,section_code');

        if (! empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        } else {
            $query->whereNotNull('room_id');
        }

        $rows = $query->get()->map(function (SectionSubject $ss) {
            $sectionCodes = collect([$ss->section?->section_code])
                ->merge($ss->mergedPlacements->pluck('section.section_code'))
                ->filter()
                ->unique()
                ->values();

            return [
                'Room' => $ss->room?->room_code,
                'Subject' => $ss->subject?->subject_title,
                // Host + every merged rider's Section Code, joined with
                // " & " (e.g. "BSIT-4A & BSIT-4A-IRREG") — same format
                // as Schedule by Faculty and the Workload tab.
                'Section' => $sectionCodes->implode(' & '),
                'Faculty' => $ss->faculty?->full_name,
                'Day' => $ss->days,
                'Start' => $this->formatTime12h($ss->start_time),
                'End' => $this->formatTime12h($ss->end_time),
                'Room Capacity' => $ss->room?->capacity,
            ];
        });

        return $this->table('Schedule by Room', $rows);
    }

    private function unscheduledSubjects(array $filters): array
    {
        $rows = $this->sectionSubjectsQuery($filters)
            ->where('status', '!=', 'Scheduled')
            ->get()
            ->map(function (SectionSubject $ss) {
                $has = fn ($v) => filled($v);
                $partial = $has($ss->faculty_id) || $has($ss->room_id) || $has($ss->days);
                $reason = match (true) {
                    ! $has($ss->faculty_id) && ! $has($ss->room_id) && ! $has($ss->days) => 'No faculty, room, or time assigned yet.',
                    ! $has($ss->faculty_id) => 'Faculty not yet assigned.',
                    ! $has($ss->room_id) => 'Room not yet assigned.',
                    ! $has($ss->days) || ! $has($ss->start_time) => 'Day/Time not yet assigned.',
                    default => 'Incomplete schedule.',
                };

                return [
                    'Section' => $ss->section?->section_code,
                    'Section Type' => $ss->section?->section_type,
                    'Program' => $this->programName($ss),
                    'Subject Code' => $ss->subject?->subject_code,
                    'Subject' => $ss->subject?->subject_title,
                    'Faculty' => $ss->faculty?->full_name ?? '—',
                    'Room' => $ss->room?->room_code ?? '—',
                    'Scheduling Status' => $partial ? 'Partially Scheduled' : 'Unscheduled',
                    'Reason' => $reason,
                ];
            });

        return $this->table('Unscheduled Subjects', $rows, emptyMessage: 'All section subjects are currently scheduled.');
    }

    /**
     * Reuses ScheduleConflictService's own overlap-detection methods
     * (findFacultyConflict / findRoomConflict / findSectionConflict) —
     * it never re-derives what counts as a conflict.
     */
    private function schedulingConflicts(array $filters, ?string $onlyType = null): array
    {
        $placements = $this->sectionSubjectsQuery($filters)
            ->where(fn ($q) => $q->whereNotNull('faculty_id')->orWhereNotNull('room_id'))
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->days && $ss->start_time && $ss->end_time);

        $seenPairs = [];
        $rows = collect();

        foreach ($placements as $ss) {
            $dayTokens = array_filter(explode(',', (string) $ss->days));

            // INTELLIGENT IRREGULAR SECTION SCHEDULING — a merged
            // Irregular-section row (and its host, and every other
            // rider merged into the same host) is the SAME class
            // session, not a second class overlapping it. Every other
            // conflict check in the app (Save Schedule, Auto Generate,
            // Room Grid moves) excludes the whole merge group via
            // ScheduleConflictService::mergeExclusionIds() — this report
            // previously excluded only $ss->id itself, so a merged
            // pair sharing the identical Faculty/Room/Day/Time by
            // design was flagged here as a false Faculty/Room
            // "conflict" even though Save Schedule never rejected it
            // and nothing about it is actually broken.
            $excludingIds = $this->conflicts->mergeExclusionIds($ss);

            $checks = [
                'Faculty' => fn () => $ss->faculty_id
                    ? $this->conflicts->findFacultyConflict($ss->faculty_id, $excludingIds, $dayTokens, $ss->start_time, $ss->end_time)
                    : null,
                'Room' => fn () => $ss->room_id
                    ? $this->conflicts->findRoomConflict($ss->room_id, $excludingIds, $dayTokens, $ss->start_time, $ss->end_time)
                    : null,
                'Section' => fn () => $this->conflicts->findSectionConflict($ss->section_id, $excludingIds, $dayTokens, $ss->start_time, $ss->end_time),
            ];

            foreach ($checks as $type => $check) {
                if ($onlyType && $type !== $onlyType) {
                    continue;
                }

                $conflictWith = $check();
                if (! $conflictWith) {
                    continue;
                }

                $pairKey = $type.':'.min($ss->id, $conflictWith->id).'-'.max($ss->id, $conflictWith->id);
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }
                $seenPairs[$pairKey] = true;

                $conflictWith->loadMissing(['section', 'subject', 'faculty', 'room']);

                $rows->push([
                    'Conflict Type' => $type,
                    'Section A' => $ss->section?->section_code,
                    'Subject A' => $ss->subject?->subject_code,
                    'Section B' => $conflictWith->section?->section_code,
                    'Subject B' => $conflictWith->subject?->subject_code,
                    'Faculty' => $ss->faculty?->full_name ?? '—',
                    'Room' => $ss->room?->room_code ?? '—',
                    'Day' => $ss->days,
                    'Time' => $this->formatTimeRange12h($ss->start_time, $ss->end_time),
                    // Faculty/Room/Section double-booking has no
                    // "Save Anyway" override anywhere in the app — Save
                    // Schedule hard-blocks it with a 422 (see
                    // SectionSubjectController). So every row here is
                    // still genuinely unresolved; only Hours Mismatch
                    // below can be Acknowledged.
                    'Status' => 'Unresolved',
                    'Note' => '—',
                ]);
            }

            // WEEKLY HOURS MISMATCH — same formula
            // SectionSubjectController's own save-time check uses
            // (minutes between Start/End) × meeting days ÷ 60, compared
            // against the Subject's declared lecture+laboratory hours
            // (falls back to 3, matching RecommendationService's own
            // fallback). That warning is confirmable at save time
            // ("Save Anyway"/hours_confirmed) — a Registrar CAN save a
            // schedule that doesn't add up to the required weekly
            // hours, same "flagged, not blocked" pattern as Room
            // Capacity. This surfaces those already-SAVED mismatches
            // here too, since Scheduling Conflicts previously only
            // checked for double-booking (Faculty/Room/Section) and
            // silently had no way to show a saved Hours Mismatch at
            // all — not because saving made it "not a conflict", but
            // because this report never looked for that kind of issue
            // in the first place.
            if (! $onlyType && $ss->subject) {
                $requiredHours = ((int) $ss->subject->lecture_hours) + ((int) $ss->subject->laboratory_hours);
                if ($requiredHours <= 0) {
                    $requiredHours = 3;
                }

                $actualMinutes = (strtotime($ss->end_time) - strtotime($ss->start_time)) / 60 * count($dayTokens);
                $actualHours = round($actualMinutes / 60, 2);

                if ($actualHours !== (float) $requiredHours) {
                    $rows->push([
                        'Conflict Type' => 'Hours Mismatch',
                        'Section A' => $ss->section?->section_code,
                        'Subject A' => $ss->subject?->subject_code,
                        'Section B' => '—',
                        'Subject B' => '—',
                        'Faculty' => $ss->faculty?->full_name ?? '—',
                        'Room' => $ss->room?->room_code ?? '—',
                        'Day' => $ss->days,
                        'Time' => $this->formatTimeRange12h($ss->start_time, $ss->end_time),
                        // Unlike Faculty/Room/Section conflicts, this
                        // one IS confirmable at save time
                        // (hours_confirmed / "Save Anyway"). A row here
                        // with hours_confirmed=true was already seen
                        // and knowingly accepted by whoever scheduled
                        // it — flag it as Acknowledged rather than
                        // Unresolved so an auditor isn't chasing an
                        // issue that's already been reviewed.
                        'Status' => $ss->hours_confirmed ? 'Acknowledged' : 'Unresolved',
                        'Note' => "Scheduled {$actualHours} hrs/week, requires {$requiredHours} hrs/week.",
                    ]);
                }
            }
        }

        return $this->table('Scheduling Conflicts', $rows, emptyMessage: 'No scheduling conflicts detected.');
    }

    // ------------------------------------------------------------
    // B. Faculty Reports
    // ------------------------------------------------------------

    private function facultyTeachingLoad(array $filters): array
    {
        $facultyQuery = Faculty::query()->with('college');
        if (! empty($filters['college_id'])) {
            $facultyQuery->where('college_id', $filters['college_id']);
        }

        $sectionIds = $this->sectionsQuery($filters)->pluck('id');

        $rows = $facultyQuery->get()->map(function (Faculty $faculty) use ($sectionIds) {
            $placements = SectionSubject::query()
                ->where('faculty_id', $faculty->id)
                ->whereIn('section_id', $sectionIds)
                ->with('subject')
                ->get();

            $hours = $placements->sum(fn (SectionSubject $ss) => $ss->start_time && $ss->end_time
                ? (strtotime($ss->end_time) - strtotime($ss->start_time)) / 3600 * max(1, count(explode(',', (string) $ss->days)))
                : 0);

            return [
                'Faculty' => $faculty->full_name,
                'Program' => $faculty->college?->short_name ?? $faculty->college?->name ?? 'General Education',
                'Number of Subjects' => $placements->pluck('subject_id')->unique()->count(),
                'Number of Sections' => $placements->pluck('section_id')->unique()->count(),
                'Total Units' => $placements->sum(fn ($ss) => $ss->subject?->units ?? 0),
                'Total Scheduled Hours' => round($hours, 1),
            ];
        })->filter(fn ($row) => empty($filters['faculty_id']) || true)->values();

        $assigned = $rows->where('Number of Subjects', '>', 0)->count();

        return $this->table('Faculty Teaching Load', $rows, summary: [
            'Total Faculty' => $rows->count(),
            'Faculty Assigned' => $assigned,
            'Faculty Unassigned' => $rows->count() - $assigned,
            'Average Teaching Load' => $rows->count() ? round($rows->avg('Total Units'), 1) : 0,
        ]);
    }

    /**
     * Plain Faculty roster — every Faculty member currently on file
     * (no scheduling/term data at all, unlike every other report in
     * this service), optionally narrowed to one College/Program. Same
     * columns as the Faculty Master page itself (Faculty ID, Name,
     * Employment Status, College, Max Teaching Units, Teaching Load,
     * Status) — including Teaching Load, via the same
     * FacultyWorkloadService::evaluateMany() the Faculty Master page
     * uses, so this never shows a different current/max than what's
     * on screen. Deliberately independent of sectionSubjectsQuery()/
     * Section scoping — a Faculty member with no current teaching
     * load at all still belongs on this list.
     */
    private function facultyList(array $filters): array
    {
        $faculty = Faculty::query()
            ->with('college')
            ->when($filters['college_id'] ?? null, fn ($q, $v) => $q->where('college_id', $v))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $workloads = $this->workload->evaluateMany($faculty);

        $rows = $faculty->map(function (Faculty $f) use ($workloads) {
            $w = $workloads[$f->id] ?? null;

            return [
                'Faculty ID' => $f->faculty_id,
                'Faculty Name' => $f->full_name,
                'Employment Status' => $f->employment_type,
                'College' => $f->college?->name ?? 'General Education',
                'Max Teaching Units' => $f->max_teaching_units,
                'Teaching Load' => $w ? "{$w['current']} / {$w['max']} {$w['unit_label']}" : '—',
                'Status' => $f->status,
            ];
        })->values();

        return $this->table('Faculty List', $rows, emptyMessage: 'No faculty found for the selected filters.');
    }

    /**
     * Plain Room roster — every Room currently on file, optionally
     * narrowed to one College/Program. Same columns as the Rooms page
     * itself (Room Name, Building, Floor, Room Type, College,
     * Department/Program, Capacity, Utilization, Availability,
     * Status) — including Utilization/Availability, via the same
     * RoomUtilizationService::summarizeRooms() the Rooms page uses.
     * Same "no scheduling data at all" independence as facultyList()
     * above.
     */
    private function roomList(array $filters): array
    {
        $rooms = Room::query()
            ->with(['college', 'department'])
            ->when($filters['college_id'] ?? null, fn ($q, $v) => $q->where('college_id', $v))
            ->orderBy('room_code')
            ->get();

        $summaries = $this->roomUtilization->summarizeRooms($rooms);

        $rows = $rooms->map(function (Room $room) use ($summaries) {
            $summary = $summaries[$room->id] ?? null;

            return [
                'Room Name' => $room->room_name,
                'Building' => $room->building,
                'Floor' => $room->floor ?: '—',
                'Room Type' => $room->room_type,
                'College' => $room->college?->name ?? 'All Colleges',
                'Department / Program' => $room->department?->name ?? 'All Programs',
                'Capacity' => $room->capacity,
                'Utilization' => $summary ? "{$summary['scheduled_hours']} / {$summary['max_hours']} hrs ({$summary['utilization_percent']}%)" : '—',
                'Availability' => $summary['availability'] ?? '—',
                'Status' => $room->status,
            ];
        })->values();

        return $this->table('Room List', $rows, emptyMessage: 'No rooms found for the selected filters.');
    }

    /**
     * Plain Subject roster — every Subject currently on file,
     * optionally narrowed to one College/Program. Same columns as the
     * Subject Library page itself (Subject Code, Subject Title,
     * Category, Subject Type, College, Major(s), Units, Lecture
     * Hours, Lab Hours, Required Hours, Status) — same "no scheduling
     * data at all" independence as facultyList()/roomList() above,
     * this is the Subject master list itself, not which Sections
     * currently carry it.
     */
    private function subjectList(array $filters): array
    {
        $rows = Subject::query()
            ->with(['college', 'majors'])
            ->when($filters['college_id'] ?? null, fn ($q, $v) => $q->where('college_id', $v))
            ->orderBy('subject_code')
            ->get()
            ->map(fn (Subject $subject) => [
                'Subject Code' => $subject->subject_code,
                'Subject Title' => $subject->subject_title,
                'Category' => $subject->category,
                'Subject Type' => $subject->subject_type === 'practicum' ? 'Practicum / OJT' : 'Regular',
                'College' => $subject->college?->code ?? '—',
                'Major(s)' => $subject->majors->count() ? $subject->majors->pluck('code')->implode(', ') : '—',
                'Units' => $subject->units,
                'Lecture Hours' => $subject->subject_type === 'practicum' ? '—' : $subject->lecture_hours,
                'Lab Hours' => $subject->subject_type === 'practicum' ? '—' : $subject->laboratory_hours,
                'Required Hours' => $subject->subject_type === 'practicum' ? ($subject->required_hours ?? '—') : '—',
                'Status' => $subject->is_active ? 'Active' : 'Inactive',
            ])
            ->values();

        return $this->table('Subject List', $rows, emptyMessage: 'No subjects found for the selected filters.');
    }

    /**
     * Faculty rows shaped for re-import — same header order and value
     * shape (college CODE, not name/id) as
     * FacultyController::importTemplate()/import(), so the file this
     * produces can be fed straight back into Bulk Import with zero
     * reshaping. Kept in lockstep with that template by hand — if its
     * column list ever changes, update both.
     */
    public function facultyImportRows(array $filters): array
    {
        $columns = [
            'faculty_id', 'first_name', 'middle_name', 'last_name', 'suffix',
            'employment_type', 'college', 'max_teaching_units', 'workload_type',
            'max_weekly_hours', 'status', 'email', 'contact_number', 'remarks',
        ];

        $rows = Faculty::query()
            ->with('college')
            ->when($filters['college_id'] ?? null, fn ($q, $v) => $q->where('college_id', $v))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Faculty $f) => [
                'faculty_id' => $f->faculty_id,
                'first_name' => $f->first_name,
                'middle_name' => $f->middle_name,
                'last_name' => $f->last_name,
                'suffix' => $f->suffix,
                'employment_type' => $f->employment_type,
                'college' => $f->college?->code ?? '',
                'max_teaching_units' => $f->max_teaching_units,
                'workload_type' => $f->workload_type,
                'max_weekly_hours' => $f->max_weekly_hours,
                'status' => $f->status,
                'email' => $f->email,
                'contact_number' => $f->contact_number,
                'remarks' => $f->remarks,
            ])
            ->values();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Room rows shaped for re-import — same header order/value shape
     * as RoomController::importTemplate()/import() (college/department
     * CODES; room_code is deliberately never included, since Import
     * always auto-derives it from Room Name, same as the Add Room
     * form). Kept in lockstep with that template by hand.
     */
    public function roomImportRows(array $filters): array
    {
        $columns = [
            'room_name', 'building', 'floor', 'room_type',
            'room_category', 'college', 'department', 'capacity', 'status', 'remarks',
        ];

        $rows = Room::query()
            ->with(['college', 'department'])
            ->when($filters['college_id'] ?? null, fn ($q, $v) => $q->where('college_id', $v))
            ->orderBy('room_code')
            ->get()
            ->map(fn (Room $room) => [
                'room_name' => $room->room_name,
                'building' => $room->building,
                'floor' => $room->floor,
                'room_type' => $room->room_type,
                'room_category' => $room->room_category,
                'college' => $room->college?->code ?? '',
                'department' => $room->department?->code ?? '',
                'capacity' => $room->capacity,
                'status' => $room->status,
                'remarks' => $room->remarks,
            ])
            ->values();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Subject rows shaped for re-import — same header order/value
     * shape as SubjectController::importTemplate()/import()
     * (college CODE, majors as ;-joined CODES, lowercase subject_type,
     * plain "Active"/"Inactive" status). Kept in lockstep with that
     * template by hand.
     */
    public function subjectImportRows(array $filters): array
    {
        $columns = [
            'subject_code', 'subject_title', 'category', 'subject_type', 'college',
            'majors', 'units', 'lecture_hours', 'laboratory_hours', 'required_hours',
            'deployment_type', 'preferred_room_category', 'status', 'description',
        ];

        $rows = Subject::query()
            ->with(['college', 'majors'])
            ->when($filters['college_id'] ?? null, fn ($q, $v) => $q->where('college_id', $v))
            ->orderBy('subject_code')
            ->get()
            ->map(fn (Subject $subject) => [
                'subject_code' => $subject->subject_code,
                'subject_title' => $subject->subject_title,
                'category' => $subject->category,
                'subject_type' => $subject->subject_type ?: 'regular',
                'college' => $subject->college?->code ?? '',
                'majors' => $subject->majors->count() ? $subject->majors->pluck('code')->implode(';') : '',
                'units' => $subject->units,
                'lecture_hours' => $subject->lecture_hours,
                'laboratory_hours' => $subject->laboratory_hours,
                'required_hours' => $subject->required_hours,
                'deployment_type' => $subject->deployment_type,
                'preferred_room_category' => $subject->preferred_room_category,
                'status' => $subject->is_active ? 'Active' : 'Inactive',
                'description' => $subject->description,
            ])
            ->values();

        return ['columns' => $columns, 'rows' => $rows];
    }

    // ------------------------------------------------------------
    // C. Room Reports
    // ------------------------------------------------------------

    /**
     * Utilization here is computed directly from the same
     * Section/SectionSubject records as every other report (scoped to
     * the selected Academic Year/Semester rather than only the Active
     * term, since a Report may look at a past or future term) — but
     * still classified with utilizationStatus()-equivalent thresholds
     * already established by RoomUtilizationService, not a new rule.
     */
    private function roomUtilization(array $filters): array
    {
        $sectionIds = $this->sectionsQuery($filters)->pluck('id');
        $schoolYear = SchoolYear::query()->where('name', $filters['academic_year'] ?? null)->first() ?? SchoolYear::active();
        $days = $schoolYear ? $schoolYear->allowedDays() : SchoolYear::DEFAULT_CLASS_DAYS;

        $roomQuery = Room::query();
        if (! empty($filters['room_id'])) {
            $roomQuery->where('id', $filters['room_id']);
        }

        $placementsByRoom = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->whereNotNull('room_id')
            ->whereNotNull('start_time')
            ->get()
            ->groupBy('room_id');

        $rows = $roomQuery->get()->map(function (Room $room) use ($placementsByRoom, $days) {
            $placements = $placementsByRoom->get($room->id, collect());
            $hours = $placements->sum(fn (SectionSubject $ss) => (strtotime($ss->end_time) - strtotime($ss->start_time)) / 3600 * max(1, count(array_filter(explode(',', (string) $ss->days)))));
            $maxHours = 8 * count($days);
            $percent = $maxHours > 0 ? round(($hours / $maxHours) * 100, 1) : 0;

            return [
                'Room' => $room->room_code,
                'Room Type' => $room->room_type,
                'Capacity' => $room->capacity,
                'Number of Scheduled Classes' => $placements->count(),
                'Total Scheduled Hours' => round($hours, 1),
                'Utilization Percentage' => $percent,
            ];
        });

        return $this->table('Room Utilization', $rows, summary: [
            'Total Rooms' => $rows->count(),
            'Rooms in Use' => $rows->where('Number of Scheduled Classes', '>', 0)->count(),
            'Rooms Unused' => $rows->where('Number of Scheduled Classes', 0)->count(),
            'Most Utilized Room' => $rows->sortByDesc('Utilization Percentage')->first()['Room'] ?? '—',
        ]);
    }

    // ------------------------------------------------------------
    // D. Academic Reports
    // ------------------------------------------------------------

    private function sectionsOverview(array $filters): array
    {
        $rows = $this->sectionsQuery($filters)->withCount('sectionSubjects')->get()->map(function (Section $section) {
            $scheduled = $section->sectionSubjects()->where('status', 'Scheduled')->count();

            return [
                'Section' => $section->section_code,
                'Section Type' => $section->section_type,
                'Program' => $this->programName($section),
                'Year Level' => $section->year_level,
                'Academic Year' => $section->academic_year,
                'Semester' => $section->semester,
                'Estimated Students' => $section->estimated_students,
                'Status' => $section->status,
                'Number of Subjects' => $section->section_subjects_count,
                'Scheduling Status' => $section->section_subjects_count === 0
                    ? 'No Subjects'
                    : ($scheduled === $section->section_subjects_count ? 'Scheduled' : ($scheduled > 0 ? 'Partially Scheduled' : 'Unscheduled')),
            ];
        });

        return $this->table('Sections Overview', $rows);
    }

    private function programYearSummary(array $filters): array
    {
        $sections = $this->sectionsQuery($filters)->withCount('sectionSubjects')->get();

        $rows = $sections->groupBy(fn (Section $s) => $this->programName($s).'|'.$s->year_level)
            ->map(function (Collection $group) {
                $first = $group->first();
                $subjectIds = SectionSubject::query()->whereIn('section_id', $group->pluck('id'))->get();

                return [
                    'Program' => $this->programName($first),
                    'Year Level' => $first->year_level,
                    'Number of Sections' => $group->count(),
                    'Regular Sections' => $group->where('section_type', 'Regular')->count(),
                    'Irregular Sections' => $group->where('section_type', 'Irregular')->count(),
                    'Number of Subjects' => $subjectIds->count(),
                    'Scheduled Subjects' => $subjectIds->where('status', 'Scheduled')->count(),
                    'Unscheduled Subjects' => $subjectIds->where('status', '!=', 'Scheduled')->count(),
                ];
            })->values();

        return $this->table('Program / Year Level Summary', $rows);
    }

    private function curriculumReport(array $filters): array
    {
        $query = Curriculum::query()->with(['major', 'items.subject']);
        if (! empty($filters['major_id'])) {
            $query->where('major_id', $filters['major_id']);
        }

        $rows = collect();
        foreach ($query->get() as $curriculum) {
            foreach ($curriculum->items as $item) {
                $rows->push([
                    'Curriculum' => $curriculum->code ?? $curriculum->name,
                    'Program' => $curriculum->major?->short_name ?? $curriculum->major?->name,
                    'Curriculum Year' => trim(($curriculum->start_year ?? '').'-'.($curriculum->end_year ?? ''), '-'),
                    'Year Level' => $item->year_level,
                    'Semester' => $item->semester,
                    'Subject Code' => $item->subject?->subject_code,
                    'Subject' => $item->subject?->subject_title,
                    'Units' => $item->subject?->units,
                ]);
            }
        }

        return $this->table('Curriculum / Prospectus Report', $rows);
    }

    private function sectionSubjectsReport(array $filters): array
    {
        $query = $this->sectionSubjectsQuery($filters);
        if (! empty($filters['section_id'])) {
            $query->where('section_id', $filters['section_id']);
        }

        $rows = $query->get()->map(fn (SectionSubject $ss) => [
            'Section' => $ss->section?->section_code,
            'Subject Code' => $ss->subject?->subject_code,
            'Subject' => $ss->subject?->subject_title,
            'Units' => $ss->subject?->units,
            'Faculty' => $ss->faculty?->full_name ?? '—',
            'Room' => $ss->room?->room_code ?? '—',
            'Schedule' => $ss->days && $ss->start_time ? "{$ss->days} ".$this->formatTimeRange12h($ss->start_time, $ss->end_time) : '—',
            'Scheduling Status' => $ss->status,
            'Source' => $ss->source,
        ]);

        return $this->table('Section Subjects', $rows);
    }

    // ------------------------------------------------------------
    // E. Irregular Section Reports
    // ------------------------------------------------------------

    private function irregularSections(array $filters): array
    {
        $filters['section_type'] = 'Irregular';

        $rows = $this->sectionsQuery($filters)->withCount('sectionSubjects')->get()->map(function (Section $section) {
            $subjects = SectionSubject::query()->where('section_id', $section->id)->get();

            return [
                'Section' => $section->section_code,
                'Program' => $this->programName($section),
                'Year Level' => $section->year_level,
                'Academic Year' => $section->academic_year,
                'Semester' => $section->semester,
                'Estimated Students' => $section->estimated_students,
                'Number of Subjects' => $subjects->count(),
                'Scheduled Subjects' => $subjects->where('status', 'Scheduled')->count(),
                'Merged Subjects' => $subjects->where('is_merged', true)->count(),
                'Independent Subjects' => $subjects->where('status', 'Scheduled')->where('is_merged', false)->count(),
            ];
        });

        return $this->table('Irregular Sections', $rows);
    }

    /**
     * Reads directly off the existing Merge relationship
     * (SectionSubject::is_merged / mergedInto()) — no merge logic is
     * re-derived here, only displayed.
     */
    private function irregularMergeReport(array $filters): array
    {
        $filters['section_type'] = 'Irregular';
        $sectionIds = $this->sectionsQuery($filters)->pluck('id');

        $rows = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->where('is_merged', true)
            ->with(['section', 'subject', 'mergedInto.section', 'mergedInto.faculty', 'mergedInto.room', 'mergedInto.mergedPlacements'])
            ->get()
            ->map(function (SectionSubject $ss) {
                $host = $ss->mergedInto;
                $existingStudents = $host?->section?->estimated_students ?? 0;
                $irregularStudents = $ss->section?->estimated_students ?? 0;
                $combined = $existingStudents + ($host?->mergedPlacements->sum(fn ($p) => $p->section?->estimated_students ?? 0) ?? 0);

                return [
                    'Irregular Section' => $ss->section?->section_code,
                    'Subject' => $ss->subject?->subject_title,
                    'Regular Section' => $host?->section?->section_code ?? '—',
                    'Faculty' => $host?->faculty?->full_name ?? '—',
                    'Room' => $host?->room?->room_code ?? '—',
                    'Day' => $host?->days ?? '—',
                    'Time' => $host ? $this->formatTimeRange12h($host->start_time, $host->end_time) : '—',
                    'Estimated Irregular Students' => $irregularStudents,
                    'Existing Class Students' => $existingStudents,
                    'Combined Students' => $combined,
                    'Room Capacity' => $host?->room?->capacity ?? '—',
                    'Merge Status' => 'Merged',
                ];
            });

        return $this->table('Irregular Merge Report', $rows, emptyMessage: 'No irregular subjects are currently merged.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * Formats a "HH:mm" (or "HH:mm:ss") time string as 12-hour
     * "h:mm AM/PM", matching the same convention already used on the
     * Rooms page's Weekly Timetable. Leaves non-time-like values
     * (null, already-formatted strings) untouched.
     */
    private function formatTime12h(?string $time): ?string
    {
        if (! $time) {
            return $time;
        }

        $parts = explode(':', $time);
        if (count($parts) < 2 || ! is_numeric($parts[0])) {
            return $time;
        }

        $hours = (int) $parts[0];
        $minutes = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $suffix = $hours >= 12 ? 'PM' : 'AM';
        $hours %= 12;
        if ($hours === 0) {
            $hours = 12;
        }

        return "{$hours}:{$minutes} {$suffix}";
    }

    /**
     * Formats a "HH:mm–HH:mm" (en dash separated) range using
     * formatTime12h() on each side.
     */
    private function formatTimeRange12h(?string $start, ?string $end): string
    {
        if (! $start || ! $end) {
            return '—';
        }

        return $this->formatTime12h($start).'–'.$this->formatTime12h($end);
    }

    /**
     * Semester dropdown labels (filterOptions() above) don't match the
     * actual Semester.name values in the database — the filter shows
     * "First Semester"/"Second Semester" while Semester::NAMES stores
     * "1st Semester"/"2nd Semester". Normalizes the filter's label to
     * the real stored name before looking it up, so resolveAcademicTerm()
     * below doesn't silently fail to match.
     */
    private const SEMESTER_FILTER_TO_NAME = [
        'First Semester' => '1st Semester',
        'Second Semester' => '2nd Semester',
        'Summer' => 'Summer',
    ];

    /**
     * Resolves the {academic_year, semester} filter strings this page
     * already works with into the actual AcademicTerm row the Faculty
     * Schedule Email System is keyed on. Returns null if either filter
     * is empty/unmatched (e.g. "All Terms").
     */
    private function resolveAcademicTerm(array $filters): ?AcademicTerm
    {
        $yearName = $filters['academic_year'] ?? null;
        $semesterName = $filters['semester'] ?? null;

        if (! $yearName || ! $semesterName) {
            return null;
        }

        $semesterName = self::SEMESTER_FILTER_TO_NAME[$semesterName] ?? $semesterName;

        $schoolYear = SchoolYear::query()->where('name', $yearName)->first();
        $semester = Semester::query()->where('name', $semesterName)->first();

        if (! $schoolYear || ! $semester) {
            return null;
        }

        return AcademicTerm::query()
            ->where('school_year_id', $schoolYear->id)
            ->where('semester_id', $semester->id)
            ->first();
    }

    private function table(string $title, Collection $rows, ?string $emptyMessage = null, ?array $summary = null): array
    {
        $rows = $rows->values();
        $columns = $rows->isNotEmpty() ? array_keys($rows->first()) : [];

        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $summary,
            'empty_message' => $emptyMessage ?? 'No data found for the selected filters.',
        ];
    }

    /**
     * Server-side port of Reports/Index.vue's gridDays/gridHourRows/
     * gridBlocks computeds — builds the same weekly-timetable data the
     * on-screen Grid view renders, so the printable copy (print.blade.php)
     * can lay it out as an actual grid instead of falling back to the
     * generic flat-table dump. Deliberately kept in lockstep with the
     * Vue version: same 30-min-row math, same day-presence filtering,
     * same same-cell merge collapsing — a printed grid should never
     * show something the on-screen Grid wouldn't.
     *
     * @param  array<int, array<string, mixed>>  $rows  Report rows (schedule_by_room or schedule_by_faculty's flat shape — Room/Subject/Section/Faculty/Day/Start/End, optionally carrying a 'Schedules' sub-array for split Face-to-Face/Online rows)
     * @param  array{start_time: string, end_time: string, available_days: array<int, string>, interval_minutes: int}  $schedulingWindow
     */
    public function buildGridData(array $rows, array $schedulingWindow, string $reportType): array
    {
        $toMinutes = fn (string $hhmm): int => (function () use ($hhmm) {
            [$h, $m] = array_pad(explode(':', $hhmm ?: '00:00'), 2, '0');

            return ((int) $h) * 60 + (int) $m;
        })();
        $toHHMM = fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        $formatHourLabel = function (string $hhmm) use ($toMinutes): string {
            $mins = $toMinutes($hhmm);
            $h = intdiv($mins, 60);
            $m = $mins % 60;
            $period = $h >= 12 ? 'PM' : 'AM';
            $h12 = $h % 12 === 0 ? 12 : $h % 12;

            return sprintf('%d:%02d %s', $h12, $m, $period);
        };
        // Report rows carry the already-12h-formatted "5:00 PM" string
        // (not a raw sortable "H:i" value) — parses it back into the
        // same minute space $toMinutes/hourRows use, same as
        // Index.vue's timeToMinutes12h().
        $timeToMinutes12h = function (?string $label): ?int {
            if (! $label || ! preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', trim($label), $m)) {
                return null;
            }
            $hours = ((int) $m[1]) % 12;
            if (strtoupper($m[3]) === 'PM') {
                $hours += 12;
            }

            return $hours * 60 + (int) $m[2];
        };

        $interval = $schedulingWindow['interval_minutes'] ?: 30;
        $start = $toMinutes($schedulingWindow['start_time'] ?: '07:00');
        $end = $toMinutes($schedulingWindow['end_time'] ?: '17:00');

        $hourRows = [];
        for ($t = $start; $t < $end; $t += $interval) {
            $hourRows[] = $toHHMM($t);
        }
        if (! $hourRows) {
            $hourRows = ['07:00', '07:30', '08:00'];
        }

        $allDays = $schedulingWindow['available_days'] ?: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        // Same rowSchedules() fallback as Index.vue: use the row's own
        // 'Schedules' sub-array when present (split Face-to-Face/Online),
        // otherwise treat the row's flat Room/Day/Start/End as its one
        // meeting.
        $rowSchedules = fn (array $row): array => ! empty($row['Schedules'])
            ? $row['Schedules']
            : [['Room' => $row['Room'] ?? null, 'Day' => $row['Day'] ?? null, 'Start' => $row['Start'] ?? null, 'End' => $row['End'] ?? null]];

        $present = [];
        foreach ($rows as $row) {
            foreach ($rowSchedules($row) as $schedule) {
                foreach (array_filter(array_map('trim', explode(',', $schedule['Day'] ?? ''))) as $day) {
                    $present[$day] = true;
                }
            }
        }
        $days = array_values(array_filter($allDays, fn ($d) => isset($present[$d])));

        $blocks = [];
        foreach ($rows as $row) {
            foreach ($rowSchedules($row) as $schedule) {
                $startMin = $timeToMinutes12h($schedule['Start'] ?? null);
                $endMin = $timeToMinutes12h($schedule['End'] ?? null);
                if ($startMin === null || $endMin === null) {
                    continue;
                }

                $startIndex = max(0, (int) round(($startMin - $start) / $interval));
                $span = max(1, (int) round(($endMin - $startMin) / $interval));

                foreach (array_filter(array_map('trim', explode(',', $schedule['Day'] ?? ''))) as $day) {
                    if (! in_array($day, $days, true)) {
                        continue;
                    }
                    $blocks[] = array_merge($row, $schedule, [
                        'day' => $day,
                        'startIndex' => $startIndex,
                        'span' => $span,
                    ]);
                }
            }
        }

        // Merged/shared sessions (a faculty-shortage merge running one
        // Online lecture for two independent Sections at once) — collapse
        // same-cell, same-meeting blocks into one, joining Sections with
        // "/" and flagging merged, same as Index.vue's gridBlocks merge
        // step.
        $mergedBlocks = [];
        $seen = [];
        foreach ($blocks as $block) {
            $key = implode('|', [$block['day'], $block['startIndex'], $block['span'], $block['Room'] ?? '', $block['Subject'] ?? '']);
            if (isset($seen[$key])) {
                $i = $seen[$key];
                $sections = explode('/', $mergedBlocks[$i]['Section'] ?? '');
                if (! in_array($block['Section'] ?? '', $sections, true)) {
                    $mergedBlocks[$i]['Section'] = ($mergedBlocks[$i]['Section'] ?? '').'/'.($block['Section'] ?? '');
                    $mergedBlocks[$i]['merged'] = true;
                }
                continue;
            }
            $seen[$key] = count($mergedBlocks);
            $mergedBlocks[] = $block;
        }

        // Grid cell label lines — Room report shows Subject/Section/
        // Faculty; Faculty report shows Subject/Section/Room, same as
        // Index.vue's gridCellLabel().
        foreach ($mergedBlocks as &$block) {
            $isOnline = ($block['Room'] ?? null) === 'Online';
            if ($reportType === 'schedule_by_room') {
                $block['line1'] = $block['Subject'] ?? null;
                $block['line2'] = $block['Section'] ?? null;
                $block['line3'] = $block['Faculty'] ?? null;
            } else {
                $block['line1'] = $block['Subject'] ?? null;
                $block['line2'] = $block['Section'] ?? null;
                $block['line3'] = ! empty($block['merged']) ? (($block['Room'] ?? '').'/Merge') : ($block['Room'] ?? null);
            }
            $block['online'] = $isOnline;
        }
        unset($block);

        return [
            'days' => $days,
            'hourRows' => array_map(fn ($hhmm) => [
                'value' => $hhmm,
                'label' => $formatHourLabel($hhmm).' – '.$formatHourLabel($toHHMM($toMinutes($hhmm) + $interval)),
            ], $hourRows),
            'blocks' => $mergedBlocks,
        ];
    }
}