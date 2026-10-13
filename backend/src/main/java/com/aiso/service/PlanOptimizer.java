package com.aiso.service;

import com.aiso.domain.DependencyType;
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
import java.util.TreeSet;

/**
 * Schedules several projects at once on shared resources, honouring manager FORCEs.
 *
 * <h3>Constraint hierarchy</h3>
 * <ol>
 *   <li><b>Absolute</b> (never violated): resource capacity, mandatory precedence (FS/SS), running work stays where it
 *       is, nothing starts before "now".</li>
 *   <li><b>Manager FORCE</b>: a forced operation starts exactly at its forced time. A FORCE that contradicts an absolute
 *       constraint is not applied; it is reported as a {@link Conflict} and becomes "not before that time".</li>
 *   <li><b>Manager-overridable</b>: strict project priority. A FORCE may need predecessors of the forced operation to
 *       run before higher-priority work; that only happens when the plain priority order misses the forced start.</li>
 *   <li><b>Soft</b> (the objective): see below.</li>
 * </ol>
 *
 * <h3>Construction</h3>
 * Running work is placed at 0, then FORCEd work at its exact start, then (only when needed) the predecessors of a
 * FORCE that would otherwise be late, earliest deadline first, then every project in priority order. Everything
 * already placed is frozen, so a lower-priority project can never delay a higher-priority one; it uses the time left.
 * For each project several dispatching rules are tried (critical path, longest first, shortest first, most
 * successors, seeded randomised critical path, and "stable": the reference plan replayed, each operation not earlier
 * than its reference start).
 *
 * <h3>Objective (lexicographic)</h3>
 * A candidate is scored on the complete schedule that results when all lower-priority projects are then placed with
 * the critical-path rule (look-ahead): (1) project finish times in priority order, (2) churn against the reference
 * plan: moved committed (already assigned) operations, then moved operations, then total shift, (3) wasted lane
 * time, (4) sum of finish times. So the schedule only changes where that helps a higher-priority goal.
 * <p>
 * Without FORCEs the critical-path candidate reproduces the plain single-pass plan, so the result is never worse than
 * that plan in priority order. This is a heuristic: it does not prove optimality. Seeds are fixed; the same input
 * always gives the same plan.
 */
public final class PlanOptimizer {

    public enum Rule {
        CRITICAL_PATH, LONGEST_FIRST, SHORTEST_FIRST, MOST_SUCCESSORS, RANDOMIZED, STABLE
    }

    public enum ConflictKind {
        /** the forced start is before now */
        START_IN_PAST,
        /** the predecessor chain cannot be done by the forced start even with unlimited resources */
        PRECEDENCE,
        /** it would make an older FORCE impossible by precedence ({@code related} = that FORCE) */
        BLOCKS_FORCE,
        /** forced and running work on the resource overlap beyond its capacity */
        CAPACITY,
        /** no exact contradiction was found, but this heuristic found no schedule that honours the FORCE */
        NOT_FOUND
    }

    /** Where an operation was in the reference plan (hours from now); committed = it is already assigned. */
    public record Ref(double start, boolean committed) {
    }

    /**
     * Manager FORCE start times and the reference plan that keeps the schedule stable (all in hours from now).
     * The iteration order of {@code forcedStart} is the seniority of the FORCEs, oldest first: when FORCEs contradict
     * each other, the younger one is given up.
     */
    public record Pins(Map<String, Double> forcedStart, Map<String, Ref> reference) {
        public static final Pins NONE = new Pins(Map.of(), Map.of());
    }

    /**
     * A FORCE that is not honoured.
     *
     * @param proven         true = no schedule at all can honour it; false = only this heuristic could not
     * @param related        the operations it collides with (CAPACITY) or the binding predecessor chain (PRECEDENCE)
     * @param earliestStart  earliest start the constraint allows (PRECEDENCE), when a clashing operation ends
     *                       (CAPACITY), the start actually planned (NOT_FOUND), the forced start of the older FORCE
     *                       (BLOCKS_FORCE), 0 (START_IN_PAST)
     */
    public record Conflict(String operationId, ConflictKind kind, boolean proven, List<String> related,
                           double requestedStart, double earliestStart) {
    }

