<?php

namespace Tests\Support;

use App\Scheduling\Dep;
use App\Scheduling\JavaRandom;
use App\Scheduling\Plan;
use App\Scheduling\Task;

/**
 * Realistic scheduling scenarios and the rules every plan must keep, for the scheduler tests and the benchmark.
 *
 * A generated workshop has several projects with priorities, a few shared resources (some with 2 or 3 lanes, one
 * bottleneck that most projects need), operations of mixed length (short setups to long machining), chains with
 * parallel branches and joins, some start-to-start links, and work already running or assigned.
 */
final class ScheduleScenarios
{
    /**
     * @return array{tasks: Task[], capacities: array<string, int>}
     */
    public static function workshop(int $seed, int $projects = 4, int $opsPerProject = 15, int $resources = 6): array
    {
        $rnd = new JavaRandom($seed);
        $capacities = [];
        for ($r = 1; $r <= $resources; $r++) {
            // the first resource is the bottleneck with one lane; some others have 2 or 3 lanes
            $capacities["R{$r}"] = $r === 1 ? 1 : [1, 1, 2, 3][self::int($rnd, 4)];
        }
        $tasks = [];
        for ($p = 1; $p <= $projects; $p++) {
            $ids = [];
            for ($i = 1; $i <= $opsPerProject; $i++) {
                $id = "P{$p}-{$i}";
                // 35% of the work needs the bottleneck
                $res = $rnd->nextDouble() < 0.35 ? 'R1' : 'R' . (2 + self::int($rnd, $resources - 1));
                $hours = [0.5, 1, 1.5, 2, 3, 4, 6, 8][self::int($rnd, 8)];
                $deps = [];
                if ($i > 1 && $rnd->nextDouble() < 0.8) {
                    // usually after the previous one (a chain), sometimes after an earlier one (a parallel branch)
                    $pred = $rnd->nextDouble() < 0.7 ? $ids[$i - 2] : $ids[self::int($rnd, $i - 1)];
                    $deps[] = new Dep($pred, $rnd->nextDouble() < 0.1 ? Dep::START_TO_START : Dep::FINISH_TO_START);
                    if ($i > 3 && $rnd->nextDouble() < 0.2) {
                        // a join: a second predecessor
                        $other = $ids[self::int($rnd, $i - 2)];
                        if ($other !== $pred) {
                            $deps[] = new Dep($other, Dep::FINISH_TO_START);
                        }
                    }
                }
                $ids[] = $id;
                $tasks[] = ['id' => $id, 'project' => "P{$p}", 'rank' => $p, 'res' => $res, 'hours' => $hours, 'deps' => $deps];
            }
        }

        // one running operation on some resources: the first operation without predecessors there
        $running = [];
        foreach ($tasks as $t) {
            if ($t['deps'] === [] && !isset($running[$t['res']]) && $rnd->nextDouble() < 0.5) {
                $running[$t['res']] = $t['id'];
            }
        }
        // assigned work (held by an executor): ready operations, never more on a resource than it has lanes with the running one
        $byRes = [];
        foreach ($running as $res => $id) {
            $byRes[$res] = 1;
        }
        $held = [];
        foreach ($tasks as $t) {
            if ($t['deps'] === [] && !in_array($t['id'], $running, true) && ($byRes[$t['res']] ?? 0) < $capacities[$t['res']] && self::int($rnd, 4) === 0) {
                $held[$t['id']] = true;
                $byRes[$t['res']] = ($byRes[$t['res']] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($tasks as $t) {
            $isRunning = in_array($t['id'], $running, true);
            // about one operation in twenty is blocked and waits for the block to be lifted
            $release = !$isRunning && !isset($held[$t['id']]) && self::int($rnd, 20) === 0 ? 8.0 : 0.0;
            $out[] = new Task($t['id'], $t['project'], $t['rank'], $t['res'], $isRunning ? $t['hours'] / 2 : $t['hours'], $isRunning, $t['deps'],
                isset($held[$t['id']]), $release);
        }

        return ['tasks' => $out, 'capacities' => $capacities, 'held' => $held];
    }

    /** A whole number in [0, n). */
    private static function int(JavaRandom $rnd, int $n): int
    {
        return min($n - 1, (int) floor($rnd->nextDouble() * $n));
    }

    /**
     * Everything a plan must never break. Returns the problems found (empty: the plan is valid).
     *
     * @param Task[] $tasks
     * @param array<string, int> $capacities
     * @param array<string, true> $held operations that hold their resource from now (assigned work)
     * @return string[]
     */
    public static function violations(Plan $plan, array $tasks, array $capacities, array $held = []): array
    {
        $problems = [];
        $byId = [];
        foreach ($tasks as $t) {
            $byId[$t->id] = $t;
        }
        foreach ($tasks as $t) {
            $s = $plan->slots[$t->id] ?? null;
            if ($s === null) {
                $problems[] = "{$t->id} is not planned";
                continue;
            }
            if ($s->start < -1e-9) {
                $problems[] = "{$t->id} starts in the past";
            }
            if (abs(($s->end - $s->start) - $t->hours) > 1e-6) {
                $problems[] = "{$t->id} has the wrong duration";
            }
            if ($t->running && abs($s->start) > 1e-9) {
                $problems[] = "running {$t->id} was moved";
            }
            if ($s->start < $t->release - 1e-9) {
                $problems[] = "{$t->id} starts before it may (release {$t->release})";
            }
            foreach ($t->deps as $d) {
                $p = $plan->slots[$d->predecessorId] ?? null;
                if ($p === null || !isset($byId[$d->predecessorId])) {
                    continue;
                }
                $bound = $d->type === Dep::START_TO_START ? $p->start : $p->end;
                if ($s->start < $bound - 1e-6) {
                    $problems[] = "{$t->id} starts before its predecessor {$d->predecessorId} allows";
                }
            }
        }
        // capacity: at no moment more operations on a resource than it has lanes
        $events = [];
        foreach ($tasks as $t) {
            $s = $plan->slots[$t->id] ?? null;
            if ($s !== null && $s->end > $s->start + 1e-9) {
                $from = isset($held[$t->id]) ? 0.0 : $s->start;
                $events[$t->resourceId][] = [$from, 1];
                $events[$t->resourceId][] = [$s->end, -1];
            }
        }
        foreach ($events as $res => $list) {
            usort($list, static fn (array $a, array $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
            $now = 0;
            foreach ($list as [$at, $delta]) {
                $now += $delta;
                if ($now > max(1, $capacities[$res] ?? 1)) {
                    $problems[] = "resource {$res} has {$now} operations at hour {$at}";
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * A lower bound on the finish of a set of operations that no schedule can beat: the longest chain of dependencies,
     * and for each resource the work it must do divided by its lanes.
     *
     * @param Task[] $tasks
     */
    public static function lowerBound(array $tasks, array $capacities): float
    {
        $byId = [];
        foreach ($tasks as $t) {
            $byId[$t->id] = $t;
        }
        $memo = [];
        $finish = static function (string $id) use (&$finish, &$memo, $byId): float {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            $t = $byId[$id];
            $start = 0.0;
            foreach ($t->deps as $d) {
                if (isset($byId[$d->predecessorId])) {
                    $p = $byId[$d->predecessorId];
                    $start = max($start, $d->type === Dep::START_TO_START ? $finish($p->id) - $p->hours : $finish($p->id));
                }
            }

            return $memo[$id] = $start + $t->hours;
        };
        $chain = 0.0;
        $load = [];
        foreach ($tasks as $t) {
            $chain = max($chain, $finish($t->id));
            $load[$t->resourceId] = ($load[$t->resourceId] ?? 0.0) + $t->hours;
        }
        $resource = 0.0;
        foreach ($load as $res => $h) {
            $resource = max($resource, $h / max(1, $capacities[$res] ?? 1));
        }

        return max($chain, $resource);
    }

    /** @param Task[] $tasks @return array<string, float> finish per project */
    public static function finishes(Plan $plan, array $tasks): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $s = $plan->slots[$t->id] ?? null;
            if ($s !== null) {
                $out[$t->projectId] = max($out[$t->projectId] ?? 0.0, $s->end);
            }
        }
        ksort($out);

        return $out;
    }
}
