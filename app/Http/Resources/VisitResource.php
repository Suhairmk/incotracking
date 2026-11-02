<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_visit_id' => $this->client_visit_id,
            'attendance_id' => $this->attendance_id,
            'employee' => new UserResource($this->whenLoaded('employee')),
            'customer_name' => $this->customer_name,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'latitude' => Geo::fromE7($this->latitude_e7),
            'longitude' => Geo::fromE7($this->longitude_e7),
            'accuracy_meters' => $this->accuracy_meters,
            'device_recorded_at' => $this->device_recorded_at?->toIso8601String(),
            'server_recorded_at' => $this->server_recorded_at?->toIso8601String(),
            'remarks' => $this->remarks,
            'outcome' => $this->outcome?->value,
            'outcome_label' => $this->outcome?->label(),
            'photo_url' => $this->photo_path ? route('photos.visit', [
                'visit' => $this->id,
                'variant' => 'full',
            ], false) : null,
            'photo_thumb_url' => $this->photo_path ? route('photos.visit', [
                'visit' => $this->id,
                'variant' => 'thumb',
            ], false) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
