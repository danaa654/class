<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportSubjectsRequest;
use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Models\College;
use App\Models\Major;
use App\Models\Subject;
use App\Services\NotificationService;
use App\Support\AccessScope;
use App\Support\RoomCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubjectController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Display the Subject Library page.
     *
     * Subjects here form the MASTER LIST of every subject offered by the
     * institution — this is not the Curriculum. The Curriculum will
     * reference these subjects later.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Subject::class);

        $user = $request->user();
        $search = trim((string) $request->query('subject_search', ''));

        // Every authorized role can VIEW the full Subject Library (Dean/
        // OIC need to see GenEd/Minor subjects to schedule their own
        // sections) — the Add/Edit/Delete actions are what's scoped,
        // enforced per-record below and in the frontend via `canManage`.
        $subjects = Subject::query()
            ->with(['college:id,code,name', 'majors:id,name,code,department_id'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('subject_code', 'like', "%{$search}%")
                        ->orWhere('subject_title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhereHas('majors', function ($majorQuery) use ($search) {
                            $majorQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('subject_code')
            ->paginate(10, ['*'], 'subject_page')
            ->withQueryString();

        // Tell the frontend, per row, whether the current user may
        // modify this subject's definition — UI-level enforcement on
        // top of (never instead of) the server-side policy checks in
        // update()/destroy() below.
        $subjects->getCollection()->transform(function (Subject $subject) use ($request) {
            $subject->setAttribute('can_manage', $request->user()->can('update', $subject));

            return $subject;
        });

        return Inertia::render('Subjects/Index', [
            'subjects' => $subjects,
            'filters' => ['subject_search' => $search],
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'majors' => Major::query()
                ->where('status', 'Active')
                ->with('department:id,college_id')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'department_id'])
                ->map(fn (Major $major) => [
                    'id' => $major->id,
                    'name' => $major->name,
                    'code' => $major->code,
                    'college_id' => $major->department?->college_id,
                ]),
            'roomCategories' => RoomCategories::LIST,
            // What this user is allowed to pick, so the form never even
            // offers an option the backend would reject — mirrored by
            // the FormRequest/Policy checks server-side either way.
            'subjectAccess' => [
                'categoryOptions' => $this->categoryOptionsFor($user),
                'lockedCollegeId' => AccessScope::collegeId($user),
                'isCollegeScoped' => AccessScope::isCollegeScoped($user),
                'isAssistantDean' => AccessScope::isAssistantDean($user),
            ],
        ]);
    }

    /**
     * Store a newly created subject in the Subject Library.
     */
    public function store(StoreSubjectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $majorIds = array_values(array_unique(array_filter($data['major_ids'] ?? [])));
        unset($data['major_ids']);

        $data['is_active'] = $data['is_active'] ?? true;
        $data['major_id'] = $majorIds[0] ?? null;

        $this->authorize('createOfCategory', [Subject::class, $data['category'], $data['college_id'] ?? null]);

        $subject = Subject::create($data);
        $subject->majors()->sync($majorIds);

        $this->notifications->subjectCreated($subject, $request->user());

        return redirect()->route('subjects')->with('success', 'Subject created successfully.');
    }

    /**
     * Update an existing subject in the Subject Library.
     */
    public function update(UpdateSubjectRequest $request, Subject $subject): RedirectResponse
    {
        $this->authorize('update', $subject);

        $data = $request->validated();
        $majorIds = array_values(array_unique(array_filter($data['major_ids'] ?? [])));
        unset($data['major_ids']);

        $data['is_active'] = $data['is_active'] ?? true;
        $data['major_id'] = $majorIds[0] ?? null;

        // Fill (don't save yet) so getDirty() tells us exactly which
        // columns actually changed value, not just which keys were
        // present in the form payload — same convention as
        // FacultyController::update(). Cosmetic/free-text fields
        // (description, deployment_remarks, preferred_room_category)
        // are left out of the notification the same way Faculty's
        // remarks/contact info are, per spec ("do not generate
        // unnecessary notifications for insignificant UI changes").
        $subject->fill($data);
        $changedFields = array_intersect(
            array_keys($subject->getDirty()),
            ['subject_code', 'subject_title', 'category', 'college_id', 'subject_type', 'units', 'lecture_hours', 'laboratory_hours', 'required_hours', 'is_active'],
        );
        $changes = array_map(fn (string $field) => [
            'field' => $field,
            'old' => $subject->getOriginal($field),
            'new' => $subject->{$field},
        ], $changedFields);

        $subject->save();
        $subject->majors()->sync($majorIds);

        if (! empty($changes)) {
            $this->notifications->subjectUpdated($subject, $request->user(), $changes);
        }

        return redirect()->route('subjects')->with('success', 'Subject updated successfully.');
    }

    /**
     * Delete a subject from the Subject Library.
     *
     * Blocked if the subject is already mapped into any Curriculum —
     * the Curriculum only ever references the master Subject, so
     * deleting it here would silently break that Curriculum's structure.
     */
    public function destroy(Request $request, Subject $subject): RedirectResponse
    {
        $this->authorize('delete', $subject);

        if ($subject->curriculumItems()->exists()) {
            return redirect()->route('subjects')->with(
                'error',
                'This subject is used in one or more curriculums and cannot be deleted. Remove it from those curriculums first.',
            );
        }

        // Notify/log BEFORE the delete — subjectRecipients() and the
        // activity log entry both need college_id/category/title,
        // which are gone once the row is removed.
        $this->notifications->subjectDeleted($subject, $request->user());

        $subject->delete();

        return redirect()->route('subjects')->with('success', 'Subject deleted successfully.');
    }

    /**
     * Downloadable CSV template for the Bulk Import — column headers
     * plus one example row, so the adviser's curriculum spreadsheet
     * has an exact target shape to copy into rather than guessing
     * column names from the docs.
     */
    public function importTemplate(): StreamedResponse
    {
        $this->authorize('create', Subject::class);

        $columns = [
            'subject_code', 'subject_title', 'category', 'subject_type', 'college',
            'majors', 'units', 'lecture_hours', 'laboratory_hours', 'required_hours',
            'deployment_type', 'preferred_room_category', 'status', 'description',
        ];

        $example = [
            'CC106', 'Application Development and Emerging Technologies', 'Major', 'regular',
            'CCS', 'BSIT', '3', '2', '3', '', '', '', 'Active', '',
        ];

        return response()->streamDownload(function () use ($columns, $example) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $example);
            fclose($handle);
        }, 'classly-subjects-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk Import — Subject Library.
     *
     * Adviser request: adding a whole new curriculum's worth of
     * Subjects one at a time through the Add Subject dialog doesn't
     * scale. This reads a CSV (template above), validates and
     * authorizes EACH ROW independently — mirroring
     * StoreSubjectRequest's rules and SubjectPolicy::createOfCategory()
     * exactly, so an imported row can never end up with looser
     * validation or authorization than a manually-added one — and
     * creates whichever rows pass. Rows that fail are reported back
     * with their line number and reason; they never block the rows
     * that DID pass (same "created/skipped" spirit as
     * SubjectOfferingGeneratorService and generateCurriculumSubjects()
     * elsewhere in this app, rather than an all-or-nothing transaction
     * that discards a whole large file over one typo).
     */
    public function import(ImportSubjectsRequest $request): RedirectResponse
    {
        $this->authorize('create', Subject::class);

        $user = $request->user();
        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return redirect()->route('subjects')->with('error', 'Could not read the uploaded file. Please try again.');
        }

        $header = $this->readImportHeader($handle);
        if ($header === null) {
            fclose($handle);

            return redirect()->route('subjects')->with('error', 'The uploaded file is empty.');
        }
        if (is_array($header) && isset($header['error'])) {
            fclose($handle);

            return redirect()->route('subjects')->with('error', $header['error']);
        }

        $colleges = College::query()->get(['id', 'code', 'name'])->keyBy(fn ($c) => Str::lower($c->code));
        $majors = Major::query()->with('department:id,college_id')->get(['id', 'code', 'name', 'department_id'])->keyBy(fn ($m) => Str::lower($m->code));

        // Preloaded once so every row's "does this subject already
        // exist" check is an in-memory lookup instead of a query per
        // row — same existing-subject check the preview overview
        // uses, kept in sync as rows get created below.
        $existingCodes = Subject::query()->pluck('subject_code')
            ->map(fn ($code) => Str::lower($code))
            ->flip()
            ->map(fn () => true)
            ->all();

        $created = [];
        $skipped = [];
        $errors = [];
        $seenCodesInFile = [];
        $rowNumber = 1; // header is row 1; data starts at row 2

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip fully blank lines (common trailing rows from Excel exports).
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
            $code = Str::lower(trim((string) ($data['subject_code'] ?? '')));

            // Same-as-existing prevention comes FIRST, before any other
            // validation — a subject_code already in the master list
            // (or already seen earlier in this file) is going to be
            // skipped no matter what the rest of the row says, so
            // there's no reason to also validate its Category/Major/
            // hours and risk reporting a confusing "invalid" for a
            // row whose only real issue is "this already exists".
            if ($code !== '' && isset($existingCodes[$code])) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'subject_code' => $data['subject_code'] ?? null,
                    'subject_title' => $data['subject_title'] ?? null,
                ];
                $seenCodesInFile[$code] = true;

                continue;
            }

            try {
                $attributes = $this->validateImportRow($data, $user, $colleges, $majors, $seenCodesInFile);

                $subject = $this->persistImportRow($attributes);
                $created[] = $subject;
                $existingCodes[$code] = true;
                $seenCodesInFile[$code] = true;
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'subject_code' => $data['subject_code'] ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        fclose($handle);

        if (! empty($created)) {
            $this->notifications->subjectsImported($created, $user);
        }

        $createdCount = count($created);
        $skippedCount = count($skipped);
        $errorCount = count($errors);

        $flash = [];
        if ($createdCount > 0) {
            $flash['success'] = $createdCount === 1
                ? '1 subject imported successfully.'
                : "{$createdCount} subjects imported successfully.";
        }
        if ($skippedCount > 0) {
            $flash['success'] = trim(($flash['success'] ?? '').' '.(
                $skippedCount === 1
                    ? '1 subject already existed and was skipped.'
                    : "{$skippedCount} subjects already existed and were skipped."
            ));
        }
        if ($errorCount > 0) {
            $flash['error'] = $createdCount > 0 || $skippedCount > 0
                ? "{$errorCount} row(s) could not be imported — see details below."
                : "None of the {$errorCount} row(s) could be imported — see details below.";
        }
        if ($createdCount === 0 && $skippedCount === 0 && $errorCount === 0) {
            $flash['error'] = 'The file had no data rows to import.';
        }

        // Row-level detail the Add Subject flow has no equivalent
        // for — shared via HandleInertiaRequests exactly like
        // facultyDeletionImpact/roomDeletionImpact, so the modal can
        // show a per-row table instead of only a single flash string.
        $flash['subjectImportErrors'] = $errors;
        $flash['subjectImportSkipped'] = $skipped;

        return redirect()->route('subjects')->with($flash);
    }

    /**
     * Read-only overview of a CSV before anything is saved.
     *
     * Adviser request: before committing a bulk import, show which
     * rows are brand-new and which subject_codes already exist in
     * the master list (e.g. "MMW" already on file) so those can be
     * reviewed/skipped instead of finding out only after the fact.
     * Runs the exact same per-row validation as import() but never
     * persists anything — a preview subject_code is never inserted.
     */
    public function preview(ImportSubjectsRequest $request): JsonResponse
    {
        $this->authorize('create', Subject::class);

        $user = $request->user();
        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return response()->json(['error' => 'Could not read the uploaded file. Please try again.'], 422);
        }

        $header = $this->readImportHeader($handle);
        if ($header === null) {
            fclose($handle);

            return response()->json(['error' => 'The uploaded file is empty.'], 422);
        }
        if (is_array($header) && isset($header['error'])) {
            fclose($handle);

            return response()->json(['error' => $header['error']], 422);
        }

        $colleges = College::query()->get(['id', 'code', 'name'])->keyBy(fn ($c) => Str::lower($c->code));
        $majors = Major::query()->with('department:id,college_id')->get(['id', 'code', 'name', 'department_id'])->keyBy(fn ($m) => Str::lower($m->code));

        $existingCodes = Subject::query()->pluck('subject_code')
            ->map(fn ($code) => Str::lower($code))
            ->flip()
            ->map(fn () => true)
            ->all();

        $rows = [];
        $seenCodesInFile = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
            $code = Str::lower(trim((string) ($data['subject_code'] ?? '')));

            // Existence is checked FIRST, before any other validation
            // — same ordering as import() above, and for the same
            // reason: a row that's going to be skipped for already
            // existing shouldn't also be run through Category/Major/
            // hours checks that were never going to matter for it.
            if ($code !== '' && isset($existingCodes[$code])) {
                $rows[] = [
                    'row' => $rowNumber,
                    'subject_code' => $data['subject_code'] ?? null,
                    'subject_title' => $data['subject_title'] ?? null,
                    'category' => $data['category'] ?? null,
                    'status' => 'exists',
                    'message' => 'A subject with this code already exists — it will be skipped.',
                ];
                $seenCodesInFile[$code] = true;

                continue;
            }

            try {
                $this->validateImportRow($data, $user, $colleges, $majors, $seenCodesInFile);

                $rows[] = [
                    'row' => $rowNumber,
                    'subject_code' => $data['subject_code'] ?? null,
                    'subject_title' => $data['subject_title'] ?? null,
                    'category' => $data['category'] ?? null,
                    'status' => 'new',
                    'message' => null,
                ];
            } catch (\InvalidArgumentException $e) {
                $rows[] = [
                    'row' => $rowNumber,
                    'subject_code' => $data['subject_code'] ?? null,
                    'subject_title' => $data['subject_title'] ?? null,
                    'category' => $data['category'] ?? null,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }

            $seenCodesInFile[$code] = true;
        }

        fclose($handle);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'new' => count(array_filter($rows, fn ($r) => $r['status'] === 'new')),
                'exists' => count(array_filter($rows, fn ($r) => $r['status'] === 'exists')),
                'error' => count(array_filter($rows, fn ($r) => $r['status'] === 'error')),
            ],
        ]);
    }

    /**
     * Parse and validate the CSV header row, shared by import() and
     * preview() so the two never drift apart on what counts as a
     * readable file.
     *
     * @return array<int, string>|array{error: string}|null null = empty file, ['error' => ...] = bad header, otherwise the normalized header
     */
    private function readImportHeader($handle): array|null
    {
        $header = fgetcsv($handle);
        if ($header === false) {
            return null;
        }

        // Header matching is case/whitespace-insensitive so a
        // spreadsheet export with "Subject Code" or " subject_code "
        // still lines up with the template's plain snake_case names.
        $header = array_map(fn ($col) => Str::slug((string) $col, '_'), $header);

        $required = ['subject_code', 'subject_title', 'category', 'units', 'lecture_hours', 'laboratory_hours'];
        $missing = array_diff($required, $header);
        if (! empty($missing)) {
            // Columns that only ever appear in the Room Master's Bulk
            // Import template — if any show up here, the person
            // almost certainly picked the wrong file rather than
            // mistyped a Subject column, so say that plainly instead
            // of just listing what's "missing" from a file that was
            // never meant to be a Subjects CSV in the first place.
            $roomOnlyColumns = ['room_name', 'building', 'room_type', 'room_category'];
            if (! empty(array_intersect($roomOnlyColumns, $header))) {
                return ['error' => 'This looks like a Rooms CSV, not a Subjects CSV. Please upload a file exported for Subject import — download the template below for the exact expected format.'];
            }

            return ['error' => 'The file is missing required column(s): '.implode(', ', $missing).'. Download the template for the exact expected columns.'];
        }

        return $header;
    }

    /**
     * Create the Subject + sync majors for an already-validated row.
     * Split out from validateImportRow() so preview() can run every
     * check up to (but never including) the actual write.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistImportRow(array $attributes): Subject
    {
        return DB::transaction(function () use ($attributes) {
            $majorIds = $attributes['major_ids'];
            unset($attributes['major_ids']);

            $subject = Subject::create($attributes);
            $subject->majors()->sync($majorIds);

            return $subject;
        });
    }

    /**
     * Validate + resolve a single import row into Subject attributes,
     * WITHOUT persisting anything. Throws InvalidArgumentException
     * (caught by the caller, per-row) on any validation/authorization
     * failure so one bad row never aborts the rows around it, and
     * never blocks purely on subject_code already existing — that
     * check is the caller's job (import() skips it, preview() labels
     * it), since "already exists" isn't a validation failure.
     *
     * @param  array<string, mixed>  $data
     * @param  \Illuminate\Support\Collection<string, College>  $colleges  keyed by lowercase code
     * @param  \Illuminate\Support\Collection<string, Major>  $majors  keyed by lowercase code
     * @param  array<string, bool>  $seenCodesInFile
     * @return array<string, mixed>
     */
    private function validateImportRow(array $data, ?\App\Models\User $user, $colleges, $majors, array $seenCodesInFile): array
    {
        // Same College/Category forcing StoreSubjectRequest::prepareForValidation()
        // applies for a manual Add Subject — a Dean/OIC can never
        // import a row into another College or as a non-Major
        // category by editing the spreadsheet, matching the same
        // "never trust the client alone" rule the single-record form
        // already follows.
        if (AccessScope::isCollegeScoped($user)) {
            $data['college'] = null; // resolved to the user's own college below
            $data['category'] = 'Major';
        }

        $category = Str::title(Str::lower((string) ($data['category'] ?? '')));
        if (! in_array($category, ['Major', 'General Education', 'Minor'], true)) {
            throw new \InvalidArgumentException("Invalid category \"{$data['category']}\" — must be Major, General Education, or Minor.");
        }

        if (AccessScope::isAssistantDean($user) && $category === 'Major') {
            throw new \InvalidArgumentException('Assistant Dean may only import GenEd and Minor subjects.');
        }
        if (AccessScope::isCollegeScoped($user) && $category !== 'Major') {
            throw new \InvalidArgumentException('Dean/OIC may only import Major subjects.');
        }

        $collegeId = AccessScope::collegeId($user);
        if (! $collegeId && $category === 'Major') {
            $collegeCode = (string) ($data['college'] ?? '');
            if ($collegeCode === '') {
                throw new \InvalidArgumentException('College is required for a Major subject.');
            }
            $college = $colleges->get(Str::lower($collegeCode));
            if (! $college) {
                throw new \InvalidArgumentException("Unknown college code \"{$collegeCode}\".");
            }
            $collegeId = $college->id;
        }

        $majorIds = [];
        if (! empty($data['majors'])) {
            // Multiple majors in one cell, separated by ; or , —
            // accepts both since a spreadsheet author will reach for
            // whichever is natural (comma is the CSV delimiter
            // itself, but within a quoted cell it's still valid CSV).
            $majorCodes = preg_split('/[;,]/', (string) $data['majors']);
            foreach ($majorCodes as $code) {
                $code = trim($code);
                if ($code === '') {
                    continue;
                }
                $major = $majors->get(Str::lower($code));
                if (! $major) {
                    throw new \InvalidArgumentException("Unknown major code \"{$code}\".");
                }
                if ($collegeId && $major->department?->college_id && (int) $major->department->college_id !== (int) $collegeId) {
                    // Named explicitly for the Dean/OIC case: their
                    // College/Category are silently locked above
                    // (never taken from the CSV's own `college`
                    // column), so a row failing here means it belongs
                    // to a program outside their own department, not
                    // a formatting mistake in the file.
                    $ownCollegeName = AccessScope::isCollegeScoped($user)
                        ? ($colleges->first(fn ($c) => (int) $c->id === (int) $collegeId)?->name ?? 'your College')
                        : null;
                    $reason = $ownCollegeName
                        ? "Major \"{$code}\" belongs to a different College — it doesn't belong to {$ownCollegeName}, which is what your account is scoped to."
                        : "Major \"{$code}\" does not belong to the selected College.";

                    throw new \InvalidArgumentException($reason);
                }
                $majorIds[] = $major->id;
            }
        }
        if ($category === 'Major' && empty($majorIds)) {
            throw new \InvalidArgumentException('At least one major is required for a Major subject.');
        }

        if (! AccessScope::isUnrestricted($user)) {
            $allowed = AccessScope::isAssistantDean($user)
                ? in_array($category, ['General Education', 'Minor'], true)
                : (AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $collegeId));
            if (! $allowed) {
                $message = AccessScope::isAssistantDean($user)
                    ? 'Assistant Dean accounts may only import General Education or Minor subjects — this row is categorized as Major.'
                    : 'This subject does not belong to your College — your account can only import subjects for its own College.';
                throw new \InvalidArgumentException($message);
            }
        }

        $subjectType = Str::lower((string) ($data['subject_type'] ?? 'regular')) ?: 'regular';
        if (! in_array($subjectType, ['regular', 'practicum'], true)) {
            throw new \InvalidArgumentException("Invalid subject_type \"{$data['subject_type']}\" — must be regular or practicum.");
        }

        $requiredHours = null;
        $deploymentType = null;
        if ($subjectType === 'practicum') {
            $requiredHours = is_numeric($data['required_hours'] ?? null) ? (int) $data['required_hours'] : null;
            if ($requiredHours === null || $requiredHours < 1) {
                throw new \InvalidArgumentException('required_hours is required (and must be at least 1) for a practicum subject.');
            }
            $deploymentType = Str::lower((string) ($data['deployment_type'] ?? ''));
            if (! in_array($deploymentType, ['on_campus', 'off_campus'], true)) {
                throw new \InvalidArgumentException('deployment_type must be on_campus or off_campus for a practicum subject.');
            }
        }

        $preferredRoomCategory = trim((string) ($data['preferred_room_category'] ?? ''));
        if ($preferredRoomCategory !== '' && ! in_array($preferredRoomCategory, RoomCategories::LIST, true)) {
            throw new \InvalidArgumentException("Invalid preferred_room_category \"{$preferredRoomCategory}\".");
        }

        $statusValue = Str::lower(trim((string) ($data['status'] ?? 'active')));
        $isActive = ! in_array($statusValue, ['inactive', 'false', '0', 'no'], true);

        $subjectCode = trim((string) ($data['subject_code'] ?? ''));
        $subjectTitle = trim((string) ($data['subject_title'] ?? ''));

        $validator = Validator::make(
            [
                'subject_code' => $subjectCode,
                'subject_title' => $subjectTitle,
                'units' => $data['units'] ?? null,
                'lecture_hours' => $data['lecture_hours'] ?? null,
                'laboratory_hours' => $data['laboratory_hours'] ?? null,
            ],
            [
                // No DB "unique" rule here on purpose — an existing
                // subject_code isn't a validation failure, it's an
                // "already exists" outcome the caller decides what to
                // do with (skip on import, label on preview).
                'subject_code' => ['required', 'string', 'max:20'],
                'subject_title' => ['required', 'string', 'max:255'],
                'units' => ['required', 'integer', 'min:0'],
                'lecture_hours' => ['required', 'integer', 'min:0'],
                'laboratory_hours' => ['required', 'integer', 'min:0'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        // Duplicate subject_code WITHIN this same file — the DB
        // unique rule above only catches a clash against rows already
        // saved before this import started.
        if (isset($seenCodesInFile[Str::lower($subjectCode)])) {
            throw new \InvalidArgumentException("Duplicate subject_code \"{$subjectCode}\" earlier in this file.");
        }

        return [
            'subject_code' => $subjectCode,
            'subject_title' => $subjectTitle,
            'college_id' => $collegeId,
            'major_id' => $majorIds[0] ?? null,
            'major_ids' => $majorIds,
            'category' => $category,
            'subject_type' => $subjectType,
            'units' => (int) $data['units'],
            'lecture_hours' => (int) $data['lecture_hours'],
            'laboratory_hours' => (int) $data['laboratory_hours'],
            'required_hours' => $requiredHours,
            'deployment_type' => $deploymentType,
            'deployment_remarks' => null,
            'preferred_room_category' => $preferredRoomCategory ?: null,
            'is_active' => $isActive,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
        ];
    }

    /**
     * The subject Category values this user's role is allowed to
     * pick from, for populating the Add/Edit Subject form's Category
     * select — Admin/Registrar get all three, Assistant Dean is
     * restricted to the shared types, Dean/OIC to Major only.
     *
     * @return array<int, string>
     */
    private function categoryOptionsFor(?\App\Models\User $user): array
    {
        if (AccessScope::isUnrestricted($user)) {
            return ['Major', 'General Education', 'Minor'];
        }

        if (AccessScope::isAssistantDean($user)) {
            return ['General Education', 'Minor'];
        }

        return ['Major'];
    }
}