    /**
     * @param rules      which rule produced each project's placement
     * @param candidates total schedules evaluated
     * @param honoured   FORCEd operations placed exactly at their forced start
     * @param escalated  operations placed ahead of project priority so that a FORCE could be met
     */
    public record Result(Plan plan, Map<String, Rule> rules, int candidates, List<Conflict> conflicts,
                         Set<String> honoured, Set<String> escalated) {
    }

    private static final double EPS = 1e-9;
    /** A start that changed by less than a minute is not a move. */
    static final double MOVE_EPS = 1.0 / 60;
    private static final int MAX_RANDOM_TRIALS = 48;
    private static final long RANDOM_WORK_BUDGET = 1_500_000L;

    private PlanOptimizer() {
    }

    /** Everything needed to place one project (independent of what is already placed). */
    private record Proj(String id, int rank, List<Task> tasks, Map<String, Double> tail,
                        Map<String, Integer> descendants, Comparator<String> criticalPath) {
    }

    public static Result optimize(Collection<Task> tasks, Map<String, Integer> capacities) {
        return run(tasks, capacities, Pins.NONE, false);
    }

    public static Result optimize(Collection<Task> tasks, Map<String, Integer> capacities, Pins pins) {
        return run(tasks, capacities, pins, false);
    }

    /** The single-pass baseline (critical-path rule per project in priority order, no search) under the same FORCEs. */
    public static Result naive(Collection<Task> tasks, Map<String, Integer> capacities, Pins pins) {
        return run(tasks, capacities, pins, true);
    }

    private static Result run(Collection<Task> tasks, Map<String, Integer> capacities, Pins pins, boolean naive) {
        Map<String, Task> byId = SchedulePlanner.index(tasks);
        Map<String, Double> requested = new LinkedHashMap<>(); // seniority order
        pins.forcedStart().forEach((id, start) -> {
            Task t = byId.get(id);
            if (t != null && !t.running()) {
                requested.put(id, start);
            }
        });
        List<Conflict> conflicts = new ArrayList<>();
        Map<String, Double> release = new HashMap<>(); // stays empty: a FORCE that is given up constrains nothing
        Map<String, Double> active = ForceCheck.admissible(byId, capacities, requested, conflicts);
        List<Proj> projects = projects(byId);
        Map<String, List<String>> successors = successors(byId);

        // Escalation: a FORCE whose predecessors end too late first gets its predecessor chain placed ahead of
        // project priority (earliest deadline first). If that is not enough, a FORCE is given up (reported): a younger
        // one that stands in the way, otherwise the late one.
        Set<String> escalatedForces = new TreeSet<>();
        Set<String> urgent;
        Attempt a;
        while (true) {
            urgent = ancestors(byId, escalatedForces, active);
            a = attempt(byId, capacities, pins.reference(), active, urgent, release, projects, successors, naive);
            List<String> late = late(byId, active, a.placed);
            if (late.isEmpty()) {
                break;
            }
            boolean escalatedMore = false;
            for (String id : late) {
                escalatedMore |= escalatedForces.add(id);
            }
            if (!escalatedMore) {
                // Still late with every late FORCE escalated. Seniority decides who is given up: first try whether
                // giving up a younger FORCE on a resource the oldest late one depends on rescues it; otherwise give
                // up the youngest late FORCE.
                String oldestLate = null;
                String youngestLate = null;
                for (String id : requested.keySet()) {
                    if (late.contains(id)) {
                        oldestLate = oldestLate == null ? id : oldestLate;
                        youngestLate = id;
                    }
                }
                String victim = null;
                List<String> related = null;
                Set<String> resources = resourcesOf(byId, oldestLate);
                List<String> younger = new ArrayList<>(requested.keySet());
                younger = younger.subList(younger.indexOf(oldestLate) + 1, younger.size());
                for (int i = younger.size() - 1; i >= 0 && victim == null; i--) {
                    String j = younger.get(i);
                    if (!active.containsKey(j) || !resources.contains(byId.get(j).resourceId())) {
                        continue;
                    }
                    Map<String, Double> without = new LinkedHashMap<>(active);
                    without.remove(j);
                    Set<String> esc = new TreeSet<>(escalatedForces);
                    esc.remove(j);
                    Attempt probe = attempt(byId, capacities, pins.reference(), without, ancestors(byId, esc, without),
                            release, projects, successors, naive);
                    if (!late(byId, without, probe.placed).contains(oldestLate)) {
                        victim = j;
                        related = List.of(oldestLate);
                    }
                }
                if (victim == null) {
                    victim = youngestLate;
                    related = lateDeps(byId, victim, active.get(victim), a.placed);
                }
                escalatedForces.remove(victim);
                double start = active.remove(victim);
                conflicts.add(new Conflict(victim, ConflictKind.NOT_FOUND, false, related, start, Double.NaN));
            }
        }

        List<Conflict> out = new ArrayList<>();
        for (Conflict c : conflicts) {
            Slot s = a.placed.get(c.operationId());
            out.add(c.kind() == ConflictKind.NOT_FOUND && s != null
                    ? new Conflict(c.operationId(), c.kind(), c.proven(), c.related(), c.requestedStart(), s.start()) : c);
        }
        return new Result(SchedulePlanner.result(a.placed, a.lanes), a.rules, a.candidates, out,
                new TreeSet<>(active.keySet()), urgent);
    }

