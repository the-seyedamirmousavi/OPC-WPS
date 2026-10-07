package com.aiso.service;

import com.aiso.domain.DependencyType;
import com.aiso.service.SchedulePlanner.Dep;
import com.aiso.service.SchedulePlanner.Plan;
import com.aiso.service.SchedulePlanner.Task;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.within;

class SchedulePlannerTest {

    private static Task task(String id, String res, double hours, Dep... deps) {
        return new Task(id, res, hours, false, List.of(deps));
    }

    private static Dep fs(String pred) {
        return new Dep(pred, DependencyType.FINISH_TO_START);
    }

    @Test
    void chainRunsSequentially() {
        Plan p = SchedulePlanner.plan(List.of(task("A", "R", 4), task("B", "R", 3, fs("A"))), Map.of("R", 1));
        assertThat(p.slots().get("A").end()).isEqualTo(4);
        assertThat(p.slots().get("B").start()).isEqualTo(4);
        assertThat(p.makespan()).isEqualTo(7);
    }

    @Test
    void capacityOneSerialisesIndependentTasks() {
        Plan p = SchedulePlanner.plan(List.of(task("A", "R", 5), task("B", "R", 5)), Map.of("R", 1));
        assertThat(p.makespan()).isEqualTo(10);
    }

    @Test
    void capacityTwoRunsInParallel() {
        Plan p = SchedulePlanner.plan(List.of(task("A", "R", 5), task("B", "R", 5)), Map.of("R", 2));
        assertThat(p.makespan()).isEqualTo(5);
    }

    @Test
    void criticalChainIsPlacedFirstOnScarceResource() {
        // X has a long tail (X -> Y 20h); Z has none. With one slot X must go first.
        Plan p = SchedulePlanner.plan(List.of(
                task("Z", "R", 5), task("X", "R", 5), task("Y", "S", 20, fs("X"))), Map.of("R", 1, "S", 1));
        assertThat(p.slots().get("X").start()).isEqualTo(0);
        assertThat(p.slots().get("Z").start()).isEqualTo(5);
        assertThat(p.makespan()).isEqualTo(25);
    }

    @Test
    void startToStartOnlyWaitsForPredecessorStart() {
        Plan p = SchedulePlanner.plan(List.of(
                task("A", "R1", 10), task("B", "R2", 4, new Dep("A", DependencyType.START_TO_START))), Map.of("R1", 1, "R2", 1));
        assertThat(p.slots().get("B").start()).isEqualTo(0);
    }

    @Test
    void dependencyOnFinishedWorkIsIgnored() {
        Plan p = SchedulePlanner.plan(List.of(task("B", "R", 3, fs("DONE"))), Map.of("R", 1));
        assertThat(p.slots().get("B").start()).isEqualTo(0);
    }

    @Test
    void runningTaskOccupiesItsSlotForRemainingHours() {
        Task running = new Task("RUN", "R", 6, true, List.of());
        Plan p = SchedulePlanner.plan(List.of(running, task("NEXT", "R", 2)), Map.of("R", 1));
        assertThat(p.slots().get("NEXT").start()).isCloseTo(6, within(1e-9));
    }

    @Test
    void lowPriorityTaskFillsAnIdleGapBeforeALaterHighPriorityOne() {
        // X (other resource, 10h) -> A (R, 5h). B (R, 4h) is independent and less critical than A.
        // R is idle until A becomes ready at t=10, so B must run in [0,4] instead of queueing behind A.
        Plan p = SchedulePlanner.plan(List.of(
                task("X", "S", 10), task("A", "R", 5, fs("X")), task("B", "R", 4)), Map.of("R", 1, "S", 1));
        assertThat(p.slots().get("A").start()).isEqualTo(10);
        assertThat(p.slots().get("B").start()).isEqualTo(0);
        assertThat(p.makespan()).isEqualTo(15);
    }

    @Test
    void gapTooSmallIsNotUsed() {
        // the gap before A (t=0..3) is shorter than B (4h): B has to go after A
        Plan p = SchedulePlanner.plan(List.of(
                task("X", "S", 3), task("A", "R", 5, fs("X")), task("B", "R", 4)), Map.of("R", 1, "S", 1));
        assertThat(p.slots().get("A").start()).isEqualTo(3);
        assertThat(p.slots().get("B").start()).isEqualTo(8);
    }
}
