<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_number' => sprintf(
                'ORD-%s-%06d',
                now()->format('Ymd'),
                fake()->unique()->numberBetween(1, 999999),
            ),
            'client_order_id' => strtoupper(fake()->unique()->regexify('[A-Z0-9]{26}')),
            'shop_id' => Shop::factory(),
            'employee_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'total_paise' => 0,
            'notes' => fake()->optional()->sentence(),
            'device_created_at' => now()->subMinutes(10),
            'server_recorded_at' => now(),
        ];
    }

    public function delivered(?int $actorId = null): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Delivered,
            'delivered_at' => now(),
            'delivered_by' => $actorId,
        ]);
    }

    public function cancelled(?int $actorId = null): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actorId,
        ]);
    }
}
