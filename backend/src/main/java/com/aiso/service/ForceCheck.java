package com.aiso.service;

import com.aiso.domain.DependencyType;
import com.aiso.service.PlanOptimizer.Conflict;
import com.aiso.service.PlanOptimizer.ConflictKind;
import com.aiso.service.SchedulePlanner.Dep;
import com.aiso.service.SchedulePlanner.Task;

import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeSet;

/**
 * The checks of manager FORCE start times that can be decided exactly. A FORCE rejected here is impossible for
 * <em>every</em> schedule, not just for the heuristic:
 * <ol>
 *   <li><b>start in the past</b> - nothing can start before "now";</li>
 *   <li><b>precedence</b> - even with unlimited resources the predecessor chain cannot be finished (FS) or started
 *       (SS) by the forced start; running work and the FORCEs already admitted are taken as fixed. A FORCE that would
 *       make an admitted FORCE impossible this way is rejected too ({@code BLOCKS_FORCE});</li>
 *   <li><b>capacity</b> - at some moment the forced and running operations on one resource would need more lanes than
 *       the resource has. For intervals that is exactly "more than capacity overlap at one point" (interval graphs
 *       are perfect), and the planner's first-fit in start order then always finds a lane.</li>
 * </ol>
 * FORCEs are admitted in <b>seniority</b> order (the order of the map: oldest first), so a new FORCE can never push
 * out an older one: the newcomer is the one reported. Running work always wins. A rejected FORCE constrains nothing
 * (a "not before" time could in turn break an older FORCE); it is only reported.
 */
final class ForceCheck {

    static final double EPS = 1e-6;

    private ForceCheck() {
    }

    /**
     * @param forced  requested start per operation (hours from now), oldest FORCE first; only pending tasks
     * @param out     receives one conflict per rejected FORCE
     * @return the FORCEs that pass all exact checks, in (start, id) order
     */
    static Map<String, Double> admissible(Map<String, Task> byId, Map<String, Integer> capacities,
                                          Map<String, Double> forced, List<Conflict> out) {
        Map<String, List<String>> owners = new HashMap<>();
        Map<String, List<double[]>> busy = running(byId, capacities, owners);
        Map<String, Double> admitted = new HashMap<>();
        for (Map.Entry<String, Double> f : forced.entrySet()) {
            String id = f.getKey();
            double start = f.getValue();
            Task t = byId.get(id);
            if (start < -EPS) {
                out.add(new Conflict(id, ConflictKind.START_IN_PAST, true, List.of(), start, 0));
                continue;
            }
            start = Math.max(0, start);

            Map<String, Double> trial = new HashMap<>(admitted);
            trial.put(id, start);
            Map<String, Double> es = earliestStarts(byId, trial);
            double need = need(t, byId, es);
            if (need > start + EPS) {
                out.add(new Conflict(id, ConflictKind.PRECEDENCE, true, chain(id, byId, es, trial), start, need));
                continue;
            }
            String blocked = null;
            for (String g : new TreeSet<>(admitted.keySet())) {
                if (need(byId.get(g), byId, es) > admitted.get(g) + EPS) {
                    blocked = g;
                    break;
                }
            }
            if (blocked != null) {
                out.add(new Conflict(id, ConflictKind.BLOCKS_FORCE, true, List.of(blocked), start, admitted.get(blocked)));
                continue;
            }

            double end = start + t.hours();
            List<double[]> intervals = busy.computeIfAbsent(t.resourceId(), r -> new ArrayList<>());
            List<String> ids = owners.computeIfAbsent(t.resourceId(), r -> new ArrayList<>());
            int capacity = Math.max(1, capacities.getOrDefault(t.resourceId(), 1));
            if (maxOverlap(intervals, start, end) + 1 > capacity) {
                List<String> clash = new ArrayList<>();
                double free = Double.MAX_VALUE;
                for (int i = 0; i < intervals.size(); i++) {
                    double[] iv = intervals.get(i);
                    if (iv[0] < end - EPS && start < iv[1] - EPS) {
                        clash.add(ids.get(i));
                        free = Math.min(free, iv[1]);
                    }
                }
                out.add(new Conflict(id, ConflictKind.CAPACITY, true, clash, start, free));
                continue;
            }
            intervals.add(new double[]{start, end});
            ids.add(id);
            admitted.put(id, start);
        }
        Map<String, Double> ordered = new LinkedHashMap<>();
        sortedByStart(admitted).forEach(id -> ordered.put(id, admitted.get(id)));
        return ordered;
    }

