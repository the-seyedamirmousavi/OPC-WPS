package com.aiso.assign;

import java.util.List;

/** Output of an assignment strategy: proposed picks, operations left unassigned and why. */
public record Suggestion(List<Pick> picks, List<Skip> skips, String summary, List<String> warnings,
                         String model, Long inputTokens, Long outputTokens) {

    public record Pick(String operationId, String userId, String reason) {
    }

    public record Skip(String operationId, String reason) {
    }

    public static Suggestion of(List<Pick> picks, List<Skip> skips, String summary) {
        return new Suggestion(picks, skips, summary, List.of(), null, null, null);
    }
}
