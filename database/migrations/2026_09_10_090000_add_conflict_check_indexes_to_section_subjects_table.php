<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERFORMANCE — conflict-check indexes.
 *
 * ScheduleConflictService::findFacultyConflict(), findRoomConflict(),
 * and findFacultyDailyHoursViolation() all filter section_subjects by
 * faculty_id / room_id (already FK-indexed individually) and then
 * compare start_time/end_time for overlap. Without a composite index
 * that includes the time columns, MySQL narrows by the FK index alone
 * and then has to scan every remaining row for the overlap check —
 * on every single drag/click drop, since all 4 checks run
 * synchronously before the request resolves.
 *
 * These composite indexes let the overlap comparison itself be
 * satisfied by the index instead of a row-by-row scan after the FK
 * lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->index(['faculty_id', 'start_time', 'end_time'], 'section_subjects_faculty_time_idx');
            $table->index(['room_id', 'start_time', 'end_time'], 'section_subjects_room_time_idx');
            $table->index(['section_id', 'start_time', 'end_time'], 'section_subjects_section_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropIndex('section_subjects_faculty_time_idx');
            $table->dropIndex('section_subjects_room_time_idx');
            $table->dropIndex('section_subjects_section_time_idx');
        });
    }
};