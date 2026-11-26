<?php

namespace Database\Factories;

use App\Models\MonthlySalesTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlySalesTarget>
 */
class MonthlySalesTargetFactory extends Factory
{
    protected $model = MonthlySalesTarget::class;

    public function definition(): array
    {
        return [
            'employee_id' => User::factory(),
            'month' => now()->startOfMonth()->toDateString(),
            'target_paise' => fake()->numberBetween(50, 500) * 100_000,
            'updated_by' => null,
        ];
    }
}
