package com.aiso.service;

import com.aiso.domain.DependencyType;

import java.util.ArrayList;
import java.util.Collection;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Capacity-constrained forward schedule (serial schedule generation), the building block of the multi-project
 * {@link PlanOptimizer}.
 * <p>
 * Time is measured in hours from "now" on a continuous clock (no working calendar). Each resource has
 * {@code capacity} parallel lanes. Among the operations whose predecessors are already placed, the one with the
 * highest priority is placed at the earliest time that fits on any lane, reusing idle gaps.
 * <p>
 * {@link #plan} is the single-pass heuristic (project rank first, then longest remaining chain). It is also the
 * "naive" baseline the optimizer is compared against.
 */
public final class SchedulePlanner {

    public record Dep(String predecessorId, DependencyType type) {
    }

    /**
     * @param projectId   owning project (empty for single-project use)
     * @param projectRank 1 = most important project; 0 when projects are not used
     * @param running     true if the operation is already IN_PROGRESS (occupies a lane from t=0 for its remaining hours)
     * @param hours       remaining duration for running operations, full duration for pending ones
     */
    public record Task(String id, String projectId, int projectRank, String resourceId, double hours, boolean running,
                       List<Dep> deps) {
        public Task(String id, String resourceId, double hours, boolean running, List<Dep> deps) {
            this(id, "", 0, resourceId, hours, running, deps);
        }
    }

    public record Slot(double start, double end) {
    }

    public record Plan(Map<String, Slot> slots, double makespan, Lanes lanes) {
    }

    /** Busy intervals per resource lane. Copyable, so alternative placements can be tried and discarded. */
    public static final class Lanes {
        private final Map<String, Integer> capacities;
        private final Map<String, List<List<double[]>>> busy = new HashMap<>();

        public Lanes(Map<String, Integer> capacities) {
            this.capacities = capacities;
        }

        public Lanes copy() {
            Lanes c = new Lanes(capacities);
            busy.forEach((res, lanes) -> {
                List<List<double[]>> copy = new ArrayList<>();
                lanes.forEach(l -> copy.add(new ArrayList<>(l))); // intervals are never mutated after creation
                c.busy.put(res, copy);
            });
            return c;
        }

        public List<List<double[]>> forResource(String resourceId) {
            return busy.computeIfAbsent(resourceId, r -> {
                List<List<double[]>> lanes = new ArrayList<>();
                for (int i = 0; i < Math.max(1, capacities.getOrDefault(r, 1)); i++) {
                    lanes.add(new ArrayList<>());
                }
                return lanes;
            });
        }

        public Map<String, List<List<double[]>>> all() {
            return busy;
        }
    }

    private SchedulePlanner() {
    }

    /**
     * Single pass over all tasks. Dependencies on ids that are not part of {@code tasks} (completed or cancelled
     * work) are treated as satisfied.
     */
    public static Plan plan(Collection<Task> tasks, Map<String, Integer> capacities) {
        Map<String, Task> byId = index(tasks);
        Map<String, Double> tail = tails(byId.values());

        Lanes lanes = new Lanes(capacities);
        Map<String, Slot> placed = new LinkedHashMap<>();
        placeRunning(byId.values(), placed, lanes);

        Set<String> pending = new HashSet<>();
        byId.values().stream().filter(t -> !t.running()).forEach(t -> pending.add(t.id()));

        Comparator<String> priority = Comparator
                .comparingInt((String id) -> byId.get(id).projectRank())
                .thenComparing(Comparator.comparing((String id) -> tail.getOrDefault(id, 0.0)).reversed())
                .thenComparing(id -> id);
        pass(byId, pending, placed, lanes, priority);
        return result(placed, lanes);
    }

    // ---- building blocks used by PlanOptimizer -----------------------------------------------------------------

    static Map<String, Task> index(Collection<Task> tasks) {
        Map<String, Task> byId = new LinkedHashMap<>();
        tasks.forEach(t -> byId.put(t.id(), t));
        return byId;
    }

    /** Longest remaining chain (own duration included) per task, considering only dependencies inside {@code tasks}. */
    static Map<String, Double> tails(Collection<Task> tasks) {
        Set<String> ids = new HashSet<>();
        tasks.forEach(t -> ids.add(t.id()));
        Map<String, List<String>> successors = new HashMap<>();
        Map<String, Double> hours = new HashMap<>();
        for (Task t : tasks) {
            hours.put(t.id(), t.hours());
            for (Dep d : t.deps()) {
                if (ids.contains(d.predecessorId())) {
                    successors.computeIfAbsent(d.predecessorId(), k -> new ArrayList<>()).add(t.id());
                }
            }
        }
        return GraphUtil.tailHours(hours, successors);
    }

    static void placeRunning(Collection<Task> tasks, Map<String, Slot> placed, Lanes lanes) {
        for (Task t : tasks) {
            if (t.running()) {
                placed.put(t.id(), place(lanes, t.resourceId(), 0, Math.max(0, t.hours())));
            }
        }
    }

    /** Places every id in {@code pending}, highest priority first, as soon as all its predecessors are placed. */
    static void pass(Map<String, Task> byId, Set<String> pending, Map<String, Slot> placed, Lanes lanes,
                     Comparator<String> priority) {
        while (!pending.isEmpty()) {
            String next = pending.stream()
                    .filter(id -> byId.get(id).deps().stream()
                            .allMatch(d -> !byId.containsKey(d.predecessorId()) || placed.containsKey(d.predecessorId())))
                    .min(priority)
                    .orElse(null);
            if (next == null) {
                break; // unresolved cycle: leave the rest unplanned
            }
            Task t = byId.get(next);
            double ready = 0;
            for (Dep d : t.deps()) {
                Slot p = placed.get(d.predecessorId());
                if (p != null) {
                    ready = Math.max(ready, d.type() == DependencyType.START_TO_START ? p.start() : p.end());
                }
            }
            placed.put(next, place(lanes, t.resourceId(), ready, t.hours()));
            pending.remove(next);
        }
    }

    static Plan result(Map<String, Slot> placed, Lanes lanes) {
        double makespan = placed.values().stream().mapToDouble(Slot::end).max().orElse(0);
        return new Plan(placed, makespan, lanes);
    }

    /**
     * Places a task at the earliest start >= {@code ready} on whichever lane can fit it, filling idle gaps left
     * between tasks that were placed earlier (so a lower-priority task can use time a higher-priority one is not using).
     */
    private static Slot place(Lanes all, String resourceId, double ready, double duration) {
        List<List<double[]>> lanes = all.forResource(resourceId);
        int bestLane = 0;
        double bestStart = Double.MAX_VALUE;
        for (int i = 0; i < lanes.size(); i++) {
            double start = earliestFit(lanes.get(i), ready, duration);
            if (start < bestStart) {
                bestStart = start;
                bestLane = i;
            }
        }
        List<double[]> lane = lanes.get(bestLane);
        lane.add(new double[]{bestStart, bestStart + duration});
        lane.sort(Comparator.comparingDouble(iv -> iv[0]));
        return new Slot(bestStart, bestStart + duration);
    }

    private static double earliestFit(List<double[]> lane, double ready, double duration) {
        double start = ready;
        for (double[] iv : lane) { // sorted by start
            if (start + duration <= iv[0] + 1e-9) {
                return start;
            }
            start = Math.max(start, iv[1]);
        }
        return start;
    }
}
