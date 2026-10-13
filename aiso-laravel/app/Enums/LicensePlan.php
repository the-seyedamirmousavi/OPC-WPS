<?php

namespace App\Enums;

/** How long the software may be used. UNLIMITED never expires; YEARLY is one year; TRIAL is 30 days; CUSTOM is a number of days. */
enum LicensePlan: string
{
    case UNLIMITED = 'UNLIMITED';
    case YEARLY = 'YEARLY';
    case TRIAL = 'TRIAL';
    case CUSTOM = 'CUSTOM';

    public const TRIAL_DAYS = 30;
}
