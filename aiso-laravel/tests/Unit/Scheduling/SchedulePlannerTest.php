<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Dep;
use App\Scheduling\SchedulePlanner;
use App\Scheduling\Task;
use PHPUnit\Framework\TestCase;

/** Port of SchedulePlannerTest.java. */
class SchedulePlannerTest extends TestCase
{
    private static function task(string $id, string $res, float $hours, Dep ...$deps): Task
    {
        return new Task($id, '', 0, $res, $hours, false, array_values($deps));
    }

    private static function fs(string $pred): Dep
    {
        return new Dep($pred, Dep::FINISH_TO_START);
    }

    public function test_chain_runs_sequentially(): void
    {
        $p = SchedulePlanner::plan([self::task('A', 'R', 4), self::task('B', 'R', 3, self::fs('A'))], ['R' => 1]);
        $this->assertSame(4.0, $p->slots['A']->end);
        $this->assertSame(4.0, $p->slots['B']->start);
        $this->assertSame(7.0, $p->makespan);
    }

    public function test_capacity_one_serialises_independent_tasks(): void
    {
        $p = SchedulePlanner::plan([self::task('A', 'R', 5), self::task('B', 'R', 5)], ['R' => 1]);
        $this->assertSame(10.0, $p->makespan);
    }

    public function test_capacity_two_runs_in_parallel(): void
    {
        $p = SchedulePlanner::plan([self::task('A', 'R', 5), self::task('B', 'R', 5)], ['R' => 2]);
        $this->assertSame(5.0, $p->makespan);
    }

    public function test_critical_chain_is_placed_first_on_scarce_resource(): void
    {
        // X has a long tail (X -> Y 20h); Z has none. With one slot X must go first.
        $p = SchedulePlanner::plan([
            self::task('Z', 'R', 5),
            self::task('X', 'R', 5),
            self::task('Y', 'S', 20, self::fs('X')),
        ], ['R' => 1, 'S' => 1]);
        $this->assertSame(0.0, $p->slots['X']->start);
        $this->assertSame(5.0, $p->slots['Z']->start);
        $this->assertSame(25.0, $p->makespan);
    }

    public function test_start_to_start_only_waits_for_predecessor_start(): void
    {
        $p = SchedulePlanner::plan([
            self::task('A', 'R1', 10),
            new Task('B', '', 0, 'R2', 4, false, [new Dep('A', Dep::START_TO_START)]),
        ], ['R1' => 1, 'R2' => 1]);
        $this->assertSame(0.0, $p->slots['B']->start);
    }

    public function test_dependency_on_finished_work_is_ignored(): void
    {
        $p = SchedulePlanner::plan([self::task('B', 'R', 3, self::fs('DONE'))], ['R' => 1]);
        $this->assertSame(0.0, $p->slots['B']->start);
    }

    public function test_running_task_occupies_its_slot_for_remaining_hours(): void
    {
        $running = new Task('RUN', '', 0, 'R', 6, true, []);
        $p = SchedulePlanner::plan([$running, self::task('NEXT', 'R', 2)], ['R' => 1]);
        $this->assertEqualsWithDelta(6.0, $p->slots['NEXT']->start, 1e-9);
    }

    public function test_low_priority_task_fills_an_idle_gap_before_a_later_high_priority_one(): void
    {
        // X (other resource, 10h) -> A (R, 5h). B (R, 4h) is independent: R is idle until A is ready at t=10,
        // so B must run in [0,4] instead of queueing behind A.
        $p = SchedulePlanner::plan([
            self::task('X', 'S', 10),
            self::task('A', 'R', 5, self::fs('X')),
            self::task('B', 'R', 4),
        ], ['R' => 1, 'S' => 1]);
        $this->assertSame(10.0, $p->slots['A']->start);
        $this->assertSame(0.0, $p->slots['B']->start);
        $this->assertSame(15.0, $p->makespan);
    }

    public function test_gap_too_small_is_not_used(): void
    {
        // the gap before A (t=0..3) is shorter than B (4h): B has to go after A
        $p = SchedulePlanner::plan([
            self::task('X', 'S', 3),
            self::task('A', 'R', 5, self::fs('X')),
            self::task('B', 'R', 4),
        ], ['R' => 1, 'S' => 1]);
        $this->assertSame(3.0, $p->slots['A']->start);
        $this->assertSame(8.0, $p->slots['B']->start);
    }
}
