package com.aiso.assign;

import com.aiso.assign.AssignmentContext.ReadyOp;
import com.aiso.assign.Suggestion.Pick;
import com.aiso.assign.Suggestion.Skip;
import com.aiso.config.AisoProperties;
import com.aiso.domain.AssignmentMode;
import com.anthropic.client.AnthropicClient;
import com.anthropic.client.okhttp.AnthropicOkHttpClient;
import com.anthropic.models.messages.MessageCreateParams;
import com.anthropic.models.messages.StopReason;
import com.anthropic.models.messages.StructuredMessage;
import com.anthropic.models.messages.StructuredMessageCreateParams;
import com.fasterxml.jackson.annotation.JsonPropertyDescription;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Component;

import java.time.Duration;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;

/**
 * Asks the configured LLM to dispatch the READY operations and returns a structured answer.
 * <p>
 * The model only <em>proposes</em>. Every pick is re-checked against the same hard constraints the algorithm uses
 * ({@link AssignmentState}); violating picks are dropped and reported as warnings. The API key comes from the
 * environment (ANTHROPIC_API_KEY) and is never stored in the database.
 */
@Component
public class LlmStrategy implements AssignmentStrategy {

    private static final Logger log = LoggerFactory.getLogger(LlmStrategy.class);
    private static final ObjectMapper JSON = new ObjectMapper();

    static final String SYSTEM_PROMPT = """
            You are the dispatcher of a small manufacturing project. You receive a JSON snapshot with the
            operations that are READY to start, the executive users, and the resources (work centres) with their
            capacity. Decide which user should perform which READY operation.

            HARD CONSTRAINTS - the system rejects any assignment that breaks one of them:
            1. Use only user ids and operation ids that appear in the snapshot.
            2. Each operation is assigned to at most one user.
            3. If an operation has fixedUserId, it can only go to that user.
            4. A resource can take at most freeSlots new operations (count your own assignments too).
            5. A user can take at most freeTaskSlots new operations (count your own assignments too).

            PROJECTS: several projects share the same resources. Every operation has a projectPriority
            (1 = most important project). The system enforces strict priority: an operation of a lower-priority
            project may only take a resource slot that no READY operation of a higher-priority project on the same
            resource is waiting for. Any pick that violates this is moved or dropped by the system.

            OBJECTIVES, in this order:
            1. Respect project priority (see above).
            2. Waste no resource time: never leave a free resource slot idle if a READY operation for it exists and
               a user can take it. Lower-priority operations may fill slots that higher-priority work does not need.
            3. Finish each project as early as possible: give priority to operations with a larger tailHours
               (the longest chain of work waiting behind them is the critical path) and an earlier plannedStartHours
               (start in the optimised plan, smaller = sooner).
            4. Balance workload: prefer the user with the lower loadHours.
            5. When loads are comparable, prefer the resource's responsibleUserId.

            Every READY operation must appear exactly once, either in "assignments" or in "unassigned" with the
            reason it cannot be started now. Keep each reason to one short plain-English sentence.
            """;

    private final AisoProperties props;
    private volatile AnthropicClient client;

    public LlmStrategy(AisoProperties props) {
        this.props = props;
    }

    @Override
    public AssignmentMode mode() {
        return AssignmentMode.LLM;
    }

    @Override
    public Suggestion suggest(AssignmentContext ctx) {
        AisoProperties.Llm cfg = props.llm();
        if (cfg.apiKey() == null || cfg.apiKey().isBlank()) {
            throw new LlmUnavailableException("ANTHROPIC_API_KEY is not configured on the server");
        }
        if (cfg.model() == null || cfg.model().isBlank()) {
            throw new LlmUnavailableException("AISO_LLM_MODEL is not set on the server");
        }
        if (ctx.ready().isEmpty()) {
            return Suggestion.of(List.of(), List.of(), "No READY operations.");
        }

        StructuredMessage<LlmOutput> response;
        try {
            StructuredMessageCreateParams<LlmOutput> params = MessageCreateParams.builder()
                    .model(cfg.model())
                    .maxTokens((long) cfg.maxTokens())
                    .system(SYSTEM_PROMPT)
                    .addUserMessage(snapshot(ctx))
                    .outputConfig(LlmOutput.class)
                    .build();
            response = client(cfg).messages().create(params);
        } catch (LlmUnavailableException e) {
            throw e;
        } catch (Exception e) {
            log.warn("LLM assignment call failed: {}", e.toString());
            throw new LlmUnavailableException("LLM API call failed: " + e.getMessage(), e);
        }

        if (response.stopReason().map(r -> r.equals(StopReason.REFUSAL)).orElse(false)) {
            throw new LlmUnavailableException("The model declined the request (stop reason: refusal)");
        }
        LlmOutput out = response.content().stream()
                .flatMap(block -> block.text().stream())
                .map(t -> t.text())
                .findFirst()
                .orElseThrow(() -> new LlmUnavailableException("The model returned no structured output"));

        return validate(ctx, out, cfg.model(), response.usage().inputTokens(), response.usage().outputTokens());
    }

