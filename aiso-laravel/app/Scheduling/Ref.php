<?php

namespace App\Scheduling;

/** Where an operation was in the reference plan (hours from now); committed = it is already assigned. */
final class Ref
{
    public function __construct(public readonly float $start, public readonly bool $committed)
    {
    }
}
