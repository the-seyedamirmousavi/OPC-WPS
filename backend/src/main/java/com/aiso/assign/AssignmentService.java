package com.aiso.assign;

import com.aiso.assign.AssignmentContext.ReadyOp;
import com.aiso.assign.AssignmentContext.ResourceInfo;
import com.aiso.assign.AssignmentContext.UserInfo;
import com.aiso.config.AisoProperties;
import com.aiso.domain.AppUser;
import com.aiso.domain.AssignmentMode;
import com.aiso.domain.AssignmentProposal;
import com.aiso.domain.AssignmentRun;
import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.ProposalStatus;
import com.aiso.domain.WorkResource;
import com.aiso.repo.AssignmentProposalRepository;
import com.aiso.repo.AssignmentRunRepository;
import com.aiso.security.CurrentUser;
import com.aiso.service.AuditService;
import com.aiso.service.LanguageService;
import com.aiso.service.OperationService;
import com.aiso.service.PlanService;
import com.aiso.service.ProjectData;
import com.aiso.service.ProjectDataService;
import com.aiso.service.Views.ProposalView;
import com.aiso.service.Views.RunView;
import com.aiso.service.Views.SkippedView;
import com.aiso.web.ApiException;
import org.springframework.data.domain.PageRequest;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.transaction.support.TransactionTemplate;

import java.time.Instant;
import java.util.ArrayList;
import java.util.EnumMap;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

/**
 * Orchestrates task assignment. The active {@link AssignmentMode} picks the strategy; the result is always a set of
 * PENDING proposals that a manager reviews and approves - nothing is assigned automatically.
 */
@Service
public class AssignmentService {

    private final Map<AssignmentMode, AssignmentStrategy> strategies = new EnumMap<>(AssignmentMode.class);
    private final ProjectDataService dataService;
    private final PlanService planService;
    private final OperationService operationService;
    private final AssignmentRunRepository runs;
    private final AssignmentProposalRepository proposals;
    private final AuditService audit;
    private final AisoProperties props;
    private final TransactionTemplate tx;
    private final LanguageService lang;

    public AssignmentService(List<AssignmentStrategy> all, ProjectDataService dataService, PlanService planService,
                             OperationService operationService, AssignmentRunRepository runs,
                             AssignmentProposalRepository proposals, AuditService audit, AisoProperties props,
                             TransactionTemplate tx, LanguageService lang) {
        all.forEach(s -> strategies.put(s.mode(), s));
        this.dataService = dataService;
        this.planService = planService;
        this.operationService = operationService;
        this.runs = runs;
        this.proposals = proposals;
        this.audit = audit;
        this.props = props;
        this.tx = tx;
        this.lang = lang;
    }

    /**
     * Generates proposals with the requested mode (or the configured default). Deliberately not transactional:
     * the LLM call can take a while and must not hold a database connection.
     */
    public RunView suggest(AssignmentMode requested, CurrentUser actor) {
        ProjectData data = dataService.load();
        AssignmentMode mode = requested != null ? requested : data.settings().getAssignmentMode();
        PlanService.PlanView plan = planService.plan(data);
        AssignmentContext ctx = buildContext(data, plan);

        long started = System.nanoTime();
        AssignmentMode effective = mode;
        boolean fallback = false;
        List<String> warnings = new ArrayList<>();
        Suggestion suggestion;
        if (mode == AssignmentMode.LLM) {
            try {
                suggestion = strategies.get(AssignmentMode.LLM).suggest(ctx);
            } catch (LlmUnavailableException e) {
                if (!props.llm().fallbackToAlgorithm()) {
                    throw new ApiException(HttpStatus.BAD_GATEWAY, "LLM assignment failed: " + e.getMessage());
                }
                warnings.add("LLM mode failed (" + e.getMessage() + "). The algorithm produced these proposals instead.");
                suggestion = strategies.get(AssignmentMode.ALGORITHM).suggest(ctx);
                effective = AssignmentMode.ALGORITHM;
                fallback = true;
            }
        } else {
            suggestion = strategies.get(AssignmentMode.ALGORITHM).suggest(ctx);
        }
        warnings.addAll(suggestion.warnings());
        long elapsed = (System.nanoTime() - started) / 1_000_000;

        final AssignmentMode effectiveMode = effective;
        final boolean usedFallback = fallback;
        final Suggestion result = suggestion;
        return tx.execute(status -> persist(data, plan, mode, effectiveMode, usedFallback, result, warnings, elapsed, actor));
    }