    private record Attempt(Map<String, Slot> placed, Lanes lanes, Map<String, Rule> rules, int candidates) {
    }

    private static Attempt attempt(Map<String, Task> byId, Map<String, Integer> capacities, Map<String, Ref> reference,
                                   Map<String, Double> active, Set<String> urgent, Map<String, Double> release,
                                   List<Proj> projects, Map<String, List<String>> successors, boolean naive) {
        Lanes lanes = new Lanes(capacities);
        Map<String, Slot> placed = new LinkedHashMap<>();
        SchedulePlanner.placeRunning(byId.values(), placed, lanes);
        for (Map.Entry<String, Double> f : active.entrySet()) { // already in (start, id) order
            Task t = byId.get(f.getKey());
            Slot s = SchedulePlanner.placeAt(lanes, t.resourceId(), f.getValue(), t.hours());
            if (s != null) { // ForceCheck guarantees a free lane; if not, late() reports the FORCE
                placed.put(t.id(), s);
            }
        }
        Map<String, Double> rel = new HashMap<>(release);
        active.forEach((id, start) -> {
            if (!placed.containsKey(id)) {
                rel.put(id, start);
            }
        });
        if (!urgent.isEmpty()) {
            Map<String, Double> ls = latestStarts(byId, urgent, active, successors);
            Comparator<String> edf = Comparator.comparingDouble((String id) -> ls.getOrDefault(id, Double.MAX_VALUE))
                    .thenComparing(id -> id);
            SchedulePlanner.pass(byId, new HashSet<>(urgent), placed, lanes, edf, rel);
        }

        Map<String, Set<String>> pending = new LinkedHashMap<>();
        for (Proj p : projects) {
            Set<String> ids = new HashSet<>();
            p.tasks().stream().filter(t -> !placed.containsKey(t.id())).forEach(t -> ids.add(t.id()));
            pending.put(p.id(), ids);
        }

        Map<String, Slot> current = placed;
        Lanes currentLanes = lanes;
        Map<String, Rule> chosen = new LinkedHashMap<>();
        int candidates = 0;
        for (int k = 0; k < projects.size(); k++) {
            Proj project = projects.get(k);
            Set<String> mine = pending.get(project.id());
            if (mine.isEmpty()) {
                continue;
            }
            List<Candidate> options = new ArrayList<>();
            options.add(new Candidate(Rule.CRITICAL_PATH, project.criticalPath()));
            if (!naive) {
                options.addAll(alternatives(project, byId));
                if (mine.stream().anyMatch(reference::containsKey)) {
                    Map<String, Double> keep = new HashMap<>();
                    mine.forEach(id -> {
                        if (reference.containsKey(id)) {
                            keep.put(id, reference.get(id).start());
                        }
                    });
                    options.add(new Candidate(Rule.STABLE, stable(project, reference), keep));
                }
                // work the look-ahead costs per trial: this project plus every project behind it
                long work = (long) mine.size() * mine.size();
                for (int j = k + 1; j < projects.size(); j++) {
                    long n = pending.get(projects.get(j).id()).size();
                    work += n * n;
                }
                int randomTrials = (int) Math.min(MAX_RANDOM_TRIALS, RANDOM_WORK_BUDGET / Math.max(1, work));
                for (int r = 0; r < randomTrials; r++) {
                    Random rnd = new Random(31L * r + 7L * project.rank() + 1);
                    Map<String, Double> key = new HashMap<>();
                    for (String id : new TreeSet<>(mine)) { // sorted: the keys do not depend on input order
                        key.put(id, project.tail().getOrDefault(id, 0.0) * (1 + 0.6 * rnd.nextDouble()));
                    }
                    options.add(new Candidate(Rule.RANDOMIZED,
                            Comparator.comparing((String id) -> key.getOrDefault(id, 0.0)).reversed().thenComparing(id -> id)));
                }
            }

            Trial best = null;
            List<Proj> behind = projects.subList(k + 1, projects.size());
            for (Candidate c : options) {
                Trial t = trial(c, byId, project, behind, pending, current, currentLanes, reference, rel);
                candidates++;
                if (best == null || better(t.score, best.score)) {
                    best = t;
                }
            }
            current = best.placed;
            currentLanes = best.lanes;
            chosen.put(project.id(), best.rule);
        }
        return new Attempt(current, currentLanes, chosen, candidates);
    }

