<?php

namespace Tests\Feature\Api\V1;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\MailMessage;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_process_stores_extraction_without_changing_application_status(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($user);

        $application = $this->makeApplication();

        $this->postJson("/api/v1/applications/{$application->id}/ai/process", ['force' => true])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ai_extraction.status', 'completed')
            ->assertJsonPath('data.ai_extraction.applicant.phone', '0722000000');

        $application->refresh();
        $this->assertSame(Application::STATUS_RECEIVED, $application->status);
        $this->assertSame('pending', $application->screening_status);
        $this->assertSame('0711111111', $application->applicant?->phone);
    }

    public function test_accept_fills_empty_fields_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($user);

        $application = $this->makeApplication();
        $this->postJson("/api/v1/applications/{$application->id}/ai/process", ['force' => true])->assertOk();

        $this->postJson("/api/v1/applications/{$application->id}/ai/review", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.ai_extraction.status', 'accepted');

        $application->applicant?->refresh();
        $this->assertSame('jane@example.com', $application->applicant?->email);
        $this->assertSame('0711111111', $application->applicant?->phone);
        $this->assertSame('NCK/12345', $application->applicant?->registration_number);
        $this->assertSame(Application::STATUS_RECEIVED, $application->fresh()->status);
    }

    public function test_reject_does_not_change_applicant_records(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($user);

        $application = $this->makeApplication();
        $this->postJson("/api/v1/applications/{$application->id}/ai/process", ['force' => true])->assertOk();

        $this->postJson("/api/v1/applications/{$application->id}/ai/review", ['action' => 'reject'])
            ->assertOk()
            ->assertJsonPath('data.ai_extraction.status', 'rejected');

        $application->applicant?->refresh();
        $this->assertNull($application->applicant?->registration_number);
        $this->assertSame('0711111111', $application->applicant?->phone);
    }

    public function test_auto_queue_is_skipped_when_ai_is_disabled(): void
    {
        $this->seed(DatabaseSeeder::class);
        SystemSetting::query()->where('key', 'ai_enabled')->update(['value' => 'false']);

        $application = $this->makeApplication();
        app(\App\Services\AI\ApplicationAiProcessor::class)->queue($application);

        $this->assertSame(0, $application->aiExtractions()->count());
    }

    private function makeApplication(): Application
    {
        $applicant = Applicant::query()->create([
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '0711111111',
        ]);

        $message = MailMessage::query()->create([
            'graph_message_id' => 'graph-ai-'.uniqid(),
            'mailbox' => 'careers@nckenya.go.ke',
            'sender_name' => 'Jane Doe',
            'sender_email' => 'jane@example.com',
            'subject' => 'Application for Registered Nurse',
            'body_text' => "Name: Jane Tester\nPhone: 0722000000\nRegistration number: NCK/12345\nEmail: other@example.com",
            'received_at' => now(),
        ]);

        return Application::query()->create([
            'application_reference' => 'NCK-2026-'.random_int(100000, 999999),
            'applicant_id' => $applicant->id,
            'mail_message_id' => $message->id,
            'subject' => $message->subject,
            'status' => Application::STATUS_RECEIVED,
            'screening_status' => 'pending',
            'source' => 'email',
            'received_at' => now(),
        ]);
    }
}
