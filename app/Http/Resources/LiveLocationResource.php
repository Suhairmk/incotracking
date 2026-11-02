<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiveLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now('UTC');
        $staleAfter = (int) config('tracking.stale_after_minutes', 10);
        $attendance = $this->relationLoaded('attendance') ? $this->attendance : null;

        $lastHeartbeat = $this->last_heartbeat_at ?? $this->last_received_at;
        $health = match (true) {
            $this->tracking_state?->value === 'interrupted' => 'interrupted',
            $lastHeartbeat === null => 'starting',
            $lastHeartbeat->lt($now->subMinutes($staleAfter)) => 'stale',
            default => 'active',
        };

        $latitude = $this->latitude_e7 ?? $attendance?->start_latitude_e7;
        $longitude = $this->longitude_e7 ?? $attendance?->start_longitude_e7;

        return [
            'employee_id' => $this->employee_id,
            'employee' => new UserResource($this->whenLoaded('employee')),
            'attendance_id' => $this->attendance_id,
            'latitude' => $latitude !== null ? Geo::fromE7($latitude) : null,
            'longitude' => $longitude !== null ? Geo::fromE7($longitude) : null,
            'accuracy_meters' => $this->accuracy_meters ?? $attendance?->start_accuracy,
            'speed_kmh' => $this->speed_cm_per_second !== null
                ? round($this->speed_cm_per_second * 0.036, 1)
                : null,
            'last_recorded_at' => $this->last_recorded_at?->toIso8601String(),
            'last_received_at' => $this->last_received_at?->toIso8601String(),
            'last_heartbeat_at' => $lastHeartbeat?->toIso8601String(),
            'tracking_state' => $this->tracking_state?->value,
            'health' => $health,
            'stale_seconds' => $lastHeartbeat ? max(0, (int) $lastHeartbeat->diffInSeconds($now)) : null,
            'attendance' => $attendance ? [
                'start_time' => $attendance->start_time?->toIso8601String(),
                'total_distance_meters' => (int) $attendance->total_distance_meters,
                'total_distance_km' => round($attendance->total_distance_meters / 1000, 3),
                'visits_count' => (int) $attendance->visits_count,
                'accepted_points_count' => (int) $attendance->accepted_points_count,
            ] : null,
        ];
    }
}
