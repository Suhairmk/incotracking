<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'location_name' => $this->location_name,
            'address' => $this->address,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'latitude' => Geo::fromE7($this->latitude_e7),
            'longitude' => Geo::fromE7($this->longitude_e7),
            'accuracy_meters' => $this->accuracy_meters,
            // Present only when the list was queried with a nearby origin.
            'distance_meters' => $this->resource->getAttribute('distance_meters') !== null
                ? (int) $this->resource->getAttribute('distance_meters')
                : null,
            'created_by' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