    // ---- candidates & scoring --------------------------------------------------------------------------------

    /** @param release extra "not before" times of this candidate (the stable rule keeps reference starts), or empty */
    private record Candidate(Rule rule, Comparator<String> priority, Map<String, Double> release) {
        Candidate(Rule rule, Comparator<String> priority) {
            this(rule, priority, Map.of());
        }
    }

    /** Moves against the reference plan: committed (assigned) operations count first. */
    private record Churn(int committedMoved, int moved, double shift) {
    }

    /** @param finishes project finish times in priority order, from the project being placed to the last one */
    private record Score(List<Double> finishes, Churn churn, double waste, double sumEnds) {
    }

    private record Trial(Rule rule, Map<String, Slot> placed, Lanes lanes, Score score) {
    }

    private static Comparator<String> criticalPath(Map<String, Double> tail) {
        return Comparator.comparing((String id) -> tail.getOrDefault(id, 0.0)).reversed().thenComparing(id -> id);
    }

    private static List<Candidate> alternatives(Proj p, Map<String, Task> byId) {
        List<Candidate> options = new ArrayList<>();
        Comparator<String> byTailDesc = Comparator.comparing((String id) -> p.tail().getOrDefault(id, 0.0)).reversed();
        options.add(new Candidate(Rule.LONGEST_FIRST,
                Comparator.comparing((String id) -> byId.get(id).hours()).reversed().thenComparing(byTailDesc).thenComparing(id -> id)));
        options.add(new Candidate(Rule.SHORTEST_FIRST,
                Comparator.comparing((String id) -> byId.get(id).hours()).thenComparing(byTailDesc).thenComparing(id -> id)));
        options.add(new Candidate(Rule.MOST_SUCCESSORS,
                Comparator.comparing((String id) -> p.descendants().getOrDefault(id, 0)).reversed().thenComparing(byTailDesc).thenComparing(id -> id)));
        return options;
    }

    /**
     * Reference-plan order; together with the reference starts as "not before" times this replays the reference plan
     * exactly where it is still feasible and shifts work only as far as the changes force it. New operations follow
     * by critical path.
     */
    private static Comparator<String> stable(Proj p, Map<String, Ref> reference) {
        return Comparator.comparingInt((String id) -> reference.containsKey(id) ? 0 : 1)
                .thenComparingDouble(id -> reference.containsKey(id) ? reference.get(id).start() : 0)
                .thenComparing(p.criticalPath());
    }

