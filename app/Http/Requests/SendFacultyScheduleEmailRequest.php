<?php

namespace App\Http\Requests;

use App\Models\Faculty;
use App\Support\AccessScope;
use Illuminate\Foundation\Http\FormRequest;

class SendFacultyScheduleEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Administrator/Registrar may send any faculty's schedule,
        // institution-wide — unchanged from spec section 18.
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        // Dean/OIC may also send — but only for their OWN faculty
        // (same College), matching the scope
        // FacultyScheduleEmailController::bulkSend() already enforces
        // for "Send All". A Dean/OIC with no College assigned yet is
        // never treated as unrestricted (AccessScope::hasNoAssignedCollege()'s
        // rule), so they get no access here either. Any other role
        // (Faculty, Assistant Dean, etc.) is forbidden outright, same
        // as before.
        if (AccessScope::isCollegeScoped($user)) {
            if (AccessScope::hasNoAssignedCollege($user)) {
                return false;
            }

            $faculty = Faculty::find($this->input('faculty_id'));

            return $faculty && AccessScope::canAccessCollege($user, $faculty->college_id);
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'faculty_id' => ['required', 'integer', 'exists:faculties,id'],
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }
}