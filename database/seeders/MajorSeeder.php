<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Major;
use Illuminate\Database\Seeder;

/**
 * MajorSeeder
 * ---------------------------------------------------------------------
 * Creates one Major per Department, using DepartmentSeeder's college/
 * department structure. This is what SubjectSeeder resolves major_id
 * against (via `Major::where('code', ...)`), so the two must be run
 * together (order doesn't matter, but both need College/Department
 * seeded first) — see the 'code' values below, which intentionally
 * match the codes SubjectSeeder's catalog methods are tagged with.
 *
 * Run with: php artisan db:seed --class=MajorSeeder
 * Safe to re-run: uses Major::firstOrCreate() throughout.
 */
class MajorSeeder extends Seeder
{
    /**
     * Majors keyed by their Department's code (from DepartmentSeeder).
     * 'code' here is what SubjectSeeder looks Majors up by.
     */
    public const MAJORS = [
        'CCS-BSIT' => [
            'code' => 'IT',
            'name' => 'Bachelor of Science in Information Technology',
            'short_name' => 'BSIT',
            'years' => 4,
        ],
        'CTE-BSED' => [
            'code' => 'ED',
            'name' => 'Bachelor of Secondary Education major in English',
            'short_name' => 'BSED',
            'years' => 4,
        ],
        'SHTM-BSHM' => [
            'code' => 'HM',
            'name' => 'Bachelor of Science in Hospitality Management',
            'short_name' => 'BSHM',
            'years' => 4,
        ],
        'SHTM-BSTM' => [
            'code' => 'TM',
            'name' => 'Bachelor of Science in Tourism Management',
            'short_name' => 'BSTM',
            'years' => 4,
        ],
        'COC-BSCRIMQD' => [
            'code' => 'QD',
            'name' => 'Bachelor of Science in Criminology major in Questioned Documents Examination',
            'short_name' => 'BSCRIM-QD',
            'years' => 4,
        ],
        'COC-BSCRIMFI' => [
            'code' => 'FI',
            'name' => 'Bachelor of Science in Criminology major in Fingerprint Identification',
            'short_name' => 'BSCRIM-FI',
            'years' => 4,
        ],
        'COC-BSCRIMFB' => [
            'code' => 'FB',
            'name' => 'Bachelor of Science in Criminology major in Firearms Identification (Forensic Ballistics)',
            'short_name' => 'BSCRIM-FB',
            'years' => 4,
        ],
        'COC-BSCRIMLD' => [
            'code' => 'LD',
            'name' => 'Bachelor of Science in Criminology major in Lie Detection',
            'short_name' => 'BSCRIM-LD',
            'years' => 4,
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $missingDepartments = [];

        foreach (self::MAJORS as $departmentCode => $major) {
            $department = Department::where('code', $departmentCode)->first();

            if (! $department) {
                $missingDepartments[] = $departmentCode;

                continue;
            }

            Major::firstOrCreate(
                ['code' => $major['code']],
                [...$major, 'department_id' => $department->id, 'status' => 'Active']
            );
        }

        if (! empty($missingDepartments)) {
            $codes = implode(', ', $missingDepartments);
            $this->command?->warn(
                "MajorSeeder: Department(s) [{$codes}] not found — run CollegeSeeder and ".
                'DepartmentSeeder first, then re-run this seeder.'
            );
        }
    }
}