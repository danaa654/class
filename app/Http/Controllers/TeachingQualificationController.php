<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFacultyQualificationsRequest;
use App\Models\Faculty;
use App\Models\Subject;
use App\Services\NotificationService;
use App\Support\AccessScope;
use Illuminate\Http\RedirectResponse;

/**
 * Teaching Qualifications no longer has a standalone page — it's the
 * "Teaching Qualifications" tab on the Faculty Details page
 * (Scheduling/Faculty/Details.vue), which duplicated this module's UI
 * exactly. Only the sync endpoint remains.
 */
class TeachingQualificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Sync the set of subjects a faculty member is qualified to teach.
     *
     * A full replace (sync) from the frontend's point of view, but per
     * spec Section 6/14, a scoped user must only ever be able to touch
     * the slice of qualifications within their own authorization:
     *   - Assistant Dean: GenEd/Minor qualifications only, any College.
     *   - Dean/OIC: Major qualifications only, for their own College's
     *     faculty. They must NEVER be able to add/remove a GenEd/Minor
     *     qualification just because the faculty belongs to their
     *     College (spec Section 14, explicit warning).
     *   - Admin/Registrar: everything (bypassed via Gate::before).
     *
     * We therefore compute the final subject set as: (existing
     * qualifications OUTSIDE the user's authorized category/scope,
     * left untouched) + (incoming ids that ARE within the user's
     * authorized category/scope). The request payload can never
     * smuggle in a change to qualifications the user isn't allowed
     * to touch, no matter what ids are submitted.
     */
    public function update(UpdateFacultyQualificationsRequest $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('view', $faculty);

        $user = $request->user();
        $incomingIds = collect($request->validated('subject_ids'));

        $existingIds = $faculty->subjects()->pluck('subjects.id');
        $allRelevantIds = $existingIds->merge($incomingIds)->unique();

        $subjectsById = Subject::whereIn('id', $allRelevantIds)->get()->keyBy('id');

        $manageable = fn (int $subjectId): bool => $subjectsById->has($subjectId)
            && $this->userManagesQualification($user, $faculty, (string) $subjectsById[$subjectId]->category);

        // Keep every existing qualification the user is NOT authorized
        // to touch, exactly as-is.
        $untouchable = $existingIds->reject($manageable);

        // Apply the incoming set only for the slice the user IS
        // authorized to touch.
        $authorizedIncoming = $incomingIds->filter($manageable);

        $finalIds = $untouchable->merge($authorizedIncoming)->unique()->values();

        // Diff by subject code (readable in a notification) BEFORE
        // syncing, so we can tell recipients exactly what changed —
        // spec Section 8 wants "Added subjects: ... / Removed
        // subjects: ...", not just "qualifications changed".
        $addedCodes = $subjectsById->only($finalIds->diff($existingIds)->all())->pluck('subject_code')->values()->all();
        $removedCodes = $subjectsById->only($existingIds->diff($finalIds)->all())->pluck('subject_code')->values()->all();

        $faculty->subjects()->sync($finalIds);

        if (! empty($addedCodes) || ! empty($removedCodes)) {
            $this->notifications->facultyQualificationsUpdated($faculty, $user, $addedCodes, $removedCodes);
        }

        return redirect()
            ->route('scheduling.faculty.show', $faculty)
            ->with('success', 'Teaching qualifications updated successfully.');
    }

    private function userManagesQualification($user, Faculty $faculty, string $category): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        if (AccessScope::isSharedCategory($category)) {
            return AccessScope::isAssistantDean($user);
        }

        return AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $faculty->college_id);
    }
}