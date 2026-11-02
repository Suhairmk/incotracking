<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeLiveLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackingBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    public function test_batch_is_idempotent_filters_bad_points_and_updates_distance(): void
    {
        $employee = User::factory()->create();
        $attendance = Attendance::factory()->create([
            'employee_id' => $employee->id,
            'start_client_id' => (string) Str::ulid(),
            'start_time' => now('UTC')->subHours(4),
            'start_device_recorded_at' => now('UTC')->subHours(4),
        ]);

        Sanctum::actingAs($employee);

        $base = now('UTC')->subMinutes(10);
        $points = [
            $this->point(12.9710000, 77.5940000, $base, 8),
            $this->point(12.9720000, 77.5940000, $base->copy()->addMinute(), 8),
            $this->point(12.9730000, 77.5940000, $base->copy()->addMinutes(2), 8),
        ];

        $payload = [
            'start_client_id' => $attendance->start_client_id,
            'batch_id' => (string) Str::ulid(),
            'tracking_state' => 'moving',
            'heartbeat' => true,
            'points' => $points,
        ];

        $response = $this->postJson('/api/v1/employee/tracking/batch', $payload)
            ->assertOk()
            ->assertJsonPath('data.accepted_count', 3)
            ->assertJsonPath('data.duplicate_count', 0);

        $attendance->refresh();
        $this->assertCount(3, $response->json('data.acknowledged_ids'));
        $this->assertSame(3, $attendance->accepted_points_count);
        $this->assertSame(0, $attendance->rejected_points_count);
        $this->assertGreaterThan(190, $attendance->total_distance_meters);
        $this->assertSame('moving', $attendance->tracking_state->value);
        $this->assertNotNull($attendance->last_heartbeat_at);

        $live = EmployeeLiveLocation::find($employee->id);
        $this->assertNotNull($live);
        $this->assertSame(8, $live->accuracy_meters);

        $distanceAfterFirstBatch = $attendance->total_distance_meters;

        // Re-uploading the same batch must not duplicate distance or points.
        $this->postJson('/api/v1/employee/tracking/batch', $payload)
            ->assertOk()
            ->assertJsonPath('data.accepted_count', 0)
            ->assertJsonPath('data.duplicate_count', 3);

        $attendance->refresh();
        $this->assertSame(3, $attendance->accepted_points_count);
        $this->assertSame($distanceAfterFirstBatch, $attendance->total_distance_meters);

        // Poor-accuracy samples are persisted as rejected but never add distance.
        $bad = $this->point(12.9740000, 77.5940000, $base->copy()->addMinutes(3), 120);
        $this->postJson('/api/v1/employee/tracking/batch', [
            'start_client_id' => $attendance->start_client_id,
            'tracking_state' => 'moving',
            'points' => [$bad],
        ])->assertOk()
            ->assertJsonPath('data.rejected.0.reason', 'poor_accuracy');

        $attendance->refresh();
        $this->assertSame(3, $attendance->accepted_points_count);
        $this->assertSame(1, $attendance->rejected_points_count);
        $this->assertSame($distanceAfterFirstBatch, $attendance->total_distance_meters);

        $this->assertDatabaseHas('location_points', [
            'client_point_id' => $bad['client_point_id'],
            'quality_status' => 'rejected',
            'rejection_reason' => 'poor_accuracy',
        ]);
    }

    public function test_employee_can_only_upload_to_own_attendance(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $attendance = Attendance::factory()->create(['employee_id' => $owner->id]);

        Sanctum::actingAs($other);

        $this->postJson('/api/v1/employee/tracking/batch', [
            'start_client_id' => $attendance->start_client_id,
            'tracking_state' => 'stationary',
            'points' => [],
        ])->assertNotFound();
    }

    private function point(float $lat, float $lng, $recordedAt, int $accuracy): array
    {
        return [
            'client_point_id' => (string) Str::ulid(),
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_meters' => $accuracy,
            'speed_kmh' => 5,
            'heading_degrees' => 90,
            'recorded_at' => $recordedAt->toIso8601String(),
        ];
    }
}