    /**
     * Places {@code project} with the candidate rule, then completes the schedule with the critical-path rule for the
     * projects behind it, and scores that complete schedule. Only the placement of {@code project} is kept.
     */
    private static Trial trial(Candidate c, Map<String, Task> byId, Proj project, List<Proj> behind,
                               Map<String, Set<String>> pending, Map<String, Slot> placed, Lanes lanes,
                               Map<String, Ref> reference, Map<String, Double> release) {
        Lanes l = lanes.copy();
        Map<String, Slot> p = new HashMap<>(placed);
        Map<String, Double> rel = release;
        if (!c.release().isEmpty()) {
            rel = new HashMap<>(release);
            for (Map.Entry<String, Double> e : c.release().entrySet()) {
                rel.merge(e.getKey(), e.getValue(), Math::max);
            }
        }
        SchedulePlanner.pass(byId, new HashSet<>(pending.get(project.id())), p, l, c.priority(), rel);

        Lanes simLanes = l.copy();
        Map<String, Slot> sim = new HashMap<>(p);
        for (Proj later : behind) {
            SchedulePlanner.pass(byId, new HashSet<>(pending.get(later.id())), sim, simLanes, later.criticalPath(), release);
        }

        List<Double> finishes = new ArrayList<>();
        finishes.add(finishOf(project, sim));
        for (Proj later : behind) {
            finishes.add(finishOf(later, sim));
        }
        double sum = 0;
        for (Task t : project.tasks()) {
            Slot s = p.get(t.id());
            if (s != null) {
                sum += s.end();
            }
        }
        return new Trial(c.rule(), p, l, new Score(finishes, churn(pending.get(project.id()), p, reference), waste(simLanes), sum));
    }

    private static Churn churn(Set<String> ids, Map<String, Slot> placed, Map<String, Ref> reference) {
        int committed = 0;
        int moved = 0;
        double shift = 0;
        for (String id : ids) {
            Ref r = reference.get(id);
            Slot s = placed.get(id);
            if (r == null || s == null) {
                continue;
            }
            double d = Math.abs(s.start() - Math.max(0, r.start()));
            if (d > MOVE_EPS) {
                moved++;
                shift += d;
                if (r.committed()) {
                    committed++;
                }
            }
        }
        return new Churn(committed, moved, shift);
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
        if (a.churn().committedMoved() != b.churn().committedMoved()) {
            return a.churn().committedMoved() < b.churn().committedMoved();
        }
        if (a.churn().moved() != b.churn().moved()) {
            return a.churn().moved() < b.churn().moved();
        }
        if (Math.abs(a.churn().shift() - b.churn().shift()) > 1e-6) {
            return a.churn().shift() < b.churn().shift();
        }
        if (Math.abs(a.waste() - b.waste()) > EPS) {
            return a.waste() < b.waste();
        }
        return a.sumEnds() < b.sumEnds() - EPS;
    }

    // ---- FORCE helpers ------------------------------------------------------------------------------------------

    /** FORCEs not placed at their start, or whose predecessors finish (FS) / start (SS) after it. */
    private static List<String> late(Map<String, Task> byId, Map<String, Double> active, Map<String, Slot> placed) {
        List<String> late = new ArrayList<>();
        for (Map.Entry<String, Double> f : active.entrySet()) {
            Slot s = placed.get(f.getKey());
            if (s == null || Math.abs(s.start() - f.getValue()) > ForceCheck.EPS
                    || !lateDeps(byId, f.getKey(), f.getValue(), placed).isEmpty()) {
                late.add(f.getKey());
            }
        }
        return late;
    }

    private static List<String> lateDeps(Map<String, Task> byId, String id, double start, Map<String, Slot> placed) {
        List<String> out = new ArrayList<>();
        for (Dep d : byId.get(id).deps()) {
            if (!byId.containsKey(d.predecessorId())) {
                continue;
            }
            Slot p = placed.get(d.predecessorId());
            double bound = p == null ? Double.MAX_VALUE : d.type() == DependencyType.START_TO_START ? p.start() : p.end();
            if (bound > start + ForceCheck.EPS) {
                out.add(d.predecessorId());
            }
        }
        return out;
    }

