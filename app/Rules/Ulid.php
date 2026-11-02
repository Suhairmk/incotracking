<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Ulid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value)) {
            $fail('The :attribute must be a valid ULID.');
        }
    }
}
