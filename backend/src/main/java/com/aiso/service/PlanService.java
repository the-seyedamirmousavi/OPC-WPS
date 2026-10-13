package com.aiso.service;

import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.PlanBaseline;
import com.aiso.domain.Predecessor;
import com.aiso.domain.ScheduleForce;
import com.aiso.domain.WorkResource;
import com.aiso.service.PlanOptimizer.Conflict;
import com.aiso.service.PlanOptimizer.Pins;
import com.aiso.service.PlanOptimizer.Ref;
import com.aiso.service.SchedulePlanner.Lanes;
import com.aiso.service.SchedulePlanner.Task;
import org.springframework.stereotype.Service;

import java.time.Duration;
import java.time.Instant;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.stream.Collectors;

/**
 * Builds the projected schedule of all unfinished work of all active projects, together, on the shared resources.
 * The result is a projection recomputed on demand; it honours the manager FORCEs and stays close to the saved
 * reference plan (see {@link PlanOptimizer}). The baseline due time of an operation is fixed when it is assigned.
 */
@Service
public class PlanService {

    /**
     * @param tasks the planner input the schedule was computed from (used for metrics)
     * @param lanes busy intervals per resource lane
     * @param rules which dispatching rule won for each project (empty for the naive plan)
     * @param conflicts FORCEs that are not honoured, with the reason
     * @param honoured  operations placed exactly at their FORCEd start
     * @param escalated operations moved ahead of project priority so that a FORCE can be met
     */
    public record PlanView(Map<String, SchedulePlanner.Slot> slots, double makespanHours, Instant now,
                           Map<String, Double> tailHours, List<Task> tasks, Lanes lanes,
                           Map<String, PlanOptimizer.Rule> rules, int candidates, List<Conflict> conflicts,
                           Set<String> honoured, Set<String> escalated) {
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
            // millisecond precision, so a FORCEd start comes back as exactly the instant that was forced
            return now.plus(Duration.ofMillis(Math.round(hours * 3_600_000)));
        }
    }

    /** The optimised multi-project plan. */
    public PlanView plan(ProjectData data) {
        return plan(data, null);
    }

    /** @param rankOverride project id to rank, replacing the stored priorities (used to preview a re-ranking) */
    public PlanView plan(ProjectData data, Map<String, Integer> rankOverride) {
        List<Task> tasks = tasks(data, rankOverride);
        return view(data, tasks, PlanOptimizer.optimize(tasks, capacities(data), pins(data, true)));
    }

    /** Optimised from scratch: FORCEs are honoured, the reference plan is ignored (no stability preference). */
    public PlanView planFresh(ProjectData data) {
        List<Task> tasks = tasks(data, null);
        return view(data, tasks, PlanOptimizer.optimize(tasks, capacities(data), pins(data, false)));
    }

    /** Single-pass baseline (project rank, then critical path) under the same FORCEs, to measure the optimiser. */
    public PlanView planNaive(ProjectData data, Map<String, Integer> rankOverride) {
        List<Task> tasks = tasks(data, rankOverride);
        return view(data, tasks, PlanOptimizer.naive(tasks, capacities(data), pins(data, false)));
    }

    private PlanView view(ProjectData data, List<Task> tasks, PlanOptimizer.Result r) {
        Map<String, Double> hours = new HashMap<>();
        tasks.forEach(t -> hours.put(t.id(), t.hours()));
        Map<String, Double> tail = GraphUtil.tailHours(hours, data.successors());
        SchedulePlanner.Plan plan = r.plan();
        return new PlanView(plan.slots(), plan.makespan(), data.now(), tail, tasks, plan.lanes(), r.rules(),
                r.candidates(), r.conflicts(), r.honoured(), r.escalated());
    }

    /**
     * FORCEd starts and the reference plan in hours from now. The reference of an operation is the saved baseline,
     * or - if it was assigned after that was saved - the planned start its executor was given.
     */
    static Pins pins(ProjectData data, boolean withReference) {
        Map<String, Double> forced = new LinkedHashMap<>(); // oldest FORCE first: it wins a contradiction
        List<ScheduleForce> bySeniority = new ArrayList<>(data.forces().values());
        bySeniority.sort(Comparator.comparing(ScheduleForce::getCreatedAt, Comparator.nullsLast(Comparator.naturalOrder()))
                .thenComparing(ScheduleForce::getOperationId));
        for (ScheduleForce f : bySeniority) {
            Operation o = data.ops().get(f.getOperationId());
            if (f.getForcedStart() != null && o != null && !o.getStatus().isTerminal()) {
                forced.put(o.getId(), hoursFrom(data.now(), f.getForcedStart()));
            }
        }
        Map<String, Ref> reference = new HashMap<>();
        if (withReference) {
            for (Operation o : data.ops().values()) {
                if (o.getStatus().isTerminal()) {
                    continue;
                }
                boolean committed = o.getStatus() == OperationStatus.ASSIGNED;
                PlanBaseline b = data.baseline().get(o.getId());
                boolean assignedLater = committed && o.getPlannedStart() != null
                        && (b == null || (o.getAssignedAt() != null && o.getAssignedAt().isAfter(b.getSavedAt())));
                if (assignedLater) {
                    reference.put(o.getId(), new Ref(hoursFrom(data.now(), o.getPlannedStart()), true));
                } else if (b != null) {
                    reference.put(o.getId(), new Ref(hoursFrom(data.now(), b.getPlannedStart()), committed));
                }
            }
        }
        return new Pins(forced, reference);
    }

    static double hoursFrom(Instant now, Instant t) {
        return Duration.between(now, t).toMillis() / 3_600_000.0;
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
