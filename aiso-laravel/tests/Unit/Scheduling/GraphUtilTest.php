<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\GraphUtil;
use App\Scheduling\JavaRandom;
use PHPUnit\Framework\TestCase;

/** Port of GraphUtilTest.java, plus the java.util.Random sequence the randomised rule depends on. */
class GraphUtilTest extends TestCase
{
    public function test_finds_cycle_and_returns_closed_path(): void
    {
        $preds = ['A' => ['C'], 'B' => ['A'], 'C' => ['B'], 'D' => []];
        $cycle = GraphUtil::findCycle($preds);
        $this->assertNotEmpty($cycle);
        $this->assertSame($cycle[0], $cycle[count($cycle) - 1]);
        $this->assertContains('A', $cycle);
        $this->assertContains('B', $cycle);
        $this->assertContains('C', $cycle);
        $this->assertNotContains('D', $cycle);
    }

    public function test_acyclic_graph_has_no_cycle(): void
    {
        $this->assertSame([], GraphUtil::findCycle(['A' => [], 'B' => ['A'], 'C' => ['A', 'B']]));
    }

    public function test_self_loop_is_a_cycle(): void
    {
        $this->assertSame(['A', 'A'], GraphUtil::findCycle(['A' => ['A']]));
    }

    public function test_tail_is_longest_chain_including_self(): void
    {
        // A(2) -> B(3) -> D(1)   and   A -> C(10) -> D
        $hours = ['A' => 2.0, 'B' => 3.0, 'C' => 10.0, 'D' => 1.0];
        $succ = ['A' => ['B', 'C'], 'B' => ['D'], 'C' => ['D']];
        $tail = GraphUtil::tailHours($hours, $succ);
        $this->assertSame(1.0, $tail['D']);
        $this->assertSame(11.0, $tail['C']);
        $this->assertSame(4.0, $tail['B']);
        $this->assertSame(13.0, $tail['A']);
    }

    public function test_java_random_matches_the_java_sequence(): void
    {
        // new java.util.Random(42).nextDouble() == 0.7275636800328681 (Java 8+ documented sequence)
        $this->assertEqualsWithDelta(0.7275636800328681, (new JavaRandom(42))->nextDouble(), 1e-15);
    }
}
