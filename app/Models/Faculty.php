<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Faculty Master record.
 *
 * IMPORTANT: Faculty members are NOT system users — they never log in.
 * This model exists purely so the registrar/admin can maintain a roster
 * of people available to be assigned teaching loads during scheduling.
 * Subject/schedule/room/section assignment is handled by later modules;
 * this model only stores faculty identity and eligibility information.
 */
class Faculty extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'faculty_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'employment_type',
        'college_id',
        'max_teaching_units',
        'workload_type',
        'max_weekly_hours',
        'status',
        'email',
        'contact_number',
        'remarks',
    ];

    /**
     * `faculty_category` is derived (see getFacultyCategoryAttribute()
     * below) rather than a real column, so it must be explicitly
     * appended to appear in JSON responses the same way the old stored
     * column did.
     *
     * @var list<string>
     */
    protected $appends = ['faculty_category', 'full_name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_teaching_units' => 'integer',
            'max_weekly_hours' => 'integer',
        ];
    }

    /**
     * Every schedule placement (across every Section/College) this
     * Faculty member has ever been assigned to. Used by
     * FacultyWorkloadService — prefer that service's
     * currentLoad()/evaluate() over querying this relation directly,
     * since the service is what applies the "active semester only, no
     * Conflict status" workload scope.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<SectionSubject>
     */
    public function sectionSubjects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SectionSubject::class);
    }

    /**
     * Delivery history for the Faculty Schedule Email System (Reports
     * -> Faculty Schedule -> Send via Email). See FacultyScheduleEmail.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FacultyScheduleEmail>
     */
    public function scheduleEmails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacultyScheduleEmail::class);
    }

    /**
     * The college this faculty member belongs to.
     *
     * @return BelongsTo<College, Faculty>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * Subjects this faculty member is qualified to teach.
     *
     * Teaching qualification is a pure many-to-many link (via
     * `faculty_subject`) — it carries no extra data of its own. The
     * scheduling engine's Genetic Algorithm reads this relationship to
     * ensure it never assigns a faculty member to a subject they are
     * not qualified for.
     *
     * @return BelongsToMany<Subject>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'faculty_subject')->withTimestamps();
    }

    /**
     * Full display name, formatted "Last, First Middle Suffix".
     */
    public function getFullNameAttribute(): string
    {
        $middleInitial = $this->middle_name ? ' '.mb_substr($this->middle_name, 0, 1).'.' : '';
        $suffix = $this->suffix ? ' '.$this->suffix : '';

        return "{$this->last_name}, {$this->first_name}{$middleInitial}{$suffix}";
    }

    /**
     * Derived from `college_id` rather than stored, so this can never
     * drift out of sync with it: no College means General Education
     * Faculty (GenEd/Minor — English, Math, Filipino, NSTP, PE, etc.),
     * otherwise the faculty member belongs to a department (College).
     */
    public function getFacultyCategoryAttribute(): string
    {
        return $this->college_id === null
            ? 'General Education Faculty'
            : 'Department Faculty';
    }

    /**
     * RBAC query scope (spec Section 24 — QUERY SCOPING): restrict the
     * Faculty roster to what $user is authorized to see.
     * Admin/Registrar/Assistant Dean: unrestricted (full roster —
     * Assistant Dean needs to find Minor/GenEd-qualified faculty
     * regardless of which College they belong to). Dean/OIC: their
     * own College's faculty only, UNLESS $allColleges is true (the
     * "All Faculty" filter option — a read-mostly institution-wide
     * view; write access to rows outside their own College is still
     * blocked by FacultyPolicy::canAccess(), this only affects what
     * they can SEE). Anyone else: no rows.
     */
    public function scopeVisibleTo($query, ?\App\Models\User $user, bool $allColleges = false)
    {
        // Assistant Dean sees the FULL roster (like Admin/Registrar) —
        // Minor/GenEd subjects are taught by faculty who belong to any
        // College (e.g. a CCS faculty teaching an ITE minor for CTE/
        // BSHM/BSTM), so restricting this list to college_id-null
        // faculty hid exactly the people Assistant Dean needs to find
        // to assign them. What stays restricted to Assistant Dean is
        // WHICH qualifications/subjects they may manage for a given
        // faculty (Minor/GenEd only) — see FacultyPolicy::manageQualification()
        // — never which faculty rows they can see.
        if (\App\Support\AccessScope::isUnrestricted($user) || \App\Support\AccessScope::isAssistantDean($user) || $allColleges) {
            return $query;
        }

        $ids = \App\Support\AccessScope::visibleCollegeIds($user);

        return $query->whereIn('college_id', $ids ?? [-1]);
    }
}