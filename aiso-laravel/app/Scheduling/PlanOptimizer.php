<?php

namespace App\Scheduling;

/**
 * Schedules several projects at once on shared resources, honouring manager FORCEs.
 *
 * Constraint hierarchy:
 *  1. Absolute (never violated): resource capacity, mandatory precedence (FS/SS), running work stays where it is,
 *     nothing starts before "now".
 *  2. Manager FORCE: a forced operation starts exactly at its forced time. A FORCE that contradicts an absolute
 *     constraint is not applied; it is reported as a {@see Conflict} and becomes "not before that time".
 *  3. Manager-overridable: strict project priority. A FORCE may need predecessors of the forced operation to run before
 *     higher-priority work; that only happens when the plain priority order misses the forced start.
 *  4. Soft (the objective): see below.
 *
 * Construction: running work is placed at 0, then FORCEd work at its exact start, then (only when needed) the
 * predecessors of a FORCE that would otherwise be late, earliest deadline first, then every project in priority order.
 * Everything already placed is frozen, so a lower-priority project can never delay a higher-priority one.
 *
 * Objective (lexicographic): a candidate is scored on the complete schedule that results when all lower-priority
 * projects are then placed with the critical-path rule (look-ahead): (1) project finish times in priority order,
 * (2) churn against the reference plan: moved committed operations, moved operations, total shift, (3) wasted lane
 * time, (4) sum of finish times.
 *
 * This is a heuristic: it does not prove optimality. Seeds are fixed; the same input always gives the same plan.
 */
final class PlanOptimizer
{
    public const RULE_CRITICAL_PATH = 'CRITICAL_PATH';
    public const RULE_LONGEST_FIRST = 'LONGEST_FIRST';
    public const RULE_SHORTEST_FIRST = 'SHORTEST_FIRST';
    public const RULE_MOST_SUCCESSORS = 'MOST_SUCCESSORS';
    public const RULE_RANDOMIZED = 'RANDOMIZED';
    public const RULE_STABLE = 'STABLE';

    private const EPS = 1e-9;
    /** A start that changed by less than a minute is not a move. */
    public const MOVE_EPS = 1.0 / 60;
    private const MAX_RANDOM_TRIALS = 48;
    private const RANDOM_WORK_BUDGET = 1_500_000;

    /**
     * @param Task[] $tasks
     * @param array<string, int> $capacities
     */
    public static function optimize(array $tasks, array $capacities, ?Pins $pins = null): OptimizeResult
    {
        return self::run($tasks, $capacities, $pins ?? Pins::none(), false);
    }

    /** The single-pass baseline (critical-path rule per project in priority order, no search) under the same FORCEs. */
    public static function naive(array $tasks, array $capacities, Pins $pins): OptimizeResult
    {
        return self::run($tasks, $capacities, $pins, true);
    }

