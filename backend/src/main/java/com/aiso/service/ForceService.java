package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.PlanBaseline;
import com.aiso.domain.Project;
import com.aiso.domain.ScheduleForce;
import com.aiso.domain.WorkResource;
import com.aiso.repo.PlanBaselineRepository;
import com.aiso.repo.ScheduleForceRepository;
import com.aiso.security.CurrentUser;
import com.aiso.service.Forces.Board;
import com.aiso.service.Forces.Finding;
import com.aiso.service.Forces.ForceView;
import com.aiso.service.Forces.Impact;
import com.aiso.service.Forces.MovedOp;
import com.aiso.service.Forces.ProjectShift;
import com.aiso.service.PlanOptimizer.Conflict;
import com.aiso.service.PlanService.PlanView;
import com.aiso.web.ApiException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.DateTimeException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.LocalTime;
import java.time.OffsetDateTime;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.TreeMap;
import java.util.TreeSet;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Manager FORCE: "this operation starts at this time" and/or "this operation is done by this user".
 * <p>
 * A FORCE is checked before it is stored: against the absolute rules (finished or running work, inactive resource,
 * invalid user) and by planning the whole schedule with it ({@link PlanOptimizer}). It is refused when it is
 * impossible or would make another FORCE impossible; the reason names the operations involved. An accepted FORCE is
 * stored, an already assigned operation gets its new executor / planned time, and the resulting plan is saved as the
 * reference plan so later re-planning stays close to it.
 */
@Service
public class ForceService {

    /** @param start forced start (Tehran time "2026-10-08 14:30", Jalali "1405/07/16 14:30" or ISO instant), optional */
    public record Request(String start, String userId, String reason) {
    }

    private static final DateTimeFormatter TEHRAN_TIME = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm").withZone(Jalali.TEHRAN);
    private static final Pattern DATE_TIME = Pattern.compile("^(\\d{4}[/\\-.]\\d{1,2}[/\\-.]\\d{1,2})[ T](\\d{1,2}):(\\d{2})$");
    private static final int MAX_MOVED = 100;

    private final ProjectDataService dataService;
    private final PlanService planService;
    private final ScheduleForceRepository forces;
    private final PlanBaselineRepository baseline;
    private final OperationService operationService;
    private final AuditService audit;
    private final LanguageService lang;

    public ForceService(ProjectDataService dataService, PlanService planService, ScheduleForceRepository forces,
                        PlanBaselineRepository baseline, OperationService operationService, AuditService audit,
                        LanguageService lang) {
        this.dataService = dataService;
        this.planService = planService;
        this.forces = forces;
        this.baseline = baseline;
        this.operationService = operationService;
        this.audit = audit;
        this.lang = lang;
    }

    // ---- queries ------------------------------------------------------------------------------------------------

    @Transactional
    public Board board() {
        ProjectData data = dataService.load();
        PlanView plan = planService.plan(data);
        Map<String, Conflict> conflicts = new HashMap<>();
        plan.conflicts().forEach(c -> conflicts.put(c.operationId(), c));
        List<ForceView> views = new ArrayList<>();
        List<Finding> problems = new ArrayList<>();
        List<ScheduleForce> list = new ArrayList<>(data.forces().values());
        list.sort(Comparator.comparing(ScheduleForce::getForcedStart, Comparator.nullsLast(Comparator.naturalOrder()))
                .thenComparing(ScheduleForce::getOperationId));
        for (ScheduleForce f : list) {
            Operation op = data.ops().get(f.getOperationId());
            if (op == null) {
                continue;
            }
            Finding problem = null;
            String state = stateOf(f, op, plan, data);
            if ("NOT_HONOURED".equals(state)) {
                Conflict c = conflicts.get(op.getId());
                problem = c != null ? finding(c, data, plan)
                        : new Finding("USER_NOT_ALLOWED", op.getId(), true, List.of(), lang.forRequest(
                        "The forced executor " + f.getForcedUserId() + " is no longer an active executive user"));
                problems.add(problem);
            }
            views.add(new ForceView(op.getId(), op.getName(), op.getProjectId(), data.projectName(op.getProjectId()),
                    data.rankOf(op), data.resourceName(op.getResourceId()), op.getStatus(), f.getForcedStart(),
                    f.getForcedUserId(), data.userName(f.getForcedUserId()), f.getReason(), f.getCreatedBy(),
                    f.getCreatedAt(), state, plan.start(op.getId()), plan.end(op.getId()), problem));
        }
        Instant savedAt = data.baseline().values().stream().map(PlanBaseline::getSavedAt).max(Comparator.naturalOrder()).orElse(null);
        return new Board(views, problems, plan.escalated().size(), savedAt);
    }

