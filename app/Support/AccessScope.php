<?php

namespace App\Support;

use App\Models\User;

/**
 * Central ROLE + SCOPE resolver for Classly's authorization model.
 *
 * Authorization here is always ROLE + SCOPE + RESOURCE + ACTION, never a
 * flat boolean. This class is the single source of truth for what a
 * user's SCOPE is, so Policies, Controllers, and query scoping never
 * duplicate (and risk disagreeing on) this logic.
 *
 * Roles come from Spatie Permission (see database/seeders/RoleSeeder.php).
 * College scope comes from users.college_id (Dean/OIC only).
 */
class AccessScope
{
    /** Roles with unrestricted access to every College's resources. */
    public const UNRESTRICTED_ROLES = ['Administrator', 'Registrar'];

    /** Roles whose scope is College-bound (Dean/OIC). */
    public const COLLEGE_SCOPED_ROLES = ['Dean', 'OIC'];

    /** The role limited to GenEd/Minor resources across all Colleges. */
    public const ASSISTANT_DEAN_ROLE = 'Assistant Dean';

    /** Only Administrator may manage user accounts, roles, and scope. */
    public const USER_MANAGEMENT_ROLES = ['Administrator'];

    public static function isAdministrator(?User $user): bool
    {
        return (bool) $user?->hasRole('Administrator');
    }

