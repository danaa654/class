<?php

use App\Http\Controllers\AcademicCalendarController;
use App\Http\Controllers\AcademicStructureController;
use App\Http\Controllers\AcademicTermController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\CollegeController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\CurriculumSubjectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\FacultyScheduleEmailController;
use App\Http\Controllers\FacultyLoadRequestController;
use App\Http\Controllers\FacultyRequestController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomRecommendationController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SectionSubjectController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\ActiveSessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeachingQualificationController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\ViewingTermController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WelcomeController::class, 'index'])->name('welcome');

// Guest-only authentication routes.
// Registration and email verification are intentionally NOT included —
// accounts are created only by the Administrator. Password reset IS
// included so users who forget their password can recover access
// without needing an Administrator to reset it for them.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

// Authenticated routes.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [UsersController::class, 'index'])->name('users');
    Route::post('/users', [UsersController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/status', [UsersController::class, 'updateStatus'])->name('users.status');
    Route::patch('/users/{user}/must-change-password', [UsersController::class, 'updateMustChangePassword'])->name('users.must-change-password');
    Route::delete('/users/{user}', [UsersController::class, 'destroy'])->name('users.destroy');
    Route::put('/account', [UsersController::class, 'updateAccount'])->name('account.update');
    Route::get('/academic-calendar', [AcademicCalendarController::class, 'index'])->name('academic-calendar');
    Route::post('/school-years', [SchoolYearController::class, 'store'])->name('school-years.store');
    Route::put('/school-years/{schoolYear}', [SchoolYearController::class, 'update'])->name('school-years.update');
    Route::delete('/school-years/{schoolYear}', [SchoolYearController::class, 'destroy'])->name('school-years.destroy');
    Route::put('/school-years/{schoolYear}/restore', [SchoolYearController::class, 'restore'])->name('school-years.restore');
    Route::post('/semesters', [SemesterController::class, 'store'])->name('semesters.store');
    Route::put('/semesters/{semester}', [SemesterController::class, 'update'])->name('semesters.update');
    Route::delete('/semesters/{semester}', [SemesterController::class, 'destroy'])->name('semesters.destroy');
    Route::put('/semesters/{semester}/restore', [SemesterController::class, 'restore'])->name('semesters.restore');
    Route::post('/academic-terms', [AcademicTermController::class, 'store'])->name('academic-terms.store');
    Route::put('/academic-terms/{academicTerm}', [AcademicTermController::class, 'update'])->name('academic-terms.update');
    Route::delete('/academic-terms/{academicTerm}', [AcademicTermController::class, 'destroy'])->name('academic-terms.destroy');
    Route::put('/academic-terms/{academicTerm}/restore', [AcademicTermController::class, 'restore'])->name('academic-terms.restore');
    Route::put('/academic-terms/{academicTerm}/archive', [AcademicTermController::class, 'archive'])->name('academic-terms.archive');
    // Per-user "Viewing Academic Term" switch — Admin/Registrar only
    // (enforced again inside the controller). Changes what THIS user
    // sees (Dashboard/Reports/Settings/Sections default) without
    // touching the real Active term or any other user's session.
    Route::put('/viewing-term', [ViewingTermController::class, 'update'])->name('viewing-term.update');
    Route::delete('/viewing-term', [ViewingTermController::class, 'destroy'])->name('viewing-term.destroy');
    Route::get('/academic-structure', [AcademicStructureController::class, 'index'])->name('academic-structure');
    Route::post('/colleges', [CollegeController::class, 'store'])->name('colleges.store');
    Route::put('/colleges/{college}', [CollegeController::class, 'update'])->name('colleges.update');
    Route::delete('/colleges/{college}', [CollegeController::class, 'destroy'])->name('colleges.destroy');
    Route::put('/colleges/{college}/restore', [CollegeController::class, 'restore'])->name('colleges.restore');
    Route::delete('/colleges/{college}/force', [CollegeController::class, 'forceDelete'])->name('colleges.force-delete');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::put('/departments/{department}/restore', [DepartmentController::class, 'restore'])->name('departments.restore');
    Route::delete('/departments/{department}/force', [DepartmentController::class, 'forceDelete'])->name('departments.force-delete');
    Route::post('/majors', [MajorController::class, 'store'])->name('majors.store');
    Route::put('/majors/{major}', [MajorController::class, 'update'])->name('majors.update');
    Route::delete('/majors/{major}', [MajorController::class, 'destroy'])->name('majors.destroy');
    Route::put('/majors/{major}/restore', [MajorController::class, 'restore'])->name('majors.restore');
    Route::delete('/majors/{major}/force', [MajorController::class, 'forceDelete'])->name('majors.force-delete');
    Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects');
    Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
    Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
    Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');
    // Bulk Import — spec: "adding a new curriculum one subject at a
    // time by hand is a hassle". The template download and the import
    // POST both sit under the same middleware group (auth + role
    // gate) as the rest of Subjects, and the import itself is
    // authorized per-row exactly like a manual Add Subject would be
    // (see SubjectController::import()).
    Route::get('/subjects/import/template', [SubjectController::class, 'importTemplate'])->name('subjects.import.template');
    Route::post('/subjects/import/preview', [SubjectController::class, 'preview'])->name('subjects.import.preview');
    Route::post('/subjects/import', [SubjectController::class, 'import'])->name('subjects.import');
    Route::get('/curriculums', [CurriculumController::class, 'index'])->name('curriculums');
    Route::post('/curriculums', [CurriculumController::class, 'store'])->name('curriculums.store');
    Route::put('/curriculums/{curriculum}', [CurriculumController::class, 'update'])->name('curriculums.update');
    Route::delete('/curriculums/{curriculum}', [CurriculumController::class, 'destroy'])->name('curriculums.destroy');
    Route::put('/curriculums/{curriculum}/restore', [CurriculumController::class, 'restore'])->name('curriculums.restore');
    Route::get('/curriculums/{curriculum}/subjects', [CurriculumSubjectController::class, 'index'])->name('curriculums.subjects');
    Route::post('/curriculums/{curriculum}/subjects', [CurriculumSubjectController::class, 'store'])->name('curriculums.subjects.store');
    Route::put('/curriculums/{curriculum}/subjects/{curriculumItem}', [CurriculumSubjectController::class, 'update'])->name('curriculums.subjects.update');
    Route::delete('/curriculums/{curriculum}/subjects/{curriculumItem}', [CurriculumSubjectController::class, 'destroy'])->name('curriculums.subjects.destroy');
    Route::get('/scheduling', [SchedulingController::class, 'index'])->name('scheduling');
    Route::get('/scheduling/faculty', [FacultyController::class, 'index'])->name('scheduling.faculty');
    // Bulk Import — Faculty Master (mirrors Room Master's/Subject
    // Library's Bulk Import). Placed above the {faculty} routes so
    // "import" is never mistaken for a faculty identifier.
    Route::get('/scheduling/faculty/import/template', [FacultyController::class, 'importTemplate'])->name('scheduling.faculty.import.template');
    Route::post('/scheduling/faculty/import/preview', [FacultyController::class, 'preview'])->name('scheduling.faculty.import.preview');
    Route::post('/scheduling/faculty/import', [FacultyController::class, 'import'])->name('scheduling.faculty.import');
    Route::post('/scheduling/faculty', [FacultyController::class, 'store'])->name('scheduling.faculty.store');
    Route::get('/scheduling/faculty/{faculty}', [FacultyController::class, 'show'])->name('scheduling.faculty.show');
    Route::put('/scheduling/faculty/{faculty}', [FacultyController::class, 'update'])->name('scheduling.faculty.update');
    Route::delete('/scheduling/faculty/{faculty}', [FacultyController::class, 'destroy'])->name('scheduling.faculty.destroy');

    // Faculty load change requests — Dean/OIC/Assistant Dean's only
    // path to raising a faculty member's teaching load ceiling; Admin/
    // Registrar review via {facultyLoadRequest}/review. The queue now
    // renders as a section on the Faculty page itself
    // (FacultyController@index) rather than its own screen, so there
    // is no GET index route here anymore — just store/review.
    Route::post('/scheduling/faculty-load-requests', [FacultyLoadRequestController::class, 'store'])->name('scheduling.faculty-load-requests.store');
    Route::put('/scheduling/faculty-load-requests/{facultyLoadRequest}/review', [FacultyLoadRequestController::class, 'review'])->name('scheduling.faculty-load-requests.review');
    Route::delete('/scheduling/faculty-load-requests/{facultyLoadRequest}', [FacultyLoadRequestController::class, 'destroy'])->name('scheduling.faculty-load-requests.destroy');

    // Faculty Management requests — Dean/OIC/Assistant Dean's only path
    // to creating or deactivating a Faculty member; Admin/Registrar
    // review via {facultyRequest}/review. Queue renders as a section on
    // the Faculty page itself (FacultyController@index).
    Route::post('/scheduling/faculty-requests', [FacultyRequestController::class, 'storeCreation'])->name('scheduling.faculty-requests.store-creation');
    Route::post('/scheduling/faculty/{faculty}/deactivation-request', [FacultyRequestController::class, 'storeDeactivation'])->name('scheduling.faculty-requests.store-deactivation');
    Route::put('/scheduling/faculty-requests/{facultyRequest}/review', [FacultyRequestController::class, 'review'])->name('scheduling.faculty-requests.review');
    Route::delete('/scheduling/faculty-requests/{facultyRequest}', [FacultyRequestController::class, 'cancel'])->name('scheduling.faculty-requests.cancel');

    Route::put('/scheduling/teaching-qualifications/{faculty}', [TeachingQualificationController::class, 'update'])->name('scheduling.teaching-qualifications.update');
    Route::get('/scheduling/rooms', [RoomController::class, 'index'])->name('scheduling.rooms');
    Route::get('/scheduling/rooms/{room}/schedule', [RoomController::class, 'schedule'])->name('scheduling.rooms.schedule');
    // Bulk Import — Room Master (mirrors Subject Library's Bulk
    // Import). Placed above the {room} routes so "import" is never
    // mistaken for a room identifier.
    Route::get('/scheduling/rooms/import/template', [RoomController::class, 'importTemplate'])->name('scheduling.rooms.import.template');
    Route::post('/scheduling/rooms/import/preview', [RoomController::class, 'preview'])->name('scheduling.rooms.import.preview');
    Route::post('/scheduling/rooms/import', [RoomController::class, 'import'])->name('scheduling.rooms.import');
    Route::post('/scheduling/rooms', [RoomController::class, 'store'])->name('scheduling.rooms.store');
    Route::put('/scheduling/rooms/{room}', [RoomController::class, 'update'])->name('scheduling.rooms.update');
    Route::delete('/scheduling/rooms/{room}', [RoomController::class, 'destroy'])->name('scheduling.rooms.destroy');
    // Room Recommendation — "Recommended Subjects" on the Room Details
    // page (source of truth for room-subject soft preferences, read
    // by RecommendationService::recommendRooms() as a scoring bonus).
    Route::get('/scheduling/rooms/{room}/recommendations', [RoomRecommendationController::class, 'index'])->name('scheduling.rooms.recommendations');
    Route::get('/scheduling/rooms/{room}/recommendations/subjects', [RoomRecommendationController::class, 'searchableSubjects'])->name('scheduling.rooms.recommendations.subjects');
    Route::post('/scheduling/rooms/{room}/recommendations', [RoomRecommendationController::class, 'store'])->name('scheduling.rooms.recommendations.store');
    Route::delete('/scheduling/rooms/{room}/recommendations/{recommendation}', [RoomRecommendationController::class, 'destroy'])->name('scheduling.rooms.recommendations.destroy');
    Route::get('/scheduling/sections', [SectionController::class, 'index'])->name('scheduling.sections');
    Route::post('/scheduling/sections', [SectionController::class, 'store'])->name('scheduling.sections.store');
    Route::post('/scheduling/sections/preview-batch', [SectionController::class, 'previewBatch'])->name('scheduling.sections.preview-batch');
    Route::post('/scheduling/sections/curriculum-subjects-preview', [SectionController::class, 'curriculumSubjectsPreview'])->name('scheduling.sections.curriculum-subjects-preview');
    Route::post('/scheduling/sections/manual-subjects-preview', [SectionController::class, 'manualSubjectsPreview'])->name('scheduling.sections.manual-subjects-preview');
    Route::post('/scheduling/sections/batch', [SectionController::class, 'storeBatch'])->name('scheduling.sections.store-batch');
    Route::put('/scheduling/sections/{section}', [SectionController::class, 'update'])->name('scheduling.sections.update');
    Route::delete('/scheduling/sections/{section}', [SectionController::class, 'destroy'])->name('scheduling.sections.destroy');
    // Archived-section detection + restore for the Add Section modal
    // (SectionController::checkArchived()/restore()) — {section} here
    // is intentionally NOT route-model-bound, since the whole point of
    // both endpoints is finding/acting on a SOFT-DELETED Section that
    // Laravel's default implicit binding would 404 on.
    Route::post('/scheduling/sections/check-archived', [SectionController::class, 'checkArchived'])->name('scheduling.sections.check-archived');
    Route::put('/scheduling/sections/{section}/restore', [SectionController::class, 'restore'])->name('scheduling.sections.restore');
    Route::post('/scheduling/sections/{section}/finalize', [SectionController::class, 'finalize'])->name('scheduling.sections.finalize');
    Route::post('/scheduling/sections/{section}/unlock', [SectionController::class, 'unlock'])->name('scheduling.sections.unlock');
    Route::get('/scheduling/section-subjects', [SectionSubjectController::class, 'index'])->name('scheduling.section-subjects');
    Route::get('/scheduling/section-subjects/{section}', [SectionSubjectController::class, 'show'])->name('scheduling.section-subjects.show');
    // REAL-TIME SCHEDULE CHANGE DETECTION — lightweight polling target.
    // Returns only { section_id, schedule_version, updated_at }, never
    // the schedule itself, so the frontend can poll cheaply while
    // viewing scheduling.section-subjects.show. See
    // SectionSubjectController::scheduleVersion().
    Route::get('/scheduling/section-subjects/{section}/version', [SectionSubjectController::class, 'scheduleVersion'])->name('scheduling.section-subjects.version');
    Route::post('/scheduling/section-subjects/{section}/generate-curriculum', [SectionSubjectController::class, 'generateCurriculumSubjects'])->name('scheduling.section-subjects.generate-curriculum');
    Route::get('/scheduling/section-subjects/{section}/curriculum-preview', [SectionSubjectController::class, 'curriculumPreview'])->name('scheduling.section-subjects.curriculum-preview');
    Route::post('/scheduling/section-subjects/{section}', [SectionSubjectController::class, 'store'])->name('scheduling.section-subjects.store');
    Route::delete('/scheduling/section-subjects/{section}/{subject}', [SectionSubjectController::class, 'destroy'])->name('scheduling.section-subjects.destroy');
    // Inline scheduling-cell auto-save (Faculty/Room/Days/Time/Capacity) — the
    // Section Subjects page (scheduling.section-subjects.show) IS the
    // scheduling workspace; there is no separate workspace page/route.
    Route::patch('/scheduling/section-subjects/{section}/{subject}/schedule', [SectionSubjectController::class, 'updateSchedule'])->name('scheduling.section-subjects.schedule');
    // Room Grid drag-and-drop — moves a schedule block that may belong
    // to a DIFFERENT Section than the one currently open (spec: "Room
    // Grid drag-and-drop revision"). Deliberately not nested under
    // {section} — see SectionSubjectController::moveRoomGridAssignment()'s
    // docblock for why authorization here is per-assignment, not
    // per-currently-viewed-Section.
    Route::patch('/scheduling/room-grid/section-subjects/{subject}/move', [SectionSubjectController::class, 'moveRoomGridAssignment'])->name('scheduling.room-grid.move');
    // Smart Assignment Recommendation Engine (Prompt 8.6) — ranked
    // Faculty/Room/Time suggestions for one row, never auto-assigns.
    Route::get('/scheduling/section-subjects/{section}/{subject}/recommend', [SectionSubjectController::class, 'recommend'])->name('scheduling.section-subjects.recommend');
    Route::get('/scheduling/section-subjects/{section}/{subject}/faculty-options', [SectionSubjectController::class, 'facultyOptions'])->name('scheduling.section-subjects.faculty-options');
    Route::post('/scheduling/section-subjects/{section}/{subject}/faculty-override', [SectionSubjectController::class, 'overrideFaculty'])->name('scheduling.section-subjects.faculty-override');
    Route::get('/scheduling/section-subjects/{section}/{subject}/room-options', [SectionSubjectController::class, 'roomOptions'])->name('scheduling.section-subjects.room-options');
    Route::post('/scheduling/section-subjects/{section}/{subject}/room-override', [SectionSubjectController::class, 'overrideRoom'])->name('scheduling.section-subjects.room-override');
    // SPLIT-DELIVERY SCHEDULING — turn one subject's schedule row into
    // a Face-to-Face row + an Online row (split), or collapse a
    // previously-split pair back into one row (unsplit).
    Route::post('/scheduling/section-subjects/{section}/{subject}/split', [SectionSubjectController::class, 'splitSchedule'])->name('scheduling.section-subjects.split');
    Route::post('/scheduling/section-subjects/{section}/{subject}/unsplit', [SectionSubjectController::class, 'unsplitSchedule'])->name('scheduling.section-subjects.unsplit');
    // Busy Time Ranges — for the row's selected Room/Faculty + Days,
    // every already-booked Start/End Time window, so the Start/End
    // Time dropdowns can grey out slots that would conflict before
    // the Registrar even picks one.
    Route::get('/scheduling/section-subjects/{section}/{subject}/busy-times', [SectionSubjectController::class, 'busyTimes'])->name('scheduling.section-subjects.busy-times');

    // Room Scheduler (Room-Centric Time Grid) — read-only for now; the
    // drag/drop write path is a later slice of this feature. Nested
    // under {section} so the controller-wide manageScheduling
    // middleware (see SectionSubjectController::middleware()) applies
    // the same as every other action here, even though the returned
    // room schedule itself spans every Section using that Room.
    Route::get('/scheduling/section-subjects/{section}/rooms', [SectionSubjectController::class, 'roomOptionsForGrid'])->name('scheduling.section-subjects.rooms');
    Route::get('/scheduling/section-subjects/{section}/rooms/{room}/schedule', [SectionSubjectController::class, 'roomSchedule'])->name('scheduling.section-subjects.room-schedule');
    Route::post('/scheduling/section-subjects/{section}/{subject}/time-override', [SectionSubjectController::class, 'overrideTime'])->name('scheduling.section-subjects.time-override');
    // Smart Day & Time Recommendation modal — ranked, conflict-free
    // alternatives when a manually-picked Day/Time fails validation.
    // Reuses RecommendationService::recommendTimes() (the exact same
    // engine Auto Generate itself uses), never a separate simplified
    // algorithm — see SectionSubjectController::timeRecommendations().
    Route::get('/scheduling/section-subjects/{section}/{subject}/time-recommendations', [SectionSubjectController::class, 'timeRecommendations'])->name('scheduling.section-subjects.time-recommendations');

    // INTELLIGENT IRREGULAR SECTION SCHEDULING — merge recommendation
    // modal + Administrator override actions (see IrregularSectionMergeService).
    Route::get('/scheduling/section-subjects/{section}/{subject}/merge-recommendation', [SectionSubjectController::class, 'mergeRecommendation'])->name('scheduling.section-subjects.merge-recommendation');
    Route::get('/scheduling/section-subjects/{section}/{subject}/reverse-merge-recommendation', [SectionSubjectController::class, 'reverseMergeRecommendation'])->name('scheduling.section-subjects.reverse-merge-recommendation');
    Route::post('/scheduling/section-subjects/{section}/{subject}/merge', [SectionSubjectController::class, 'applyMerge'])->name('scheduling.section-subjects.merge');
    Route::post('/scheduling/section-subjects/{section}/{subject}/schedule-independently', [SectionSubjectController::class, 'scheduleIndependently'])->name('scheduling.section-subjects.schedule-independently');

    // ⚡ Auto Generate Schedule (Prompt 8.9).
    Route::post('/scheduling/section-subjects/{section}/auto-generate', [SectionSubjectController::class, 'autoGenerate'])->name('scheduling.section-subjects.auto-generate');
    Route::post('/scheduling/section-subjects/{section}/auto-generate/regenerate', [SectionSubjectController::class, 'regenerateSchedule'])->name('scheduling.section-subjects.auto-generate.regenerate');
    Route::post('/scheduling/section-subjects/{section}/auto-generate/clear', [SectionSubjectController::class, 'clearAutoGenerated'])->name('scheduling.section-subjects.auto-generate.clear');
    // "Save Schedule" batch save — the Registrar edits Faculty/Room/Days/
    // Start/End Time across multiple rows locally in the table, then
    // submits every row at once here; all rows save in a single
    // transaction (Prompt 8.4 — Manual Scheduling Per Subject).
    Route::post('/scheduling/section-subjects/{section}/schedule/batch', [SectionSubjectController::class, 'batchUpdateSchedule'])->name('scheduling.section-subjects.schedule.batch');
    Route::post('/scheduling/section-subjects/{section}/schedule/clear', [SectionSubjectController::class, 'clearSchedule'])->name('scheduling.section-subjects.schedule.clear');
    // SCHEDULING NOTIFICATION SYSTEM (spec Section 11) — polling API
    // + page. All read/mark-read only; notifications themselves are
    // only ever created server-side by NotificationService from
    // inside the scheduling operations that trigger them.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::get('/notifications/{notification}/redirect', [NotificationController::class, 'redirect'])->name('notifications.redirect');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
    // Server-rendered, branded printable version — opens in its own
    // tab from Reports/Index.vue's Print button rather than printing
    // the SPA page itself. See ReportsController::print().
    Route::get('/reports/print', [ReportsController::class, 'print'])->name('reports.print');

    // FACULTY SCHEDULE EMAIL SYSTEM — "Send via Email" on the Schedule
    // by Faculty report (see FacultyScheduleEmailController /
    // FacultyScheduleEmailService). Sits alongside the read-only
    // ReportsController routes above.
    Route::post('/reports/faculty-schedule/send', [FacultyScheduleEmailController::class, 'send'])->name('reports.faculty-schedule.send');
    Route::post('/reports/faculty-schedule/bulk-send', [FacultyScheduleEmailController::class, 'bulkSend'])->name('reports.faculty-schedule.bulk-send');
    Route::post('/reports/faculty-schedule/{facultyScheduleEmail}/resend', [FacultyScheduleEmailController::class, 'resend'])->name('reports.faculty-schedule.resend');
    Route::get('/reports/faculty-schedule/{faculty}/history', [FacultyScheduleEmailController::class, 'history'])->name('reports.faculty-schedule.history');

    // SETTINGS — system-wide configuration only (see SettingsController
    // and App\Services\SettingsService). GET renders the page; each PUT
    // saves one tab's group of settings; the POST is a non-destructive
    // "refresh configuration cache" action for Administrators.
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
    Route::put('/settings/workload', [SettingsController::class, 'updateWorkload'])->name('settings.workload.update');
    Route::put('/settings/rooms', [SettingsController::class, 'updateRooms'])->name('settings.rooms.update');
    Route::put('/settings/auto-schedule', [SettingsController::class, 'updateAutoSchedule'])->name('settings.autoschedule.update');
    Route::put('/settings/irregular', [SettingsController::class, 'updateIrregular'])->name('settings.irregular.update');
    Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::post('/settings/system/refresh-cache', [SettingsController::class, 'refreshCache'])->name('settings.system.refresh-cache');

    // ACTIVE SESSIONS — Administrator-only "who's logged in" tab on
    // the Settings page. See ActiveSessionController::activeSessions()
    // (called from SettingsController::index()) and ::destroy() below
    // for the force-logout action.
    Route::delete('/settings/active-sessions/{session}', [ActiveSessionController::class, 'destroy'])->name('settings.active-sessions.destroy');

    // SECURITY / PASSWORD POLICY — the forced change-password screen
    // EnsurePasswordIsCurrent redirects to. Must live inside this auth
    // group (the user is already authenticated) and its route names
    // must match EnsurePasswordIsCurrent::EXEMPT_ROUTES exactly, or the
    // middleware will redirect this very page back to itself in a loop.
    Route::get('/password/change', [ChangePasswordController::class, 'create'])->name('password.change');
    Route::put('/password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Catch-all for any URL that doesn't match a route above (stale links,
// bookmarks to removed pages, typos, etc). Instead of Laravel's raw 404
// page, send the user somewhere useful: logged-in users go to their
// dashboard, guests go to the login page.
Route::fallback(function () {
    return redirect(auth()->check() ? route('dashboard') : route('login'));
});