<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ACTIVITY LOG — "who did what, when" tab on the Settings page.
 * Administrator and Registrar see the unrestricted, institution-wide
 * log. Dean/OIC/Assistant Dean also get a read-only view (see
 * SettingsController::index()'s $canViewActivityLog), but scoped to
 * their own College (plus shared/institution-wide entries — see
 * activityLog()'s docblock) via $scopeCollegeId/$sharedOnly below —
 * same ROLE + SCOPE model as App\Support\AccessScope uses elsewhere
 * (Sections, Faculty qualifications, etc.), just applied here per
 * log entry's polymorphic subject instead of a direct column, since
 * ActivityLog itself doesn't store college_id.
 * Same pattern as ActiveSessionController's builder, but Active
 * Sessions itself stays Administrator-only since that's a stricter
 * "who's online right now" concern rather than an audit-trail one.
 * A plain static builder method called from SettingsController::index()
 * (wrapped in Inertia::lazy() so it's only queried when the tab is
 * actually opened or its filters change — see the
 * `router.reload({ only: ['activityLog'] })` calls on the frontend).
 *
 * Read-only — there is no write action here at all; every row is
 * created elsewhere via ActivityLogService::record().
 */
class ActivityLogController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Build the paginated, filtered Activity Log payload consumed by
     * the Settings page's Activity Log tab.
     *
     * @param  ?int  $scopeCollegeId  null = unrestricted (Admin/Registrar).
     *      Otherwise restricts to entries whose subject (Faculty/
     *      Subject/Room/User) resolves to this College OR has no
     *      College at all (a shared/institution-wide resource — a
     *      GenEd/Minor Subject, an all-college Room, etc.) — same
     *      "own College + shared" reach NotificationService's
     *      subjectRecipients()/roomRecipients() already give a Dean/
     *      OIC for those events, so the log isn't missing entries
     *      they were actually notified about. Section entries are the
     *      one exception: a Section always belongs to exactly one
     *      College (via major->department->college_id), so those are
     *      matched separately below and never treated as "shared".
     * @param  bool  $sharedOnly  Assistant Dean only (a pure Assistant
     *      Dean, not also College-scoped): ignore $scopeCollegeId
     *      entirely and show ONLY entries whose subject has no College
     *      at all — Sections never match this since they always
     *      belong to a College.
     * @return array{
     *     data: list<array{id:int,actor:?string,role:?string,action:string,description:string,created_at:string}>,
     *     current_page:int, last_page:int, total:int,
     *     filters: array{action:?string,user_id:?int,date_from:?string,date_to:?string},
     *     action_options: list<string>,
     *     user_options: list<array{id:int,name:string}>,
     * }
     */
    public static function activityLog(Request $request, ?int $scopeCollegeId = null, bool $sharedOnly = false): array
    {
        $query = ActivityLog::query()->with('user')->latest('created_at');

        if ($sharedOnly || $scopeCollegeId !== null) {
            $query->where(function ($scoped) use ($scopeCollegeId, $sharedOnly) {
                $scoped->whereHasMorph(
                    'subject',
                    [Faculty::class, Subject::class, Room::class, User::class],
                    fn ($q) => $sharedOnly
                        ? $q->whereNull('college_id')
                        : $q->where(fn ($w) => $w->where('college_id', $scopeCollegeId)->orWhereNull('college_id'))
                );

                if (! $sharedOnly) {
                    $scoped->orWhereHasMorph(
                        'subject',
                        [Section::class],
                        fn ($q) => $q->whereHas('major.department', fn ($inner) => $inner->where('college_id', $scopeCollegeId))
                    );
                }
            });
        }

        $action = $request->string('log_action')->toString() ?: null;
        $userId = $request->integer('log_user_id') ?: null;
        $dateFrom = $request->string('log_date_from')->toString() ?: null;
        $dateTo = $request->string('log_date_to')->toString() ?: null;

        if ($action) {
            $query->where('action', $action);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Cloned BEFORE pagination/ordering is finalized so the user
        // filter dropdown only offers users who actually have a
        // (scope-visible) log entry — same $query, including the
        // College scoping above, minus the action/date filters that
        // don't belong in this pluck.
        $scopedUserIds = (clone $query)->whereNotNull('user_id')->distinct()->pluck('user_id');

        /** @var LengthAwarePaginator $paginated */
        $paginated = $query->paginate(
            self::PER_PAGE,
            ['*'],
            'log_page',
            (int) $request->input('log_page', 1),
        );

        return [
            'data' => collect($paginated->items())->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'actor' => $log->user?->full_name ?? 'System',
                'role' => $log->user?->getRoleNames()->first(),
                'action' => $log->action,
                'description' => $log->description,
                'created_at' => $log->created_at->toIso8601String(),
            ])->all(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'total' => $paginated->total(),
            'filters' => [
                'action' => $action,
                'user_id' => $userId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'action_options' => ActivityLogService::actions(),
            'user_options' => User::query()
                ->whereIn('id', $scopedUserIds)
                ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->full_name])
                ->values()
                ->all(),
        ];
    }
}