    /** Re-checks the model output against the hard constraints; anything that violates them is dropped. */
    Suggestion validate(AssignmentContext ctx, LlmOutput out, String model, Long in, Long outTokens) {
        AssignmentState state = new AssignmentState(ctx);
        List<Pick> picks = new ArrayList<>();
        List<String> warnings = new ArrayList<>();
        Map<String, String> notes = new HashMap<>();
        Set<String> placed = new HashSet<>();

        Map<String, ReadyOp> readyById = new HashMap<>();
        ctx.ready().forEach(o -> readyById.put(o.id(), o));
        // higher-priority projects claim capacity first, so lower-priority picks are the ones that no longer fit
        List<LlmAssignment> proposed = new ArrayList<>(nullSafe(out.assignments()));
        proposed.sort(Comparator.comparingInt((LlmAssignment a) -> a == null || a.operationId() == null
                        || readyById.get(a.operationId()) == null ? Integer.MAX_VALUE : readyById.get(a.operationId()).projectRank())
                .thenComparingDouble(a -> a == null || a.operationId() == null || readyById.get(a.operationId()) == null
                        ? 0 : readyById.get(a.operationId()).plannedStartHours()));
        for (LlmAssignment a : proposed) {
            if (a == null || a.operationId() == null || a.userId() == null) {
                continue;
            }
            if (placed.contains(a.operationId())) {
                warnings.add("Ignored duplicate assignment of " + a.operationId());
                continue;
            }
            Optional<String> bad = state.reject(a.operationId(), a.userId());
            if (bad.isPresent()) {
                warnings.add("Dropped " + a.operationId() + " -> " + a.userId() + ": " + bad.get());
                notes.put(a.operationId(), "Model suggestion rejected: " + bad.get());
                continue;
            }
            picks.add(new Pick(a.operationId(), a.userId(), blankTo(a.reason(), "Chosen by the model")));
            state.apply(a.operationId(), a.userId());
            placed.add(a.operationId());
        }
        repairPriorityInversions(ctx, readyById, picks, placed, warnings);
        for (LlmSkip s : nullSafe(out.unassigned())) {
            if (s != null && s.operationId() != null && !placed.contains(s.operationId())) {
                notes.putIfAbsent(s.operationId(), blankTo(s.reason(), "Left unassigned by the model"));
            }
        }
        List<Skip> skips = new ArrayList<>();
        for (ReadyOp op : ctx.ready()) {
            if (!placed.contains(op.id())) {
                skips.add(new Skip(op.id(), notes.getOrDefault(op.id(), "Not addressed by the model")));
            }
        }
        String summary = blankTo(out.summary(), picks.size() + " operations proposed by the model.");
        return new Suggestion(picks, skips, summary, warnings, model, in, outTokens);
    }

    /**
     * Strict project priority: if a better-ranked READY operation was left out while a worse-ranked one holds a slot
     * on the same resource, the worse-ranked pick hands its slot (and user) to the better-ranked operation, provided
     * the whole result still satisfies every hard constraint.
     */
    private static void repairPriorityInversions(AssignmentContext ctx, Map<String, ReadyOp> readyById, List<Pick> picks,
                                                 Set<String> placed, List<String> warnings) {
        boolean changed = true;
        for (int guard = 0; changed && guard < 200; guard++) {
            changed = false;
            List<ReadyOp> left = ctx.ready().stream().filter(o -> !placed.contains(o.id()))
                    .sorted(Comparator.comparingInt(ReadyOp::projectRank).thenComparingDouble(ReadyOp::plannedStartHours)).toList();
            for (ReadyOp y : left) {
                for (Pick z : new ArrayList<>(picks)) {
                    ReadyOp zo = readyById.get(z.operationId());
                    if (zo == null || !zo.resourceId().equals(y.resourceId()) || zo.projectRank() <= y.projectRank()) {
                        continue;
                    }
                    if (y.fixedUserId() != null && !y.fixedUserId().equals(z.userId())) {
                        continue;
                    }
                    List<Pick> trial = new ArrayList<>(picks);
                    trial.remove(z);
                    trial.add(new Pick(y.id(), z.userId(), "Given this slot by the project-priority rule"));
                    if (replay(ctx, trial)) {
                        picks.clear();
                        picks.addAll(trial);
                        placed.remove(z.operationId());
                        placed.add(y.id());
                        warnings.add("Priority rule: " + y.id() + " (project priority " + y.projectRank() + ") took the slot of "
                                + z.operationId() + " (project priority " + zo.projectRank() + ") on " + y.resourceName());
                        changed = true;
                        break;
                    }
                }
                if (changed) {
                    break;
                }
            }
        }
    }

