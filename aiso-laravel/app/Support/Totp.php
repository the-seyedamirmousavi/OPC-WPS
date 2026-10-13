<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 6 digits, 30 s), the format Google Authenticator, Microsoft
 * Authenticator, Authy and similar apps use. Secrets are written in base32 (RFC 4648).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random 160-bit secret in base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The otpauth:// address an authenticator app takes (as a QR code or typed in). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer), self::DIGITS, self::PERIOD);
    }

    /** The code for a moment in time. */
    public static function code(string $secret, int $timestamp): string
    {
        return self::hotp(self::base32Decode($secret), intdiv($timestamp, self::PERIOD));
    }

    /**
     * Checks a code against the current time step and the neighbours (clock drift). Returns the matching time step so the
     * caller can refuse to accept the same step twice, or null when the code is wrong.
     */
    public static function verify(string $secret, string $code, ?int $now = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secret);
        $step = intdiv($now ?? time(), self::PERIOD);
        $match = null;
        // every step is compared (no early exit) so timing does not reveal which one was close
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $step + $i), $code) && $match === null) {
                $match = $step + $i;
            }
        }

        return $match;
    }

    private static function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim(str_replace(' ', '', $text), '='))) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v === false) {
                throw new \InvalidArgumentException('Not a base32 secret');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
