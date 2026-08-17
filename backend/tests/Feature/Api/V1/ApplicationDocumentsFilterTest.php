<?php

namespace Tests\Feature\Api\V1;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationDocumentsFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_applications_index_can_filter_by_attached_documents(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($user);

        $withDocs = $this->makeApplication('NCK-2026-100001');
        $withoutDocs = $this->makeApplication('NCK-2026-100002');

        ApplicationDocument::query()->create([
            'application_id' => $withDocs->id,
            'original_name' => 'cv.pdf',
            'disk' => 'private',
            'path' => 'applications/cv.pdf',
            'document_type' => 'attachment',
        ]);

        $this->getJson('/api/v1/applications?documents=with')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.application_reference', 'NCK-2026-100001')
            ->assertJsonPath('data.data.0.documents_count', 1);

        $this->getJson('/api/v1/applications?documents=without')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.application_reference', 'NCK-2026-100002')
            ->assertJsonPath('data.data.0.documents_count', 0);

        $this->assertTrue(Application::query()->whereKey($withoutDocs->id)->exists());
    }

    private function makeApplication(string $reference): Application
    {
        $applicant = Applicant::query()->create([
            'full_name' => 'Filter Test '.$reference,
            'email' => strtolower($reference).'@example.com',
        ]);

        return Application::query()->create([
            'application_reference' => $reference,
            'applicant_id' => $applicant->id,
            'subject' => 'Application',
            'status' => Application::STATUS_RECEIVED,
            'screening_status' => 'pending',
            'source' => 'email',
            'received_at' => now(),
        ]);
    }
}
