package com.aiso.service;

import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** Pure graph helpers over operation ids. No Spring or persistence dependencies. */
public final class GraphUtil {

    private GraphUtil() {
    }

    /**
     * Finds a dependency cycle.
     *
     * @param predecessors operation id -> ids of its predecessors
     * @return the cycle as a path whose first and last element are equal (e.g. A, B, C, A), or an empty list
     */
    public static List<String> findCycle(Map<String, ? extends Collection<String>> predecessors) {
        Map<String, Integer> state = new HashMap<>(); // 1 = on stack, 2 = done
        List<String> keys = new ArrayList<>(predecessors.keySet());
        Collections.sort(keys);
        for (String start : keys) {
            if (state.getOrDefault(start, 0) == 0) {
                List<String> path = new ArrayList<>();
                List<String> cycle = dfs(start, predecessors, state, path);
                if (!cycle.isEmpty()) {
                    return cycle;
                }
            }
        }
        return List.of();
    }

    private static List<String> dfs(String node, Map<String, ? extends Collection<String>> preds,
                                    Map<String, Integer> state, List<String> path) {
        state.put(node, 1);
        path.add(node);
        Collection<String> direct = preds.get(node);
        List<String> next = direct == null ? new ArrayList<>() : new ArrayList<>(direct);
        Collections.sort(next);
        for (String p : next) {
            int s = state.getOrDefault(p, 0);
            if (s == 1) {
                List<String> cycle = new ArrayList<>(path.subList(path.indexOf(p), path.size()));
                cycle.add(p);
                return cycle;
            }
            if (s == 0) {
                List<String> cycle = dfs(p, preds, state, path);
                if (!cycle.isEmpty()) {
                    return cycle;
                }
            }
        }
        path.remove(path.size() - 1);
        state.put(node, 2);
        return List.of();
    }

    /**
     * Longest remaining duration from each operation to the end of the project, including the operation itself.
     * A larger tail means the operation is more critical.
     *
     * @param hours      operation id -> duration in hours (only ids in this map are considered)
     * @param successors operation id -> ids of operations depending on it
     */
    public static Map<String, Double> tailHours(Map<String, Double> hours, Map<String, ? extends Collection<String>> successors) {
        Map<String, Double> memo = new HashMap<>();
        for (String id : hours.keySet()) {
            tail(id, hours, successors, memo, new HashSet<>());
        }
        return memo;
    }

    private static double tail(String id, Map<String, Double> hours, Map<String, ? extends Collection<String>> succ,
                               Map<String, Double> memo, Set<String> visiting) {
        Double known = memo.get(id);
        if (known != null) {
            return known;
        }
        if (!visiting.add(id)) {
            return 0; // defensive: cycles are rejected at import
        }
        double best = 0;
        Collection<String> next = succ.get(id);
        for (String s : next == null ? List.<String>of() : next) {
            if (hours.containsKey(s)) {
                best = Math.max(best, tail(s, hours, succ, memo, visiting));
            }
        }
        visiting.remove(id);
        double value = hours.getOrDefault(id, 0.0) + best;
        memo.put(id, value);
        return value;
    }
}
