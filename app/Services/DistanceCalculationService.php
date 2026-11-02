<?php

namespace App\Services;

use App\Enums\LocationQuality;
use App\Models\Attendance;
use App\Models\LocationPoint;
use App\Support\Geo;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DistanceCalculationService
{
    /**
     * Process only points beyond the stored cursor. This is the normal path for
     * a batch upload and avoids re-scanning a whole day's route.
     */
    public function processIncremental(Attendance $attendance): void
    {
        $lock = Cache::lock("attendance:distance:{$attendance->id}", 60);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            $this->markForRecalculation($attendance);

            return;
        }

        try {
            $attendance->refresh();

            if ($attendance->distance_needs_recalc) {
                return;
            }

            $cursorAt = $attendance->last_processed_recorded_at;
            $cursorId = (int) ($attendance->last_processed_point_id ?? 0);
            $lastLat = $attendance->last_processed_latitude_e7;
            $lastLng = $attendance->last_processed_longitude_e7;
            $lastAt = $cursorAt;
            $distance = (int) $attendance->total_distance_meters;
            $lastLocationAt = $attendance->last_location_at;
            $processed = 0;

            LocationPoint::query()
                ->where('attendance_id', $attendance->id)
                ->where('quality_status', LocationQuality::Accepted->value)
                ->when($cursorAt !== null, function ($query) use ($cursorAt, $cursorId) {
                    $query->where(function ($q) use ($cursorAt, $cursorId) {
                        $q->where('recorded_at', '>', $cursorAt)
                            ->orWhere(function ($q2) use ($cursorAt, $cursorId) {
                                $q2->where('recorded_at', '=', $cursorAt)
                                    ->where('id', '>', $cursorId);
                            });
                    });
                })
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->chunk(500, function ($points) use (
                    &$distance,
                    &$lastLat,
                    &$lastLng,
                    &$lastAt,
                    &$lastLocationAt,
                    &$processed
                ) {
                    foreach ($points as $point) {
                        $processed++;

                        if ($lastLat !== null && $lastLng !== null && $lastAt !== null) {
                            $seconds = $lastAt->diffInSeconds($point->recorded_at, false);
                            $meters = Geo::distanceMeters(
                                (int) $lastLat,
                                (int) $lastLng,
                                $point->latitude_e7,
                                $point->longitude_e7,
                            );

                            if ($this->isSegmentCountable($seconds, $meters)) {
                                $distance += (int) round($meters);
                            }
                        }

                        $lastLat = $point->latitude_e7;
                        $lastLng = $point->longitude_e7;
                        $lastAt = $point->recorded_at;

                        if ($lastLocationAt === null || $point->recorded_at->gt($lastLocationAt)) {
                            $lastLocationAt = $point->recorded_at;
                        }
                    }
                });

            $counts = $this->counts($attendance->id);
            $lastPoint = LocationPoint::query()
                ->where('attendance_id', $attendance->id)
                ->where('quality_status', LocationQuality::Accepted->value)
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();

            $attendance->forceFill([
                'total_distance_meters' => $distance,
                'accepted_points_count' => $counts['accepted'],
                'rejected_points_count' => $counts['rejected'],
                'last_location_at' => $lastLocationAt,
                'last_processed_point_id' => $lastPoint?->id,
                'last_processed_recorded_at' => $lastPoint?->recorded_at,
                'last_processed_latitude_e7' => $lastPoint?->latitude_e7,
                'last_processed_longitude_e7' => $lastPoint?->longitude_e7,
                'last_processed_accuracy' => $lastPoint?->accuracy_meters,
            ])->save();
        } finally {
            $lock->release();
        }
    }

    /**
     * Authoritative full-day recalculation. Called at work closure and when a
     * late/out-of-order point invalidates the incremental cursor.
     */
    public function recalculate(Attendance $attendance): Attendance
    {
        $lock = Cache::lock("attendance:distance:{$attendance->id}", 120);
        $lock->block(30);

        try {
            $attendance->refresh();

            $distance = 0;
            $lastLat = null;
            $lastLng = null;
            $lastAt = null;
            $lastLocationAt = null;
            $lastPoint = null;

            LocationPoint::query()
                ->where('attendance_id', $attendance->id)
                ->where('quality_status', LocationQuality::Accepted->value)
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->chunk(1000, function ($points) use (
                    &$distance,
                    &$lastLat,
                    &$lastLng,
                    &$lastAt,
                    &$lastLocationAt,
                    &$lastPoint
                ) {
                    foreach ($points as $point) {
                        if ($lastLat !== null && $lastLng !== null && $lastAt !== null) {
                            $seconds = $lastAt->diffInSeconds($point->recorded_at, false);
                            $meters = Geo::distanceMeters(
                                (int) $lastLat,
                                (int) $lastLng,
                                $point->latitude_e7,
                                $point->longitude_e7,
                            );

                            if ($this->isSegmentCountable($seconds, $meters)) {
                                $distance += (int) round($meters);
                            }
                        }

                        $lastLat = $point->latitude_e7;
                        $lastLng = $point->longitude_e7;
                        $lastAt = $point->recorded_at;
                        $lastPoint = $point;

                        if ($lastLocationAt === null || $point->recorded_at->gt($lastLocationAt)) {
                            $lastLocationAt = $point->recorded_at;
                        }
                    }
                });

            $counts = $this->counts($attendance->id);

            $attendance->forceFill([
                'total_distance_meters' => $distance,
                'accepted_points_count' => $counts['accepted'],
                'rejected_points_count' => $counts['rejected'],
                'last_location_at' => $lastLocationAt,
                'last_processed_point_id' => $lastPoint?->id,
                'last_processed_recorded_at' => $lastPoint?->recorded_at,
                'last_processed_latitude_e7' => $lastPoint?->latitude_e7,
                'last_processed_longitude_e7' => $lastPoint?->longitude_e7,
                'last_processed_accuracy' => $lastPoint?->accuracy_meters,
                'distance_needs_recalc' => false,
            ])->save();

            return $attendance->refresh();
        } finally {
            $lock->release();
        }
    }

    public function markForRecalculation(Attendance $attendance): void
    {
        if (! $attendance->distance_needs_recalc) {
            $attendance->forceFill(['distance_needs_recalc' => true])->save();
        }
    }

    /**
     * Whether a segment should add to travelled distance.
     */
    public function isSegmentCountable(int|float $seconds, float $meters): bool
    {
        $maxGapSeconds = (int) config('tracking.max_gap_minutes', 10) * 60;
        $minSegment = (float) config('tracking.min_segment_meters', 8);
        $maxSpeed = (float) config('tracking.max_speed_kmh', 180);

        if ($seconds <= 0 || $seconds > $maxGapSeconds) {
            return false;
        }

        if ($meters < $minSegment) {
            return false;
        }

        $speed = Geo::speedKmh($meters, (float) $seconds);

        return $speed === null || $speed <= $maxSpeed;
    }

    /**
     * @return array{accepted: int, rejected: int}
     */
    private function counts(int $attendanceId): array
    {
        $counts = DB::table('location_points')
            ->where('attendance_id', $attendanceId)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN quality_status = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted,
                 COALESCE(SUM(CASE WHEN quality_status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected"
            )
            ->first();

        return [
            'accepted' => (int) ($counts->accepted ?? 0),
            'rejected' => (int) ($counts->rejected ?? 0),
        ];
    }
}
