<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now('UTC');
        $staleAfter = (int) config('tracking.stale_after_minutes', 10);
        $trackingStatus = $this->trackingStatus($now, $staleAfter);

        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            'status' => $this->status?->value,
            'start_time' => $this->start_time?->toIso8601String(),
            'end_time' => $this->end_time?->toIso8601String(),
            'start_device_recorded_at' => $this->start_device_recorded_at?->toIso8601String(),
            'end_device_recorded_at' => $this->end_device_recorded_at?->toIso8601String(),
            'start_client_id' => $this->start_client_id,
            'end_client_id' => $this->end_client_id,
            'start' => [
                'latitude' => Geo::fromE7($this->start_latitude_e7),
                'longitude' => Geo::fromE7($this->start_longitude_e7),
                'accuracy_meters' => $this->start_accuracy,
                'photo_url' => $this->start_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'start',
                ], false) : null,
                'photo_thumb_url' => $this->start_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'start_thumb',
                ], false) : null,
                'meter_photo_url' => $this->start_meter_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'start_meter',
                ], false) : null,
                'meter_photo_thumb_url' => $this->start_meter_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'start_meter_thumb',
                ], false) : null,
            ],
            'end' => $this->end_time ? [
                'latitude' => $this->end_latitude_e7 !== null ? Geo::fromE7($this->end_latitude_e7) : null,
                'longitude' => $this->end_longitude_e7 !== null ? Geo::fromE7($this->end_longitude_e7) : null,
                'accuracy_meters' => $this->end_accuracy,
                'photo_url' => $this->end_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'end',
                ], false) : null,
                'photo_thumb_url' => $this->end_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'end_thumb',
                ], false) : null,
                'meter_photo_url' => $this->end_meter_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'end_meter',
                ], false) : null,
                'meter_photo_thumb_url' => $this->end_meter_photo_path ? route('photos.attendance', [
                    'attendance' => $this->id,
                    'type' => 'end_meter_thumb',
                ], false) : null,
            ] : null,
            'total_distance_meters' => (int) $this->total_distance_meters,
            'total_distance_km' => round($this->total_distance_meters / 1000, 3),
            'total_working_seconds' => (int) $this->total_working_seconds,
            'total_paused_seconds' => (int) $this->total_paused_seconds,
            'worked_seconds' => $this->workedSeconds($now),
            'is_paused' => $this->isPaused(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'resumed_at' => $this->resumed_at?->toIso8601String(),
            'accepted_points_count' => (int) $this->accepted_points_count,
            'rejected_points_count' => (int) $this->rejected_points_count,
            'visits_count' => (int) $this->visits_count,
            'last_location_at' => $this->last_location_at?->toIso8601String(),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'tracking_state' => $this->tracking_state?->value,
            'tracking_status' => $trackingStatus,
            'distance_needs_recalc' => (bool) $this->distance_needs_recalc,
            'employee' => new UserResource($this->whenLoaded('employee')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function trackingStatus($now, int $staleAfter): string
    {
        if ($this->status?->value === 'completed') {
            return 'completed';
        }

        if ($this->tracking_state?->value === 'interrupted') {
            return 'interrupted';
        }

        if (! $this->last_heartbeat_at) {
            return 'starting';
        }

        return $this->last_heartbeat_at->lt($now->subMinutes($staleAfter))
            ? 'stale'
            : 'active';
    }
}