    /**
     * @param Task[] $tasks
     * @param array<string, int> $capacities
     */
    private static function run(array $tasks, array $capacities, Pins $pins, bool $naive): OptimizeResult
    {
        $byId = SchedulePlanner::index($tasks);
        $requested = []; // seniority order
        foreach ($pins->forcedStart as $id => $start) {
            $id = (string) $id;
            if (isset($byId[$id]) && !$byId[$id]->running) {
                $requested[$id] = (float) $start;
            }
        }
        $conflicts = [];
        $active = ForceCheck::admissible($byId, $capacities, $requested, $conflicts);
        $projects = self::projects($byId);
        $successors = self::successors($byId);

        // Escalation: a FORCE whose predecessors end too late first gets its predecessor chain placed ahead of project
        // priority (earliest deadline first). If that is not enough, a FORCE is given up (reported): a younger one that
        // stands in the way, otherwise the late one.
        $escalatedForces = [];
        while (true) {
            $urgent = self::ancestors($byId, array_keys($escalatedForces), $active);
            $a = self::attempt($byId, $capacities, $pins->reference, $active, $urgent, [], $projects, $successors, $naive);
            $late = self::late($byId, $active, $a->placed);
            if ($late === []) {
                break;
            }
            $escalatedMore = false;
            foreach ($late as $id) {
                if (!isset($escalatedForces[$id])) {
                    $escalatedForces[$id] = true;
                    $escalatedMore = true;
                }
            }
            if (!$escalatedMore) {
                // Still late with every late FORCE escalated. Seniority decides who is given up: first try whether
                // giving up a younger FORCE on a resource the oldest late one depends on rescues it; otherwise give up
                // the youngest late FORCE.
                $oldestLate = null;
                $youngestLate = null;
                foreach (array_keys($requested) as $id) {
                    if (in_array((string) $id, $late, true)) {
                        $oldestLate ??= (string) $id;
                        $youngestLate = (string) $id;
                    }
                }
                $victim = null;
                $related = [];
                $resources = self::resourcesOf($byId, $oldestLate);
                $keys = array_map('strval', array_keys($requested));
                $younger = array_slice($keys, array_search($oldestLate, $keys, true) + 1);
                for ($i = count($younger) - 1; $i >= 0 && $victim === null; $i--) {
                    $j = $younger[$i];
                    if (!isset($active[$j]) || !isset($resources[$byId[$j]->resourceId])) {
                        continue;
                    }
                    $without = $active;
                    unset($without[$j]);
                    $esc = $escalatedForces;
                    unset($esc[$j]);
                    $probe = self::attempt($byId, $capacities, $pins->reference, $without,
                        self::ancestors($byId, array_keys($esc), $without), [], $projects, $successors, $naive);
                    if (!in_array($oldestLate, self::late($byId, $without, $probe->placed), true)) {
                        $victim = $j;
                        $related = [$oldestLate];
                    }
                }
                if ($victim === null) {
                    $victim = $youngestLate;
                    $related = self::lateDeps($byId, $victim, $active[$victim], $a->placed);
                }
                unset($escalatedForces[$victim]);
                $start = $active[$victim];
                unset($active[$victim]);
                $conflicts[] = new Conflict($victim, Conflict::KIND_NOT_FOUND, false, $related, $start, NAN);
            }
        }

        $out = [];
        foreach ($conflicts as $c) {
            $s = $a->placed[$c->operationId] ?? null;
            $out[] = $c->kind === Conflict::KIND_NOT_FOUND && $s !== null
                ? new Conflict($c->operationId, $c->kind, $c->proven, $c->related, $c->requestedStart, $s->start)
                : $c;
        }
        $honoured = array_keys($active);
        sort($honoured, SORT_STRING);
        $escalated = array_keys($urgent);
        sort($escalated, SORT_STRING);

        return new OptimizeResult(
            SchedulePlanner::result($a->placed, $a->lanes),
            $a->rules,
            $a->candidates,
            $out,
            array_fill_keys(array_map('strval', $honoured), true),
            array_fill_keys(array_map('strval', $escalated), true),
        );
    }

