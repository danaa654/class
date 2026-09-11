<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Section — a group of students under a Major, Curriculum, and Year
 * Level that will receive a class schedule.
 *
 * This module only stores the section itself. Subjects, faculty,
 * rooms, and schedules are assigned by later modules.
 */
class Section extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'section_code',
        'section_name',
        'section_type',
        'major_id',
        'curriculum_id',
        'academic_year',
        'semester',
        'year_level',
        'estimated_students',
        'status',
        'remarks',
    ];

    /**
     * Deliberately NOT in $fillable: is_finalized/finalized_at/
     * finalized_by are only ever set by SectionController::finalize()
     * / unlock(), never via mass assignment from a generic update
     * request — same reasoning as schedule_version below.
     */

    /**
     * `section_code_active` is a DB-generated column (see the sections
     * migration) that exists purely to make the section_code unique
     * index soft-delete-aware. It carries no information the app needs
     * beyond that, so it's kept out of arrays/JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'section_code_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_students' => 'integer',
            // CONCURRENCY HARDENING — optimistic-concurrency counter.
            // Deliberately NOT in $fillable: it is only ever advanced
            // by ScheduleConflictService::bumpScheduleVersion() inside
            // a locked transaction, never via mass assignment from a
            // controller/request. See the
            // 2026_08_17_090000_add_schedule_version_to_sections_table
            // migration for the full mechanism.
            'schedule_version' => 'integer',
            'is_finalized' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * CONCURRENCY HARDENING — the user who most recently advanced
     * schedule_version (see ScheduleConflictService::
     * bumpScheduleVersion() and the
     * 2026_08_17_090000_add_schedule_version_to_sections_table
     * migration). Nullable: a bump from a console/system context has
     * no known actor.
     */
    public function scheduleVersionUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'schedule_version_updated_by');
    }

    /**
     * SECTION-LEVEL SCHEDULE FINALIZATION — the single source of truth
     * the frontend and backend both defer to for "can this Section's
     * schedule be touched right now?". Backend enforcement lives in
     * ScheduleConflictService::lockResources() (throws
     * SectionFinalizedException); this accessor is what RoomGrid.vue
     * / SectionSubjects/Show.vue check to render the locked state
     * before a request is even made.
     */
    public function isEditable(): bool
    {
        return ! $this->is_finalized;
    }

    /**
     * The User who finalized this Section's schedule. Null while
     * not finalized, and also null (without unlocking the schedule)
     * if that User account is later deleted — see the
     * add_finalization_fields_to_sections_table migration's
     * nullOnDelete().
     *
     * @return BelongsTo<User, Section>
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * Whether this Section is Irregular — its students follow a mix
     * of subjects that don't line up with one single Regular
     * section's block schedule, so its subjects are scheduled one at
     * a time by IrregularSectionMergeService (merge into a compatible
     * Regular section's class where possible, else an independent
     * schedule) rather than as one uniform block.
     */
    public function isIrregular(): bool
    {
        return $this->section_type === 'Irregular';
    }

    /**
     * The Major this Section belongs to.
     *
     * @return BelongsTo<Major, Section>
     */
    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    /**
     * The Curriculum this Section follows. Must belong to the same
     * Major (enforced in StoreSectionRequest/UpdateSectionRequest).
     *
     * @return BelongsTo<Curriculum, Section>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * The pivot placements (Section Subjects) for this Section. Prefer
     * this over `subjects()` when you need the Source/Remarks pivot
     * data directly (e.g. listing the Section's subject table).
     *
     * @return HasMany<SectionSubject>
     */
    public function sectionSubjects(): HasMany
    {
        return $this->hasMany(SectionSubject::class);
    }

    /**
     * The master Subjects assigned to this Section. This is the
     * Section's subject list — NOT the schedule. No faculty, room, or
     * time is attached here.
     *
     * @return BelongsToMany<Subject>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'section_subjects')
            ->withPivot(['id', 'source', 'remarks'])
            ->withTimestamps();
    }

    /**
     * RBAC query scope (spec Section 10, 15, 24): Sections belong to a
     * College through Major -> Department -> College. Admin/Registrar/
     * Assistant Dean see every Section (Assistant Dean needs this to
     * schedule GenEd/Minor subjects into any College's sections; write
     * access to the Section record itself remains gated by
     * SectionPolicy). Dean/OIC see only their own College's Sections.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Section>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Section>
     */
    public function scopeVisibleTo($query, ?\App\Models\User $user)
    {
        if (\App\Support\AccessScope::isUnrestricted($user) || \App\Support\AccessScope::isAssistantDean($user)) {
            return $query;
        }

        $collegeId = \App\Support\AccessScope::collegeId($user);

        return $query->whereHas('major.department', function ($inner) use ($collegeId) {
            $inner->where('college_id', $collegeId ?? -1);
        });
    }

    /**
     * SCHEDULING-PROGRESS INDICATOR ("Fully/Partially Scheduled
     * (x/y)") — counts distinct SUBJECTS, not section_subjects rows.
     *
     * A split-delivery subject (see SectionSubject::isSplitComponent())
     * stores its Face-to-Face half and Online half as two separate
     * section_subjects rows sharing one subject_id. Counting rows would
     * make CC102 worth "2" toward both the numerator and denominator —
     * inflating the total and making a school with lots of split
     * subjects look "more scheduled" than one that never splits
     * anything, for no real reason. This counts each subject once:
     * total_subjects_count = number of distinct subjects on the
     * section; assigned_subjects_count = number of those subjects
     * where EVERY one of its rows (both halves, for a split subject)
     * has Faculty/Days/Start/End filled in, with Room required only
     * for the non-Online half — a Practicum/OJT subject's row(s)
     * always count as done, same exemption the row-level check already
     * gave them.
     *
     * Callers that used to do:
     *     ->withCount(['sectionSubjects as total_subjects_count', 'sectionSubjects as assigned_subjects_count' => ...])
     * should call this instead:
     *     Section::withSubjectProgressCounts(Section::query()->...)
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Section>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Section>
     */
    public static function withSubjectProgressCounts($query)
    {
        return $query->addSelect([
            'total_subjects_count' => SectionSubject::query()
                ->selectRaw('count(distinct subject_id)')
                ->whereColumn('section_subjects.section_id', 'sections.id'),
            'assigned_subjects_count' => SectionSubject::query()
                ->selectRaw('count(distinct subject_id)')
                ->whereColumn('section_subjects.section_id', 'sections.id')
                ->whereNotExists(function ($notExists) {
                    $notExists->selectRaw('1')
                        ->from('section_subjects as ss2')
                        ->join('subjects as sub2', 'sub2.id', '=', 'ss2.subject_id')
                        ->whereColumn('ss2.section_id', 'section_subjects.section_id')
                        ->whereColumn('ss2.subject_id', 'section_subjects.subject_id')
                        ->where('sub2.subject_type', '!=', 'practicum')
                        ->where(function ($unfinished) {
                            $unfinished->whereNull('ss2.faculty_id')
                                ->orWhereNull('ss2.days')
                                ->orWhereNull('ss2.start_time')
                                ->orWhereNull('ss2.end_time')
                                ->orWhere(function ($needsRoom) {
                                    $needsRoom->whereNull('ss2.room_id')
                                        ->where('ss2.delivery_mode', '!=', 'online');
                                });
                        });
                }),
        ]);
    }
}