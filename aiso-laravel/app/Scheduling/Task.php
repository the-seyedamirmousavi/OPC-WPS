<?php

namespace App\Scheduling;

/**
 * One operation as the planner sees it.
 *
 * @param bool  $running true if IN_PROGRESS: occupies a lane from t=0 for its remaining hours
 * @param float $hours   remaining duration for running operations, full duration for pending ones
 * @param Dep[] $deps    predecessors
 * @param bool  $held    true if ASSIGNED: an executor already holds it, so it keeps its place on the resource ahead of
 *                       unassigned work (a higher-priority operation cannot be given out while it holds the lane)
 * @param float $release earliest start in hours from now (e.g. blocked work waits for the block to be lifted)
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
        public readonly bool $held = false,
        public readonly float $release = 0.0,
    ) {
    }
}