    /** HONOURED, NOT_HONOURED or DONE (see {@link ForceView}); null without a FORCE. */
    public static String stateOf(ScheduleForce f, Operation op, PlanView plan, ProjectData data) {
        if (f == null) {
            return null;
        }
        if (op.getStatus().isTerminal() || op.getStatus() == OperationStatus.IN_PROGRESS) {
            return "DONE";
        }
        boolean startMissed = f.getForcedStart() != null && !plan.honoured().contains(op.getId());
        AppUser u = f.getForcedUserId() == null ? null : data.users().get(f.getForcedUserId());
        boolean userInvalid = f.getForcedUserId() != null && (u == null || !u.isActive() || !u.getRole().isExecutor());
        return startMissed || userInvalid ? "NOT_HONOURED" : "HONOURED";
    }

    /** What the FORCE would change. Nothing is stored. */
    @Transactional
    public Impact preview(String operationId, Request req) {
        return impact(dataService.load(), operationId, req, parseStart(req.start()));
    }

    // ---- commands -----------------------------------------------------------------------------------------------

    /** Creates or replaces the FORCE on the operation; refused (409) when it is impossible. */
    @Transactional
    public Board apply(String operationId, Request req, CurrentUser actor) {
        ProjectData data = dataService.load();
        Instant start = parseStart(req.start());
        Impact impact = impact(data, operationId, req, start);
        if (!impact.feasible()) {
            throw ApiException.conflict("FORCE rejected: " + impact.conflicts().get(0).message());
        }
        String userId = blank(req.userId()) ? null : req.userId().trim();
        String reason = blank(req.reason()) ? null : req.reason().trim();
        ScheduleForce f = forces.findById(operationId).orElse(null);
        String before = describe(f);
        if (f == null) {
            f = new ScheduleForce();
            f.setOperationId(operationId);
        }
        f.setForcedStart(start);
        f.setForcedUserId(userId);
        f.setReason(reason);
        f.setCreatedBy(actor.id());
        f.setCreatedAt(Instant.now());
        forces.save(f);

        Operation op = data.ops().get(operationId);
        String note = "FORCE: " + (reason == null ? "" : reason);
        if (op.getStatus() == OperationStatus.ASSIGNED) {
            if (userId != null && !userId.equals(op.getAssignedUserId())) {
                operationService.assign(operationId, userId, impact.plannedStart(), impact.plannedEnd(), actor.id(), note);
            } else if (start != null) {
                operationService.reschedule(operationId, impact.plannedStart(), impact.plannedEnd(), actor.id(), note);
            }
        }
        audit.record(actor.id(), "FORCE_SET", "Operation", operationId, before, describe(f), reason);
        saveBaseline(planService.plan(dataService.load()));
        return board();
    }

    @Transactional
    public Board remove(String operationId, CurrentUser actor) {
        ScheduleForce f = forces.findById(operationId)
                .orElseThrow(() -> ApiException.notFound("No FORCE on operation " + operationId));
        String before = describe(f);
        forces.delete(f);
        forces.flush();
        audit.record(actor.id(), "FORCE_REMOVED", "Operation", operationId, before, null, null);
        saveBaseline(planService.plan(dataService.load()));
        return board();
    }

    /**
     * Re-optimises from scratch: FORCEs are still honoured, but the schedule no longer tries to stay close to the
     * previous plan. The result becomes the new reference plan.
     */
    @Transactional
    public Board reoptimize(CurrentUser actor) {
        PlanView fresh = planService.planFresh(dataService.load());
        saveBaseline(fresh);
        audit.record(actor.id(), "PLAN_REOPTIMIZED", "Plan", "*", null, fresh.slots().size() + " operations", null);
        return board();
    }

    // ---- impact -------------------------------------------------------------------------------------------------

