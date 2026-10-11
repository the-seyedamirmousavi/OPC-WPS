<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\Operation;
use App\Models\PlanBaseline;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\ScheduleForce;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use Carbon\CarbonImmutable;

/**
 * Immutable in-memory snapshot of the project used by the engine, planner and dashboards.
 *
 * @param array<string, Project>          $projects
 * @param array<string, Operation>        $ops
 * @param array<string, WorkResource>     $resources
 * @param array<string, AppUser>          $users
 * @param array<string, \App\Models\Item> $items
 * @param array<string, Predecessor[]>    $predecessors operation id => its predecessor links
 * @param array<string, string[]>         $successors   predecessor id => ids of operations depending on it
 * @param array<string, ScheduleForce>    $forces
 * @param array<string, PlanBaseline>     $baseline
 */
final class ProjectData
{
    public function __construct(
        public readonly SystemSetting $settings,
        public readonly array $projects,
        public readonly array $ops,
        public readonly array $resources,
        public readonly array $users,
        public readonly array $items,
        public readonly array $predecessors,
        public readonly array $successors,
        public readonly array $forces,
        public readonly array $baseline,
        public readonly CarbonImmutable $now,
    ) {
    }

    /** @return Predecessor[] */
    public function predsOf(string $operationId): array
    {
        return $this->predecessors[$operationId] ?? [];
    }

    /** Priority rank of the project owning the operation (1 = most important); a large number if unknown. */
    public function rankOf(Operation $op): int
    {
        $p = $this->projects[$op->project_id] ?? null;

        return $p === null ? 9999 : (int) $p->priority;
    }

    /** The manager FORCE on the operation, or null. */
    public function forceOf(string $operationId): ?ScheduleForce
    {
        return $this->forces[$operationId] ?? null;
    }

    /** The same snapshot with another set of FORCEs (to evaluate a FORCE before it is stored). */
    public function withForces(array $otherForces): self
    {
        return new self($this->settings, $this->projects, $this->ops, $this->resources, $this->users, $this->items,
            $this->predecessors, $this->successors, $otherForces, $this->baseline, $this->now);
    }

    public function projectName(?string $projectId): ?string
    {
        $p = $projectId === null ? null : ($this->projects[$projectId] ?? null);

        return $p === null ? $projectId : $p->name;
    }

    public function userName(?string $userId): ?string
    {
        $u = $userId === null ? null : ($this->users[$userId] ?? null);

        return $u?->full_name;
    }

    public function resourceName(?string $resourceId): ?string
    {
        $r = $resourceId === null ? null : ($this->resources[$resourceId] ?? null);

        return $r === null ? $resourceId : $r->name;
    }
}
