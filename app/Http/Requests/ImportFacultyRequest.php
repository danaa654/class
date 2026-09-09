<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the uploaded file for the Faculty Master's Bulk Import
 * (see FacultyController::import()). Only the file itself is checked
 * here — every individual row's data is validated separately inside
 * the controller (each row can succeed or fail independently, unlike
 * a single-record FormRequest that lives or dies as one payload).
 * Mirrors ImportRoomsRequest / ImportSubjectsRequest.
 */
class ImportFacultyRequest extends FormRequest
{
    /**
     * Row-level authorization (Dean/OIC/Assistant Dean's own-College
     * scope vs Admin/Registrar) is enforced per-row in the controller,
     * mirroring StoreFacultyRequest/FacultyPolicy::createForCollege()
     * — this request only confirms the user may reach the Import
     * action at all (FacultyPolicy::create()), checked via
     * authorize() in the controller before parsing begins.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Plain CSV only, matching the Subject Library's and Room
            // Master's Bulk Import — no spreadsheet library is
            // installed in this project. Exporting a Google Sheet/
            // Excel file to CSV before uploading is a one-click step.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Please upload a .csv file (in Excel: File -> Save As -> CSV, or File -> Download -> CSV in Google Sheets).',
            'file.max' => 'The file is too large — please split it into smaller batches (max 5MB).',
        ];
    }
}