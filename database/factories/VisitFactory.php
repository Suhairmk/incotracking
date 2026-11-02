<?php

namespace Database\Factories;

use App\Enums\VisitOutcome;
use App\Models\Attendance;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => fn (array $attributes) => Attendance::find($attributes['attendance_id'])?->employee_id,
            'attendance_id' => Attendance::factory(),
            'client_visit_id' => (string) Str::ulid(),
            'customer_name' => fake()->company(),
            'contact_person' => fake()->name(),
            'contact_number' => fake()->numerify('9##########'),
            'photo_path' => 'tests/visits/photo.jpg',
            'photo_thumb_path' => 'tests/visits/photo_thumb.jpg',
            'latitude_e7' => 127971234,
            'longitude_e7' => 776412345,
            'accuracy_meters' => 10,
            'device_recorded_at' => now('UTC'),
            'server_recorded_at' => now('UTC'),
            'remarks' => fake()->sentence(),
            'outcome' => VisitOutcome::Successful,
        ];
    }
}
