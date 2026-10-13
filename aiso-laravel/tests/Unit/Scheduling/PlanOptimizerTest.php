<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Dep;
use App\Scheduling\PlanOptimizer;
use App\Scheduling\Slot;
use App\Scheduling\Task;
use PHPUnit\Framework\TestCase;

/** Port of PlanOptimizerTest.java. */
class PlanOptimizerTest extends TestCase
{
    /** @param string[] $preds */
    private static function t(string $id, string $project, int $rank, string $res, float $hours, array $preds = []): Task
    {
        $deps = [];
        foreach ($preds as $p) {
            $deps[] = new Dep($p, Dep::FINISH_TO_START);
        }

        return new Task($id, $project, $rank, $res, $hours, false, $deps);
    }

    /** @param Task[] $tasks */
    private static function finish(object $plan, array $tasks, string $project): float
    {
        $max = 0.0;
        foreach ($tasks as $x) {
            if ($x->projectId === $project) {
                $max = max($max, $plan->slots[$x->id]->end);
            }
        }

        return $max;
    }

    public function test_higher_priority_project_is_never_delayed_by_a_lower_one(): void
    {
        $p1 = [self::t('A', 'P1', 1, 'R', 4), self::t('B', 'P1', 1, 'R', 4, ['A'])];
        $p2 = [self::t('C', 'P2', 2, 'R', 6), self::t('D', 'P2', 2, 'R', 6, ['C'])];
        $both = array_merge($p1, $p2);
        $alone = self::finish(PlanOptimizer::optimize($p1, ['R' => 1])->plan, $p1, 'P1');
        $together = PlanOptimizer::optimize($both, ['R' => 1])->plan;
        $this->assertSame(8.0, $alone);
        $this->assertSame(8.0, self::finish($together, $both, 'P1'));
        $this->assertSame(20.0, self::finish($together, $both, 'P2')); // runs after P1 on the single lane
    }

    /**
     * X (5h on S) then A (1h on R) belong to P1; B and C (3h each, C after B) to P2. By priority A starts at 5 and the
     * 2 idle hours before it on R cannot be used by C. Pooled, C goes first and A moves behind it: R is never idle.
     */
    private static function idleRiskTasks(): array
    {
        return [
            self::t('X', 'P1', 1, 'S', 5), self::t('A', 'P1', 1, 'R', 1, ['X']),
            self::t('B', 'P2', 2, 'R', 3), self::t('C', 'P2', 2, 'R', 3, ['B']),
        ];
    }

    public function test_priority_goal_keeps_the_higher_project_on_time_and_leaves_resources_idle(): void
    {
        $tasks = self::idleRiskTasks();
        $plan = PlanOptimizer::optimize($tasks, ['R' => 1, 'S' => 1])->plan;
        $this->assertSame(6.0, self::finish($plan, $tasks, 'P1'));
        $this->assertSame(2.0, PlanOptimizer::waste($plan->lanes));
    }

    public function test_resource_goal_packs_the_pool_even_when_a_higher_project_finishes_later(): void
    {
        $tasks = self::idleRiskTasks();
        $result = PlanOptimizer::optimize($tasks, ['R' => 1, 'S' => 1], null, true);
        $this->assertSame(0.0, PlanOptimizer::waste($result->plan->lanes));
        $this->assertSame(7.0, self::finish($result->plan, $tasks, 'P1')); // A waited for C
        $this->assertSame(7.0, $result->plan->makespan);
        // every project reports the rule that scheduled the pool
        $this->assertSame(['P1', 'P2'], array_keys($result->rules));
    }

    public function test_resource_goal_still_respects_capacity_and_dependencies(): void
    {
        $tasks = self::idleRiskTasks();
        $plan = PlanOptimizer::optimize($tasks, ['R' => 1, 'S' => 1], null, true)->plan;
        $this->assertGreaterThanOrEqual($plan->slots['X']->end, $plan->slots['A']->start);
        $this->assertGreaterThanOrEqual($plan->slots['B']->end, $plan->slots['C']->start);
        foreach (['A', 'B', 'C'] as $x) { // one lane on R: no two of its tasks overlap
            foreach (['A', 'B', 'C'] as $y) {
                if ($x < $y) {
                    $this->assertTrue($plan->slots[$x]->end <= $plan->slots[$y]->start + 1e-9 || $plan->slots[$y]->end <= $plan->slots[$x]->start + 1e-9);
                }
            }
        }
    }

    public function test_swapping_priorities_swaps_who_waits(): void
    {
        $tasks = [self::t('A', 'P1', 2, 'R', 4), self::t('C', 'P2', 1, 'R', 6)];
        $p = PlanOptimizer::optimize($tasks, ['R' => 1])->plan;
        $this->assertEquals(new Slot(0.0, 6.0), $p->slots['C']);
        $this->assertEquals(new Slot(6.0, 10.0), $p->slots['A']);
    }

