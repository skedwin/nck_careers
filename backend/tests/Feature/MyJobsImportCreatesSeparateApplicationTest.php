<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\Position;
use App\Services\MyJobs\MyJobsImportService;
use Database\Seeders\PositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class MyJobsImportCreatesSeparateApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_does_not_reuse_a_mailbox_application_for_the_same_vacancy(): void
    {
        $this->seed(PositionSeeder::class);

        $position = Position::query()->where('reference_code', 'NCK/REC1')->firstOrFail();
        $applicant = Applicant::query()->create([
            'full_name' => 'Jane Doe',
            'email' => 'jane.myjobs@example.com',
        ]);

        $mailbox = Application::query()->create([
            'application_reference' => 'NCK-2026-111111',
            'applicant_id' => $applicant->id,
            'position_id' => $position->id,
            'subject' => 'Application for Director Registration and Licensing',
            'status' => Application::STATUS_RECEIVED,
            'screening_status' => 'pending',
            'source' => 'email',
            'received_at' => now(),
        ]);

        $importer = app(MyJobsImportService::class);
        $method = new ReflectionMethod(MyJobsImportService::class, 'importRow');
        $result = $method->invoke($importer, [
            'name' => 'Jane Doe',
            'email' => 'jane.myjobs@example.com',
            'file' => 'NCK_REC1.xlsx',
            'mapped_position_id' => $position->id,
            'mapped_position_code' => $position->reference_code,
            'mapped_position_title' => $position->title,
        ], false, false);

        $this->assertSame('created', $result);
        $this->assertSame('email', $mailbox->fresh()->source);
        $this->assertSame(1, Application::query()->myJobs()->where('position_id', $position->id)->count());
        $this->assertSame(1, Application::query()->notMyJobs()->where('position_id', $position->id)->count());
        $this->assertSame(
            $applicant->id,
            Application::query()->myJobs()->where('position_id', $position->id)->value('applicant_id')
        );
    }

    public function test_second_import_enriches_the_existing_myjobs_application(): void
    {
        $this->seed(PositionSeeder::class);

        $position = Position::query()->where('reference_code', 'NCK/REC1')->firstOrFail();
        $importer = app(MyJobsImportService::class);
        $method = new ReflectionMethod(MyJobsImportService::class, 'importRow');
        $row = [
            'name' => 'John Kamau',
            'email' => 'john.myjobs@example.com',
            'file' => 'NCK_REC1.xlsx',
            'mapped_position_id' => $position->id,
            'mapped_position_code' => $position->reference_code,
            'mapped_position_title' => $position->title,
        ];

        $this->assertSame('created', $method->invoke($importer, $row, false, false));
        $this->assertSame('enriched', $method->invoke($importer, $row, false, false));
        $this->assertSame(1, Application::query()->myJobs()->where('position_id', $position->id)->count());
    }
}
