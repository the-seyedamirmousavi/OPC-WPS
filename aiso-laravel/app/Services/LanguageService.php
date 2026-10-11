<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Support\Text\FaTranslator;

/**
 * Chooses the language of text the system produces.
 *  - Request language: for answers to one HTTP call (errors, import findings, Excel downloads): the Accept-Language
 *    header (the web app sends the user's UI language), else the system language.
 *  - System language: for text that is stored and shown to several people later (notifications, assignment reasons,
 *    history notes): the language setting chosen by the owner.
 */
class LanguageService
{
    public function systemIsPersian(): bool
    {
        return strcasecmp((string) SystemSetting::current()->language, 'fa') === 0;
    }

    /** True when the current HTTP request asks for Persian (or sends no preference and the system language is Persian). */
    public function requestIsPersian(): bool
    {
        $header = request()->header('Accept-Language');
        if ($header !== null && trim($header) !== '') {
            return str_starts_with(strtolower(trim($header)), 'fa');
        }

        return $this->systemIsPersian();
    }

    /** Text for the current request. */
    public function forRequest(string $text): string
    {
        return $this->requestIsPersian() ? FaTranslator::translate($text) : $text;
    }

    /** Text that will be stored. */
    public function forStorage(string $text): string
    {
        return $this->systemIsPersian() ? FaTranslator::translate($text) : $text;
    }
}
