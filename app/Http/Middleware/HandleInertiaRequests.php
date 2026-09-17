<?php

namespace App\Http\Middleware;

use App\Models\AcademicTerm;
use App\Models\Notification;
use App\Services\SettingsService;
use App\Support\ViewingTerm;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Role names for the logged-in user, shared globally so
                // any page/layout can gate UI (e.g. the admin-only
                // "Manage Account" tab on User Management) without each
                // controller having to remember to pass it separately.
                'roles' => fn () => $user?->getRoleNames() ?? [],
                // Coarse module-level abilities for SIDEBAR/BUTTON
                // visibility only. This is a UI convenience, never the
                // authorization boundary itself — every route/action
                // these gate is independently re-checked server-side
                // (Policies + Gate::define in AppServiceProvider) on
                // every request, so spoofing these client-side changes
                // nothing (spec Section 21/23).
                'can' => fn () => $user ? [
                    'manageUsers' => $user->can('manage-users'),
                    'manageAcademicStructure' => $user->can('manage-academic-structure'),
                    'manageAcademicCalendar' => $user->can('manage-academic-calendar'),
                    'manageCurriculum' => $user->can('manage-curriculum'),
                    'manageSettings' => $user->can('manage-settings'),
                    'viewReports' => $user->can('view-reports'),
                    'viewScheduling' => $user->can('view-scheduling'),
                    'runAutoSchedule' => $user->can('run-auto-schedule'),
                    'manageFaculty' => $user->can('create', \App\Models\Faculty::class),
                    // Gates the Maximum Teaching Units / Weekly Hours
                    // fields in the Faculty form. Admin/Registrar only
                    // — everyone else sees them disabled and is routed
                    // to the "Request Load Increase" flow instead. See
                    // FacultyPolicy::changeMaxLoad() and
                    // FacultyController::update()/store() for the
                    // server-side enforcement this UI hint mirrors.
                    'changeFacultyMaxLoad' => $user->can('changeMaxLoad', \App\Models\Faculty::class),
                    'manageRooms' => $user->can('create', \App\Models\Room::class),
                    'manageSections' => $user->can('create', \App\Models\Section::class),
                    // Section-level schedule finalization/unlock —
                    // symmetric (SectionPolicy::finalize()/
                    // unlockSchedule()). Both are open to
                    // Registrar/Admin AND a Dean/OIC over their own
                    // College/Department scope (the Sections index is
                    // already scoped per-request, so a page-wide flag
                    // is safe here) — a Dean/OIC can finalize and
                    // later unlock their own College's Sections, per
                    // adviser guidance. Gates the Finalize/Unlock
                    // buttons in Sections/Index.vue; the real
                    // enforcement is still the Policy + Gate on every
                    // request, this is UI-visibility only.
                    'finalizeSchedule' => \App\Support\AccessScope::isUnrestricted($user) || \App\Support\AccessScope::isCollegeScoped($user),
                    'unlockSchedule' => \App\Support\AccessScope::isUnrestricted($user) || \App\Support\AccessScope::isCollegeScoped($user),
                ] : [],
                // The College a Dean/OIC is scoped to (null for
                // unrestricted/Assistant Dean roles). Lets the
                // frontend show "no College assigned" messaging (spec
                // Section 26) without re-deriving the role logic.
                'collegeId' => fn () => \App\Support\AccessScope::collegeId($user),
                'hasNoAssignedCollege' => fn () => \App\Support\AccessScope::hasNoAssignedCollege($user),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Deletion-impact payload for the double-confirmation
                // flow — see FacultyController::destroy() and
                // Faculty/Index.vue's onDeleteFaculty().
                'facultyDeletionImpact' => fn () => $request->session()->get('facultyDeletionImpact'),
                // Same double-confirmation pattern for Room deletion —
                // see RoomController::destroy() and
                // RoomUtilizationService::deletionImpact().
                'roomDeletionImpact' => fn () => $request->session()->get('roomDeletionImpact'),
                // Per-row failure detail from the Subject Library's
                // Bulk Import — see SubjectController::import(). A
                // plain success/error string can't show which
                // specific rows failed and why, so this rides
                // alongside them the same way facultyDeletionImpact/
                // roomDeletionImpact do.
                'subjectImportErrors' => fn () => $request->session()->get('subjectImportErrors'),
                // Same per-row failure detail for the Room Master's
                // Bulk Import — see RoomController::import().
                'roomImportErrors' => fn () => $request->session()->get('roomImportErrors'),
            ],
            // The currently Active Academic Term (School Year +
            // Semester) — the real, system-wide one, unaffected by
            // any user's Viewing Term switch below. Kept for anything
            // that specifically needs the true Active term regardless
            // of what the current user is browsing.
            //
            // Uses ViewingTerm::activeTermCached(), a per-request
            // memoized lookup, rather than firing its own separate
            // query — this prop, 'viewingAcademicTerm', and
            // 'isViewingOverride' used to each independently query the
            // Active term, so one Inertia visit (i.e. every sidebar
            // click) ran this same query up to 3 times.
            'activeAcademicTerm' => fn () => \App\Support\ViewingTerm::activeTermCached($request),
            // The Academic Term THIS user is currently viewing —
            // their session override (Admin/Registrar only — see
            // ViewingTerm) if one is set, else the real Active term.
            // This is what the header pill in AppLayout.vue displays
            // and what defaults Reports/Sections/etc. build against,
            // so switching it never affects any other user.
            'viewingAcademicTerm' => fn () => ViewingTerm::resolve($request)
                ?->loadMissing(['schoolYear:id,name', 'semester:id,name']),
            // True only when the user's session override points at a
            // genuinely different term than the real Active one — see
            // ViewingTerm::isDeviatingFromActive(). Lets the header
            // show a "Planning" badge exactly when it should, not
            // whenever an override happens to be set (an override
            // pointed AT the Active term itself must never show
            // "Planning").
            'isViewingOverride' => fn () => ViewingTerm::isDeviatingFromActive($request),
            // Whether this user is even allowed to use the switch
            // (Administrator/Registrar/Dean/OIC/Assistant Dean — see
            // AccessScope::canSwitchViewingTerm()) — gates showing the
            // dropdown affordance at all in the header.
            'canSwitchViewingTerm' => fn () => ViewingTerm::canSwitch($user),
            // Every switchable (non-Archived) Academic Term, for the
            // header dropdown. Only fetched for users who can actually
            // switch, to avoid the query on every request otherwise.
            'availableAcademicTerms' => fn () => ViewingTerm::canSwitch($user)
                ? AcademicTerm::query()
                    ->where('status', '!=', 'Archived')
                    ->with(['schoolYear:id,name', 'semester:id,name'])
                    ->orderByDesc('id')
                    ->get(['id', 'school_year_id', 'semester_id', 'status'])
                : [],
            // SCHEDULING NOTIFICATION SYSTEM — unread count for the
            // header bell badge (see NotificationBell.vue), shared on
            // every full Inertia visit so the badge is correct on
            // first paint before the poll kicks in. A closure so it's
            // never queried on a partial reload that doesn't need it.
            'unreadNotificationCount' => fn () => $user
                ? Notification::query()->where('recipient_user_id', $user->id)->where('is_read', false)->count()
                : 0,
            // IN-SYSTEM MESSAGING — seeds the floating chat button's
            // badge on first paint, before ChatWidget's first poll
            // response lands (same pattern as the bell above).
            'unreadChatCount' => fn () => $user
                ? \App\Models\ChatMessage::query()
                    ->join('conversation_participants', 'conversation_participants.conversation_id', '=', 'chat_messages.conversation_id')
                    ->where('conversation_participants.user_id', $user->id)
                    ->where('chat_messages.sender_id', '!=', $user->id)
                    ->where(function ($query) {
                        $query->whereNull('conversation_participants.last_read_at')
                            ->orWhereColumn('chat_messages.created_at', '>', 'conversation_participants.last_read_at');
                    })
                    ->count()
                : 0,
            // SCHOOL BRANDING — single source of truth for the school's
            // identity (Settings → General), shared on every page so the
            // Welcome/Login pages, Dashboard, and the main app
            // sidebar/header can all display the configured name/logo
            // without each controller re-fetching SettingsService
            // itself. This is intentionally separate from CLASSLY's own
            // system branding (name/logo/tagline), which is never
            // driven by these values. Falls back to sensible
            // defaults (config('app.name') / null logo) when nothing
            // has been configured yet, so nothing ever errors or shows
            // a broken image.
            'schoolBranding' => fn () => (function () {
                $settings = app(SettingsService::class)->group('general');

                return [
                    'name' => $settings['general.school_name'] ?: config('app.name', 'Classly'),
                    'shortName' => $settings['general.school_short_name'] ?: null,
                    'logoUrl' => $settings['general.school_logo_path'] ?: null,
                ];
            })(),
        ];
    }
}