<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft_graph.mock_mode' => true,
            'services.microsoft_graph.mailbox' => 'careers@nckenya.go.ke',
            'services.microsoft_graph.tenant_id' => 'test-tenant',
            'services.microsoft_graph.client_id' => 'test-client',
            'services.microsoft_graph.client_secret' => 'test-secret',
            'nck.auth_dev_login' => true,
        ]);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_mailbox_status_requires_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/mailbox/status')->assertForbidden();
    }

    public function test_admin_can_view_mailbox_status(): void
    {
        $admin = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/mailbox/status')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mailbox', 'careers@nckenya.go.ke')
            ->assertJsonPath('data.mock_mode', true)
            ->assertJsonPath('data.read_only', true);
    }

    public function test_admin_can_test_mailbox_connection_in_mock_mode(): void
    {
        $admin = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/mailbox/test-connection')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mode', 'mock')
            ->assertJsonPath('data.mailbox_user.mail', 'careers@nckenya.go.ke')
            ->assertJsonPath('data.sample_messages.pages_retrieved', 2)
            ->assertJsonPath('data.sample_messages.messages_retrieved', 2);

        $this->getJson('/api/v1/mailbox/status')
            ->assertOk()
            ->assertJsonPath('data.last_check.success', true);
    }

    public function test_pagination_fixtures_expose_next_link_on_first_page(): void
    {
        $page1 = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Graph/messages_page_1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertArrayHasKey('@odata.nextLink', $page1);
        $this->assertNotEmpty($page1['value']);
    }
}
