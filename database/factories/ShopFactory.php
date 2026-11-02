<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        // Around Bengaluru, matching the seeded field data.
        $latitude = 12.9716 + fake()->randomFloat(6, -0.08, 0.08);
        $longitude = 77.5946 + fake()->randomFloat(6, -0.08, 0.08);

        return [
            'created_by' => User::factory(),
            'name' => fake()->company(),
            'type' => fake()->randomElement([Shop::TYPE_SHOP, Shop::TYPE_DEALER]),
            'location_name' => fake()->randomElement([
                'Indiranagar', 'Koramangala', 'HSR Layout', 'Whitefield', 'Jayanagar',
            ]),
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('9#########'),
            'notes' => fake()->optional()->sentence(),
            'latitude_e7' => (int) round($latitude * 10_000_000),
            'longitude_e7' => (int) round($longitude * 10_000_000),
            'accuracy_meters' => null,
        ];
    }

    public function dealer(): static
    {
        return $this->state(fn () => ['type' => Shop::TYPE_DEALER]);
    }

    public function near(float $latitude, float $longitude): static
    {
        return $this->state(fn () => [
            'latitude_e7' => (int) round($latitude * 10_000_000),
            'longitude_e7' => (int) round($longitude * 10_000_000),
        ]);
    }
}
