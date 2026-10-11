<?php

namespace App\Scheduling;

/**
 * Capacity-constrained forward schedule (serial schedule generation), the building block of {@see PlanOptimizer}.
 *
 * Time is measured in hours from "now" on a continuous clock. Each resource has {@code capacity} parallel lanes. Among
 * the operations whose predecessors are already placed, the one with the highest priority is placed at the earliest
 * time that fits on any lane, reusing idle gaps.
 *
 * {@see plan()} is the single-pass heuristic (project rank first, then longest remaining chain). It is also the "naive"
 * baseline the optimiser is compared against.
 */
final class SchedulePlanner
{
    /** @param array<string, Task> $tasks @return array<string, Task> */
    public static function index(array $tasks): array
    {
        $byId = [];
        foreach ($tasks as $t) {
            $byId[$t->id] = $t;
        }

        return $byId;
    }

    /**
     * Single pass over all tasks. Dependencies on ids that are not part of $tasks (completed or cancelled work) are
     * treated as satisfied.
     *
     * @param Task[] $tasks
     * @param array<string, int> $capacities
     */
    public static function plan(array $tasks, array $capacities): Plan
    {
        $byId = self::index($tasks);
        $tail = self::tails(array_values($byId));

        $lanes = new Lanes($capacities);
        $placed = [];
        self::placeRunning(array_values($byId), $placed, $lanes);

        $pending = [];
        foreach ($byId as $t) {
            if (!$t->running) {
                $pending[$t->id] = true;
            }
        }

        $priority = static function (string $a, string $b) use ($byId, $tail): int {
            return ($byId[$a]->projectRank <=> $byId[$b]->projectRank)
                ?: (($tail[$b] ?? 0.0) <=> ($tail[$a] ?? 0.0))
                ?: strcmp($a, $b);
        };
        self::pass($byId, $pending, $placed, $lanes, $priority);

        return self::result($placed, $lanes);
    }

    /**
     * Longest remaining chain (own duration included) per task, considering only dependencies inside $tasks.
     *
     * @param Task[] $tasks
     * @return array<string, float>
     */
    public static function tails(array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $t) {
            $ids[$t->id] = true;
        }
        $successors = [];
        $hours = [];
        foreach ($tasks as $t) {
            $hours[$t->id] = $t->hours;
            foreach ($t->deps as $d) {
                if (isset($ids[$d->predecessorId])) {
                    $successors[$d->predecessorId][] = $t->id;
                }
            }
        }

        return GraphUtil::tailHours($hours, $successors);
    }

    /**
     * @param Task[] $tasks
     * @param array<string, Slot> $placed
     */
    public static function placeRunning(array $tasks, array &$placed, Lanes $lanes): void
    {
        foreach ($tasks as $t) {
            if ($t->running) {
                $placed[$t->id] = self::place($lanes, $t->resourceId, 0.0, max(0.0, $t->hours));
            }
        }
    }

    /**
     * Places every id in $pending (a set: id => true), highest priority first, as soon as all its predecessors are
     * placed.
     *
     * @param array<string, Task> $byId
     * @param array<string, bool> $pending
     * @param array<string, Slot> $placed
     * @param callable(string, string): int $priority
     * @param array<string, float> $release earliest allowed start per task (hours from now)
     */
    public static function pass(array $byId, array &$pending, array &$placed, Lanes $lanes, callable $priority, array $release = []): void
    {
        while ($pending !== []) {
            $next = null;
            foreach (array_keys($pending) as $id) {
                $ready = true;
                foreach ($byId[$id]->deps as $d) {
                    if (isset($byId[$d->predecessorId]) && !isset($placed[$d->predecessorId])) {
                        $ready = false;
                        break;
                    }
                }
                if (!$ready) {
                    continue;
                }
                if ($next === null || $priority((string) $id, $next) < 0) {
                    $next = (string) $id;
                }
            }
            if ($next === null) {
                break; // unresolved cycle: leave the rest unplanned
            }
            $t = $byId[$next];
            $ready = max(0.0, (float) ($release[$next] ?? 0.0));
            foreach ($t->deps as $d) {
                $p = $placed[$d->predecessorId] ?? null;
                if ($p !== null) {
                    $bound = $d->type === Dep::START_TO_START ? $p->start : $p->end;
                    $ready = max($ready, $bound);
                }
            }
            $placed[$next] = self::place($lanes, $t->resourceId, $ready, $t->hours);
            unset($pending[$next]);
        }
    }

    /** @param array<string, Slot> $placed */
    public static function result(array $placed, Lanes $lanes): Plan
    {
        $makespan = 0.0;
        foreach ($placed as $s) {
            $makespan = max($makespan, $s->end);
        }

        return new Plan($placed, $makespan, $lanes);
    }

    /**
     * Places an operation at the earliest start >= $ready on whichever lane fits it, filling idle gaps between
     * operations placed earlier (so a lower-priority operation can use time a higher-priority one is not using).
     */
    private static function place(Lanes $all, string $resourceId, float $ready, float $duration): Slot
    {
        $lanes = $all->lanes($resourceId);
        $bestLane = 0;
        $bestStart = PHP_FLOAT_MAX;
        foreach ($lanes as $i => $lane) {
            $start = self::earliestFit($lane, $ready, $duration);
            if ($start < $bestStart) {
                $bestStart = $start;
                $bestLane = $i;
            }
        }
        $all->addInterval($resourceId, $bestLane, $bestStart, $bestStart + $duration);

        return new Slot($bestStart, $bestStart + $duration);
    }

    /**
     * Places an operation at exactly $start on the first lane that is free for the whole interval (a manager FORCE).
     *
     * @return Slot|null null when every lane is busy somewhere in that interval
     */
    public static function placeAt(Lanes $all, string $resourceId, float $start, float $duration): ?Slot
    {
        foreach ($all->lanes($resourceId) as $i => $lane) {
            $free = true;
            foreach ($lane as $iv) {
                if ($iv[0] < $start + $duration - 1e-9 && $start < $iv[1] - 1e-9) {
                    $free = false;
                    break;
                }
            }
            if ($free) {
                $all->addInterval($resourceId, $i, $start, $start + $duration);

                return new Slot($start, $start + $duration);
            }
        }

        return null;
    }

    /** @param list<array{0: float, 1: float}> $lane sorted by start */
    private static function earliestFit(array $lane, float $ready, float $duration): float
    {
        $start = $ready;
        foreach ($lane as $iv) {
            if ($start + $duration <= $iv[0] + 1e-9) {
                return $start;
            }
            $start = max($start, $iv[1]);
        }

        return $start;
    }
}
