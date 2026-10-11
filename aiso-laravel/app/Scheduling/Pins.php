<?php

namespace App\Scheduling;

/**
 * Manager FORCE start times and the reference plan that keeps the schedule stable (all in hours from now).
 *
 * @param array<string, float> $forcedStart oldest FORCE first: when FORCEs contradict each other, the younger is given up
 * @param array<string, Ref>   $reference
 */
final class Pins
{
    public function __construct(public readonly array $forcedStart = [], public readonly array $reference = [])
    {
    }

    public static function none(): self
    {
        return new self();
    }
}
