<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'subject_code',
        'subject_title',
        'college_id',
        'major_id',
        'category',
        'subject_type',
        'units',
        'lecture_hours',
        'laboratory_hours',
        'required_hours',
        'deployment_type',
        'deployment_remarks',
        'preferred_room_category',
        'is_active',
        'description',
    ];

    /**
     * `recommended_room_ids` is derived (see
     * getRecommendedRoomIdsAttribute() above) rather than a real
     * column, so it must be explicitly appended to appear in JSON
     * responses — same pattern as SectionSubject::$appends for
     * `faculty_mismatch`.
     *
     * @var list<string>
     */
    protected $appends = ['recommended_room_ids'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'lecture_hours' => 'integer',
            'laboratory_hours' => 'integer',
            'required_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * True for Practicum / OJT / Internship / Fieldwork / Clinical
     * Practice subjects — conducted off-campus.
     *
     * This is the single flag every downstream module (Curriculum,
     * Section Subjects, ScheduleConflictService, AutoScheduleService,
     * Reports) should check instead of re-deriving "no room" logic
     * of its own. Never treat a Practicum/OJT subject as a Regular
     * subject with an empty room — it's explicitly a different
     * delivery model: Subject -> Faculty Supervisor -> Off-Campus ->
     * Required Hours, not Subject -> Faculty -> Room -> Time.
     */
    public function isPracticum(): bool
    {
        return $this->subject_type === 'practicum';
    }

    /**
     * The College that owns this Subject's definition. Required for
     * Major subjects; nullable for institution-wide GenEd/Minor
     * subjects that aren't scoped to any single College.
     *
     * @return BelongsTo<College, Subject>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * Legacy single-Major relation, kept for backward compatibility
     * with read paths that haven't been migrated to the majors()
     * many-to-many yet (RecommendationService, RoomRecommendationController).
     * Always mirrors the first row in the subject_major pivot — see
     * SubjectController::syncMajors().
     *
     * @return BelongsTo<Major, Subject>
     */
    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    /**
     * Every Major this Subject is applicable to. A Subject belongs to
     * one College but may be shared across several Majors within it —
     * this is the source of truth going forward; `major`/`major_id`
     * above are a derived convenience for legacy callers.
     *
     * @return BelongsToMany<Major>
     */
    public function majors(): BelongsToMany
    {
        return $this->belongsToMany(Major::class, 'subject_major')->withTimestamps();
    }

    /**
     * Curriculum placements that reference this Subject as the subject
     * itself (not as a prerequisite). Used to block deletion of a
     * Subject that's already mapped into a Curriculum.
     *
     * @return HasMany<CurriculumItem>
     */
    public function curriculumItems(): HasMany
    {
        return $this->hasMany(CurriculumItem::class);
    }

    /**
     * Faculty members qualified to teach this subject.
     *
     * @return BelongsToMany<Faculty>
     */
    public function faculty(): BelongsToMany
    {
        return $this->belongsToMany(Faculty::class, 'faculty_subject')->withTimestamps();
    }

    /**
     * Rooms this Subject is recommended for (soft preference,
     * configured from the Room Details page). Used by
     * RecommendationService::recommendRooms() as a scoring bonus,
     * never a hard constraint.
     *
     * @return BelongsToMany<Room>
     */
    public function recommendedRooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'room_subject_recommendations')
            ->wherePivot('active', true)
            ->withPivot(['id', 'active', 'created_by', 'created_at'])
            ->withTimestamps();
    }

    /**
     * The ids of every Room explicitly recommended for this Subject —
     * appended (when the relation is eager-loaded) so the Scheduling
     * table's client-side Room Type Mismatch check (Show.vue) can tell
     * an intentional Administrator Override apart from an accidental
     * manual placement, the same way AutoScheduleService's own Room
     * Type filter already does via `is_manual_override`. Deliberately
     * returns an empty array (never lazy-loads) when the relation
     * wasn't eager-loaded, so a caller that doesn't need this never
     * pays for an extra query per Subject.
     *
     * @return array<int>
     */
    public function getRecommendedRoomIdsAttribute(): array
    {
        return $this->relationLoaded('recommendedRooms')
            ? $this->recommendedRooms->pluck('id')->all()
            : [];
    }

    /** True for General Education / Minor subjects — institution-wide, shared across every College (spec Section 13). */
    public function isSharedResource(): bool
    {
        return \App\Support\AccessScope::isSharedCategory($this->category);
    }

    /**
     * RBAC query scope (spec Section 24): subjects a user may MANAGE
     * (not merely view — every authorized role may view every subject
     * for scheduling purposes, see SubjectPolicy::view()).
     * Admin/Registrar: everything. Assistant Dean: GenEd/Minor only.
     * Dean/OIC: Major subjects owned by their own College only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Subject>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Subject>
     */
    public function scopeManageableBy($query, ?\App\Models\User $user)
    {
        if (\App\Support\AccessScope::isUnrestricted($user)) {
            return $query;
        }

        if (\App\Support\AccessScope::isAssistantDean($user)) {
            return $query->whereIn('category', ['General Education', 'Minor']);
        }

        $collegeId = \App\Support\AccessScope::collegeId($user);

        return $query->where('category', 'Major')
            ->where('college_id', $collegeId ?? -1);
    }
}