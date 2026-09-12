<?php

namespace App\Services;

use App\Jobs\SendFacultyScheduleEmailJob;
use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\FacultyScheduleEmail;
use App\Models\SectionSubject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Faculty Schedule Email System (spec: Faculty Schedule Email System
 * prompt). Reuses the same SectionSubject data ReportsService's
 * "Schedule by Faculty" report already queries — this service does not
 * introduce a second source of truth for what a faculty member teaches.
 */
class FacultyScheduleEmailService
{
    /**
     * A schedule is only "finalized" (sendable) once every one of the
     * faculty's assigned classes for the term has faculty/day/time all
     * set, AND a Room too — but only for rows that actually need one.
     * An Online split row (SectionSubject::requiresRoom() === false)
     * never gets a Room by design, so requiring one here would leave
     * ANY faculty teaching a Face-to-Face/Online split subject stuck
     * on "Not Finalized" forever, even once every row shows
     * "Scheduled" on the Workload tab and the Section itself reads
     * "Fully Scheduled" — matches the exact same Room-only-if-required
     * rule Section::withSubjectProgressCounts() already uses for that
     * "Fully Scheduled" count, so this can never disagree with it.
     */
    public function isFinalized(Faculty $faculty, AcademicTerm $term): bool
    {
        $rows = $this->scheduleRows($faculty, $term);

        if ($rows->isEmpty()) {
            return false;
        }

        return $rows->every(fn (SectionSubject $ss) => $ss->faculty_id
            && $ss->days
            && $ss->start_time
            && $ss->end_time
            && ($ss->room_id || ! $ss->requiresRoom()));
    }

    /**
     * @return Collection<int, SectionSubject>
     */
    public function scheduleRows(Faculty $faculty, AcademicTerm $term): Collection
    {
        // `sections` has no academic_term_id column — it stores
        // academic_year + semester as plain strings, spelled
        // differently from the Semester model (see
        // AcademicTerm::sectionSemesterValue()). Reuse the same
        // matchingSectionsQuery() every other report/service already
        // relies on for this mapping, instead of filtering on a
        // column that doesn't exist.
        $sectionIds = $term->matchingSectionsQuery()->pluck('id');

        return SectionSubject::query()
            ->where('faculty_id', $faculty->id)
            ->where('is_merged', false)
            ->whereIn('section_id', $sectionIds)
            ->with(['section.major.department.college', 'subject', 'room'])
            ->get();
    }

