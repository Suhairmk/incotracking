<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            // Echoed back so an offline row uploaded by this device can be
            // matched against the server copy when the lists are merged.
            'client_order_id' => $this->client_order_id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'total_paise' => $this->total_paise,
            'notes' => $this->notes,
            'shop' => [
                'id' => $this->shop?->id,
                'name' => $this->shop?->name,
                'type' => $this->shop?->type,
                'location_name' => $this->shop?->location_name,
                'gst_number' => $this->shop?->gst_number,
            ],
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id,
                'name' => $this->employee?->name,
                'employee_code' => $this->employee?->employee_code,
            ]),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivered_by' => $this->whenLoaded('deliveredBy', fn () => [
                'id' => $this->deliveredBy?->id,
                'name' => $this->deliveredBy?->name,
            ]),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => [
                'id' => $this->cancelledBy?->id,
                'name' => $this->cancelledBy?->name,
            ]),
            'device_created_at' => $this->device_created_at?->toIso8601String(),
            'server_recorded_at' => $this->server_recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
