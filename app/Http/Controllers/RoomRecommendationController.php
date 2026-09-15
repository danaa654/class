<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomSubjectRecommendation;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Room Recommendation & Smart Auto-Scheduling — "Recommended Subjects"
 * management on the Room Details page.
 *
 * The Room page is the single source of truth for these preferences.
 * A recommendation is a SOFT preference only: it is read by
 * RecommendationService::recommendRooms() and AutoScheduleService as
 * a scoring bonus, and never becomes a hard constraint — it never
 * blocks a manual assignment to a different room, and saving a
 * recommendation never creates or edits a schedule by itself.
 */
class RoomRecommendationController extends Controller
{
    /** Roles that may manage recommendations for every Room. */
    private const UNSCOPED_ROLES = ['Administrator', 'Registrar'];

    /**
     * List the Subjects currently recommended for a Room, plus a
     * lightweight recommendation-coverage count for the summary chip.
     */
    public function index(Room $room): JsonResponse
    {
        $this->authorizeRoom($room);

        $room->load(['recommendedSubjects' => function ($query) {
            $query->select('subjects.id', 'subjects.subject_code', 'subjects.subject_title', 'subjects.category', 'subjects.units', 'subjects.major_id')
                ->with('major.department')
                ->orderBy('subjects.subject_code');
        }]);

        return response()->json([
            'recommendations' => $room->recommendedSubjects->map(function (Subject $subject) use ($room) {
                $subjectCollegeId = $subject->major?->department?->college_id;
                $subjectDepartmentId = $subject->major?->department_id;

                // Preferred (explicit) is always the level here — this
                // list IS the explicit recommendation set. is_manual_override
                // flags when the pick crosses the room's own College/
                // Department scope, i.e. an administrator intentionally
                // recommending a Subject that doesn't naturally belong
                // to this Room (see RecommendationService::recommendationLevel()).
                $naturalMatch = (! $room->college_id && ! $room->department_id)
                    || ($room->department_id && $room->department_id === $subjectDepartmentId)
                    || ($room->college_id && $room->college_id === $subjectCollegeId);

                return [
                    'recommendation_id' => $subject->pivot->id,
                    'subject_id' => $subject->id,
                    'subject_code' => $subject->subject_code,
                    'subject_title' => $subject->subject_title,
                    'category' => $subject->category,
                    'units' => $subject->units,
                    'major' => $subject->major?->name,
                    'college' => $subject->major?->department?->college?->name,
                    'recommendation_level' => 'preferred',
                    'is_manual_override' => ! $naturalMatch,
                ];
            }),
            'count' => $room->recommendedSubjects->count(),
        ]);
    }

