<?php

namespace App\Services;

use App\Enums\PlanningGoal;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Imports\ImportService;
use App\Imports\ParsedData;
use App\Models\AppUser;
use App\Models\Item;
use App\Models\Operation;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use Illuminate\Support\Facades\DB;

/**
 * Planning without an Excel file. The screen loads the current plan, the manager edits it as a draft (projects,
 * resources, operations, dependencies) and submits it. The draft becomes the same typed data a workbook would produce
 * and goes through the import pipeline, so every rule (references, cycles, started operations, limits), the schedule
 * preview and the audit trail are the ones Excel imports already use.
 */
class PlanningService
{
    private const ID = '/\A[A-Za-z0-9_.\-]{1,64}\z/';

    public function __construct(
        private readonly ImportService $importer,
        private readonly ProjectDataService $dataService,
        private readonly PlanService $plans,
        private readonly SchedulingService $scheduling,
        private readonly AuditService $audit,
    ) {
    }

    // ---- planning goal --------------------------------------------------------------------------------------------

    /**
     * The current goal and what each goal would give on the saved data: length of the plan, idle resource time and the
     * finish of every project. Nothing is stored. $focus tries another focus project for the time goal.
     */
    public function goals(?string $focus): array
    {
        $data = $this->dataService->load();
        $active = array_values(array_filter($data->projects, static fn (Project $p) => $p->status === ProjectStatus::ACTIVE));
        usort($active, static fn (Project $a, Project $b): int => ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id));
        $ids = array_map(static fn (Project $p) => $p->id, $active);
        $stored = $data->settings->planning_focus_project;
        $focus = $focus !== null && in_array($focus, $ids, true) ? $focus
            : ($stored !== null && in_array($stored, $ids, true) ? $stored : ($ids[0] ?? null));

        $options = [];
        foreach (PlanningGoal::cases() as $g) {
            $m = $this->scheduling->metrics($data, $this->plans->plan($data, null, $g, $focus));
            $options[] = [
                'goal' => $g->value,
                'makespanHours' => $m['makespanHours'],
                'busyHours' => $m['busyHours'],
                'wasteHours' => $m['wasteHours'],
                'utilizationPercent' => $m['utilizationPercent'],
                'projects' => array_map(static fn (array $p) => ['projectId' => $p['projectId'], 'finishHours' => $p['finishHours']], $m['projects']),
            ];
        }

