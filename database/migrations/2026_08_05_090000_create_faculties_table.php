<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();

            // Human-assigned identifier (e.g. employee number). Not the
            // primary key on purpose — registrar staff manage/reassign this
            // independently of the internal row id.
            $table->string('faculty_id')->unique();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->enum('employment_type', ['Full-time', 'Part-time']);

            // Department Faculty belong to a College; General Education
            // Faculty (GenEd/Minor — English, Math, Filipino, NSTP, PE,
            // etc.) don't belong to one, so college_id is nullable and
            // left null for them. There's no separate category column —
            // Faculty::getFacultyCategoryAttribute() derives "Department
            // Faculty" vs "General Education Faculty" straight from
            // whether college_id is set, so the two can never disagree.
            // The scheduling engine uses this College together with
            // Teaching Qualifications to rank candidates.
            $table->foreignId('college_id')->nullable()->constrained()->restrictOnDelete();

            // Teaching load ceiling used later by the scheduling module to
            // prevent overloading a faculty member. Defaults to 21 units.
            $table->unsignedTinyInteger('max_teaching_units')->default(21);

            $table->enum('status', ['Active', 'Inactive'])->default('Active');

            $table->string('email')->nullable();
            $table->string('contact_number')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculties');
    }
};