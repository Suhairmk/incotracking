<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'string', 'max:24'],
            // Minor units, capped at ₹1 crore per unit.
            'price_paise' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
