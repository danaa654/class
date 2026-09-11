<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcademicCalendarController extends Controller
{
    /**
     * Display the Academic Calendar page.
     *
     * School Years and Semesters are no longer managed as separate
     * tabs on this page — everything (School Year via Start/End Year,
     * Semester, Status, Remarks, and Scheduling Preferences) is
     * entered in one place: the Add/Edit Academic Term dialog. The
     * School Year and Semester tables themselves are untouched and
     * still power the rest of the app (Sections, Faculty Loading,
     * Subject Offerings, Planning Term, etc.) — see
     * AcademicTermController@resolveSchoolYear for how a School Year
     * gets created/updated from this form.
     */
    public function index(Request $request): Response
    {
        $this->authorize('manage-academic-calendar');

        $academicTermSearch = trim((string) $request->query('academic_term_search', ''));

        $academicTerms = AcademicTerm::query()
            ->withTrashed()
            ->with(['schoolYear', 'semester'])
            ->when($academicTermSearch !== '', function ($query) use ($academicTermSearch) {
                $query->where(function ($inner) use ($academicTermSearch) {
                    $inner->whereHas('schoolYear', function ($sy) use ($academicTermSearch) {
                        $sy->where('name', 'like', "%{$academicTermSearch}%");
                    })->orWhereHas('semester', function ($sem) use ($academicTermSearch) {
                        $sem->where('name', 'like', "%{$academicTermSearch}%")
                            ->orWhere('short_name', 'like', "%{$academicTermSearch}%");
                    });
                });
            })
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'academic_term_page')
            ->withQueryString();

        // END SEMESTER READINESS / MISSING OFFERINGS — mirrors
        // AcademicTermController::index() exactly (that copy is now
        // dead code kept only for its docblocks/history; THIS
        // controller is the one actually bound to the
        // /academic-calendar route, so this is the transform that
        // really needs to run). See that method's comments for the
        // full reasoning — kept brief here to avoid duplicating the
        // whole essay twice.
        $academicTerms->getCollection()->transform(function (AcademicTerm $academicTerm) {
            $unfinalizedSectionCodes = $academicTerm->matchingSectionsQuery()
                ->where('is_finalized', false)
                ->orderBy('section_code')
                ->limit(20)
                ->pluck('section_code');

            $totalUnfinalized = $academicTerm->matchingSectionsQuery()
                ->where('is_finalized', false)
                ->count();

            // TOTAL SECTIONS — a term with zero Sections at all used to
            // sail through the finalization gate ("nothing to protect")
            // and only get caught by the softer missing-offerings
            // reason-prompt below. That's now backed up by a hard gate
            // in archive() itself: End Semester requires at least one
            // Section to exist, full stop. Exposed here so the button
            // can be hidden (not just eventually rejected) for an empty
            // term, same pattern as all_sections_finalized above.
            $totalSections = $academicTerm->matchingSectionsQuery()->count();

            $academicTerm->all_sections_finalized = $totalUnfinalized === 0;
            $academicTerm->unfinalized_sections_count = $totalUnfinalized;
            $academicTerm->unfinalized_section_codes = $unfinalizedSectionCodes;
            $academicTerm->has_sections = $totalSections > 0;
            $academicTerm->missing_offerings = $academicTerm->missingOfferings();

            return $academicTerm;
        });

        // Semester dropdown source for the Academic Term form is
        // hardcoded on the frontend (Semester::NAMES) — no query
        // needed here. AcademicTermController@resolveSemester creates
        // the matching Semester record behind the scenes the first
        // time each one is actually used, so nothing needs seeding.

        return Inertia::render('AcademicCalendar/Index', [
            'academicTerms' => $academicTerms,
            'filters' => [
                'academic_term_search' => $academicTermSearch,
            ],
            // Scheduling Preferences options/defaults for the
            // Academic Term Add/Edit dialog — kept here so the
            // frontend never has to hardcode the Lunch Break window,
            // the Day list, or the Time Interval choices.
            'schedulingSettingsOptions' => [
                'days' => SchoolYear::ALL_DAYS,
                'default_days' => SchoolYear::DEFAULT_CLASS_DAYS,
                'default_class_start_time' => SchoolYear::DEFAULT_CLASS_START_TIME,
                'default_class_end_time' => SchoolYear::DEFAULT_CLASS_END_TIME,
                'lunch_break_start' => SchoolYear::LUNCH_BREAK_START,
                'lunch_break_end' => SchoolYear::LUNCH_BREAK_END,
            ],
        ]);
    }
}