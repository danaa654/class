<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurriculumItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The Add Subject dialog now lets the user check several Subjects at
     * once and place all of them into the same Year Level / Semester in
     * one submit, so `subject_id` becomes `subject_ids` (an array).
     * Prerequisite is intentionally not settable here — it's per-Subject
     * and stays on the single-item Edit dialog.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The {curriculum} route parameter is available via the bound model.
        $curriculumId = $this->route('curriculum')?->id;

        return [
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => [
                'distinct',
                'exists:subjects,id',
                Rule::unique('curriculum_items', 'subject_id')->where(
                    fn ($query) => $query->where('curriculum_id', $curriculumId),
                ),
            ],
            'year_level' => ['required', Rule::in(['1st Year', '2nd Year', '3rd Year', '4th Year'])],
            'semester' => ['required', Rule::in(['First Semester', 'Second Semester', 'Summer'])],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_ids.required' => 'Select at least one subject.',
            'subject_ids.*.distinct' => 'Each subject can only be selected once.',
            'subject_ids.*.unique' => 'One of the selected subjects is already part of this curriculum.',
        ];
    }
}