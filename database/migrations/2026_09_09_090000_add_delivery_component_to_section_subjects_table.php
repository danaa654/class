<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPLIT-DELIVERY SCHEDULING (F2F + Online per subject).
     *
     * Today a Subject's total weekly hours (lecture_hours +
     * laboratory_hours) are collapsed into ONE section_subjects row —
     * one Room, one Faculty, one Days/Time slot for the whole
     * subject (see MeetingPatternService::classify()). That can't
     * represent a subject like CAP102 (3 hrs Lab F2F + 2 hrs Lecture
     * Online), which needs two independent schedule slots: one that
     * requires a Room and participates in Room conflict detection,
     * and one that explicitly does not.
     *
     * This migration lets a Subject occupy TWO rows within the same
     * Section — one per `component` — instead of exactly one.
     *
     * NOTE: MySQL won't let you drop the (section_id, subject_id)
     * unique index directly — section_id's foreign key constraint is
     * currently relying on that same index to exist ("Cannot drop
     * index ...: needed in a foreign key constraint"). The fix is to
     * drop the foreign key first, then the index, then recreate both
     * in the right order.
     */
    public function up(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->enum('component', ['combined', 'lecture', 'laboratory'])
                ->default('combined')
                ->after('subject_id');

            $table->enum('delivery_mode', ['face_to_face', 'online'])
                ->default('face_to_face')
                ->after('component');

            $table->unsignedTinyInteger('split_hours')->nullable()->after('delivery_mode');
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            // Drop the FK that depends on the old unique index, then
            // the index itself.
            $table->dropForeign(['section_id']);
            $table->dropUnique(['section_id', 'subject_id']);
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            // Recreate the FK (plain index, no uniqueness) and the
            // new, wider unique constraint.
            $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
            $table->unique(['section_id', 'subject_id', 'component']);
        });
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropUnique(['section_id', 'subject_id', 'component']);
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
            $table->unique(['section_id', 'subject_id']);
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropColumn(['component', 'delivery_mode', 'split_hours']);
        });
    }
};