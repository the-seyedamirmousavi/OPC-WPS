<?php

namespace App\Services;

use App\Enums\DependencyType;
use App\Enums\OperationStatus;
use App\Enums\Role;
use App\Models\AppUser;
use App\Models\Operation;
use App\Models\Predecessor;
use Carbon\CarbonImmutable;

/**
 * Dependency (OPC) engine: decides which operations are READY. Rules are explicit and deterministic:
 *  - an operation with no mandatory predecessors is READY;
 *  - FINISH_TO_START needs the predecessor COMPLETED (and approved, when the setting requires it);
 *  - START_TO_START needs the predecessor IN_PROGRESS or COMPLETED;
 *  - a CANCELLED predecessor never satisfies a dependency; the manager has to decide;
 *  - all mandatory predecessors must be satisfied; a single missing one keeps the operation NOT_READY.
 */
class DependencyService
{
    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    public static function satisfied(Predecessor $p, Operation $pred, bool $requireApproval): bool
    {
        if (!$p->mandatory) {
            return true;
        }
        $s = $pred->status;

        return match ($p->dependency_type) {
            DependencyType::FINISH_TO_START => $s === OperationStatus::COMPLETED
                && (!$requireApproval || $pred->completion_approved),
            DependencyType::START_TO_START => $s === OperationStatus::IN_PROGRESS || $s === OperationStatus::COMPLETED,
        };
    }

    /**
     * Predecessors that currently hold the operation back.
     *
     * @return Predecessor[]
     */
    public static function unsatisfied(Operation $op, ProjectData $data): array
    {
        $out = [];
        $requireApproval = (bool) $data->settings->require_completion_approval;
        foreach ($data->predsOf($op->id) as $p) {
            $pred = $data->ops[$p->predecessor_id] ?? null;
            if ($pred === null || !self::satisfied($p, $pred, $requireApproval)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Re-evaluates every NOT_READY / READY operation and moves it to the correct state. Called after any event that can
     * change predecessor state (import, start, completion, cancel, approval).
     *
     * @return string[] ids of operations that became READY during this evaluation
     */
    public function evaluateAll(string $actorId): array
    {
        $data = $this->dataService->load();
        $becameReady = [];
        foreach ($data->ops as $op) {
            $s = $op->status;
            if ($s !== OperationStatus::NOT_READY && $s !== OperationStatus::READY) {
                continue;
            }
            $ready = self::unsatisfied($op, $data) === [];
            if ($ready && $s === OperationStatus::NOT_READY) {
                $this->move($op, OperationStatus::READY, $actorId, 'All mandatory prerequisites satisfied');
                $becameReady[] = $op->id;
            } elseif (!$ready && $s === OperationStatus::READY) {
                $this->move($op, OperationStatus::NOT_READY, $actorId, 'A prerequisite is no longer satisfied');
            }
        }
        if ($becameReady !== []) {
            $this->announceReady($becameReady, $data);
        }

        return $becameReady;
    }

    private function move(Operation $op, OperationStatus $to, string $actorId, string $reason): void
    {
        $from = $op->status;
        $op->status = $to;
        $op->updated_at = CarbonImmutable::now();
        $op->save();
        $this->audit->record($actorId, 'STATUS_CHANGE', 'Operation', $op->id, $from, $to, $reason);
    }

    /** @param string[] $ids */
    private function announceReady(array $ids, ProjectData $data): void
    {
        foreach ($ids as $id) {
            $op = $data->ops[$id];
            $responsible = $op->responsible_user_id
                ?? $data->resources[$op->resource_id]->responsible_user_id;
            /** @var AppUser|null $u */
            $u = $responsible === null ? null : ($data->users[$responsible] ?? null);
            if ($u !== null && $u->isActive() && $u->user_role->isExecutor()) {
                $this->notifications->notifyUser($u->id, 'OPERATION_READY',
                    "Operation {$op->id} ({$op->name}) is ready to be executed.", $op->id);
            }
        }
        $shown = array_slice($ids, 0, 8);
        $list = implode(', ', $shown) . (count($ids) > 8 ? ', ...' : '');
        $this->notifications->notifyManagers('OPERATIONS_READY',
            count($ids) . " operation(s) became READY: {$list}", null);
    }
}