    private RunView persist(ProjectData data, PlanService.PlanView plan, AssignmentMode requested,
                            AssignmentMode effective, boolean fallback, Suggestion s, List<String> warnings,
                            long elapsed, CurrentUser actor) {
        for (AssignmentProposal old : proposals.findByStatus(ProposalStatus.PENDING)) {
            old.setStatus(ProposalStatus.SUPERSEDED);
            old.setDecidedAt(Instant.now());
            proposals.save(old);
        }
        AssignmentRun run = new AssignmentRun();
        run.setId(UUID.randomUUID().toString());
        run.setRequestedMode(requested);
        run.setEffectiveMode(effective);
        run.setFallback(fallback);
        run.setModel(effective == AssignmentMode.LLM ? s.model() : null);
        run.setSummary(clip(lang.forStorage(s.summary()), 2000));
        run.setWarnings(clip(String.join("\n", warnings.stream().map(lang::forStorage).toList()), 4000));
        run.setUnassigned(clip(String.join("\n", s.skips().stream().map(k -> k.operationId() + "|" + lang.forStorage(k.reason())).toList()), 4000));
        run.setRequestedBy(actor.id());
        run.setDurationMs(elapsed);
        run.setInputTokens(s.inputTokens());
        run.setOutputTokens(s.outputTokens());
        runs.save(run);

        for (Suggestion.Pick p : s.picks()) {
            AssignmentProposal ap = new AssignmentProposal();
            ap.setRunId(run.getId());
            ap.setOperationId(p.operationId());
            ap.setUserId(p.userId());
            ap.setSource(effective);
            ap.setReason(clip(lang.forStorage(p.reason()), 2000));
            ap.setPlannedStart(plan.start(p.operationId()));
            ap.setPlannedEnd(plan.end(p.operationId()));
            proposals.save(ap);
        }
        audit.record(actor.id(), "ASSIGNMENT_SUGGESTED", "AssignmentRun", run.getId(), requested, effective,
                s.picks().size() + " proposals" + (fallback ? " (LLM fallback to algorithm)" : ""));
        return runView(run, proposals.findByRunId(run.getId()), data);
    }

    /** Latest run with its proposals, or null if none yet. */
    public RunView latest() {
        return tx.execute(status -> {
            List<AssignmentRun> latest = runs.findAllByOrderByCreatedAtDesc(PageRequest.of(0, 1));
            if (latest.isEmpty()) {
                return null;
            }
            AssignmentRun run = latest.get(0);
            return runView(run, proposals.findByRunId(run.getId()), dataService.load());
        });
    }

    public List<RunView> history(int limit) {
        return tx.execute(status -> {
            ProjectData data = dataService.load();
            return runs.findAllByOrderByCreatedAtDesc(PageRequest.of(0, Math.min(limit, 50))).stream()
                    .map(r -> runView(r, proposals.findByRunId(r.getId()), data)).toList();
        });
    }

    /** Applies PENDING proposals after re-checking them against the current state of the project. */
    public List<ProposalView> approve(List<Long> ids, CurrentUser actor) {
        return tx.execute(status -> {
            ProjectData data = dataService.load();
            PlanService.PlanView plan = planService.plan(data);
            AssignmentState state = new AssignmentState(buildContext(data, plan));
            List<ProposalView> out = new ArrayList<>();
            for (AssignmentProposal p : proposals.findAllById(ids)) {
                if (p.getStatus() != ProposalStatus.PENDING) {
                    continue;
                }
                Operation op = data.ops().get(p.getOperationId());
                Optional<String> problem = op == null || op.getStatus() != OperationStatus.READY
                        ? Optional.of("Operation is no longer READY")
                        : state.reject(p.getOperationId(), p.getUserId());
                if (problem.isPresent()) {
                    decide(p, ProposalStatus.REJECTED, actor, "Not applied: " + problem.get());
                } else {
                    operationService.assign(p.getOperationId(), p.getUserId(), p.getPlannedStart(), p.getPlannedEnd(),
                            actor.id(), "Approved " + p.getSource() + " proposal: " + p.getReason());
                    state.apply(p.getOperationId(), p.getUserId());
                    decide(p, ProposalStatus.APPROVED, actor, null);
                }
                out.add(view(p, data, plan));
            }
            return out;
        });
    }

    public List<ProposalView> reject(List<Long> ids, String note, CurrentUser actor) {
        return tx.execute(status -> {
            ProjectData data = dataService.load();
            PlanService.PlanView plan = planService.plan(data);
            List<ProposalView> out = new ArrayList<>();
            for (AssignmentProposal p : proposals.findAllById(ids)) {
                if (p.getStatus() == ProposalStatus.PENDING) {
                    decide(p, ProposalStatus.REJECTED, actor, note == null || note.isBlank() ? "Rejected by manager" : note);
                    out.add(view(p, data, plan));
                }
            }
            return out;
        });
    }

    /** Manager override: assign a specific user regardless of what was proposed. */
    public void manualAssign(String operationId, String userId, String note, CurrentUser actor) {
        tx.executeWithoutResult(status -> {
            ProjectData data = dataService.load();
            PlanService.PlanView plan = planService.plan(data);
            operationService.assign(operationId, userId, plan.start(operationId), plan.end(operationId), actor.id(),
                    "Manual assignment" + (note == null || note.isBlank() ? "" : ": " + note));
            for (AssignmentProposal p : proposals.findByStatus(ProposalStatus.PENDING)) {
                if (p.getOperationId().equals(operationId)) {
                    decide(p, ProposalStatus.SUPERSEDED, actor, "Manually assigned");
                }
            }
        });
    }

