<?php

namespace Tests\Unit;

use App\Support\Gstin;
use PHPUnit\Framework\TestCase;

class GstinTest extends TestCase
{
    public function test_a_real_gstin_is_accepted(): void
    {
        $this->assertNull(Gstin::validationError('27AAPFU0939F1ZV'));
        $this->assertNull(Gstin::validationError('29ABCDE1234F5ZS'));
        $this->assertNull(Gstin::validationError('07AAAAA0000A1Z4'));
        $this->assertTrue(Gstin::isValid('36ABCDE1234F1Z1'));
    }

    public function test_the_check_digit_is_computed_from_the_first_fourteen_characters(): void
    {
        $this->assertSame('V', Gstin::checkDigit('27AAPFU0939F1Z'));
        $this->assertSame('S', Gstin::checkDigit('29ABCDE1234F5Z'));
    }

    public function test_case_and_whitespace_are_normalised_before_checking(): void
    {
        $this->assertTrue(Gstin::isValid(' 27aapfu0939f1zv '));
        $this->assertSame('27AAPFU0939F1ZV', Gstin::normalize(' 27aapfu0939f1zv '));
        $this->assertNull(Gstin::normalize(''));
        $this->assertNull(Gstin::normalize(null));
    }

    public function test_a_wrong_check_digit_is_rejected(): void
    {
        $this->assertSame(
            'GSTIN check digit does not match the rest of the number.',
            Gstin::validationError('27AAPFU0939F1ZW'),
        );
    }

    public function test_an_unrecognised_state_code_is_rejected(): void
    {
        $this->assertSame(
            'GSTIN state code is not a recognised state or territory.',
            Gstin::validationError('40ABCDE1234F1Z1'),
        );
    }

    public function test_the_wrong_length_is_rejected(): void
    {
        $this->assertSame(
            'GSTIN must be exactly 15 characters.',
            Gstin::validationError('27AAPFU0939F1Z'),
        );
        $this->assertSame(
            'GSTIN must be exactly 15 characters.',
            Gstin::validationError(null),
        );
    }

    public function test_a_malformed_shape_is_rejected(): void
    {
        $this->assertSame(
            'GSTIN must be a state code, a PAN, an entity number, Z and a check digit.',
            Gstin::validationError('27-AAPFU0939F1Z'),
        );
    }
}