    private Impact impact(ProjectData data, String operationId, Request req, Instant start) {
        Operation op = data.ops().get(operationId);
        if (op == null) {
            throw ApiException.notFound("Operation " + operationId + " not found");
        }
        String userId = blank(req.userId()) ? null : req.userId().trim();
        if (start == null && userId == null) {
            throw ApiException.badRequest("Give a forced start, a forced executor or both");
        }
        List<Finding> conflicts = new ArrayList<>(absolute(data, op, start, userId));

        PlanView before = planService.plan(data);
        PlanView after = before;
        if (conflicts.isEmpty()) {
            ScheduleForce f = new ScheduleForce();
            f.setOperationId(operationId);
            f.setForcedStart(start);
            f.setForcedUserId(userId);
            f.setCreatedAt(Instant.now()); // the youngest FORCE: it is the one given up in a contradiction
            Map<String, ScheduleForce> next = new TreeMap<>(data.forces());
            next.put(operationId, f);
            after = planService.plan(data.withForces(next));
            Set<String> brokenBefore = new HashSet<>();
            before.conflicts().forEach(c -> brokenBefore.add(c.operationId()));
            for (Conflict c : after.conflicts()) {
                if (c.operationId().equals(operationId)) {
                    conflicts.add(0, finding(c, data, after));
                } else if (!brokenBefore.contains(c.operationId())) {
                    conflicts.add(new Finding("BREAKS_FORCE", c.operationId(), c.proven(), c.related(), lang.forRequest(
                            "It would make the FORCE on " + c.operationId() + " impossible: " + englishMessage(c, data, after))));
                }
            }
        }

        List<Finding> warnings = new ArrayList<>();
        if (userId != null) {
            if (op.getResponsibleUserId() != null && !op.getResponsibleUserId().equals(userId)) {
                warnings.add(warning("OPC_EXECUTOR_OVERRIDDEN", operationId,
                        "Overrides the executor fixed in the OPC sheet (" + op.getResponsibleUserId() + ")"));
            }
            long active = data.ops().values().stream()
                    .filter(o -> o.getStatus().isActive() && userId.equals(o.getAssignedUserId()) && !o.getId().equals(operationId))
                    .count();
            int limit = data.settings().getMaxActiveTasksPerUser();
            if (active >= limit) {
                warnings.add(warning("OVER_TASK_LIMIT", operationId, "User " + userId + " already has " + active
                        + " active tasks (limit " + limit + "); the FORCE overrides the limit"));
            }
            if (op.getStatus() == OperationStatus.ASSIGNED && op.getAssignedUserId() != null && !userId.equals(op.getAssignedUserId())) {
                warnings.add(warning("REASSIGNS", operationId, "Operation " + operationId + " is assigned to "
                        + op.getAssignedUserId() + "; it will be reassigned to " + userId));
            }
        }

        // what moves
        List<MovedOp> moved = new ArrayList<>();
        int committed = 0;
        double total = 0;
        for (Map.Entry<String, SchedulePlanner.Slot> e : after.slots().entrySet()) {
            SchedulePlanner.Slot b = before.slots().get(e.getKey());
            Operation o = data.ops().get(e.getKey());
            if (b == null || o == null || o.getId().equals(operationId)) {
                continue;
            }
            double d = e.getValue().start() - b.start();
            if (Math.abs(d) > PlanOptimizer.MOVE_EPS) {
                boolean isCommitted = o.getStatus() == OperationStatus.ASSIGNED;
                committed += isCommitted ? 1 : 0;
                total += Math.abs(d);
                moved.add(new MovedOp(o.getId(), o.getName(), data.projectName(o.getProjectId()), isCommitted,
                        before.at(b.start()), after.at(e.getValue().start()), d));
            }
        }
        moved.sort(Comparator.comparingDouble((MovedOp m) -> -Math.abs(m.shiftHours())).thenComparing(MovedOp::operationId));
        int movedCount = moved.size();
        if (committed > 0) {
            warnings.add(warning("COMMITTED_MOVED", null, committed + " already assigned operation(s) would move"));
        }
        List<ProjectShift> projects = projectShifts(data, before, after);
        for (ProjectShift p : projects) {
            if (p.deltaHours() > PlanOptimizer.MOVE_EPS) {
                warnings.add(warning("PRIORITY_DELAYED", null, String.format(Locale.ROOT,
                        "Project %s (priority %d) would finish %.1f h later", p.name(), p.priority(), p.deltaHours())));
            }
        }
        Set<String> newlyEscalated = new TreeSet<>(after.escalated());
        newlyEscalated.removeAll(before.escalated());
        if (!newlyEscalated.isEmpty()) {
            warnings.add(new Finding("ESCALATED", operationId, false, List.copyOf(newlyEscalated), lang.forRequest(
                    newlyEscalated.size() + " operation(s) run ahead of higher-priority work to make the forced start possible")));
        }
        return new Impact(conflicts.isEmpty(), operationId, before.start(operationId), after.start(operationId),
                after.end(operationId), userId, conflicts, warnings, movedCount, committed, total,
                moved.subList(0, Math.min(MAX_MOVED, moved.size())), projects,
                PlanOptimizer.waste(before.lanes()), PlanOptimizer.waste(after.lanes()));
    }

