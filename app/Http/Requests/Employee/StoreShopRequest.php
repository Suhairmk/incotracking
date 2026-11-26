<?php

namespace App\Http\Requests\Employee;

use App\Models\Shop;
use App\Rules\ValidGstin;
use App\Rules\ValidWhatsAppNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([Shop::TYPE_SHOP, Shop::TYPE_DEALER])],
            'location_name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Required when a shop is added — every new shop carries a
            // WhatsApp number so reports can be sent to it — and optional
            // when an existing entry is edited, because shops recorded
            // before this field existed (and the bulk Excel import) must
            // stay editable without being blocked.
            'whatsapp_number' => [
                $this->isMethod('POST') ? 'required' : 'nullable',
                'string',
                'max:20',
                new ValidWhatsAppNumber,
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Optional: an unregistered shop or dealer is still listed. Only
            // the shape and checksum are checked — never a lookup, and never
            // a duplicate block.
            'gst_number' => ['nullable', 'string', new ValidGstin],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'between:0,65535'],
        ];
    }
}
