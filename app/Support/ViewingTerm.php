<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the per-user "Viewing Academic Term" — a session-scoped
 * override that lets an Administrator/Registrar/Dean/OIC/Assistant
 * Dean browse the app as if a different (non-Active) Academic Term
 * were current, WITHOUT touching that term's real `status` column or
 * affecting any other user's session.
 *
 * Only roles AccessScope::canSwitchViewingTerm() allows may set this
 * override — every other role always resolves straight to the real
 * Active term, same as before this feature existed. This is
 * intentionally a SESSION value, not a users-table
 * column: it's a "what am I looking at right now" browsing
 * preference, not a durable account setting, so it naturally resets
 * to the real Active term on a fresh login/device.
 *
 * Every controller that used to call AcademicTerm::active() directly
 * for "what term should this page/report default to" should call
 * ViewingTerm::resolve($request) instead. The real Active term is
 * still the one true system-of-record status (enforced by
 * AcademicTerm::booted()) — this class never changes it.
 */
class ViewingTerm
{
    public const SESSION_KEY = 'viewing_academic_term_id';

    /**
     * Per-request memoization for AcademicTerm::active() and
     * resolveOverride(). HandleInertiaRequests calls resolve() and
     * isDeviatingFromActive() as two separate shared props on every
     * single Inertia visit (i.e. every sidebar click), and each of
     * those independently called resolveOverride() and
     * AcademicTerm::active() — so one navigation was running the
     * "active term" query 3x and the "session override" query 2x for
     * no reason. Keyed by spl_object_id($request) so this stays
     * correct even in a persistent-worker setup (Octane) where static
     * state would otherwise leak across requests; a plain php-fpm
     * request only ever sees one Request instance anyway, so this is
     * effectively a request-scoped cache either way.
     *
     * @var array<int, AcademicTerm|null>
     */
    private static array $activeTermCache = [];

    /** @var array<int, array{0: bool, 1: ?AcademicTerm}> */
    private static array $overrideCache = [];

    /**
     * The Academic Term this request's user should see, honoring
     * their session override if one is set and still valid, else the
     * real Active term.
     */
    public static function resolve(Request $request): ?AcademicTerm
    {
        $override = self::resolveOverride($request);

        return $override ?? self::activeTerm($request);
    }

    /**
     * Whether this user's session override, if any, actually points
     * at a DIFFERENT term than the real Active one. This is
     * deliberately NOT the same question as "is an override set" —
     * a Registrar/Admin can explicitly pick the term that happens to
     * BE the real Active term (e.g. after browsing a future semester,
     * clicking back to the one that's actually live), and that must
     * read as "viewing the Active term" everywhere in the UI, not as
     * a false "Planning" state. HandleInertiaRequests' isViewingOverride
     * prop — which drives the header's amber "Planning" badge — uses
     * this, not a raw null-check on the override.
     */
    public static function isDeviatingFromActive(Request $request): bool
    {
        $override = self::resolveOverride($request);

        if ($override === null) {
            return false;
        }

        $activeTerm = self::activeTerm($request);

        return $activeTerm === null || $override->id !== $activeTerm->id;
    }

    /**
     * Just the session override (null if unset, not allowed for this
     * user, or no longer valid e.g. since Archived/deleted) — used by
     * HandleInertiaRequests to tell the frontend whether it's looking
     * at a real Active term or a planning/inactive one.
     */
    public static function resolveOverride(Request $request): ?AcademicTerm
    {
        $key = spl_object_id($request);

        if (array_key_exists($key, self::$overrideCache)) {
            return self::$overrideCache[$key][1];
        }

        $override = self::resolveOverrideUncached($request);
        self::$overrideCache[$key] = [true, $override];

        return $override;
    }

    private static function resolveOverrideUncached(Request $request): ?AcademicTerm
    {
        $user = $request->user();

        if (! AccessScope::canSwitchViewingTerm($user)) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);

        if (! $id) {
            return null;
        }

        // Archived (or deleted) terms are read-only history — an
        // override pointing at one is treated as expired rather than
        // silently keeping the admin stuck viewing a closed term.
        return AcademicTerm::query()
            ->where('status', '!=', 'Archived')
            ->find($id);
    }

    /**
     * The real Active AcademicTerm, memoized per request and eager-
     * loaded with schoolYear/semester — the same shape
     * HandleInertiaRequests' 'activeAcademicTerm' prop needs. Public
     * so that prop can call this directly instead of running its own
     * separate query: before this, a single Inertia visit (i.e. one
     * sidebar click) queried the Active term up to 3 times
     * (activeAcademicTerm, resolve() via viewingAcademicTerm, and
     * isDeviatingFromActive() via isViewingOverride) — now it's once.
     *
     * set()/clear() below intentionally do NOT touch this cache —
     * they only ever run standalone (ViewingTermController), never in
     * the same request as a resolve()/isDeviatingFromActive()/
     * activeTermCached() call.
     */
    public static function activeTermCached(Request $request): ?AcademicTerm
    {
        $key = spl_object_id($request);

        if (! array_key_exists($key, self::$activeTermCache)) {
            self::$activeTermCache[$key] = AcademicTerm::query()
                ->where('status', 'Active')
                ->with(['schoolYear:id,name', 'semester:id,name'])
                ->first(['id', 'school_year_id', 'semester_id', 'status']);
        }

        return self::$activeTermCache[$key];
    }

    /** Internal alias used by resolve()/isDeviatingFromActive() above. */
    private static function activeTerm(Request $request): ?AcademicTerm
    {
        return self::activeTermCached($request);
    }

    /**
     * Set the session override for this request's user to the given
     * Academic Term. Caller (ViewingTermController) is responsible
     * for authorizing and validating the term first.
     */
    public static function set(Request $request, AcademicTerm $academicTerm): void
    {
        $request->session()->put(self::SESSION_KEY, $academicTerm->id);
    }

    /**
     * Clear the override, returning this user's session to the real
     * Active term.
     */
    public static function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /** Whether $user is allowed to use the term switch at all. */
    public static function canSwitch(?User $user): bool
    {
        return AccessScope::canSwitchViewingTerm($user);
    }
}