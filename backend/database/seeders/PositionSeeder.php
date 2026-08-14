<?php

namespace Database\Seeders;

use App\Models\Position;
use App\Models\PositionCriterion;
use App\Support\NairobiDate;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now(NairobiDate::TZ);

        // Retire and remove any positions outside the official NCK/REC1–13 set.
        Position::query()
            ->where('reference_code', 'not like', 'NCK/REC%')
            ->each(function (Position $position): void {
                // Applications.position_id is nullOnDelete.
                $position->criteria()->delete();
                $position->delete();
            });

        $defaultCriteria = [
            ['code' => 'cover_letter', 'label' => 'Application / cover letter present', 'description' => 'Email or attached application letter.', 'is_mandatory' => true, 'weight' => 3, 'sort_order' => 1],
            ['code' => 'cv', 'label' => 'Curriculum Vitae attached', 'description' => 'CV / resume in attachments.', 'is_mandatory' => true, 'weight' => 5, 'sort_order' => 2],
            ['code' => 'certificates', 'label' => 'Academic / professional certificates', 'description' => 'Relevant certificates attached.', 'is_mandatory' => true, 'weight' => 4, 'sort_order' => 3],
            ['code' => 'id_docs', 'label' => 'National ID / passport copy', 'description' => 'Identification documents attached.', 'is_mandatory' => false, 'weight' => 2, 'sort_order' => 4],
        ];

        $positions = [
            [
                'reference_code' => 'NCK/REC1',
                'title' => 'Director Registration and Licensing',
                'department' => 'Registration and Licensing',
                'grade' => 'Director',
                'vacancies' => 1,
                'sort_order' => 1,
            ],
            [
                'reference_code' => 'NCK/REC2',
                'title' => 'Corporate Secretary & Director Legal Services',
                'department' => 'Legal Services',
                'grade' => 'Director',
                'vacancies' => 1,
                'sort_order' => 2,
            ],
            [
                'reference_code' => 'NCK/REC3',
                'title' => 'Director Corporate Services',
                'department' => 'Corporate Services',
                'grade' => 'Director',
                'vacancies' => 1,
                'sort_order' => 3,
            ],
            [
                'reference_code' => 'NCK/REC4',
                'title' => 'Deputy Director, Research, Strategy, Planning & Performance Management',
                'department' => 'Strategy and Planning',
                'grade' => 'Deputy Director',
                'vacancies' => 1,
                'sort_order' => 4,
            ],
            [
                'reference_code' => 'NCK/REC5',
                'title' => 'Deputy Director, Human Resources and Administration',
                'department' => 'Human Resources',
                'grade' => 'Deputy Director',
                'vacancies' => 1,
                'sort_order' => 5,
            ],
            [
                'reference_code' => 'NCK/REC6',
                'title' => 'Senior Corporate Communication Officer',
                'department' => 'Corporate Communication',
                'grade' => 'Senior Officer',
                'vacancies' => 1,
                'sort_order' => 6,
            ],
            [
                'reference_code' => 'NCK/REC7',
                'title' => 'Corporate Communication Officer',
                'department' => 'Corporate Communication',
                'grade' => 'Officer',
                'vacancies' => 1,
                'sort_order' => 7,
            ],
            [
                'reference_code' => 'NCK/REC8',
                'title' => 'Registration and Licensing Officer',
                'department' => 'Registration and Licensing',
                'grade' => 'Officer',
                'vacancies' => 2,
                'sort_order' => 8,
            ],
            [
                'reference_code' => 'NCK/REC9',
                'title' => 'Education and Examination Officer',
                'department' => 'Education and Examinations',
                'grade' => 'Officer',
                'vacancies' => 2,
                'sort_order' => 9,
            ],
            [
                'reference_code' => 'NCK/REC10',
                'title' => 'Standards and Compliance Officer',
                'department' => 'Standards and Compliance',
                'grade' => 'Officer',
                'vacancies' => 2,
                'sort_order' => 10,
            ],
            [
                'reference_code' => 'NCK/REC11',
                'title' => 'Customer Care Assistant/Senior',
                'department' => 'Customer Care',
                'grade' => 'Assistant',
                'vacancies' => 2,
                'sort_order' => 11,
            ],
            [
                'reference_code' => 'NCK/REC12',
                'title' => 'Office Administrator',
                'department' => 'Administration',
                'grade' => 'Administrator',
                'vacancies' => 1,
                'sort_order' => 12,
            ],
            [
                'reference_code' => 'NCK/REC13',
                'title' => 'Office Assistant',
                'department' => 'Administration',
                'grade' => 'Assistant',
                'vacancies' => 2,
                'sort_order' => 13,
            ],
        ];

        foreach ($positions as $row) {
            $position = Position::query()->updateOrCreate(
                ['reference_code' => $row['reference_code']],
                [
                    'title' => $row['title'],
                    'description' => sprintf(
                        '%s — %s (%d %s). Applications received via careers@nckenya.go.ke.',
                        $row['title'],
                        $row['reference_code'],
                        $row['vacancies'],
                        $row['vacancies'] === 1 ? 'Position' : 'Positions'
                    ),
                    'department' => $row['department'],
                    'grade' => $row['grade'],
                    'status' => 'open',
                    'vacancies' => $row['vacancies'],
                    'sort_order' => $row['sort_order'],
                    'opens_at' => $now->copy()->subDays(30),
                    'closes_at' => $now->copy()->addMonths(2),
                ]
            );

            foreach ($defaultCriteria as $criterion) {
                PositionCriterion::query()->updateOrCreate(
                    [
                        'position_id' => $position->id,
                        'code' => $criterion['code'],
                    ],
                    $criterion + ['position_id' => $position->id]
                );
            }
        }
    }
}
