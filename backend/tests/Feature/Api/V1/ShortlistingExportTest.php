<?php

namespace Tests\Feature\Api\V1;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShortlistingExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortlisting_exports_require_authentication(): void
    {
        $this->getJson('/api/v1/shortlisting/export/excel')->assertUnauthorized();
        $this->getJson('/api/v1/shortlisting/export/pdf')->assertUnauthorized();
    }

    public function test_authenticated_user_can_export_shortlisting_excel_and_pdf(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($user);

        $position = Position::query()->create([
            'title' => 'Test Officer',
            'reference_code' => 'TST/01',
            'vacancies' => 1,
            'status' => 'open',
        ]);

        $applicant = Applicant::query()->create([
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '0700000000',
            'national_id' => '12345678',
            'gender' => 'Female',
            'county' => 'Nairobi',
            'is_pwd' => false,
        ]);

        Application::query()->create([
            'application_reference' => 'NCK-2026-0001',
            'applicant_id' => $applicant->id,
            'position_id' => $position->id,
            'subject' => 'Application',
            'status' => Application::STATUS_SHORTLISTED,
            'screening_status' => 'passed',
            'source' => 'mailbox',
            'received_at' => now(),
        ]);

        $excel = $this->get('/api/v1/shortlisting/export/excel');
        $excel->assertOk();
        $excel->assertHeader('content-type', 'application/vnd.ms-excel');
        $this->assertNotSame('', $excel->streamedContent());

        $pdf = $this->get('/api/v1/shortlisting/export/pdf');
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
    }
}
