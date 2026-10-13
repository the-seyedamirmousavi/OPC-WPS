<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Dep;
use App\Scheduling\Pins;
use App\Scheduling\PlanOptimizer;
use App\Scheduling\Task;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScheduleScenarios as S;

/**
 * The scheduler on realistic workshops: generated ones (many projects, a bottleneck, lanes of 1 to 3, chains, branches,
 * joins, start-to-start links, running work) and named situations from the shop floor.
 */
class SchedulingScenariosTest extends TestCase
{
    private static function t(string $id, string $project, int $rank, string $res, float $hours, array $preds = [], bool $running = false,
                              bool $held = false, float $release = 0.0): Task
    {
        $deps = array_map(static fn ($p) => is_array($p) ? new Dep($p[0], $p[1]) : new Dep($p), $preds);

        return new Task($id, $project, $rank, $res, $hours, $running, $deps, $held, $release);
    }

    public function test_every_generated_workshop_gets_a_valid_plan_in_every_mode(): void
    {
        foreach ([[3, 8, 4], [4, 15, 6], [5, 25, 8]] as [$p, $n, $r]) {
            for ($seed = 1; $seed <= 12; $seed++) {
                $w = S::workshop($seed * 101 + $n, $p, $n, $r);
                foreach (['priority' => PlanOptimizer::optimize($w['tasks'], $w['capacities']),
                          'resources' => PlanOptimizer::optimize($w['tasks'], $w['capacities'], null, true),
                          'naive' => PlanOptimizer::naive($w['tasks'], $w['capacities'], Pins::none())] as $mode => $r2) {
                    $this->assertSame([], S::violations($r2->plan, $w['tasks'], $w['capacities'], $w['held']), "seed {$seed}, {$p}x{$n}, {$mode}");
                }
            }
        }
    }

    public function test_the_optimiser_never_makes_a_higher_priority_project_later_than_the_simple_plan(): void
    {
        for ($seed = 1; $seed <= 25; $seed++) {
            $w = S::workshop($seed * 37, 4, 15, 6);
            $opt = S::finishes(PlanOptimizer::optimize($w['tasks'], $w['capacities'])->plan, $w['tasks']);
            $naive = S::finishes(PlanOptimizer::naive($w['tasks'], $w['capacities'], Pins::none())->plan, $w['tasks']);
            // lexicographic in priority order: the first project that differs must be earlier
            foreach ($naive as $project => $f) {
                if (abs($opt[$project] - $f) > 1e-6) {
                    $this->assertLessThan($f, $opt[$project], "seed {$seed}: {$project} got later than in the simple plan");
                    break;
                }
            }
        }
    }

    public function test_plans_stay_close_to_what_is_possible_at_all(): void
    {
        $priority = [];
        $resources = [];
        for ($seed = 1; $seed <= 20; $seed++) {
            $w = S::workshop($seed * 53, 4, 15, 6);
            $lb = S::lowerBound($w['tasks'], $w['capacities']);
            $priority[] = PlanOptimizer::optimize($w['tasks'], $w['capacities'])->plan->makespan / $lb;
            $resources[] = PlanOptimizer::optimize($w['tasks'], $w['capacities'], null, true)->plan->makespan / $lb;
        }
        // measured 2026-10-13: priority goal 1.11, resource goal 1.01 on average (strict priority costs some length)
        $this->assertLessThan(1.25, array_sum($priority) / count($priority));
        $this->assertLessThan(1.08, array_sum($resources) / count($resources));
    }

    public function test_a_rush_order_never_moves_running_work(): void
    {
        $tasks = [
            self::t('RUN', 'OLD', 3, 'M', 4, [], true),
            self::t('RUSH', 'NEW', 1, 'M', 2),
        ];
        $plan = PlanOptimizer::optimize($tasks, ['M' => 1])->plan;

        $this->assertSame(0.0, $plan->slots['RUN']->start);
        $this->assertSame(4.0, $plan->slots['RUSH']->start);
    }

    public function test_assigned_work_keeps_its_resource_when_more_urgent_work_arrives(): void
    {
        // OP-11 is assigned (its executor holds the machine); a new top-priority operation arrives on the same machine
        $tasks = [
            self::t('OP-11', 'PRJ-2', 5, 'M', 1, [], false, true),
            self::t('NEW', 'LINE-A', 1, 'M', 1),
        ];
        $plan = PlanOptimizer::optimize($tasks, ['M' => 1])->plan;

        $this->assertSame(0.0, $plan->slots['OP-11']->start, 'the assigned work starts as planned');
        $this->assertSame(1.0, $plan->slots['NEW']->start, 'the new work waits for the machine to be free');
        $this->assertSame([], S::violations($plan, $tasks, ['M' => 1], ['OP-11' => true]));
    }

