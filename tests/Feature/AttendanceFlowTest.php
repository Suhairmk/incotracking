<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    public function test_start_end_flow_is_idempotent_and_calculates_duration(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $startClientId = (string) Str::ulid();
        $startDeviceTime = now('UTC')->subMinutes(90);
        $payload = [
            'start_client_id' => $startClientId,
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 9,
            'device_recorded_at' => $startDeviceTime->toIso8601String(),
            'device_id' => 'android-test-device',
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
        ];

        $response = $this->post('/api/v1/employee/attendance/start', $payload, [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.start_client_id', $startClientId)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('meta.created', true);

        $attendanceId = $response->json('data.id');
        $this->assertDatabaseHas('attendance', [
            'id' => $attendanceId,
            'employee_id' => $employee->id,
            'start_client_id' => $startClientId,
        ]);

        // Same idempotency key returns the original session.
        $this->post('/api/v1/employee/attendance/start', [
            ...$payload,
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $attendanceId)
            ->assertJsonPath('meta.created', false);

        $this->assertDatabaseCount('attendance', 1);

        // A second session on the same work date is rejected. The device time
        // matches the first start so both land on the same business day even
        // when the suite runs close to midnight in the business timezone.
        $this->post('/api/v1/employee/attendance/start', [
            'start_client_id' => (string) Str::ulid(),
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 9,
            'device_recorded_at' => $startDeviceTime->toIso8601String(),
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('selfie2.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $endClientId = (string) Str::ulid();
        $endResponse = $this->post('/api/v1/employee/attendance/end', [
            'start_client_id' => $startClientId,
            'end_client_id' => $endClientId,
            'latitude' => 12.9720000,
            'longitude' => 77.5950000,
            'accuracy_meters' => 7,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'device_id' => 'android-test-device',
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('end.jpg', 900, 1200),
        ], ['Accept' => 'application/json']);

        $endResponse->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.end_client_id', $endClientId);

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame(AttendanceStatus::Completed, $attendance->status);
        $this->assertGreaterThanOrEqual(5300, $attendance->total_working_seconds);
        $this->assertLessThanOrEqual(5500, $attendance->total_working_seconds);
        $this->assertNotNull($attendance->start_photo_path);
        $this->assertNotNull($attendance->end_photo_path);
        Storage::disk(config('filesystems.default'))->assertExists($attendance->start_photo_path);
        Storage::disk(config('filesystems.default'))->assertExists($attendance->end_photo_path);

        // Re-sending the same closing event is safe.
        $this->post('/api/v1/employee/attendance/end', [
            'start_client_id' => $startClientId,
            'end_client_id' => $endClientId,
            'latitude' => 12.9720000,
            'longitude' => 77.5950000,
            'accuracy_meters' => 7,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('end.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        // A different second closing event is a conflict.
        $this->post('/api/v1/employee/attendance/end', [
            'start_client_id' => $startClientId,
            'end_client_id' => (string) Str::ulid(),
            'latitude' => 12.9720000,
            'longitude' => 77.5950000,
            'accuracy_meters' => 7,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('end2.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_employee_cannot_end_another_employees_session(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $attendance = Attendance::factory()->create([
            'employee_id' => $owner->id,
            'start_client_id' => (string) Str::ulid(),
        ]);

        Sanctum::actingAs($other);

        $this->post('/api/v1/employee/attendance/end', [
            'start_client_id' => $attendance->start_client_id,
            'end_client_id' => (string) Str::ulid(),
            'latitude' => 12.9720000,
            'longitude' => 77.5950000,
            'accuracy_meters' => 7,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('end.jpg'),
        ], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_offline_start_is_accepted_within_the_sync_window_and_files_the_device_work_date(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        // Captured three days ago while offline, inside the
        // tracking.history_max_days window.
        $capturedAt = now('UTC')->subDays(3)->setTime(10, 15);

        $response = $this->post('/api/v1/employee/attendance/start', [
            'start_client_id' => (string) Str::ulid(),
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 9,
            'device_recorded_at' => $capturedAt->toIso8601String(),
            'device_id' => 'android-offline-device',
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('offline.jpg', 900, 1200),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('meta.created', true);

        // The work date follows the captured day in the business timezone,
        // not the day the synchronization reached the server.
        $this->assertDatabaseHas('attendance', [
            'employee_id' => $employee->id,
            'work_date' => $capturedAt->copy()->setTimezone(BusinessTime::timezone())->toDateString(),
        ]);
        $this->assertNotSame(
            now('UTC')->setTimezone(BusinessTime::timezone())->toDateString(),
            Attendance::where('employee_id', $employee->id)->sole()->work_date,
        );

        // Server start time stays authoritative.
        $attendance = Attendance::where('employee_id', $employee->id)->sole();
        $this->assertEqualsWithDelta(now('UTC')->timestamp, $attendance->start_time->timestamp, 5);
    }

    public function test_pause_and_resume_track_break_time_and_are_idempotent(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $startClientId = (string) Str::ulid();
        $startDeviceTime = now('UTC')->subMinutes(90);

        $this->post('/api/v1/employee/attendance/start', [
            'start_client_id' => $startClientId,
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 9,
            'device_recorded_at' => $startDeviceTime->toIso8601String(),
            'device_id' => 'android-test-device',
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
        ], ['Accept' => 'application/json'])->assertCreated();

        // First break: 30 minutes of the 90-minute day.
        $pauseClientId = (string) Str::ulid();
        $pauseResponse = $this->post('/api/v1/employee/attendance/pause', [
            'start_client_id' => $startClientId,
            'pause_client_id' => $pauseClientId,
            'device_recorded_at' => now('UTC')->subMinutes(60)->toIso8601String(),
        ], ['Accept' => 'application/json']);

        $pauseResponse->assertOk()
            ->assertJsonPath('data.is_paused', true)
            ->assertJsonPath('data.status', 'active');
        $this->assertNotNull($pauseResponse->json('data.paused_at'));

        // Replaying the same pause event is safe and does not restart the break.
        $this->post('/api/v1/employee/attendance/pause', [
            'start_client_id' => $startClientId,
            'pause_client_id' => $pauseClientId,
            'device_recorded_at' => now('UTC')->subMinutes(60)->toIso8601String(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.is_paused', true)
            ->assertJsonPath('data.paused_at', $pauseResponse->json('data.paused_at'));

        // Resume 30 minutes after the pause.
        $resumeResponse = $this->post('/api/v1/employee/attendance/resume', [
            'start_client_id' => $startClientId,
            'resume_client_id' => (string) Str::ulid(),
            'device_recorded_at' => now('UTC')->subMinutes(30)->toIso8601String(),
        ], ['Accept' => 'application/json']);

        $resumeResponse->assertOk()->assertJsonPath('data.is_paused', false);
        $this->assertEqualsWithDelta(1800, $resumeResponse->json('data.total_paused_seconds'), 5);

        // Replaying the same resume event must not double-count the break.
        $this->post('/api/v1/employee/attendance/resume', [
            'start_client_id' => $startClientId,
            'resume_client_id' => (string) Str::ulid(),
            'device_recorded_at' => now('UTC')->subMinutes(25)->toIso8601String(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.is_paused', false);
        $this->assertEqualsWithDelta(
            1800,
            Attendance::where('employee_id', $employee->id)->sole()->total_paused_seconds,
            5
        );

        // A second, shorter break of 10 minutes.
        $this->post('/api/v1/employee/attendance/pause', [
            'start_client_id' => $startClientId,
            'pause_client_id' => (string) Str::ulid(),
            'device_recorded_at' => now('UTC')->subMinutes(20)->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/employee/attendance/resume', [
            'start_client_id' => $startClientId,
            'resume_client_id' => (string) Str::ulid(),
            'device_recorded_at' => now('UTC')->subMinutes(10)->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertOk();

        $attendanceId = $pauseResponse->json('data.id');
        $this->assertEqualsWithDelta(
            2400,
            Attendance::findOrFail($attendanceId)->total_paused_seconds,
            10
        );

        // Closing the day: 90 minutes elapsed minus 40 minutes of breaks
        // leaves 50 minutes of worked time.
        $endResponse = $this->post('/api/v1/employee/attendance/end', [
            'start_client_id' => $startClientId,
            'end_client_id' => (string) Str::ulid(),
            'latitude' => 12.9720000,
            'longitude' => 77.5950000,
            'accuracy_meters' => 7,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('end.jpg', 900, 1200),
        ], ['Accept' => 'application/json']);

        $endResponse->assertOk()->assertJsonPath('data.is_paused', false);
        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame(AttendanceStatus::Completed, $attendance->status);
        $this->assertGreaterThanOrEqual(2950, $attendance->total_working_seconds);
        $this->assertLessThanOrEqual(3100, $attendance->total_working_seconds);
        $this->assertEqualsWithDelta(2400, $attendance->total_paused_seconds, 10);
    }

    public function test_ending_while_paused_subtracts_the_open_break(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $startClientId = (string) Str::ulid();
        $attendanceId = $this->post('/api/v1/employee/attendance/start', [
            'start_client_id' => $startClientId,
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 9,
            'device_recorded_at' => now('UTC')->subMinutes(120)->toIso8601String(),
            'device_id' => 'android-test-device',
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        // The employee pauses and forgets to resume before closing the day.
        $this->post('/api/v1/employee/attendance/pause', [
            'start_client_id' => $startClientId,
            'pause_client_id' => (string) Str::ulid(),
            'device_recorded_at' => now('UTC')->subMinutes(40)->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/v1/employee/attendance/end', [
            'start_client_id' => $startClientId,
            'end_client_id' => (string) Str::ulid(),
            'latitude' => 12.9720000,
            'longitude' => 77.5950000,
            'accuracy_meters' => 7,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'meter_photo' => UploadedFile::fake()->image('meter.jpg', 1200, 900),
            'photo' => UploadedFile::fake()->image('end.jpg', 900, 1200),
        ], ['Accept' => 'application/json'])->assertOk();

        // 120 minutes elapsed minus the 40-minute open break = 80 minutes.
        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertGreaterThanOrEqual(4700, $attendance->total_working_seconds);
        $this->assertLessThanOrEqual(4900, $attendance->total_working_seconds);
        $this->assertEqualsWithDelta(2400, $attendance->total_paused_seconds, 10);
    }

    public function test_start_and_end_require_a_vehicle_meter_photo_and_expose_it(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $startClientId = (string) Str::ulid();
        $startPayload = [
            'start_client_id' => $startClientId,
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 9,
            'device_recorded_at' => now('UTC')->subMinutes(30)->toIso8601String(),
            'device_id' => 'android-test-device',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
        ];

        // The odometer reading is mandatory, not an optional extra.
        $this->post('/api/v1/employee/attendance/start', $startPayload, ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meter_photo');

        $response = $this->post('/api/v1/employee/attendance/start', [
            ...$startPayload,
            'meter_photo' => UploadedFile::fake()->image('odometer_start.jpg', 1200, 900),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $attendanceId = $response->json('data.id');
        $this->assertStringEndsWith(
            '/start_meter',
            (string) $response->json('data.start.meter_photo_url')
        );

        $endPayload = [
            'start_client_id' => $startClientId,
            'end_client_id' => (string) Str::ulid(),
            'latitude' => 12.9720000,
            'longitude' => 77.5950000,
            'accuracy_meters' => 7,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'photo' => UploadedFile::fake()->image('end.jpg', 900, 1200),
        ];

        $this->post('/api/v1/employee/attendance/end', $endPayload, ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meter_photo');

        $endResponse = $this->post('/api/v1/employee/attendance/end', [
            ...$endPayload,
            'meter_photo' => UploadedFile::fake()->image('odometer_end.jpg', 1200, 900),
        ], ['Accept' => 'application/json']);

        $endResponse->assertOk();
        $this->assertStringEndsWith(
            '/end_meter',
            (string) $endResponse->json('data.end.meter_photo_url')
        );

        $attendance = Attendance::findOrFail($attendanceId);
        $disk = Storage::disk(config('filesystems.default'));
        $disk->assertExists((string) $attendance->start_meter_photo_path);
        $disk->assertExists((string) $attendance->start_meter_photo_thumb_path);
        $disk->assertExists((string) $attendance->end_meter_photo_path);
        $disk->assertExists((string) $attendance->end_meter_photo_thumb_path);
        $this->assertStringContainsString('_meter', (string) $attendance->start_meter_photo_path);
    }

    public function test_employee_cannot_pause_or_resume_another_employees_session(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $attendance = Attendance::factory()->create([
            'employee_id' => $owner->id,
            'start_client_id' => (string) Str::ulid(),
        ]);

        Sanctum::actingAs($other);

        $this->post('/api/v1/employee/attendance/pause', [
            'start_client_id' => $attendance->start_client_id,
            'pause_client_id' => (string) Str::ulid(),
            'device_recorded_at' => now('UTC')->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->post('/api/v1/employee/attendance/resume', [
            'start_client_id' => $attendance->start_client_id,
            'resume_client_id' => (string) Str::ulid(),
            'device_recorded_at' => now('UTC')->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertNotFound();
    }
}
