package com.aiso.service;

import com.aiso.domain.Operation;
import com.aiso.domain.Project;
import com.aiso.domain.ProjectStatus;
import com.aiso.repo.ProjectRepository;
import com.aiso.security.CurrentUser;
import com.aiso.service.Scheduling.ProjectSummary;
import com.aiso.web.ApiException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.stream.Collectors;

/** Project list, priorities and lifecycle. Priorities are a strict ranking 1..n of the active projects. */
@Service
@Transactional
public class ProjectService {

    private final ProjectRepository projects;
    private final ProjectDataService dataService;
    private final PlanService planService;
    private final SchedulingService scheduling;
    private final AuditService audit;

    public ProjectService(ProjectRepository projects, ProjectDataService dataService, PlanService planService,
                          SchedulingService scheduling, AuditService audit) {
        this.projects = projects;
        this.dataService = dataService;
        this.planService = planService;
        this.scheduling = scheduling;
        this.audit = audit;
    }

    @Transactional(readOnly = true)
    public List<ProjectSummary> list() {
        ProjectData data = dataService.load();
        return scheduling.summaries(data, planService.plan(data));
    }

    /**
     * Applies a complete ranking of the active projects (first = highest priority) and optional due dates.
     *
     * @param dueDates project id -> ISO date or date-time; an empty value clears the due date; absent = unchanged
     */
    public List<ProjectSummary> setPriorities(List<String> ranking, Map<String, String> dueDates, CurrentUser actor) {
        List<Project> active = projects.findByStatusOrderByPriorityAscIdAsc(ProjectStatus.ACTIVE);
        Map<String, Integer> ranks = SchedulingService.ranks(ranking, active.stream().map(Project::getId).toList());
        String before = describe(active);
        for (Project p : active) {
            p.setPriority(ranks.get(p.getId()));
        }
        if (dueDates != null) {
            for (Project p : active) {
                if (dueDates.containsKey(p.getId())) {
                    p.setDueDate(parseDue(dueDates.get(p.getId())));
                }
            }
        }
        projects.saveAll(active);
        audit.record(actor.id(), "PROJECT_PRIORITIES_CHANGED", "Project", "*", before,
                describe(active.stream().sorted(Comparator.comparingInt(Project::getPriority)).toList()), null);
        return list();
    }

    public List<ProjectSummary> update(String id, String name, String dueDate, boolean clearDueDate, ProjectStatus status,
                                       CurrentUser actor) {
        Project p = projects.findById(id).orElseThrow(() -> ApiException.notFound("Project " + id + " not found"));
        String before = p.getName() + "/" + p.getStatus() + "/" + p.getDueDate();
        if (name != null && !name.isBlank()) {
            p.setName(name.trim());
        }
        if (clearDueDate) {
            p.setDueDate(null);
        } else if (dueDate != null) {
            p.setDueDate(parseDue(dueDate));
        }
        if (status != null && status != p.getStatus()) {
            if (status == ProjectStatus.ARCHIVED) {
                long open = dataService.load().ops().values().stream()
                        .filter((Operation o) -> id.equals(o.getProjectId()) && !o.getStatus().isTerminal()).count();
                if (open > 0) {
                    throw ApiException.conflict("Project " + id + " still has " + open
                            + " unfinished operation(s). Complete or cancel them first.");
                }
                p.setStatus(ProjectStatus.ARCHIVED);
            } else {
                int last = projects.findByStatusOrderByPriorityAscIdAsc(ProjectStatus.ACTIVE).size();
                p.setStatus(ProjectStatus.ACTIVE);
                p.setPriority(last + 1);
            }
        }
        projects.save(p);
        renumber();
        audit.record(actor.id(), "PROJECT_UPDATED", "Project", id, before, p.getName() + "/" + p.getStatus() + "/" + p.getDueDate(), null);
        return list();
    }

    /** Keeps the active ranks gap-free (1..n) after an archive. */
    public void renumber() {
        List<Project> active = projects.findByStatusOrderByPriorityAscIdAsc(ProjectStatus.ACTIVE);
        for (int i = 0; i < active.size(); i++) {
            active.get(i).setPriority(i + 1);
        }
        projects.saveAll(active);
    }

    public static Instant parseDue(String text) {
        if (text == null || text.isBlank()) {
            return null;
        }
        String t = text.trim();
        try {
            LocalDate jalali = Jalali.parse(t); // 1405/07/25, also with Persian digits
            if (jalali != null) {
                return endOfDay(jalali);
            }
            if (t.length() <= 10) {
                return endOfDay(LocalDate.parse(t));
            }
            return Instant.parse(t);
        } catch (DateTimeParseException e) {
            throw ApiException.badRequest("Due date must be YYYY-MM-DD, a Jalali date like 1405/07/25, or an ISO instant, got '" + text + "'");
        }
    }

    /** End of the given calendar day in Tehran, so a due date "on the 25th" includes the 25th. */
    private static Instant endOfDay(LocalDate d) {
        return d.plusDays(1).atStartOfDay(Jalali.TEHRAN).minusSeconds(1).toInstant();
    }

    private static String describe(List<Project> list) {
        return new ArrayList<>(list).stream().map(p -> p.getId() + "=" + p.getPriority()).collect(Collectors.joining(", "));
    }
}
