<?php

namespace Database\Factories;

use App\Enums\LocationQuality;
use App\Models\Attendance;
use App\Models\LocationPoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LocationPoint>
 */
class LocationPointFactory extends Factory
{
    public function definition(): array
    {
        $recordedAt = now('UTC');

        return [
            'employee_id' => fn (array $attributes) => Attendance::find($attributes['attendance_id'])?->employee_id,
            'attendance_id' => Attendance::factory(),
            'client_point_id' => (string) Str::ulid(),
            'latitude_e7' => 127971234,
            'longitude_e7' => 776412345,
            'accuracy_meters' => 10,
            'speed_cm_per_second' => 0,
            'heading_degrees' => null,
            'recorded_at' => $recordedAt,
            'received_at' => $recordedAt,
            'quality_status' => LocationQuality::Accepted,
            'rejection_reason' => null,
        ];
    }
}
