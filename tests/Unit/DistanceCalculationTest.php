<?php

namespace Tests\Unit;

use App\Services\DistanceCalculationService;
use App\Support\Geo;
use Tests\TestCase;

class DistanceCalculationTest extends TestCase
{
    public function test_haversine_distance_is_accurate_for_one_degree_of_latitude(): void
    {
        $meters = Geo::distanceMeters(0, 0, 10_000_000, 0);

        $this->assertEqualsWithDelta(111_194.9, $meters, 1.0);
    }

    public function test_segment_filters_remove_gaps_tiny_noise_and_impossible_speed(): void
    {
        $service = new DistanceCalculationService;

        $this->assertTrue($service->isSegmentCountable(60, 100));
        $this->assertFalse($service->isSegmentCountable(60, 4), 'Segments below the minimum distance must not count.');
        $this->assertFalse($service->isSegmentCountable(11 * 60, 500), 'A tracking gap must not count.');
        $this->assertFalse($service->isSegmentCountable(1, 1000), 'Impossible speed must be excluded.');
        $this->assertFalse($service->isSegmentCountable(0, 100));
    }

    public function test_speed_conversion(): void
    {
        $this->assertEqualsWithDelta(36.0, Geo::speedKmh(100, 10), 0.001);
        $this->assertNull(Geo::speedKmh(100, 0));
    }
}
