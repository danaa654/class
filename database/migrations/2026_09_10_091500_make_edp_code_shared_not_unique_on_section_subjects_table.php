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

        // FIX (SQLite test suite failure): the original version of this
        // reconciliation used DB::table(...)->join(...)->update(['lecture_row.edp_code' =>
        // DB::raw('f2f_row.edp_code')]). On MySQL, Laravel compiles that
        // straight to a real multi-table `UPDATE ... JOIN ... SET`, which
        // MySQL supports natively. SQLite has no such statement, so
        // Laravel's SQLite grammar instead rewrites joined updates into
        // `UPDATE section_subjects SET edp_code = f2f_row.edp_code WHERE
        // rowid IN (<subquery containing the join>)` — but the `f2f_row`
        // alias only exists inside that subquery, not in the outer SET
        // clause, so SQLite throws "no such column: f2f_row.edp_code"
        // (every test suite run against the SQLite :memory: DB hit this,
        // since RefreshDatabase runs every migration first). A correlated
        // subquery is standard SQL that both SQLite and MySQL execute
        // identically, so it replaces the join-update entirely instead of
        // special-casing the driver.
        DB::statement(<<<'SQL'
            update section_subjects
            set edp_code = (
                select f2f_row.edp_code
                from section_subjects as f2f_row
                where f2f_row.section_id = section_subjects.section_id
                  and f2f_row.subject_id = section_subjects.subject_id
                  and f2f_row.component = 'laboratory'
            )
            where component = 'lecture'
              and exists (
                  select 1
                  from section_subjects as f2f_row
                  where f2f_row.section_id = section_subjects.section_id
                    and f2f_row.subject_id = section_subjects.subject_id
                    and f2f_row.component = 'laboratory'
                    and f2f_row.edp_code != section_subjects.edp_code
              )
        SQL);
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropIndex('section_subjects_edp_code_idx');
            $table->unique('edp_code', 'section_subjects_edp_code_unique');
        });
    }
};