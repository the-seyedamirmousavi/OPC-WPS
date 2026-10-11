<?php

namespace App\Scheduling;

/** Start and end of a placed operation, in hours from now. */
final class Slot
{
    public function __construct(public readonly float $start, public readonly float $end)
    {
    }
}
