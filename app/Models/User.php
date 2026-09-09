<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'profile_photo_path',
        'email',
        'password',
        'status',
        'college_id',
        'department_id',
        'is_gened_assistant_dean',
    ];

    /**
     * Appended to every serialized User (including the "auth.user" prop
     * shared on every Inertia page — see HandleInertiaRequests) so the
     * frontend never has to build the storage URL itself. Absent/null
     * whenever no photo has been uploaded — the UI falls back to
     * showing the user's initials.
     *
     * @var list<string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Public URL for the user's uploaded profile photo, or null if
     * they haven't uploaded one. Stored on the "public" disk (see
     * config/filesystems.php) — requires `php artisan storage:link`
     * to have been run so storage/app/public is reachable at
     * public/storage.
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->profile_photo_path
                ? Storage::disk('public')->url($this->profile_photo_path)
                : null,
        );
    }

    /**
     * The college this user (Dean / OIC) is assigned to.
     *
     * @return BelongsTo<College, User>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * The department this user (Dean / OIC) is assigned to.
     *
     * @return BelongsTo<Department, User>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The departments this user (an OIC) is assigned to oversee. An OIC
     * can be assigned to any subset of departments within their college.
     *
     * @return BelongsToMany<Department>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    /**
     * The user's full name, built from first/middle/last/suffix when
     * available, falling back to the legacy `name` column.
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->first_name && ! $this->last_name) {
                    return $this->name;
                }

                return trim(implode(' ', array_filter([
                    $this->first_name,
                    $this->middle_name,
                    $this->last_name,
                    $this->suffix,
                ])));
            },
        );
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            // Additive GenEd/Minor authority for a Dean/OIC who is ALSO
            // acting as Assistant Dean (e.g. a college's Dean covering
            // GenEd/Minor subjects institution-wide). Never set for a
            // user whose primary role already IS Assistant Dean — see
            // App\Support\AccessScope::isAssistantDean().
            'is_gened_assistant_dean' => 'boolean',
        ];
    }
}