    public function test_assigned_work_on_a_two_lane_resource_leaves_the_other_lane_to_urgent_work(): void
    {
        $tasks = [
            self::t('HELD', 'LOW', 5, 'M', 3, [], false, true),
            self::t('URGENT', 'TOP', 1, 'M', 2),
        ];
        $plan = PlanOptimizer::optimize($tasks, ['M' => 2])->plan;

        $this->assertSame(0.0, $plan->slots['HELD']->start);
        $this->assertSame(0.0, $plan->slots['URGENT']->start);
    }

    public function test_blocked_work_waits_and_its_successors_with_it(): void
    {
        $tasks = [
            self::t('BLOCKED', 'P', 1, 'M', 2, [], false, false, 8.0),
            self::t('NEXT', 'P', 1, 'N', 1, ['BLOCKED']),
            self::t('OTHER', 'Q', 2, 'M', 3),
        ];
        $plan = PlanOptimizer::optimize($tasks, ['M' => 1, 'N' => 1])->plan;

        $this->assertGreaterThanOrEqual(8.0, $plan->slots['BLOCKED']->start);
        $this->assertGreaterThanOrEqual($plan->slots['BLOCKED']->end, $plan->slots['NEXT']->start);
        $this->assertSame(0.0, $plan->slots['OTHER']->start, 'the machine is used while the blocked work waits');
    }

    public function test_the_bottleneck_is_never_left_idle_while_work_for_it_is_waiting(): void
    {
        // five independent jobs from three projects on one machine: back to back, highest priority first
        $tasks = [];
        foreach ([['A', 'P1', 1, 2], ['B', 'P2', 2, 1], ['C', 'P3', 3, 3], ['D', 'P1', 1, 1], ['E', 'P2', 2, 2]] as [$id, $p, $rank, $h]) {
            $tasks[] = self::t($id, $p, $rank, 'BOTTLENECK', $h);
        }
        $plan = PlanOptimizer::optimize($tasks, ['BOTTLENECK' => 1])->plan;

        $this->assertSame(9.0, $plan->makespan, 'no idle time on the bottleneck');
        $this->assertLessThanOrEqual(3.0, max($plan->slots['A']->end, $plan->slots['D']->end), 'the top project goes first');
    }

    public function test_start_to_start_lets_work_overlap(): void
    {
        $tasks = [
            self::t('PAINT', 'P', 1, 'A', 4),
            self::t('DRY', 'P', 1, 'B', 4, [['PAINT', Dep::START_TO_START]]),
        ];
        $plan = PlanOptimizer::optimize($tasks, ['A' => 1, 'B' => 1])->plan;

        $this->assertSame(0.0, $plan->slots['DRY']->start);
        $this->assertSame(4.0, $plan->makespan);
    }

    public function test_a_long_chain_goes_first_so_parallel_work_fills_around_it(): void
    {
        // critical chain X1-X2-X3 (3x3h) and three 1h jobs share machine M; the chain must not wait behind them
        $tasks = [
            self::t('X1', 'P', 1, 'M', 3), self::t('X2', 'P', 1, 'N', 3, ['X1']), self::t('X3', 'P', 1, 'M', 3, ['X2']),
            self::t('S1', 'P', 1, 'M', 1), self::t('S2', 'P', 1, 'M', 1), self::t('S3', 'P', 1, 'M', 1),
        ];
        $plan = PlanOptimizer::optimize($tasks, ['M' => 1, 'N' => 1])->plan;

        $this->assertSame(0.0, $plan->slots['X1']->start);
        $this->assertSame(9.0, $plan->makespan, 'the short jobs fill the gap while X2 runs on N');
    }

    public function test_a_large_shop_is_planned_quickly_and_the_same_way_every_time(): void
    {
        $w = S::workshop(4242, 6, 40, 12);
        $t0 = microtime(true);
        $a = PlanOptimizer::optimize($w['tasks'], $w['capacities'])->plan;
        $seconds = microtime(true) - $t0;
        $b = PlanOptimizer::optimize($w['tasks'], $w['capacities'])->plan;

        $this->assertLessThan(5.0, $seconds, '240 operations on 12 resources');
        $this->assertSame([], S::violations($a, $w['tasks'], $w['capacities']));
        foreach ($a->slots as $id => $s) {
            $this->assertSame($s->start, $b->slots[$id]->start, "{$id} moved between two identical runs");
        }
    }
}
