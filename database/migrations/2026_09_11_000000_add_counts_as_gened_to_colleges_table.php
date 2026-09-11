<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some Colleges (e.g. College of Teacher Education) supply the
     * faculty who teach General Education/Minor subjects at this
     * school, even though those faculty still belong to a real
     * College rather than sitting in the "no College" pool. This
     * flag lets a College opt in to being treated as part of the
     * GenEd faculty pool for scheduling recommendations, without
     * changing what College its faculty administratively belong to.
     *
     * Deliberately scoped to scheduling *recommendations* only
     * (RecommendationService's General Education Match tier and
     * SectionSubject::faculty_mismatch) — it does not touch the
     * unrelated "College-less" checks used for Dean/OIC jurisdiction
     * or Room/FacultyRequest scoping elsewhere in the app.
     */
    public function up(): void
    {
        Schema::table('colleges', function (Blueprint $table) {
            $table->boolean('counts_as_gened')
                ->default(false)
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('colleges', function (Blueprint $table) {
            $table->dropColumn('counts_as_gened');
        });
    }
};