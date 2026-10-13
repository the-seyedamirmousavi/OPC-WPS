package com.aiso.service;

import com.aiso.domain.DependencyType;
import com.aiso.service.PlanOptimizer.Conflict;
import com.aiso.service.PlanOptimizer.ConflictKind;
import com.aiso.service.PlanOptimizer.Pins;
import com.aiso.service.PlanOptimizer.Ref;
import com.aiso.service.PlanOptimizer.Result;
import com.aiso.service.SchedulePlanner.Dep;
import com.aiso.service.SchedulePlanner.Slot;
import com.aiso.service.SchedulePlanner.Task;
import org.junit.jupiter.api.Test;

import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Random;

import static org.assertj.core.api.Assertions.assertThat;

/** Manager FORCE scenarios on the planner level (the scenario numbers follow the design document, section 10). */
class PlanForceTest {

    private static Task t(String id, String project, int rank, String res, double hours, String... preds) {
        List<Dep> deps = new ArrayList<>();
        for (String p : preds) {
            deps.add(new Dep(p, DependencyType.FINISH_TO_START));
        }
        return new Task(id, project, rank, res, hours, false, deps);
    }

    /** FORCE starts in seniority order (oldest first): id1, start1, id2, start2, ... */
    private static Map<String, Double> oldestFirst(Object... kv) {
        Map<String, Double> m = new java.util.LinkedHashMap<>();
        for (int i = 0; i < kv.length; i += 2) {
            m.put((String) kv[i], ((Number) kv[i + 1]).doubleValue());
        }
        return m;
    }

    private static Pins forced(Map<String, Double> starts) {
        return new Pins(starts, Map.of());
    }

    private static Pins withRef(Map<String, Double> starts, Map<String, Slot> reference, String... committed) {
        Map<String, Ref> ref = new HashMap<>();
        List<String> c = List.of(committed);
        reference.forEach((id, s) -> ref.put(id, new Ref(s.start(), c.contains(id))));
        return new Pins(starts, ref);
    }

    private static Slot slot(Result r, String id) {
        return r.plan().slots().get(id);
    }

    private static double finish(Result r, List<Task> tasks, String project) {
        return tasks.stream().filter(x -> x.projectId().equals(project))
                .mapToDouble(x -> r.plan().slots().get(x.id()).end()).max().orElse(0);
    }

    // 1 -------------------------------------------------------------------------------------------------------------

    @Test
    void s01_simpleForceIsPlacedExactlyAndTheRestFlowsAroundIt() {
        List<Task> tasks = List.of(t("A", "P", 1, "R", 4), t("B", "P", 1, "R", 3), t("C", "P", 1, "R", 2));
        Result r = PlanOptimizer.optimize(tasks, Map.of("R", 1), forced(Map.of("B", 10.0)));
        assertThat(slot(r, "B")).isEqualTo(new Slot(10, 13));
        assertThat(r.conflicts()).isEmpty();
        assertThat(r.honoured()).containsExactly("B");
        assertFeasible(tasks, r, Map.of("R", 1), Map.of("B", 10.0));
        // A and C use the time before the FORCE (gap filling): nothing waits behind it needlessly
        assertThat(slot(r, "A").end()).isLessThanOrEqualTo(10);
        assertThat(slot(r, "C").end()).isLessThanOrEqualTo(10);
    }

    // 2 -------------------------------------------------------------------------------------------------------------

    @Test
    void s02_directConflictMovesTheOperationThatHeldTheSlot() {
        List<Task> tasks = List.of(t("A", "P", 1, "R", 4), t("B", "P", 1, "R", 4));
        Result before = PlanOptimizer.optimize(tasks, Map.of("R", 1));
        assertThat(slot(before, "A")).isEqualTo(new Slot(0, 4));

        Result r = PlanOptimizer.optimize(tasks, Map.of("R", 1), withRef(Map.of("B", 0.0), before.plan().slots()));
        assertThat(slot(r, "B")).isEqualTo(new Slot(0, 4));
        assertThat(slot(r, "A")).isEqualTo(new Slot(4, 8));
    }

    // 3 -------------------------------------------------------------------------------------------------------------

