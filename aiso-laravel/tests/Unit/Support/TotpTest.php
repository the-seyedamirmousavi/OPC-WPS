<?php

namespace Tests\Unit\Support;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 appendix B secret ("12345678901234567890") in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** The RFC lists 8-digit codes; the 6-digit code is the same number modulo 10^6. */
    public static function rfcVectors(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc_6238_test_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::RFC_SECRET, $time));
        $this->assertSame(intdiv($time, 30), Totp::verify(self::RFC_SECRET, $expected, $time));
    }

    public function test_neighbouring_steps_are_accepted_but_not_further_ones(): void
    {
        $code = Totp::code(self::RFC_SECRET, 59); // step 1
        $this->assertSame(1, Totp::verify(self::RFC_SECRET, $code, 89));  // step 2: one step of drift
        $this->assertSame(1, Totp::verify(self::RFC_SECRET, $code, 30));  // step 1
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, 120));    // step 4: too old
    }

    public function test_malformed_codes_are_rejected(): void
    {
        foreach (['', 'abcdef', '12345', '1234567', '12 34'] as $bad) {
            $this->assertNull(Totp::verify(self::RFC_SECRET, $bad, 59), "'{$bad}' must not verify");
        }
        $this->assertSame(1, Totp::verify(self::RFC_SECRET, '287 082', 59), 'spaces typed by the user are ignored');
    }

    public function test_base32_round_trips_and_secrets_are_random_and_valid(): void
    {
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));

        $a = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        $this->assertNotSame($a, Totp::generateSecret());
    }
}
