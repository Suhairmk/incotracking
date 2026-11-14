<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active ?? false;
    }

    public function rules(): array
    {
        return [
            // ULID minted on the device: makes an offline re-upload
            // idempotent instead of creating a second order.
            'client_order_id' => ['required', 'string', 'size:26'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'device_created_at' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            // The price the agent quoted is what the order keeps, so an
            // offline quote survives a catalogue change made in between.
            'items.*.unit_price_paise' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }
}
