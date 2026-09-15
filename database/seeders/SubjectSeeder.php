<?php

namespace Database\Seeders;

use App\Models\Major;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * SubjectSeeder
 * ---------------------------------------------------------------------
 * Populates the master `subjects` catalog from the official PAP
 * curricula for BSED (English), BSIT, BSTM, BSHM, and BSCRIM
 * (Questioned Documents / Fingerprint / Firearms / Lie Detection).
 *
 * SCHEMA NOTES / ASSUMPTIONS (read before editing)
 * ---------------------------------------------------------------------
 * This seeder targets the `subjects` table plus the `subject_major`
 * pivot:
 *     subject_code, subject_title, college_id, major_id, category,
 *     subject_type, units, lecture_hours, laboratory_hours,
 *     required_hours, deployment_type, description, is_active
 * It does NOT use required_room_type/required_room_group,
 * allow_split_schedule, prerequisite_id, or a SubjectRoomGroup pivot —
 * none of those exist in this app yet.
 *
 * PRACTICUM/OJT: any row with 'practicum' => true is seeded with
 * subject_type = 'practicum' and deployment_type = 'off_campus'
 * instead of 'regular' (see run()). Set 'required_hours' on that same
 * row wherever the source PAP curriculum PDF states an explicit hour
 * count (e.g. "300 hours", "600 hours"); left null otherwise so it
 * can be confirmed and filled in via the Subjects page instead of
 * guessing a number the PDF never printed.
 *
 * 1. `category` is only 'Major' or 'General Education'. Every row's
 *    'major' boolean below maps directly to that. ('Minor' is a valid
 *    category value in the schema but this catalog has no Minor rows.)
 *
 * 2. Applicable Major(s) are resolved best-effort from each row's
 *    'group' value by looking up `Major::where('code', $group)`.
 *    - Single-program catalogs (bsitOnly, bsedOnly, bstmOnly,
 *      bshmOnly, and each BSCRIM specialization) are tagged with that
 *      program's Major code (e.g. 'BSIT', 'BSCRIMQD') and linked via
 *      the subject_major pivot.
 *    - General Education/PATHFIT/NSTP subjects are tagged with a null
 *      group — genuinely institution-wide, no single Major (or even
 *      College) owns them, so they're seeded with no linked Major and
 *      college_id left null.
 *    - tourismHospitalityShared() sets an explicit 'group' => ['TM',
 *      'BSHM'] array per row; bscrimShared() is tagged with all four
 *      BSCRIM specialization codes (two of its rows — FORENSIC4 and
 *      FORENSIC6 — override that with their own smaller 'group'
 *      array, since those two aren't actually used by all four
 *      specializations per the PDFs). run() links the Subject to
 *      EVERY Major in its resolved group via subject_major, and uses
 *      the first one's College as the Subject's owning college_id.
 *    - If a Major with a given code doesn't exist yet in your database,
 *      that subject is still seeded — just with no Major linked for
 *      that code — and a warning listing the missing code(s) is
 *      printed at the end. Create the matching Major record(s) and
 *      re-run to link them.
 *
 * 3. Prerequisites ('prereq' key on each row) are intentionally NOT
 *    written anywhere. In this app, a prerequisite is a property of a
 *    Curriculum placement (CurriculumItem::prerequisite_subject_id),
 *    not of the master Subject — the same subject can have a different
 *    prerequisite in different curriculums. The 'prereq' data is left
 *    in each row purely as reference for whoever builds out Curriculum
 *    Items later; this seeder simply ignores it.
 *
 * 4. The legacy 'room' key (Lecture / Computer Laboratory / Science
 *    Laboratory / Speech Laboratory / PE Area / Any) is likewise left
 *    in the data for reference but unused — there's no room-type
 *    column on `subjects` in this schema.
 *
 * 5. Deduplication, code normalization, and PATHFIT title-variance
 *    notes from the original catalog still apply — see the individual
 *    catalog methods below for details.
 *
 * Run with: php artisan db:seed --class=SubjectSeeder
 * Safe to re-run: uses Subject::updateOrCreate() throughout.
 */
