<?php

namespace App\Assignment;

/**
 * A READY operation.
 *
 * @param float   $tailHours   longest remaining chain of work that depends on it, itself included
 * @param ?string $fixedUserId executor fixed in the OPC sheet, or by a manager FORCE; a hard constraint when set
 * @param bool    $forcedUser  true when $fixedUserId comes from a manager FORCE (it then also overrides the task limit)
 */
final class ReadyOp
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $itemName,
        public readonly string $resourceId,
        public readonly string $resourceName,
        public readonly float $hours,
        public readonly float $tailHours,
        public readonly ?string $fixedUserId,
        public readonly string $projectId,
        public readonly string $projectName,
        public readonly int $projectRank,
        public readonly float $plannedStartHours,
        public readonly bool $forcedUser = false,
    ) {
    }
}
