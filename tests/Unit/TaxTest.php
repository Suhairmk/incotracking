<?php

namespace Tests\Unit;

use App\Support\Tax;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxTest extends TestCase
{
    public function test_the_order_rate_is_eighteen_percent(): void
    {
        $this->assertSame(1800, Tax::ORDER_RATE_BP);
        $this->assertSame(18.0, Tax::percent());
    }

    #[DataProvider('roundingCases')]
    public function test_line_tax_is_rounded_half_up_to_the_nearest_paise(int $taxable, int $expected): void
    {
        $this->assertSame($expected, Tax::lineTax($taxable));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function roundingCases(): array
    {
        return [
            'exact paise' => [20000, 3600],
            'rounds down below half' => [102, 18],
            'rounds up at half' => [25, 5],
            'rounds up above half' => [75, 14],
            'zero' => [0, 0],
            'negative guard' => [-100, 0],
        ];
    }

    public function test_a_custom_rate_is_honoured(): void
    {
        $this->assertSame(500, Tax::lineTax(10000, 500));
        $this->assertSame(0, Tax::lineTax(10000, 0));
    }

    public function test_the_total_is_the_sum_of_per_line_taxes(): void
    {
        $lines = [20000, 7500];
        $tax = array_sum(array_map(fn (int $line): int => Tax::lineTax($line), $lines));

        $this->assertSame(4950, $tax);
    }
}
