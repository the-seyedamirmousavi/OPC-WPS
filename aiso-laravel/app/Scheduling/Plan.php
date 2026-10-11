<?php

namespace App\Scheduling;

final class Plan
{
    /** @param array<string, Slot> $slots */
    public function __construct(public readonly array $slots, public readonly float $makespan, public readonly Lanes $lanes)
    {
    }
}