    /** Running work per resource as the planner places it (each on the lane that frees first, from 0). */
    private static Map<String, List<double[]>> running(Map<String, Task> byId, Map<String, Integer> capacities,
                                                      Map<String, List<String>> owners) {
        Map<String, List<double[]>> out = new HashMap<>();
        Map<String, double[]> laneEnds = new HashMap<>();
        for (Task t : byId.values()) { // same order as the planner
            if (!t.running()) {
                continue;
            }
            double[] laneEnd = laneEnds.computeIfAbsent(t.resourceId(), r -> new double[Math.max(1, capacities.getOrDefault(r, 1))]);
            int lane = minLane(laneEnd);
            double start = laneEnd[lane];
            laneEnd[lane] = start + Math.max(0, t.hours());
            out.computeIfAbsent(t.resourceId(), r -> new ArrayList<>()).add(new double[]{start, laneEnd[lane]});
            owners.computeIfAbsent(t.resourceId(), r -> new ArrayList<>()).add(t.id());
        }
        return out;
    }

    /** Largest number of {@code intervals} in use at one moment of [start, end). */
    private static int maxOverlap(List<double[]> intervals, double start, double end) {
        List<Double> points = new ArrayList<>();
        points.add(start);
        for (double[] iv : intervals) {
            if (iv[0] > start && iv[0] < end - EPS) {
                points.add(iv[0]);
            }
        }
        int max = 0;
        for (double p : points) {
            int n = 0;
            for (double[] iv : intervals) {
                if (iv[0] <= p + EPS && p < iv[1] - EPS) {
                    n++;
                }
            }
            max = Math.max(max, n);
        }
        return max;
    }

    static List<String> sortedByStart(Map<String, Double> starts) {
        List<String> ids = new ArrayList<>(starts.keySet());
        ids.sort(Comparator.comparingDouble((String id) -> starts.get(id)).thenComparing(id -> id));
        return ids;
    }

    /**
     * Earliest start of every task ignoring resource capacity (a lower bound): running work at 0, FORCEd work at its
     * forced start, everything else after its predecessors.
     */
    static Map<String, Double> earliestStarts(Map<String, Task> byId, Map<String, Double> fixed) {
        Map<String, Double> es = new HashMap<>();
        Set<String> visiting = new HashSet<>();
        for (String id : byId.keySet()) {
            es(id, byId, fixed, es, visiting);
        }
        return es;
    }

    private static double es(String id, Map<String, Task> byId, Map<String, Double> fixed, Map<String, Double> memo,
                             Set<String> visiting) {
        Double known = memo.get(id);
        if (known != null) {
            return known;
        }
        Task t = byId.get(id);
        double v;
        if (t.running()) {
            v = 0;
        } else if (fixed.containsKey(id)) {
            v = fixed.get(id);
        } else {
            v = 0;
            if (visiting.add(id)) { // a cycle (rejected at import) must not recurse forever
                for (Dep d : t.deps()) {
                    Task p = byId.get(d.predecessorId());
                    if (p != null) {
                        double ps = es(p.id(), byId, fixed, memo, visiting);
                        v = Math.max(v, d.type() == DependencyType.START_TO_START ? ps : ps + p.hours());
                    }
                }
                visiting.remove(id);
            }
        }
        memo.put(id, v);
        return v;
    }

    /** Earliest start the predecessors of {@code t} allow. */
    private static double need(Task t, Map<String, Task> byId, Map<String, Double> es) {
        double v = 0;
        for (Dep d : t.deps()) {
            Task p = byId.get(d.predecessorId());
            if (p != null) {
                double ps = es.get(p.id());
                v = Math.max(v, d.type() == DependencyType.START_TO_START ? ps : ps + p.hours());
            }
        }
        return v;
    }

    /** The binding predecessor chain of a FORCE that is too early, first operation first. */
    private static List<String> chain(String id, Map<String, Task> byId, Map<String, Double> es, Map<String, Double> fixed) {
        List<String> out = new ArrayList<>();
        Set<String> seen = new HashSet<>();
        String cur = id;
        while (cur != null && seen.add(cur)) {
            Task t = byId.get(cur);
            String next = null;
            double best = -1;
            for (Dep d : t.deps()) {
                Task p = byId.get(d.predecessorId());
                if (p != null) {
                    double v = d.type() == DependencyType.START_TO_START ? es.get(p.id()) : es.get(p.id()) + p.hours();
                    if (v > best + EPS || (Math.abs(v - best) <= EPS && next != null && p.id().compareTo(next) < 0)) {
                        best = v;
                        next = p.id();
                    }
                }
            }
            if (next == null || best <= EPS) {
                break;
            }
            out.add(next);
            Task n = byId.get(next);
            if (n.running() || fixed.containsKey(next)) {
                break;
            }
            cur = next;
        }
        Collections.reverse(out);
        return out;
    }

    private static int minLane(double[] laneEnd) {
        int best = 0;
        for (int i = 1; i < laneEnd.length; i++) {
            if (laneEnd[i] < laneEnd[best]) {
                best = i;
            }
        }
        return best;
    }
}