    /**
     * Searchable subject picker for the "Add Subject Recommendation"
     * modal — excludes subjects already recommended for this Room,
     * and flags which candidates are a NATURAL fit for this Room
     * (same College/Department + Lecture/Laboratory hours matching
     * the Room's own type) versus a cross-department pick the
     * administrator would be explicitly overriding into place.
     * Natural-fit subjects sort first so the common case (recommend
     * more of what already belongs here) never gets buried under
     * every Subject in the catalog, while a deliberate override (e.g.
     * ITE201 — a minor subject — into a CCS lab) is still one search
     * away and clearly labeled once picked.
     */
    public function searchableSubjects(Request $request, Room $room): JsonResponse
    {
        $this->authorizeRoom($room);

        $search = trim((string) $request->query('search', ''));
        $alreadyRecommended = $room->subjectRecommendations()
            ->where('active', true)
            ->pluck('subject_id');

        $roomWantsLaboratory = $room->room_type === 'Laboratory';

        $subjects = Subject::query()
            ->where('is_active', true)
            ->whereNotIn('id', $alreadyRecommended)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('subject_code', 'like', "%{$search}%")
                        ->orWhere('subject_title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->with('major.department')
            ->orderBy('subject_code')
            ->limit(200)
            ->get(['id', 'subject_code', 'subject_title', 'category', 'units', 'major_id', 'laboratory_hours', 'lecture_hours']);

        // Informational only — a Subject already recommended for OTHER
        // Rooms is never excluded here (a Subject can be recommended
        // for as many Rooms as fit), but the picker should still let
        // the admin see where else it's already preferred so they're
        // not surprised or duplicating effort unknowingly.
        $otherRoomsBySubject = RoomSubjectRecommendation::query()
            ->where('active', true)
            ->where('room_id', '!=', $room->id)
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->with('room:id,room_name')
            ->get(['id', 'room_id', 'subject_id'])
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'room_id' => $row->room->id,
                // Room Name only — see RecommendationService::roomDisplayName()
                // for why concatenating room_code alongside room_name is
                // avoided: an auto-generated/imported room_code (e.g.
                // "ROOM306LAB1") that differs from its readable room_name
                // ("Room 306 (Lab 1)") made the same room look like two
                // different rooms depending on where its label was built.
                'room_name' => $row->room->room_name,
            ])->values());

        $mapped = $subjects->map(function (Subject $subject) use ($room, $roomWantsLaboratory, $otherRoomsBySubject) {
            $subjectCollegeId = $subject->major?->department?->college_id;
            $subjectDepartmentId = $subject->major?->department_id;

            $scopeMatch = (! $room->college_id && ! $room->department_id)
                || ($room->department_id && $room->department_id === $subjectDepartmentId)
                || ($room->college_id && $room->college_id === $subjectCollegeId);

            $subjectWantsLaboratory = (int) $subject->laboratory_hours > 0;
            $typeMatch = $roomWantsLaboratory ? $subjectWantsLaboratory : ! $subjectWantsLaboratory;

            $naturalFit = $scopeMatch && $typeMatch;

            $fitReason = match (true) {
                $naturalFit && $room->department_id && $room->department_id === $subjectDepartmentId
                    => 'Same program, correct room type',
                $naturalFit && $room->college_id => 'Same college, correct room type',
                $naturalFit => 'Shared room, correct room type',
                ! $scopeMatch => 'Different college/program',
                default => $roomWantsLaboratory ? 'No laboratory hours' : 'Requires a laboratory room',
            };

            return [
                'id' => $subject->id,
                'subject_code' => $subject->subject_code,
                'subject_title' => $subject->subject_title,
                'category' => $subject->category,
                'units' => $subject->units,
                'major' => $subject->major?->name,
                'natural_fit' => $naturalFit,
                'fit_reason' => $fitReason,
                'other_recommended_rooms' => $otherRoomsBySubject[$subject->id] ?? [],
            ];
        });

        // Natural fits first (so the common case sorts to the top),
        // then alphabetically by code within each group — this is
        // purely a display order, every subject remains selectable
        // either way (see the "Room Recommendation is never a hard
        // dependency" rule).
        $ordered = $mapped->all();
        usort($ordered, function (array $a, array $b) {
            if ($a['natural_fit'] !== $b['natural_fit']) {
                return $b['natural_fit'] <=> $a['natural_fit'];
            }

            return $a['subject_code'] <=> $b['subject_code'];
        });
        $ordered = array_slice($ordered, 0, 50);

        return response()->json([
            'subjects' => $ordered,
        ]);
    }

    /**
     * Save one or more Subject recommendations for a Room. Only
     * stores the preference — never touches an existing schedule.
     */
    public function store(Request $request, Room $room): RedirectResponse
    {
        $this->authorizeRoom($room);

        $validated = $request->validate([
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')],
        ]);

        foreach (array_unique($validated['subject_ids']) as $subjectId) {
            RoomSubjectRecommendation::updateOrCreate(
                ['room_id' => $room->id, 'subject_id' => $subjectId],
                ['active' => true, 'created_by' => Auth::id()],
            );
        }

        return back()->with('success', 'Room recommendation saved.');
    }

    /**
     * Remove a Subject's recommendation for a Room. This never
     * touches an existing schedule — it only stops the Subject from
     * being scored as "Recommended for this Room" on future Auto
     * Schedule runs.
     */
    public function destroy(Room $room, RoomSubjectRecommendation $recommendation): RedirectResponse
    {
        $this->authorizeRoom($room);

        if ($recommendation->room_id !== $room->id) {
            throw ValidationException::withMessages(['recommendation' => 'This recommendation does not belong to that room.']);
        }

        $recommendation->delete();

        return back()->with('success', 'Room recommendation removed.');
    }

    /**
     * Mirrors RoomController's college-scope rule: Administrator/
     * Registrar manage every Room; Dean/OIC manage their own
     * College's Rooms plus shared (no-College) Rooms; everyone else
     * is read-only for recommendations.
     */
    private function authorizeRoom(Room $room): void
    {
        $user = Auth::user();
        $role = $user?->getRoleNames()->first();

        if (! $user) {
            abort(403);
        }

        if (in_array($role, self::UNSCOPED_ROLES, true)) {
            return;
        }

        if (in_array($role, ['Dean', 'OIC'], true)
            && ($room->college_id === null || $room->college_id === $user->college_id)) {
            return;
        }

        abort(403, 'You are not authorized to manage recommendations for this room.');
    }
}