    /** Rules no FORCE may break, decided without planning. */
    private List<Finding> absolute(ProjectData data, Operation op, Instant start, String userId) {
        List<Finding> out = new ArrayList<>();
        String id = op.getId();
        if (op.getStatus().isTerminal()) {
            out.add(new Finding("OPERATION_FINISHED", id, true, List.of(),
                    lang.forRequest("Operation " + id + " is " + op.getStatus() + " and cannot be forced")));
            return out;
        }
        if (start != null && op.getStatus() == OperationStatus.IN_PROGRESS) {
            out.add(new Finding("ALREADY_STARTED", id, true, List.of(),
                    lang.forRequest("Operation " + id + " is " + op.getStatus() + "; its start cannot be forced")));
        }
        WorkResource res = data.resources().get(op.getResourceId());
        if (start != null && (res == null || !res.isActive())) {
            out.add(new Finding("RESOURCE_INACTIVE", id, true, List.of(),
                    lang.forRequest("Resource " + data.resourceName(op.getResourceId()) + " of " + id + " is inactive")));
        }
        if (userId != null) {
            AppUser u = data.users().get(userId);
            boolean changeable = op.getStatus() == OperationStatus.NOT_READY || op.getStatus() == OperationStatus.READY
                    || op.getStatus() == OperationStatus.ASSIGNED;
            if (u == null || !u.isActive() || !u.getRole().isExecutor()) {
                out.add(new Finding("USER_NOT_ALLOWED", id, true, List.of(),
                        lang.forRequest("User " + userId + " is not an active executive user")));
            } else if (!changeable && op.getAssignedUserId() != null && !userId.equals(op.getAssignedUserId())) {
                out.add(new Finding("EXECUTOR_LOCKED", id, true, List.of(op.getAssignedUserId()), lang.forRequest(
                        "Operation " + id + " is " + op.getStatus() + " with executor " + op.getAssignedUserId()
                                + "; the executor cannot change now")));
            }
        }
        return out;
    }

    private List<ProjectShift> projectShifts(ProjectData data, PlanView before, PlanView after) {
        List<ProjectShift> out = new ArrayList<>();
        List<Project> list = new ArrayList<>(data.projects().values());
        list.sort(Comparator.comparingInt(Project::getPriority).thenComparing(Project::getId));
        for (Project p : list) {
            double b = finish(data, before, p.getId());
            double a = finish(data, after, p.getId());
            if (b < 0 && a < 0) {
                continue;
            }
            out.add(new ProjectShift(p.getId(), p.getName(), p.getPriority(), b < 0 ? null : before.at(b),
                    a < 0 ? null : after.at(a), Math.max(0, a) - Math.max(0, b)));
        }
        return out;
    }

    /** Latest planned end of the project's unfinished work in hours, or -1 when it has none. */
    private static double finish(ProjectData data, PlanView plan, String projectId) {
        double f = -1;
        for (Map.Entry<String, SchedulePlanner.Slot> e : plan.slots().entrySet()) {
            Operation o = data.ops().get(e.getKey());
            if (o != null && projectId.equals(o.getProjectId())) {
                f = Math.max(f, e.getValue().end());
            }
        }
        return f;
    }

    // ---- messages -----------------------------------------------------------------------------------------------

    private Finding finding(Conflict c, ProjectData data, PlanView plan) {
        return new Finding(c.kind().name(), c.operationId(), c.proven(), c.related(),
                lang.forRequest(englishMessage(c, data, plan)));
    }

    private Finding warning(String kind, String operationId, String message) {
        return new Finding(kind, operationId, false, List.of(), lang.forRequest(message));
    }