    public function test_lower_priority_work_fills_idle_gaps_of_higher_priority_work(): void
    {
        // X (10h on S) then A (5h on R). R is idle for 10h. P2 has B (4h on R): it must use that gap.
        $tasks = [self::t('X', 'P1', 1, 'S', 10), self::t('A', 'P1', 1, 'R', 5, ['X']), self::t('B', 'P2', 2, 'R', 4)];
        $p = PlanOptimizer::optimize($tasks, ['R' => 1, 'S' => 1])->plan;
        $this->assertEquals(new Slot(0.0, 4.0), $p->slots['B']);
        $this->assertEquals(new Slot(10.0, 15.0), $p->slots['A']);
        $this->assertSame(15.0, self::finish($p, $tasks, 'P1')); // B did not delay P1
    }

    public function test_running_work_is_never_moved_by_a_new_higher_priority_project(): void
    {
        $running = new Task('RUN', 'P2', 2, 'R', 5, true, []);
        $urgent = self::t('U', 'P1', 1, 'R', 3);
        $p = PlanOptimizer::optimize([$running, $urgent], ['R' => 1])->plan;
        $this->assertEquals(new Slot(0.0, 5.0), $p->slots['RUN']);
        $this->assertEquals(new Slot(5.0, 8.0), $p->slots['U']);
    }

    public function test_result_is_reproducible(): void
    {
        $tasks = self::random(3, 10, 11);
        $caps = ['R1' => 1, 'R2' => 2, 'R3' => 1];
        $this->assertEquals(
            PlanOptimizer::optimize($tasks, $caps)->plan->slots,
            PlanOptimizer::optimize($tasks, $caps)->plan->slots,
        );
    }

    public function test_never_worse_than_naive_for_the_highest_priority_projects_and_always_feasible(): void
    {
        $caps = ['R1' => 1, 'R2' => 2, 'R3' => 1];
        for ($seed = 0; $seed < 60; $seed++) {
            $tasks = self::random(3, 9, $seed);
            $naive = \App\Scheduling\SchedulePlanner::plan($tasks, $caps);
            $opt = PlanOptimizer::optimize($tasks, $caps)->plan;
            foreach (['P1', 'P2', 'P3'] as $project) {
                $o = self::finish($opt, $tasks, $project);
                $n = self::finish($naive, $tasks, $project);
                if (abs($o - $n) > 1e-9) {
                    // only the project(s) the optimiser trades away may get later; P1 must never be worse
                    if ($project === 'P1') {
                        $this->assertLessThanOrEqual($n, $o, "seed {$seed} project {$project}");
                    }
                }
            }
            $this->assertFeasible($tasks, $opt, $caps);
        }
    }

    /** @param Task[] $tasks */
    private function assertFeasible(array $tasks, object $plan, array $caps): void
    {
        $this->assertCount(count($tasks), $plan->slots);
        foreach ($tasks as $task) {
            $s = $plan->slots[$task->id];
            $this->assertEqualsWithDelta($task->hours, $s->end - $s->start, 1e-9);
            foreach ($task->deps as $d) {
                $this->assertGreaterThanOrEqual($plan->slots[$d->predecessorId]->end - 1e-9, $s->start);
            }
        }
        foreach ($plan->lanes->all() as $res => $lanes) {
            $this->assertCount($caps[$res] ?? 1, $lanes);
            foreach ($lanes as $lane) {
                usort($lane, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                for ($i = 1; $i < count($lane); $i++) {
                    $this->assertGreaterThanOrEqual($lane[$i - 1][1] - 1e-9, $lane[$i][0]);
                }
            }
        }
    }

    /**
     * Random projects of up to 10 tasks on three resources; each task may depend on earlier tasks of the same project.
     *
     * @return Task[]
     */
    private static function random(int $projects, int $perProject, int $seed): array
    {
        $rnd = new \App\Scheduling\JavaRandom($seed);
        $res = ['R1', 'R2', 'R3'];
        $tasks = [];
        for ($p = 1; $p <= $projects; $p++) {
            for ($i = 0; $i < $perProject; $i++) {
                $preds = [];
                for ($j = 0; $j < $i; $j++) {
                    if ($rnd->nextDouble() < 0.25) {
                        $preds[] = "P{$p}-{$j}";
                    }
                }
                $resource = $res[(int) floor($rnd->nextDouble() * 3)];
                $hours = 1 + (int) floor($rnd->nextDouble() * 8);
                $tasks[] = self::t("P{$p}-{$i}", "P{$p}", $p, $resource, (float) $hours, $preds);
            }
        }

        return $tasks;
    }
}
