<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Enums\TrackingState;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        $start = now('UTC')->subHours(4);

        return [
            'employee_id' => User::factory(),
            'work_date' => $start->copy()->setTimezone(config('app.business_timezone'))->toDateString(),
            'start_time' => $start,
            'start_device_recorded_at' => $start,
            'start_device_id' => 'test-device',
            'start_client_id' => (string) Str::ulid(),
            'start_latitude_e7' => 127971234,
            'start_longitude_e7' => 776412345,
            'start_accuracy' => 8,
            'start_photo_path' => 'tests/attendance/start.jpg',
            'start_photo_thumb_path' => 'tests/attendance/start_thumb.jpg',
            'start_meter_photo_path' => 'tests/attendance/start_meter.jpg',
            'start_meter_photo_thumb_path' => 'tests/attendance/start_meter_thumb.jpg',
            'total_distance_meters' => 0,
            'total_working_seconds' => 0,
            'accepted_points_count' => 0,
            'rejected_points_count' => 0,
            'visits_count' => 0,
            'tracking_state' => TrackingState::Unknown,
            'status' => AttendanceStatus::Active,
        ];
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $end = now('UTC');
            $start = $end->copy()->subHours(4);

            return [
                'start_time' => $start,
                'start_device_recorded_at' => $start,
                'end_time' => $end,
                'end_device_recorded_at' => $end,
                'end_device_id' => 'test-device',
                'end_client_id' => (string) Str::ulid(),
                'end_latitude_e7' => 127981234,
                'end_longitude_e7' => 776422345,
                'end_accuracy' => 8,
                'end_photo_path' => 'tests/attendance/end.jpg',
                'end_photo_thumb_path' => 'tests/attendance/end_thumb.jpg',
                'end_meter_photo_path' => 'tests/attendance/end_meter.jpg',
                'end_meter_photo_thumb_path' => 'tests/attendance/end_meter_thumb.jpg',
                'total_working_seconds' => 4 * 3600,
                'status' => AttendanceStatus::Completed,
                'tracking_state' => TrackingState::Completed,
            ];
        });
    }
}
