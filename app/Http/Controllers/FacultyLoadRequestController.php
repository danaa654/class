<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewFacultyLoadRequestRequest;
use App\Http\Requests\StoreFacultyLoadRequestRequest;
use App\Models\Faculty;
use App\Models\FacultyLoadRequest;
use App\Services\FacultyWorkloadService;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Workflow for raising a Faculty member's teaching load ceiling when a
 * College is short-staffed.
 *
 * Anyone with changeMaxLoad access (Administrator, Registrar, Dean,
 * OIC, or Assistant Dean — see FacultyPolicy::changeMaxLoad()) can
 * also edit Faculty::max_teaching_units / max_weekly_hours directly
 * (FacultyController@update). This controller is the shared entry
 * point for both: a changeMaxLoad holder's own submission here is
 * applied immediately (self-approved, same as review() below), while
 * anyone else's creates a Pending FacultyLoadRequest that sits in the
 * queue until a changeMaxLoad holder reviews it.
 *
 * The review queue itself is now rendered inside FacultyController@index
 * (a section on the Faculty page) rather than its own screen — this
 * controller just handles the store/review actions.
 */
class FacultyLoadRequestController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly FacultyWorkloadService $workloadService,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Submit a new load change for a Faculty member.
     *
     * Anyone WITHOUT changeMaxLoad access has no direct write path to
     * max_teaching_units/max_weekly_hours — for them this always
     * creates a Pending FacultyLoadRequest that sits in the queue
     * until a changeMaxLoad holder (Admin, Registrar, Dean, OIC, or
     * Assistant Dean) reviews it.
     *
     * A changeMaxLoad holder, on the other hand, already has a direct
     * edit path (FacultyController@update, gated by the same
     * FacultyPolicy::changeMaxLoad) — routing their own submission
     * through here as another Pending request would just mean they
     * (or another reviewer) has to separately go approve their own
     * change before it takes effect, which is a no-op approval step,
     * not a real review. So when the actor holds changeMaxLoad, this
     * applies the change immediately: the record is created already
     * Approved/self-reviewed and the Faculty row is updated in the
     * same transaction, same as review() below.
     */
    public function store(StoreFacultyLoadRequestRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', FacultyLoadRequest::class);

        $data = $request->validated();
        $faculty = Faculty::findOrFail($data['faculty_id']);

        // Requester must actually be allowed to see/manage this
        // faculty member (College scope) — create() only checked the
        // coarse role.
        $this->authorize('view', $faculty);

        $requestedUnits = $data['requested_max_teaching_units'] ?? null;
        $requestedHours = $data['requested_max_weekly_hours'] ?? null;

        // JSON callers (currently: Room Grid's "Request more units" /
        // "Add Units" shortcut — see RoomGrid.vue's openLoadRequestDialog())
        // need these two guard-clause failures back as a normal 422
        // {errors} body they can toast inline, not a 302 redirect they'd
        // have to follow and then re-parse HTML out of. Anything that
        // didn't ask for JSON (the Faculty Master page's own Inertia
        // form — see Faculty/Index.vue's onSubmitLoadRequest()) keeps
        // getting exactly the back()->withErrors() it always has.
        if ($requestedUnits === null && $requestedHours === null) {
            $message = 'Enter the units and/or hours you are requesting.';

            return $request->wantsJson()
                ? response()->json(['errors' => ['requested_max_teaching_units' => [$message]]], 422)
                : back()->withErrors(['requested_max_teaching_units' => $message])->withInput();
        }

        if ($requestedUnits !== null && $requestedUnits <= $faculty->max_teaching_units) {
            $message = 'The requested units must be higher than the faculty member\'s current maximum ('.$faculty->max_teaching_units.').';

            return $request->wantsJson()
                ? response()->json(['errors' => ['requested_max_teaching_units' => [$message]]], 422)
                : back()->withErrors(['requested_max_teaching_units' => $message])->withInput();
        }

        $actorIsReviewer = $request->user()->can('changeMaxLoad', Faculty::class);
        $cap = FacultyLoadRequest::effectiveCapFor($request->user());

        // SAFETY NET — BUG FIX: Admin/Registrar's request is auto-approved
        // and applied to the Faculty row immediately, with no separate
        // reviewer downstream to catch a ceiling that's still too low for
        // what the faculty member is ACTUALLY carrying right now. This
        // bit them via Room Grid's "Add Units" shortcut: its old default
        // ("current max + 1") assumed max_teaching_units was still an
        // accurate ceiling, but a faculty member can already be carrying
        // MORE than that ceiling after an Administrator's workload
        // override on Save (writeSchedule()'s "Proceed Anyway" — see
        // RoomGrid.vue). "current max + 1" then undershot badly, leaving
        // them just as visibly over-capacity afterward (e.g. shown as
        // 15 / 12 Units on the Faculty Master page). Frontend now
        // defaults its prefilled amount higher (see RoomGrid.vue's
        // openLoadRequestDialog()), but this floor is enforced here too
        // regardless of what value actually arrives, so a stale client,
        // a hand-crafted request, or a future caller can't reintroduce
        // the same bug. Reuses FacultyWorkloadService::currentLoad() —
        // the exact same figure already shown as the "Y" in every
        // "X units left (Y/Z)" display — rather than re-deriving load a
        // second way. Deliberately scoped to the auto-approve path only:
        // a Pending request still goes through manual review, where a
        // human reviewer sees (and can adjust) the number before it ever
        // touches the Faculty row, so this floor would just be second-
        // guessing that review rather than fixing an actual bug.
        if ($actorIsReviewer && $requestedUnits !== null) {
            $currentLoad = $this->workloadService->currentLoad($faculty);

            if ($currentLoad > $cap) {
                // The faculty is ALREADY carrying more than the
                // institution-wide ceiling allows (only reachable via an
                // Administrator's explicit workload override on Save) —
                // no valid ceiling can be applied here without breaching
                // effectiveCapFor() itself, so this has to surface as an
                // error rather than silently clamping to $cap and leaving
                // the faculty exactly as over-capacity as they were
                // before this request.
                $message = "{$faculty->full_name}'s current teaching load ({$currentLoad}) already exceeds the institution-wide ceiling ({$cap} units). Raise the ceiling under Settings > Faculty & Workload, or reduce their assigned load, before requesting a change here.";

                return $request->wantsJson()
                    ? response()->json(['errors' => ['requested_max_teaching_units' => [$message]]], 422)
                    : back()->withErrors(['requested_max_teaching_units' => $message])->withInput();
            }
        } else {
            $currentLoad = null;
        }

        DB::transaction(function () use ($faculty, $requestedUnits, $requestedHours, $data, $request, $actorIsReviewer, $cap, $currentLoad) {
            $units = $requestedUnits !== null
                ? min($requestedUnits, $cap)
                : $faculty->max_teaching_units;

            // See the safety-net comment above this transaction — floors
            // the auto-approved ceiling at the faculty's actual current
            // load (already confirmed <= $cap up there) so it can never
            // land below what they're really carrying, regardless of
            // what the request itself asked for.
            if ($actorIsReviewer && $currentLoad !== null) {
                $units = max($units, $currentLoad);
            }

            $loadRequest = FacultyLoadRequest::create([
                'faculty_id' => $faculty->id,
                'current_max_teaching_units' => $faculty->max_teaching_units,
                'requested_max_teaching_units' => $units,
                'current_max_weekly_hours' => $faculty->max_weekly_hours,
                'requested_max_weekly_hours' => $requestedHours,
                'reason' => $data['reason'],
                'status' => $actorIsReviewer ? 'Approved' : 'Pending',
                'requested_by' => $request->user()->id,
                'reviewed_by' => $actorIsReviewer ? $request->user()->id : null,
                'reviewed_at' => $actorIsReviewer ? now() : null,
            ]);

            if ($actorIsReviewer) {
                $faculty->update([
                    'max_teaching_units' => $units,
                    'max_weekly_hours' => $requestedHours ?? $faculty->max_weekly_hours,
                ]);

                $this->activityLog->record(
                    ActivityLogService::FACULTY_UPDATED,
                    "{$request->user()->full_name} added teaching load units for {$faculty->full_name} ({$faculty->max_teaching_units} → {$units} units).",
                    $faculty,
                    $request->user(),
                );

                // No Pending step, so there's no separate reviewer to
                // notify — but the Dean/OIC of this faculty's College
                // still needs to know their faculty member's ceiling
                // just changed, even though they didn't submit
                // anything themselves.
                $this->notifications->facultyLoadUpdatedDirectly($loadRequest, $request->user());

                return;
            }

            $this->activityLog->record(
                ActivityLogService::FACULTY_UPDATED,
                "{$request->user()->full_name} requested a teaching load increase for {$faculty->full_name}.",
                $faculty,
                $request->user(),
            );

            $this->notifications->facultyLoadRequestSubmitted($loadRequest, $request->user());
        });

        $successMessage = $actorIsReviewer
            ? 'Faculty load ceiling updated.'
            : 'Load change request submitted for review.';

        // Format-aware response — the Room Grid's inline "Request more
        // units"/"Add Units" shortcut (RoomGrid.vue) calls this same
        // endpoint via fetch() with Accept: application/json so it can
        // update its own local Faculty data and stay on the Room Grid
        // instead of being yanked into a full-page Inertia redirect to
        // the Faculty Master page mid-scheduling. Every other caller
        // (the Faculty Master page's own "Request a load increase"/
        // "Add Load" Inertia form) never sends that header, so it falls
        // through to the exact same redirect this action has always
        // returned — that flow is untouched.
        if ($request->wantsJson()) {
            $faculty->refresh();

            return response()->json([
                'success' => true,
                'message' => $successMessage,
                // Lets the frontend tell "took effect immediately" apart
                // from "still needs a reviewer" without having to infer
                // it from the message string.
                'auto_approved' => $actorIsReviewer,
                // Only meaningful when auto_approved is true — a Pending
                // request hasn't touched the Faculty row yet, so these
                // just echo back the (unchanged) current ceiling rather
                // than implying a change already happened.
                'max_teaching_units' => $faculty->max_teaching_units,
                'max_weekly_hours' => $faculty->max_weekly_hours,
            ]);
        }

        return redirect()->route('scheduling.faculty')->with('success', $successMessage);
    }

    /**
     * Admin/Registrar approves or denies a pending request from a
     * Dean/OIC/Assistant Dean (or anyone else without changeMaxLoad
     * access). Approval is the ONLY place outside FacultyController@
     * update (a changeMaxLoad holder's direct edit) or this
     * controller's own store() auto-approve path that
     * Faculty::max_teaching_units / max_weekly_hours may change.
     */
    public function review(ReviewFacultyLoadRequestRequest $request, FacultyLoadRequest $facultyLoadRequest): RedirectResponse
    {
        $this->authorize('review', FacultyLoadRequest::class);

        if ($facultyLoadRequest->status !== 'Pending') {
            return back()->with('error', 'This request has already been reviewed.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $facultyLoadRequest, $request) {
            if ($data['decision'] === 'Approved') {
                // Hard institution-wide ceiling, enforced again here even
                // though ReviewFacultyLoadRequestRequest already capped it
                // at submission time — never trust a value that's been
                // sitting in the database (or in the request) unverified.
                // approved_max_teaching_units lets Admin/Registrar grant a
                // different ceiling than what was asked for; null falls
                // back to exactly what the Dean/OIC requested.
                $units = min(
                    $data['approved_max_teaching_units'] ?? $facultyLoadRequest->requested_max_teaching_units,
                    FacultyLoadRequest::effectiveCapFor($request->user()),
                );
                $hours = $data['approved_max_weekly_hours'] ?? $facultyLoadRequest->requested_max_weekly_hours ?? $facultyLoadRequest->faculty->max_weekly_hours;

                $facultyLoadRequest->faculty->update([
                    'max_teaching_units' => $units,
                    'max_weekly_hours' => $hours,
                ]);

                // Record what was actually granted, not just what was
                // asked for, so the Faculty Load Requests list and the
                // requester's notification reflect reality when a
                // reviewer adjusts the number.
                $facultyLoadRequest->requested_max_teaching_units = $units;
                $facultyLoadRequest->requested_max_weekly_hours = $hours;
            }

            $facultyLoadRequest->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'decision_note' => $data['decision_note'] ?? null,
            ]);

            $this->activityLog->record(
                ActivityLogService::FACULTY_UPDATED,
                "{$request->user()->full_name} {$data['decision']} a teaching load request for {$facultyLoadRequest->faculty->full_name}.",
                $facultyLoadRequest->faculty,
                $request->user(),
            );

            $this->notifications->facultyLoadRequestReviewed($facultyLoadRequest, $request->user());
        });

        return back()->with('success', 'Request '.strtolower($data['decision']).'.');
    }

    /**
     * Remove an already-decided request from the list (cleanup only —
     * see FacultyLoadRequestPolicy::delete()). Pending requests are
     * rejected here even if a caller somehow bypasses the policy
     * check, since only review() may resolve those.
     */
    public function destroy(FacultyLoadRequest $facultyLoadRequest): RedirectResponse
    {
        $this->authorize('delete', $facultyLoadRequest);

        if ($facultyLoadRequest->status === 'Pending') {
            return back()->with('error', 'Approve or deny this request before removing it.');
        }

        $facultyLoadRequest->delete();

        return back()->with('success', 'Load request removed.');
    }
}