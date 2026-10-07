package com.aiso.assign;

import com.aiso.assign.AssignmentContext.ReadyOp;
import com.aiso.assign.AssignmentContext.ResourceInfo;
import com.aiso.assign.AssignmentContext.UserInfo;
import com.aiso.assign.LlmStrategy.LlmAssignment;
import com.aiso.assign.LlmStrategy.LlmOutput;
import com.aiso.assign.LlmStrategy.LlmSkip;
import com.aiso.config.AisoProperties;
import org.junit.jupiter.api.Test;

import java.util.List;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** The model proposes, the system disposes: output that breaks a hard constraint must never get through. */
class LlmStrategyValidationTest {

    private static final AisoProperties NO_KEY = new AisoProperties(null, null,
            new AisoProperties.Llm("", "test-model", 30, 1000, true), new AisoProperties.Assignment(8));

    private final LlmStrategy strategy = new LlmStrategy(NO_KEY);

    private final AssignmentContext ctx = new AssignmentContext(
            List.of(new ReadyOp("A", "A", null, "R", "R", 5, 5, null),
                    new ReadyOp("B", "B", null, "R", "R", 5, 5, null),
                    new ReadyOp("C", "C", null, "S", "S", 5, 5, "u2")),
            List.of(new UserInfo("u1", "u1", true, 0, 0), new UserInfo("u2", "u2", true, 0, 0)),
            List.of(new ResourceInfo("R", "R", 1, 0, null, true), new ResourceInfo("S", "S", 1, 0, null, true)),
            3, 8);

    @Test
    void withoutApiKeyTheStrategyReportsUnavailable() {
        assertThatThrownBy(() -> strategy.suggest(ctx))
                .isInstanceOf(LlmUnavailableException.class)
                .hasMessageContaining("ANTHROPIC_API_KEY");
    }

    @Test
    void validAnswerPassesThrough() {
        LlmOutput out = new LlmOutput(
                List.of(new LlmAssignment("A", "u1", "critical"), new LlmAssignment("C", "u2", "fixed")),
                List.of(new LlmSkip("B", "resource R is full")), "ok");
        Suggestion s = strategy.validate(ctx, out, "m", 10L, 5L);
        assertThat(s.picks()).extracting(Suggestion.Pick::operationId).containsExactly("A", "C");
        assertThat(s.skips()).extracting(Suggestion.Skip::operationId).containsExactly("B");
        assertThat(s.warnings()).isEmpty();
        assertThat(s.inputTokens()).isEqualTo(10L);
    }

    @Test
    void capacityViolationIsDropped() {
        // R has one slot: the second assignment on R must be rejected
        LlmOutput out = new LlmOutput(
                List.of(new LlmAssignment("A", "u1", "x"), new LlmAssignment("B", "u2", "x")), List.of(), "s");
        Suggestion s = strategy.validate(ctx, out, "m", null, null);
        assertThat(s.picks()).extracting(Suggestion.Pick::operationId).containsExactly("A");
        assertThat(s.warnings()).anyMatch(w -> w.contains("B") && w.contains("capacity"));
        assertThat(s.skips()).extracting(Suggestion.Skip::operationId).contains("B", "C");
    }

    @Test
    void fixedExecutorViolationIsDropped() {
        LlmOutput out = new LlmOutput(List.of(new LlmAssignment("C", "u1", "x")), List.of(), "s");
        Suggestion s = strategy.validate(ctx, out, "m", null, null);
        assertThat(s.picks()).isEmpty();
        assertThat(s.warnings()).anyMatch(w -> w.contains("fixed"));
    }

    @Test
    void invented_idsAndDuplicatesAreIgnored() {
        LlmOutput out = new LlmOutput(
                List.of(new LlmAssignment("ZZZ", "u1", "x"), new LlmAssignment("A", "ghost", "x"),
                        new LlmAssignment("A", "u1", "ok"), new LlmAssignment("A", "u2", "dup")),
                List.of(), "s");
        Suggestion s = strategy.validate(ctx, out, "m", null, null);
        assertThat(s.picks()).extracting(Suggestion.Pick::operationId).containsExactly("A");
        assertThat(s.picks().get(0).userId()).isEqualTo("u1");
        assertThat(s.warnings()).hasSize(3);
    }

    @Test
    void nullListsFromTheModelAreTolerated() {
        Suggestion s = strategy.validate(ctx, new LlmOutput(null, null, null), "m", null, null);
        assertThat(s.picks()).isEmpty();
        assertThat(s.skips()).hasSize(3);
    }
}
