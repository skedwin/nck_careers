<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.database', 'ok');
    }

    public function test_dev_login_is_disabled_by_default(): void
    {
        config(['nck.auth_dev_login' => false]);

        $this->postJson('/api/v1/auth/dev-login', [
            'email' => 'admin@nckenya.go.ke',
            'password' => 'ChangeMeNow!123',
        ])->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_dev_login_and_me_endpoints(): void
    {
        config(['nck.auth_dev_login' => true]);
        $this->seed(DatabaseSeeder::class);

        $login = $this->postJson('/api/v1/auth/dev-login', [
            'email' => 'admin@nckenya.go.ke',
            'password' => 'ChangeMeNow!123',
        ]);

        $login->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@nckenya.go.ke');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_dashboard_stub(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'admin@nckenya.go.ke')->firstOrFail();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_applications', 0);
    }
}
