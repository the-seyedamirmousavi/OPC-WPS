<?php

namespace App\Enums;

enum ProjectStatus: string
{
    /** Scheduled together with the other active projects. */
    case ACTIVE = 'ACTIVE';
    /** Finished and put away; only possible when every operation is completed or cancelled. */
    case ARCHIVED = 'ARCHIVED';
}
