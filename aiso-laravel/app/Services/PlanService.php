<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Models\ScheduleForce;
use App\Scheduling\Dep;
use App\Scheduling\GraphUtil;
use App\Scheduling\OptimizeResult;
use App\Scheduling\Pins;
use App\Scheduling\PlanOptimizer;
use App\Scheduling\Ref;
use App\Scheduling\Task;
use Carbon\CarbonImmutable;

/**
 * Builds the projected schedule of all unfinished work of all active projects, together, on the shared resources. The
 * result is recomputed on demand; it honours the manager FORCEs and stays close to the saved reference plan.
 */
class PlanService
{
    /** The optimised multi-project plan. */
    public function plan(ProjectData $data, ?array $rankOverride = null): PlanView
    {
        $tasks = self::tasks($data, $rankOverride);

        return $this->view($data, $tasks, PlanOptimizer::optimize($tasks, self::capacities($data), self::pins($data, true)));
    }

    /** Optimised from scratch: FORCEs are honoured, the reference plan is ignored (no stability preference). */
    public function planFresh(ProjectData $data): PlanView
    {
        $tasks = self::tasks($data, null);

        return $this->view($data, $tasks, PlanOptimizer::optimize($tasks, self::capacities($data), self::pins($data, false)));
    }

    /** Single-pass baseline (project rank, then critical path) under the same FORCEs, to measure the optimiser. */
    public function planNaive(ProjectData $data, ?array $rankOverride = null): PlanView
    {
        $tasks = self::tasks($data, $rankOverride);

        return $this->view($data, $tasks, PlanOptimizer::naive($tasks, self::capacities($data), self::pins($data, false)));
    }

    private function view(ProjectData $data, array $tasks, OptimizeResult $r): PlanView
    {
        $hours = [];
        foreach ($tasks as $t) {
            $hours[$t->id] = $t->hours;
        }
        $tail = GraphUtil::tailHours($hours, $data->successors);

        return new PlanView($r->plan->slots, $r->plan->makespan, $data->now, $tail, $tasks, $r->plan->lanes,
            $r->rules, $r->candidates, $r->conflicts, $r->honoured, $r->escalated);
    }

    /**
     * FORCEd starts and the reference plan in hours from now. The reference of an operation is the saved baseline or,
     * if it was assigned after that was saved, the planned start its executor was given.
     */
    public static function pins(ProjectData $data, bool $withReference): Pins
    {
        $forced = []; // oldest FORCE first: it wins a contradiction
        $bySeniority = array_values($data->forces);
        usort($bySeniority, static function (ScheduleForce $a, ScheduleForce $b): int {
            return self::nullsLastCompare($a->created_at, $b->created_at)
                ?: strcmp($a->operation_id, $b->operation_id);
        });
        foreach ($bySeniority as $f) {
            $o = $data->ops[$f->operation_id] ?? null;
            if ($f->forced_start !== null && $o !== null && !$o->status->isTerminal()) {
                $forced[$o->id] = self::hoursFrom($data->now, $f->forced_start);
            }
        }

        $reference = [];
        if ($withReference) {
            foreach ($data->ops as $o) {
                if ($o->status->isTerminal()) {
                    continue;
                }
                $committed = $o->status === OperationStatus::ASSIGNED;
                $b = $data->baseline[$o->id] ?? null;
                $assignedLater = $committed && $o->planned_start !== null
                    && ($b === null || ($o->assigned_at !== null && $o->assigned_at->greaterThan($b->saved_at)));
                if ($assignedLater) {
                    $reference[$o->id] = new Ref(self::hoursFrom($data->now, $o->planned_start), true);
                } elseif ($b !== null) {
                    $reference[$o->id] = new Ref(self::hoursFrom($data->now, $b->planned_start), $committed);
                }
            }
        }

        return new Pins($forced, $reference);
    }

    /** Hours from $now to $t (negative when $t is in the past). */
    public static function hoursFrom(CarbonImmutable $now, \DateTimeInterface $t): float
    {
        $millis = CarbonImmutable::instance($t)->getPreciseTimestamp(3) - $now->getPreciseTimestamp(3);

        return $millis / 3_600_000.0;
    }

    /** @return array<string, int> */
    private static function capacities(ProjectData $data): array
    {
        $out = [];
        foreach ($data->resources as $r) {
            $out[$r->id] = (int) $r->capacity;
        }

        return $out;
    }

    /**
     * @param array<string, int>|null $rankOverride project id => rank, replacing the stored priorities (preview only)
     * @return Task[]
     */
    private static function tasks(ProjectData $data, ?array $rankOverride): array
    {
        $tasks = [];
        foreach ($data->ops as $o) {
            if ($o->status->isTerminal()) {
                continue;
            }
            $running = $o->status === OperationStatus::IN_PROGRESS;
            $h = $running ? $o->totalHours() * (1 - $o->progress_percent / 100.0) : $o->totalHours();
            $deps = [];
            foreach ($data->predsOf($o->id) as $p) {
                if ($p->mandatory) {
                    $deps[] = new Dep($p->predecessor_id, $p->dependency_type->value);
                }
            }
            $rank = $rankOverride !== null && array_key_exists($o->project_id, $rankOverride)
                ? (int) $rankOverride[$o->project_id]
                : $data->rankOf($o);
            $tasks[] = new Task($o->id, $o->project_id, $rank, $o->resource_id, $h, $running, $deps);
        }

        return $tasks;
    }

    /** Orders nulls last, as the Java comparator did. */
    private static function nullsLastCompare(?\DateTimeInterface $a, ?\DateTimeInterface $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }

        return $a->getTimestamp() <=> $b->getTimestamp();
    }
}
