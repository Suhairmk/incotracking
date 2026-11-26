<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OdometerClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'attendance_id' => $this->attendance_id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            'odometer_meters' => (int) $this->odometer_meters,
            'odometer_km' => round($this->odometer_meters / 1000, 3),
            'previous_odometer_meters' => $this->previous_odometer_meters !== null
                ? (int) $this->previous_odometer_meters
                : null,
            'claimed_distance_meters' => $this->claimed_distance_meters !== null
                ? (int) $this->claimed_distance_meters
                : null,
            'claimed_distance_km' => $this->claimed_distance_meters !== null
                ? round($this->claimed_distance_meters / 1000, 3)
                : null,
            'gps_distance_meters' => (int) $this->gps_distance_meters,
            'gps_distance_km' => round($this->gps_distance_meters / 1000, 3),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'admin_notes' => $this->admin_notes,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'employee' => new UserResource($this->whenLoaded('employee')),
            'attendance' => new AttendanceResource($this->whenLoaded('attendance')),
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
