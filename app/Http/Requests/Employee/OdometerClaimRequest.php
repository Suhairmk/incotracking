<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An odometer reading typed off the meter: kilometres with up to three
 * decimals (tenths of a metre is finer than any meter can show, but the
 * conversion keeps integer metres on the way out without rounding drift).
 */
class OdometerClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'odometer_km' => ['required', 'numeric', 'min:0', 'max:5000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'odometer_km.required' => 'Enter the odometer reading.',
            'odometer_km.numeric' => 'The odometer reading must be a number.',
            'odometer_km.min' => 'The odometer reading cannot be negative.',
            'odometer_km.max' => 'That odometer reading looks impossible (over 5,000,000 km).',
        ];
    }

    /**
     * The typed kilometres as integer metres.
     */
    public function odometerMeters(): int
    {
        return (int) round(((float) $this->validated('odometer_km')) * 1000);
    }
}
