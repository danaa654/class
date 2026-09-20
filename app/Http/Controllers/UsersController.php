<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Department;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ImageOptimizationService;
use App\Services\NotificationService;
use App\Services\PasswordPolicyService;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    /**
     * Roles that require a College assignment (Dean oversees the whole
     * college, so no Department is required).
     *
     * @var list<string>
     */
    private const ROLES_REQUIRING_COLLEGE = ['Dean', 'OIC'];

    /**
     * Roles that require both College and Department (an OIC may be
     * covering a specific department).
     *
     * @var list<string>
     */
    private const ROLES_REQUIRING_DEPARTMENT = ['OIC'];

    /**
     * Roles that may additionally be granted GenEd/Minor authority via
     * the "Also manage GenEd/Minor subjects across all colleges"
     * checkbox (is_gened_assistant_dean). Assistant Dean itself is
     * excluded — that role is already unrestricted for shared
     * categories, so the flag would be redundant.
     *
     * @var list<string>
     */
    private const ROLES_ELIGIBLE_FOR_GENED_FLAG = ['Dean', 'OIC'];

    public function __construct(
        private readonly PasswordPolicyService $policy,
        private readonly ActivityLogService $activityLog = new ActivityLogService,
        private readonly NotificationService $notifications = new NotificationService,
        private readonly ImageOptimizationService $imageOptimizer = new ImageOptimizationService,
    ) {}

    /**
     * Display the User Management page. Administrator only — Registrar,
     * Dean, OIC and Assistant Dean must never see or reach this page;
     * they get "Manage Account" on the Settings page instead.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAdministrator($request);

        // PERFORMANCE — select only the columns Users/Index.vue's
        // transform() actually reads, instead of every column
        // (profile photo binary paths, timestamps, etc. this table
        // never displays) for every one of potentially hundreds of users.
        $users = User::with(['roles', 'college:id,name', 'department:id,name', 'departments:id,name'])
            ->orderByDesc('id')
            ->get([
                'id', 'first_name', 'middle_name', 'last_name', 'suffix', 'name', 'email',
                'status', 'college_id', 'department_id', 'must_change_password',
                'is_gened_assistant_dean', 'profile_photo_path',
            ])
            ->map(fn (User $user) => $this->transform($user));

        return Inertia::render('Users/Index', [
            'users' => $users,
            'colleges' => College::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name', 'college_id']),
            // Live checklist under the New Password field in the
            // Manage Account tab below — same source PasswordPolicyService
            // feeds to Settings' Manage Account tab for every other role.
            'passwordPolicy' => $this->policy->requirements(),
        ]);
    }

    /**
     * Store a newly created user. Administrator only.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        $validated = $request->validate($this->rules($request));

        $user = User::create([
            'name' => trim("{$validated['first_name']} {$validated['last_name']}"),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'status' => $validated['status'],
            'college_id' => $validated['college_id'] ?? null,
            'is_gened_assistant_dean' => $this->resolveGenedFlag($validated),
            'email_verified_at' => now(),
        ]);

        $user->assignRole($validated['role']);

        // Attach the OIC's specific department assignments. Leaving this
        // empty (when "oversees all departments" is checked) means the
        // OIC covers every department in their college by implication.
        if (! empty($validated['department_ids'])) {
            $user->departments()->sync($validated['department_ids']);
        }

        return redirect()->route('users')->with('success', 'User account created successfully.');
    }

    /**
     * Update an existing user (the "Edit" action in User Management).
     * Administrator only.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        $validated = $request->validate($this->rules($request, $user));

        $user->fill([
            'name' => trim("{$validated['first_name']} {$validated['last_name']}"),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'email' => $validated['email'],
            'status' => $validated['status'],
            'college_id' => $validated['college_id'] ?? null,
            'is_gened_assistant_dean' => $this->resolveGenedFlag($validated),
        ]);

        // Password is optional on edit — only touch it if one was given.
        // Note: this does NOT clear must_change_password — an
        // Administrator resetting the credential isn't the same as the
        // account holder actually changing it themselves, so a pending
        // "require password change on next login" stays pending.
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            $user->password_changed_at = now();
        }

        $user->save();

        if ($user->roles->first()?->name !== $validated['role']) {
            $user->syncRoles([$validated['role']]);
        }

        $user->departments()->sync($validated['department_ids'] ?? []);

        return redirect()->route('users')->with('success', 'User account updated successfully.');
    }

    /**
     * Toggle a user between Active and Inactive (the "Deactivate" /
     * "Activate" row action in User Management). Administrator only.
     * An Administrator may not deactivate their own account, which
     * would otherwise lock them out of User Management entirely.
     */
    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        if ($user->id === $request->user()->id) {
            return redirect()->route('users')->with('error', 'You cannot deactivate your own account.');
        }

        $user->status = $user->status === 'Active' ? 'Inactive' : 'Active';
        $user->save();

        $message = $user->status === 'Active'
            ? 'User account activated.'
            : 'User account deactivated.';

        return redirect()->route('users')->with('success', $message);
    }

    /**
     * Toggle "Require password change on next login" for one user (a row
     * action in User Management). Administrator only.
     *
     * This is the ONLY place must_change_password gets flipped to true —
     * an explicit, per-user, Administrator-triggered action. Nothing
     * else in the app sets it (creating an account doesn't, resetting a
     * password from update() above doesn't), so existing accounts are
     * never retroactively forced to change their password just because
     * this feature/tab now exists.
     */
    public function updateMustChangePassword(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        if ($user->id === $request->user()->id) {
            return redirect()->route('users')->with('error', 'You cannot force this on your own account — change your password from Manage Account instead.');
        }

        $user->must_change_password = ! $user->must_change_password;
        $user->save();

        $message = $user->must_change_password
            ? "{$user->full_name} will be required to change their password on next login."
            : "The required password change for {$user->full_name} was cancelled.";

        $this->activityLog->record(
            ActivityLogService::PASSWORD_CHANGE_REQUIRED,
            $message,
            $user,
            $request->user(),
        );

        // Only notify the user when the requirement is being turned ON
        // — cancelling it isn't something they need to act on.
        if ($user->must_change_password) {
            $this->notifications->passwordChangeRequired($user, $request->user());
        }

        return redirect()->route('users')->with('success', $message);
    }

    /**
     * Permanently delete a user account (the "Delete" row action in User
     * Management). Administrator only. An Administrator may not delete
     * their own account, and the last remaining Administrator account
     * may never be deleted, to guarantee someone can always manage the
     * system.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        if ($user->id === $request->user()->id) {
            return redirect()->route('users')->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole('Administrator') && User::role('Administrator')->count() <= 1) {
            return redirect()->route('users')->with('error', 'You cannot delete the last remaining Administrator account.');
        }

        $user->delete();

        return redirect()->route('users')->with('success', 'User account deleted.');
    }

    /**
     * Update the logged-in user's own account details (name, email,
     * password). Used by the "Manage Account" tab on the User Management
     * page (Administrator) and on the Settings page (Registrar, Dean,
     * OIC, Assistant Dean). Every role may only ever touch its own
     * account here — $request->user() is never swapped for another row.
     */
    public function updateAccount(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            // Profile photo — optional. 'image' covers jpg/png/webp/etc;
            // 2MB cap keeps the "public" disk from filling up with
            // full-resolution phone photos. 'remove_photo' is a separate
            // boolean flag (rather than overloading a null 'photo') so
            // "didn't touch the photo field" and "explicitly clear it"
            // stay distinguishable.
            'photo' => ['nullable', 'image', 'max:2048'],
            'remove_photo' => ['nullable', 'boolean'],
            // STRONG tier — this is a user setting their OWN password
            // (voluntary self-service change), so it goes through
            // PasswordPolicyService like every other self-set-password
            // path (Auth\ChangePasswordController). Never swap this back
            // to a bare 'min:8' — that belongs only to rules() below,
            // where an ADMINISTRATOR is setting someone else's password.
            'password' => ['nullable', 'confirmed', ...$this->policy->rules()],
        ]);

        $user->fill([
            'name' => trim("{$validated['first_name']} {$validated['last_name']}"),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'email' => $validated['email'],
        ]);

        // New photo replaces (and deletes) any existing one. An explicit
        // "remove_photo" clears it without a replacement. Neither
        // touches profile_photo_path when the user didn't interact with
        // the photo field at all, so the rest of the form can still be
        // saved on its own.
        if ($request->hasFile('photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }
            // PERFORMANCE — compress images. Re-encodes/downsizes the
            // upload before it's written to disk (see
            // ImageOptimizationService) instead of storing whatever
            // multi-MB original the browser sent, since this photo is
            // then served on every page that shows the user's avatar.
            $user->profile_photo_path = $this->imageOptimizer->storeOptimized($request->file('photo'), 'profile-photos');
        } elseif ($request->boolean('remove_photo') && $user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
        }

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            $user->password_changed_at = now();
            $user->must_change_password = false;
        }

        $user->save();

        // Administrators manage their account from the Users page; every
        // other role manages it from Settings, so send each back to the
        // page their "Manage Account" tab actually lives on.
        $redirectRoute = $user->hasRole('Administrator') ? 'users' : 'settings';

        return redirect()->route($redirectRoute)->with('success', 'Your account has been updated.');
    }

    /**
     * Guard used by index()/store()/update() — User Management (viewing,
     * creating, and editing accounts) is restricted to the Administrator
     * role. Registrar, Dean, OIC and Assistant Dean are blocked here even
     * if they hit these routes directly (e.g. by URL), not just hidden
     * from the sidebar.
     */
    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()->hasRole('Administrator'), 403);
    }

    /**
     * Shared validation rules for store() and update(). On update, the
     * password becomes optional and unique checks ignore the current row.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?User $user = null): array
    {
        return [
            'role' => ['required', Rule::in(RoleSeeder::ROLES)],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            // RELAXED tier — deliberate. An Administrator is setting up
            // or resetting ANOTHER PERSON's account here (new CCS Dean,
            // Registrar, etc.) and hands the credential to them directly,
            // so friction is a cost with no security benefit — the
            // account is forced through the strong policy the moment
            // that person changes it themselves (see must_change_password
            // below and Auth\ChangePasswordController). Do NOT replace
            // this with PasswordPolicyService::rules() — that's reserved
            // for self-service password changes only (see
            // updateAccount() above).
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'oversees_all_departments' => ['boolean'],
            // Only meaningful for Dean/OIC — see ROLES_ELIGIBLE_FOR_GENED_FLAG
            // and resolveGenedFlag(). Validated as a plain boolean here;
            // resolveGenedFlag() is what actually zeroes it out for
            // ineligible roles, so a stale "true" left over from a role
            // switch in the form can never slip through.
            'is_gened_assistant_dean' => ['boolean'],
            'college_id' => [
                Rule::requiredIf(in_array($request->input('role'), self::ROLES_REQUIRING_COLLEGE, true)),
                'nullable',
                'exists:colleges,id',
            ],
            'department_ids' => [
                Rule::requiredIf(
                    in_array($request->input('role'), self::ROLES_REQUIRING_DEPARTMENT, true)
                    && ! $request->boolean('oversees_all_departments')
                ),
                'array',
            ],
            'department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where('college_id', $request->input('college_id')),
            ],
        ];
    }

    /**
     * Whether the "Also manage GenEd/Minor subjects across all colleges"
     * flag should actually be persisted for this submission. Only
     * Dean/OIC are eligible (see ROLES_ELIGIBLE_FOR_GENED_FLAG) — for
     * every other role (Administrator, Registrar, Assistant Dean) the
     * flag is always false, regardless of what the request sent, so a
     * leftover checked value from switching roles in the form can never
     * be saved against an ineligible role.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveGenedFlag(array $validated): bool
    {
        return in_array($validated['role'], self::ROLES_ELIGIBLE_FOR_GENED_FLAG, true)
            && (bool) ($validated['is_gened_assistant_dean'] ?? false);
    }

    /**
     * Shape a User model into the array the Users/Index page expects,
     * shared by the index listing and the edit-form prefill.
     *
     * @return array<string, mixed>
     */
    private function transform(User $user): array
    {
        $role = $user->roles->first()?->name;
        $collegeDepartmentCount = $user->college
            ? Department::where('college_id', $user->college_id)->count()
            : 0;

        $departmentLabel = null;

        if ($role === 'OIC' && $user->college_id) {
            $assigned = $user->departments->pluck('name');

            $departmentLabel = match (true) {
                $assigned->isEmpty() => 'All Departments',
                $assigned->count() >= $collegeDepartmentCount => 'All Departments',
                default => $assigned->implode(', '),
            };
        }

        return [
            'id' => $user->id,
            'fullName' => $user->full_name,
            'firstName' => $user->first_name,
            'middleName' => $user->middle_name,
            'lastName' => $user->last_name,
            'suffix' => $user->suffix,
            'email' => $user->email,
            'role' => $role,
            'mustChangePassword' => $user->must_change_password,
            'isGenedAssistantDean' => (bool) $user->is_gened_assistant_dean,
            'collegeId' => $user->college_id,
            'departmentIds' => $user->departments->pluck('id'),
            'college' => $user->college?->name,
            'department' => $departmentLabel,
            'status' => $user->status,
        ];
    }
}