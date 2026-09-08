<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Faculty;
use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use App\Support\AccessScope;
use App\Support\ViewingTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SchedulingController extends Controller
{
    /**
     * Display the Scheduling Dashboard — a read-only control center
     * summarizing scheduling progress, conflicts, and utilization for
     * the term this user is currently viewing. All actual schedule
     * editing happens on Scheduling > Sections > Section Subjects;
     * this page never writes to section_subjects itself.
     */
    public function index(Request $request): Response
    {
        $this->authorize('view-scheduling');

        // THIS user's Viewing Term (their session override if
        // Admin/Registrar switched it, else the real system-wide
        // Active term) — see App\Support\ViewingTerm. Everyone else
        // (Dean/OIC/Assistant Dean) always resolves straight to the
        // real Active term, same as before this feature existed.
        $activeTerm = ViewingTerm::resolve($request);
        $activeTerm?->loadMissing(['schoolYear:id,name', 'semester:id,name']);

        // Sections/SectionSubjects don't carry a foreign key to
        // AcademicTerm — they're scoped by the plain academic_year /
        // semester strings, matched via AcademicTerm::matchingSectionsQuery()
        // rather than a raw string compare (Sections spell Semester
        // "First Semester"/"Second Semester"; the Semester model spells
        // it "1st Semester"/"2nd Semester" — see that method's docblock).
        $sectionsQuery = $activeTerm ? $activeTerm->matchingSectionsQuery() : Section::query();
        $sectionsQuery->visibleTo(auth()->user());

        $sectionIds = (clone $sectionsQuery)->pluck('id');

        $totalSections = $sectionIds->count();

        $sectionSubjectsQuery = SectionSubject::query()->whereIn('section_id', $sectionIds);

        $totalSubjects = (clone $sectionSubjectsQuery)->count();
        $scheduledSubjects = (clone $sectionSubjectsQuery)->where('status', 'Scheduled')->count();
        $conflictSubjects = (clone $sectionSubjectsQuery)->where('status', 'Conflict')->count();
        $remainingSubjects = $totalSubjects - $scheduledSubjects;

        $completion = $totalSubjects > 0
            ? (int) round(($scheduledSubjects / $totalSubjects) * 100)
            : 0;

        $activeRoomsUsed = (clone $sectionSubjectsQuery)->whereNotNull('room_id')->distinct('room_id')->count('room_id');
        $activeFacultyAssigned = (clone $sectionSubjectsQuery)->whereNotNull('faculty_id')->distinct('faculty_id')->count('faculty_id');

        $noFacultyCount = (clone $sectionSubjectsQuery)->whereNull('faculty_id')->count();
        $noRoomCount = (clone $sectionSubjectsQuery)->whereNull('room_id')->count();

        $sectionsNeedingScheduling = (clone $sectionSubjectsQuery)
            ->where('status', '!=', 'Scheduled')
            ->distinct('section_id')
            ->count('section_id');

        // Faculty load: assigned units (sum of subject.units for
        // placements with a faculty attached) per faculty member.
        $facultyLoads = SectionSubject::query()
            ->whereIn('section_subjects.section_id', $sectionIds)
            ->whereNotNull('section_subjects.faculty_id')
            ->join('subjects', 'subjects.id', '=', 'section_subjects.subject_id')
            ->join('faculties', 'faculties.id', '=', 'section_subjects.faculty_id')
            ->groupBy('faculties.id', 'faculties.first_name', 'faculties.last_name', 'faculties.max_teaching_units')
            ->select([
                'faculties.id',
                'faculties.first_name',
                'faculties.last_name',
                'faculties.max_teaching_units',
                DB::raw('COALESCE(SUM(subjects.units), 0) as assigned_units'),
            ])
            ->orderByDesc('assigned_units')
            ->get();

        $facultyOverloadCount = $facultyLoads->filter(
            fn ($f) => $f->max_teaching_units && $f->assigned_units > $f->max_teaching_units
        )->count();

        $topFaculty = $facultyLoads->take(8)->map(fn ($f) => [
            'name' => trim("{$f->first_name} {$f->last_name}"),
            'units' => (int) $f->assigned_units,
            'max' => (int) ($f->max_teaching_units ?? 0),
        ])->values();

        // Room conflicts: same room, overlapping day tokens, overlapping time.
        $roomConflictCount = $this->countRoomConflicts($sectionIds);

        // Room utilization: how many scheduled meetings use each room,
        // relative to the busiest room (simple proxy for occupancy %).
        $roomUsage = SectionSubject::query()
            ->whereIn('section_subjects.section_id', $sectionIds)
            ->whereNotNull('section_subjects.room_id')
            ->join('rooms', 'rooms.id', '=', 'section_subjects.room_id')
            ->groupBy('rooms.id', 'rooms.room_code', 'rooms.room_name')
            ->select([
                'rooms.id',
                'rooms.room_code',
                'rooms.room_name',
                DB::raw('COUNT(*) as bookings'),
            ])
            ->orderByDesc('bookings')
            ->take(8)
            ->get();

        $maxBookings = max(1, $roomUsage->max('bookings') ?? 1);
        $topRooms = $roomUsage->map(fn ($r) => [
            'name' => $r->room_name ?: $r->room_code,
            'occupancy' => (int) round(($r->bookings / $maxBookings) * 100),
        ])->values();

        // Progress per college, via Section -> Major -> Department -> College.
        $collegeProgress = College::query()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['id', 'name', 'short_name'])
            ->map(function ($college) use ($sectionIds) {
                $ids = Section::query()
                    ->whereIn('id', $sectionIds)
                    ->whereHas('major.department', fn ($q) => $q->where('college_id', $college->id))
                    ->pluck('id');

                $total = SectionSubject::query()->whereIn('section_id', $ids)->count();
                $scheduled = SectionSubject::query()->whereIn('section_id', $ids)->where('status', 'Scheduled')->count();

                return [
                    'id' => $college->id,
                    'name' => $college->short_name ?: $college->name,
                    'total' => $total,
                    'scheduled' => $scheduled,
                    'percent' => $total > 0 ? (int) round(($scheduled / $total) * 100) : 0,
                ];
            })
            ->filter(fn ($c) => $c['total'] > 0)
            ->values();

        // Recent activity: most recently updated placements (Faculty/
        // Room/Time assignments) merged with Faculty roster events
        // (added, units changed, workload overridden — spec: Dean's
        // direct "Add Faculty"/"Add Units" should surface here too,
        // not just in Settings > Activity Log), newest first across
        // both sources.
        $placementActivity = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->with(['section:id,section_code', 'subject:id,subject_title'])
            ->orderByDesc('updated_at')
            ->take(8)
            ->get()
            ->map(fn ($ss) => [
                'label' => $ss->status === 'Scheduled'
                    ? "Scheduled {$ss->section?->section_code} — {$ss->subject?->subject_title}"
                    : "Updated {$ss->section?->section_code} — {$ss->subject?->subject_title}",
                'status' => $ss->status,
                'updated_at' => $ss->updated_at,
                'sort_at' => $ss->updated_at,
            ]);

        // Same college scoping as everything else on this dashboard —
        // null means unrestricted (Admin/Registrar see every college's
        // Faculty events too), a Dean/OIC/Assistant Dean only sees
        // their own college's (or, for Assistant Dean, the
        // no-college/GenEd pool's — see visibleCollegeIds()'s docblock).
        $visibleCollegeIds = AccessScope::visibleCollegeIds(auth()->user());

        $facultyActivityQuery = ActivityLog::query()
            ->where('subject_type', Faculty::class)
            ->whereIn('action', [ActivityLogService::FACULTY_CREATED, ActivityLogService::FACULTY_UPDATED])
            ->with('subject')
            ->orderByDesc('created_at')
            ->take(8)
            ->get();

        if ($visibleCollegeIds !== null) {
            $facultyActivityQuery = $facultyActivityQuery->filter(
                fn ($log) => $log->subject && in_array($log->subject->college_id, $visibleCollegeIds, true)
            );
        }

        $facultyActivity = $facultyActivityQuery
            ->map(fn ($log) => [
                'label' => $log->description,
                'status' => null,
                'updated_at' => $log->created_at,
                'sort_at' => $log->created_at,
            ]);

        $recentActivity = $placementActivity
            ->concat($facultyActivity)
            ->sortByDesc('sort_at')
            ->take(8)
            ->map(fn ($item) => [
                'label' => $item['label'],
                'status' => $item['status'],
                'updated_at' => optional($item['updated_at'])->diffForHumans(),
            ])
            ->values();

        return Inertia::render('Scheduling/Index', [
            'activeTerm' => $activeTerm ? [
                'school_year' => $activeTerm->schoolYear?->name,
                'semester' => $activeTerm->semester?->name,
            ] : null,
            'stats' => [
                'total_sections' => $totalSections,
                'total_subjects' => $totalSubjects,
                'scheduled_subjects' => $scheduledSubjects,
                'remaining_subjects' => $remainingSubjects,
                'completion' => $completion,
                'active_rooms' => $activeRoomsUsed,
                'active_faculty' => $activeFacultyAssigned,
            ],
            'alerts' => [
                'no_faculty' => $noFacultyCount,
                'no_room' => $noRoomCount,
                'faculty_overload' => $facultyOverloadCount,
                'room_conflicts' => $roomConflictCount,
                'sections_needing_scheduling' => $sectionsNeedingScheduling,
                'conflict_subjects' => $conflictSubjects,
            ],
            'collegeProgress' => $collegeProgress,
            'topFaculty' => $topFaculty,
            'topRooms' => $topRooms,
            'recentActivity' => $recentActivity,
        ]);
    }

    /**
     * Naive room-conflict counter: counts placement pairs that share a
     * room with an overlapping day token and overlapping time range.
     * Used only for the dashboard alert count — the authoritative
     * conflict check lives in the scheduling engine's own validation.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $sectionIds
     */
    private function countRoomConflicts($sectionIds): int
    {
        $placements = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->whereNotNull('room_id')
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get(['id', 'room_id', 'days', 'start_time', 'end_time']);

        $conflicts = 0;

        foreach ($placements->groupBy('room_id') as $roomPlacements) {
            $list = $roomPlacements->values();

            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    $a = $list[$i];
                    $b = $list[$j];

                    $daysA = array_filter(explode(',', (string) $a->days));
                    $daysB = array_filter(explode(',', (string) $b->days));

                    if (empty(array_intersect($daysA, $daysB))) {
                        continue;
                    }

                    if ($a->start_time < $b->end_time && $b->start_time < $a->end_time) {
                        $conflicts++;
                    }
                }
            }
        }

        return $conflicts;
    }
}