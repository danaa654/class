<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCHEDULING NOTIFICATION SYSTEM — polling API (spec Section 11).
 *
 * Every endpoint here only ever READS or marks-read; nothing in this
 * controller creates a Notification — those are only ever written by
 * NotificationService from inside the scheduling operations that
 * trigger them (see SectionController::finalize()/unlock() and
 * SectionSubjectController::performScheduleAssignmentUpdate()).
 */
class NotificationController extends Controller
{
    /**
     * Full notification list/page (spec Section 21) with All/Unread
     * filtering via ?filter=unread.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->where('recipient_user_id', $user->id)
            ->when($request->query('filter') === 'unread', fn ($query) => $query->where('is_read', false))
            ->with(['actor:id,name,first_name,last_name,middle_name,suffix,profile_photo_path', 'section:id,section_code'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filter' => $request->query('filter', 'all'),
        ]);
    }

    /**
     * Lightweight payload for the header bell dropdown — capped list
     * + unread count, meant to be polled every 10–30s (spec Section
     * 11). Deliberately NOT paginated the same way as index() so the
     * poll stays cheap.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->where('recipient_user_id', $user->id)
            ->with(['actor:id,name,first_name,last_name,middle_name,suffix,profile_photo_path', 'section:id,section_code'])
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => Notification::query()
                ->where('recipient_user_id', $user->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => Notification::query()
                ->where('recipient_user_id', $request->user()->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    /**
     * Mark one notification read (spec Section 13) and hand back the
     * route to the relevant Section's schedule so the frontend can
     * navigate there — see the `redirect` field. Scoped to the
     * requesting user's own notifications only; a recipient mismatch
     * is treated as "not found" rather than 403, so it doesn't leak
     * whether a given notification id exists for someone else.
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id, 404);

        $notification->markRead();

        return response()->json([
            'notification' => $notification,
            'redirect' => $this->routeFor($notification),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a single notification. Only already-read notifications
     * can be deleted — an unread one still represents something the
     * recipient hasn't seen yet, so it stays until they've read (or
     * bulk-marked) it, matching the same "read before it can go away"
     * rule Mark All Read already implies. Same ownership check as
     * markRead()/redirect(): a recipient mismatch reads as 404 rather
     * than 403, so it doesn't leak whether the id exists for someone
     * else.
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id, 404);

        if (! $notification->is_read) {
            return response()->json([
                'message' => 'Only already-read notifications can be deleted.',
            ], 422);
        }

        $notification->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Convenience GET entry point (e.g. from an email or a bookmark)
     * that marks the notification read and redirects straight to its
     * Section's schedule (spec Section 14) — generated from the
     * Section id via the app's own routing, never a stored URL.
     */
    public function redirect(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id, 404);

        $notification->markRead();

        $route = $this->routeFor($notification);

        return $route ? redirect($route) : redirect()->route('scheduling.sections');
    }

    private function routeFor(Notification $notification): ?string
    {
        if ($notification->type === \App\Services\NotificationService::TYPE_FACULTY_LOAD_REQUEST_SUBMITTED
            || $notification->type === \App\Services\NotificationService::TYPE_FACULTY_LOAD_REQUEST_REVIEWED) {
            return route('scheduling.faculty');
        }

        // Faculty-scoped notifications (spec Section 16) — jump
        // straight to that Faculty member's profile when we still
        // have one to show (faculty_id is in every one of these
        // notifications' data payload — see NotificationService's
        // facultyXxx() methods). A deleted Faculty has no profile
        // left to open, so those fall back to the Faculty Master list
        // instead of a 404.
        $facultyScopedTypes = [
            \App\Services\NotificationService::TYPE_FACULTY_CREATED_DIRECTLY,
            \App\Services\NotificationService::TYPE_FACULTY_UPDATED_DIRECTLY,
            \App\Services\NotificationService::TYPE_FACULTY_WORKLOAD_UPDATED,
            \App\Services\NotificationService::TYPE_FACULTY_OVERLOAD,
            \App\Services\NotificationService::TYPE_FACULTY_QUALIFICATIONS_UPDATED,
            \App\Services\NotificationService::TYPE_FACULTY_DEACTIVATED_DIRECTLY,
            \App\Services\NotificationService::TYPE_FACULTY_ASSIGNMENTS_NEED_ATTENTION,
        ];

        if (in_array($notification->type, $facultyScopedTypes, true)) {
            $facultyId = $notification->data['faculty_id'] ?? null;

            return $facultyId
                ? route('scheduling.faculty.show', $facultyId)
                : route('scheduling.faculty');
        }

        if ($notification->type === \App\Services\NotificationService::TYPE_FACULTY_DELETED_DIRECTLY) {
            return route('scheduling.faculty');
        }

        // Sends the recipient straight to their own Manage Account tab
        // — Administrators manage their account from User Management,
        // everyone else from Settings (see UsersController::updateAccount()).
        if ($notification->type === \App\Services\NotificationService::TYPE_PASSWORD_CHANGE_REQUIRED) {
            $notification->loadMissing('recipient.roles');

            return $notification->recipient?->hasRole('Administrator')
                ? route('users', ['tab' => 'account'])
                : route('settings', ['tab' => 'account']);
        }

        if (! $notification->section_id) {
            return null;
        }

        return route('scheduling.section-subjects.show', $notification->section_id);
    }
}