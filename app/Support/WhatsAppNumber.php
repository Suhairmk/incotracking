<?php

namespace App\Support;

/**
 * Normalising a WhatsApp number into the canonical digit string the
 * messaging paths need: `919876543210`.
 *
 * WhatsApp addresses a chat by digits only — the country code included, no
 * plus, spaces or dashes. Field staff type the number the way it is written
 * on a shop board (`+91 98765 43210`, `098765 43210` or a bare ten digits),
 * so the shape is folded here once, on the way in, and the stored value is
 * already what a `wa.me` link wants.
 */
class WhatsAppNumber
{
    private const COUNTRY_CODE = '91';

    /**
     * Canonical digits, or null when nothing usable is left.
     */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        // "+0091…" / "0091…" as well as "+91…".
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // A leading trunk zero (`09876543210`) belongs to the national
        // format, not to the country code.
        if (str_starts_with($digits, '0') && strlen($digits) > 10) {
            $digits = substr($digits, 1);
        }

        // Ten digits typed bare are an Indian mobile: prefix the country
        // code so the number dials from anywhere.
        if (strlen($digits) === 10) {
            $digits = self::COUNTRY_CODE.$digits;
        }

        return $digits === '' ? null : $digits;
    }

    public static function isValid(?string $value): bool
    {
        return self::validationError($value) === null;
    }

    /**
     * A human-readable reason the number cannot be used, or null when it
     * passes. Kept separate from [normalize] so a rejected value is never
     * quietly rewritten into something that looks stored.
     */
    public static function validationError(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if ($digits === '') {
            return 'Enter the WhatsApp number of the shop.';
        }

        if (strlen($digits) > 15) {
            return 'A WhatsApp number cannot be longer than 15 digits.';
        }

        $normalized = self::normalize($value);

        if ($normalized === null || strlen($normalized) < 11) {
            return 'Enter a full number with the country code, e.g. 9876543210 or 919876543210.';
        }

        if (str_starts_with($normalized, '0')) {
            return 'Enter the number without a leading zero, e.g. 9876543210.';
        }

        return null;
    }
}
