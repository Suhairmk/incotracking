<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Premium Rice 25kg', 'Sunflower Oil 1L', 'Detergent Powder 1kg',
                'Dish Wash Bar', 'Toilet Soap 100g', 'Shampoo Sachet Pack',
                'Tea Powder 500g', 'Toothpaste 150g',
            ]),
            'unit' => fake()->randomElement(['pcs', 'box', 'kg', 'ltr', 'pack']),
            'price_paise' => fake()->numberBetween(500, 250000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
