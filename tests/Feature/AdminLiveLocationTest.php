<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\TrackingState;
use App\Models\Attendance;
use App\Models\EmployeeLiveLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The live map lists every employee currently on duty, together with the
 * position the field last reported. An active session without a stored
 * heartbeat still appears, falling back to the spot where the shift began.
 */
class AdminLiveLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->employee = User::factory()->create();
    }

    public function test_an_active_shift_without_a_heartbeat_reports_its_start_position(): void
    {
        $attendance = Attendance::factory()->create([
            'employee_id' => $this->employee->id,
            'status' => AttendanceStatus::Active,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/live-locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $this->employee->id)
            ->assertJsonPath('data.0.attendance_id', $attendance->id)
            ->assertJsonPath('data.0.health', 'starting')
            ->assertJsonPath('meta.count', 1);
    }

    public function test_a_stored_heartbeat_wins_over_the_start_position(): void
    {
        $attendance = Attendance::factory()->create([
            'employee_id' => $this->employee->id,
            'status' => AttendanceStatus::Active,
        ]);

        EmployeeLiveLocation::create([
            'employee_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'latitude_e7' => 130826800,
            'longitude_e7' => 802707000,
            'accuracy_meters' => 12,
            'last_recorded_at' => now('UTC'),
            'last_received_at' => now('UTC'),
            'last_heartbeat_at' => now('UTC'),
            'tracking_state' => TrackingState::Moving,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/live-locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.latitude', 13.08268)
            ->assertJsonPath('data.0.longitude', 80.2707)
            ->assertJsonPath('data.0.health', 'active');
    }

    public function test_completed_shifts_are_not_listed_and_staff_are_refused(): void
    {
        Attendance::factory()->completed()->create([
            'employee_id' => $this->employee->id,
        ]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/live-locations')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->employee);
        $this->getJson('/api/v1/admin/live-locations')->assertForbidden();
    }
}
