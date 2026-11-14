<?php

namespace App\Support;

/**
 * Structural validation of an Indian GSTIN (Goods and Services Tax
 * Identification Number) without any external lookup.
 *
 * A GSTIN is 15 characters: a two-digit state code, the ten-character PAN of
 * the holder, a one-character entity number for that PAN, the literal "Z" and
 * a checksum digit computed over the preceding fourteen characters. Only the
 * shape and the checksum are checked here — proving the number actually
 * belongs to a registered taxpayer needs the GST portal, which is out of
 * scope on purpose.
 */
class Gstin
{
    private const LENGTH = 15;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * State and territory codes recognised by the GST system. 25 and 28 were
     * retired when their territories were merged, but legacy numbers may
     * still carry them, so the full 01-38 range is accepted.
     *
     * @var list<string>
     */
    private const STATE_CODES = [
        '01', '02', '03', '04', '05', '06', '07', '08', '09', '10',
        '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
        '21', '22', '23', '24', '25', '26', '27', '28', '29', '30',
        '31', '32', '33', '34', '35', '36', '37', '38', '97',
    ];

    /**
     * Uppercases and strips whitespace so a number typed in any shape is
     * stored identically. Returns null when nothing is left.
     */
    public static function normalize(?string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $value) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    public static function isValid(?string $value): bool
    {
        return self::validationError($value) === null;
    }

    /**
     * Returns a human-readable reason the number is not acceptable, or null
     * when it passes every check. The checks run cheapest-first so an
     * obvious typo never reaches the checksum.
     */
    public static function validationError(?string $value): ?string
    {
        $gstin = self::normalize($value);

        if ($gstin === null || strlen($gstin) !== self::LENGTH) {
            return 'GSTIN must be exactly 15 characters.';
        }

        if (! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            return 'GSTIN must be a state code, a PAN, an entity number, Z and a check digit.';
        }

        if (! in_array(substr($gstin, 0, 2), self::STATE_CODES, true)) {
            return 'GSTIN state code is not a recognised state or territory.';
        }

        if (self::checkDigit(substr($gstin, 0, self::LENGTH - 1)) !== $gstin[self::LENGTH - 1]) {
            return 'GSTIN check digit does not match the rest of the number.';
        }

        return null;
    }

    /**
     * GSTIN checksum over the first fourteen characters: each alphanumeric
     * value is weighted 2, 1, 2, 1… from the right, every product is folded
     * into base 36 (quotient + remainder), and the check digit is the
     * complement of the total modulo 36.
     */
    public static function checkDigit(string $firstFourteen): string
    {
        $factor = 2;
        $sum = 0;

        for ($i = strlen($firstFourteen) - 1; $i >= 0; $i--) {
            $codePoint = strpos(self::ALPHABET, $firstFourteen[$i]);
            $digit = $factor * (int) $codePoint;
            $factor = $factor === 2 ? 1 : 2;

            $sum += intdiv($digit, 36) + ($digit % 36);
        }

        return self::ALPHABET[(36 - ($sum % 36)) % 36];
    }
}
