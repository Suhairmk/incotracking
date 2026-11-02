<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationPointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => Geo::fromE7($this->latitude_e7),
            'longitude' => Geo::fromE7($this->longitude_e7),
            'accuracy_meters' => $this->accuracy_meters,
            'speed_kmh' => $this->speed_cm_per_second !== null
                ? round($this->speed_cm_per_second * 0.036, 1)
                : null,
            'heading_degrees' => $this->heading_degrees,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'quality_status' => $this->quality_status?->value,
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