class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = array_merge(
            // General Education / PATHFIT / NSTP — Major-category flag is false
            // for these already (see generalEducationAndMinors()), so the null
            // group here is moot; kept null for clarity.
            $this->tag($this->generalEducationAndMinors(), null),
            // Every row in tourismHospitalityShared() sets its own explicit
            // 'group' => ['TM', 'HM'] array — tag()'s fallback is never
            // actually used for this catalog.
            $this->tag($this->tourismHospitalityShared(), null),
            $this->tag($this->bstmOnly(), 'TM'),
            $this->tag($this->bshmOnly(), 'HM'),
            $this->tag($this->bsitOnly(), 'IT'),
            $this->tag($this->bsedOnly(), 'ED'),
            // Shared across all four BSCRIM specializations — Questioned
            // Documents, Fingerprint, Firearms/Ballistics, and Lie
            // Detection all require these, so every row is linked to all
            // four Majors (two rows below override this with a smaller
            // subset — see their inline comments).
            $this->tag($this->bscrimShared(), ['QD', 'FI', 'FB', 'LD']),
            $this->tag($this->bscrimQuestionedDocuments(), 'QD'),
            $this->tag($this->bscrimFingerprint(), 'FI'),
            $this->tag($this->bscrimFirearms(), 'FB'),
            $this->tag($this->bscrimLieDetection(), 'LD'),
        );

        $unmatchedMajorCodes = [];

        foreach ($subjects as $s) {
            $isMajor = (bool) $s['major'];
            // Practicum/OJT flag — see the 'practicum' key on the individual
            // PRACTICUM*/PRAC101 rows below (bstmOnly, bshmOnly, bsitOnly,
            // bsedOnly, bscrimShared). Every other row defaults to Regular.
            $isPracticum = (bool) ($s['practicum'] ?? false);
            $majorIds = [];
            $collegeId = null;

            if ($isMajor) {
                // tourismHospitalityShared() rows set 'group' to an array of
                // program codes (e.g. ['BSTM', 'BSHM']) — under the new
                // College + Applicable Major(s) model every matching Major
                // is linked via the subject_major pivot, not just the first.
                $majorCodes = is_array($s['group']) ? $s['group'] : (($s['group'] !== null) ? [$s['group']] : []);

                foreach ($majorCodes as $majorCode) {
                    $major = Major::with('department')->where('code', $majorCode)->first();

                    if ($major === null) {
                        $unmatchedMajorCodes[$majorCode] = true;

                        continue;
                    }

                    $majorIds[] = $major->id;
                    // All majors tagged on one row are expected to share the
                    // same College (e.g. BSTM/BSHM both under CTHM) — the
                    // first resolved major's College is used as the
                    // Subject's owning College.
                    $collegeId ??= $major->department?->college_id;
                }
            }

            $subject = Subject::updateOrCreate(
                ['subject_code' => $s['code']],
                [
                    'subject_title' => $s['title'],
                    'category' => $isMajor ? 'Major' : 'General Education',
                    'college_id' => $collegeId,
                    'major_id' => $majorIds[0] ?? null,
                    'subject_type' => $isPracticum ? 'practicum' : 'regular',
                    'units' => $s['units'],
                    'lecture_hours' => $s['lec'],
                    'laboratory_hours' => $s['lab'],
                    // Total off-campus hours the student must complete —
                    // only set where the PAP curriculum PDF states it
                    // explicitly (BSTM/BSHM Practicum phases: 300/600
                    // hours). Left null for PRAC101/BSED/BSCRIM Internship
                    // rows, whose PDFs don't print an hour count — fill
                    // in via the Subjects page once confirmed.
                    'required_hours' => $isPracticum ? ($s['required_hours'] ?? null) : null,
                    'deployment_type' => $isPracticum ? 'off_campus' : null,
                    'is_active' => true,
                ]
            );

            $subject->majors()->sync($majorIds);
        }

        if (! empty($unmatchedMajorCodes)) {
            $codes = implode(', ', array_keys($unmatchedMajorCodes));
            $this->command?->warn(
                "SubjectSeeder: no Major found with code(s) [{$codes}]. Those subjects were ".
                "seeded with no linked Major/College — create matching Major records (matching the ".
                "'code' column) and re-run this seeder to link them."
            );
        }
    }

    /**
     * Stamps every row in a catalog array with the Major 'code' it
     * belongs to (unless a row already sets one explicitly — used by
     * tourismHospitalityShared() below to opt into an array of program
     * codes instead of the catalog's default single code). A null
     * A null $group is a valid, deliberate fallback meaning "no single
     * Major applies" (see run()'s comments for which catalogs use
     * this). $group may also be a single code (string) or several
     * codes at once (array) — e.g. bscrimShared() tags every row with
     * all four BSCRIM specialization codes.
     */
    private function tag(array $rows, array|string|null $group): array
    {
        foreach ($rows as &$row) {
            if (!array_key_exists('group', $row)) {
                $row['group'] = $group;
            }
        }

        return $rows;
    }

    /**
     * Shared General Education, PATHFIT, and NSTP subjects.
     * Appear identically across BSED, BSIT, BSTM, BSHM, and all BSCRIM
     * majors — seeded once per the dedup rule.
     */
    private function generalEducationAndMinors(): array
    {
        return [
            ['code' => 'UTS',      'title' => 'Understanding the Self',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            // Normalized from "MATH" (BSCRIM PDFs) to MMW — same subject as every other program's MMW.
            ['code' => 'MMW',      'title' => 'Mathematics in the Modern World',            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'GENSOC',   'title' => 'Gender and Society',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'READINGS', 'title' => 'Readings in Philippine History',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PCOM',     'title' => 'Purposive Communication',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'ITE',      'title' => 'Living in the IT Era',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TCW',      'title' => 'The Contemporary World',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'ART',      'title' => 'Art Appreciation',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PPC',      'title' => 'Philippine Popular Culture',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'STS',      'title' => 'Science, Technology and Society',             'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'ETHICS',   'title' => 'Ethics',                                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PIC',      'title' => 'Philippine Indigenous Communities',           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'RIZAL',    'title' => 'Life, Works and Writings of Rizal',           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'NSTP1',    'title' => 'National Service Training Program 1',         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'NSTP2',    'title' => 'National Service Training Program 2',         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => 'NSTP1'],

            // PATHFIT: canonical titles below are the majority titles used by BSIT/BSTM/BSHM.
            // BSED and BSCRIM use different descriptive titles for the same code/hours/units slot:
            //   PATHFIT1 -> BSED: "Movement Enhancement Training" | BSCRIM: "Fundamentals of Martial Arts"
            //   PATHFIT2 -> BSED: "Fitness Exercise for Specific Sports" | BSCRIM: "Arms and Disarming Techniques"
            //   PATHFIT3 -> BSED: "Physical Activities Towards Health and Fitness in Dance" | BSCRIM: "First Aid and Water Survival" (no prereq in BSCRIM)
            //   PATHFIT4 -> BSED: "Physical Activities Towards Health and Fitness in Sports" | BSCRIM: "Fundamentals of Marksmanship" (no prereq in BSCRIM)
            // Confirm with your adviser whether these should be split into per-program subjects instead.
            ['code' => 'PATHFIT1', 'title' => 'Movement Competency Training',                'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PATHFIT2', 'title' => 'Exercise-based Fitness Activities',            'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => 'PATHFIT1'],
            ['code' => 'PATHFIT3', 'title' => 'Individual and Dual Sports',                   'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => 'PATHFIT2'],
            ['code' => 'PATHFIT4', 'title' => 'Team Sports',                                  'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => 'PATHFIT3'],
        ];
    }

    /**
     * Genuinely identical across BSTM and BSHM (verified line-for-line
     * against both PDFs: same titles, hours, and units).
     */
    private function tourismHospitalityShared(): array
    {
        return [
            ['code' => 'BC1',  'title' => 'Operation Management',                                            'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'BC2',  'title' => 'Strategic Management',                                             'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => 'BC1'],

            ['code' => 'THC1', 'title' => 'Micro Perspective of Tourism and Hospitality',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC2', 'title' => 'Risk Management as Applied to Safety, Security and Sanitation',    'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC3', 'title' => 'Philippine Culture and Tourism Geography',                         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC4', 'title' => 'Quality Service Management in Tourism',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC5', 'title' => 'Professional Development and Applied Ethics',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC6', 'title' => 'Entrepreneurship in Tourism and Hospitality',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC7', 'title' => 'Legal Aspects in Tourism and Hospitality',                         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC8', 'title' => 'Multi-cultural Diversity in Workplace for Tourism Professionals',  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'THC9', 'title' => 'Macro Perspective of Tourism and Hospitality',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => 'THC1'],
            ['code' => 'THC10','title' => 'Tourism and Hospitality Marketing',                                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],

            ['code' => 'FLT1', 'title' => 'Aircraft Familiarization',                                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'FLT2', 'title' => 'Airport and Airline Operation Practices',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => 'FLT1'],
            ['code' => 'FLT3', 'title' => 'Emergency Procedures and Equipment',                                'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'group' => ['TM', 'HM'], 'prereq' => 'FLT2'],
            ['code' => 'FLT4', 'title' => 'Air Laws and Regulations',                                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => 'FLT2'],
            // FLT5 prerequisite is "FLT 3 & 4" (multiple) — left null, see comment rule.
            ['code' => 'FLT5', 'title' => 'In-flight Food and Beverage Services / Food Menu Theory',           'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'group' => ['TM', 'HM'], 'prereq' => null],

            // Bridging courses for non-ABM (Accountancy/Business/Management) SHS graduates.
            // Applicable to BSTM and BSHM only, same as the rest of this method — Pass 1
            // in run() inserts one pivot row per program from the explicit 'group' array.
            // lec bumped to 3 (matching every other plain 3-unit Lecture subject in this
            // catalog) so total_hours lines up with the unit count.
            ['code' => 'NON-ABM1', 'title' => 'Business Marketing',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'NON-ABM2', 'title' => 'Organization and Management',     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'NON-ABM3', 'title' => 'Fundamentals of Accounting',      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'NON-ABM4', 'title' => 'Business Finance',                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
            ['code' => 'NON-ABM5', 'title' => 'Applied Economics',               'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],

            // BSTM's "RESEARCH" and BSHM's "RESEARCH" are the same subject; BSED uses "RES" and
            // BSCRIM uses "RESEARCH1"/"RESEARCH2" — different codes, so no collision.
            ['code' => 'RESEARCH', 'title' => 'Methods of Research',             'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['TM', 'HM'], 'prereq' => null],
        ];
    }

    /** BSTM-only major subjects. */
    private function bstmOnly(): array
    {
        return [
            ['code' => 'TPC1',  'title' => 'Tour and Travel Management',                                     'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            ['code' => 'TPC2',  'title' => 'Foreign Language 1 (Japanese 1 / Korean 1)',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TPC3',  'title' => 'Global Culture and Tourism Geography',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'TPC1'],
            ['code' => 'TPC4',  'title' => 'Tourism Policy, Planning and Development',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'TPC1'],
            ['code' => 'TPC5',  'title' => 'Sustainable Tourism',                                              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "THC 1 & 6" (multiple) — left null.
            ['code' => 'TPC6',  'title' => 'Introduction to Meetings, Incentives, Conferences and Events Management', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TPC7',  'title' => 'Foreign Language 2 (Japanese 2 / Korean 2)',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'TPC2'],
            ['code' => 'TPC8',  'title' => 'Applied Business Tools and Technologies',                         'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => 'BC1'],
            ['code' => 'TPC9',  'title' => 'Transportation Management',                                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TPC10', 'title' => 'Research in Tourism',                                              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'RESEARCH'],

            // Prereq "THC 3; TPC 3" (multiple) — left null.
            ['code' => 'PROF-ELECT1-BSTM', 'title' => 'Tour Guiding',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PROF-ELECT2-BSTM', 'title' => 'Medical and Wellness Tourism',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "BC 1; TPC 8" (multiple) — left null.
            ['code' => 'PROF-ELECT3-BSTM', 'title' => 'Tourism Information Management',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "TPC 1 & 4; THC 10" (multiple) — left null.
            ['code' => 'PROF-ELECT4-BSTM', 'title' => 'Destination Management and Marketing',  'lec' => 3, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            // Prereq "TPC 1, 3 & 5" (multiple) — left null.
            ['code' => 'PROF-ELECT5-BSTM', 'title' => 'Eco-Tourism Management',                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // PRACTICUM/OJT: Tourism practicum phases. Prereqs are textual ("All 2nd Year TPC & FLT
            // Subjects" / "All TPC Subjects") — left null. Flagged practicum => true below; hours are printed in the PDF.
            ['code' => 'PRACTICUM1-BSTM', 'title' => 'Phase I - Tourism Practicum in Travel Agency / Passenger Handling and Assisting 1 (300 hours)', 'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Any', 'prereq' => null, 'practicum' => true, 'required_hours' => 300],
            ['code' => 'PRACTICUM2-BSTM', 'title' => 'Phase II - Airline/Shipping Line Phase (600 hours)',                                            'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null, 'practicum' => true, 'required_hours' => 600],
        ];
    }

    /** BSHM-only major subjects. */
    private function bshmOnly(): array
    {
        return [
            ['code' => 'HPC1', 'title' => 'Kitchen Essentials and Basic Foods Preparation',              'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            ['code' => 'HPC2', 'title' => 'Foreign Language 1 (Japanese 1 / Korean 1)',                   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "HPC 1; THC 2" (multiple) — left null.
            ['code' => 'HPC3', 'title' => 'Fundamentals of Food Service Operation',                       'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            ['code' => 'HPC4', 'title' => 'Fundamentals of Lodging Operations',                           'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'HPC5', 'title' => 'Ergonomics and Facilities Planning for the Hospitality Industry', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'HPC6', 'title' => 'Introduction to Meetings, Incentives, Conferences and Events Management', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'HPC7', 'title' => 'Foreign Language 2 (Japanese 2 / Korean 2)',                   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'HPC2'],
            ['code' => 'HPC8', 'title' => 'Applied Business Tools and Technologies',                      'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => 'BC1'],
            ['code' => 'HPC9', 'title' => 'Supply Chain Management in Hospitality Industry',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'BC2'],
            ['code' => 'HPC10','title' => 'Research in Hospitality',                                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'RESEARCH'],

            // Prereq "HPC 1; THC 2" (multiple) — left null.
            ['code' => 'PROF-ELECT1-BSHM', 'title' => 'Fundamentals of Food Science and Technology', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => null],
            ['code' => 'PROF-ELECT2-BSHM', 'title' => 'Housekeeping Operations',                      'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'HPC4'],
            ['code' => 'PROF-ELECT3-BSHM', 'title' => 'Front Office',                                  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'THC9'],
            ['code' => 'PROF-ELECT4-BSHM', 'title' => 'Food and Beverage Service Management',          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'HPC3'],
            // Prereq "HPC 1 & 3; PROF ELECT 1 & 7" (multiple / source typo — "PROF ELECT 7" doesn't exist in this program) — left null.
            ['code' => 'PROF-ELECT5-BSHM', 'title' => 'Catering Management',                           'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => null],

            // PRACTICUM/OJT: Hospitality practicum phases. Prereqs are textual ("All 2nd Year HPC
            // Subjects" / "All HPC Subjects") — left null. Flagged practicum => true below; hours are printed in the PDF.
            ['code' => 'PRACTICUM1-BSHM', 'title' => 'Phase I - Housekeeping/Food and Beverage Operations/Passenger Handling and Assisting 1 (300 hours)', 'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Any', 'prereq' => null, 'practicum' => true, 'required_hours' => 300],
            ['code' => 'PRACTICUM2-BSHM', 'title' => 'Phase II - Hotel Phase (600 hours)',                                                                  'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null, 'practicum' => true, 'required_hours' => 600],
        ];
    }

    /** BSIT-only major subjects. */
    private function bsitOnly(): array
    {
        return [
            ['code' => 'CC101',  'title' => 'Introduction to Computing',                          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'CC102',  'title' => 'Computer Programming 1',                              'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'CC103',  'title' => 'Computer Programming 2',                              'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC102'],
            ['code' => 'HCI101', 'title' => 'Introduction to Human Computer Interaction',          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC102'],

            // Bridging courses for non-ICT SHS graduates. Units bumped to 3 (matching
            // every other lec=2/lab=3 subject in this catalog, e.g. CC101/CC102).
            ['code' => 'NON-ICT1', 'title' => 'Introduction to Computer Systems Servicing',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'NON-ICT2', 'title' => 'Installing and Configuring Computer Systems', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'NON-ICT1'],

            ['code' => 'CC104', 'title' => 'Data Structures and Algorithms',        'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'CC105', 'title' => 'Information Management',                'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'PF101', 'title' => 'Object-Oriented Programming',           'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'PT101', 'title' => 'Platform Technologies',                 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'MS101', 'title' => 'Discrete Mathematics',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => 'MMW'],

            ['code' => 'IM101', 'title' => 'Advanced Database Systems',             'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC105'],
            ['code' => 'WS101', 'title' => 'Web Systems and Technologies',          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'HCI101'],
            ['code' => 'NET101','title' => 'Networking 1',                          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'PT101'],
            // Prereq "PF 101; PT 101" (multiple) — left null.
            ['code' => 'IPT101','title' => 'Integrative Programming and Technologies', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'MS102', 'title' => 'Quantitative Methods',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => 'MS101'],

            ['code' => 'CC106', 'title' => 'Application Development and Emerging Technologies', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IM101'],
            ['code' => 'NET102','title' => 'Networking 2',                          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'NET101'],
            ['code' => 'SIA101','title' => 'Systems Integration and Architecture',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IPT101'],
            ['code' => 'IAS101','title' => 'Information Assurance and Security 1',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'SIA101'],
            // Prereq "IPT 101; CC 106" (multiple) — left null.
            ['code' => 'CAP101','title' => 'Capstone Project and Research 1',       'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],

            ['code' => 'IAS102','title' => 'Information Assurance and Security 2',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IAS101'],
            ['code' => 'SA101', 'title' => 'Systems Administration and Maintenance','lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IAS101'],
            ['code' => 'CAP102','title' => 'Capstone Project and Research 2',       'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CAP101'],
            // Prereq "4th Year Standing" (textual) — left null.
            ['code' => 'SP101', 'title' => 'Social and Professional Issues',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],

            // PRACTICUM/OJT. Prereq "IAS 101; CC 106" (multiple) — left null. Flagged
            // practicum => true below; the PDF doesn't print an hour count, so required_hours
            // is left null pending confirmation.
            ['code' => 'PRAC101', 'title' => 'Practicum', 'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null, 'practicum' => true],

            // Recommended electives: Computer-Aided Design, Graphic Design, Film/Video Production,
            // Digital Animation — the PDF lists these as generic "Elective 1-4" slots.
            ['code' => 'IT-ELECT1', 'title' => 'Elective 1', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'IT-ELECT2', 'title' => 'Elective 2', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC102'],
            ['code' => 'IT-ELECT3', 'title' => 'Elective 3', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'IT-ELECT4', 'title' => 'Elective 4', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
        ];
    }

    /** BSED (English major)-only subjects. */
    private function bsedOnly(): array
    {
        return [
            ['code' => 'EDUC1', 'title' => 'The Child and Adolescent Learners and Learning Principles', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM1',   'title' => 'Introduction to Linguistics',                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM2',   'title' => 'Language, Culture and Society',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM1'],
            ['code' => 'EM3',   'title' => 'Structure of English',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM1'],
            // Cognate/Elective course, not a core Major or GE subject per the unit summary.
            ['code' => 'ELECT', 'title' => 'Stylistics and Discourse Analysis',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],

            // Prereq "EM 2 & 3" (multiple) — left null.
            ['code' => 'EM4',   'title' => 'Principles and Theories of Language Acquisition and Learning', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM5',   'title' => 'Language Programs and Policies in Multilingual Societies', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM2'],
            // Prereq "EM 1 & 3" (multiple) — left null.
            ['code' => 'EM6',   'title' => 'Technical Writing',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC2', 'title' => 'The Teaching Profession',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // Prereq "EM 2 & 4" (multiple) — left null.
            ['code' => 'EM7',   'title' => 'Children and Adolescent Literature',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM8',   'title' => 'Mythology and Folklore',                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM2'],
            ['code' => 'EM9',   'title' => 'Contemporary and Popular Literature',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM2'],
            ['code' => 'EDUC3', 'title' => 'Facilitating Learner-Centered Teaching',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC4', 'title' => 'Technology for Teaching and Learning 1',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'EM10',  'title' => 'Survey of Afro-Asian Literature',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM11',  'title' => 'Survey of English and American Literature',    'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM12',  'title' => 'Survey of Philippine Literature in English',   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM13',  'title' => 'Language Learning Materials Development',      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM4'],
            ['code' => 'EDUC5', 'title' => 'Foundation of Special and Inclusive Education','lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC6', 'title' => 'Assessment of Learning 1',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // BSED's own "Methods of Research" code — distinct from BSTM/BSHM's "RESEARCH".
            ['code' => 'RES',   'title' => 'Methods of Research',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM14',  'title' => 'Literary Criticism',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM15',  'title' => 'Speech and Theater Arts',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM3'],
            ['code' => 'EM16',  'title' => 'Campus Journalism',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM6'],
            ['code' => 'EM17',  'title' => 'Technology for Teaching and Learning 2 (Technology in Language Education)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM13'],
            ['code' => 'EDUC7', 'title' => 'The Teachers and the School Curriculum',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC8', 'title' => 'The Teacher and the Community, School Culture and Organization Leadership', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'COGNATE','title' => 'Remedial Instruction and Early Intervention for Language Difficulties', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'EM18',  'title' => 'Teaching and Assessment of Literature Studies', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM14'],
            ['code' => 'EM19',  'title' => 'Teaching and Assessment of Macro Skills',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM4'],
            // Prereq "EM 1 & 3" (multiple) — left null.
            ['code' => 'EM20',  'title' => 'Teaching and Assessment of Grammar',            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM21',  'title' => 'Language Education Research',                   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM4'],
            ['code' => 'EDUC9', 'title' => 'Building and Enhancing New Literacies Across the Curriculum', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC10','title' => 'Assessment of Learning 2',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // Field Study / Practicum. Prereqs are textual ("All Professional & Major Subjects") —
            // left null. FS1/FS2 are classroom-adjacent observation subjects, not OJT proper.
            ['code' => 'FS1', 'title' => 'Observations of Teaching-Learning in Actual School Environment', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'FS2', 'title' => 'Participation and Teaching Assistantship',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // PRACTICUM/OJT: Teaching Internship. Flagged practicum => true below; the PDF doesn't print an
            // hour count, so required_hours is left null pending confirmation.
            ['code' => 'PRACTICUM', 'title' => 'Teaching Internship', 'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null, 'practicum' => true], // prereq FS1 & FS2 (multiple)
        ];
    }

    /**
     * Shared core across all four BSCRIM majors (Questioned Documents,
     * Fingerprint Identification, Firearms Identification, Lie
     * Detection). Verified identical titles/hours/units across all four
     * PDFs. Ignoring the FB/LD/QD/FI specialization labels per the
     * instructions — every BSCRIM lab subject below is conceptually a
     * BSCRIM specialized room; there is no `required_room_group` column
     * to encode that, so it's noted only in comments.
     */
    private function bscrimShared(): array
    {
        return [
            ['code' => 'LAW1',  'title' => 'Introduction to Philippine Criminal Justice System', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CRIM1', 'title' => 'Introduction to Criminology',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CFLM1', 'title' => 'Character Formation, Nationalism and Patriotism',      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CRIM2', 'title' => 'Theories of Crime Causation',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'LEA1',  'title' => 'Law Enforcement Organization and Administration',      'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CDI1',  'title' => 'Fundamentals of Investigation and Intelligence',       'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'LEA2',  'title' => 'Comparative Models in Policing',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'CRIM3', 'title' => 'Human Behavior and Victimology',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CFLM2', 'title' => 'Character Formation with Leadership, Decision Making, Management and Administration', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'CFLM1'],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'CRIM4', 'title' => 'Professional Conduct and Ethical Standards',            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // BSCRIM specialized lab subject (Science Laboratory; conceptually a BSCRIM-only room group).
            ['code' => 'FORENSIC1', 'title' => 'Forensic Photography',                              'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],
            ['code' => 'CDI2',  'title' => 'Specialized Crime Investigation with Legal Medicine',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'CDI1'],
            ['code' => 'CHEM',  'title' => 'General Chemistry (Organic)',                            'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],

            ['code' => 'CORR1', 'title' => 'Institutional Corrections',                              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'LAW2',  'title' => 'Human Rights Education',                                 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            ['code' => 'LAW3',  'title' => 'Criminal Law (Book 1)',                                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            // Prereq "CDI 1 & 2" (multiple) — left null.
            ['code' => 'CDI3',  'title' => 'Specialized Crime Investigation with Simulation on Interrogation and Interview', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CDI4',  'title' => 'Traffic Management and Accidental Investigation with Driving', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'CRIM5', 'title' => 'Juvenile Delinquency and Juvenile Justice System',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'FORENSIC2', 'title' => 'Personal Identification Techniques',                  'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],

            ['code' => 'CORR2', 'title' => 'Non-Institutional Corrections',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'CORR1'],
            // Prereq "LEA 1 & 2" (multiple) — left null.
            ['code' => 'LEA3',  'title' => 'Introduction to Industrial Security and Concepts',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'LAW4',  'title' => 'Criminal Law (Book 2)',                                    'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            ['code' => 'LAW5',  'title' => 'Evidence',                                                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            // Prereq "CDI 1 & 2" (multiple) — left null.
            ['code' => 'CDI5',  'title' => 'Technical English 1 (Technical Report Writing and Presentation)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'RESEARCH1', 'title' => 'Criminological Research 1 (Research Methods with Applied Statistics)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CDI6',  'title' => 'Fire Protection and Arson Investigation',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'FORENSIC5', 'title' => 'Forensic Ballistics',                                  'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],
            // Used by QD and FI majors; skipped by FAI (own ballistics track) and LD.
            ['code' => 'FORENSIC6', 'title' => 'Lie Detection Techniques',                             'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'group' => ['QD', 'FI'], 'prereq' => null],
            // Used by FI, FAI, and LD majors; skipped by QD (own questioned-documents track).
            ['code' => 'FORENSIC4', 'title' => 'Questioned Documents Examination',                     'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'group' => ['FI', 'FB', 'LD'], 'prereq' => null],

            ['code' => 'CORR3', 'title' => 'Therapeutic Modalities',                                    'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CORR 1 & 2 (multiple)
            ['code' => 'LEA4',  'title' => 'Law Enforcement Operation and Planning with Crime Mapping', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq LEA 1 & 2 (multiple)
            ['code' => 'CDI7',  'title' => 'Vice and Drug Education and Control',                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'CRIM6', 'title' => 'Dispute Resolution and Crisis/Incident Management',         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CRIM 1 & 2 (multiple)
            ['code' => 'RESEARCH2', 'title' => 'Criminological Research 2 (Thesis Writing and Presentation)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CRIM 1 & 2 (multiple)
            // Prereq "4th Year Standing" (textual) — left null.
            ['code' => 'ENHANCE1', 'title' => 'Enhancement on CRIM, CORR and CDI', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // PRACTICUM/OJT: Criminology internship phases. Prereq for phase 1 is "4th Year Standing"
            // (textual, left null); phase 2 correctly chains to phase 1. Both flagged practicum =>
            // true below. Original PDF marks lab column as "FIELD" (off-campus hours) with no
            // explicit hour count printed, so required_hours is left null pending confirmation.
            ['code' => 'PRACTICUM1-BSCRIM', 'title' => 'Internship (On-the-Job Training 1)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => null, 'practicum' => true],
            ['code' => 'PRACTICUM2-BSCRIM', 'title' => 'Internship (On-the-Job Training 2)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'PRACTICUM1-BSCRIM', 'practicum' => true],

            ['code' => 'LAW6',  'title' => 'Criminal Procedure and Court Testimony',                    'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            ['code' => 'CDI8',  'title' => 'Technical English 2 (Legal Forms)',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'CDI9',  'title' => 'Introduction to Cybercrime and Environmental Laws Protection', 'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'ENHANCE2', 'title' => 'Enhancement on FORENSICS, LEA and CLJ', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'ENHANCE1'],
        ];
    }

    /** BSCRIM — Major in Questioned Documents Examination. */
    private function bscrimQuestionedDocuments(): array
    {
        return [
            ['code' => 'QD1', 'title' => 'Introduction to Questioned Documents Examination',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'QD2', 'title' => 'Basic Principles of Questioned Documents Examination',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD1'],
            ['code' => 'QD3', 'title' => 'Determine Factors in Questioned Documents Examination',  'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD2'],
            ['code' => 'QD4', 'title' => 'Tools and Instrument in Questioned Documents Examination','lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD3'],
            ['code' => 'QD5', 'title' => 'Typewriting, Counterfeiting and Alteration Detection',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD4'],
            ['code' => 'QD6', 'title' => 'Moot Court in Relation to Questioned Documents Cases',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD5'],
        ];
    }

    /** BSCRIM — Major in Fingerprint Identification. */
    private function bscrimFingerprint(): array
    {
        return [
            ['code' => 'FI1', 'title' => 'Introduction to Fingerprint Identification',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'FI2', 'title' => 'Mechanics of Taking Legible Inked Fingerprint',      'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI1'],
            ['code' => 'FI3', 'title' => 'The Fingerprint Pattern and Their Interpretation',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI2'],
            ['code' => 'FI4', 'title' => 'The Latent Impression and Their Development Process','lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI3'],
            ['code' => 'FI5', 'title' => 'The Fingerprint Classification',                     'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI4'],
            ['code' => 'FI6', 'title' => 'Preparation for Court Testimony',                    'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI5'],
        ];
    }

    /** BSCRIM — Major in Firearms Identification (Forensic Ballistics). */
    private function bscrimFirearms(): array
    {
        return [
            ['code' => 'FAI1', 'title' => 'Introduction to Firearms Identification',   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'FAI2', 'title' => 'Study of Small Firearms Identification',     'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI1'],
            ['code' => 'FAI3', 'title' => 'Techniques in Handling Physical Evidence',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI2'],
            ['code' => 'FAI4', 'title' => 'Principles in Firearms Identification',      'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI3'],
            ['code' => 'FAI5', 'title' => 'Theory on Ballistics Examination',           'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI4'],
            ['code' => 'FAI6', 'title' => 'Practical Exams on Bullet and Shells with the Bullet Comparison Microscope', 'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI5'],
        ];
    }

    /** BSCRIM — Major in Lie Detection (Polygraph). */
    private function bscrimLieDetection(): array
    {
        return [
            ['code' => 'LD1', 'title' => 'Introduction to Lie Detection',                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'LD2', 'title' => 'The Polygraph of Lying',                              'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD1'],
            ['code' => 'LD3', 'title' => 'The Polygraph and Its Operation (Hands-on)',          'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD2'],
            ['code' => 'LD4', 'title' => 'The Test Question Formulation and Construction',      'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD3'],
            ['code' => 'LD5', 'title' => 'The Legal Status of the Polygraph Technique',         'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD4'],
            ['code' => 'LD6', 'title' => 'Interrogation Tactics and Techniques and Other Considerations', 'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD5'],
        ];
    }
}