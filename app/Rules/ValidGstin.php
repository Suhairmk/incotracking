<?php

namespace App\Rules;

use App\Support\Gstin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts an empty value (the field is optional everywhere) and validates the
 * GSTIN shape and checksum when one is given.
 */
class ValidGstin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a valid GSTIN.');

            return;
        }

        if ($error = Gstin::validationError($value)) {
            $fail($error);
        }
    }
}
