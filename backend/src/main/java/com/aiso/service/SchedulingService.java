package com.aiso.service;

import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Project;
import com.aiso.domain.ProjectStatus;
import com.aiso.domain.WorkResource;
import com.aiso.service.PlanService.PlanView;
import com.aiso.service.Scheduling.Impact;
import com.aiso.service.Scheduling.Overview;
import com.aiso.service.Scheduling.PlanMetrics;
import com.aiso.service.Scheduling.ProjectDelta;
import com.aiso.service.Scheduling.ProjectPlan;
import com.aiso.service.Scheduling.ProjectSummary;
import com.aiso.service.Scheduling.ResourceMetric;
import com.aiso.service.SchedulePlanner.Slot;
import com.aiso.service.SchedulePlanner.Task;
import com.aiso.web.ApiException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Duration;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.EnumMap;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** Measures schedules (finish per project, wasted resource time) and previews the effect of a ranking. */
@Service
public class SchedulingService {

    private final ProjectDataService dataService;
    private final PlanService planService;

    public SchedulingService(ProjectDataService dataService, PlanService planService) {
        this.dataService = dataService;
        this.planService = planService;
    }

    @Transactional
    public Overview overview() {
        ProjectData data = dataService.load();
        PlanView optimized = planService.plan(data);
        PlanView naive = planService.planNaive(data, null);
        return new Overview(metrics(data, optimized), metrics(data, naive), summaries(data, optimized));
    }

    /** What would happen to every project if the active projects were ranked in this order? Nothing is stored. */
    @Transactional
    public Impact previewRanking(List<String> ranking) {
        ProjectData data = dataService.load();
        Map<String, Integer> ranks = ranks(ranking, activeIds(data));
        return impact(data, data, ranks);
    }

    /**
     * @param current  data as it is now (ranks as stored)
     * @param proposed data after the change (e.g. after an import), scheduled with {@code proposedRanks}
     */
    public Impact impact(ProjectData current, ProjectData proposed, Map<String, Integer> proposedRanks) {
        PlanMetrics now = metrics(current, planService.plan(current));
        PlanMetrics optimized = metrics(proposed, planService.plan(proposed, proposedRanks));
        PlanMetrics naive = metrics(proposed, planService.planNaive(proposed, proposedRanks));

        Map<String, ProjectPlan> before = new HashMap<>();
        now.projects().forEach(p -> before.put(p.projectId(), p));
        List<ProjectDelta> deltas = new ArrayList<>();
        for (ProjectPlan p : optimized.projects()) {
            ProjectPlan old = before.get(p.projectId());
            deltas.add(new ProjectDelta(p.projectId(), p.name(), old == null ? null : old.rank(), p.rank(),
                    old == null ? null : old.finishHours(), p.finishHours(),
                    old == null ? null : p.finishHours() - old.finishHours()));
        }
        return new Impact(now, optimized, naive, deltas,
                naive.wasteHours() - optimized.wasteHours(), naive.makespanHours() - optimized.makespanHours());
    }

    // ---- metrics ----------------------------------------------------------------------------------------------

    public PlanMetrics metrics(ProjectData data, PlanView view) {
        Map<String, List<Task>> byProject = new HashMap<>();
        for (Task t : view.tasks()) {
            byProject.computeIfAbsent(t.projectId(), k -> new ArrayList<>()).add(t);
        }
        List<Project> active = data.projects().values().stream()
                .filter(p -> p.getStatus() == ProjectStatus.ACTIVE)
                .sorted(Comparator.comparingInt(Project::getPriority).thenComparing(Project::getId)).toList();

        List<ProjectPlan> plans = new ArrayList<>();
        for (Project p : active) {
            List<Task> tasks = byProject.getOrDefault(p.getId(), List.of());
            double start = Double.MAX_VALUE;
            double finish = 0;
            for (Task t : tasks) {
                Slot s = view.slots().get(t.id());
                if (s != null) {
                    start = Math.min(start, s.start());
                    finish = Math.max(finish, s.end());
                }
            }
            double tardiness = 0;
            if (p.getDueDate() != null && !tasks.isEmpty()) {
                double due = Duration.between(view.now(), p.getDueDate()).toSeconds() / 3600.0;
                tardiness = Math.max(0, finish - due);
            }
            int rank = tasks.isEmpty() ? p.getPriority() : tasks.get(0).projectRank();
            plans.add(new ProjectPlan(p.getId(), p.getName(), rank, tasks.size(), start == Double.MAX_VALUE ? 0 : start,
                    finish, tasks.isEmpty() ? null : view.at(finish), p.getDueDate(), tardiness,
                    view.rules().get(p.getId())));
        }
        plans.sort(Comparator.comparingInt(ProjectPlan::rank).thenComparing(ProjectPlan::projectId));

        double busyAll = 0;
        double wasteAll = 0;
        List<ResourceMetric> resources = new ArrayList<>();
        for (Map.Entry<String, List<List<double[]>>> e : new java.util.TreeMap<>(view.lanes().all()).entrySet()) {
            double busy = 0;
            double waste = 0;
            for (List<double[]> lane : e.getValue()) {
                double end = 0;
                double b = 0;
                for (double[] iv : lane) {
                    b += iv[1] - iv[0];
                    end = Math.max(end, iv[1]);
                }
                busy += b;
                waste += end - b;
            }
            if (busy <= 0 && waste <= 0) {
                continue;
            }
            WorkResource r = data.resources().get(e.getKey());
            resources.add(new ResourceMetric(e.getKey(), r == null ? e.getKey() : r.getName(),
                    r == null ? e.getValue().size() : r.getCapacity(), busy, waste, percent(busy, busy + waste)));
            busyAll += busy;
            wasteAll += waste;
        }
        return new PlanMetrics(view.makespanHours(), view.slots().isEmpty() ? null : view.projectEnd(), busyAll, wasteAll,
                percent(busyAll, busyAll + wasteAll), plans, resources, view.candidates());
    }

