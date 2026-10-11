<?php

namespace App\Enums;

enum DependencyType: string
{
    /** Successor may start once the predecessor is completed. */
    case FINISH_TO_START = 'FINISH_TO_START';
    /** Successor may start once the predecessor has started (or completed). */
    case START_TO_START = 'START_TO_START';
}
