<?php

namespace App\Scheduling;

/**
 * The checks of manager FORCE start times that can be decided exactly. A FORCE rejected here is impossible for every
 * schedule, not just for the heuristic:
 *  1. start in the past: nothing can start before "now";
 *  2. precedence: even with unlimited resources the predecessor chain cannot be finished (FS) or started (SS) by the
 *     forced start; running work and the FORCEs already admitted are taken as fixed. A FORCE that would make an
 *     admitted FORCE impossible this way is rejected too (BLOCKS_FORCE);
 *  3. capacity: at some moment the forced and running operations on one resource would need more lanes than the
 *     resource has.
 *
 * FORCEs are admitted in seniority order (the order of the input array: oldest first), so a new FORCE can never push
 * out an older one. Running work always wins. A rejected FORCE constrains nothing; it is only reported.
 */
final class ForceCheck
{
    public const EPS = 1e-6;

    /**
     * @param array<string, Task> $byId
     * @param array<string, int> $capacities
     * @param array<string, float> $forced requested start per operation (hours from now), oldest FORCE first
     * @param Conflict[] $out receives one conflict per rejected FORCE (by reference)
     * @return array<string, float> admitted FORCEs in (start, id) order
     */
    public static function admissible(array $byId, array $capacities, array $forced, array &$out): array
    {
        $owners = [];
        $busy = self::running($byId, $capacities, $owners);
        $admitted = [];
        foreach ($forced as $id => $start) {
            $id = (string) $id;
            $start = (float) $start;
            $t = $byId[$id];
            if ($start < -self::EPS) {
                $out[] = new Conflict($id, Conflict::KIND_START_IN_PAST, true, [], $start, 0.0);
                continue;
            }
            $start = max(0.0, $start);

            $trial = $admitted;
            $trial[$id] = $start;
            $es = self::earliestStarts($byId, $trial);
            $need = self::need($t, $byId, $es);
            if ($need > $start + self::EPS) {
                $out[] = new Conflict($id, Conflict::KIND_PRECEDENCE, true, self::chain($id, $byId, $es, $trial), $start, $need);
                continue;
            }
            $blocked = null;
            $keys = array_keys($admitted);
            sort($keys, SORT_STRING);
            foreach ($keys as $g) {
                $g = (string) $g;
                if (self::need($byId[$g], $byId, $es) > $admitted[$g] + self::EPS) {
                    $blocked = $g;
                    break;
                }
            }
            if ($blocked !== null) {
                $out[] = new Conflict($id, Conflict::KIND_BLOCKS_FORCE, true, [$blocked], $start, $admitted[$blocked]);
                continue;
            }

            $end = $start + $t->hours;
            $intervals = $busy[$t->resourceId] ?? [];
            $ids = $owners[$t->resourceId] ?? [];
            $capacity = max(1, (int) ($capacities[$t->resourceId] ?? 1));
            if (self::maxOverlap($intervals, $start, $end) + 1 > $capacity) {
                $clash = [];
                $free = PHP_FLOAT_MAX;
                foreach ($intervals as $i => $iv) {
                    if ($iv[0] < $end - self::EPS && $start < $iv[1] - self::EPS) {
                        $clash[] = $ids[$i];
                        $free = min($free, $iv[1]);
                    }
                }
                $out[] = new Conflict($id, Conflict::KIND_CAPACITY, true, $clash, $start, $free);
                continue;
            }
            $intervals[] = [$start, $end];
            $ids[] = $id;
            $busy[$t->resourceId] = $intervals;
            $owners[$t->resourceId] = $ids;
            $admitted[$id] = $start;
        }

        $ordered = [];
        foreach (self::sortedByStart($admitted) as $id) {
            $ordered[$id] = $admitted[$id];
        }

        return $ordered;
    }

    /**
     * Running work per resource as the planner places it (each on the lane that frees first, from 0).
     *
     * @param array<string, Task> $byId
     * @param array<string, int> $capacities
     * @param array<string, list<string>> $owners filled by reference
     * @return array<string, list<array{0: float, 1: float}>>
     */
    private static function running(array $byId, array $capacities, array &$owners): array
    {
        $out = [];
        $laneEnds = [];
        foreach ($byId as $t) {
            if (!$t->running) {
                continue;
            }
            $laneEnds[$t->resourceId] ??= array_fill(0, max(1, (int) ($capacities[$t->resourceId] ?? 1)), 0.0);
            $lane = self::minLane($laneEnds[$t->resourceId]);
            $start = $laneEnds[$t->resourceId][$lane];
            $laneEnds[$t->resourceId][$lane] = $start + max(0.0, $t->hours);
            $out[$t->resourceId][] = [$start, $laneEnds[$t->resourceId][$lane]];
            $owners[$t->resourceId][] = $t->id;
        }

        return $out;
    }