    private static double percent(double part, double whole) {
        return whole <= 0 ? 100 : part / whole * 100.0;
    }

    // ---- project summaries -----------------------------------------------------------------------------------

    List<ProjectSummary> summaries(ProjectData data, PlanView view) {
        PlanMetrics m = metrics(data, view);
        Map<String, ProjectPlan> plans = new HashMap<>();
        m.projects().forEach(p -> plans.put(p.projectId(), p));

        List<ProjectSummary> out = new ArrayList<>();
        for (Project p : data.projects().values()) {
            Map<OperationStatus, Long> counts = new EnumMap<>(OperationStatus.class);
            for (OperationStatus s : OperationStatus.values()) {
                counts.put(s, 0L);
            }
            double total = 0;
            double done = 0;
            int ops = 0;
            for (Operation o : data.ops().values()) {
                if (!p.getId().equals(o.getProjectId())) {
                    continue;
                }
                ops++;
                counts.merge(o.getStatus(), 1L, Long::sum);
                if (o.getStatus() != OperationStatus.CANCELLED) {
                    total += o.totalHours();
                    if (o.getStatus() == OperationStatus.COMPLETED) {
                        done += o.totalHours();
                    }
                }
            }
            ProjectPlan plan = plans.get(p.getId());
            out.add(new ProjectSummary(p.getId(), p.getName(), p.getPriority(), p.getStatus(), p.getDueDate(), p.getCreatedAt(),
                    ops, counts, total, done, total == 0 ? 0 : done / total * 100.0,
                    plan == null ? 0 : plan.openOperations(), plan == null ? 0 : plan.finishHours(),
                    plan == null ? null : plan.finishAt(), plan == null ? 0 : plan.tardinessHours()));
        }
        out.sort(Comparator.comparing((ProjectSummary s) -> s.status() == ProjectStatus.ACTIVE ? 0 : 1)
                .thenComparingInt(ProjectSummary::priority).thenComparing(ProjectSummary::id));
        return out;
    }

    // ---- ranking helpers (shared with ProjectService and the import preview) ---------------------------------

    public static List<String> activeIds(ProjectData data) {
        return data.projects().values().stream().filter(p -> p.getStatus() == ProjectStatus.ACTIVE)
                .sorted(Comparator.comparingInt(Project::getPriority).thenComparing(Project::getId))
                .map(Project::getId).toList();
    }

    /** Validates that {@code ranking} lists every expected project exactly once and returns id -> rank (1-based). */
    public static Map<String, Integer> ranks(List<String> ranking, Iterable<String> expected) {
        if (ranking == null || ranking.isEmpty()) {
            throw ApiException.badRequest("A ranking is required");
        }
        Set<String> want = new HashSet<>();
        expected.forEach(want::add);
        Set<String> seen = new HashSet<>();
        for (String id : ranking) {
            if (!seen.add(id)) {
                throw ApiException.badRequest("Project " + id + " appears twice in the ranking");
            }
            if (!want.contains(id)) {
                throw ApiException.badRequest("Unknown or inactive project " + id + " in the ranking");
            }
        }
        if (seen.size() != want.size()) {
            Set<String> missing = new HashSet<>(want);
            missing.removeAll(seen);
            throw ApiException.badRequest("The ranking must include every active project; missing: " + missing);
        }
        Map<String, Integer> ranks = new LinkedHashMap<>();
        for (int i = 0; i < ranking.size(); i++) {
            ranks.put(ranking.get(i), i + 1);
        }
        return ranks;
    }
}
