<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            // Snapshots of what was sold, kept even if the catalogue moves on.
            'product_name' => $this->product_name,
            'unit' => $this->unit,
            'unit_price_paise' => $this->unit_price_paise,
            'quantity' => $this->quantity,
            'line_total_paise' => $this->line_total_paise,
            'line_tax_paise' => $this->line_tax_paise,
            'tax_rate_bp' => $this->tax_rate_bp,
        ];
    }
}
