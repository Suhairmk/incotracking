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
            // GST breakdown: pre-rollout orders keep subtotal = total and a
            // zero rate, so clients can tell taxed from legacy rows apart.
            'subtotal_paise' => $this->subtotal_paise,
            'tax_paise' => $this->tax_paise,
            'tax_rate_bp' => $this->tax_rate_bp,
            'taxed' => $this->isTaxed(),
            'business_date' => $this->business_date?->toDateString(),
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
