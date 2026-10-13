<?php

namespace App\Enums;

enum SuggestionStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';

    public function isOpen(): bool
    {
        return $this === self::PENDING;
    }
}
