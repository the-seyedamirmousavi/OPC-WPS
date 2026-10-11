<?php

namespace App\Enums;

enum OperationStatus: string
{
    case NOT_READY = 'NOT_READY';
    case READY = 'READY';
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case BLOCKED = 'BLOCKED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /** Occupies a resource slot and counts toward the user's active task limit. */
    public function isActive(): bool
    {
        return $this === self::ASSIGNED || $this === self::IN_PROGRESS;
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::CANCELLED;
    }
}
