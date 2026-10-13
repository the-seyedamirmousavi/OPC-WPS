<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings of the AI service (GapGPT, an OpenAI-compatible API). The token and the model come from the owner console
 * (system_setting; the token is stored encrypted) and otherwise from the environment. The token never leaves the server.
 */
final class LlmConfig
{
    public const SOURCE_SETTINGS = 'settings';
    public const SOURCE_ENVIRONMENT = 'environment';

    public static function apiKey(): ?string
    {
        $stored = SystemSetting::current()->llm_api_key;
        if (is_string($stored) && $stored !== '') {
            try {
                $key = Crypt::decryptString($stored);
                if ($key !== '') {
                    return $key;
                }
            } catch (DecryptException) {
                // the application key changed since the token was saved: treat it as not set
            }
        }
        $env = (string) config('aiso.llm.api_key');

        return $env === '' ? null : $env;
    }

    /** Where the token in use comes from, or null when there is none. */
    public static function keySource(): ?string
    {
        $stored = SystemSetting::current()->llm_api_key;
        if (is_string($stored) && $stored !== '') {
            try {
                Crypt::decryptString($stored);

                return self::SOURCE_SETTINGS;
            } catch (DecryptException) {
                // fall through to the environment
            }
        }

        return (string) config('aiso.llm.api_key') === '' ? null : self::SOURCE_ENVIRONMENT;
    }

    /** The last four characters of the token in use, for the owner to recognise it. Never the whole token. */
    public static function keyHint(): ?string
    {
        $key = self::apiKey();

        return $key === null ? null : '…' . mb_substr($key, -4);
    }

    public static function model(): string
    {
        $stored = SystemSetting::current()->llm_model;

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : (string) config('aiso.llm.model');
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('aiso.llm.base_url'), '/');
    }

    public static function configured(): bool
    {
        return self::apiKey() !== null && self::model() !== '';
    }
}
