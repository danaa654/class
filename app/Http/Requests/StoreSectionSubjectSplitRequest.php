<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the "Split Hours" modal payload — turning one
 * SectionSubject row into a Face-to-Face row + an Online row.
 *
 * Two rules, both enforced in withValidator() because they need the
 * Subject's actual hours to check against:
 *
 *   1. f2f_hours + online_hours MUST equal the Subject's total
 *      required weekly hours (lecture_hours + laboratory_hours) —
 *      the split can redistribute hours between modes, never lose or
 *      invent hours.
 *
 *   2. online_hours can NEVER exceed the Subject's lecture_hours.
 *      Laboratory hours require hands-on/equipment time and are
 *      never eligible to move Online — this mirrors
 *      MeetingPatternService::classify()'s existing Lecture vs.
 *      Laboratory distinction. A Subject with 3 lab hrs + 2 lecture
 *      hrs can send at most 2 hrs Online; the remaining 3 (all lab,
 *      plus any lecture hours the Registrar chooses to keep
 *      Face-to-Face) stay Face-to-Face.
 */
class StoreSectionSubjectSplitRequest extends FormRequest
{
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
            'f2f_hours' => ['required', 'integer', 'min:0'],
            'online_hours' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'online_hours.min' => 'Online hours must be at least 1 — otherwise there is nothing to split.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sectionSubject = $this->route('subject');
            $subject = $sectionSubject?->subject;

            if (! $subject) {
                return;
            }

            if ($sectionSubject->component !== 'combined') {
                $validator->errors()->add(
                    'f2f_hours',
                    'This subject has already been split. Undo the existing split before splitting it again.'
                );

                return;
            }

            $totalHours = (int) $subject->lecture_hours + (int) $subject->laboratory_hours;
            $f2fHours = (int) $this->input('f2f_hours');
            $onlineHours = (int) $this->input('online_hours');

            if (($f2fHours + $onlineHours) !== $totalHours) {
                $validator->errors()->add(
                    'f2f_hours',
                    "Face-to-Face and Online hours must add up to this subject's required weekly hours ({$totalHours})."
                );
            }

            if ($onlineHours > (int) $subject->lecture_hours) {
                $validator->errors()->add(
                    'online_hours',
                    "Only Lecture hours may be scheduled Online for this subject (max {$subject->lecture_hours} hr(s)). Laboratory hours require Face-to-Face."
                );
            }
        });
    }
}