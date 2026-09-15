<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportRoomsRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\College;
use App\Models\Department;
use App\Models\Room;
use App\Services\NotificationService;
use App\Services\RoomUtilizationService;
use App\Support\RoomCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoomController extends Controller
{
    /** Roles that may see every Room regardless of College/Department scope. */
    private const UNSCOPED_ROLES = ['Administrator', 'Registrar'];

    public function __construct(
        private readonly RoomUtilizationService $utilization,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Display the Rooms page — now the authoritative resource-management
     * view for the scheduling engine: Utilization, Availability, a daily
     * breakdown, summary cards, and Room-aware filters, layered on top
     * of the existing Room Master CRUD.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Room::class);

        $search = trim((string) $request->query('room_search', ''));
        $quickFilter = (string) $request->query('quick_filter', 'all');
        $utilizationMin = $request->query('utilization_min');
        $utilizationMax = $request->query('utilization_max');
        $availabilityFilter = (string) $request->query('availability', '');

        $roomsQuery = Room::query()
            ->with(['department', 'college'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('room_code', 'like', "%{$search}%")
                        ->orWhere('room_name', 'like', "%{$search}%")
                        ->orWhere('building', 'like', "%{$search}%")
                        ->orWhere('floor', 'like', "%{$search}%")
                        ->orWhere('room_type', 'like', "%{$search}%")
                        ->orWhere('room_category', 'like', "%{$search}%");
                });
            })
            ->when($request->query('building'), fn ($q, $v) => $q->where('building', $v))
            ->when($request->query('floor'), fn ($q, $v) => $q->where('floor', $v))
            ->when($request->query('room_type'), fn ($q, $v) => $q->where('room_type', $v))
            ->when($request->query('college_id'), fn ($q, $v) => $q->where('college_id', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v));

        $this->applyScope($roomsQuery, $request);

        // Utilization/Availability are computed per-room from live
        // schedules, so they can't be filtered in SQL — pull the
        // (already-filtered-by-search/building/etc) set, summarize it
        // in one batch, then filter/paginate in memory.
        //
        // PERFORMANCE: this full fetch + summarize runs on every visit
        // to this page (i.e. every sidebar click into Rooms), even
        // though the underlying schedule data rarely changes between
        // two clicks a few seconds apart. Cache the (rooms, summaries)
        // pair for a short window, keyed by the exact filter query and
        // the user's Viewing Term, so repeat/rapid navigation into this
        // page is instant instead of re-querying + re-computing every
        // time. Short TTL (15s) keeps staleness negligible for an
        // admin tool while still absorbing the common "click away and
        // click back" / pagination-click pattern that was the main
        // source of the lag.
        $cacheKey = 'rooms.index.'.md5(serialize([
            \App\Support\ViewingTerm::resolve($request)?->id,
            $request->query(),
        ]));

        [$allRooms, $summaries] = Cache::remember($cacheKey, now()->addSeconds(15), function () use ($roomsQuery) {
            $rooms = $roomsQuery->orderBy('room_code')->get();

            return [$rooms, $this->utilization->summarizeRooms($rooms)];
        });

        $filtered = $allRooms->filter(function (Room $room) use ($summaries, $quickFilter, $utilizationMin, $utilizationMax, $availabilityFilter) {
            $summary = $summaries[$room->id];

            if ($utilizationMin !== null && $summary['utilization_percent'] < (float) $utilizationMin) {
                return false;
            }
            if ($utilizationMax !== null && $summary['utilization_percent'] > (float) $utilizationMax) {
                return false;
            }
            if ($availabilityFilter !== '' && $summary['availability'] !== $availabilityFilter) {
                return false;
            }

            return match ($quickFilter) {
                'available' => $summary['availability'] === 'Available',
                'fully_booked' => $summary['availability'] === 'Fully Booked',
                'high_usage' => $summary['utilization_status'] === 'High Usage',
                'conflicts' => $summary['has_conflict'],
                'inactive' => $room->status !== 'Active',
                default => true,
            };
        })->values();

        $page = max(1, (int) $request->query('room_page', 1));
        $perPage = 10;
        $paged = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        $rooms = new \Illuminate\Pagination\LengthAwarePaginator(
            $paged,
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'room_page', 'query' => $request->query()]
        );

        $rooms->getCollection()->transform(function (Room $room) use ($summaries) {
            $room->setAttribute('utilization', $summaries[$room->id]);

            return $room;
        });

        return Inertia::render('Scheduling/Rooms/Index', [
            'rooms' => $rooms,
            'summary' => $this->usageSummary($allRooms, $summaries),
            'filters' => [
                'room_search' => $search,
                'quick_filter' => $quickFilter,
                'building' => $request->query('building', ''),
                'floor' => $request->query('floor', ''),
                'room_type' => $request->query('room_type', ''),
                'college_id' => $request->query('college_id', ''),
                'department_id' => $request->query('department_id', ''),
                'status' => $request->query('status', ''),
                'utilization_min' => $utilizationMin,
                'utilization_max' => $utilizationMax,
                'availability' => $availabilityFilter,
            ],
            'roomTypes' => StoreRoomRequest::ROOM_TYPES,
            'roomCategories' => RoomCategories::LIST,
            'buildings' => Room::query()->whereNotNull('building')->distinct()->orderBy('building')->pluck('building'),
            'floors' => Room::query()->whereNotNull('floor')->distinct()->orderBy('floor')->pluck('floor'),
            'departments' => Department::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name', 'college_id']),
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Room Schedule Details — weekly timetable + utilization breakdown
     * for one Room, powering the Rooms page's schedule modal.
     */
    public function schedule(Request $request, Room $room): JsonResponse
    {
        $room->load(['department', 'college']);

        return response()->json([
            'room' => $room,
            'summary' => $this->utilization->summarizeRoom($room),
            'timetable' => $this->utilization->timetableForRoom($room),
        ]);
    }

    /**
     * Store a newly created room in the Room Master.
     */
    public function store(StoreRoomRequest $request): RedirectResponse
    {
        $this->authorize('create', Room::class);

        $room = Room::create($request->validated());

        $this->notifications->roomCreated($room, $request->user());

        return redirect()->route('scheduling.rooms')->with('success', 'Room added successfully.');
    }

    /**
     * Update an existing room in the Room Master.
     *
     * Room administration (capacity, type, College/Department
     * restriction) remains an Admin/Registrar responsibility (spec
     * Section 7, 16) — Assistant Dean and Dean/OIC may view and use
     * rooms for scheduling, but never edit the Room Master record.
     */
    public function update(UpdateRoomRequest $request, Room $room): RedirectResponse
    {
        $this->authorize('update', $room);

        $data = $request->validated();

        // Fill (don't save yet) so getDirty() tells us exactly which
        // columns actually changed value — same convention as
        // SubjectController::update()/FacultyController::update().
        $room->fill($data);
        $changedFields = array_intersect(
            array_keys($room->getDirty()),
            ['room_name', 'building', 'floor', 'room_type', 'room_category', 'department_id', 'college_id', 'capacity', 'status'],
        );
        $changes = array_map(fn (string $field) => [
            'field' => $field,
            'old' => $room->getOriginal($field),
            'new' => $room->{$field},
        ], $changedFields);

        $room->save();

        if (! empty($changes)) {
            $this->notifications->roomUpdated($room, $request->user(), $changes);
        }

        return redirect()->route('scheduling.rooms')->with('success', 'Room updated successfully.');
    }

    /**
     * Delete a room from the Room Master.
     *
     * Double confirmation, mirroring FacultyController::destroy(): a
     * Room tied to a finalized/locked Section can never be deleted
     * outright (unlock and reassign first). A Room with active
     * (non-finalized) placements may still be deleted, but only after
     * the admin/registrar confirms the warning — deleting it leaves
     * those scheduled SectionSubject rows without a Room.
     */
    public function destroy(Request $request, Room $room): RedirectResponse
    {
        $this->authorize('delete', $room);

        $impact = $this->utilization->deletionImpact($room);

        if ($impact['has_finalized_assignment']) {
            return back()->with('error', 'This room is assigned to a finalized schedule ('.implode(', ', $impact['finalized_section_codes']).'). Unlock the affected section(s) and reassign before deleting.');
        }

        if ($impact['has_active_assignments'] && ! $request->boolean('confirmed')) {
            return back()->with('error', 'This room has active scheduled classes. Confirm the warning to proceed.')
                ->with('roomDeletionImpact', $impact);
        }

        // Notify/log BEFORE the delete — roomRecipients() and the
        // activity log entry both need college_id/room_name, which
        // are gone once the row is removed.
        $this->notifications->roomDeleted($room, $request->user());

        $room->delete();

        return redirect()->route('scheduling.rooms')->with('success', 'Room deleted successfully.');
    }

    /**
     * Downloadable CSV template for the Room Master's Bulk Import —
     * column headers plus one example row, so a facilities/registrar
     * spreadsheet has an exact target shape to copy into rather than
     * guessing column names from the docs. Mirrors
     * SubjectController::importTemplate().
     */
    public function importTemplate(): StreamedResponse
    {
        $this->authorize('create', Room::class);

        $columns = [
            'room_name', 'building', 'floor', 'room_type',
            'room_category', 'college', 'department', 'capacity', 'status', 'remarks',
        ];

        $example = [
            'Computer Laboratory 1', 'Main Building', '2nd Floor', 'Laboratory',
            '', 'CCS', 'CCS-BSIT', '40', 'Active', '',
        ];

        return response()->streamDownload(function () use ($columns, $example) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $example);
            fclose($handle);
        }, 'classly-rooms-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk Import — Room Master.
     *
     * Adding a whole building's worth of Rooms one at a time through
     * the Add Room dialog doesn't scale. This reads a CSV (template
     * above), validates each row independently — mirroring
     * StoreRoomRequest's rules exactly, so an imported row can never
     * end up with looser validation than a manually-added one — and
     * creates whichever rows pass. Rows that fail are reported back
     * with their line number and reason; they never block the rows
     * that DID pass. Same created/skipped/error shape as
     * SubjectController::import().
     */
    public function import(ImportRoomsRequest $request): RedirectResponse
    {
        $this->authorize('create', Room::class);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return redirect()->route('scheduling.rooms')->with('error', 'Could not read the uploaded file. Please try again.');
        }

        $header = $this->readImportHeader($handle);
        if ($header === null) {
            fclose($handle);

            return redirect()->route('scheduling.rooms')->with('error', 'The uploaded file is empty.');
        }
        if (is_array($header) && isset($header['error'])) {
            fclose($handle);

            return redirect()->route('scheduling.rooms')->with('error', $header['error']);
        }

        $colleges = College::query()->get(['id', 'code', 'name'])->keyBy(fn ($c) => Str::lower($c->code));
        $departments = Department::query()->get(['id', 'code', 'name', 'college_id'])->keyBy(fn ($d) => Str::lower($d->code));

        // room_code is never collected from the CSV — the Add Room
        // form doesn't ask for it either; it's silently auto-derived
        // from the Room Name (see the frontend's deriveRoomCode()).
        // $usedCodes seeds that derivation with every code already in
        // the database so an imported room can never collide with an
        // existing one, and dedup against the *rooms already in this
        // file* keeps two rows in the same CSV from colliding with
        // each other either.
        $usedCodes = Room::query()->pluck('room_code')
            ->map(fn ($code) => Str::lower($code))
            ->flip()
            ->map(fn () => true)
            ->all();

        // "Already exists" for import purposes means the exact same
        // Room Name + Building already exists — the only realistic
        // duplicate signal available now that a code is never
        // supplied by the file (two different rooms can easily
        // derive colliding codes and that's expected, not a
        // duplicate).
        $existingRoomKeys = Room::query()->get(['room_name', 'building'])
            ->map(fn (Room $r) => Str::lower(trim($r->room_name)).'|'.Str::lower(trim((string) $r->building)))
            ->flip()
            ->map(fn () => true)
            ->all();

        // Secondary near-duplicate signal: two Room Names that read
        // differently but strip down to the same auto-generated code
        // ("Computer Lab-3" vs "Computer Laboratory 3") are almost
        // always the same physical room misspelled/reformatted, not a
        // genuinely new one — catches what the exact Name + Building
        // match above would miss.
        $existingByCode = Room::query()->get(['room_code', 'room_name', 'building'])
            ->keyBy(fn (Room $r) => Str::lower($r->room_code));

        $created = [];
        $skipped = [];
        $errors = [];
        $rowNumber = 1; // header is row 1; data starts at row 2

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
            $roomKey = Str::lower(trim((string) ($data['room_name'] ?? ''))).'|'.Str::lower(trim((string) ($data['building'] ?? '')));
            $duplicateReason = $this->duplicateRoomMessage((string) ($data['room_name'] ?? ''), (string) ($data['building'] ?? ''), $existingRoomKeys, $existingByCode);

            if ($duplicateReason !== null) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'room_name' => $data['room_name'] ?? null,
                    'building' => $data['building'] ?? null,
                    'reason' => $duplicateReason,
                ];

                continue;
            }

            try {
                $attributes = $this->validateImportRow($data, $colleges, $departments);
                $attributes['room_code'] = $this->resolveUniqueRoomCode($attributes['room_name'], $usedCodes);

                $room = Room::create($attributes);
                $created[] = $room;
                $existingRoomKeys[$roomKey] = true;
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'room_name' => $data['room_name'] ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        fclose($handle);

        if (! empty($created)) {
            $this->notifications->roomsImported($created, $request->user());
        }

        $createdCount = count($created);
        $skippedCount = count($skipped);
        $errorCount = count($errors);

        $flash = [];
        if ($createdCount > 0) {
            $flash['success'] = $createdCount === 1
                ? '1 room imported successfully.'
                : "{$createdCount} rooms imported successfully.";
        }
        if ($skippedCount > 0) {
            $flash['success'] = trim(($flash['success'] ?? '').' '.(
                $skippedCount === 1
                    ? '1 room already existed and was skipped.'
                    : "{$skippedCount} rooms already existed and were skipped."
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

        $flash['roomImportErrors'] = $errors;
        $flash['roomImportSkipped'] = $skipped;

        return redirect()->route('scheduling.rooms')->with($flash);
    }

    /**
     * Read-only overview of a CSV before anything is saved — same
     * "New / Already Exists / Invalid" preview the Subject Library's
     * Bulk Import gives, run through the exact same per-row
     * validation as import() but never persisting anything.
     */
    public function preview(ImportRoomsRequest $request): JsonResponse
    {
        $this->authorize('create', Room::class);

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
        $departments = Department::query()->get(['id', 'code', 'name', 'college_id'])->keyBy(fn ($d) => Str::lower($d->code));

        $existingRoomKeys = Room::query()->get(['room_name', 'building'])
            ->map(fn (Room $r) => Str::lower(trim($r->room_name)).'|'.Str::lower(trim((string) $r->building)))
            ->flip()
            ->map(fn () => true)
            ->all();

        $existingByCode = Room::query()->get(['room_code', 'room_name', 'building'])
            ->keyBy(fn (Room $r) => Str::lower($r->room_code));

        $rows = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
            $roomKey = Str::lower(trim((string) ($data['room_name'] ?? ''))).'|'.Str::lower(trim((string) ($data['building'] ?? '')));
            $duplicateReason = $this->duplicateRoomMessage((string) ($data['room_name'] ?? ''), (string) ($data['building'] ?? ''), $existingRoomKeys, $existingByCode);

            if ($duplicateReason !== null) {
                $rows[] = [
                    'row' => $rowNumber,
                    'room_name' => $data['room_name'] ?? null,
                    'building' => $data['building'] ?? null,
                    'status' => 'exists',
                    'message' => $duplicateReason,
                ];

                continue;
            }


            try {
                $this->validateImportRow($data, $colleges, $departments);

                $rows[] = [
                    'row' => $rowNumber,
                    'room_name' => $data['room_name'] ?? null,
                    'building' => $data['building'] ?? null,
                    'status' => 'new',
                    'message' => null,
                ];
            } catch (\InvalidArgumentException $e) {
                $rows[] = [
                    'row' => $rowNumber,
                    'room_name' => $data['room_name'] ?? null,
                    'building' => $data['building'] ?? null,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }

            $existingRoomKeys[$roomKey] = true;
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

        $header = array_map(fn ($col) => Str::slug((string) $col, '_'), $header);

        $required = ['room_name', 'building', 'room_type', 'capacity'];
        $missing = array_diff($required, $header);

        if (! empty($missing)) {
            // Columns that only ever appear in the Subject Library's
            // Bulk Import template — if any show up here, the person
            // almost certainly picked the wrong file rather than
            // mistyped a Room column, so say that plainly instead of
            // just listing what's "missing" from a file that was
            // never meant to be a Rooms CSV in the first place.
            $subjectOnlyColumns = ['subject_code', 'subject_title', 'lecture_hours', 'laboratory_hours', 'subject_type'];
            if (! empty(array_intersect($subjectOnlyColumns, $header))) {
                return ['error' => 'This looks like a Subjects CSV, not a Rooms CSV. Please upload a file exported for Room import — download the template below for the exact expected format.'];
            }

            return ['error' => 'The CSV is missing required column(s): '.implode(', ', $missing).'. Download the template for the exact expected format.'];
        }

        return $header;
    }

    /**
     * Validate + normalize one CSV row into Room::create()-ready
     * attributes, mirroring StoreRoomRequest's rules exactly so an
     * imported row is never held to looser standards than a manually
     * added one. Throws InvalidArgumentException with a human-readable
     * reason on any failure — caught by both import() and preview().
     *
     * @return array<string, mixed>
     */
    private function validateImportRow(array $data, $colleges, $departments): array
    {
        $roomName = trim((string) ($data['room_name'] ?? ''));
        $building = trim((string) ($data['building'] ?? ''));
        $roomType = trim((string) ($data['room_type'] ?? ''));

        $validator = Validator::make(
            [
                'room_name' => $roomName,
                'building' => $building,
                'room_type' => $roomType,
                'capacity' => $data['capacity'] ?? null,
            ],
            [
                'room_name' => ['required', 'string', 'max:255'],
                'building' => ['required', 'string', 'max:255'],
                'room_type' => ['required', Rule::in(StoreRoomRequest::ROOM_TYPES)],
                'capacity' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        $roomCategory = trim((string) ($data['room_category'] ?? ''));
        if ($roomCategory !== '' && ! in_array($roomCategory, RoomCategories::LIST, true)) {
            throw new \InvalidArgumentException("Invalid room_category \"{$roomCategory}\".");
        }

        // college_id = null means "All Colleges" (a shared room), same
        // meaning as leaving the College field blank on the Add/Edit
        // Room form.
        $collegeId = null;
        $collegeCode = (string) ($data['college'] ?? '');
        if (trim($collegeCode) !== '') {
            $college = $colleges->get(Str::lower(trim($collegeCode)));
            if (! $college) {
                throw new \InvalidArgumentException("Unknown college code \"{$collegeCode}\".");
            }
            $collegeId = $college->id;
        }

        // department_id = null means "All Programs" within the
        // selected College. When a department IS given, it must
        // actually belong to the row's College — same rule as
        // StoreRoomRequest — so a room can never end up pointing at,
        // say, BSIT while assigned to the College of Criminology.
        $departmentId = null;
        $departmentCode = (string) ($data['department'] ?? '');
        if (trim($departmentCode) !== '') {
            $department = $departments->get(Str::lower(trim($departmentCode)));
            if (! $department) {
                throw new \InvalidArgumentException("Unknown department code \"{$departmentCode}\".");
            }
            if ($collegeId && (int) $department->college_id !== (int) $collegeId) {
                throw new \InvalidArgumentException("Department \"{$departmentCode}\" does not belong to the selected College.");
            }
            $departmentId = $department->id;
        }

        $statusValue = Str::lower(trim((string) ($data['status'] ?? 'active')));
        if ($statusValue !== '' && ! in_array($statusValue, ['active', 'inactive'], true)) {
            throw new \InvalidArgumentException("Invalid status \"{$data['status']}\" — must be Active or Inactive.");
        }
        $status = $statusValue === 'inactive' ? 'Inactive' : 'Active';

        return [
            'room_name' => $roomName,
            'building' => $building,
            'floor' => trim((string) ($data['floor'] ?? '')) ?: null,
            'room_type' => $roomType,
            'room_category' => $roomCategory ?: null,
            'college_id' => $collegeId,
            'department_id' => $departmentId,
            'capacity' => (int) $data['capacity'],
            'status' => $status,
            'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
        ];
    }

    /**
     * Whether a CSV row's Room Name/Building looks like a duplicate of
     * a Room already in the database — either an exact Name+Building
     * match, or a Room Name that reduces to the same auto-generated
     * code as an existing room (a near-duplicate the exact match alone
     * would miss, e.g. "Computer Lab-3" vs "Computer Laboratory 3").
     * Returns the reason to show/skip with, or null if it's genuinely
     * new. Shared by import() and preview() so they can never
     * disagree on what counts as a duplicate.
     */
    private function duplicateRoomMessage(string $roomName, string $building, array $existingRoomKeys, $existingByCode): ?string
    {
        $key = Str::lower(trim($roomName)).'|'.Str::lower(trim($building));
        if (isset($existingRoomKeys[$key])) {
            return 'A room with this Name and Building already exists — it will be skipped.';
        }

        $baseCode = Str::lower($this->deriveRoomCode(trim($roomName)));
        if ($baseCode !== '' && $existingByCode->has($baseCode)) {
            $match = $existingByCode->get($baseCode);

            return "This looks like a duplicate of the existing room \"{$match->room_name}\" ({$match->building}) — its name reduces to the same auto-generated code. It will be skipped.";
        }

        return null;
    }

    /**
     * Derive a Room Code from a Room Name exactly the way the Add/Edit
     * Room form does client-side (Index.vue's deriveRoomCode()): strip
     * to uppercase alphanumerics, truncate to fit the room_code
     * column. The CSV never supplies a code — like the manual form,
     * one is always auto-generated, never typed.
     */
    private function deriveRoomCode(string $roomName, string $suffix = ''): string
    {
        $base = Str::upper(preg_replace('/[^A-Z0-9]/i', '', $roomName) ?? '');
        $base = Str::substr($base, 0, 20 - Str::length($suffix));

        return $base.$suffix;
    }

    /**
     * Resolve a Room Code guaranteed not to collide with any code
     * already in $usedCodes (existing DB rows plus every row already
     * created earlier in this same import) — mirroring the manual Add
     * Room flow's retry-with-a-random-suffix behavior, but
     * deterministic here since a batch import can't prompt the user
     * to retry. Marks the resolved code as used before returning it.
     */
    private function resolveUniqueRoomCode(string $roomName, array &$usedCodes): string
    {
        $base = $this->deriveRoomCode($roomName) ?: 'ROOM';
        $code = $base;
        $attempt = 1;

        while (isset($usedCodes[Str::lower($code)])) {
            $suffix = (string) (100 + $attempt);
            $code = $this->deriveRoomCode($roomName, $suffix) ?: $suffix;
            $attempt++;
        }

        $usedCodes[Str::lower($code)] = true;

        return $code;
    }

    /**
     * Restrict which Rooms a non-unscoped role may see, matching the
     * scope rule already used for Faculty (no College = shared
     * General Education/Minor resource, visible to everyone):
     *
     *   - Administrator / Registrar: every Room.
     *   - Dean / OIC: Rooms with no College (shared) plus Rooms
     *     belonging to their own College.
     *   - Assistant Dean / Faculty: only shared (no-College) Rooms —
     *     the General Education/Minor scope.
     */
    private function applyScope($query, Request $request): void
    {
        $user = Auth::user();
        $role = $user?->getRoleNames()->first();

        if (! $user || in_array($role, self::UNSCOPED_ROLES, true)) {
            return;
        }

        if (in_array($role, ['Dean', 'OIC'], true) && $user->college_id) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('college_id')->orWhere('college_id', $user->college_id);
            });

            return;
        }

        $query->whereNull('college_id');
    }

    /**
     * Room Usage Summary cards shown above the Rooms table.
     */
    private function usageSummary($rooms, array $summaries): array
    {
        $active = $rooms->where('status', 'Active');
        $avgUtilization = $active->isNotEmpty()
            ? round($active->avg(fn (Room $room) => $summaries[$room->id]['utilization_percent']), 1)
            : 0.0;

        return [
            'total_rooms' => $rooms->count(),
            'active_rooms' => $active->count(),
            'available_rooms' => $rooms->filter(fn (Room $r) => $summaries[$r->id]['availability'] === 'Available')->count(),
            'fully_booked_rooms' => $rooms->filter(fn (Room $r) => $summaries[$r->id]['availability'] === 'Fully Booked')->count(),
            'average_utilization' => $avgUtilization,
            'rooms_with_conflicts' => $rooms->filter(fn (Room $r) => $summaries[$r->id]['has_conflict'])->count(),
        ];
    }
}