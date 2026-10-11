<?php

namespace App\Enums;

enum Role: string
{
    case OWNER = 'OWNER';
    case MANAGER = 'MANAGER';
    case USER_1 = 'USER_1';
    case USER_2 = 'USER_2';
    case USER_3 = 'USER_3';

    /** Executive users who receive and perform tasks. */
    public function isExecutor(): bool
    {
        return $this === self::USER_1 || $this === self::USER_2 || $this === self::USER_3;
    }
}