    @Test
    void s03_cascadingConflictPushesSuccessorsAndKeepsPrecedence() {
        // A -> B -> C on alternating resources, X is forced on R exactly where A was
        List<Task> tasks = List.of(t("A", "P", 1, "R", 4), t("B", "P", 1, "S", 3, "A"), t("C", "P", 1, "R", 2, "B"),
                t("X", "Q", 2, "R", 5));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Result before = PlanOptimizer.optimize(tasks, caps);
        assertThat(slot(before, "A").start()).isEqualTo(0);

        Result r = PlanOptimizer.optimize(tasks, caps, withRef(Map.of("X", 0.0), before.plan().slots()));
        assertThat(slot(r, "X")).isEqualTo(new Slot(0, 5));
        assertThat(slot(r, "A").start()).isEqualTo(5);
        assertThat(slot(r, "B").start()).isGreaterThanOrEqualTo(slot(r, "A").end());
        assertThat(slot(r, "C").start()).isGreaterThanOrEqualTo(slot(r, "B").end());
        assertFeasible(tasks, r, caps, Map.of("X", 0.0));
    }

    // 4 -------------------------------------------------------------------------------------------------------------

    @Test
    void s04_multipleCompatibleForcesAreAllHonoured() {
        List<Task> tasks = List.of(t("A", "P", 1, "R", 2), t("B", "P", 1, "R", 2), t("C", "P", 1, "S", 3, "A"),
                t("D", "P", 1, "R", 2));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Map<String, Double> f = Map.of("A", 6.0, "B", 1.0, "C", 9.0);
        Result r = PlanOptimizer.optimize(tasks, caps, forced(f));
        assertThat(r.conflicts()).isEmpty();
        assertThat(r.honoured()).containsExactly("A", "B", "C");
        assertFeasible(tasks, r, caps, f);
    }

