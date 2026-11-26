<?php

namespace Database\Factories;

use App\Enums\OdometerClaimStatus;
use App\Models\Attendance;
use App\Models\OdometerClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OdometerClaim>
 */
class OdometerClaimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => User::factory(),
            'attendance_id' => Attendance::factory(),
            'work_date' => now('UTC')->toDateString(),
            'odometer_meters' => 45_321_700,
            'previous_odometer_meters' => null,
            'claimed_distance_meters' => null,
            'gps_distance_meters' => 0,
            'status' => OdometerClaimStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OdometerClaimStatus::Approved,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now('UTC'),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OdometerClaimStatus::Rejected,
            'admin_notes' => 'Reading does not match the fuel log.',
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now('UTC'),
        ]);
    }
}
