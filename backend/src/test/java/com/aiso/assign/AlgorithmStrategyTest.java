package com.aiso.assign;

import com.aiso.assign.AssignmentContext.ReadyOp;
import com.aiso.assign.AssignmentContext.ResourceInfo;
import com.aiso.assign.AssignmentContext.UserInfo;
import com.aiso.assign.Suggestion.Pick;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;
import java.util.function.Function;
import java.util.stream.Collectors;

import static org.assertj.core.api.Assertions.assertThat;

class AlgorithmStrategyTest {

    private final AlgorithmStrategy strategy = new AlgorithmStrategy();

    private static ReadyOp op(String id, String res, double hours, double tail, String fixed) {
        return new ReadyOp(id, id, null, res, res, hours, tail, fixed);
    }

    private static UserInfo user(String id, int tasks, double load) {
        return new UserInfo(id, id, true, tasks, load);
    }

    private static ResourceInfo res(String id, int cap, int used, String responsible) {
        return new ResourceInfo(id, id, cap, used, responsible, true);
    }

    private static Map<String, String> byOp(Suggestion s) {
        return s.picks().stream().collect(Collectors.toMap(Pick::operationId, Pick::userId));
    }

    @Test
    void spreadsWorkToTheLeastLoadedUser() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 10, 10, null), op("B", "R", 10, 10, null)),
                List.of(user("u1", 0, 20), user("u2", 0, 0)),
                List.of(res("R", 5, 0, null)), 3, 0);
        Map<String, String> picks = byOp(strategy.suggest(ctx));
        assertThat(picks.get("A")).isEqualTo("u2");   // u2 is empty
        assertThat(picks.get("B")).isEqualTo("u2");   // after A, u2 has 10h < u1's 20h
    }

    @Test
    void criticalPathOperationWinsTheLastFreeSlot() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("SHORT", "R", 5, 5, null), op("CRIT", "R", 5, 80, null)),
                List.of(user("u1", 0, 0), user("u2", 0, 0)),
                List.of(res("R", 1, 0, null)), 3, 0);
        Suggestion s = strategy.suggest(ctx);
        assertThat(byOp(s)).containsOnlyKeys("CRIT");
        assertThat(s.skips()).extracting(Suggestion.Skip::operationId).containsExactly("SHORT");
        assertThat(s.skips().get(0).reason()).contains("no free slot");
    }

    @Test
    void busyResourceSlotsAreRespected() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 5, 5, null)),
                List.of(user("u1", 0, 0)),
                List.of(res("R", 2, 2, null)), 3, 0);
        Suggestion s = strategy.suggest(ctx);
        assertThat(s.picks()).isEmpty();
        assertThat(s.skips()).hasSize(1);
    }

    @Test
    void fixedExecutorFromOpcIsAHardConstraint() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 5, 5, "u1")),
                List.of(user("u1", 0, 100), user("u2", 0, 0)),
                List.of(res("R", 1, 0, null)), 3, 0);
        assertThat(byOp(strategy.suggest(ctx))).containsEntry("A", "u1");
    }

    @Test
    void fixedExecutorAtTaskLimitLeavesOperationUnassigned() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 5, 5, "u1")),
                List.of(user("u1", 3, 10), user("u2", 0, 0)),
                List.of(res("R", 1, 0, null)), 3, 0);
        Suggestion s = strategy.suggest(ctx);
        assertThat(s.picks()).isEmpty();
        assertThat(s.skips().get(0).reason()).contains("u1");
    }

    @Test
    void taskLimitPerUserIsEnforcedAcrossTheBatch() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 1, 3, null), op("B", "R", 1, 2, null), op("C", "R", 1, 1, null)),
                List.of(user("u1", 0, 0)),
                List.of(res("R", 10, 0, null)), 2, 0);
        Suggestion s = strategy.suggest(ctx);
        assertThat(s.picks()).hasSize(2);
        assertThat(s.skips()).hasSize(1);
    }

    @Test
    void responsibleUserOfTheResourceIsPreferredWhenLoadsAreComparable() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 5, 5, null)),
                List.of(user("u1", 0, 4), user("u2", 0, 0)),
                List.of(res("R", 1, 0, "u1")), 3, 8);
        assertThat(byOp(strategy.suggest(ctx))).containsEntry("A", "u1");
    }

    @Test
    void responsiblePreferenceDoesNotOverrideAHugeImbalance() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 5, 5, null)),
                List.of(user("u1", 0, 40), user("u2", 0, 0)),
                List.of(res("R", 1, 0, "u1")), 3, 8);
        assertThat(byOp(strategy.suggest(ctx))).containsEntry("A", "u2");
    }

    @Test
    void sameInputGivesSameOutput() {
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 5, 5, null), op("B", "S", 5, 5, null), op("C", "R", 5, 5, null)),
                List.of(user("u1", 0, 0), user("u2", 0, 0), user("u3", 0, 0)),
                List.of(res("R", 2, 0, null), res("S", 1, 0, null)), 3, 0);
        assertThat(strategy.suggest(ctx).picks()).isEqualTo(strategy.suggest(ctx).picks());
    }

    @Test
    void inactiveUsersAreNeverChosen() {
        UserInfo off = new UserInfo("off", "off", false, 0, 0);
        AssignmentContext ctx = new AssignmentContext(
                List.of(op("A", "R", 5, 5, null)), List.of(off, user("u2", 2, 50)),
                List.of(res("R", 1, 0, null)), 3, 0);
        assertThat(byOp(strategy.suggest(ctx))).containsEntry("A", "u2");
    }
}
