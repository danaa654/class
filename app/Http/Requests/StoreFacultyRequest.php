<?php

namespace App\Http\Requests;

use App\Models\FacultyLoadRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacultyRequest extends FormRequest
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
        return [
            'faculty_id' => ['required', 'string', 'max:20', 'unique:faculties,faculty_id'],
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
            // Load", scoped to the requesting user (Admin/Registrar get
            // the institution-wide hard cap when override is on — see
            // FacultyLoadRequest::effectiveCapFor()). Keep in sync with
            // UpdateFacultyRequest.
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