<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->numberBetween(500, 250000);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->randomElement([
                'Premium Rice 25kg', 'Sunflower Oil 1L', 'Detergent Powder 1kg',
            ]),
            'unit' => fake()->randomElement(['pcs', 'box', 'kg']),
            'unit_price_paise' => $unitPrice,
            'quantity' => $quantity,
            'line_total_paise' => $quantity * $unitPrice,
            // Zero unless the test asks for a taxed line explicitly, which
            // mirrors orders placed before the GST rollout.
            'line_tax_paise' => 0,
            'tax_rate_bp' => 0,
        ];
    }
}
