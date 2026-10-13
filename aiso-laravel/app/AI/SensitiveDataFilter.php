<?php

namespace App\AI;

use App\Services\LlmConfig;

/**
 * Keeps sensitive values away from the AI and out of what the user is shown. Handlers return only the fields they choose;
 * this removes known sensitive keys as a second line and masks sensitive-looking numbers in the final text.
 */
final class SensitiveDataFilter
{
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'remember_token', 'api_key', 'llm_api_key', 'token', 'messenger_id', 'contact_info',
        'salary', 'national_id', 'account_number', 'iban', 'card_number',
    ];

    /** Removes sensitive keys from data at any depth. */
    public static function clean(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }
            $out[$key] = self::clean($value);
        }

        return $out;
    }

    /** Masks card numbers, IBANs, national ids and the AI token if they appear in text. */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/\bIR\d{24}\b/i', '[شماره شبا]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\d[ -]?){15}\d(?!\d)/', '[شماره کارت]', $text) ?? $text;
        $text = preg_replace('/(?<![\d-])\d{10}(?![\d-])/', '[کد ملی]', $text) ?? $text;
        $key = LlmConfig::apiKey();
        if ($key !== null && strlen($key) >= 8) {
            $text = str_replace($key, '[کلید]', $text);
        }

        return $text;
    }
}
