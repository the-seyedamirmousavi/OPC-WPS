<?php

namespace App\Assignment;

/** @param int $activeTasks ASSIGNED + IN_PROGRESS tasks; @param float $loadHours their total remaining hours */
final class UserInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $fullName,
        public readonly bool $active,
        public readonly int $activeTasks,
        public readonly float $loadHours,
    ) {
    }
}
