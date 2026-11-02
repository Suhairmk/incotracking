<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_login_and_access_own_dashboard(): void
    {
        $employee = User::factory()->create([
            'password' => 'secret-pass-123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $employee->email,
            'password' => 'secret-pass-123',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $employee->id)
            ->assertJsonPath('data.user.role', 'employee')
            ->assertJsonStructure(['data' => ['token']]);

        $token = $response->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/employee/dashboard')
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.today.status', 'not_started');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    public function test_login_by_phone_works(): void
    {
        $employee = User::factory()->create([
            'phone' => '9876543210',
            'password' => 'secret-pass-123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => '9876543210',
            'password' => 'secret-pass-123',
        ])->assertOk()->assertJsonPath('data.user.id', $employee->id);
    }

    public function test_inactive_account_cannot_login(): void
    {
        $employee = User::factory()->inactive()->create([
            'password' => 'secret-pass-123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => $employee->email,
            'password' => 'secret-pass-123',
        ])->assertForbidden();
    }

    public function test_logout_revokes_current_token(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