    /**
     * @param array<string, Task> $byId
     * @param array<string, int> $capacities
     * @param array<string, Ref> $reference
     * @param array<string, float> $active
     * @param array<string, true> $urgent
     * @param array<string, float> $release
     * @param array<int, array<string, mixed>> $projects
     * @param array<string, string[]> $successors
     */
    private static function attempt(array $byId, array $capacities, array $reference, array $active, array $urgent,
                                    array $release, array $projects, array $successors, bool $naive): object
    {
        $lanes = new Lanes($capacities);
        $placed = [];
        SchedulePlanner::placeRunning(array_values($byId), $placed, $lanes);
        foreach ($active as $id => $start) { // already in (start, id) order
            $t = $byId[$id];
            $s = SchedulePlanner::placeAt($lanes, $t->resourceId, (float) $start, $t->hours);
            if ($s !== null) { // ForceCheck guarantees a free lane; if not, late() reports the FORCE
                $placed[$id] = $s;
            }
        }
        $rel = $release;
        foreach ($active as $id => $start) {
            if (!isset($placed[$id])) {
                $rel[$id] = (float) $start;
            }
        }
        if ($urgent !== []) {
            $ls = self::latestStarts($byId, $urgent, $active, $successors);
            $edf = static function (string $a, string $b) use ($ls): int {
                return ($ls[$a] ?? PHP_FLOAT_MAX) <=> ($ls[$b] ?? PHP_FLOAT_MAX) ?: strcmp($a, $b);
            };
            $pending = $urgent;
            SchedulePlanner::pass($byId, $pending, $placed, $lanes, $edf, $rel);
        }

        $pending = [];
        foreach ($projects as $p) {
            $ids = [];
            foreach ($p['tasks'] as $t) {
                if (!isset($placed[$t->id])) {
                    $ids[$t->id] = true;
                }
            }
            $pending[$p['id']] = $ids;
        }

        $current = $placed;
        $currentLanes = $lanes;
        $chosen = [];
        $candidates = 0;
        $count = count($projects);
        for ($k = 0; $k < $count; $k++) {
            $project = $projects[$k];
            $mine = $pending[$project['id']];
            if ($mine === []) {
                continue;
            }
            $options = [self::candidate(self::RULE_CRITICAL_PATH, $project['criticalPath'])];
            if (!$naive) {
                foreach (self::alternatives($project, $byId) as $alt) {
                    $options[] = $alt;
                }
                $hasRef = false;
                foreach (array_keys($mine) as $id) {
                    if (isset($reference[$id])) {
                        $hasRef = true;
                        break;
                    }
                }
                if ($hasRef) {
                    $keep = [];
                    foreach (array_keys($mine) as $id) {
                        if (isset($reference[$id])) {
                            $keep[$id] = $reference[$id]->start;
                        }
                    }
                    $options[] = self::candidate(self::RULE_STABLE, self::stable($project, $reference), $keep);
                }
                // work the look-ahead costs per trial: this project plus every project behind it
                $n0 = count($mine);
                $work = $n0 * $n0;
                for ($j = $k + 1; $j < $count; $j++) {
                    $n = count($pending[$projects[$j]['id']]);
                    $work += $n * $n;
                }
                $randomTrials = (int) min(self::MAX_RANDOM_TRIALS, intdiv(self::RANDOM_WORK_BUDGET, max(1, $work)));
                $ids = array_map('strval', array_keys($mine));
                sort($ids, SORT_STRING); // sorted: the keys do not depend on input order
                for ($r = 0; $r < $randomTrials; $r++) {
                    $rnd = new JavaRandom(31 * $r + 7 * $project['rank'] + 1);
                    $key = [];
                    foreach ($ids as $id) {
                        $key[$id] = ($project['tail'][$id] ?? 0.0) * (1 + 0.6 * $rnd->nextDouble());
                    }
                    $options[] = self::candidate(self::RULE_RANDOMIZED,
                        static function (string $a, string $b) use ($key): int {
                            return ($key[$b] ?? 0.0) <=> ($key[$a] ?? 0.0) ?: strcmp($a, $b);
                        });
                }
            }

            $best = null;
            $behind = array_slice($projects, $k + 1);
            foreach ($options as $c) {
                $t = self::trial($c, $byId, $project, $behind, $pending, $current, $currentLanes, $reference, $rel);
                $candidates++;
                if ($best === null || self::better($t['score'], $best['score'])) {
                    $best = $t;
                }
            }
            $current = $best['placed'];
            $currentLanes = $best['lanes'];
            $chosen[$project['id']] = $best['rule'];
        }

        return (object) ['placed' => $current, 'lanes' => $currentLanes, 'rules' => $chosen, 'candidates' => $candidates];
    }

    // ---- candidates & scoring ----------------------------------------------------------------------------------

    /** @param array<string, float> $release extra "not before" times of this candidate (stable rule), or empty */
    private static function candidate(string $rule, callable $priority, array $release = []): array
    {
        return ['rule' => $rule, 'priority' => $priority, 'release' => $release];
    }

