<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Enums\PlanningGoal;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ScheduleForce;
use App\Scheduling\Dep;
use App\Scheduling\GraphUtil;
use App\Scheduling\OptimizeResult;
use App\Scheduling\Pins;
use App\Scheduling\PlanOptimizer;
use App\Scheduling\Ref;
use App\Scheduling\Task;
use App\Scheduling\WorkCalendar;
use Carbon\CarbonImmutable;

/**
 * Builds the projected schedule of all unfinished work of all active projects, together, on the shared resources. The
 * result is recomputed on demand; it honours the manager FORCEs and stays close to the saved reference plan.
 */
class PlanService
{
    /**
     * The optimised multi-project plan for the planning goal of the project (see {@see PlanningGoal}). $goal and $focus
     * evaluate another goal without changing the setting (the goal comparison on the plan builder).
     */
    public function plan(ProjectData $data, ?array $rankOverride = null, ?PlanningGoal $goal = null, ?string $focus = null): PlanView
    {
        $goal ??= self::goalOf($data);
        $tasks = self::tasks($data, $rankOverride, $goal, $focus);

        return $this->view($data, $tasks, PlanOptimizer::optimize($tasks, self::capacities($data), self::pins($data, true),
            $goal === PlanningGoal::RESOURCES));
    }

    /** Optimised from scratch: FORCEs are honoured, the reference plan is ignored (no stability preference). */
    public function planFresh(ProjectData $data): PlanView
    {
        $goal = self::goalOf($data);
        $tasks = self::tasks($data, null, $goal, null);

        return $this->view($data, $tasks, PlanOptimizer::optimize($tasks, self::capacities($data), self::pins($data, false),
            $goal === PlanningGoal::RESOURCES));
    }

    /** Single-pass baseline (project rank, then critical path) under the same FORCEs, to measure the optimiser. */
    public function planNaive(ProjectData $data, ?array $rankOverride = null): PlanView
    {
        $tasks = self::tasks($data, $rankOverride, self::goalOf($data), null);

        return $this->view($data, $tasks, PlanOptimizer::naive($tasks, self::capacities($data), self::pins($data, false)));
    }

    public static function goalOf(ProjectData $data): PlanningGoal
    {
        return $data->settings->planning_goal ?? PlanningGoal::PRIORITY;
    }

    /**
     * The time goal gives the focus project the first place for this calculation only (stored priorities do not
     * change); the other active projects follow in their own order. Null when the goal is not TIME or the focus project
     * is not an active project.
     *
     * @return array<string, int>|null project id => rank
     */
    public static function focusRanks(ProjectData $data, PlanningGoal $goal, ?string $focus): ?array
    {
        $focus ??= $data->settings->planning_focus_project;
        if ($goal !== PlanningGoal::TIME || $focus === null || !isset($data->projects[$focus]) || $data->projects[$focus]->status !== ProjectStatus::ACTIVE) {
            return null;
        }
        $others = array_values(array_filter($data->projects, static fn (Project $p) => $p->status === ProjectStatus::ACTIVE && $p->id !== $focus));
        usort($others, static fn (Project $a, Project $b): int => ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id));
        $ranks = [$focus => 1];
        foreach ($others as $i => $p) {
            $ranks[$p->id] = $i + 2;
        }

        return $ranks;
    }

    private function view(ProjectData $data, array $tasks, OptimizeResult $r): PlanView
    {
        $hours = [];
        foreach ($tasks as $t) {
            $hours[$t->id] = $t->hours;
        }
        $tail = GraphUtil::tailHours($hours, $data->successors);

        return new PlanView($r->plan->slots, $r->plan->makespan, $data->now, $tail, $tasks, $r->plan->lanes,
            $r->rules, $r->candidates, $r->conflicts, $r->honoured, $r->escalated, self::unplanned($data), WorkCalendar::current());
    }

    /**
     * Open work that cannot be planned now: operations on an inactive resource, and everything that (transitively) waits
     * for them. They are left out of the schedule instead of being shown at a time that will not happen.
     *
     * @return array<string, string> operation id => reason
     */
    public static function unplanned(ProjectData $data): array
    {
        $out = [];
        foreach ($data->ops as $o) {
            if (!$o->status->isTerminal() && $o->status !== OperationStatus::IN_PROGRESS) {
                $r = $data->resources[$o->resource_id] ?? null;
                if ($r !== null && $r->status !== 'ACTIVE') {
                    $out[$o->id] = "resource {$r->id} is inactive";
                }
            }
        }
        // whatever waits for them waits too
        $stack = array_keys($out);
        while ($stack !== []) {
            $id = (string) array_pop($stack);
            foreach ($data->successors[$id] ?? [] as $s) {
                $op = $data->ops[$s] ?? null;
                if ($op !== null && !isset($out[$s]) && !$op->status->isTerminal() && $op->status !== OperationStatus::IN_PROGRESS) {
                    $out[$s] = "waits for {$id}, which cannot be planned";
                    $stack[] = $s;
                }
            }
        }

        return $out;
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

    /** Hours of planned work from $now to $t (negative when $t is in the past); working hours when a calendar is set. */
    public static function hoursFrom(CarbonImmutable $now, \DateTimeInterface $t): float
    {
        $calendar = WorkCalendar::current();
        if ($calendar !== null) {
            return $calendar->between($now, $t);
        }
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
    private static function tasks(ProjectData $data, ?array $rankOverride, PlanningGoal $goal, ?string $focus): array
    {
        $rankOverride ??= self::focusRanks($data, $goal, $focus);
        $unplanned = self::unplanned($data);
        $blockedWait = (float) config('aiso.scheduling.blocked_release_hours', 0);
        $tasks = [];
        foreach ($data->ops as $o) {
            if ($o->status->isTerminal() || isset($unplanned[$o->id])) {
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
            // assigned work is held by its executor; blocked work cannot start before the block is lifted
            $tasks[] = new Task($o->id, $o->project_id, $rank, $o->resource_id, $h, $running, $deps,
                $o->status === OperationStatus::ASSIGNED, $o->status === OperationStatus::BLOCKED ? max(0.0, $blockedWait) : 0.0);
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
