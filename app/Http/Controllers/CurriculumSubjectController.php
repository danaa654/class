<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCurriculumItemRequest;
use App\Http\Requests\UpdateCurriculumItemRequest;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumSubjectController extends Controller
{
    /**
     * Display the "Manage Subjects" page for a single Curriculum.
     *
     * This is the Curriculum structure builder: subjects arranged by
     * Year Level and Semester. It does NOT touch scheduling, sections,
     * faculty, or rooms — those are separate, later features.
     */
    public function index(Curriculum $curriculum): Response
    {
        $curriculum->load('major');

        $items = $curriculum->items()
            ->with(['subject', 'prerequisite'])
            ->get()
            ->sortBy([
                fn ($item) => array_search($item->year_level, ['1st Year', '2nd Year', '3rd Year', '4th Year']),
                fn ($item) => array_search($item->semester, ['First Semester', 'Second Semester', 'Summer']),
                fn ($item) => $item->subject?->subject_code,
            ])
            ->values();

        // Subjects already placed in this Curriculum are excluded from the
        // "Subject" dropdown when adding — a Subject may only appear once
        // per Curriculum. The full master list is still used for the
        // Prerequisite dropdown, since a prerequisite can be any subject.
        $placedSubjectIds = $items->pluck('subject_id');

        return Inertia::render('Curriculum/Subjects', [
            'curriculum' => $curriculum,
            'items' => $items,
            'availableSubjects' => Subject::query()
                ->where('is_active', true)
                ->whereNotIn('id', $placedSubjectIds)
                ->orderBy('subject_code')
                ->get(['id', 'subject_code', 'subject_title']),
            'allSubjects' => Subject::query()
                ->where('is_active', true)
                ->orderBy('subject_code')
                ->get(['id', 'subject_code', 'subject_title']),
        ]);
    }

    /**
     * Place one or more master Subjects into the Curriculum at once.
     *
     * The Add Subject dialog now uses a checkbox multi-select, so a single
     * submit can create several CurriculumItem rows sharing the same Year
     * Level / Semester / Remarks. Prerequisite is not set here — it's
     * per-Subject and can be added afterward via Edit.
     */
    public function store(StoreCurriculumItemRequest $request, Curriculum $curriculum): RedirectResponse
    {
        $validated = $request->validated();

        $rows = collect($validated['subject_ids'])->map(fn ($subjectId) => [
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subjectId,
            'year_level' => $validated['year_level'],
            'semester' => $validated['semester'],
            'prerequisite_subject_id' => null,
            'remarks' => $validated['remarks'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Plain insert() bypasses the items() relation's automatic
        // curriculum_id assignment (that only happens via create()/save()),
        // so it's set explicitly on every row above.
        CurriculumItem::insert($rows->all());

        $count = $rows->count();

        return redirect()
            ->route('curriculums.subjects', $curriculum)
            ->with('success', $count === 1 ? 'Subject added to the curriculum.' : "{$count} subjects added to the curriculum.");
    }

    /**
     * Update a Subject's placement (Year Level / Semester / Prerequisite / Remarks).
     */
    public function update(
        UpdateCurriculumItemRequest $request,
        Curriculum $curriculum,
        CurriculumItem $curriculumItem
    ): RedirectResponse {
        abort_unless($curriculumItem->curriculum_id === $curriculum->id, 404);

        $curriculumItem->update($request->validated());

        return redirect()
            ->route('curriculums.subjects', $curriculum)
            ->with('success', 'Curriculum subject updated.');
    }

    /**
     * Remove a Subject from the Curriculum. This only deletes the
     * placement (CurriculumItem) — the master Subject record is untouched.
     */
    public function destroy(Curriculum $curriculum, CurriculumItem $curriculumItem): RedirectResponse
    {
        abort_unless($curriculumItem->curriculum_id === $curriculum->id, 404);

        $curriculumItem->delete();

        return redirect()
            ->route('curriculums.subjects', $curriculum)
            ->with('success', 'Subject removed from the curriculum.');
    }
}