    private static boolean replay(AssignmentContext ctx, List<Pick> picks) {
        AssignmentState state = new AssignmentState(ctx);
        for (Pick p : picks) {
            if (state.reject(p.operationId(), p.userId()).isPresent()) {
                return false;
            }
            state.apply(p.operationId(), p.userId());
        }
        return true;
    }

    private static <T> List<T> nullSafe(List<T> list) {
        return list == null ? List.of() : list;
    }

    private static String blankTo(String s, String fallback) {
        return s == null || s.isBlank() ? fallback : s.trim();
    }

    private AnthropicClient client(AisoProperties.Llm cfg) {
        AnthropicClient c = client;
        if (c == null) {
            synchronized (this) {
                if (client == null) {
                    client = AnthropicOkHttpClient.builder()
                            .apiKey(cfg.apiKey())
                            .timeout(Duration.ofSeconds(cfg.timeoutSeconds()))
                            .build();
                }
                c = client;
            }
        }
        return c;
    }

    /** Only ids, names and numbers leave the system - no contact details or credentials. */
    private String snapshot(AssignmentContext ctx) {
        AssignmentState state = new AssignmentState(ctx);
        Map<String, Object> root = new LinkedHashMap<>();
        root.put("maxActiveTasksPerUser", ctx.maxActiveTasksPerUser());
        root.put("readyOperations", ctx.ready().stream().map(o -> {
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("id", o.id());
            m.put("name", o.name());
            m.put("resourceId", o.resourceId());
            m.put("hours", round(o.hours()));
            m.put("tailHours", round(o.tailHours()));
            m.put("projectId", o.projectId());
            m.put("projectName", o.projectName());
            m.put("projectPriority", o.projectRank());
            m.put("plannedStartHours", round(o.plannedStartHours()));
            m.put("fixedUserId", o.fixedUserId());
            return m;
        }).toList());
        root.put("users", ctx.users().stream().map(u -> {
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("id", u.id());
            m.put("name", u.fullName());
            m.put("active", u.active());
            m.put("activeTasks", u.activeTasks());
            m.put("freeTaskSlots", Math.max(0, ctx.maxActiveTasksPerUser() - u.activeTasks()));
            m.put("loadHours", round(u.loadHours()));
            return m;
        }).toList());
        root.put("resources", ctx.resources().stream().map(r -> {
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("id", r.id());
            m.put("name", r.name());
            m.put("capacity", r.capacity());
            m.put("busy", r.used());
            m.put("freeSlots", Math.max(0, state.freeSlots(r.id())));
            m.put("responsibleUserId", r.responsibleUserId());
            m.put("active", r.active());
            return m;
        }).toList());
        try {
            return "Snapshot:\n" + JSON.writerWithDefaultPrettyPrinter().writeValueAsString(root);
        } catch (Exception e) {
            throw new LlmUnavailableException("Could not serialise the snapshot", e);
        }
    }

    private static double round(double v) {
        return Math.round(v * 10.0) / 10.0;
    }

    // ---- structured output schema ------------------------------------------------------------------------------

    public record LlmOutput(
            @JsonPropertyDescription("Operations to assign now, each at most once") List<LlmAssignment> assignments,
            @JsonPropertyDescription("READY operations that should not be started now, with the reason") List<LlmSkip> unassigned,
            @JsonPropertyDescription("One or two sentences describing the dispatching decision") String summary) {
    }

    public record LlmAssignment(
            @JsonPropertyDescription("Id of a READY operation from the snapshot") String operationId,
            @JsonPropertyDescription("Id of the user from the snapshot") String userId,
            @JsonPropertyDescription("One short sentence explaining the choice") String reason) {
    }

    public record LlmSkip(
            @JsonPropertyDescription("Id of a READY operation from the snapshot") String operationId,
            @JsonPropertyDescription("Why it should wait") String reason) {
    }
}
