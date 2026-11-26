<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tax;
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
            'business_date' => now()->toDateString(),
            'total_paise' => 0,
            'subtotal_paise' => 0,
            'tax_paise' => 0,
            'tax_rate_bp' => 0,
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

    /**
     * An order placed after the GST rollout: quoted prices are the taxable
     * value, 18% is added on top and the totals are kept consistent.
     *
     * @param  array<int, array{quantity: int, unit_price_paise: int}>  $lines
     */
    public function taxed(array $lines): static
    {
        return $this->state(function () use ($lines) {
            $subtotal = 0;
            $tax = 0;

            foreach ($lines as $line) {
                $lineTotal = $line['quantity'] * $line['unit_price_paise'];
                $lineTax = Tax::lineTax($lineTotal);
                $subtotal += $lineTotal;
                $tax += $lineTax;
            }

            return [
                'subtotal_paise' => $subtotal,
                'tax_paise' => $tax,
                'tax_rate_bp' => Tax::ORDER_RATE_BP,
                'total_paise' => $subtotal + $tax,
            ];
        });
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