    // ---- context & views --------------------------------------------------------------------------------------

    AssignmentContext buildContext(ProjectData data, PlanService.PlanView plan) {
        Map<String, Integer> used = new HashMap<>();
        Map<String, Integer> tasks = new HashMap<>();
        Map<String, Double> load = new HashMap<>();
        for (Operation o : data.ops().values()) {
            if (o.getStatus().isActive()) {
                used.merge(o.getResourceId(), 1, Integer::sum);
                if (o.getAssignedUserId() != null) {
                    tasks.merge(o.getAssignedUserId(), 1, Integer::sum);
                    double remaining = o.totalHours() * (1 - o.getProgressPercent() / 100.0);
                    load.merge(o.getAssignedUserId(), remaining, Double::sum);
                }
            }
        }
        List<ReadyOp> ready = new ArrayList<>();
        for (Operation o : data.ops().values()) {
            if (o.getStatus() == OperationStatus.READY) {
                ready.add(new ReadyOp(o.getId(), o.getName(),
                        o.getItemId() == null || data.items().get(o.getItemId()) == null ? null : data.items().get(o.getItemId()).getName(),
                        o.getResourceId(), data.resourceName(o.getResourceId()), o.totalHours(),
                        plan.tailHours().getOrDefault(o.getId(), o.totalHours()), o.getResponsibleUserId(),
                        o.getProjectId(), data.projectName(o.getProjectId()), data.rankOf(o), plan.startHours(o.getId())));
            }
        }
        List<UserInfo> users = data.users().values().stream()
                .filter(u -> u.getRole().isExecutor())
                .map(u -> new UserInfo(u.getId(), u.getFullName(), u.isActive(), tasks.getOrDefault(u.getId(), 0),
                        load.getOrDefault(u.getId(), 0.0)))
                .toList();
        List<ResourceInfo> resources = data.resources().values().stream()
                .map((WorkResource r) -> new ResourceInfo(r.getId(), r.getName(), r.getCapacity(),
                        used.getOrDefault(r.getId(), 0), r.getResponsibleUserId(), r.isActive()))
                .toList();
        return new AssignmentContext(ready, users, resources, data.settings().getMaxActiveTasksPerUser(),
                props.assignment().responsibleBonusHours());
    }

    private RunView runView(AssignmentRun run, List<AssignmentProposal> list, ProjectData data) {
        PlanService.PlanView plan = planService.plan(data);
        List<ProposalView> pv = list.stream().map(p -> view(p, data, plan)).toList();
        List<SkippedView> skipped = new ArrayList<>();
        if (run.getUnassigned() != null && !run.getUnassigned().isBlank()) {
            for (String line : run.getUnassigned().split("\n")) {
                int i = line.indexOf('|');
                if (i > 0) {
                    String id = line.substring(0, i);
                    Operation o = data.ops().get(id);
                    skipped.add(new SkippedView(id, o == null ? id : o.getName(), line.substring(i + 1)));
                }
            }
        }
        List<String> warnings = run.getWarnings() == null || run.getWarnings().isBlank()
                ? List.of() : List.of(run.getWarnings().split("\n"));
        return new RunView(run.getId(), run.getRequestedMode(), run.getEffectiveMode(), run.isFallback(), run.getModel(),
                run.getSummary(), warnings, skipped, pv, run.getCreatedAt(), run.getDurationMs(),
                run.getInputTokens(), run.getOutputTokens(), run.getRequestedBy());
    }

    private ProposalView view(AssignmentProposal p, ProjectData data, PlanService.PlanView plan) {
        Operation o = data.ops().get(p.getOperationId());
        AppUser u = data.users().get(p.getUserId());
        return new ProposalView(p.getId(), p.getRunId(), p.getOperationId(), o == null ? null : o.getName(),
                o == null ? null : data.projectName(o.getProjectId()), o == null ? 0 : data.rankOf(o),
                o == null ? null : data.resourceName(o.getResourceId()), p.getUserId(),
                u == null ? p.getUserId() : u.getFullName(), p.getSource(), p.getReason(), p.getPlannedStart(),
                p.getPlannedEnd(), p.getStatus(), p.getDecisionNote(), o == null ? 0 : o.totalHours(),
                plan.tailHours().getOrDefault(p.getOperationId(), 0.0));
    }

    private void decide(AssignmentProposal p, ProposalStatus status, CurrentUser actor, String note) {
        p.setStatus(status);
        p.setDecidedAt(Instant.now());
        p.setDecidedBy(actor.id());
        p.setDecisionNote(clip(note, 500));
        proposals.save(p);
    }

    private static String clip(String s, int max) {
        if (s == null) {
            return null;
        }
        return s.length() <= max ? s : s.substring(0, max - 3) + "...";
    }
}
