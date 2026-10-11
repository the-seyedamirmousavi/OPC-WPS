<?php

namespace App\Scheduling;

/** A predecessor link: the successor may start once the predecessor has ended (FS) or started (SS). */
final class Dep
{
    public const FINISH_TO_START = 'FINISH_TO_START';
    public const START_TO_START = 'START_TO_START';

    public function __construct(public readonly string $predecessorId, public readonly string $type = self::FINISH_TO_START)
    {
    }
}