    /**
     * Places $project with the candidate rule, then completes the schedule with the critical-path rule for the projects
     * behind it, and scores that complete schedule. Only the placement of $project is kept.
     */
    private static function trial(array $c, array $byId, array $project, array $behind, array $pending, array $placed,
                                  Lanes $lanes, array $reference, array $release): array
    {
        $l = $lanes->copy();
        $p = $placed;
        $rel = $release;
        foreach ($c['release'] as $id => $v) {
            $rel[$id] = isset($rel[$id]) ? max($rel[$id], $v) : $v;
        }
        $mine = $pending[$project['id']];
        SchedulePlanner::pass($byId, $mine, $p, $l, $c['priority'], $rel);

        $simLanes = $l->copy();
        $sim = $p;
        foreach ($behind as $later) {
            $pl = $pending[$later['id']];
            SchedulePlanner::pass($byId, $pl, $sim, $simLanes, $later['criticalPath'], $release);
        }

        $finishes = [self::finishOf($project, $sim)];
        foreach ($behind as $later) {
            $finishes[] = self::finishOf($later, $sim);
        }
        $sum = 0.0;
        foreach ($project['tasks'] as $t) {
            if (isset($p[$t->id])) {
                $sum += $p[$t->id]->end;
            }
        }

        return [
            'rule' => $c['rule'],
            'placed' => $p,
            'lanes' => $l,
            'score' => [
                'finishes' => $finishes,
                'churn' => self::churn($pending[$project['id']], $p, $reference),
                'waste' => self::waste($simLanes),
                'sumEnds' => $sum,
            ],
        ];
    }

    /** @return array{committed: int, moved: int, shift: float} */
    private static function churn(array $ids, array $placed, array $reference): array
    {
        $committed = 0;
        $moved = 0;
        $shift = 0.0;
        foreach (array_keys($ids) as $id) {
            $r = $reference[$id] ?? null;
            $s = $placed[$id] ?? null;
            if ($r === null || $s === null) {
                continue;
            }
            $d = abs($s->start - max(0.0, $r->start));
            if ($d > self::MOVE_EPS) {
                $moved++;
                $shift += $d;
                if ($r->committed) {
                    $committed++;
                }
            }
        }

        return ['committed' => $committed, 'moved' => $moved, 'shift' => $shift];
    }

    private static function finishOf(array $project, array $placed): float
    {
        $f = 0.0;
        foreach ($project['tasks'] as $t) {
            if (isset($placed[$t->id])) {
                $f = max($f, $placed[$t->id]->end);
            }
        }

        return $f;
    }

    /** Idle lane time up to each lane's last task: the capacity that existed but could not be used. */
    public static function waste(Lanes $lanes): float
    {
        $waste = 0.0;
        foreach ($lanes->all() as $resource) {
            foreach ($resource as $lane) {
                $end = 0.0;
                $busy = 0.0;
                foreach ($lane as $iv) {
                    $busy += $iv[1] - $iv[0];
                    $end = max($end, $iv[1]);
                }
                $waste += $end - $busy;
            }
        }

        return $waste;
    }

    private static function better(array $a, array $b): bool
    {
        foreach ($a['finishes'] as $i => $x) {
            $y = $b['finishes'][$i];
            if (abs($x - $y) > self::EPS) {
                return $x < $y;
            }
        }
        if ($a['churn']['committed'] !== $b['churn']['committed']) {
            return $a['churn']['committed'] < $b['churn']['committed'];
        }
        if ($a['churn']['moved'] !== $b['churn']['moved']) {
            return $a['churn']['moved'] < $b['churn']['moved'];
        }
        if (abs($a['churn']['shift'] - $b['churn']['shift']) > 1e-6) {
            return $a['churn']['shift'] < $b['churn']['shift'];
        }
        if (abs($a['waste'] - $b['waste']) > self::EPS) {
            return $a['waste'] < $b['waste'];
        }

        return $a['sumEnds'] < $b['sumEnds'] - self::EPS;
    }

    /** Tail-first order: longest remaining chain first, id as tie-break. */
    private static function criticalPath(array $tail): callable
    {
        return static function (string $a, string $b) use ($tail): int {
            return ($tail[$b] ?? 0.0) <=> ($tail[$a] ?? 0.0) ?: strcmp($a, $b);
        };
    }