    /**
     * True for roles that see every College's data (Admin/Registrar).
     * Does NOT include Assistant Dean, whose "all Colleges" access is
     * limited to GenEd/Minor resources only — see isAssistantDean().
     */
    public static function isUnrestricted(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::UNRESTRICTED_ROLES);
    }

    /**
     * True for anyone with GenEd/Minor "Assistant Dean" authority — either
     * a user whose primary role IS Assistant Dean, OR a Dean/OIC who has
     * additionally been granted GenEd/Minor authority via the
     * `is_gened_assistant_dean` flag (e.g. a college's Dean who is also
     * covering the institution-wide GenEd/Minor scope). This flag is
     * additive only — it never replaces the user's primary role/College
     * scope, it just layers the Assistant Dean permission set on top.
     */
    public static function isAssistantDean(?User $user): bool
    {
        return (bool) $user?->hasRole(self::ASSISTANT_DEAN_ROLE)
            || (bool) $user?->is_gened_assistant_dean;
    }

    public static function isCollegeScoped(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::COLLEGE_SCOPED_ROLES);
    }

    /**
     * Whether $user may use the "Viewing Academic Term" switch (see
     * App\Support\ViewingTerm) — i.e. personally browse the app as if
     * a different, non-Active term were current, without touching the
     * real system-wide Active term.
     *
     * Originally Administrator/Registrar only. Extended to Dean/OIC
     * and Assistant Dean so they can start planning/building sections
     * for an upcoming term while the current one is still live — this
     * is safe because their view of ANY term is already independently
     * scoped to their own College (Dean/OIC) or GenEd/Minor resources
     * (Assistant Dean) everywhere the switch actually matters
     * (Sections, Reports, Scheduling, etc. — see e.g.
     * ReportsController::buildFilters()'s "NEVER trust an arbitrary
     * college_id" rule). Switching WHICH TERM they see never widens
     * WHICH COLLEGE's data they see.
     */
    public static function canSwitchViewingTerm(?User $user): bool
    {
        return self::isUnrestricted($user)
            || self::isCollegeScoped($user)
            || self::isAssistantDean($user);
    }

    /**
     * The College id a Dean/OIC is restricted to, or null if the user
     * is not College-scoped (or unrestricted / Assistant Dean).
     */
    public static function collegeId(?User $user): ?int
    {
        if (! self::isCollegeScoped($user)) {
            return null;
        }

        return $user?->college_id;
    }

    /**
     * True if a College-scoped Dean/OIC has no College assigned yet.
     * Per spec: this must NEVER be treated as unrestricted access.
     */
    public static function hasNoAssignedCollege(?User $user): bool
    {
        return self::isCollegeScoped($user) && ! $user?->college_id;
    }

    /**
     * Whether $collegeId is within the user's authorized scope.
     * Admin/Registrar: always true. Assistant Dean: true only for
     * GenEd/Minor-resource checks handled separately (see
     * canManageSubjectCategory). Dean/OIC: only their own College.
     */
    public static function canAccessCollege(?User $user, ?int $collegeId): bool
    {
        if (self::isUnrestricted($user)) {
            return true;
        }

        if (self::isCollegeScoped($user)) {
            return ! self::hasNoAssignedCollege($user) && $user->college_id === $collegeId;
        }

        return false;
    }

    /**
     * Whether the user may manage a Subject/Faculty resource of the
     * given "category" classification. $category is either 'Major'
     * (College-owned) or 'General Education' / 'Minor' (institution-
     * wide, shared, Assistant Dean's responsibility).
     */
    public static function canManageByCategory(?User $user, string $category, ?int $ownerCollegeId): bool
    {
        if (self::isUnrestricted($user)) {
            return true;
        }

        $isShared = self::isSharedCategory($category);

        // A dual-role user (Dean/OIC who has ALSO been granted GenEd/Minor
        // authority via is_gened_assistant_dean) gets the Assistant Dean's
        // institution-wide reach for shared categories, but must still
        // fall through to their own College scope for non-shared (Major)
        // categories — an "isAssistantDean() -> return $isShared" short
        // circuit would otherwise wrongly block them from their own
        // College's Major resources.
        if ($isShared && self::isAssistantDean($user)) {
            return true;
        }

        if (self::isCollegeScoped($user)) {
            // Dean/OIC may VIEW/USE shared GenEd/Minor resources for
            // scheduling their own sections, but the institution-wide
            // definition itself belongs to the Assistant Dean — callers
            // that need "can edit the definition" should pass false
            // here and rely on the isShared branch above for write
            // actions, or use canModifySharedDefinition().
            return ! $isShared && self::canAccessCollege($user, $ownerCollegeId);
        }

        // Pure Assistant Dean (no College scope at all): only shared
        // categories are theirs, which the branch above already covers.
        return self::isAssistantDean($user) && $isShared;
    }

    /**
     * SECTION-LEVEL counterpart for the OTHER side of the same boundary:
     * whether $user's Auto Generate run is hard-limited to Major
     * subjects only — i.e. they are a Dean/OIC with NO GenEd/Minor
     * authority over this Section's College. Symmetric to
     * isRestrictedToSharedCategoriesFor() above:
     *   - Plain Assistant Dean, Admin/Registrar: false (not a Dean/OIC
     *     at all — the Major/GenEd/Minor choice for Admin/Registrar
     *     stays a soft preference, not a hard rule).
     *   - Plain Dean/OIC (no is_gened_assistant_dean flag, doesn't
     *     hold the Assistant Dean role): true, always — Major only,
     *     regardless of what $subjectScope preference they pass. Minor/
     *     GenEd subjects for their sections are the Assistant Dean's
     *     job to generate, not theirs.
     *   - Dual-role Dean/OIC who IS also Assistant Dean (e.g. the CTE
     *     Dean who is also the institution's Assistant Dean): false —
     *     isRestrictedToSharedCategoriesFor() already grants them the
     *     full Major + GenEd/Minor set for their OWN College, so they
     *     are not Major-only restricted there.
     */
    public static function isRestrictedToMajorOnlyFor(?User $user, ?int $sectionCollegeId): bool
    {
        if (! self::isCollegeScoped($user)) {
            return false;
        }

        // A dual-role user already gets the full set for their own
        // College via isRestrictedToSharedCategoriesFor() returning
        // false for it — so they are never Major-only restricted.
        return ! self::isAssistantDean($user);
    }

    public static function isSharedCategory(?string $category): bool
    {
        return in_array($category, ['General Education', 'Minor'], true);
    }

    /**
     * SECTION-LEVEL version of the dual-role union in canManageByCategory()
     * — used by AutoScheduleService, where the decision is "which subject
     * CATEGORIES may this run touch in THIS section" rather than a
     * per-subject check.
     *
     * A plain Assistant Dean (no College of their own) is restricted to
     * GenEd/Minor everywhere, exactly as before. But a Dean/OIC who has
     * ALSO been granted GenEd/Minor authority (is_gened_assistant_dean) —
     * e.g. the CTE Dean who is also the institution's Assistant Dean —
     * is only restricted when generating for SOMEONE ELSE'S College.
     * For their OWN College's sections, Major is theirs by virtue of
     * being that College's Dean/OIC, so the restriction must not apply:
     * they get the full Major + GenEd/Minor set, same as any other Dean.
     *
     * $sectionCollegeId is the College that owns the Section being
     * generated (Section->major->department->college_id), NOT the
     * user's own college_id — those two only coincide for the user's
     * own sections.
     */
    public static function isRestrictedToSharedCategoriesFor(?User $user, ?int $sectionCollegeId): bool
    {
        if (! self::isAssistantDean($user)) {
            return false;
        }

        if (self::isCollegeScoped($user) && self::canAccessCollege($user, $sectionCollegeId)) {
            return false;
        }

        return true;
    }

    /**
     * Whether $user may manage the TEACHING QUALIFICATION link between a
     * Faculty member and a Subject of the given category — i.e. "is this
     * faculty allowed to teach this subject", not the subject's
     * institution-wide DEFINITION (title/units/room type — see
     * canModifySharedDefinition()/SubjectPolicy).
     *
     * This is intentionally narrower than isSharedCategory():
     *   - General Education stays Assistant-Dean-exclusive, because a
     *     GenEd faculty pool floats across every College and isn't any
     *     one Dean/OIC's roster to manage.
     *   - Minor, by contrast, is still qualifying a specific faculty
     *     member who DOES belong to one College — so that College's
     *     Dean/OIC may grant/revoke a Minor qualification for their own
     *     faculty, same as they would a Major one. Assistant Dean keeps
     *     institution-wide reach over Minor qualifications too.
     *   - Major is unchanged: Dean/OIC only, own College.
     */
    public static function canManageQualification(?User $user, string $category, ?int $facultyCollegeId): bool
    {
        if (self::isUnrestricted($user)) {
            return true;
        }

        if ($category === 'General Education') {
            return self::isAssistantDean($user);
        }

        if ($category === 'Minor' && self::isAssistantDean($user)) {
            return true;
        }

        return self::isCollegeScoped($user) && self::canAccessCollege($user, $facultyCollegeId);
    }

    /**
     * Dean/OIC may VIEW and USE (assign to their own sections) a
     * shared GenEd/Minor resource, but only Admin/Registrar/Assistant
     * Dean may modify the institution-wide definition itself.
     */
    public static function canModifySharedDefinition(?User $user): bool
    {
        return self::isUnrestricted($user) || self::isAssistantDean($user);
    }

    /**
     * The list of College ids a user's queries should be restricted
     * to, or null to mean "no restriction" (Admin/Registrar/Assistant
     * Dean-for-shared-resources). A dual-role user (Dean/OIC also flagged
     * is_gened_assistant_dean) is treated as College-scoped HERE — this
     * helper isn't category-aware, so it defaults to the safer/narrower
     * scope. Callers touching GenEd/Minor resources should use
     * canManageByCategory()/canModifySharedDefinition() instead, which
     * correctly widen scope for shared categories only. Dean/OIC get a
     * single-id array (or
     * an impossible id if they have no College assigned, so their
     * queries return zero rows rather than leaking data).
     *
     * @return array<int>|null
     */
    public static function visibleCollegeIds(?User $user): ?array
    {
        if (self::isUnrestricted($user)) {
            return null;
        }

        if (self::isCollegeScoped($user)) {
            return self::hasNoAssignedCollege($user) ? [-1] : [$user->college_id];
        }

        // Assistant Dean has no College restriction for GenEd/Minor
        // resources — callers should additionally filter by category.
        if (self::isAssistantDean($user)) {
            return null;
        }

        return [-1];
    }
}