    /** Largest number of $intervals in use at one moment of [start, end). */
    private static function maxOverlap(array $intervals, float $start, float $end): int
    {
        $points = [$start];
        foreach ($intervals as $iv) {
            if ($iv[0] > $start && $iv[0] < $end - self::EPS) {
                $points[] = $iv[0];
            }
        }
        $max = 0;
        foreach ($points as $p) {
            $n = 0;
            foreach ($intervals as $iv) {
                if ($iv[0] <= $p + self::EPS && $p < $iv[1] - self::EPS) {
                    $n++;
                }
            }
            $max = max($max, $n);
        }

        return $max;
    }

    /**
     * @param array<string, float> $starts
     * @return string[]
     */
    public static function sortedByStart(array $starts): array
    {
        $ids = array_map('strval', array_keys($starts));
        usort($ids, static fn (string $a, string $b): int => ($starts[$a] <=> $starts[$b]) ?: strcmp($a, $b));

        return $ids;
    }

    /**
     * Earliest start of every task ignoring resource capacity (a lower bound): running work at 0, FORCEd work at its
     * forced start, everything else after its predecessors.
     *
     * @param array<string, Task> $byId
     * @param array<string, float> $fixed
     * @return array<string, float>
     */
    public static function earliestStarts(array $byId, array $fixed): array
    {
        $es = [];
        $visiting = [];
        foreach (array_keys($byId) as $id) {
            self::es((string) $id, $byId, $fixed, $es, $visiting);
        }

        return $es;
    }

    private static function es(string $id, array $byId, array $fixed, array &$memo, array &$visiting): float
    {
        if (array_key_exists($id, $memo)) {
            return $memo[$id];
        }
        $t = $byId[$id];
        if ($t->running) {
            $v = 0.0;
        } elseif (array_key_exists($id, $fixed)) {
            $v = (float) $fixed[$id];
        } else {
            $v = 0.0;
            if (!isset($visiting[$id])) { // a cycle (rejected at import) must not recurse forever
                $visiting[$id] = true;
                foreach ($t->deps as $d) {
                    $p = $byId[$d->predecessorId] ?? null;
                    if ($p !== null) {
                        $ps = self::es($p->id, $byId, $fixed, $memo, $visiting);
                        $v = max($v, $d->type === Dep::START_TO_START ? $ps : $ps + $p->hours);
                    }
                }
                unset($visiting[$id]);
            }
        }
        $memo[$id] = $v;

        return $v;
    }

    /** Earliest start the predecessors of $t allow. */
    private static function need(Task $t, array $byId, array $es): float
    {
        $v = 0.0;
        foreach ($t->deps as $d) {
            $p = $byId[$d->predecessorId] ?? null;
            if ($p !== null) {
                $ps = $es[$p->id];
                $v = max($v, $d->type === Dep::START_TO_START ? $ps : $ps + $p->hours);
            }
        }

        return $v;
    }

    /**
     * The binding predecessor chain of a FORCE that is too early, first operation first.
     *
     * @return string[]
     */
    private static function chain(string $id, array $byId, array $es, array $fixed): array
    {
        $out = [];
        $seen = [];
        $cur = $id;
        while ($cur !== null && !isset($seen[$cur])) {
            $seen[$cur] = true;
            $t = $byId[$cur];
            $next = null;
            $best = -1.0;
            foreach ($t->deps as $d) {
                $p = $byId[$d->predecessorId] ?? null;
                if ($p !== null) {
                    $v = $d->type === Dep::START_TO_START ? $es[$p->id] : $es[$p->id] + $p->hours;
                    if ($v > $best + self::EPS || (abs($v - $best) <= self::EPS && $next !== null && strcmp($p->id, $next) < 0)) {
                        $best = $v;
                        $next = $p->id;
                    }
                }
            }
            if ($next === null || $best <= self::EPS) {
                break;
            }
            $out[] = $next;
            $n = $byId[$next];
            if ($n->running || array_key_exists($next, $fixed)) {
                break;
            }
            $cur = $next;
        }

        return array_reverse($out);
    }

    private static function minLane(array $laneEnd): int
    {
        $best = 0;
        for ($i = 1, $n = count($laneEnd); $i < $n; $i++) {
            if ($laneEnd[$i] < $laneEnd[$best]) {
                $best = $i;
            }
        }

        return $best;
    }
}
