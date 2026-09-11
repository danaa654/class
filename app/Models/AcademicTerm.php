<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An Academic Term is a School Year + Semester combination.
 *
 * The Global Scheduling Settings the Auto Schedule AI reads (Class
 * Start/End Time, Time Interval, Available Class Days, Lunch Break)
 * live on the School Year this term belongs to — see
 * SchoolYear::active() and schoolYear(). They are intentionally not
 * duplicated here.
 */
class AcademicTerm extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_year_id',
        'semester_id',
        'status',
        'remarks',
    ];

    /**
     * The School Year this Academic Term belongs to.
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * The Semester this Academic Term belongs to.
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * The single Active Academic Term, if one exists.
     */
    public static function active(): ?self
    {
        return static::query()->where('status', 'Active')->first();
    }

    /**
     * Whether a real AcademicTerm exists matching the given Section-
     * spelled Academic Year + Semester (e.g. "2026-2027" +
     * "First Semester") — matched via sectionSemesterValue() rather
     * than a raw string compare, same reasoning as that method's own
     * docblock.
     *
     * Used as the actual server-side safety net behind the Add/Edit
     * Section forms' Academic Year/Semester dropdowns: those dropdowns
     * are now built from real AcademicTerm records, but that alone
     * doesn't stop a direct/scripted request from submitting a
     * combination with no AcademicTerm behind it at all.
     *
     * @param bool $allowArchived When false (the default used for
     *   creating a NEW Section), an Archived term does not count as a
     *   match — creating a Section under a term that's done doesn't
     *   make sense. When true (used when updating an existing
     *   Section), an Archived term still counts, so editing a Section
     *   that already belongs to a since-Archived term isn't blocked.
     */
    public static function existsForSection(string $academicYear, string $semester, bool $allowArchived = false): bool
    {
        return static::query()
            ->when(! $allowArchived, fn ($query) => $query->where('status', '!=', 'Archived'))
            ->whereHas('schoolYear', fn ($query) => $query->where('name', $academicYear))
            ->with('semester:id,name')
            ->get(['id', 'school_year_id', 'semester_id'])
            ->contains(fn (self $term) => $term->sectionSemesterValue() === $semester);
    }

    /**
     * The Section.semester enum value ("First Semester" / "Second
     * Semester" / "Summer") this term's Semester corresponds to.
     *
     * Sections store Semester as "First Semester"/"Second Semester"/
     * "Summer" (see the sections table enum) while the Semester model
     * this term belongs to stores "1st Semester"/"2nd Semester"/
     * "Summer" (see Semester::NAMES) — the two parts of the app spell
     * the same Semester differently, so an exact string compare
     * between them silently matches NOTHING. Every place that needs
     * to find the Sections belonging to this term's Semester (dashboard
     * stats, global Faculty/Room conflict scoping, etc.) must go
     * through this method instead of comparing the names directly, so
     * that mismatch can never quietly reappear in a second place.
     *
     * Returns null when the Semester name doesn't recognizably match
     * either convention (e.g. semester relation missing) — callers
     * should treat null as "can't scope by semester" and fail safe
     * (typically: don't filter by semester at all) rather than
     * matching zero or all Sections by accident.
     */
    public function sectionSemesterValue(): ?string
    {
        $name = strtolower(trim((string) $this->semester?->name));

        return match (true) {
            $name === '' => null,
            str_contains($name, 'summer') => 'Summer',
            str_starts_with($name, '1st') || str_starts_with($name, 'first') => 'First Semester',
            str_starts_with($name, '2nd') || str_starts_with($name, 'second') => 'Second Semester',
            default => null,
        };
    }

    /**
     * Every currently-Active Major (whose Department and College are
     * also both Active — a discontinued/no-longer-offered College or
     * Department is deliberately excluded, see the caller for why)
     * that has NO Section at all under this term's School Year +
     * Semester. Purely informational — used to warn an Admin/
     * Registrar ending a semester that some offering was seemingly
     * skipped, not to block anything by itself (a college/major that
     * genuinely isn't offered that term is a normal, valid state).
     *
     * @return array<int, array{college: ?string, major: string}>
     */
    public function missingOfferings(): array
    {
        return Major::query()
            ->where('status', 'Active')
            ->whereHas('department', fn ($query) => $query->where('status', 'Active')
                ->whereHas('college', fn ($college) => $college->where('status', 'Active')))
            ->with('department.college')
            ->get()
            ->reject(fn (Major $major) => $this->matchingSectionsQuery()->where('major_id', $major->id)->exists())
            ->map(fn (Major $major) => [
                'college' => $major->college()?->name,
                'major' => $major->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Every Section belonging to this Academic Term's School Year +
     * Semester, matched via sectionSemesterValue() rather than a raw
     * string compare. Falls back to "every Section" (rather than
     * "no Sections") when this term's School Year or Semester can't
     * be resolved, so callers never silently filter everything out
     * because of incomplete setup or a naming mismatch.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Section>
     */
    public function matchingSectionsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $this->loadMissing(['schoolYear:id,name', 'semester:id,name']);

        $query = Section::query();
        $semesterValue = $this->sectionSemesterValue();

        if ($this->schoolYear && $semesterValue) {
            $query->where('academic_year', $this->schoolYear->name)
                ->where('semester', $semesterValue);
        }

        return $query;
    }

    /**
     * Enforce the "only one Active Academic Term at a time" rule.
     *
     * Mirrors SchoolYear::booted(): when an Academic Term is saved as
     * Active, every other Academic Term is flipped to Inactive. Runs on
     * `saved` so it doesn't clobber its own row, and is scoped to
     * `withTrashed()` so a soft-deleted term left behind as Active
     * doesn't stick around as a second "active" record.
     */
    protected static function booted(): void
    {
        static::saved(function (AcademicTerm $academicTerm) {
            if ($academicTerm->status === 'Active') {
                static::withTrashed()
                    ->where('id', '!=', $academicTerm->id)
                    ->where('status', 'Active')
                    ->update(['status' => 'Inactive']);
            }
        });
    }
}