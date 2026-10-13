package com.aiso.service;

import com.aiso.domain.DependencyType;
import com.aiso.service.PlanOptimizer.Pins;
import com.aiso.service.PlanOptimizer.Ref;
import com.aiso.service.PlanOptimizer.Result;
import com.aiso.service.SchedulePlanner.Dep;
import com.aiso.service.SchedulePlanner.Slot;
import com.aiso.service.SchedulePlanner.Task;
import org.junit.jupiter.api.Test;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Random;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Timing of one planning run (the work done on every request that shows the plan) for growing shop sizes, without
 * and with FORCEs and a reference plan. Prints a table; the assertion is only a generous upper bound so the test is
 * not flaky on slow machines.
 */
class PlanPerformanceTest {

    @Test
    void planningTimeGrowsModestly() {
        Map<String, Integer> caps = new HashMap<>();
        for (int r = 0; r < 10; r++) {
            caps.put("R" + r, 1 + r % 3);
        }
        System.out.println("ops | plain ms | 20 FORCEs + reference ms | honoured | conflicts | escalated");
        for (int perProject : new int[]{50, 100, 200, 400}) {
            List<Task> tasks = shop(new Random(perProject), 5, perProject);
            PlanOptimizer.optimize(tasks, caps); // warm-up
            long t0 = System.nanoTime();
            Result plain = PlanOptimizer.optimize(tasks, caps);
            long plainMs = (System.nanoTime() - t0) / 1_000_000;

            Random rnd = new Random(1);
            Map<String, Double> forced = new HashMap<>();
            for (int k = 0; k < 20; k++) {
                Task t = tasks.get(rnd.nextInt(tasks.size()));
                forced.put(t.id(), plain.plan().slots().get(t.id()).start() + rnd.nextInt(10));
            }
            Map<String, Ref> ref = new HashMap<>();
            for (Map.Entry<String, Slot> e : plain.plan().slots().entrySet()) {
                ref.put(e.getKey(), new Ref(e.getValue().start(), false));
            }
            t0 = System.nanoTime();
            Result r = PlanOptimizer.optimize(tasks, caps, new Pins(forced, ref));
            long forceMs = (System.nanoTime() - t0) / 1_000_000;
            System.out.printf(Locale.ROOT, "%4d | %8d | %24d | %8d | %9d | %9d%n", tasks.size(), plainMs, forceMs,
                    r.honoured().size(), r.conflicts().size(), r.escalated().size());
            assertThat(r.honoured().size() + r.conflicts().size()).isEqualTo(forced.size());
            assertThat(forceMs).isLessThan(60_000);
        }
    }

    /** {@code projects} projects of {@code perProject} operations, each depending on up to two recent ones. */
    private static List<Task> shop(Random rnd, int projects, int perProject) {
        List<Task> tasks = new ArrayList<>();
        for (int p = 1; p <= projects; p++) {
            for (int i = 0; i < perProject; i++) {
                List<Dep> deps = new ArrayList<>();
                for (int k = 0; k < 2 && i > 0; k++) {
                    if (rnd.nextDouble() < 0.6) {
                        String pred = "P" + p + "-" + Math.max(0, i - 1 - rnd.nextInt(Math.min(i, 8)));
                        if (deps.stream().noneMatch(d -> d.predecessorId().equals(pred))) {
                            deps.add(new Dep(pred, DependencyType.FINISH_TO_START));
                        }
                    }
                }
                tasks.add(new Task("P" + p + "-" + i, "P" + p, p, "R" + rnd.nextInt(10), 1 + rnd.nextInt(8), false, deps));
            }
        }
        return tasks;
    }
}