        return [
            'goal' => PlanService::goalOf($data)->value,
            'focusProjectId' => $focus,
            'projects' => array_map(static fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'priority' => (int) $p->priority], $active),
            'options' => $options,
        ];
    }

    /** Stores the planning goal. The time goal needs the active project that must finish first. */
    public function setGoal(string $goal, ?string $focus, AppUser $actor): array
    {
        $g = PlanningGoal::tryFrom(strtoupper(trim($goal)));
        if ($g === null) {
            throw ApiException::badRequest('goal must be PRIORITY, RESOURCES or TIME');
        }
        $focus = $focus === null || trim($focus) === '' ? null : trim($focus);
        if ($g === PlanningGoal::TIME) {
            $p = $focus === null ? null : Project::query()->find($focus);
            if ($p === null || $p->status !== ProjectStatus::ACTIVE) {
                throw ApiException::badRequest('Choose the active project that must finish first');
            }
        }

        DB::transaction(function () use ($g, $focus, $actor) {
            $s = SystemSetting::current();
            $before = $s->planning_goal->value . ($s->planning_focus_project === null ? '' : ' ' . $s->planning_focus_project);
            $s->planning_goal = $g;
            if ($g === PlanningGoal::TIME) {
                $s->planning_focus_project = $focus;
            }
            AdminService::saveChanged($s);
            $after = $g->value . ($g === PlanningGoal::TIME ? ' ' . $focus : '');
            $this->audit->record($actor->id, 'PLANNING_GOAL_CHANGED', 'Settings', '1', $before, $after, null);
        });

        return $this->goals($focus);
    }

    // ---- Excel inside the plan builder ----------------------------------------------------------------------------

    /**
     * Reads a workbook for the plan builder. The file goes through the same checks as an import into the chosen
     * project (a dry run: nothing is saved). When it passes, `draft` holds its resources, operations and dependencies
     * to fill into the screen; otherwise `draft` is null and the problems are listed.
     */
    public function fromWorkbook(string $path, ?string $fileName, array $p, AppUser $actor): array
    {
        $d = $this->importer->parseWorkbook($path);
        $result = $this->importer->processParsed($d, $fileName, false, $actor, $this->target($p));
        $result['draft'] = null;
        if (!in_array($result['status'], ['VALID', 'PRIORITY_REQUIRED'], true)) {
            return $result;
        }

        $knownItems = array_fill_keys(Item::query()->pluck('id')->all(), true);
        $result['draft'] = [
            'resources' => array_map(static fn (array $r) => [
                'id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'userId' => $r['userId'],
                'capacity' => $r['capacity'], 'status' => $r['status'], 'description' => $r['description'],
            ], $d->resources),
            'operations' => array_map(static fn (array $o) => [
                'id' => $o['id'], 'name' => $o['name'],
                // the plan builder has no BOM: an item that is not saved yet cannot be kept
                'itemId' => $o['itemId'] !== null && isset($knownItems[$o['itemId']]) ? $o['itemId'] : null,
                'resourceId' => $o['resourceId'] ?? '', 'userId' => $o['userId'],
                'prep' => $o['prep'], 'transport' => $o['transport'], 'setup' => $o['setup'], 'direct' => $o['direct'],
                'description' => $o['description'],
            ], $d->ops),
            'predecessors' => array_map(static fn (array $x) => [
                'operationId' => $x['operationId'], 'predecessorId' => $x['predecessorId'],
                'type' => $x['type'], 'mandatory' => $x['mandatory'],
            ], $d->preds),
        ];
        if ($d->items !== [] || $d->users !== []) {
            $result['warnings'][] = 'The file also has BOM items or users. The plan builder does not read them, so they were left out.';
        }

        return $result;
    }

    /** The current plan of one project (default: the highest-priority active one) as editable data. */
    public function snapshot(?string $projectId): array
    {
        $active = Project::query()->where('status', ProjectStatus::ACTIVE->value)->orderBy('priority')->orderBy('id')->get();
        $project = $projectId !== null && $projectId !== ''
            ? $active->firstWhere('id', $projectId)
            : $active->first();

        $ops = $project === null ? collect() : Operation::query()->where('project_id', $project->id)->orderBy('id')->get();
        $opIds = $ops->pluck('id')->all();
        $preds = $opIds === [] ? collect() : Predecessor::query()->whereIn('operation_id', $opIds)->orderBy('id')->get();

        return [
            'projects' => $active->map(fn (Project $p) => [
                'id' => $p->id, 'name' => $p->name, 'priority' => (int) $p->priority,
                'dueDate' => $p->due_date?->utc()->format('Y-m-d'),
            ])->values()->all(),
            'projectId' => $project?->id,
            'resources' => WorkResource::inEnteredOrder()->get()->map(fn (WorkResource $r) => [
                'id' => $r->id, 'name' => $r->name, 'type' => $r->resource_type, 'userId' => $r->responsible_user_id,
                'capacity' => (int) $r->capacity, 'status' => $r->status, 'description' => $r->description,
            ])->values()->all(),
            'operations' => $ops->map(fn (Operation $o) => [
                'id' => $o->id, 'name' => $o->name, 'itemId' => $o->item_id, 'resourceId' => $o->resource_id,
                'userId' => $o->responsible_user_id, 'prep' => (float) $o->preparation_time,
                'transport' => (float) $o->transport_time, 'setup' => (float) $o->setup_time, 'direct' => (float) $o->direct_time,
                'description' => $o->description, 'status' => $o->status->value,
                // started or finished operations keep their resource and predecessors (the import rules refuse changes)
                'locked' => !in_array($o->status->value, ['NOT_READY', 'READY'], true),
            ])->values()->all(),
            'predecessors' => $preds->map(fn (Predecessor $p) => [
                'operationId' => $p->operation_id, 'predecessorId' => $p->predecessor_id,
                'type' => $p->dependency_type->value, 'mandatory' => (bool) $p->mandatory,
            ])->values()->all(),
            'executors' => AppUser::query()->where('active', true)->get()
                ->filter(fn (AppUser $u) => $u->receivesTasks())
                ->map(fn (AppUser $u) => ['id' => $u->id, 'fullName' => $u->full_name])->sortBy('id')->values()->all(),
        ];
    }

    /**
     * Checks (apply = false) or saves (apply = true) the draft. A new project joining active ones goes to the lowest
     * priority; the manager can reorder projects afterwards.
     *
     * @param array<string, mixed> $p the draft: projectId | newProjectName (+ newProjectId, dueDate), resources, operations, predecessors
     */
    public function submit(array $p, bool $apply, AppUser $actor): array
    {
        $d = new ParsedData();
        $d->format = 'FORM';
        $d->hasPredecessorSheet = true;

        foreach (array_values($p['resources'] ?? []) as $i => $r) {
            $row = $i + 2;
            $id = $this->id($d, $d->resourceSheet, $row, 'Resource_ID', $r['id'] ?? null);
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                $d->error($d->resourceSheet, $row, 'Resource_Name', 'Resource_Name must not be blank');
            }
            $capacity = (int) ($r['capacity'] ?? 1);
            if ($capacity < 1) {
                $d->error($d->resourceSheet, $row, 'Capacity', 'Capacity must be a whole number >= 1');
            }
            $status = strtoupper((string) ($r['status'] ?? 'ACTIVE'));
            if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                $d->error($d->resourceSheet, $row, 'Status', 'Status must be ACTIVE or INACTIVE');
            }
            $d->resources[] = [
                'row' => $row, 'id' => $id, 'name' => $name, 'type' => self::text($r['type'] ?? null),
                'userId' => self::text($r['userId'] ?? null), 'capacity' => max(1, $capacity), 'status' => $status,
                'description' => self::text($r['description'] ?? null),
            ];
        }

        foreach (array_values($p['operations'] ?? []) as $i => $o) {
            $row = $i + 2;
            $id = $this->id($d, $d->opSheet, $row, 'Operation_ID', $o['id'] ?? null);
            $name = trim((string) ($o['name'] ?? ''));
            if ($name === '') {
                $d->error($d->opSheet, $row, 'Operation_Name', 'Operation_Name must not be blank');
            }
            $resourceId = self::text($o['resourceId'] ?? null);
            if ($resourceId === null) {
                $d->error($d->opSheet, $row, 'Resource_ID', 'Resource_ID must not be blank');
            }
            $times = [];
            foreach (['prep' => 'Preparation_Time', 'transport' => 'Transport_Time', 'setup' => 'Setup_Time', 'direct' => 'Direct_Time'] as $k => $col) {
                $v = $o[$k] ?? 0;
                if (!is_numeric($v) || (float) $v < 0) {
                    $d->error($d->opSheet, $row, $col, "{$col} must be a number of hours, zero or more");
                    $v = 0;
                }
                $times[$k] = (float) $v;
            }
            $d->ops[] = [
                'row' => $row, 'id' => $id, 'name' => $name, 'itemId' => self::text($o['itemId'] ?? null),
                'resourceId' => $resourceId, 'userId' => self::text($o['userId'] ?? null),
                'prep' => $times['prep'], 'transport' => $times['transport'], 'setup' => $times['setup'], 'direct' => $times['direct'],
                'status' => null, 'description' => self::text($o['description'] ?? null),
            ];
        }

        foreach (array_values($p['predecessors'] ?? []) as $i => $x) {
            $row = $i + 2;
            $type = strtoupper((string) ($x['type'] ?? 'FINISH_TO_START'));
            if (!in_array($type, ['FINISH_TO_START', 'START_TO_START'], true)) {
                $d->error($d->predSheet, $row, 'Dependency_Type', "Dependency_Type must be FINISH_TO_START or START_TO_START (got {$type})");
            }
            $d->preds[] = [
                'row' => $row, 'operationId' => trim((string) ($x['operationId'] ?? '')),
                'predecessorId' => trim((string) ($x['predecessorId'] ?? '')), 'type' => $type,
                'condition' => null, 'mandatory' => (bool) ($x['mandatory'] ?? true), 'description' => null,
            ];
        }

        return $this->importer->processParsed($d, 'planner', $apply, $actor, $this->target($p));
    }

    /** Which project the draft belongs to; a new project goes last in the priority order. */
    private function target(array $p): array
    {
        $target = [
            'projectId' => $p['projectId'] ?? null,
            'newProjectName' => $p['newProjectName'] ?? null,
            'newProjectId' => $p['newProjectId'] ?? null,
            'dueDate' => ProjectService::parseDue($p['dueDate'] ?? null),
            'ranking' => null,
        ];
        if (empty($target['projectId']) && !empty($target['newProjectName'])) {
            // every active project keeps its place, the new one is added last
            $order = Project::query()->where('status', ProjectStatus::ACTIVE->value)->orderBy('priority')->orderBy('id')->pluck('id')->all();
            $order[] = ImportService::NEW;
            $target['ranking'] = $order;
        }

        return $target;
    }

    private function id(ParsedData $d, string $sheet, int $row, string $column, mixed $value): string
    {
        $id = trim((string) ($value ?? ''));
        if (preg_match(self::ID, $id) !== 1) {
            $d->error($sheet, $row, $column, "{$column} may only contain letters, digits, dot, dash and underscore (1 to 64 characters)");
        }

        return $id;
    }

    private static function text(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
