<?php

namespace App\AI\Departments;

use App\Enums\OperationStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Department;
use App\Models\Operation;
use App\Models\OperationReport;
use App\Models\Project;
use App\Models\WorkResource;
use App\Assignment\AlgorithmStrategy;
use App\Services\AssignmentService;
use App\Services\OperationService;
use App\Services\PlanningService;
use App\Services\PlanService;
use App\Services\ProjectDataService;
use App\Services\ProjectService;
use App\Services\SchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Production: operations and projects. Owner and managers only, like the APIs behind the planning pages. */
class ProductionHandler extends BaseHandler
{
    public function __construct(private readonly OperationService $operations, private readonly ProjectService $projects)
    {
    }

    protected function capability(): ?string
    {
        return 'production.view';
    }

    protected function read(string $intent, array $params, AppUser $user, Department $department): array
    {
        return match ($intent) {
            'status_summary' => ['data' => [
                'operations_by_status' => Operation::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
                'projects' => Project::query()->count(),
            ]],
            'list_by_status' => $this->listByStatus($params),
            'project_summary' => self::cut(
                Project::query()->orderBy('priority')->orderBy('id')->limit(self::limit([]) + 1)
                    ->get(['id', 'name', 'priority', 'status', 'due_date'])
                    ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'priority' => $p->priority,
                        'status' => $p->status, 'due_date' => $p->due_date?->toDateString()]),
                self::limit([]),
            ),
            'operation_detail' => ['data' => $this->detail((string) $params['operation_id'])],
            'search' => $this->searchAll((string) $params['query'], self::limit($params)),
            'list_by_user' => $this->byUser($params),
            'user_work_summary' => ['data' => $this->workSummary((string) $params['user_id'])],
            'user_history' => $this->history((string) $params['user_id'], self::limit($params)),
            'late_operations' => $this->late(self::limit($params)),
            'resource_load' => $this->load($params),
            'schedule_conflicts' => $this->conflicts(),
            'project_detail' => ['data' => $this->projectDetail((string) $params['project_id'])],
            'throughput' => ['data' => $this->throughput((string) $params['from'], (string) $params['to'])],
            'forecast' => ['data' => $this->forecast($params)],
        };
    }

    /**
     * When things are planned to happen, from the same scheduling algorithm as the plan page, computed now: one operation's
     * planned start and end, one project's finish, or every active project's finish. Nothing is stored.
     */
    private function forecast(array $params): array
    {
        $data = app(ProjectDataService::class)->load();
        $view = app(PlanService::class)->plan($data);
        $fmt = static fn (?\DateTimeInterface $t) => self::local($t);
        $note = 'Times come from the scheduling algorithm, computed now from the current plan; they move when the plan changes.'
            . ($view->calendar !== null ? ' They follow the working calendar (nights, days off and holidays are skipped).' : ' Time is continuous (no working calendar).');

        if (!empty($params['operation_id'])) {
            $o = Operation::query()->find($params['operation_id']);
            if ($o === null) {
                return ['found' => false];
            }
            $start = $view->start($o->id);

            return [
                'found' => true, 'note' => $note,
                'operation' => [
                    'id' => $o->id, 'name' => $o->name, 'project' => $o->project_id, 'status' => $o->status->value,
                    'resource' => $o->resource_id, 'assigned_to' => $o->assigned_user_id,
                    'planned_start' => $fmt($start ?? $o->planned_start), 'planned_end' => $fmt($view->end($o->id) ?? $o->planned_end),
                    'in_open_plan' => $start !== null,
                    // e.g. its machine is switched off, or it waits for work that is: no date is invented for it
                    'not_planned_because' => $view->unplanned[$o->id] ?? null,
                ],
            ];
        }

        $m = app(SchedulingService::class)->metrics($data, $view);
        $projects = array_map(static fn (array $p) => [
            'id' => $p['projectId'], 'name' => $p['name'], 'rank' => $p['rank'], 'open_operations' => $p['openOperations'],
            'finish_at' => $p['finishAt'] === null ? null : $fmt(CarbonImmutable::parse($p['finishAt'])),
            'due_date' => $p['dueDate'] === null ? null : CarbonImmutable::parse($p['dueDate'])->toDateString(),
            'late_hours' => round((float) $p['tardinessHours'], 1),
        ], $m['projects']);
        if (!empty($params['project_id'])) {
            $projects = array_values(array_filter($projects, static fn (array $p) => $p['id'] === $params['project_id']));
            if ($projects === []) {
                return ['found' => false];
            }
        }

        return ['found' => true, 'note' => $note, 'plan_finish_at' => $m['finishAt'] === null ? null : $fmt(CarbonImmutable::parse($m['finishAt'])),
            'utilization_percent' => round((float) $m['utilizationPercent'], 1), 'projects' => $projects];
    }

    /**
     * Adds one operation to a project through the plan builder's path: the project's current plan plus the new operation is
     * submitted as a draft, so the import rules, the schedule preview and the audit trail are the usual ones. Optionally the
     * assignment algorithm then gives it to the most suitable executor. Answers with when it is planned to start and end.
     */
    private function addOperation(array $params, AppUser $user): array
    {
        // details left out are chosen from the organisation's data (the dialogue engine already did this for the proposal)
        $params = OperationDefaults::fill($params)['args'];
        $projectId = $params['project_id'] ?? null;
        if ($projectId === null) {
            throw ApiException::badRequest('There is no active project to add the operation to');
        }
        // work on a resource that is switched off would never be scheduled
        if (empty($params['resource_id']) || WorkResource::query()->whereKey($params['resource_id'])->value('status') !== 'ACTIVE') {
            throw ApiException::badRequest("Resource {$params['resource_id']} is not active; choose an active resource");
        }
        $planning = app(PlanningService::class);
        $snap = $planning->snapshot($projectId);
        if ($snap['projectId'] !== $projectId) {
            throw ApiException::badRequest("Project {$projectId} is not active");
        }

        $n = Operation::query()->count() + 1;
        do {
            $id = sprintf('OP-%03d', $n++);
        } while (Operation::query()->whereKey($id)->exists());

        $after = array_values(array_filter(array_map('trim', explode(',', (string) ($params['after'] ?? '')))));
        $preds = $snap['predecessors'];
        foreach ($after as $p) {
            $preds[] = ['operationId' => $id, 'predecessorId' => $p, 'type' => 'FINISH_TO_START', 'mandatory' => true];
        }
        $ops = $snap['operations'];
        $ops[] = [
            'id' => $id, 'name' => $params['name'], 'resourceId' => $params['resource_id'], 'userId' => $params['user_id'] ?? null,
            'prep' => 0, 'transport' => 0, 'setup' => (float) ($params['setup_hours'] ?? 0), 'direct' => (float) $params['direct_hours'],
            'description' => $params['description'] ?? null,
        ];
        $result = $planning->submit(['projectId' => $projectId, 'resources' => $snap['resources'], 'operations' => $ops, 'predecessors' => $preds], true, $user);
        if (($result['status'] ?? null) !== 'APPLIED') {
            $why = array_map(static fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e, JSON_UNESCAPED_UNICODE)) : (string) $e, array_slice($result['errors'] ?? [], 0, 5));
            throw ApiException::badRequest('The operation was not added: ' . ($why === [] ? ($result['status'] ?? 'rejected') : implode('; ', $why)));
        }

        $assigned = null;
        $notAssigned = null;
        if (!empty($params['auto_assign'])) {
            [$assigned, $notAssigned] = $this->autoAssign($id, $user);
        }
        $o = Operation::query()->findOrFail($id);
        $forecast = $this->forecast(['operation_id' => $id])['operation'];

        return [
            'operation_id' => $id, 'project_id' => $projectId, 'name' => $o->name, 'status' => $o->status->value,
            'assigned_to' => $o->assigned_user_id, 'assignment_reason' => $assigned, 'not_assigned_because' => $notAssigned,
            'planned_start' => $forecast['planned_start'], 'planned_end' => $forecast['planned_end'],
        ];
    }

    /**
     * Lets the assignment algorithm choose an executor for one operation, without touching other pending proposals.
     *
     * @return array{0: ?string, 1: ?string} the reason it was assigned, or why it was not
     */
    private function autoAssign(string $id, AppUser $user): array
    {
        $data = app(ProjectDataService::class)->load();
        $plan = app(PlanService::class)->plan($data);
        $assignments = app(AssignmentService::class);
        $s = app(AlgorithmStrategy::class)->suggest($assignments->buildContext($data, $plan));
        foreach ($s->picks as $p) {
            if ($p->operationId === $id) {
                $assignments->manualAssign($id, $p->userId, 'Chosen by the assignment algorithm for the assistant: ' . $p->reason, $user);

                return [$p->reason, null];
            }
        }
        foreach ($s->skips as $k) {
            if ($k->operationId === $id) {
                return [null, $k->reason];
            }
        }

        return [null, 'The operation is not ready yet (it waits for its predecessors) or no executor is free'];
    }

    private const OPEN = ['NOT_READY', 'READY', 'ASSIGNED', 'IN_PROGRESS', 'BLOCKED'];

    /** The operations of one person, by planned end. Owner only (the intent carries the capability). */
    private function byUser(array $params): array
    {
        $limit = self::limit($params);
        $q = Operation::query()->where('assigned_user_id', $params['user_id'])->orderBy('planned_end')->orderBy('id');
        if (!empty($params['status'])) {
            $q->where('status', $params['status']);
        }

        return self::cut(
            $q->limit($limit + 1)->get()->map(fn (Operation $o) => [
                'id' => $o->id, 'name' => $o->name, 'project' => $o->project_id, 'resource' => $o->resource_id,
                'status' => $o->status->value, 'progress_percent' => $o->progress_percent,
                'planned_end' => self::local($o->planned_end),
            ]),
            $limit,
        );
    }

    /**
     * A person's work record. The hours are the planned hours of the finished work, and the elapsed time between start and
     * completion. The system does not record hours actually worked: the note says so, and nothing here is a ranking.
     */
    private function workSummary(string $userId): array
    {
        $user = AppUser::query()->find($userId);
        if ($user === null) {
            return ['found' => false];
        }
        $ops = Operation::query()->where('assigned_user_id', $userId);
        $byStatus = (clone $ops)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $done = (clone $ops)->where('status', 'COMPLETED')->get()->sortByDesc(fn (Operation $o) => $o->completed_at?->getTimestamp() ?? 0)->values();
        $elapsed = $done->filter(fn (Operation $o) => $o->started_at !== null && $o->completed_at !== null)
            ->map(fn (Operation $o) => abs($o->completed_at->diffInSeconds($o->started_at)) / 3600);
        $last = $done->first(fn (Operation $o) => $o->completed_at !== null);

        return [
            'found' => true,
            'user_id' => $userId,
            'name' => $user->full_name,
            'by_status' => $byStatus,
            'completed' => $done->count(),
            'planned_hours_completed' => round((float) $done->sum(fn (Operation $o) => $o->totalHours()), 2),
            'elapsed_hours_completed' => round((float) $elapsed->sum(), 2),
            'elapsed_samples' => $elapsed->count(),
            'last_completed' => $last === null ? null : ['id' => $last->id, 'name' => $last->name, 'completed_at' => self::local($last->completed_at)],
            'note' => 'ساعت واقعی کار ثبت نمی‌شود. elapsed_hours_completed فاصلهٔ زمانی بین شروع و تکمیل است، نه ساعت کار.',
        ];
    }

    /** Assignments and the reports of one person, newest first. Owner only. */
    private function history(string $userId, int $limit): array
    {
        $events = [];
        foreach (Operation::query()->where('assigned_user_id', $userId)->whereNotNull('assigned_at')->get() as $o) {
            $events[] = ['at' => $o->assigned_at, 'operation' => $o->id, 'name' => $o->name, 'event' => 'ASSIGNED', 'progress' => null, 'note' => null];
        }
        foreach (OperationReport::query()->where('user_id', $userId)->get() as $r) {
            $events[] = ['at' => $r->created_at, 'operation' => $r->operation_id, 'name' => null,
                'event' => $r->report_type->value ?? (string) $r->report_type, 'progress' => $r->progress_percent,
                'note' => $r->note === null ? null : mb_substr($r->note, 0, 200)];
        }
        usort($events, fn (array $a, array $b) => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return self::cut(array_map(fn (array $e) => $e + ['at' => self::local($e['at'])], $events), $limit);
    }

    /** Open operations that finish after the project's due date, or after today when still open; largest delay first. */
    private function late(int $limit): array
    {
        $now = CarbonImmutable::now();
        $rows = [];
        $open = Operation::query()->whereIn('status', self::OPEN)->whereNotNull('planned_end')->get();
        $dues = Project::query()->pluck('due_date', 'id');
        foreach ($open as $o) {
            $due = $dues[$o->project_id] ?? null;
            $dueAt = $due === null ? null : ($due instanceof \DateTimeInterface ? CarbonImmutable::instance($due) : CarbonImmutable::parse($due));
            $end = CarbonImmutable::instance($o->planned_end);
            $ref = $dueAt !== null && $end->greaterThan($dueAt) ? $dueAt : ($end->lessThan($now) ? $now : null);
            if ($ref === null) {
                continue;
            }
            $rows[] = ['id' => $o->id, 'name' => $o->name, 'project' => $o->project_id, 'status' => $o->status->value,
                'planned_end' => self::local($end), 'project_due' => $dueAt?->toDateString(),
                'delay_days' => round(abs($end->diffInSeconds($ref)) / 86400, 1)];
        }
        usort($rows, fn (array $a, array $b) => $b['delay_days'] <=> $a['delay_days']);

        return self::cut($rows, $limit);
    }

    /** Open operations per resource: how many, and the planned hours they hold, against the capacity. */
    private function load(array $params): array
    {
        $resources = WorkResource::query()->when(!empty($params['resource_id']), fn ($q) => $q->where('id', $params['resource_id']))
            ->orderBy('sort_order')->orderBy('id')->get();
        $open = Operation::query()->whereIn('status', self::OPEN)->get()->groupBy('resource_id');
        $rows = $resources->map(function (WorkResource $r) use ($open) {
            $list = $open->get($r->id, collect());

            return ['id' => $r->id, 'name' => $r->name, 'capacity' => (int) $r->capacity, 'status' => $r->status,
                'open_operations' => $list->count(), 'planned_hours_open' => round((float) $list->sum(fn (Operation $o) => $o->totalHours()), 2)];
        })->values()->all();

        return self::cut($rows, self::limit($params));
    }

    /** Resources where, at some moment, more operations are planned than the resource has lanes. */
    private function conflicts(): array
    {
        $ops = Operation::query()->whereIn('status', self::OPEN)->whereNotNull('planned_start')->whereNotNull('planned_end')
            ->orderBy('planned_start')->get()->groupBy('resource_id');
        $capacity = WorkResource::query()->pluck('capacity', 'id');
        $out = [];
        foreach ($ops as $resourceId => $list) {
            $cap = (int) ($capacity[$resourceId] ?? 1);
            $peak = 0;
            $peakOps = [];
            foreach ($list as $a) {
                $at = $a->planned_start;
                $here = $list->filter(fn (Operation $b) => $b->planned_start <= $at && $b->planned_end > $at)->values();
                if ($here->count() > $peak) {
                    $peak = $here->count();
                    $peakOps = $here->pluck('id')->all();
                }
            }
            if ($peak > $cap) {
                $out[] = ['resource' => (string) $resourceId, 'capacity' => $cap, 'max_concurrent' => $peak, 'operations' => $peakOps];
            }
        }

        return self::cut($out, 50);
    }

    private function projectDetail(string $projectId): array
    {
        $p = Project::query()->find($projectId);
        if ($p === null) {
            return ['found' => false];
        }
        $ops = Operation::query()->where('project_id', $projectId)->get();
        $total = $ops->count();
        $done = $ops->where('status', OperationStatus::COMPLETED)->count();
        $late = $p->due_date === null ? 0 : $ops->filter(fn (Operation $o) => in_array($o->status->value, self::OPEN, true)
            && $o->planned_end !== null && CarbonImmutable::instance($o->planned_end)->greaterThan($p->due_date))->count();

        return [
            'found' => true, 'id' => $p->id, 'name' => $p->name, 'priority' => (int) $p->priority,
            'status' => $p->status->value ?? (string) $p->status, 'due_date' => $p->due_date?->toDateString(),
            'operations' => $total, 'by_status' => $ops->countBy(fn (Operation $o) => $o->status->value)->all(),
            'progress_percent' => $total === 0 ? 0.0 : round($done / $total * 100, 1), 'late_open_operations' => $late,
        ];
    }

    private function throughput(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        $count = Operation::query()->where('status', 'COMPLETED')->whereBetween('completed_at', [$start, $end])->count();

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'count' => $count];
    }

    /** Operations and projects whose name, id or description fits the query. */
    private function searchAll(string $query, int $limit): array
    {
        $candidates = [];
        foreach (Operation::query()->limit(500)->get(['id', 'name', 'status', 'description']) as $o) {
            $candidates[] = ['id' => $o->id, 'text' => "{$o->id} {$o->name} {$o->description}",
                'row' => ['type' => 'operation', 'id' => $o->id, 'name' => $o->name, 'status' => $o->status->value]];
        }
        foreach (Project::query()->limit(200)->get(['id', 'name', 'status', 'description']) as $p) {
            $candidates[] = ['id' => $p->id, 'text' => "{$p->id} {$p->name} {$p->description}",
                'row' => ['type' => 'project', 'id' => $p->id, 'name' => $p->name, 'status' => (string) ($p->status->value ?? $p->status)]];
        }

        return self::search($query, $candidates, $limit);
    }

    private function listByStatus(array $params): array
    {
        $limit = self::limit($params);

        return self::cut(
            Operation::query()->where('status', $params['status'])->orderBy('id')->limit($limit + 1)
                ->get(['id', 'name', 'project_id', 'resource_id', 'assigned_user_id', 'progress_percent', 'planned_end'])
                ->map(fn (Operation $o) => [
                    'id' => $o->id, 'name' => $o->name, 'project' => $o->project_id, 'resource' => $o->resource_id,
                    'assigned_to' => $o->assigned_user_id, 'progress_percent' => $o->progress_percent,
                    'planned_end' => self::local($o->planned_end),
                ]),
            $limit,
        );
    }

    private function detail(string $id): array
    {
        $o = Operation::query()->find($id);

        return $o === null ? ['found' => false] : [
            'found' => true, 'id' => $o->id, 'name' => $o->name, 'status' => $o->status->value, 'project' => $o->project_id,
            'resource' => $o->resource_id, 'assigned_to' => $o->assigned_user_id, 'progress_percent' => $o->progress_percent,
            'block_reason' => $o->block_reason, 'planned_end' => self::local($o->planned_end),
        ];
    }

    /** Each change goes through OperationService, which checks the current state of the operation again. */
    protected function run(string $operation, array $params, AppUser $user, Department $department): array
    {
        $agent = $this->agentOperation($operation, $params, $user);
        if ($agent !== null) {
            return $agent;
        }
        if ($operation === 'update_project') {
            return DB::transaction(fn () => $this->updateProject($params, $user));
        }
        if ($operation === 'add_operation') {
            return $this->addOperation($params, $user);
        }
        if ($operation === 'import_plan') {
            return app(\App\Services\ChatImportService::class)->execute($params, $user);
        }
        $id = (string) $params['operation_id'];

        return DB::transaction(function () use ($operation, $params, $user, $id) {
            match ($operation) {
                'unblock_operation' => $this->operations->unblock($id, $params['note'] ?? null, $user),
                'approve_completion' => $this->operations->approve($id, $params['note'] ?? null, $user),
                'cancel_operation' => $this->operations->cancel($id, $params['reason'], $user),
                'unassign_operation' => $this->operations->unassign($id, $user),
                'comment_on_operation' => $this->operations->comment($id, $params['note'], $user),
                'assign_operation' => $this->assign($id, $params, $user),
                'reschedule_operation' => $this->operations->reschedule($id, new \DateTimeImmutable($params['planned_start']),
                    new \DateTimeImmutable($params['planned_end']), $user->id, $params['reason'] ?? null),
                default => throw ApiException::badRequest("Operation {$operation} is not available here"),
            };

            return ['operation_id' => $id, 'status' => Operation::query()->find($id)?->status->value];
        });
    }

    /** Assigning keeps the dates the operation already has unless new ones are given. */
    private function assign(string $id, array $params, AppUser $user): void
    {
        $op = Operation::query()->find($id);
        $start = isset($params['planned_start']) ? new \DateTimeImmutable($params['planned_start']) : $op?->planned_start;
        $end = isset($params['planned_end']) ? new \DateTimeImmutable($params['planned_end']) : $op?->planned_end;
        $this->operations->assign($id, $params['user_id'], $start, $end, $user->id, $params['note'] ?? 'Assigned by the assistant');
    }

    private function updateProject(array $params, AppUser $user): array
    {
        $this->projects->update($params['project_id'], $params['name'] ?? null, $params['due_date'] ?? null, false,
            isset($params['status']) ? ProjectStatus::from($params['status']) : null, $user);

        return ['project_id' => $params['project_id'], 'status' => Project::query()->find($params['project_id'])?->status->value];
    }
}