    /** Resources of the operation and of all its (transitive) predecessors. */
    private static Set<String> resourcesOf(Map<String, Task> byId, String id) {
        Set<String> out = new HashSet<>();
        Set<String> seen = new HashSet<>();
        List<String> stack = new ArrayList<>(List.of(id));
        while (!stack.isEmpty()) {
            Task t = byId.get(stack.remove(stack.size() - 1));
            if (t == null || !seen.add(t.id())) {
                continue;
            }
            out.add(t.resourceId());
            t.deps().forEach(d -> stack.add(d.predecessorId()));
        }
        return out;
    }

    /** Unplaced predecessors (transitively) of the escalated FORCEs; stops at running and other FORCEd work. */
    private static Set<String> ancestors(Map<String, Task> byId, Set<String> forces, Map<String, Double> active) {
        Set<String> out = new TreeSet<>();
        List<String> stack = new ArrayList<>(forces);
        while (!stack.isEmpty()) {
            Task t = byId.get(stack.remove(stack.size() - 1));
            for (Dep d : t.deps()) {
                Task p = byId.get(d.predecessorId());
                if (p != null && !p.running() && !active.containsKey(p.id()) && out.add(p.id())) {
                    stack.add(p.id());
                }
            }
        }
        return out;
    }

    /** Latest start of each escalated predecessor that still lets every FORCE downstream of it start on time. */
    private static Map<String, Double> latestStarts(Map<String, Task> byId, Set<String> urgent, Map<String, Double> active,
                                                    Map<String, List<String>> successors) {
        Map<String, Double> memo = new HashMap<>();
        for (String id : urgent) {
            latest(id, byId, urgent, active, successors, memo, new HashSet<>());
        }
        return memo;
    }

    private static double latest(String id, Map<String, Task> byId, Set<String> urgent, Map<String, Double> active,
                                 Map<String, List<String>> successors, Map<String, Double> memo, Set<String> visiting) {
        Double known = memo.get(id);
        if (known != null) {
            return known;
        }
        double v = Double.MAX_VALUE;
        if (visiting.add(id)) {
            double h = byId.get(id).hours();
            for (String s : successors.getOrDefault(id, List.of())) {
                boolean ss = byId.get(s).deps().stream()
                        .anyMatch(d -> d.predecessorId().equals(id) && d.type() == DependencyType.START_TO_START);
                Double bound = active.containsKey(s) ? active.get(s)
                        : urgent.contains(s) ? latest(s, byId, urgent, active, successors, memo, visiting) : null;
                if (bound != null && bound < Double.MAX_VALUE) {
                    v = Math.min(v, ss ? bound : bound - h);
                }
            }
            visiting.remove(id);
        }
        memo.put(id, v);
        return v;
    }

    // ---- structure ------------------------------------------------------------------------------------------------

    private static List<Proj> projects(Map<String, Task> byId) {
        Map<String, List<Task>> grouped = new TreeMap<>();
        Map<String, Integer> rankOf = new HashMap<>();
        for (Task t : byId.values()) {
            grouped.computeIfAbsent(t.projectId(), k -> new ArrayList<>()).add(t);
            rankOf.merge(t.projectId(), t.projectRank(), Math::min);
        }
        List<Proj> projects = new ArrayList<>();
        for (Map.Entry<String, List<Task>> e : grouped.entrySet()) {
            Map<String, Double> tail = SchedulePlanner.tails(e.getValue());
            projects.add(new Proj(e.getKey(), rankOf.get(e.getKey()), e.getValue(), tail,
                    descendants(e.getValue()), criticalPath(tail)));
        }
        projects.sort(Comparator.comparingInt(Proj::rank).thenComparing(Proj::id));
        return projects;
    }

    private static Map<String, List<String>> successors(Map<String, Task> byId) {
        Map<String, List<String>> succ = new HashMap<>();
        for (Task t : byId.values()) {
            for (Dep d : t.deps()) {
                if (byId.containsKey(d.predecessorId())) {
                    succ.computeIfAbsent(d.predecessorId(), k -> new ArrayList<>()).add(t.id());
                }
            }
        }
        return succ;
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
