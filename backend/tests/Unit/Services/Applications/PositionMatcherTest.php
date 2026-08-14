<?php

namespace Tests\Unit\Services\Applications;

use App\Models\Position;
use App\Services\Applications\PositionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionMatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $rows = [
            ['NCK/REC5', 'Deputy Director, Human Resources and Administration', 5],
            ['NCK/REC8', 'Registration and Licensing Officer', 8],
            ['NCK/REC11', 'Customer Care Assistant/Senior', 11],
            ['NCK/REC7', 'Corporate Communication Officer', 7],
            ['NCK/REC6', 'Senior Corporate Communication Officer', 6],
        ];

        foreach ($rows as [$code, $title, $sort]) {
            Position::query()->create([
                'title' => $title,
                'reference_code' => $code,
                'status' => 'open',
                'vacancies' => 1,
                'sort_order' => $sort,
                'department' => 'NCK',
            ]);
        }
    }

    public function test_matches_name_prefixed_deputy_hr_subject(): void
    {
        $match = app(PositionMatcher::class)->match(
            'Betty Ngetich Application-DEPUTY DIRECTOR HUMAN RESOURCES AND ADMINISTRATION'
        );

        $this->assertNotNull($match);
        $this->assertSame('NCK/REC5', $match['reference_code']);
    }

    public function test_matches_license_spelling_variant(): void
    {
        $match = app(PositionMatcher::class)->match(
            'Application for the Registration and License officer role'
        );

        $this->assertNotNull($match);
        $this->assertSame('NCK/REC8', $match['reference_code']);
    }

    public function test_prefers_senior_comms_over_officer(): void
    {
        $match = app(PositionMatcher::class)->match(
            'Application for Senior Corporate Communication Officer'
        );

        $this->assertNotNull($match);
        $this->assertSame('NCK/REC6', $match['reference_code']);
    }

    public function test_matches_rec_code(): void
    {
        $match = app(PositionMatcher::class)->match('REF: NCK/REC11 Customer Care');

        $this->assertNotNull($match);
        $this->assertSame('NCK/REC11', $match['reference_code']);
    }
}
