<?php

namespace App\Assignment;

/** Immutable input shared by every assignment strategy. */
final class AssignmentContext
{
    /**
     * @param ReadyOp[]      $ready
     * @param UserInfo[]     $users
     * @param ResourceInfo[] $resources
     */
    public function __construct(
        public readonly array $ready,
        public readonly array $users,
        public readonly array $resources,
        public readonly int $maxActiveTasksPerUser,
        public readonly float $responsibleBonusHours,
    ) {
    }
}