    public function buildSnapshot(Collection $rows): array
    {
        // SPLIT-DELIVERY / MULTI-SESSION SCHEDULING — same grouping rule
        // ReportsService::scheduleByFaculty() and the Workload tab's
        // assignedPlacements() both apply: every SectionSubject row
        // sharing the same Section+Subject (a Face-to-Face/Online split,
        // for example) is the SAME assigned class with more than one
        // Schedule line, never a second class under a second EDP Code.
        return $rows
            ->groupBy(fn (SectionSubject $ss) => $ss->section_id.'-'.$ss->subject_id)
            ->map(function ($group) {
                $primary = $group->first(fn (SectionSubject $ss) => $ss->delivery_mode !== 'online') ?? $group->first();

                $schedules = $group
                    ->sortBy(fn (SectionSubject $ss) => $ss->delivery_mode === 'online' ? 1 : 0)
                    ->map(fn (SectionSubject $ss) => [
                        'room' => $ss->delivery_mode === 'online' ? 'Online' : $ss->room?->room_name,
                        'days' => $ss->days,
                        'start_time' => $ss->start_time,
                        'end_time' => $ss->end_time,
                    ])
                    ->values();

                return [
                    'edp_code' => $primary->edp_code,
                    // Subject uses subject_code/subject_title (not code/title —
                    // see Subject::$fillable), and Room uses room_name (not
                    // name — see Room::$fillable). Using the wrong attribute
                    // names here silently returned null, which is why Subject
                    // Title and Room came back blank on the PDF/email.
                    'subject_code' => $primary->subject?->subject_code ?? $primary->edp_code,
                    'subject_title' => $primary->subject?->subject_title,
                    'section' => $primary->section?->section_code ?? $primary->section?->section_name,
                    'units' => $primary->subject?->units,
                    // Every Schedule/Room line for this one EDP Code —
                    // the PDF prints all of them under the same row.
                    'schedules' => $schedules->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Validate everything spec sections 4/8/9 require, then queue the
     * email. Returns the FacultyScheduleEmail history row (status
     * 'pending' until the job flips it to sent/failed).
     *
     * @throws ValidationException
     */
    public function send(Faculty $faculty, AcademicTerm $term, User $sender): FacultyScheduleEmail
    {
        if (! $faculty->email) {
            throw ValidationException::withMessages([
                'email' => 'This faculty member does not have an email address. Add an email address to the faculty profile before sending the schedule.',
            ]);
        }

        if (! filter_var($faculty->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'The email address stored for this faculty member is not valid. Please update the faculty profile.',
            ]);
        }

        if (! $this->isFinalized($faculty, $term)) {
            throw ValidationException::withMessages([
                'schedule' => 'This faculty schedule has not been finalized yet. Finalize the schedule before sending it to the faculty member.',
            ]);
        }

        $rows = $this->scheduleRows($faculty, $term);

        $previous = FacultyScheduleEmail::query()
            ->where('faculty_id', $faculty->id)
            ->where('academic_term_id', $term->id)
            ->where('status', 'sent')
            ->orderByDesc('schedule_version')
            ->first();

        $snapshot = $this->buildSnapshot($rows);
        $version = $previous ? $previous->schedule_version : 1;
        $emailType = 'initial';

        if ($previous) {
            $changed = $previous->schedule_snapshot !== $snapshot;
            $version = $changed ? $previous->schedule_version + 1 : $previous->schedule_version;
            $emailType = $changed ? 'updated' : 'resend';
        }

        $record = FacultyScheduleEmail::create([
            'faculty_id' => $faculty->id,
            'academic_term_id' => $term->id,
            'sent_by' => $sender->id,
            'recipient_email' => $faculty->email,
            'schedule_version' => $version,
            'email_type' => $emailType,
            'status' => 'pending',
            'schedule_snapshot' => $snapshot,
            'pdf_filename' => $this->pdfFilename($faculty, $term),
        ]);

        SendFacultyScheduleEmailJob::dispatch($record->id);

        return $record;
    }

    /**
     * Re-send a past record as-is (spec section 11 "Resend") without
     * re-evaluating version bumping — it reuses the original snapshot.
     */
    public function resend(FacultyScheduleEmail $record, User $sender): FacultyScheduleEmail
    {
        $faculty = $record->faculty;

        if (! $faculty->email || ! filter_var($faculty->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'The email address stored for this faculty member is not valid. Please update the faculty profile.',
            ]);
        }

        $copy = FacultyScheduleEmail::create([
            'faculty_id' => $record->faculty_id,
            'academic_term_id' => $record->academic_term_id,
            'sent_by' => $sender->id,
            'recipient_email' => $faculty->email,
            'schedule_version' => $record->schedule_version,
            'email_type' => 'resend',
            'status' => 'pending',
            'schedule_snapshot' => $record->schedule_snapshot,
            'pdf_filename' => $record->pdf_filename,
        ]);

        SendFacultyScheduleEmailJob::dispatch($copy->id);

        return $copy;
    }

    /**
     * Bulk-send finalized schedules to faculty with a valid email for
     * the term (spec section 15/16). By default targets every Active
     * faculty member; pass $facultyIds to scope it to whatever the
     * Reports page currently has filtered/selected (e.g. one college,
     * or a specific multi-select of faculty) instead of the whole
     * school. Returns the counts the confirmation modal displays, and
     * queues one job per eligible faculty member.
     *
     * @param  array<int>|null  $facultyIds
     * @return array{total: int, with_email: int, missing_email: int, queued: int}
     */
    /**
     * "Send All Faculty Schedules" (spec section 15/16).
     *
     * $facultyIds (explicit multi-select on Reports) always wins when
     * present. Otherwise, $collegeId scopes the send to match whatever
     * the Reports page's College/Program filter was showing — without
     * this, picking a College filter but not hand-picking individual
     * faculty names silently fell back to "every Active faculty",
     * emailing the entire school despite the on-screen scope label
     * claiming otherwise. null = "General Education Faculty" (no real
     * College, see Faculty::college_id / GENED_COLLEGE_VALUE on the
     * frontend), 'all' or omitted = no College narrowing at all.
     */
    public function bulkSend(AcademicTerm $term, User $sender, ?array $facultyIds = null, string|int|null $collegeId = null): array
    {
        $faculty = Faculty::query()
            ->where('status', 'Active')
            ->when($facultyIds, fn ($q) => $q->whereIn('id', $facultyIds))
            ->when(! $facultyIds && $collegeId === 'gened', fn ($q) => $q->whereNull('college_id'))
            ->when(! $facultyIds && $collegeId !== null && $collegeId !== 'gened', fn ($q) => $q->where('college_id', $collegeId))
            ->get();

        $missingEmail = 0;
        $queued = 0;

        foreach ($faculty as $member) {
            if (! $member->email) {
                $missingEmail++;

                continue;
            }

            if (! $this->isFinalized($member, $term)) {
                continue;
            }

            try {
                $this->send($member, $term, $sender);
                $queued++;
            } catch (ValidationException) {
                // Invalid email or not finalized — already accounted for
                // above, or skipped silently (bulk send only targets
                // finalized schedules per spec section 4).
            }
        }

        return [
            'total' => $faculty->count(),
            'with_email' => $faculty->whereNotNull('email')->count(),
            'missing_email' => $missingEmail,
            'queued' => $queued,
        ];
    }

    public function pdfFilename(Faculty $faculty, AcademicTerm $term): string
    {
        $name = str_replace(' ', '_', trim($faculty->last_name.' '.$faculty->first_name));
        $year = str_replace('/', '-', (string) $term->schoolYear?->name);
        $semester = str_replace(' ', '-', (string) $term->semester?->name);

        return "{$name}_Faculty_Schedule_{$year}_{$semester}.pdf";
    }
}