<?php

namespace App\Rules;

use App\Support\WhatsAppNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the WhatsApp number of a shop. An empty value is accepted —
 * whether the field is required is decided by `required`/`nullable` in the
 * request rules, so old shops can still be edited without one.
 */
class ValidWhatsAppNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('Enter a WhatsApp number, e.g. 9876543210.');

            return;
        }

        if ($error = WhatsAppNumber::validationError($value)) {
            $fail($error);
        }
    }
}
