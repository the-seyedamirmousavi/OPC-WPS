package com.aiso.service;

import com.aiso.service.SchedulePlanner.Dep;
import com.aiso.service.SchedulePlanner.Lanes;
import com.aiso.service.SchedulePlanner.Plan;
import com.aiso.service.SchedulePlanner.Slot;
import com.aiso.service.SchedulePlanner.Task;

import java.util.ArrayList;
import java.util.Collection;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Random;
import java.util.Set;
import java.util.TreeMap;

/**
 * Schedules several projects at once on shared resources.
 *
 * <h3>Priority semantics (strict)</h3>
 * Projects are handled in priority order (rank 1 first). Everything already placed for a higher-priority project is
 * frozen, so a lower-priority project can never delay it; it can only use the time that is left.
 *
 * <h3>Minimising wasted resource time</h3>
 * <ul>
 *   <li>Lower-priority operations are placed into <em>idle gaps</em> that higher-priority work leaves on a lane.</li>
 *   <li>For each project several dispatching rules are tried (critical path, longest first, shortest first, most
 *       successors) plus seeded randomised variants of the critical-path rule.</li>
 *   <li><b>Look-ahead:</b> a candidate is not judged on its own project only. It is scored on the complete schedule
 *       that results when all lower-priority projects are then placed with the critical-path rule: first the finish
 *       times in priority order (lexicographic), then the total wasted lane time, then the sum of finish times. So a
 *       placement that leaves useless fragments for the projects behind it loses to one that leaves usable gaps.</li>
 *   <li>The critical-path candidate always reproduces the plain single-pass plan, therefore the result is never worse
 *       than that plan in priority order. Seeds are fixed, so results are reproducible.</li>
 * </ul>
 * Running (IN_PROGRESS) operations are committed first and are never moved.
 */
public final class PlanOptimizer {

    public enum Rule {
        CRITICAL_PATH, LONGEST_FIRST, SHORTEST_FIRST, MOST_SUCCESSORS, RANDOMIZED
    }

    /** @param rules which rule produced each project's placement; @param candidates total schedules evaluated */
    public record Result(Plan plan, Map<String, Rule> rules, int candidates) {
    }

    private static final double EPS = 1e-9;
    private static final int MAX_RANDOM_TRIALS = 48;
    private static final long RANDOM_WORK_BUDGET = 1_500_000L;

    private PlanOptimizer() {
    }

    /** Everything needed to place one project. */
    private record Proj(String id, int rank, List<Task> tasks, Set<String> pending, Map<String, Double> tail,
                        Map<String, Integer> descendants, Comparator<String> criticalPath) {
    }

    public static Result optimize(Collection<Task> tasks, Map<String, Integer> capacities) {
        Map<String, Task> byId = SchedulePlanner.index(tasks);
        Lanes lanes = new Lanes(capacities);
        Map<String, Slot> placed = new LinkedHashMap<>();
        SchedulePlanner.placeRunning(byId.values(), placed, lanes);

        Map<String, List<Task>> grouped = new TreeMap<>();
        Map<String, Integer> rankOf = new HashMap<>();
        for (Task t : byId.values()) {
            grouped.computeIfAbsent(t.projectId(), k -> new ArrayList<>()).add(t);
            rankOf.merge(t.projectId(), t.projectRank(), Math::min);
        }
        List<Proj> projects = new ArrayList<>();
        for (Map.Entry<String, List<Task>> e : grouped.entrySet()) {
            Set<String> pending = new HashSet<>();
            e.getValue().stream().filter(t -> !t.running()).forEach(t -> pending.add(t.id()));
            Map<String, Double> tail = SchedulePlanner.tails(e.getValue());
            projects.add(new Proj(e.getKey(), rankOf.get(e.getKey()), e.getValue(), pending, tail,
                    descendants(e.getValue()), criticalPath(tail)));
        }
        projects.sort(Comparator.comparingInt(Proj::rank).thenComparing(Proj::id));

        Map<String, Rule> chosen = new LinkedHashMap<>();
        int candidates = 0;
        for (int k = 0; k < projects.size(); k++) {
            Proj project = projects.get(k);
            if (project.pending().isEmpty()) {
                continue;
            }
            List<Candidate> options = candidates(project, byId);
            // work the look-ahead costs per trial: this project plus every project behind it
            long work = (long) project.pending().size() * project.pending().size();
            for (int j = k + 1; j < projects.size(); j++) {
                work += (long) projects.get(j).pending().size() * projects.get(j).pending().size();
            }
            int randomTrials = (int) Math.min(MAX_RANDOM_TRIALS, RANDOM_WORK_BUDGET / Math.max(1, work));
            for (int r = 0; r < randomTrials; r++) {
                Random rnd = new Random(31L * r + 7L * project.rank() + 1);
                Map<String, Double> key = new HashMap<>();
                for (String id : project.pending()) {
                    key.put(id, project.tail().getOrDefault(id, 0.0) * (1 + 0.6 * rnd.nextDouble()));
                }
                options.add(new Candidate(Rule.RANDOMIZED,
                        Comparator.comparing((String id) -> key.getOrDefault(id, 0.0)).reversed().thenComparing(id -> id)));
            }

            Trial best = null;
            for (Candidate c : options) {
                Trial t = run(c, byId, project, projects.subList(k + 1, projects.size()), placed, lanes, projects);
                candidates++;
                if (best == null || better(t.score, best.score)) {
                    best = t;
                }
            }
            placed = best.placed;
            lanes = best.lanes;
            chosen.put(project.id(), best.rule);
        }
        return new Result(SchedulePlanner.result(placed, lanes), chosen, candidates);
    }