    @Test
    void s04b_twoForcesFitSideBySideOnACapacityTwoResource() {
        List<Task> tasks = List.of(t("A", "P", 1, "R", 4), t("B", "P", 1, "R", 4), t("C", "P", 1, "R", 4));
        Result r = PlanOptimizer.optimize(tasks, Map.of("R", 2), forced(Map.of("A", 2.0, "B", 3.0)));
        assertThat(r.conflicts()).isEmpty();
        assertThat(slot(r, "A")).isEqualTo(new Slot(2, 6));
        assertThat(slot(r, "B")).isEqualTo(new Slot(3, 7));
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    @Test
    void s05_conflictingForcesAreReportedAsProvenCapacityConflict() {
        List<Task> tasks = List.of(t("A", "P", 1, "R", 4), t("B", "P", 1, "R", 4));
        Result r = PlanOptimizer.optimize(tasks, Map.of("R", 1), forced(oldestFirst("A", 2, "B", 3)));
        assertThat(r.honoured()).containsExactly("A");
        assertThat(r.conflicts()).singleElement().satisfies(c -> {
            assertThat(c.operationId()).isEqualTo("B");
            assertThat(c.kind()).isEqualTo(ConflictKind.CAPACITY);
            assertThat(c.proven()).isTrue();
            assertThat(c.related()).containsExactly("A");
        });
        // the losing FORCE constrains nothing: B is planned like any operation, feasibly
        assertThat(slot(r, "B").start()).isGreaterThanOrEqualTo(6);
        assertFeasible(tasks, r, Map.of("R", 1), Map.of("A", 2.0));
    }

    // 6 -------------------------------------------------------------------------------------------------------------

    @Test
    void s06_forceAgainstAbsoluteConstraintsIsRejectedWithTheReason() {
        Task running = new Task("RUN", "P", 1, "R", 5, true, List.of());
        List<Task> tasks = List.of(running, t("A", "P", 1, "S", 6), t("B", "P", 1, "S", 2, "A"),
                t("C", "P", 1, "R", 1), t("D", "P", 1, "S", 1));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Result r = PlanOptimizer.optimize(tasks, caps, forced(Map.of("B", 3.0, "C", 1.0, "D", -2.0)));
        Map<String, Conflict> byId = new HashMap<>();
        r.conflicts().forEach(c -> byId.put(c.operationId(), c));

        assertThat(byId.get("B").kind()).isEqualTo(ConflictKind.PRECEDENCE); // A needs 6 h first
        assertThat(byId.get("B").related()).containsExactly("A");
        assertThat(byId.get("B").earliestStart()).isEqualTo(6);
        assertThat(byId.get("C").kind()).isEqualTo(ConflictKind.CAPACITY); // the running operation holds R
        assertThat(byId.get("C").related()).containsExactly("RUN");
        assertThat(byId.get("D").kind()).isEqualTo(ConflictKind.START_IN_PAST);
        assertThat(r.conflicts()).allMatch(Conflict::proven);
        assertThat(r.honoured()).isEmpty();
        assertThat(slot(r, "RUN")).isEqualTo(new Slot(0, 5)); // running work never moves
        assertFeasible(tasks, r, caps, Map.of());
    }

    // 7 -------------------------------------------------------------------------------------------------------------

    @Test
    void s07_forceOverridesProjectPriority_aSoftRule() {
        List<Task> tasks = List.of(t("H", "P1", 1, "R", 8), t("L", "P2", 2, "R", 4));
        Result plain = PlanOptimizer.optimize(tasks, Map.of("R", 1));
        assertThat(slot(plain, "H").start()).isEqualTo(0);

        Result r = PlanOptimizer.optimize(tasks, Map.of("R", 1), forced(Map.of("L", 0.0)));
        assertThat(slot(r, "L")).isEqualTo(new Slot(0, 4));
        assertThat(slot(r, "H")).isEqualTo(new Slot(4, 12)); // the higher-priority project now waits
        assertThat(r.conflicts()).isEmpty();
    }

    @Test
    void s07b_predecessorsOfAForceRunAheadOfPriorityOnlyWhenNeeded() {
        // P2's L2 is forced at 4 and needs L1 (4 h on R) first; P1's H holds R for 8 h in the priority order.
        List<Task> tasks = List.of(t("H", "P1", 1, "R", 8), t("L1", "P2", 2, "R", 4), t("L2", "P2", 2, "S", 2, "L1"));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Result r = PlanOptimizer.optimize(tasks, caps, forced(Map.of("L2", 4.0)));
        assertThat(r.conflicts()).isEmpty();
        assertThat(r.escalated()).containsExactly("L1");
        assertThat(slot(r, "L1")).isEqualTo(new Slot(0, 4));
        assertThat(slot(r, "L2")).isEqualTo(new Slot(4, 6));
        assertThat(slot(r, "H")).isEqualTo(new Slot(4, 12));

        // forced late enough, the plain priority order already works: nothing is escalated
        Result relaxed = PlanOptimizer.optimize(tasks, caps, forced(Map.of("L2", 12.0)));
        assertThat(relaxed.escalated()).isEmpty();
        assertThat(slot(relaxed, "H")).isEqualTo(new Slot(0, 8));
        assertThat(slot(relaxed, "L2")).isEqualTo(new Slot(12, 14));
    }

    // 8 -------------------------------------------------------------------------------------------------------------

    @Test
    void s08_stability_aForceOnlyMovesItsNeighbourhood() {
        Map<String, Integer> caps = Map.of("R1", 1, "R2", 2, "R3", 1);
        List<Task> tasks = random(new Random(5), 3, 10);
        Result before = PlanOptimizer.optimize(tasks, caps);
        // replanning against its own result changes nothing
        Result again = PlanOptimizer.optimize(tasks, caps, withRef(Map.of(), before.plan().slots()));
        assertThat(again.plan().slots()).isEqualTo(before.plan().slots());

        // force the last operation of the lowest-priority project to its current start + 1 h
        String target = "P3-9";
        double at = slot(before, target).start() + 1;
        Result r = PlanOptimizer.optimize(tasks, caps, withRef(Map.of(target, at), before.plan().slots()));
        Result fresh = PlanOptimizer.optimize(tasks, caps, forced(Map.of(target, at)));
        assertThat(slot(r, target).start()).isEqualTo(at);
        // higher-priority projects are untouched, and the stable plan moves no more than a re-plan from scratch
        for (Task task : tasks) {
            if (!task.projectId().equals("P3")) {
                assertThat(slot(r, task.id())).as(task.id()).isEqualTo(slot(before, task.id()));
            }
        }
        assertThat(moved(before, r)).isLessThanOrEqualTo(moved(before, fresh));
    }

    // 9 -------------------------------------------------------------------------------------------------------------

    @Test
    void s09_noFeasibleSchedule_isReportedHonestlyAsNotFound() {
        // F (on R) at 4 needs P (4 h on S) first, but S is held by the FORCE on G at [2, 6): P cannot end by 4.
        // No exact check proves it (they ignore capacity of non-forced work); the heuristic reports NOT_FOUND.
        List<Task> tasks = List.of(t("P", "X", 1, "S", 4), t("F", "X", 1, "R", 1, "P"), t("G", "X", 1, "S", 4));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Result r = PlanOptimizer.optimize(tasks, caps, forced(oldestFirst("G", 2, "F", 4)));
        assertThat(r.honoured()).containsExactly("G");
        assertThat(r.conflicts()).singleElement().satisfies(c -> {
            assertThat(c.operationId()).isEqualTo("F");
            assertThat(c.kind()).isEqualTo(ConflictKind.NOT_FOUND);
            assertThat(c.proven()).isFalse();
            assertThat(c.earliestStart()).isEqualTo(slot(r, "F").start());
        });
        assertThat(slot(r, "F").start()).isGreaterThanOrEqualTo(slot(r, "P").end());
        assertFeasible(tasks, r, caps, Map.of("G", 2.0));
    }

    @Test
    void s09b_seniority_aYoungerForceIsGivenUpForAnOlderOne() {
        // the same two FORCEs, but F is the older one: G stands in its way and is the one given up
        List<Task> tasks = List.of(t("P", "X", 1, "S", 4), t("F", "X", 1, "R", 1, "P"), t("G", "X", 1, "S", 4));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Result r = PlanOptimizer.optimize(tasks, caps, forced(oldestFirst("F", 4, "G", 2)));
        assertThat(r.honoured()).containsExactly("F");
        assertThat(r.conflicts()).singleElement().satisfies(c -> {
            assertThat(c.operationId()).isEqualTo("G");
            assertThat(c.kind()).isEqualTo(ConflictKind.NOT_FOUND);
            assertThat(c.related()).containsExactly("F");
        });
        assertFeasible(tasks, r, caps, Map.of("F", 4.0));
    }

    @Test
    void s05b_seniority_aNewForceNeverPushesOutAnOlderOne() {
        List<Task> tasks = List.of(t("A", "P", 1, "R", 4), t("B", "P", 1, "R", 4), t("C", "P", 1, "S", 2, "A"));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        // capacity tie at the same start: the younger FORCE loses whatever its id
        Result tie = PlanOptimizer.optimize(tasks, caps, forced(oldestFirst("B", 2, "A", 2)));
        assertThat(tie.honoured()).containsExactly("B");
        assertThat(tie.conflicts()).singleElement().satisfies(c -> {
            assertThat(c.operationId()).isEqualTo("A");
            assertThat(c.kind()).isEqualTo(ConflictKind.CAPACITY);
            assertThat(c.related()).containsExactly("B");
        });
        // precedence: C (older) at 5 needs A done by 5; forcing A at 3 would end it at 7
        Result blocks = PlanOptimizer.optimize(tasks, caps, forced(oldestFirst("C", 5, "A", 3)));
        assertThat(blocks.honoured()).containsExactly("C");
        assertThat(blocks.conflicts()).singleElement().satisfies(c -> {
            assertThat(c.operationId()).isEqualTo("A");
            assertThat(c.kind()).isEqualTo(ConflictKind.BLOCKS_FORCE);
            assertThat(c.proven()).isTrue();
            assertThat(c.related()).containsExactly("C");
            assertThat(c.earliestStart()).isEqualTo(5);
        });
    }

    // 10 ------------------------------------------------------------------------------------------------------------

    @Test
    void s10_removingAForceKeepsTheRestInPlace() {
        List<Task> tasks = List.of(t("A", "P", 1, "R", 2), t("B", "P", 1, "R", 2), t("C", "P", 1, "S", 6),
                t("D", "P", 1, "S", 2, "C"));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Result withForce = PlanOptimizer.optimize(tasks, caps, forced(Map.of("A", 5.0)));
        Result removed = PlanOptimizer.optimize(tasks, caps, withRef(Map.of(), withForce.plan().slots()));
        assertThat(slot(removed, "C")).isEqualTo(slot(withForce, "C"));
        assertThat(slot(removed, "D")).isEqualTo(slot(withForce, "D"));
        assertThat(slot(removed, "B")).isEqualTo(slot(withForce, "B"));
        assertThat(slot(removed, "A").start()).isLessThanOrEqualTo(5); // free again: may move earlier, never later
        assertFeasible(tasks, removed, caps, Map.of());
    }

    // 11 ------------------------------------------------------------------------------------------------------------

    @Test
    void s11_repeatedForceOperationsAreDeterministic() {
        Map<String, Integer> caps = Map.of("R1", 1, "R2", 2, "R3", 1);
        List<Task> tasks = random(new Random(9), 3, 8);
        Map<String, Slot> ref = PlanOptimizer.optimize(tasks, caps).plan().slots();
        Map<String, Double> f = Map.of("P2-3", 7.0, "P3-1", 2.0);

        Result first = PlanOptimizer.optimize(tasks, caps, withRef(f, ref));
        for (int i = 0; i < 5; i++) {
            // the same FORCEs again, and with the input in another order: identical plan
            List<Task> shuffled = new ArrayList<>(tasks);
            Collections.shuffle(shuffled, new Random(i));
            Result again = PlanOptimizer.optimize(shuffled, caps, withRef(f, ref));
            assertThat(again.plan().slots()).isEqualTo(first.plan().slots());
            assertThat(again.conflicts()).isEqualTo(first.conflicts());
        }
        // replacing a FORCE (set, change, change back) ends where setting it once ends
        PlanOptimizer.optimize(tasks, caps, withRef(Map.of("P2-3", 1.0, "P3-1", 2.0), ref));
        assertThat(PlanOptimizer.optimize(tasks, caps, withRef(f, ref)).plan().slots()).isEqualTo(first.plan().slots());
    }

    // 12 ------------------------------------------------------------------------------------------------------------

    @Test
    void s12_committedAssignmentsAreTheLastToMove() {
        // Z is already assigned (committed) at 0, C at 2; D on S fixes the project finish at 20 either way.
        // A FORCE on A at [2, 4) pushes one of them: the plan moves the uncommitted C, not Z.
        List<Task> tasks = List.of(t("Z", "P", 1, "R", 2), t("C", "P", 1, "R", 2), t("A", "P", 1, "R", 2),
                t("D", "P", 1, "S", 20));
        Map<String, Integer> caps = Map.of("R", 1, "S", 1);
        Map<String, Slot> ref = Map.of("Z", new Slot(0, 2), "C", new Slot(2, 4), "D", new Slot(0, 20));

        Result r = PlanOptimizer.optimize(tasks, caps, withRef(Map.of("A", 2.0), ref, "Z"));
        assertThat(slot(r, "Z")).isEqualTo(new Slot(0, 2));
        assertThat(slot(r, "C").start()).isEqualTo(4);
        assertThat(r.rules().get("P")).isEqualTo(PlanOptimizer.Rule.STABLE);

        // without the reference the critical-path rule would have moved Z (C sorts first)
        Result noRef = PlanOptimizer.optimize(tasks, caps, forced(Map.of("A", 2.0)));
        assertThat(slot(noRef, "C").start()).isEqualTo(0);
    }

    // determinism & safety on random instances -----------------------------------------------------------------------

    @Test
    void randomForcesAlwaysGiveAFeasiblePlanAndHonourEveryForceNotReported() {
        Map<String, Integer> caps = Map.of("R1", 1, "R2", 2, "R3", 1);
        for (int seed = 0; seed < 80; seed++) {
            Random rnd = new Random(seed);
            List<Task> tasks = random(rnd, 3, 9);
            Map<String, Double> f = new HashMap<>();
            for (int k = 0; k < 4; k++) {
                f.put(tasks.get(rnd.nextInt(tasks.size())).id(), (double) rnd.nextInt(30) - 2);
            }
            Result r = PlanOptimizer.optimize(tasks, caps, forced(f));
            Map<String, Double> honoured = new HashMap<>();
            r.honoured().forEach(id -> honoured.put(id, f.get(id)));
            assertThat(honoured.size() + r.conflicts().size()).as("seed %d", seed).isEqualTo(f.size());
            assertFeasible(tasks, r, caps, honoured);
            assertThat(PlanOptimizer.optimize(tasks, caps, forced(f)).plan().slots()).isEqualTo(r.plan().slots());
        }
    }

    @Test
    void replanningOnTheSavedPlanIsAFixedPoint_soThePreviewIsWhatTheManagerGets() {
        // ForceService saves the plan computed with a new FORCE as the reference; the next request plans against it
        Map<String, Integer> caps = Map.of("R1", 1, "R2", 2, "R3", 1);
        for (int seed = 0; seed < 200; seed++) {
            Random rnd = new Random(seed);
            List<Task> tasks = random(rnd, 3, 9);
            Map<String, Slot> r0 = PlanOptimizer.optimize(tasks, caps).plan().slots();
            Map<String, Double> f = Map.of(tasks.get(rnd.nextInt(tasks.size())).id(), (double) rnd.nextInt(20));
            Result p = PlanOptimizer.optimize(tasks, caps, withRef(f, r0));
            Result q = PlanOptimizer.optimize(tasks, caps, withRef(f, p.plan().slots()));
            assertThat(q.plan().slots()).as("seed %d", seed).isEqualTo(p.plan().slots());
        }
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    private static int moved(Result a, Result b) {
        int n = 0;
        for (Map.Entry<String, Slot> e : a.plan().slots().entrySet()) {
            if (Math.abs(e.getValue().start() - b.plan().slots().get(e.getKey()).start()) > 1e-6) {
                n++;
            }
        }
        return n;
    }

    /** Every task placed once with its duration; precedence, capacity and the honoured FORCEs hold. */
    static void assertFeasible(List<Task> tasks, Result r, Map<String, Integer> caps, Map<String, Double> honoured) {
        Map<String, Slot> slots = r.plan().slots();
        assertThat(slots).hasSize(tasks.size());
        for (Task task : tasks) {
            Slot s = slots.get(task.id());
            assertThat(s.end() - s.start()).isCloseTo(task.hours(), org.assertj.core.data.Offset.offset(1e-9));
            assertThat(s.start()).isGreaterThanOrEqualTo(-1e-9);
            for (Dep d : task.deps()) {
                Slot p = slots.get(d.predecessorId());
                if (p != null) {
                    assertThat(s.start()).as("%s after %s", task.id(), d.predecessorId())
                            .isGreaterThanOrEqualTo((d.type() == DependencyType.START_TO_START ? p.start() : p.end()) - 1e-6);
                }
            }
        }
        honoured.forEach((id, start) -> assertThat(slots.get(id).start()).as("FORCE %s", id).isCloseTo(start,
                org.assertj.core.data.Offset.offset(1e-6)));
        r.plan().lanes().all().forEach((res, lanes) -> {
            assertThat(lanes.size()).isEqualTo(caps.getOrDefault(res, 1));
            for (List<double[]> lane : lanes) {
                List<double[]> sorted = new ArrayList<>(lane);
                sorted.sort(Comparator.comparingDouble(iv -> iv[0]));
                for (int i = 1; i < sorted.size(); i++) {
                    assertThat(sorted.get(i)[0]).isGreaterThanOrEqualTo(sorted.get(i - 1)[1] - 1e-9);
                }
            }
        });
    }

    /** Random but acyclic projects: each task may depend on earlier tasks of the same project. */
    static List<Task> random(Random rnd, int projects, int perProject) {
        String[] res = {"R1", "R2", "R3"};
        List<Task> tasks = new ArrayList<>();
        for (int p = 1; p <= projects; p++) {
            for (int i = 0; i < perProject; i++) {
                List<String> preds = new ArrayList<>();
                for (int j = 0; j < i; j++) {
                    if (rnd.nextDouble() < 0.25) {
                        preds.add("P" + p + "-" + j);
                    }
                }
                tasks.add(t("P" + p + "-" + i, "P" + p, p, res[rnd.nextInt(3)], 1 + rnd.nextInt(8), preds.toArray(new String[0])));
            }
        }
        return tasks;
    }
}
