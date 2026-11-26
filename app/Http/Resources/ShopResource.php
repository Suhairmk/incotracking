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
            // Canonical digits with the country code — what the WhatsApp
            // share actions dial. Null for shops recorded before this field
            // existed or created by the bulk Excel import.
            'whatsapp_number' => $this->whatsapp_number,
            'notes' => $this->notes,
            'gst_number' => $this->gst_number,
            // Outstanding credit, kept in step with the ledger on every write.
            'credit_balance_paise' => (int) ($this->credit_balance_paise ?? 0),
            // Field visibility: administrators hide a shop from every
            // executive's directory; only administrators ever see it then.
            'is_visible_to_field' => (bool) $this->is_visible_to_field,
            // Present only when the list was queried with the staff relation,
            // so the directory never pays for it per row.
            'assigned_employee_ids' => $this->relationLoaded('staff')
                ? $this->staff->pluck('id')->map(fn ($id): int => (int) $id)->values()->all()
                : null,
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
