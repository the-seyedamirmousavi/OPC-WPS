package com.aiso.service;

import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;
import java.util.Set;

import static org.assertj.core.api.Assertions.assertThat;

class GraphUtilTest {

    @Test
    void findsCycleAndReturnsClosedPath() {
        Map<String, Set<String>> preds = Map.of("A", Set.of("C"), "B", Set.of("A"), "C", Set.of("B"), "D", Set.of());
        List<String> cycle = GraphUtil.findCycle(preds);
        assertThat(cycle).isNotEmpty();
        assertThat(cycle.get(0)).isEqualTo(cycle.get(cycle.size() - 1));
        assertThat(cycle).contains("A", "B", "C").doesNotContain("D");
    }

    @Test
    void acyclicGraphHasNoCycle() {
        Map<String, Set<String>> preds = Map.of("A", Set.of(), "B", Set.of("A"), "C", Set.of("A", "B"));
        assertThat(GraphUtil.findCycle(preds)).isEmpty();
    }

    @Test
    void selfLoopIsACycle() {
        assertThat(GraphUtil.findCycle(Map.of("A", Set.of("A")))).containsExactly("A", "A");
    }

    @Test
    void tailIsLongestChainIncludingSelf() {
        // A(2) -> B(3) -> D(1)   and   A -> C(10) -> D
        Map<String, Double> hours = Map.of("A", 2.0, "B", 3.0, "C", 10.0, "D", 1.0);
        Map<String, Set<String>> succ = Map.of("A", Set.of("B", "C"), "B", Set.of("D"), "C", Set.of("D"));
        Map<String, Double> tail = GraphUtil.tailHours(hours, succ);
        assertThat(tail.get("D")).isEqualTo(1.0);
        assertThat(tail.get("C")).isEqualTo(11.0);
        assertThat(tail.get("B")).isEqualTo(4.0);
        assertThat(tail.get("A")).isEqualTo(13.0);
    }
}
