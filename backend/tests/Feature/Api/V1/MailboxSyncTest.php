<?php

namespace Tests\Feature\Api\V1;

use App\Models\MailMessage;
use App\Models\MailSyncRun;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'services.microsoft_graph.mock_mode' => true,
            'services.microsoft_graph.mailbox' => 'careers@nckenya.go.ke',
            'services.microsoft_graph.mock_message_count' => 55,
            'nck.auth_dev_login' => true,
        ]);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_can_queue_and_complete_historical_sync(): void
    {
        $admin = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/mailbox/sync');
        $response->assertOk()->assertJsonPath('success', true);

        $run = MailSyncRun::query()->findOrFail($response->json('data.id'));
        $run->refresh();

        $this->assertSame(MailSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(55, $run->messages_discovered);
        $this->assertSame(55, $run->messages_imported);
        $this->assertSame(0, $run->messages_skipped);
        $this->assertGreaterThanOrEqual(2, $run->pages_processed);
        $this->assertSame(55, MailMessage::query()->count());
    }

    public function test_duplicate_graph_ids_are_skipped_on_second_sync(): void
    {
        $admin = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/mailbox/sync')->assertOk();
        $this->assertSame(55, MailMessage::query()->count());

        // Second sync is incremental (delta). Existing rows remain unique.
        $this->postJson('/api/v1/mailbox/sync')->assertOk();

        $this->assertSame(55, MailMessage::query()->count());
        $latest = MailSyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(MailSyncRun::STATUS_COMPLETED, $latest->status);
        $this->assertSame(0, $latest->messages_imported);
    }

    public function test_sync_can_be_paused(): void
    {
        $admin = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/mailbox/sync/pause')
            ->assertOk()
            ->assertJsonPath('data.is_paused', true);

        $this->postJson('/api/v1/mailbox/sync')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_can_continue_from_failed_run_with_cursor(): void
    {
        $admin = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($admin);

        $run = \App\Models\MailSyncRun::query()->create([
            'mailbox' => 'careers@nckenya.go.ke',
            'sync_type' => 'initial',
            'status' => \App\Models\MailSyncRun::STATUS_FAILED,
            'trigger' => 'manual',
            'messages_discovered' => 100,
            'messages_imported' => 100,
            'pages_processed' => 4,
            'next_link' => 'https://graph.microsoft.com/v1.0/users/careers@nckenya.go.ke/mailFolders/inbox/messages/delta?$skiptoken=abc',
            'error_summary' => 'timeout',
        ]);

        $this->postJson('/api/v1/mailbox/sync/continue')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $run->id);

        $run->refresh();
        $this->assertContains($run->status, [
            \App\Models\MailSyncRun::STATUS_PENDING,
            \App\Models\MailSyncRun::STATUS_RUNNING,
            \App\Models\MailSyncRun::STATUS_COMPLETED,
        ]);
    }

    public function test_mailbox_logs_endpoint_returns_runs(): void
    {
        $admin = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/mailbox/sync')->assertOk();

        $this->getJson('/api/v1/mailbox/logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 1);
    }
}
