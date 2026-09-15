<?php

use App\Models\Major;
use Illuminate\Database\Migrations\Migration;

/**
 * Data-fix migration: shortens existing Major.code values from the
 * long form (BSIT, BSCRIMFI, ...) to the new short form (IT, FI, ...)
 * used going forward for EDP Codes and Section Prefix suggestions.
 *
 * Safe to run on a database that already has Sections/SectionSubjects
 * with EDP codes minted under the old long codes — those historical
 * edp_code / section_code strings are NOT touched here. Only the
 * majors.code column itself changes, which only affects codes minted
 * AFTER this runs.
 *
 * If a major with the target short code already exists (shouldn't
 * happen on a normal PAP install), that row is skipped and logged so
 * it can be resolved manually instead of silently colliding on the
 * unique constraint.
 */
return new class extends Migration
{
    private const MAP = [
        'BSIT' => 'IT',
        'BSED' => 'ED',
        'BSHM' => 'HM',
        'BSTM' => 'TM',
        'BSCRIMQD' => 'QD',
        'BSCRIMFI' => 'FI',
        'BSCRIMFB' => 'FB',
        'BSCRIMLD' => 'LD',
    ];

    public function up(): void
    {
        foreach (self::MAP as $oldCode => $newCode) {
            $major = Major::withTrashed()->where('code', $oldCode)->first();

            if (! $major) {
                continue;
            }

            if (Major::withTrashed()->where('code', $newCode)->where('id', '!=', $major->id)->exists()) {
                logger()->warning("Skipped shortening Major code '{$oldCode}' -> '{$newCode}': a major with code '{$newCode}' already exists.");

                continue;
            }

            $major->code = $newCode;
            $major->save();
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $oldCode => $newCode) {
            $major = Major::withTrashed()->where('code', $newCode)->first();

            if ($major) {
                $major->code = $oldCode;
                $major->save();
            }
        }
    }
};