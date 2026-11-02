<?php

namespace App\Support;

final class Geo
{
    public const EARTH_RADIUS_METERS = 6371000.0;

    public static function toE7(float $degrees): int
    {
        return (int) round($degrees * 10_000_000);
    }

    public static function fromE7(int $value): float
    {
        return $value / 10_000_000;
    }

    /**
     * Great-circle distance between two E7 coordinates.
     */
    public static function distanceMeters(
        int $lat1E7,
        int $lng1E7,
        int $lat2E7,
        int $lng2E7,
    ): float {
        $lat1 = deg2rad($lat1E7 / 10_000_000);
        $lat2 = deg2rad($lat2E7 / 10_000_000);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad(($lng2E7 - $lng1E7) / 10_000_000);

        $a = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1, sqrt($a)));
    }

    /**
     * Speed over a segment in km/h. Returns null for non-positive durations.
     */
    public static function speedKmh(float $meters, float $seconds): ?float
    {
        if ($seconds <= 0) {
            return null;
        }

        return ($meters / $seconds) * 3.6;
    }
}
