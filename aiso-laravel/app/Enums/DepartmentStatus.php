<?php

namespace App\Enums;

enum DepartmentStatus: string
{
    case ACTIVE = 'ACTIVE';
    /** Kept for its history; it takes no new suggestions or resource changes. */
    case INACTIVE = 'INACTIVE';
}