    /** @return array<int, array{rule: string, priority: callable, release: array}> */
    private static function alternatives(array $p, array $byId): array
    {
        $tail = $p['tail'];
        $desc = $p['descendants'];
        return [
            self::candidate(self::RULE_LONGEST_FIRST, static function (string $a, string $b) use ($byId, $tail): int {
                return ($byId[$b]->hours <=> $byId[$a]->hours) ?: (($tail[$b] ?? 0.0) <=> ($tail[$a] ?? 0.0)) ?: strcmp($a, $b);
            }),
            self::candidate(self::RULE_SHORTEST_FIRST, static function (string $a, string $b) use ($byId, $tail): int {
                return ($byId[$a]->hours <=> $byId[$b]->hours) ?: (($tail[$b] ?? 0.0) <=> ($tail[$a] ?? 0.0)) ?: strcmp($a, $b);
            }),
            self::candidate(self::RULE_MOST_SUCCESSORS, static function (string $a, string $b) use ($desc, $tail): int {
                return (($desc[$b] ?? 0) <=> ($desc[$a] ?? 0)) ?: (($tail[$b] ?? 0.0) <=> ($tail[$a] ?? 0.0)) ?: strcmp($a, $b);
            }),
        ];
    }

    /**
     * Reference-plan order; together with the reference starts as "not before" times this replays the reference plan
     * exactly where it is still feasible and shifts work only as far as the changes force it. New operations follow by
     * critical path.
     */
    private static function stable(array $p, array $reference): callable
    {
        $cp = self::criticalPath($p['tail']);
        return static function (string $a, string $b) use ($reference, $cp): int {
            $r = (isset($reference[$a]) ? 0 : 1) <=> (isset($reference[$b]) ? 0 : 1);
            if ($r !== 0) {
                return $r;
            }
            $sa = isset($reference[$a]) ? $reference[$a]->start : 0.0;
            $sb = isset($reference[$b]) ? $reference[$b]->start : 0.0;

            return $sa <=> $sb ?: $cp($a, $b);
        };
    }

    // ---- FORCE helpers -----------------------------------------------------------------------------------------

    /**
     * FORCEs not placed at their start, or whose predecessors finish (FS) / start (SS) after it.
     *
     * @return string[]
     */
    private static function late(array $byId, array $active, array $placed): array
    {
        $late = [];
        foreach ($active as $id => $start) {
            $s = $placed[$id] ?? null;
            if ($s === null || abs($s->start - $start) > ForceCheck::EPS || self::lateDeps($byId, (string) $id, $start, $placed) !== []) {
                $late[] = (string) $id;
            }
        }

        return $late;
    }

    /** @return string[] */
    private static function lateDeps(array $byId, string $id, float $start, array $placed): array
    {
        $out = [];
        foreach ($byId[$id]->deps as $d) {
            if (!isset($byId[$d->predecessorId])) {
                continue;
            }
            $p = $placed[$d->predecessorId] ?? null;
            $bound = $p === null ? PHP_FLOAT_MAX : ($d->type === Dep::START_TO_START ? $p->start : $p->end);
            if ($bound > $start + ForceCheck::EPS) {
                $out[] = $d->predecessorId;
            }
        }

        return $out;
    }

    /**
     * Resources of the operation and of all its (transitive) predecessors.
     *
     * @return array<string, true>
     */
    private static function resourcesOf(array $byId, string $id): array
    {
        $out = [];
        $seen = [];
        $stack = [$id];
        while ($stack !== []) {
            $t = $byId[array_pop($stack)] ?? null;
            if ($t === null || isset($seen[$t->id])) {
                continue;
            }
            $seen[$t->id] = true;
            $out[$t->resourceId] = true;
            foreach ($t->deps as $d) {
                $stack[] = $d->predecessorId;
            }
        }

        return $out;
    }

