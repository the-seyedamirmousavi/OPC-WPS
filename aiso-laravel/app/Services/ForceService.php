<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Operation;
use App\Models\PlanBaseline;
use App\Models\ScheduleForce;
use App\Scheduling\Conflict;
use App\Scheduling\PlanOptimizer;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manager FORCE: "this operation starts at this time" and/or "this operation is done by this user".
 *
 * A FORCE is checked before it is stored: against the absolute rules (finished or running work, inactive resource,
 * invalid user) and by planning the whole schedule with it. It is refused when it is impossible or would make another
 * FORCE impossible; the reason names the operations involved. An accepted FORCE is stored, an already assigned
 * operation gets its new executor / planned time, and the resulting plan is saved as the reference plan so later
 * re-planning stays close to it.
 *
 * Findings and impacts are returned as arrays shaped for the JSON API (camelCase keys).
 */
class ForceService
{
    private const MAX_MOVED = 100;
    private const DATE_TIME = '/^(\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2})[ T](\d{1,2}):(\d{2})$/';

    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly PlanService $planService,
        private readonly OperationService $operationService,
        private readonly AuditService $audit,
        private readonly LanguageService $lang,
    ) {
    }

    // ---- queries ------------------------------------------------------------------------------------------------

    public function board(): array
    {
        $data = $this->dataService->load();
        $plan = $this->planService->plan($data);
        $conflicts = [];
        foreach ($plan->conflicts as $c) {
            $conflicts[$c->operationId] = $c;
        }

        $list = array_values($data->forces);
        usort($list, static function (ScheduleForce $a, ScheduleForce $b): int {
            return self::nullsLastCompare($a->forced_start, $b->forced_start) ?: strcmp($a->operation_id, $b->operation_id);
        });

        $views = [];
        $problems = [];
        foreach ($list as $f) {
            $op = $data->ops[$f->operation_id] ?? null;
            if ($op === null) {
                continue;
            }
            $problem = null;
            $state = self::stateOf($f, $op, $plan, $data);
            if ($state === 'NOT_HONOURED') {
                $c = $conflicts[$op->id] ?? null;
                $problem = $c !== null
                    ? $this->finding($c, $data, $plan)
                    : self::finding0('USER_NOT_ALLOWED', $op->id, true, [], $this->lang->forRequest(
                        "The forced executor {$f->forced_user_id} is no longer an active executive user"));
                $problems[] = $problem;
            }
            $views[] = [
                'operationId' => $op->id,
                'operationName' => $op->name,
                'projectId' => $op->project_id,
                'projectName' => $data->projectName($op->project_id),
                'projectRank' => $data->rankOf($op),
                'resourceName' => $data->resourceName($op->resource_id),
                'operationStatus' => $op->status->value,
                'forcedStart' => self::iso($f->forced_start),
                'forcedUserId' => $f->forced_user_id,
                'forcedUserName' => $data->userName($f->forced_user_id),
                'reason' => $f->reason,
                'createdBy' => $f->created_by,
                'createdAt' => self::iso($f->created_at),
                'state' => $state,
                'plannedStart' => self::iso($plan->start($op->id)),
                'plannedEnd' => self::iso($plan->end($op->id)),
                'conflict' => $problem,
            ];
        }

        $savedAt = null;
        foreach ($data->baseline as $b) {
            if ($savedAt === null || $b->saved_at->greaterThan($savedAt)) {
                $savedAt = $b->saved_at;
            }
        }

        return [
            'forces' => $views,
            'conflicts' => $problems,
            'escalated' => count($plan->escalated),
            'baselineSavedAt' => self::iso($savedAt),
        ];
    }

    /** HONOURED, NOT_HONOURED or DONE; null without a FORCE. */
    public static function stateOf(?ScheduleForce $f, Operation $op, PlanView $plan, ProjectData $data): ?string
    {
        if ($f === null) {
            return null;
        }
        if ($op->status->isTerminal() || $op->status === OperationStatus::IN_PROGRESS) {
            return 'DONE';
        }
        $startMissed = $f->forced_start !== null && !isset($plan->honoured[$op->id]);
        $u = $f->forced_user_id === null ? null : ($data->users[$f->forced_user_id] ?? null);
        $userInvalid = $f->forced_user_id !== null
            && ($u === null || !$u->isActive() || !$u->user_role->isExecutor());

        return $startMissed || $userInvalid ? 'NOT_HONOURED' : 'HONOURED';
    }

    /** What the FORCE would change. Nothing is stored. */
    public function preview(string $operationId, ?string $start, ?string $userId, ?string $reason = null): array
    {
        return $this->impact($this->dataService->load(), $operationId, self::parseStart($start), self::blankToNull($userId));
    }

    // ---- commands -----------------------------------------------------------------------------------------------

    /** Creates or replaces the FORCE on the operation; refused (409) when it is impossible. */
    public function apply(string $operationId, ?string $start, ?string $userId, ?string $reason, AppUser $actor): array
    {
        return DB::transaction(function () use ($operationId, $start, $userId, $reason, $actor) {
            $data = $this->dataService->load();
            $startAt = self::parseStart($start);
            $userId = self::blankToNull($userId);
            $impact = $this->impact($data, $operationId, $startAt, $userId);
            if (!$impact['feasible']) {
                throw ApiException::conflict('FORCE rejected: ' . $impact['conflicts'][0]['message']);
            }
            $reason = self::blankToNull($reason);
            $f = ScheduleForce::query()->find($operationId);
            $before = self::describe($f);
            if ($f === null) {
                $f = new ScheduleForce(['operation_id' => $operationId]);
            }
            $f->forced_start = $startAt;
            $f->forced_user_id = $userId;
            $f->reason = $reason;
            $f->created_by = $actor->id;
            $f->created_at = CarbonImmutable::now();
            $f->save();

            $op = $data->ops[$operationId];
            $note = 'FORCE: ' . ($reason ?? '');
            if ($op->status === OperationStatus::ASSIGNED) {
                if ($userId !== null && $userId !== $op->assigned_user_id) {
                    $this->operationService->assign($operationId, $userId, $impact['plannedStartAt'], $impact['plannedEndAt'], $actor->id, $note);
                } elseif ($startAt !== null) {
                    $this->operationService->reschedule($operationId, $impact['plannedStartAt'], $impact['plannedEndAt'], $actor->id, $note);
                }
            }
            $this->audit->record($actor->id, 'FORCE_SET', 'Operation', $operationId, $before, self::describe($f), $reason);
            $this->saveBaseline($this->planService->plan($this->dataService->load()));

            return $this->board();
        });
    }

    public function remove(string $operationId, AppUser $actor): array
    {
        return DB::transaction(function () use ($operationId, $actor) {
            $f = ScheduleForce::query()->find($operationId);
            if ($f === null) {
                throw ApiException::notFound("No FORCE on operation {$operationId}");
            }
            $before = self::describe($f);
            $f->delete();
            $this->audit->record($actor->id, 'FORCE_REMOVED', 'Operation', $operationId, $before, null, null);
            $this->saveBaseline($this->planService->plan($this->dataService->load()));

            return $this->board();
        });
    }

    /**
     * Re-optimises from scratch: FORCEs are still honoured, but the schedule no longer tries to stay close to the
     * previous plan. The result becomes the new reference plan.
     */
    public function reoptimize(AppUser $actor): array
    {
        return DB::transaction(function () use ($actor) {
            $fresh = $this->planService->planFresh($this->dataService->load());
            $this->saveBaseline($fresh);
            $this->audit->record($actor->id, 'PLAN_REOPTIMIZED', 'Plan', '*', null, count($fresh->slots) . ' operations', null);

            return $this->board();
        });
    }

    // ---- impact -------------------------------------------------------------------------------------------------

    private function impact(ProjectData $data, string $operationId, ?CarbonImmutable $start, ?string $userId): array
    {
        $op = $data->ops[$operationId] ?? null;
        if ($op === null) {
            throw ApiException::notFound("Operation {$operationId} not found");
        }
        if ($start === null && $userId === null) {
            throw ApiException::badRequest('Give a forced start, a forced executor or both');
        }

        /** @var array<int, array> $conflicts */
        $conflicts = $this->absolute($data, $op, $start, $userId);
        $before = $this->planService->plan($data);
        $after = $before;
        if ($conflicts === []) {
            $f = new ScheduleForce([
                'operation_id' => $operationId,
                'forced_start' => $start,
                'forced_user_id' => $userId,
                'created_at' => CarbonImmutable::now(), // the youngest FORCE: the one given up in a contradiction
            ]);
            $next = $data->forces;
            $next[$operationId] = $f;
            $after = $this->planService->plan($data->withForces($next));

            $brokenBefore = [];
            foreach ($before->conflicts as $c) {
                $brokenBefore[$c->operationId] = true;
            }
            foreach ($after->conflicts as $c) {
                if ($c->operationId === $operationId) {
                    array_unshift($conflicts, $this->finding($c, $data, $after));
                } elseif (!isset($brokenBefore[$c->operationId])) {
                    $conflicts[] = self::finding0('BREAKS_FORCE', $c->operationId, $c->proven, $c->related,
                        $this->lang->forRequest("It would make the FORCE on {$c->operationId} impossible: "
                            . self::englishMessage($c, $data, $after)));
                }
            }
        }

        $warnings = [];
        if ($userId !== null) {
            if ($op->responsible_user_id !== null && $op->responsible_user_id !== $userId) {
                $warnings[] = self::warning('OPC_EXECUTOR_OVERRIDDEN', $operationId,
                    "Overrides the executor fixed in the OPC sheet ({$op->responsible_user_id})");
            }
            $active = 0;
            foreach ($data->ops as $o) {
                if ($o->status->isActive() && $o->assigned_user_id === $userId && $o->id !== $operationId) {
                    $active++;
                }
            }
            $limit = (int) $data->settings->max_active_tasks_per_user;
            if ($active >= $limit) {
                $warnings[] = self::warning('OVER_TASK_LIMIT', $operationId,
                    "User {$userId} already has {$active} active tasks (limit {$limit}); the FORCE overrides the limit");
            }
            if ($op->status === OperationStatus::ASSIGNED && $op->assigned_user_id !== null && $userId !== $op->assigned_user_id) {
                $warnings[] = self::warning('REASSIGNS', $operationId,
                    "Operation {$operationId} is assigned to {$op->assigned_user_id}; it will be reassigned to {$userId}");
            }
        }

        // what moves
        $moved = [];
        $committed = 0;
        $total = 0.0;
        foreach ($after->slots as $id => $slot) {
            $b = $before->slots[$id] ?? null;
            $o = $data->ops[$id] ?? null;
            if ($b === null || $o === null || $o->id === $operationId) {
                continue;
            }
            $d = $slot->start - $b->start;
            if (abs($d) > PlanOptimizer::MOVE_EPS) {
                $isCommitted = $o->status === OperationStatus::ASSIGNED;
                $committed += $isCommitted ? 1 : 0;
                $total += abs($d);
                $moved[] = [
                    'operationId' => $o->id,
                    'name' => $o->name,
                    'projectName' => $data->projectName($o->project_id),
                    'committed' => $isCommitted,
                    'before' => self::iso($before->at($b->start)),
                    'after' => self::iso($after->at($slot->start)),
                    'shiftHours' => $d,
                ];
            }
        }
        usort($moved, static fn (array $a, array $b): int => (-abs($a['shiftHours']) <=> -abs($b['shiftHours']))
            ?: strcmp($a['operationId'], $b['operationId']));
        $movedCount = count($moved);
        if ($committed > 0) {
            $warnings[] = self::warning('COMMITTED_MOVED', null, "{$committed} already assigned operation(s) would move");
        }
        $projects = $this->projectShifts($data, $before, $after);
        foreach ($projects as $p) {
            if ($p['deltaHours'] > PlanOptimizer::MOVE_EPS) {
                $warnings[] = self::warning('PRIORITY_DELAYED', null, sprintf(
                    'Project %s (priority %d) would finish %.1f h later', $p['name'], $p['priority'], $p['deltaHours']));
            }
        }
        $newlyEscalated = array_values(array_diff(array_keys($after->escalated), array_keys($before->escalated)));
        sort($newlyEscalated, SORT_STRING);
        if ($newlyEscalated !== []) {
            $warnings[] = [
                'kind' => 'ESCALATED',
                'operationId' => $operationId,
                'proven' => false,
                'related' => $newlyEscalated,
                'message' => $this->lang->forRequest(count($newlyEscalated)
                    . ' operation(s) run ahead of higher-priority work to make the forced start possible'),
            ];
        }

        return [
            'feasible' => $conflicts === [],
            'operationId' => $operationId,
            'currentStart' => self::iso($before->start($operationId)),
            'plannedStart' => self::iso($after->start($operationId)),
            'plannedEnd' => self::iso($after->end($operationId)),
            'plannedStartAt' => $after->start($operationId),
            'plannedEndAt' => $after->end($operationId),
            'forcedUserId' => $userId,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            'movedCount' => $movedCount,
            'committedMoved' => $committed,
            'totalShiftHours' => $total,
            'moved' => array_slice($moved, 0, self::MAX_MOVED),
            'projects' => $projects,
            'wasteBefore' => PlanOptimizer::waste($before->lanes),
            'wasteAfter' => PlanOptimizer::waste($after->lanes),
        ];
    }

    /**
     * Rules no FORCE may break, decided without planning.
     *
     * @return array<int, array> findings
     */
    private function absolute(ProjectData $data, Operation $op, ?CarbonImmutable $start, ?string $userId): array
    {
        $out = [];
        $id = $op->id;
        if ($op->status->isTerminal()) {
            return [self::finding0('OPERATION_FINISHED', $id, true, [],
                $this->lang->forRequest("Operation {$id} is {$op->status->value} and cannot be forced"))];
        }
        if ($start !== null && $op->status === OperationStatus::IN_PROGRESS) {
            $out[] = self::finding0('ALREADY_STARTED', $id, true, [],
                $this->lang->forRequest("Operation {$id} is {$op->status->value}; its start cannot be forced"));
        }
        $res = $data->resources[$op->resource_id] ?? null;
        if ($start !== null && ($res === null || !$res->isActive())) {
            $out[] = self::finding0('RESOURCE_INACTIVE', $id, true, [],
                $this->lang->forRequest('Resource ' . $data->resourceName($op->resource_id) . " of {$id} is inactive"));
        }
        if ($userId !== null) {
            $u = $data->users[$userId] ?? null;
            $changeable = in_array($op->status, [OperationStatus::NOT_READY, OperationStatus::READY, OperationStatus::ASSIGNED], true);
            if ($u === null || !$u->isActive() || !$u->user_role->isExecutor()) {
                $out[] = self::finding0('USER_NOT_ALLOWED', $id, true, [],
                    $this->lang->forRequest("User {$userId} is not an active executive user"));
            } elseif (!$changeable && $op->assigned_user_id !== null && $userId !== $op->assigned_user_id) {
                $out[] = self::finding0('EXECUTOR_LOCKED', $id, true, [$op->assigned_user_id], $this->lang->forRequest(
                    "Operation {$id} is {$op->status->value} with executor {$op->assigned_user_id}; the executor cannot change now"));
            }
        }

        return $out;
    }

    /** @return array<int, array> */
    private function projectShifts(ProjectData $data, PlanView $before, PlanView $after): array
    {
        $list = array_values($data->projects);
        usort($list, static fn ($a, $b): int => ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id));
        $out = [];
        foreach ($list as $p) {
            $b = self::finish($data, $before, $p->id);
            $a = self::finish($data, $after, $p->id);
            if ($b < 0 && $a < 0) {
                continue;
            }
            $out[] = [
                'projectId' => $p->id,
                'name' => $p->name,
                'priority' => (int) $p->priority,
                'before' => $b < 0 ? null : self::iso($before->at($b)),
                'after' => $a < 0 ? null : self::iso($after->at($a)),
                'deltaHours' => max(0.0, $a) - max(0.0, $b),
            ];
        }

        return $out;
    }

    /** Latest planned end of the project's unfinished work in hours, or -1 when it has none. */
    private static function finish(ProjectData $data, PlanView $plan, string $projectId): float
    {
        $f = -1.0;
        foreach ($plan->slots as $id => $slot) {
            $o = $data->ops[$id] ?? null;
            if ($o !== null && $projectId === $o->project_id) {
                $f = max($f, $slot->end);
            }
        }

        return $f;
    }

    // ---- messages -----------------------------------------------------------------------------------------------

    private function finding(Conflict $c, ProjectData $data, PlanView $plan): array
    {
        return self::finding0($c->kind, $c->operationId, $c->proven, $c->related,
            $this->lang->forRequest(self::englishMessage($c, $data, $plan)));
    }

    private static function warning(string $kind, ?string $operationId, string $message): array
    {
        return self::finding0($kind, $operationId, false, [], $message);
    }

    private static function finding0(string $kind, ?string $operationId, bool $proven, array $related, string $message): array
    {
        return ['kind' => $kind, 'operationId' => $operationId, 'proven' => $proven, 'related' => array_values($related), 'message' => $message];
    }

    public static function englishMessage(Conflict $c, ProjectData $data, PlanView $plan): string
    {
        $id = $c->operationId;
        $at = self::tehranTime($plan->at($c->requestedStart));
        switch ($c->kind) {
            case Conflict::KIND_START_IN_PAST:
                return "Forced start {$at} of {$id} is in the past";
            case Conflict::KIND_PRECEDENCE:
                return "{$id} cannot start at {$at}: its predecessors (" . implode(', ', $c->related)
                    . ') allow ' . self::tehranTime($plan->at($c->earliestStart)) . ' at the earliest';
            case Conflict::KIND_CAPACITY:
                $op = $data->ops[$id] ?? null;
                $r = $op === null ? null : ($data->resources[$op->resource_id] ?? null);
                return "{$id} cannot start at {$at}: resource " . ($r === null ? '?' : $r->name)
                    . ' (capacity ' . ($r === null ? 1 : $r->capacity) . ') is taken by '
                    . implode(', ', $c->related) . ' at that time';
            case Conflict::KIND_BLOCKS_FORCE:
                return "{$id} cannot start at {$at}: the FORCE on {$c->related[0]} (start "
                    . self::tehranTime($plan->at($c->earliestStart)) . ') needs it earlier';
            default: // NOT_FOUND
                $older = count($c->related) === 1 && isset($data->forces[$c->related[0]]) ? $c->related[0] : null;

                return $older !== null
                    ? "No schedule was found in which {$id} starts at {$at} without making the older FORCE on {$older} impossible; it is planned for "
                        . self::tehranTime($plan->at($c->earliestStart)) . '.'
                    : "No schedule was found in which {$id} starts at {$at}; it is planned for "
                        . self::tehranTime($plan->at($c->earliestStart)) . '. A feasible schedule may still exist.';
        }
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** Replaces the reference plan with $plan (updates rows in place). */
    private function saveBaseline(PlanView $plan): void
    {
        $now = CarbonImmutable::now();
        $existing = PlanBaseline::query()->get()->keyBy('operation_id')->all();
        $keep = [];
        foreach ($plan->slots as $id => $slot) {
            $b = $existing[$id] ?? new PlanBaseline(['operation_id' => $id]);
            unset($existing[$id]);
            $b->planned_start = $plan->at($slot->start);
            $b->planned_end = $plan->at($slot->end);
            $b->saved_at = $now;
            $keep[] = $b;
        }
        foreach ($existing as $gone) {
            $gone->delete();
        }
        foreach ($keep as $b) {
            $b->save();
        }
    }

    public static function describe(?ScheduleForce $f): ?string
    {
        if ($f === null) {
            return null;
        }
        $parts = [];
        if ($f->forced_start !== null) {
            $parts[] = 'start ' . self::tehranTime($f->forced_start);
        }
        if ($f->forced_user_id !== null) {
            $parts[] = 'executor ' . $f->forced_user_id;
        }

        return implode(', ', $parts);
    }

    /** "2026-10-08 14:30" in Tehran time. */
    public static function tehranTime(?\DateTimeInterface $t): string
    {
        return $t === null ? '-' : CarbonImmutable::instance($t)->setTimezone(Jalali::TEHRAN)->format('Y-m-d H:i');
    }

    /**
     * Accepts "2026-10-08 14:30" / "2026-10-08T14:30" (Tehran time), the same with a Jalali date ("1405/07/16 14:30",
     * Persian digits allowed), or an ISO instant / offset date-time. Blank means "no forced start".
     */
    public static function parseStart(?string $text): ?CarbonImmutable
    {
        if (self::blankToNull($text) === null) {
            return null;
        }
        $t = Jalali::latin(trim($text));
        try {
            if (preg_match(self::DATE_TIME, $t, $m) === 1) {
                $date = Jalali::parseDate($m[1]);
                if ($date === null) {
                    $date = CarbonImmutable::parse(str_replace(['/', '.'], '-', $m[1]), Jalali::TEHRAN)->startOfDay();
                }

                return CarbonImmutable::create($date->year, $date->month, $date->day, (int) $m[2], (int) $m[3], 0, Jalali::TEHRAN);
            }

            return CarbonImmutable::parse($t);
        } catch (\Throwable) {
            throw ApiException::badRequest("Start must be a date and time like 2026-10-08 14:30, 1405/07/16 14:30 or an ISO instant, got '{$text}'");
        }
    }

    private static function blankToNull(?string $s): ?string
    {
        $s = $s === null ? null : trim($s);

        return $s === null || $s === '' ? null : $s;
    }

    private static function nullsLastCompare(?\DateTimeInterface $a, ?\DateTimeInterface $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }

        return $a->getTimestamp() <=> $b->getTimestamp();
    }

    private static function iso(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : CarbonImmutable::instance($t)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
