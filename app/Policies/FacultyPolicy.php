<?php

namespace App\Policies;

use App\Models\Faculty;
use App\Models\User;
use App\Support\AccessScope;

class FacultyPolicy
{
    /** Everyone with a Scheduling-side role may see the roster (query is still scoped). */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC']);
    }

    public function view(User $user, Faculty $faculty): bool
    {
        // Viewing a faculty record's details is READ access, same lane
        // as the roster list (scopeVisibleTo) — Assistant Dean can look
        // up any faculty, e.g. a CCS faculty who also teaches an ITE
        // Minor subject for another College, in order to assign them.
        // This is intentionally broader than canAccess()/update(),
        // which still gate WRITE access to Assistant Dean's own
        // GenEd/Minor-faculty lane.
        if (AccessScope::isAssistantDean($user)) {
            return true;
        }

        // A College-scoped Dean/OIC may open ANY faculty's detail page
        // read-only via the Faculty Master "All Faculty" filter (see
        // Faculty::scopeVisibleTo()'s $allColleges param) — the page
        // itself hides the Edit button and re-checks update()/
        // manageQualification() for write actions, so allowing the
        // read here doesn't grant anything beyond what that list
        // already showed them.
        if (AccessScope::isCollegeScoped($user)) {
            return true;
        }

        return $this->canAccess($user, $faculty);
    }

    /**
     * Directly creating an ACTIVE Faculty record. Per the current
     * spec, every Scheduling-side role (Admin, Registrar, Dean/OIC,
     * Assistant Dean) may add Faculty directly — there is no longer
     * a request/approval queue for Faculty creation. Scoping to the
     * correct College still happens via createForCollege() below.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC']);
    }

    /**
     * A College-scoped Dean/OIC/Assistant Dean creating a Faculty
     * record may only assign it to their own College (or leave it a
     * GenEd/Minor faculty with no College — but that is the Assistant
     * Dean's lane to manage further; creation of the base record is
     * allowed so Deans can roster their own people).
     */
    public function createForCollege(User $user, ?int $collegeId): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        // GenEd/Minor faculty (no College) is Assistant Dean's lane —
        // covers both a pure Assistant Dean and a Dean/OIC additionally
        // flagged with GenEd/Minor authority (is_gened_assistant_dean).
        // Checked by the TARGET college being null, not by the user's
        // role alone — otherwise a flagged Dean/OIC creating a Faculty
        // for their OWN College would wrongly hit this branch and be
        // rejected, since isAssistantDean($user) is also true for them.
        if ($collegeId === null) {
            return AccessScope::isAssistantDean($user);
        }

        if (AccessScope::isCollegeScoped($user)) {
            return AccessScope::canAccessCollege($user, $collegeId);
        }

        return false;
    }

    /**
     * Whether the user may submit a Faculty CREATION request for the
     * given (proposed) College. Only Dean/OIC/Assistant Dean use this
     * path — Admin/Registrar use create()/createForCollege() directly
     * instead (see FacultyRequestPolicy::create()).
     */
    public function requestCreate(User $user, ?int $collegeId): bool
    {
        // Same fix as createForCollege() above — branch on the TARGET
        // college being null, not on the user's role, so a flagged
        // Dean/OIC isn't blocked from their own College.
        if ($collegeId === null) {
            return AccessScope::isAssistantDean($user);
        }

        if (AccessScope::isCollegeScoped($user)) {
            return AccessScope::canAccessCollege($user, $collegeId);
        }

        return false;
    }

    /**
     * Whether the user may submit a Faculty DEACTIVATION/removal
     * request for this Faculty member. Admin/Registrar deactivate
     * directly via delete() instead.
     *
     * In practice this path is now only reachable for GenEd/Minor
     * faculty (college_id === null), i.e. Assistant Dean — a Dean/OIC
     * requesting for their OWN College's faculty will find delete()
     * (see its docblock) already returns true for that same faculty,
     * so the frontend's canDeactivateDirectly check wins and this
     * request workflow is never surfaced to them for that case. Left
     * unchanged (rather than narrowed to Assistant-Dean-only) so nothing
     * breaks if a Dean/OIC is ever scoped to a College they're not
     * currently assigned to, or if direct delete is later narrowed again.
     */
    public function requestDeactivate(User $user, Faculty $faculty): bool
    {
        // Same fix as createForCollege() above — branch on the
        // FACULTY's own college_id being null, not on the user's
        // role, so a flagged Dean/OIC isn't blocked from their own
        // College's faculty.
        if ($faculty->college_id === null) {
            return AccessScope::isAssistantDean($user);
        }

        if (AccessScope::isCollegeScoped($user)) {
            return AccessScope::canAccessCollege($user, $faculty->college_id);
        }

        return false;
    }

    public function update(User $user, Faculty $faculty): bool
    {
        return $this->canAccess($user, $faculty);
    }

    /**
     * Directly deactivating/removing a Faculty record. Admin/
     * Registrar may always do this, for any Faculty. A Dean/OIC may
     * also delete directly, but ONLY a Faculty member within their
     * own College — never GenEd/Minor faculty (no College) and never
     * another College's faculty, same scoping canAccess()/update()
     * already enforce for edits. Assistant Dean still has NO direct
     * delete path (GenEd/Minor faculty deletion always goes through
     * requestDeactivate() -> Admin/Registrar approval) — this is
     * deliberately narrower than canAccess(), which would otherwise
     * also grant Assistant Dean direct delete over GenEd/Minor
     * faculty.
     */
    public function delete(User $user, Faculty $faculty): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        if ($faculty->college_id === null) {
            // GenEd/Minor faculty — Assistant Dean's lane, but direct
            // delete power there stays Admin/Registrar-only; even a
            // Dean/OIC flagged with GenEd/Minor authority
            // (is_gened_assistant_dean) only gets that authority over
            // shared resources' DEFINITIONS, not over deleting a
            // Faculty record outright.
            return false;
        }

        return AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $faculty->college_id);
    }

    /**
     * Whether the user may reassign this Faculty member to a
     * different College. Per spec, Dean/OIC (and Assistant Dean) may
     * NEVER move a faculty between Colleges — only Admin/Registrar.
     */
    public function reassignCollege(User $user): bool
    {
        return AccessScope::isUnrestricted($user);
    }

    /**
     * Whether the user may directly change a Faculty member's teaching
     * load ceiling (max_teaching_units / max_weekly_hours). Per the
     * current spec, every Scheduling-side role (Admin, Registrar,
     * Dean/OIC, Assistant Dean) may set this directly — the
     * FacultyLoadRequest approval queue is no longer required for
     * this field.
     */
    public function changeMaxLoad(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC']);
    }

    /**
     * Whether the user may manage a specific teaching qualification
     * entry. General Education stays Assistant-Dean-exclusive
     * (institution-wide floating pool); Major and Minor are both
     * manageable by the faculty's own College Dean/OIC (Minor is also
     * manageable by Assistant Dean, institution-wide). See
     * AccessScope::canManageQualification() for the full rationale.
     */
    public function manageQualification(User $user, Faculty $faculty, string $subjectCategory): bool
    {
        return AccessScope::canManageQualification($user, $subjectCategory, $faculty->college_id);
    }

    private function canAccess(User $user, Faculty $faculty): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        // Assistant Dean's lane is GenEd/Minor faculty, represented in
        // this codebase as a Faculty record with no college_id (see
        // FacultyController's "General Education Faculty" filter).
        // Branch on the FACULTY's college_id being null, not on the
        // user's role — a Dean/OIC additionally flagged with GenEd/
        // Minor authority (is_gened_assistant_dean) also passes
        // isAssistantDean(), so checking the role first would wrongly
        // block them from editing their OWN College's faculty (a
        // non-null college_id would fail the old `=== null` check).
        if ($faculty->college_id === null) {
            return AccessScope::isAssistantDean($user);
        }

        if (AccessScope::isCollegeScoped($user)) {
            return AccessScope::canAccessCollege($user, $faculty->college_id);
        }

        return false;
    }
}