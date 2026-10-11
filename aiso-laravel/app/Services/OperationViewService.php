<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Exceptions\ApiException;
use App\Models\Operation;
use App\Models\OperationReport;
use App\Models\Predecessor;
use Carbon\CarbonImmutable;

/** Read models of operations for the API: the operation list, one operation with its history, the user's own tasks. */
class OperationViewService
{
    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly PlanService $planService,
    ) {
    }

    /** @return array[] */
    public function all(): array
    {
        $data = $this->dataService->load();
        $plan = $this->planService->plan($data);

        return array_values(array_map(fn (Operation $o) => $this->toView($o, $data, $plan), $data->ops));
    }

    /** @return array[] */
    public function forUser(string $userId): array
    {
        return array_values(array_filter($this->all(), static fn (array $v) => $userId === $v['assignedUserId']));
    }

    public function detail(string $id): array
    {
        $data = $this->dataService->load();
        $op = $data->ops[$id] ?? null;
        if ($op === null) {
            throw ApiException::notFound("Operation {$id} not found");
        }
        $plan = $this->planService->plan($data);

        $reports = [];
        foreach (OperationReport::query()->where('operation_id', $id)->orderBy('created_at')->orderBy('id')->get() as $r) {
            $reports[] = [
                'id' => $r->id,
                'userId' => $r->user_id,
                'userName' => $data->userName($r->user_id),
                'type' => $r->report_type->value,
                'progressPercent' => $r->progress_percent,
                'note' => $r->note,
                'createdAt' => self::iso($r->created_at),
            ];
        }

        $succ = [];
        foreach ($data->successors[$id] ?? [] as $sid) {
            $s = $data->ops[$sid] ?? null;
            $edge = null;
            foreach ($data->predsOf($sid) as $p) {
                if ($p->predecessor_id === $id) {
                    $edge = $p;
                    break;
                }
            }
            if ($s !== null && $edge !== null) {
                $succ[] = [
                    'id' => $s->id,
                    'name' => $s->name,
                    'status' => $s->status->value,
                    'type' => $edge->dependency_type->value,
                    'mandatory' => (bool) $edge->mandatory,
                    'satisfied' => DependencyService::satisfied($edge, $op, (bool) $data->settings->require_completion_approval),
                ];
            }
        }

        return [
            'operation' => $this->toView($op, $data, $plan),
            'reports' => $reports,
            'successors' => $succ,
        ];
    }

    public function toView(Operation $o, ProjectData $data, PlanView $plan): array
    {
        $requireApproval = (bool) $data->settings->require_completion_approval;
        $preds = [];
        $waiting = [];
        foreach ($data->predsOf($o->id) as $p) {
            $pred = $data->ops[$p->predecessor_id] ?? null;
            $ok = $pred !== null && DependencyService::satisfied($p, $pred, $requireApproval);
            $pv = self::predView($p, $pred, $ok);
            $preds[] = $pv;
            if (!$ok) {
                $waiting[] = $pv;
            }
        }
        $item = $o->item_id === null ? null : ($data->items[$o->item_id] ?? null);
        $assigned = $o->assigned_user_id === null ? null : ($data->users[$o->assigned_user_id] ?? null);
        $st = $o->status;
        $force = $data->forceOf($o->id);
        $now = $data->now;
        $delayed = !$st->isTerminal() && $o->planned_end !== null && $now->greaterThan($o->planned_end);
        $delayHours = $delayed ? ($now->getTimestamp() - $o->planned_end->getTimestamp()) / 3600.0 : 0.0;
        $late = $st === OperationStatus::COMPLETED && $o->planned_end !== null && $o->completed_at !== null
            && $o->completed_at->greaterThan($o->planned_end);

        return [
            'id' => $o->id,
            'name' => $o->name,
            'projectId' => $o->project_id,
            'projectName' => $data->projectName($o->project_id),
            'projectRank' => $data->rankOf($o),
            'itemId' => $o->item_id,
            'itemName' => $item?->name,
            'resourceId' => $o->resource_id,
            'resourceName' => $data->resourceName($o->resource_id),
            'responsibleUserId' => $o->responsible_user_id,
            'assignedUserId' => $o->assigned_user_id,
            'assignedUserName' => $assigned?->full_name,
            'status' => $st->value,
            'totalHours' => $o->totalHours(),
            'directHours' => (float) $o->direct_time,
            'progressPercent' => (int) $o->progress_percent,
            'plannedStart' => self::iso($o->planned_start),
            'plannedEnd' => self::iso($o->planned_end),
            'projectedStart' => self::iso($plan->start($o->id)),
            'projectedEnd' => self::iso($plan->end($o->id)),
            'assignedAt' => self::iso($o->assigned_at),
            'startedAt' => self::iso($o->started_at),
            'completedAt' => self::iso($o->completed_at),
            'completionApproved' => (bool) $o->completion_approved,
            'blockReason' => $o->block_reason,
            'cancelReason' => $o->cancel_reason,
            'predecessors' => $preds,
            'waitingFor' => $st === OperationStatus::NOT_READY ? $waiting : [],
            'delayed' => $delayed,
            'delayHours' => $delayHours,
            'late' => $late,
            'tailHours' => $plan->tailHours[$o->id] ?? 0.0,
            'description' => $o->description,
            'forcedStart' => self::iso($force?->forced_start),
            'forcedUserId' => $force?->forced_user_id,
            'forceState' => ForceService::stateOf($force, $o, $plan, $data),
        ];
    }

    private static function predView(Predecessor $p, ?Operation $pred, bool $ok): array
    {
        return [
            'id' => $p->predecessor_id,
            'name' => $pred === null ? '?' : $pred->name,
            'status' => $pred?->status->value,
            'type' => $p->dependency_type->value,
            'mandatory' => (bool) $p->mandatory,
            'satisfied' => $ok,
        ];
    }

    private static function iso(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : CarbonImmutable::instance($t)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
