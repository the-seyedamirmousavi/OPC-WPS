<?php

namespace App\Scheduling;

/**
 * One operation as the planner sees it.
 *
 * @param bool  $running true if IN_PROGRESS: occupies a lane from t=0 for its remaining hours
 * @param float $hours   remaining duration for running operations, full duration for pending ones
 * @param Dep[] $deps    predecessors
 */
final class Task
{
    /** @param Dep[] $deps */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly int $projectRank,
        public readonly string $resourceId,
        public readonly float $hours,
        public readonly bool $running,
        public readonly array $deps = [],
    ) {
    }
}
