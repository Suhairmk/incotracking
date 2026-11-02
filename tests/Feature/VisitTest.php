<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    public function test_visit_is_saved_once_with_photo_and_updates_summary(): void
    {
        $employee = User::factory()->create();
        $attendance = Attendance::factory()->create([
            'employee_id' => $employee->id,
            'start_client_id' => (string) Str::ulid(),
        ]);
        $clientVisitId = (string) Str::ulid();

        Sanctum::actingAs($employee);

        $payload = [
            'start_client_id' => $attendance->start_client_id,
            'client_visit_id' => $clientVisitId,
            'customer_name' => 'Patel Hardware',
            'contact_person' => 'Rakesh Patel',
            'contact_number' => '9876543210',
            'remarks' => 'Interested in the annual service package.',
            'outcome' => 'interested',
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 11,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'photo' => UploadedFile::fake()->image('shop.jpg', 1200, 900),
        ];

        $this->post('/api/v1/employee/visits', $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.customer_name', 'Patel Hardware')
            ->assertJsonPath('data.outcome', 'interested')
            ->assertJsonPath('meta.created', true);

        $attendance->refresh();
        $this->assertSame(1, $attendance->visits_count);
        $this->assertDatabaseCount('visits', 1);

        $visit = Visit::firstOrFail();
        $this->assertNotNull($visit->photo_path);
        $this->assertNotNull($visit->photo_thumb_path);
        Storage::disk(config('filesystems.default'))->assertExists($visit->photo_path);
        Storage::disk(config('filesystems.default'))->assertExists($visit->photo_thumb_path);

        $this->post('/api/v1/employee/visits', [
            ...$payload,
            'photo' => UploadedFile::fake()->image('shop.jpg', 1200, 900),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('meta.created', false);

        $this->assertSame(1, Visit::count());
        $attendance->refresh();
        $this->assertSame(1, $attendance->visits_count);

        $this->getJson('/api/v1/employee/visits')->assertOk()
            ->assertJsonPath('data.0.id', $visit->id);
    }

    public function test_visit_cannot_be_added_after_work_ends(): void
    {
        $employee = User::factory()->create();
        $attendance = Attendance::factory()->create([
            'employee_id' => $employee->id,
            'status' => AttendanceStatus::Completed,
            'end_time' => now('UTC'),
            'end_client_id' => (string) Str::ulid(),
            'end_photo_path' => 'attendance/end.jpg',
            'end_latitude_e7' => 127971234,
            'end_longitude_e7' => 776412345,
            'end_accuracy' => 9,
        ]);

        Sanctum::actingAs($employee);

        $this->post('/api/v1/employee/visits', [
            'start_client_id' => $attendance->start_client_id,
            'client_visit_id' => (string) Str::ulid(),
            'customer_name' => 'Late Shop',
            'outcome' => 'successful',
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'accuracy_meters' => 10,
            'device_recorded_at' => now('UTC')->toIso8601String(),
            'photo' => UploadedFile::fake()->image('shop.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_employee_cannot_view_another_employees_visit(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $attendance = Attendance::factory()->create(['employee_id' => $owner->id]);
        $visit = Visit::create([
            'employee_id' => $owner->id,
            'attendance_id' => $attendance->id,
            'client_visit_id' => (string) Str::ulid(),
            'customer_name' => 'Private Customer',
            'photo_path' => 'visits/photo.jpg',
            'latitude_e7' => 127971234,
            'longitude_e7' => 776412345,
            'accuracy_meters' => 10,
            'device_recorded_at' => now('UTC'),
            'server_recorded_at' => now('UTC'),
            'outcome' => 'successful',
        ]);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/employee/visits/{$visit->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/v1/admin/visits/{$visit->id}")->assertOk();
    }
}