    static String englishMessage(Conflict c, ProjectData data, PlanView plan) {
        String id = c.operationId();
        String at = tehranTime(plan.at(c.requestedStart()));
        return switch (c.kind()) {
            case START_IN_PAST -> "Forced start " + at + " of " + id + " is in the past";
            case PRECEDENCE -> id + " cannot start at " + at + ": its predecessors (" + String.join(", ", c.related())
                    + ") allow " + tehranTime(plan.at(c.earliestStart())) + " at the earliest";
            case CAPACITY -> {
                Operation op = data.ops().get(id);
                WorkResource r = op == null ? null : data.resources().get(op.getResourceId());
                yield id + " cannot start at " + at + ": resource " + (r == null ? "?" : r.getName()) + " (capacity "
                        + (r == null ? 1 : r.getCapacity()) + ") is taken by " + String.join(", ", c.related()) + " at that time";
            }
            case BLOCKS_FORCE -> id + " cannot start at " + at + ": the FORCE on " + c.related().get(0) + " (start "
                    + tehranTime(plan.at(c.earliestStart())) + ") needs it earlier";
            case NOT_FOUND -> {
                String older = c.related().size() == 1 && data.forces().containsKey(c.related().get(0)) ? c.related().get(0) : null;
                yield older != null
                        ? "No schedule was found in which " + id + " starts at " + at + " without making the older FORCE on "
                        + older + " impossible; it is planned for " + tehranTime(plan.at(c.earliestStart())) + "."
                        : "No schedule was found in which " + id + " starts at " + at + "; it is planned for "
                        + tehranTime(plan.at(c.earliestStart())) + ". A feasible schedule may still exist.";
            }
        };
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** Replaces the reference plan with {@code plan} (updates rows in place: no delete-and-reinsert of the same ids). */
    private void saveBaseline(PlanView plan) {
        Instant now = Instant.now();
        Map<String, PlanBaseline> existing = new HashMap<>();
        baseline.findAll().forEach(b -> existing.put(b.getOperationId(), b));
        List<PlanBaseline> keep = new ArrayList<>();
        plan.slots().forEach((id, slot) -> {
            PlanBaseline b = existing.remove(id);
            if (b == null) {
                b = new PlanBaseline();
                b.setOperationId(id);
            }
            b.setPlannedStart(plan.at(slot.start()));
            b.setPlannedEnd(plan.at(slot.end()));
            b.setSavedAt(now);
            keep.add(b);
        });
        baseline.deleteAll(existing.values());
        baseline.saveAll(keep);
        baseline.flush();
    }

    private static String describe(ScheduleForce f) {
        if (f == null) {
            return null;
        }
        return (f.getForcedStart() == null ? "" : "start " + tehranTime(f.getForcedStart()))
                + (f.getForcedStart() != null && f.getForcedUserId() != null ? ", " : "")
                + (f.getForcedUserId() == null ? "" : "executor " + f.getForcedUserId());
    }

    /** "2026-10-08 14:30" in Tehran time. */
    public static String tehranTime(Instant t) {
        return t == null ? "-" : TEHRAN_TIME.format(t);
    }

    /**
     * Accepts "2026-10-08 14:30" / "2026-10-08T14:30" (Tehran time), the same with a Jalali date
     * ("1405/07/16 14:30", Persian digits allowed), or an ISO instant / offset date-time. Blank means "no forced start".
     */
    public static Instant parseStart(String text) {
        if (blank(text)) {
            return null;
        }
        String t = Jalali.latin(text.trim());
        try {
            Matcher m = DATE_TIME.matcher(t);
            if (m.matches()) {
                LocalDate d = Jalali.parse(m.group(1));
                if (d == null) {
                    d = LocalDate.parse(m.group(1).replace('/', '-').replace('.', '-'));
                }
                LocalTime time = LocalTime.of(Integer.parseInt(m.group(2)), Integer.parseInt(m.group(3)));
                return LocalDateTime.of(d, time).atZone(Jalali.TEHRAN).toInstant();
            }
            if (t.endsWith("Z")) {
                return Instant.parse(t);
            }
            return OffsetDateTime.parse(t).toInstant();
        } catch (DateTimeException | NumberFormatException e) {
            throw ApiException.badRequest("Start must be a date and time like 2026-10-08 14:30, 1405/07/16 14:30 or an ISO instant, got '" + text + "'");
        }
    }

    private static boolean blank(String s) {
        return s == null || s.isBlank();
    }
}
