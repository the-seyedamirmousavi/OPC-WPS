<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The Bale bot token. The owner sets it in Settings (stored encrypted, never returned); the BALE_BOT_TOKEN environment
 * value applies when nothing is stored. The token never leaves the server.
 */
final class BaleConfig
{
    public const SOURCE_SETTINGS = 'settings';
    public const SOURCE_ENVIRONMENT = 'environment';

    public static function token(): ?string
    {
        $stored = SystemSetting::current()->bale_bot_token;
        if (is_string($stored) && $stored !== '') {
            try {
                $token = Crypt::decryptString($stored);
                if ($token !== '') {
                    return $token;
                }
            } catch (DecryptException) {
                // the application key changed since the token was saved: treat it as not set
            }
        }
        $env = (string) config('aiso.bale.token');

        return $env === '' ? null : $env;
    }

    /** Where the token in use comes from, or null when there is none. */
    public static function keySource(): ?string
    {
        $stored = SystemSetting::current()->bale_bot_token;
        if (is_string($stored) && $stored !== '') {
            try {
                Crypt::decryptString($stored);

                return self::SOURCE_SETTINGS;
            } catch (DecryptException) {
                // fall through to the environment
            }
        }

        return (string) config('aiso.bale.token') === '' ? null : self::SOURCE_ENVIRONMENT;
    }

    /** The last four characters of the token in use, so the owner can recognise it. Never the whole token. */
    public static function keyHint(): ?string
    {
        $token = self::token();

        return $token === null ? null : '…' . mb_substr($token, -4);
    }

    public static function configured(): bool
    {
        return self::token() !== null;
    }
}
