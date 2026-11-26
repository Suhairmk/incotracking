<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\ShopCreditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopCreditEntry>
 */
class ShopCreditEntryFactory extends Factory
{
    protected $model = ShopCreditEntry::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement([
            ShopCreditEntry::TYPE_OPENING,
            ShopCreditEntry::TYPE_RECEIPT,
            ShopCreditEntry::TYPE_PAYMENT,
            ShopCreditEntry::TYPE_ADJUSTMENT,
        ]);

        return [
            'shop_id' => Shop::factory(),
            'type' => $type,
            'amount_paise' => $type === ShopCreditEntry::TYPE_OPENING
                ? $this->faker->numberBetween(1000, 500000)
                : -$this->faker->numberBetween(100, 200000),
            'entry_date' => now()->toDateString(),
            'reference' => strtoupper($this->faker->bothify('VCH-####')),
            'details' => $this->faker->sentence(5),
            'source' => ShopCreditEntry::SOURCE_MANUAL,
            'created_by' => User::factory(),
        ];
    }

    public function sale(int $amountPaise): static
    {
        return $this->state(fn (): array => [
            'type' => ShopCreditEntry::TYPE_SALE,
            'amount_paise' => $amountPaise,
            'source' => ShopCreditEntry::SOURCE_ORDER,
        ]);
    }
}
