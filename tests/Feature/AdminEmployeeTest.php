<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_reports_live_today_status(): void
    {
        $admin = User::factory()->admin()->create();
        $working = User::factory()->create();
        Attendance::factory()->create([
            'employee_id' => $working->id,
            'start_client_id' => (string) Str::ulid(),
            'work_date' => now('UTC')->toDateString(),
            'status' => AttendanceStatus::Active,
            'last_heartbeat_at' => now('UTC'),
        ]);
        $notStarted = User::factory()->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/employees')->assertOk();

        $statuses = collect($response->json('data'))
            ->pluck('today_status', 'id')
            ->all();

        $this->assertSame('working', $statuses[$working->id]);
        $this->assertSame('not_started', $statuses[$notStarted->id]);
    }

    public function test_show_exposes_today_status_and_visit_count(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $attendance = Attendance::factory()->create([
            'employee_id' => $employee->id,
            'start_client_id' => (string) Str::ulid(),
            'work_date' => now('UTC')->toDateString(),
            'status' => AttendanceStatus::Completed,
            'end_time' => now('UTC'),
            'end_client_id' => (string) Str::ulid(),
        ]);
        Visit::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance->id,
            'client_visit_id' => (string) Str::ulid(),
            'customer_name' => 'Sunrise Traders',
            'outcome' => 'successful',
            'latitude_e7' => 129715987,
            'longitude_e7' => 775945627,
            'accuracy_meters' => 12,
            'device_recorded_at' => now('UTC'),
            'server_recorded_at' => now('UTC'),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.employee.today_status', 'completed')
            ->assertJsonPath('data.employee.attendance_count', 1)
            ->assertJsonPath('data.employee.visit_count', 1)
            ->assertJsonPath('data.today_attendance.id', $attendance->id);
    }

    public function test_show_marks_stale_working_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        Attendance::factory()->create([
            'employee_id' => $employee->id,
            'start_client_id' => (string) Str::ulid(),
            'work_date' => now('UTC')->toDateString(),
            'status' => AttendanceStatus::Active,
            'last_heartbeat_at' => now('UTC')->subHours(2),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.employee.today_status', 'stale');
    }

    public function test_employee_cannot_read_admin_employee_endpoints(): void
    {
        $employee = User::factory()->create();

        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/admin/employees')->assertForbidden();
        $this->getJson("/api/v1/admin/employees/{$employee->id}")->assertForbidden();
    }
}
