<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the additive "also acts as Assistant Dean for GenEd/Minor
     * subjects" flag. This is layered ON TOP of a Dean/OIC's normal
     * College-scoped role — it does NOT replace or duplicate the
     * Assistant Dean Spatie role, and it must never be set true for a
     * user whose primary role already IS Assistant Dean (that case is
     * already unrestricted for shared categories on its own).
     *
     * Placed after College and Department assignment so it reads
     * naturally on the users table alongside college_id/department_id.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_gened_assistant_dean')
                ->default(false)
                ->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_gened_assistant_dean');
        });
    }
};