    // ---- candidates & scoring --------------------------------------------------------------------------------

    private record Candidate(Rule rule, Comparator<String> priority) {
    }

    /** @param finishes project finish times in priority order, from the project being placed to the last one */
    private record Score(List<Double> finishes, double waste, double sumEnds) {
    }

    private record Trial(Rule rule, Map<String, Slot> placed, Lanes lanes, Score score) {
    }

    private static Comparator<String> criticalPath(Map<String, Double> tail) {
        return Comparator.comparing((String id) -> tail.getOrDefault(id, 0.0)).reversed().thenComparing(id -> id);
    }

    private static List<Candidate> candidates(Proj p, Map<String, Task> byId) {
        List<Candidate> options = new ArrayList<>();
        Comparator<String> byTailDesc = Comparator.comparing((String id) -> p.tail().getOrDefault(id, 0.0)).reversed();
        options.add(new Candidate(Rule.CRITICAL_PATH, p.criticalPath()));
        options.add(new Candidate(Rule.LONGEST_FIRST,
                Comparator.comparing((String id) -> byId.get(id).hours()).reversed().thenComparing(byTailDesc).thenComparing(id -> id)));
        options.add(new Candidate(Rule.SHORTEST_FIRST,
                Comparator.comparing((String id) -> byId.get(id).hours()).thenComparing(byTailDesc).thenComparing(id -> id)));
        options.add(new Candidate(Rule.MOST_SUCCESSORS,
                Comparator.comparing((String id) -> p.descendants().getOrDefault(id, 0)).reversed().thenComparing(byTailDesc).thenComparing(id -> id)));
        return options;
    }

    /**
     * Places {@code project} with the candidate rule, then completes the schedule with the critical-path rule for the
     * projects behind it, and scores that complete schedule. Only the placement of {@code project} is kept.
     */
    private static Trial run(Candidate c, Map<String, Task> byId, Proj project, List<Proj> behind,
                             Map<String, Slot> placed, Lanes lanes, List<Proj> all) {
        Lanes l = lanes.copy();
        Map<String, Slot> p = new HashMap<>(placed);
        SchedulePlanner.pass(byId, new HashSet<>(project.pending()), p, l, c.priority());
        Lanes keptLanes = l;
        Map<String, Slot> keptPlaced = p;

        Lanes simLanes = l.copy();
        Map<String, Slot> sim = new HashMap<>(p);
        for (Proj later : behind) {
            SchedulePlanner.pass(byId, new HashSet<>(later.pending()), sim, simLanes, later.criticalPath());
        }

        List<Double> finishes = new ArrayList<>();
        finishes.add(finishOf(project, sim));
        for (Proj later : behind) {
            finishes.add(finishOf(later, sim));
        }
        double sum = 0;
        for (Task t : project.tasks()) {
            Slot s = keptPlaced.get(t.id());
            if (s != null) {
                sum += s.end();
            }
        }
        return new Trial(c.rule(), keptPlaced, keptLanes, new Score(finishes, waste(simLanes), sum));
    }

    private static double finishOf(Proj p, Map<String, Slot> placed) {
        double f = 0;
        for (Task t : p.tasks()) {
            Slot s = placed.get(t.id());
            if (s != null) {
                f = Math.max(f, s.end());
            }
        }
        return f;
    }

    /** Idle lane time up to each lane's last task: the capacity that existed but could not be used. */
    static double waste(Lanes lanes) {
        double waste = 0;
        for (List<List<double[]>> resource : lanes.all().values()) {
            for (List<double[]> lane : resource) {
                double end = 0;
                double busy = 0;
                for (double[] iv : lane) {
                    busy += iv[1] - iv[0];
                    end = Math.max(end, iv[1]);
                }
                waste += end - busy;
            }
        }
        return waste;
    }

    private static boolean better(Score a, Score b) {
        for (int i = 0; i < a.finishes().size(); i++) {
            double x = a.finishes().get(i);
            double y = b.finishes().get(i);
            if (Math.abs(x - y) > EPS) {
                return x < y;
            }
        }
        if (Math.abs(a.waste() - b.waste()) > EPS) {
            return a.waste() < b.waste();
        }
        return a.sumEnds() < b.sumEnds() - EPS;
    }

    /** Number of (transitive) successors of each task inside its project. */
    private static Map<String, Integer> descendants(List<Task> tasks) {
        Map<String, List<String>> succ = new HashMap<>();
        Set<String> ids = new HashSet<>();
        tasks.forEach(t -> ids.add(t.id()));
        for (Task t : tasks) {
            for (Dep d : t.deps()) {
                if (ids.contains(d.predecessorId())) {
                    succ.computeIfAbsent(d.predecessorId(), k -> new ArrayList<>()).add(t.id());
                }
            }
        }
        Map<String, Integer> out = new HashMap<>();
        for (String id : ids) {
            Set<String> seen = new HashSet<>();
            List<String> stack = new ArrayList<>(succ.getOrDefault(id, List.of()));
            while (!stack.isEmpty()) {
                String x = stack.remove(stack.size() - 1);
                if (seen.add(x)) {
                    stack.addAll(succ.getOrDefault(x, List.of()));
                }
            }
            out.put(id, seen.size());
        }
        return out;
    }
}
