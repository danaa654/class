<?php

namespace App\Http\Requests;

use App\Models\FacultyLoadRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacultyRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The {faculty} route parameter is available via the bound model.
        $facultyId = $this->route('faculty')?->id;

        return [
            'faculty_id' => ['required', 'string', 'max:20', Rule::unique('faculties', 'faculty_id')->ignore($facultyId)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'employment_type' => ['required', Rule::in(['Full-time', 'Part-time'])],
            // College is optional: leaving it blank makes this a General
            // Education Faculty member (no department), picking one makes
            // them Department Faculty. See Faculty::getFacultyCategoryAttribute().
            'college_id' => ['nullable', 'exists:colleges,id'],
            // Cap follows Settings > Faculty & Workload > "Max Teaching
            // Load", scoped to the requesting user — Admin/Registrar
            // only reach FacultyLoadRequest::HARD_CAP_UNITS (the true
            // institution-wide ceiling) when "Allow Administrator
            // override" is on; otherwise everyone is held to the
            // configured value. Keep in sync with StoreFacultyRequest.
            // Raising a faculty member above their current value is
            // Admin/Registrar-only in practice — see
            // FacultyController::update()'s pin-back of this field for
            // other roles, and FacultyLoadRequest for how Dean/OIC/
            // Assistant Dean request an increase instead.
            'max_teaching_units' => ['required', 'integer', 'min:0', 'max:'.FacultyLoadRequest::effectiveCapFor($this->user())],
            // Whichever workload measurement the institution uses.
            // 'units' (default) checks against max_teaching_units;
            // 'hours' checks against max_weekly_hours instead. See
            // FacultyWorkloadService.
            'workload_type' => ['nullable', Rule::in(['units', 'hours'])],
            'max_weekly_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}