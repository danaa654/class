<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SPLIT-DELIVERY SHARED EDP CODE.
 *
 * edp_code was originally unique per row on the assumption that every
 * row is a fully separate subject offering. That's no longer true
 * once a subject is split (splitSchedule()): the Face-to-Face row and
 * its Online sibling are the SAME subject, in the SAME section, just
 * broken into two schedule components (component = 'laboratory' /
 * 'lecture' — already uniquely constrained together with
 * section_id + subject_id). They are meant to carry the SAME edp_code,
 * which the old unique(edp_code) constraint made impossible.
 *
 * Drops that column-level uniqueness and replaces it with a plain
 * index (edp_code is still looked up/filtered on in reports, so it's
 * worth indexing — it just can no longer be UNIQUE on its own).
 * Real per-row uniqueness continues to be enforced by the existing
 * unique(section_id, subject_id, component) constraint.
 *
 * DATA FIX (folded in here rather than a separate migration): any
 * 'lecture' (Online) row that was split before the
 * splitSchedule()/splitSiblingSections() code fix was minted a
 * brand-new edp_code instead of inheriting its 'laboratory'
 * (Face-to-Face) sibling's. This reconciles those existing pairs to
 * share one code. On a fresh database (migrate:fresh, no split rows
 * yet) this UPDATE simply matches zero rows and is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropUnique('section_subjects_edp_code_unique');
            $table->index('edp_code', 'section_subjects_edp_code_idx');
        });

        DB::table('section_subjects as lecture_row')
            ->join('section_subjects as f2f_row', function ($join) {
                $join->on('f2f_row.section_id', '=', 'lecture_row.section_id')
                    ->on('f2f_row.subject_id', '=', 'lecture_row.subject_id')
                    ->where('f2f_row.component', '=', 'laboratory');
            })
            ->where('lecture_row.component', '=', 'lecture')
            ->whereColumn('lecture_row.edp_code', '!=', 'f2f_row.edp_code')
            ->update([
                'lecture_row.edp_code' => DB::raw('f2f_row.edp_code'),
            ]);
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropIndex('section_subjects_edp_code_idx');
            $table->unique('edp_code', 'section_subjects_edp_code_unique');
        });
    }
};