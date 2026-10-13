<?php

namespace App\Services;

use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in of the software vendor. The vendor uses the normal sign-in form, so nothing in the interface reveals the
 * console. The configured user name and password are only hashes; a match opens a vendor session that is separate
 * from the application's own users (it grants no application role and no access to the application's API).
 */
class VendorAuth
{
    public const SESSION_KEY = 'aiso_vendor_seen_at';
    /** Set after the password step while an authenticator code is still owed. */
    public const PENDING_KEY = 'aiso_vendor_pending_at';
    /** Name of the device cookie that must carry the vendor token (see tokenOk()). Set by hand in the browser. */
    public const COOKIE = 'aiso_dev';
    /** How long the authenticator code may take after the password step. */
    public const OTP_WINDOW_SECONDS = 300;

    public function configured(): bool
    {
        return (string) config('aiso.vendor.salt') !== ''
            && (string) config('aiso.vendor.user_hash') !== ''
            && (string) config('aiso.vendor.password_hash') !== '';
    }

    /** True only when both the user name and the password are the vendor's. */
    public function matches(string $username, string $password): bool
    {
        if (!$this->configured()) {
            return false;
        }
        // The user name is compared first, in constant time, so ordinary sign-ins never pay for a bcrypt check.
        if (!hash_equals((string) config('aiso.vendor.user_hash'), self::userHash($username, (string) config('aiso.vendor.salt')))) {
            return false;
        }

        return Hash::check($password, (string) config('aiso.vendor.password_hash'));
    }

    public function tokenRequired(): bool
    {
        return (string) config('aiso.vendor.token_hash') !== '';
    }

    /**
     * The device cookie carries the secret token. Without a configured token hash this is always true. The cookie is
     * excluded from Laravel's cookie encryption (bootstrap/app.php) so a value typed into the browser can be read.
     */
    public function tokenOk(Request $request): bool
    {
        if (!$this->tokenRequired()) {
            return true;
        }
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && $token !== ''
            && hash_equals((string) config('aiso.vendor.token_hash'), self::tokenHash($token));
    }

    public function totpRequired(): bool
    {
        return (string) config('aiso.vendor.totp_secret') !== '';
    }

    /** Password step passed but an authenticator code is still owed: nothing is granted yet. */
    public function beginOtp(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $request->session()->put(self::PENDING_KEY, time());
    }

    public function pendingOtp(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }
        $at = $request->session()->get(self::PENDING_KEY);

        return is_int($at) && time() - $at <= self::OTP_WINDOW_SECONDS;
    }

    /** A correct code that was not used before (a time step is accepted once, so a sniffed code cannot be replayed). */
    public function verifyOtp(string $code): bool
    {
        $step = Totp::verify((string) config('aiso.vendor.totp_secret'), $code);
        if ($step === null) {
            return false;
        }

        return Cache::add('vendor-totp-step:' . $step, true, 120);
    }

    public function start(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $request->session()->forget(self::PENDING_KEY);
        $request->session()->put(self::SESSION_KEY, time());
    }

    /** A live vendor session; every call refreshes the idle timer. The device cookie is checked on every request. */
    public function active(Request $request): bool
    {
        if (!$this->configured() || !$request->hasSession() || !$this->tokenOk($request)) {
            return false;
        }
        $seen = $request->session()->get(self::SESSION_KEY);
        if (!is_int($seen)) {
            return false;
        }
        if (time() - $seen > max(1, (int) config('aiso.vendor.idle_minutes')) * 60) {
            $request->session()->forget(self::SESSION_KEY);

            return false;
        }
        $request->session()->put(self::SESSION_KEY, time());

        return true;
    }

    public function end(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public static function tokenHash(string $token): string
    {
        // the token is long and random, so a plain SHA-256 is enough (nothing to brute-force)
        return hash('sha256', $token);
    }

    public static function userHash(string $username, string $salt): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($username)), $salt);
    }

    /** @return array{salt: string, user_hash: string, password_hash: string} the three values to store */
    public static function hashCredentials(string $username, string $password): array
    {
        $salt = bin2hex(random_bytes(16));

        return [
            'salt' => $salt,
            'user_hash' => self::userHash($username, $salt),
            'password_hash' => Hash::make($password),
        ];
    }
}
