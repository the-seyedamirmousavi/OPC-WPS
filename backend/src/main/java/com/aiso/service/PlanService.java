package com.aiso.service;

import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Predecessor;
import com.aiso.domain.WorkResource;
import com.aiso.service.SchedulePlanner.Lanes;
import com.aiso.service.SchedulePlanner.Task;
import org.springframework.stereotype.Service;

import java.time.Duration;
import java.time.Instant;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.stream.Collectors;

/**
 * Builds the projected schedule of all unfinished work of all active projects, together, on the shared resources.
 * The result is a projection recomputed on demand (never stored); the baseline due time of an operation is fixed
 * separately when it is assigned.
 */
@Service
public class PlanService {

    /**
     * @param tasks the planner input the schedule was computed from (used for metrics)
     * @param lanes busy intervals per resource lane
     * @param rules which dispatching rule won for each project (empty for the naive plan)
     */
    public record PlanView(Map<String, SchedulePlanner.Slot> slots, double makespanHours, Instant now,
                           Map<String, Double> tailHours, List<Task> tasks, Lanes lanes,
                           Map<String, PlanOptimizer.Rule> rules, int candidates) {
        public Instant start(String id) {
            SchedulePlanner.Slot s = slots.get(id);
            return s == null ? null : at(s.start());
        }

        public Instant end(String id) {
            SchedulePlanner.Slot s = slots.get(id);
            return s == null ? null : at(s.end());
        }

        public double startHours(String id) {
            SchedulePlanner.Slot s = slots.get(id);
            return s == null ? 0 : s.start();
        }

        public Instant projectEnd() {
            return slots.isEmpty() ? null : at(makespanHours);
        }

        public Instant at(double hours) {
            return now.plus(Duration.ofSeconds(Math.round(hours * 3600)));
        }
    }

    /** The optimised multi-project plan. */
    public PlanView plan(ProjectData data) {
        return plan(data, null);
    }

    /** @param rankOverride project id to rank, replacing the stored priorities (used to preview a re-ranking) */
    public PlanView plan(ProjectData data, Map<String, Integer> rankOverride) {
        List<Task> tasks = tasks(data, rankOverride);
        PlanOptimizer.Result r = PlanOptimizer.optimize(tasks, capacities(data));
        return view(data, tasks, r.plan(), r.rules(), r.candidates());
    }

    /** Single-pass baseline (project rank, then critical path) that the optimiser is measured against. */
    public PlanView planNaive(ProjectData data, Map<String, Integer> rankOverride) {
        List<Task> tasks = tasks(data, rankOverride);
        return view(data, tasks, SchedulePlanner.plan(tasks, capacities(data)), Map.of(), 1);
    }

    private PlanView view(ProjectData data, List<Task> tasks, SchedulePlanner.Plan plan,
                          Map<String, PlanOptimizer.Rule> rules, int candidates) {
        Map<String, Double> hours = new HashMap<>();
        tasks.forEach(t -> hours.put(t.id(), t.hours()));
        Map<String, Double> tail = GraphUtil.tailHours(hours, data.successors());
        return new PlanView(plan.slots(), plan.makespan(), data.now(), tail, tasks, plan.lanes(), rules, candidates);
    }

    private static Map<String, Integer> capacities(ProjectData data) {
        return data.resources().values().stream().collect(Collectors.toMap(WorkResource::getId, WorkResource::getCapacity));
    }

    private static List<Task> tasks(ProjectData data, Map<String, Integer> rankOverride) {
        List<Task> tasks = new ArrayList<>();
        for (Operation o : data.ops().values()) {
            if (o.getStatus().isTerminal()) {
                continue;
            }
            boolean running = o.getStatus() == OperationStatus.IN_PROGRESS;
            double h = running ? o.totalHours() * (1 - o.getProgressPercent() / 100.0) : o.totalHours();
            List<SchedulePlanner.Dep> deps = new ArrayList<>();
            for (Predecessor p : data.predsOf(o.getId())) {
                if (p.isMandatory()) {
                    deps.add(new SchedulePlanner.Dep(p.getPredecessorId(), p.getDependencyType()));
                }
            }
            int rank = rankOverride != null && rankOverride.containsKey(o.getProjectId())
                    ? rankOverride.get(o.getProjectId()) : data.rankOf(o);
            tasks.add(new Task(o.getId(), o.getProjectId(), rank, o.getResourceId(), h, running, deps));
        }
        return tasks;
    }
}
