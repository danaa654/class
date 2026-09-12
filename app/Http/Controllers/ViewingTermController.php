<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Support\ViewingTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lets an Administrator/Registrar/Dean/OIC/Assistant Dean switch which
 * Academic Term THEY
 * personally see across the app (Dashboard, Reports, Settings,
 * Sections defaulting, etc.) without changing the real system-wide
 * Active term or affecting any other user.
 *
 * See App\Support\ViewingTerm for the full reasoning — this
 * controller is intentionally thin: it only authorizes + validates,
 * then delegates the actual session write to that class.
 */
class ViewingTermController extends Controller
{
    /**
     * Switch the current user's session to view the given Academic
     * Term. Only a role AccessScope::canSwitchViewingTerm() allows may
     * do this (Administrator/Registrar/Dean/OIC/Assistant Dean) —
     * anyone else hitting this route directly is rejected, not
     * silently ignored, so a scripted request can't quietly succeed
     * for a role that shouldn't have this ability.
     *
     * An Archived term can't be switched to — it's closed history,
     * not something to actively "view" as a working context.
     */
    public function update(Request $request): RedirectResponse
    {
        if (! ViewingTerm::canSwitch($request->user())) {
            abort(403, 'Your role is not permitted to switch the viewing academic term.');
        }

        $validated = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ]);

        $academicTerm = AcademicTerm::findOrFail($validated['academic_term_id']);

        if ($academicTerm->status === 'Archived') {
            throw ValidationException::withMessages([
                'academic_term_id' => 'An Archived academic term cannot be selected as the viewing term.',
            ]);
        }

        ViewingTerm::set($request, $academicTerm);

        $academicTerm->loadMissing(['schoolYear:id,name', 'semester:id,name']);
        $label = trim(($academicTerm->schoolYear?->name ?? '').' • '.($academicTerm->semester?->name ?? ''), ' •');

        return back()->with('success', "Now viewing {$label}.");
    }

    /**
     * Return this user's session to the real Active term.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if (! ViewingTerm::canSwitch($request->user())) {
            abort(403, 'Your role is not permitted to switch the viewing academic term.');
        }

        ViewingTerm::clear($request);

        return back()->with('success', 'Now viewing the active academic term.');
    }
}