    /**
     * Unplaced predecessors (transitively) of the escalated FORCEs; stops at running and other FORCEd work.
     *
     * @param string[] $forces
     * @return array<string, true> sorted set
     */
    private static function ancestors(array $byId, array $forces, array $active): array
    {
        $out = [];
        $stack = $forces;
        while ($stack !== []) {
            $t = $byId[array_pop($stack)] ?? null;
            if ($t === null) {
                continue;
            }
            foreach ($t->deps as $d) {
                $p = $byId[$d->predecessorId] ?? null;
                if ($p !== null && !$p->running && !isset($active[$p->id]) && !isset($out[$p->id])) {
                    $out[$p->id] = true;
                    $stack[] = $p->id;
                }
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Latest start of each escalated predecessor that still lets every FORCE downstream of it start on time.
     *
     * @return array<string, float>
     */
    private static function latestStarts(array $byId, array $urgent, array $active, array $successors): array
    {
        $memo = [];
        foreach (array_keys($urgent) as $id) {
            self::latest((string) $id, $byId, $urgent, $active, $successors, $memo, []);
        }

        return $memo;
    }

    private static function latest(string $id, array $byId, array $urgent, array $active, array $successors,
                                   array &$memo, array $visiting): float
    {
        if (array_key_exists($id, $memo)) {
            return $memo[$id];
        }
        $v = PHP_FLOAT_MAX;
        if (!isset($visiting[$id])) {
            $visiting[$id] = true;
            $h = $byId[$id]->hours;
            foreach ($successors[$id] ?? [] as $s) {
                $ss = false;
                foreach ($byId[$s]->deps as $d) {
                    if ($d->predecessorId === $id && $d->type === Dep::START_TO_START) {
                        $ss = true;
                        break;
                    }
                }
                if (isset($active[$s])) {
                    $bound = (float) $active[$s];
                } elseif (isset($urgent[$s])) {
                    $bound = self::latest($s, $byId, $urgent, $active, $successors, $memo, $visiting);
                } else {
                    $bound = null;
                }
                if ($bound !== null && $bound < PHP_FLOAT_MAX) {
                    $v = min($v, $ss ? $bound : $bound - $h);
                }
            }
        }
        $memo[$id] = $v;

        return $v;
    }

    // ---- structure ---------------------------------------------------------------------------------------------

    /**
     * Projects in priority order, each with its tasks, tails, descendant counts and critical-path comparator.
     *
     * @param array<string, Task> $byId
     * @return array<int, array<string, mixed>>
     */
    private static function projects(array $byId): array
    {
        $grouped = [];
        $rankOf = [];
        foreach ($byId as $t) {
            $grouped[$t->projectId][] = $t;
            $rankOf[$t->projectId] = isset($rankOf[$t->projectId]) ? min($rankOf[$t->projectId], $t->projectRank) : $t->projectRank;
        }
        ksort($grouped, SORT_STRING);
        $projects = [];
        foreach ($grouped as $pid => $tasks) {
            $tail = SchedulePlanner::tails($tasks);
            $projects[] = [
                'id' => (string) $pid,
                'rank' => $rankOf[$pid],
                'tasks' => $tasks,
                'tail' => $tail,
                'descendants' => self::descendants($tasks),
                'criticalPath' => self::criticalPath($tail),
            ];
        }
        usort($projects, static fn (array $a, array $b): int => ($a['rank'] <=> $b['rank']) ?: strcmp($a['id'], $b['id']));

        return $projects;
    }

    /**
     * @param array<string, Task> $byId
     * @return array<string, string[]> operation id => ids of operations depending on it
     */
    private static function successors(array $byId): array
    {
        $succ = [];
        foreach ($byId as $t) {
            foreach ($t->deps as $d) {
                if (isset($byId[$d->predecessorId])) {
                    $succ[$d->predecessorId][] = $t->id;
                }
            }
        }

        return $succ;
    }

    /**
     * Number of (transitive) successors of each task inside its project.
     *
     * @param Task[] $tasks
     * @return array<string, int>
     */
    private static function descendants(array $tasks): array
    {
        $succ = [];
        $ids = [];
        foreach ($tasks as $t) {
            $ids[$t->id] = true;
        }
        foreach ($tasks as $t) {
            foreach ($t->deps as $d) {
                if (isset($ids[$d->predecessorId])) {
                    $succ[$d->predecessorId][] = $t->id;
                }
            }
        }
        $out = [];
        foreach (array_keys($ids) as $id) {
            $seen = [];
            $stack = $succ[$id] ?? [];
            while ($stack !== []) {
                $x = array_pop($stack);
                if (!isset($seen[$x])) {
                    $seen[$x] = true;
                    foreach ($succ[$x] ?? [] as $y) {
                        $stack[] = $y;
                    }
                }
            }
            $out[(string) $id] = count($seen);
        }

        return $out;
    }
}
