<?php

namespace App\Console\Commands;

use App\Services\VendorAuth;
use App\Support\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates (or removes) the software vendor's sign-in. The vendor signs in on the normal form and lands in the vendor
 * console. Three layers, each optional after the first:
 *   1. user name + password: stored only as hashes in .env;
 *   2. a device token: the browser must carry it in a cookie (only its SHA-256 is stored);
 *   3. an authenticator app code (TOTP): the base32 secret is stored, as every TOTP verifier must.
 * Plain values are shown once, here.
 */
class VendorCredentials extends Command
{
    protected $signature = 'aiso:vendor-credentials
        {--username= : User name to use (default: a random one)}
        {--password= : Password to use (default: a random 24-character one)}
        {--token-only : Only create a new device token; keeps the user name and password}
        {--totp-only : Only create a new authenticator secret; keeps the user name, password and token}
        {--no-token : With a full create: do not require the device cookie}
        {--totp : With a full create: also require an authenticator code}
        {--disable-token : Stop requiring the device cookie}
        {--disable-totp : Stop requiring the authenticator code}
        {--print-only : Print the .env lines instead of writing them to .env}
        {--remove : Remove every vendor credential (switches the vendor console off)}';

    protected $description = 'Create or remove the software vendor console credentials (user name, password, device token, authenticator)';

    private const ALL_KEYS = ['AISO_VENDOR_SALT', 'AISO_VENDOR_USER_HASH', 'AISO_VENDOR_PASSWORD_HASH', 'AISO_VENDOR_TOKEN_HASH', 'AISO_VENDOR_TOTP_SECRET'];

    public function handle(): int
    {
        if ($this->option('remove')) {
            $this->writeEnv(array_fill_keys(self::ALL_KEYS, ''));
            $this->info('Vendor sign-in removed. The vendor console is now switched off.');

            return self::SUCCESS;
        }
        if ($this->option('disable-token') || $this->option('disable-totp')) {
            $clear = [];
            if ($this->option('disable-token')) {
                $clear['AISO_VENDOR_TOKEN_HASH'] = '';
            }
            if ($this->option('disable-totp')) {
                $clear['AISO_VENDOR_TOTP_SECRET'] = '';
            }
            $this->writeEnv($clear);
            $this->info('Done. Those extra checks are no longer required.');

            return self::SUCCESS;
        }

        $values = [];
        $report = [];
        $tokenOnly = (bool) $this->option('token-only');
        $totpOnly = (bool) $this->option('totp-only');

        if (!$tokenOnly && !$totpOnly) {
            $username = trim((string) ($this->option('username') ?: 'dev-' . strtolower(Str::random(10))));
            $password = (string) ($this->option('password') ?: Str::random(24));
            if (mb_strlen($username) < 6 || mb_strlen($password) < 12) {
                $this->error('Use a user name of at least 6 and a password of at least 12 characters.');

                return self::INVALID;
            }
            $h = VendorAuth::hashCredentials($username, $password);
            $values += ['AISO_VENDOR_SALT' => $h['salt'], 'AISO_VENDOR_USER_HASH' => $h['user_hash'], 'AISO_VENDOR_PASSWORD_HASH' => $h['password_hash']];
            $report[] = "user name: {$username}";
            $report[] = "password : {$password}";
        }

        if ($tokenOnly || (!$totpOnly && !$this->option('no-token'))) {
            $token = Str::random(48);
            $values['AISO_VENDOR_TOKEN_HASH'] = VendorAuth::tokenHash($token);
            $report[] = '';
            $report[] = 'device token (put it in the browser cookie; see below):';
            $report[] = '  cookie name : ' . VendorAuth::COOKIE;
            $report[] = "  cookie value: {$token}";
        }

        if ($totpOnly || (!$tokenOnly && $this->option('totp'))) {
            $secret = Totp::generateSecret();
            $values['AISO_VENDOR_TOTP_SECRET'] = $secret;
            $report[] = '';
            $report[] = 'authenticator secret (add it to Google Authenticator / Authy / Microsoft Authenticator):';
            $report[] = "  secret : {$secret}";
            $report[] = '  address: ' . Totp::uri($secret, 'vendor', 'AISO');
            $report[] = '  (choose "enter a setup key", type: time based, 6 digits)';
        }

        if ($this->option('print-only')) {
            foreach ($values as $k => $v) {
                $this->line("{$k}='{$v}'");
            }
        } else {
            $this->writeEnv($values);
            $this->callSilently('config:clear');
        }

        $this->newLine();
        $this->warn('Shown only now; the system keeps just hashes (and the authenticator secret, which it must have to check codes):');
        foreach ($report as $line) {
            $this->line($line === '' ? '' : '  ' . $line);
        }
        if (isset($values['AISO_VENDOR_TOKEN_HASH'])) {
            $this->newLine();
            $this->line('  Set the cookie BEFORE you sign in (browser DevTools > Application > Cookies): path "/", expires in a year,');
            $this->line('  tick HttpOnly and SameSite=Strict (and Secure when the site is on https). Without it the vendor sign-in is refused.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, string> $values */
    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        foreach ($values as $key => $value) {
            $line = $key . "='" . $value . "'";
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $env) === 1) {
                $env = (string) preg_replace_callback($pattern, static fn () => $line, $env);
            } else {
                $env = rtrim($env, "\r\n") . "\n" . $line . "\n";
            }
        }
        file_put_contents($path, $env);
        $this->components->info('.env updated (' . implode(', ', array_keys($values)) . ').');
    }
}
