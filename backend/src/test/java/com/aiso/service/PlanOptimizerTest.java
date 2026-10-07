package com.aiso.service;

import com.aiso.domain.DependencyType;
import com.aiso.service.SchedulePlanner.Dep;
import com.aiso.service.SchedulePlanner.Plan;
import com.aiso.service.SchedulePlanner.Slot;
import com.aiso.service.SchedulePlanner.Task;
import org.junit.jupiter.api.Test;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.Random;

import static org.assertj.core.api.Assertions.assertThat;

class PlanOptimizerTest {

    private static Task t(String id, String project, int rank, String res, double hours, String... preds) {
        List<Dep> deps = new ArrayList<>();
        for (String p : preds) {
            deps.add(new Dep(p, DependencyType.FINISH_TO_START));
        }
        return new Task(id, project, rank, res, hours, false, deps);
    }

    private static double finish(Plan plan, List<Task> tasks, String project) {
        return tasks.stream().filter(x -> x.projectId().equals(project))
                .mapToDouble(x -> plan.slots().get(x.id()).end()).max().orElse(0);
    }

    @Test
    void higherPriorityProjectIsNeverDelayedByALowerOne() {
        List<Task> p1 = List.of(t("A", "P1", 1, "R", 4), t("B", "P1", 1, "R", 4, "A"));
        List<Task> p2 = List.of(t("C", "P2", 2, "R", 6), t("D", "P2", 2, "R", 6, "C"));
        List<Task> both = new ArrayList<>(p1);
        both.addAll(p2);

        double aloneFinish = finish(PlanOptimizer.optimize(p1, Map.of("R", 1)).plan(), p1, "P1");
        Plan together = PlanOptimizer.optimize(both, Map.of("R", 1)).plan();

        assertThat(finish(together, both, "P1")).isEqualTo(aloneFinish).isEqualTo(8);
        assertThat(finish(together, both, "P2")).isEqualTo(20); // runs after P1 on the single lane
    }

    @Test
    void swappingPrioritiesSwapsWhoWaits() {
        List<Task> a = List.of(t("A", "P1", 2, "R", 4), t("C", "P2", 1, "R", 6));
        Plan p = PlanOptimizer.optimize(a, Map.of("R", 1)).plan();
        assertThat(p.slots().get("C")).isEqualTo(new Slot(0, 6));
        assertThat(p.slots().get("A")).isEqualTo(new Slot(6, 10));
    }

    @Test
    void lowerPriorityWorkFillsIdleGapsOfHigherPriorityWork() {
        // P1: X (10h on S) then A (5h on R). R is idle for 10h. P2 has B (4h on R) -> must use that gap.
        List<Task> tasks = List.of(t("X", "P1", 1, "S", 10), t("A", "P1", 1, "R", 5, "X"), t("B", "P2", 2, "R", 4));
        Plan p = PlanOptimizer.optimize(tasks, Map.of("R", 1, "S", 1)).plan();
        assertThat(p.slots().get("B")).isEqualTo(new Slot(0, 4));
        assertThat(p.slots().get("A")).isEqualTo(new Slot(10, 15));
        assertThat(finish(p, tasks, "P1")).isEqualTo(15); // B did not delay P1
    }

    @Test
    void runningWorkIsNeverMovedByANewHigherPriorityProject() {
        Task running = new Task("RUN", "P2", 2, "R", 5, true, List.of());
        Task urgent = t("U", "P1", 1, "R", 3);
        Plan p = PlanOptimizer.optimize(List.of(running, urgent), Map.of("R", 1)).plan();
        assertThat(p.slots().get("RUN")).isEqualTo(new Slot(0, 5));
        assertThat(p.slots().get("U")).isEqualTo(new Slot(5, 8));
    }

    @Test
    void resultIsReproducible() {
        List<Task> tasks = random(new Random(11), 3, 10);
        Map<String, Integer> caps = Map.of("R1", 1, "R2", 2, "R3", 1);
        assertThat(PlanOptimizer.optimize(tasks, caps).plan().slots())
                .isEqualTo(PlanOptimizer.optimize(tasks, caps).plan().slots());
    }

    @Test
    void neverWorseThanTheNaiveSinglePassForTheHighestPriorityProjects_andAlwaysFeasible() {
        Map<String, Integer> caps = Map.of("R1", 1, "R2", 2, "R3", 1);
        for (int seed = 0; seed < 60; seed++) {
            List<Task> tasks = random(new Random(seed), 3, 9);
            Plan naive = SchedulePlanner.plan(tasks, caps);
            Plan opt = PlanOptimizer.optimize(tasks, caps).plan();

            // lexicographic by priority: P1 first, then P2, then P3
            for (String project : List.of("P1", "P2", "P3")) {
                double o = finish(opt, tasks, project);
                double n = finish(naive, tasks, project);
                if (Math.abs(o - n) > 1e-9) {
                    assertThat(o).as("seed %d project %s", seed, project).isLessThan(n);
                    break; // strictly better on a higher-priority project: later ones may differ
                }
            }
            assertFeasible(tasks, opt, caps);
        }
    }

    private static void assertFeasible(List<Task> tasks, Plan plan, Map<String, Integer> caps) {
        assertThat(plan.slots()).hasSize(tasks.size());
        for (Task task : tasks) {
            Slot s = plan.slots().get(task.id());
            assertThat(s.end() - s.start()).isEqualTo(task.hours());
            for (Dep d : task.deps()) {
                assertThat(s.start()).isGreaterThanOrEqualTo(plan.slots().get(d.predecessorId()).end() - 1e-9);
            }
        }
        plan.lanes().all().forEach((res, lanes) -> {
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
    private static List<Task> random(Random rnd, int projects, int perProject) {
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
