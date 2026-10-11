<?php

namespace App\Assignment;

/**
 * Mutable working copy of the hard constraints. Both strategies use it, so a proposal that is acceptable to one is
 * acceptable to the other:
 *  1. the user must be an active executive user;
 *  2. if the OPC sheet - or a manager FORCE, which takes precedence - fixes an executor, only that user is allowed;
 *  3. the resource must be active and have a free slot (ASSIGNED + IN_PROGRESS < capacity);
 *  4. the user must stay at or below the configured active-task limit, unless a manager FORCE names that user.
 */
final class AssignmentState
{
    /** @var array<string, ReadyOp> */
    private array $ops = [];
    /** @var array<string, UserInfo> */
    private array $users = [];
    /** @var array<string, ResourceInfo> */
    private array $resources = [];
    /** @var array<string, int> */
    private array $resourceUsed = [];
    /** @var array<string, int> */
    private array $userTasks = [];
    /** @var array<string, float> */
    private array $userLoad = [];
    private readonly int $maxActive;

    public function __construct(AssignmentContext $ctx)
    {
        foreach ($ctx->ready as $o) {
            $this->ops[$o->id] = $o;
        }
        foreach ($ctx->users as $u) {
            $this->users[$u->id] = $u;
            $this->userTasks[$u->id] = $u->activeTasks;
            $this->userLoad[$u->id] = $u->loadHours;
        }
        foreach ($ctx->resources as $r) {
            $this->resources[$r->id] = $r;
            $this->resourceUsed[$r->id] = $r->used;
        }
        $this->maxActive = $ctx->maxActiveTasksPerUser;
    }

    /** The reason the pair violates a hard constraint, or null if it is allowed. */
    public function reject(string $operationId, string $userId): ?string
    {
        $op = $this->ops[$operationId] ?? null;
        if ($op === null) {
            return "Operation {$operationId} is not READY";
        }
        $user = $this->users[$userId] ?? null;
        if ($user === null || !$user->active) {
            return "User {$userId} is unknown, inactive or not an executive user";
        }
        if ($op->fixedUserId !== null && $op->fixedUserId !== $userId) {
            return $op->forcedUser
                ? "Operation {$operationId} is forced to executor {$op->fixedUserId} by the manager"
                : "Operation {$operationId} is fixed to executor {$op->fixedUserId}";
        }
        $res = $this->resources[$op->resourceId] ?? null;
        if ($res === null || !$res->active) {
            return "Resource {$op->resourceId} is inactive";
        }
        if ($this->freeSlots($op->resourceId) <= 0) {
            return "Resource {$res->name} is at capacity ({$res->capacity}/{$res->capacity})";
        }
        if ($this->tasksOf($userId) >= $this->maxActive && !$op->forcedUser) {
            return "User {$userId} already has {$this->tasksOf($userId)} active tasks (limit {$this->maxActive})";
        }

        return null;
    }

    public function apply(string $operationId, string $userId): void
    {
        $op = $this->ops[$operationId];
        $this->resourceUsed[$op->resourceId] = ($this->resourceUsed[$op->resourceId] ?? 0) + 1;
        $this->userTasks[$userId] = ($this->userTasks[$userId] ?? 0) + 1;
        $this->userLoad[$userId] = ($this->userLoad[$userId] ?? 0.0) + $op->hours;
    }

    public function freeSlots(string $resourceId): int
    {
        $r = $this->resources[$resourceId] ?? null;

        return $r === null ? 0 : $r->capacity - ($this->resourceUsed[$resourceId] ?? 0);
    }

    public function usedSlots(string $resourceId): int
    {
        return $this->resourceUsed[$resourceId] ?? 0;
    }

    public function tasksOf(string $userId): int
    {
        return $this->userTasks[$userId] ?? 0;
    }

    public function loadOf(string $userId): float
    {
        return $this->userLoad[$userId] ?? 0.0;
    }

    /** @return array<string, ResourceInfo> */
    public function resources(): array
    {
        return $this->resources;
    }
}
