<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\LocationQuality;
use App\Enums\TrackingState;
use App\Jobs\RecalculateAttendanceDistance;
use App\Models\Attendance;
use App\Models\EmployeeLiveLocation;
use App\Models\LocationPoint;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationBatchService
{
    public function __construct(
        private readonly DistanceCalculationService $distance,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Persist a batch, acknowledge each record and update summaries.
     *
     * @param  array<int, array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    public function ingest(
        Attendance $attendance,
        array $points,
        TrackingState $state,
        ?string $batchId = null,
        bool $heartbeat = false,
    ): array {
        $now = CarbonImmutable::now('UTC');
        $receivedAt = $now;
        $employeeId = $attendance->employee_id;

        $unique = [];
        foreach ($points as $point) {
            $unique[Str::upper((string) $point['client_point_id'])] = $point;
        }

        $existingIds = DB::table('location_points')
            ->where('employee_id', $employeeId)
            ->whereIn('client_point_id', array_keys($unique))
            ->pluck('client_point_id')
            ->map(fn ($id) => Str::upper((string) $id))
            ->all();

        $existingLookup = array_fill_keys($existingIds, true);
        $rows = [];
        $rejectedPreview = [];
        $newAcceptedTimes = [];

        $earliest = ($attendance->start_device_recorded_at ?? $attendance->start_time)
            ->subMinutes(10);
        $latest = $attendance->status === AttendanceStatus::Completed
            ? ($attendance->end_device_recorded_at ?? $attendance->end_time ?? $now)->addMinutes(10)
            : $now->addMinutes(5);

        foreach ($unique as $clientId => $point) {
            if (isset($existingLookup[$clientId])) {
                continue;
            }

            $recordedAt = CarbonImmutable::parse($point['recorded_at'], 'UTC');
            $accuracy = (int) round((float) $point['accuracy_meters']);
            $speedKmh = isset($point['speed_kmh']) ? (float) $point['speed_kmh'] : null;

            $reason = null;

            if ($accuracy > (int) config('tracking.max_accepted_accuracy_meters', 50)) {
                $reason = 'poor_accuracy';
            } elseif ($speedKmh !== null && $speedKmh > (float) config('tracking.max_speed_kmh', 180)) {
                $reason = 'impossible_speed';
            } elseif ($recordedAt->lessThan($earliest)) {
                $reason = 'before_attendance';
            } elseif ($recordedAt->greaterThan($latest)) {
                $reason = 'after_attendance';
            } elseif ($recordedAt->lessThan($now->subDays((int) config('tracking.history_max_days', 30)))) {
                $reason = 'too_old';
            }

            $quality = $reason === null
                ? LocationQuality::Accepted
                : LocationQuality::Rejected;

            if ($reason !== null) {
                $rejectedPreview[] = ['client_point_id' => $clientId, 'reason' => $reason];
            } else {
                $newAcceptedTimes[] = $recordedAt;
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'attendance_id' => $attendance->id,
                'client_point_id' => $clientId,
                'latitude_e7' => Geo::toE7((float) $point['latitude']),
                'longitude_e7' => Geo::toE7((float) $point['longitude']),
                'accuracy_meters' => max(0, min(65535, $accuracy)),
                'speed_cm_per_second' => $speedKmh === null
                    ? null
                    : max(0, min(65535, (int) round($speedKmh * 100_000 / 3600))),
                'heading_degrees' => isset($point['heading_degrees'])
                    ? ((int) round((float) $point['heading_degrees']) % 360 + 360) % 360
                    : null,
                'recorded_at' => $recordedAt,
                'received_at' => $receivedAt,
                'quality_status' => $quality->value,
                'rejection_reason' => $reason,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('location_points')->insertOrIgnore($chunk);
        }

        $persistedIds = DB::table('location_points')
            ->where('employee_id', $employeeId)
            ->whereIn('client_point_id', array_keys($unique))
            ->pluck('client_point_id')
            ->map(fn ($id) => Str::upper((string) $id))
            ->all();

        $acknowledged = array_values(array_intersect(array_keys($unique), $persistedIds));
        $newIds = array_values(array_diff($acknowledged, $existingIds));
        $duplicateCount = count($existingIds);
        $newAccepted = empty($newIds) ? 0 : LocationPoint::query()
            ->where('employee_id', $employeeId)
            ->whereIn('client_point_id', $newIds)
            ->where('quality_status', LocationQuality::Accepted->value)
            ->count();

        $this->updateHeartbeat($attendance, $state, $now);
        $attendance->refresh();

        $latestAccepted = LocationPoint::query()
            ->where('attendance_id', $attendance->id)
            ->where('quality_status', LocationQuality::Accepted->value)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $this->updateLiveLocation($attendance, $state, $now, $latestAccepted);

        $this->refreshTimestampSummary($attendance, $latestAccepted);

        $cursorAt = $attendance->last_processed_recorded_at;
        $minNewAccepted = $newAcceptedTimes ? min($newAcceptedTimes) : null;
        $hasLatePoints = $cursorAt !== null
            && $minNewAccepted !== null
            && $minNewAccepted->lessThan($cursorAt);

        if ($attendance->status === AttendanceStatus::Completed) {
            $this->distance->markForRecalculation($attendance);
            RecalculateAttendanceDistance::dispatch($attendance->id)->afterResponse();
        } elseif ($hasLatePoints) {
            $this->distance->markForRecalculation($attendance);
            RecalculateAttendanceDistance::dispatch($attendance->id)->afterResponse();
        } else {
            $this->distance->processIncremental($attendance);
        }

        $attendance->refresh();

        if ($newAccepted > 0) {
            $this->logger->log('tracking.batch', null, $attendance, [
                'batch_id' => $batchId ? Str::upper($batchId) : null,
                'points' => count($unique),
                'accepted' => $newAccepted,
                'heartbeat' => $heartbeat,
            ]);
        }

        return [
            'acknowledged_ids' => $acknowledged,
            'duplicate_count' => $duplicateCount,
            'rejected' => $rejectedPreview,
            'accepted_count' => $newAccepted,
            'server_time' => $now,
            'batch_id' => $batchId ? Str::upper($batchId) : null,
            'attendance' => $attendance,
        ];
    }

    private function updateHeartbeat(Attendance $attendance, TrackingState $state, CarbonImmutable $now): void
    {
        $attendance->forceFill([
            'last_heartbeat_at' => $now,
            'tracking_state' => $state,
        ])->save();
    }

    private function updateLiveLocation(
        Attendance $attendance,
        TrackingState $state,
        CarbonImmutable $now,
        ?LocationPoint $latestAccepted,
    ): void {
        $existing = EmployeeLiveLocation::find($attendance->employee_id);

        $payload = [
            'attendance_id' => $attendance->id,
            'last_received_at' => $now,
            'last_heartbeat_at' => $now,
            'tracking_state' => $state->value,
            'updated_at' => $now,
        ];

        if ($latestAccepted && (
            ! $existing?->last_recorded_at
            || $latestAccepted->recorded_at->greaterThanOrEqualTo($existing->last_recorded_at)
        )) {
            $payload += [
                'latitude_e7' => $latestAccepted->latitude_e7,
                'longitude_e7' => $latestAccepted->longitude_e7,
                'accuracy_meters' => $latestAccepted->accuracy_meters,
                'speed_cm_per_second' => $latestAccepted->speed_cm_per_second,
                'last_recorded_at' => $latestAccepted->recorded_at,
            ];
        } elseif (! $existing) {
            $payload += [
                'latitude_e7' => $attendance->start_latitude_e7,
                'longitude_e7' => $attendance->start_longitude_e7,
                'accuracy_meters' => $attendance->start_accuracy,
                'last_recorded_at' => $attendance->start_time,
            ];
        }

        EmployeeLiveLocation::updateOrCreate(
            ['employee_id' => $attendance->employee_id],
            $payload
        );
    }

    private function refreshTimestampSummary(Attendance $attendance, ?LocationPoint $latest): void
    {
        if ($latest && (! $attendance->last_location_at || $latest->recorded_at->gt($attendance->last_location_at))) {
            $attendance->forceFill(['last_location_at' => $latest->recorded_at])->save();
        }
    }
}
