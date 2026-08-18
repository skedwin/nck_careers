<?php

namespace Tests\Feature\Api\V1;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportViewerDuplicateHideTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_viewer_can_call_hide_and_unhide_duplicate_endpoints(): void
    {
        $this->seed(DatabaseSeeder::class);
        $viewer = User::query()->create([
            'name' => 'Report Viewer',
            'display_name' => 'Report Viewer',
            'email' => 'viewer-duplicates@nckenya.go.ke',
            'password' => 'ChangeMeNow!123',
            'is_active' => true,
        ]);
        $viewer->syncRoles(['Report Viewer']);
        Sanctum::actingAs($viewer);

        $application = $this->makeApplication('NCK-2026-200001');

        $this->postJson("/api/v1/applications/{$application->id}/hide-duplicate")
            ->assertStatus(422);

        $application->forceFill([
            'duplicate_hidden_at' => now(),
            'duplicate_hidden_by' => $viewer->id,
        ])->save();

        $this->postJson("/api/v1/applications/{$application->id}/unhide-duplicate")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($application->fresh()->duplicate_hidden_at);
    }

    public function test_read_only_cannot_hide_or_unhide_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reader = User::query()->create([
            'name' => 'Read Only',
            'display_name' => 'Read Only',
            'email' => 'readonly-duplicates@nckenya.go.ke',
            'password' => 'ChangeMeNow!123',
            'is_active' => true,
        ]);
        $reader->syncRoles(['Read Only']);
        Sanctum::actingAs($reader);

        $application = $this->makeApplication('NCK-2026-200002');

        $this->postJson("/api/v1/applications/{$application->id}/hide-duplicate")
            ->assertForbidden();
        $this->postJson("/api/v1/applications/{$application->id}/unhide-duplicate")
            ->assertForbidden();
    }

    public function test_report_viewer_role_includes_profile_update(): void
    {
        $this->seed(DatabaseSeeder::class);
        $role = Role::findByName('Report Viewer');

        $this->assertTrue($role->hasPermissionTo('applications.profile.update'));
        $this->assertFalse($role->hasPermissionTo('applications.update'));
    }

    private function makeApplication(string $reference): Application
    {
        $applicant = Applicant::query()->create([
            'full_name' => 'Duplicate Tester',
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
