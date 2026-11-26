<?php

namespace App\Support;

/**
 * Flat GST applied to every newly placed order.
 *
 * Money travels as integer paise, so a line's tax is rounded half-up with
 * integer arithmetic. The mobile app mirrors this exact formula for the
 * breakdown it shows before the server confirms the order; existing orders
 * (rate 0) are never recalculated.
 */
final class Tax
{
    /**
     * 18% expressed in basis points: 1800 bp = 18.00%.
     */
    public const ORDER_RATE_BP = 1800;

    /**
     * Tax for one taxable amount, rounded half-up to the nearest paise.
     */
    public static function lineTax(int $taxablePaise, int $rateBp = self::ORDER_RATE_BP): int
    {
        if ($taxablePaise <= 0 || $rateBp <= 0) {
            return 0;
        }

        return intdiv($taxablePaise * $rateBp + 5000, 10000);
    }

    /**
     * The same rate as a human percentage (18.0) for labels and PDFs.
     */
    public static function percent(int $rateBp = self::ORDER_RATE_BP): float
    {
        return round($rateBp / 100, 2);
    }
}
