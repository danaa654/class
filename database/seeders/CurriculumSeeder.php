<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\Major;
use Illuminate\Database\Seeder;

/**
 * CurriculumSeeder
 * ---------------------------------------------------------------------
 * Creates one Curriculum per Major, using the codes CurriculumItemSeeder
 * expects (e.g. "BSIT-2023-2027", "BSCRIM-FB-2023-2027"). Unlike the
 * generic per-Major loop, this keeps explicit rows so the code and name
 * can diverge from the Major's own code — needed for the four BSCRIM
 * majors, whose Curriculum codes use a "BSCRIM-<SPEC>" convention that
 * doesn't match their Major codes (BSCRIMQD, BSCRIMFI, BSCRIMFB, BSCRIMLD).
 *
 * Run order: CollegeSeeder -> DepartmentSeeder -> MajorSeeder ->
 * SubjectSeeder -> CurriculumSeeder -> CurriculumItemSeeder.
 *
 * Safe to re-run: uses Curriculum::updateOrCreate() keyed on `code`.
 */
class CurriculumSeeder extends Seeder
{
    /**
     * Every Curriculum here spans 4 years, matching the A.Y. 2023-2027
     * span printed on PAP's actual prospectus covers.
     */
    private const START_YEAR = 2023;

    private const END_YEAR = 2027;

    public function run(): void
    {
        $curricula = [
            [
                'major_code' => 'IT',
                'code' => 'BSIT-2023-2027',
                'name' => 'BS Information Technology Curriculum',
            ],
            [
                'major_code' => 'ED',
                'code' => 'BSED-ENG-2023-2027',
                'name' => 'BSED English Curriculum',
            ],
            [
                'major_code' => 'QD',
                'code' => 'BSCRIM-QD-2023-2027',
                'name' => 'BS Criminology (Questioned Documents Examination) Curriculum',
            ],
            [
                'major_code' => 'FI',
                'code' => 'BSCRIM-FI-2023-2027',
                'name' => 'BS Criminology (Fingerprint Identification) Curriculum',
            ],
            [
                'major_code' => 'FB',
                'code' => 'BSCRIM-FB-2023-2027',
                'name' => 'BS Criminology (Firearms Identification) Curriculum',
            ],
            [
                'major_code' => 'LD',
                'code' => 'BSCRIM-LD-2023-2027',
                'name' => 'BS Criminology (Lie Detection) Curriculum',
            ],
            [
                'major_code' => 'HM',
                'code' => 'BSHM-2023-2027',
                'name' => 'BS Hospitality Management Curriculum',
            ],
            [
                'major_code' => 'TM',
                'code' => 'BSTM-2023-2027',
                'name' => 'BS Tourism Management Curriculum',
            ],
        ];

        $missingMajors = [];

        foreach ($curricula as $curriculum) {
            $major = Major::where('code', $curriculum['major_code'])->first();

            if (! $major) {
                $missingMajors[] = $curriculum['major_code'];

                continue;
            }

            Curriculum::updateOrCreate(
                ['code' => $curriculum['code']],
                [
                    'major_id' => $major->id,
                    'name' => $curriculum['name'],
                    'start_year' => self::START_YEAR,
                    'end_year' => self::END_YEAR,
                    'status' => 'Active',
                    'allow_new_students' => true,
                    'description' => null,
                ]
            );
        }

        if (! empty($missingMajors)) {
            $codes = implode(', ', $missingMajors);
            $this->command?->warn(
                "CurriculumSeeder: Major(s) [{$codes}] not found — run MajorSeeder first, then re-run this seeder."
            );
        }
    }
}