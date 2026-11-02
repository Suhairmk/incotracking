<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class BusinessTime
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Kolkata');
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    /**
     * UTC boundaries for a business-timezone calendar date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function utcBounds(string $date): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $date, self::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    public static function normalizeDate(?string $date): string
    {
        if (! $date) {
            return self::today();
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $date, self::timezone())->toDateString();
    }
}
