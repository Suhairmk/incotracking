<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Ulid
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && Str::isUlid($value);
    }

    public static function generate(): string
    {
        return strtoupper((string) Str::ulid());
    }
}
