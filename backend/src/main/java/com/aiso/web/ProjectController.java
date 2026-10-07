package com.aiso.web;

import com.aiso.domain.ProjectStatus;
import com.aiso.security.CurrentUser;
import com.aiso.service.ProjectService;
import com.aiso.service.Scheduling.Impact;
import com.aiso.service.Scheduling.Overview;
import com.aiso.service.Scheduling.ProjectSummary;
import com.aiso.service.SchedulingService;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotEmpty;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PatchMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.Map;

/**
 * Projects scheduled together on shared resources. A project is created by importing an Excel file
 * (see {@code POST /api/import}); here they are ranked, previewed, renamed, given due dates and archived.
 */
@RestController
@RequestMapping("/api")
@PreAuthorize(Access.MANAGEMENT)
public class ProjectController {

    /** @param ranking all active project ids, first = highest priority; @param dueDates optional id -> date (empty clears) */
    public record RankingRequest(@NotEmpty List<String> ranking, Map<String, String> dueDates) {
    }

    public record PreviewRequest(@NotEmpty List<String> ranking) {
    }

    public record ProjectUpdate(String name, String dueDate, Boolean clearDueDate, ProjectStatus status) {
    }

    private final ProjectService projects;
    private final SchedulingService scheduling;

    public ProjectController(ProjectService projects, SchedulingService scheduling) {
        this.projects = projects;
        this.scheduling = scheduling;
    }

    @GetMapping("/projects")
    public List<ProjectSummary> list() {
        return projects.list();
    }

    /** Applies a new priority order of the active projects. The schedule is recomputed from it immediately. */
    @PutMapping("/projects/priorities")
    public List<ProjectSummary> priorities(Authentication auth, @Valid @RequestBody RankingRequest req) {
        return projects.setPriorities(req.ranking(), req.dueDates(), CurrentUser.from(auth));
    }

    @PatchMapping("/projects/{id}")
    public List<ProjectSummary> update(Authentication auth, @PathVariable String id, @RequestBody ProjectUpdate req) {
        return projects.update(id, req.name(), req.dueDate(), Boolean.TRUE.equals(req.clearDueDate()), req.status(), CurrentUser.from(auth));
    }

    /** What would change if the active projects were ranked this way? Nothing is stored. */
    @PostMapping("/scheduling/preview")
    public Impact preview(@Valid @RequestBody PreviewRequest req) {
        return scheduling.previewRanking(req.ranking());
    }

    /** Current optimised schedule metrics (finish per project, wasted resource time) next to the naive baseline. */
    @GetMapping("/scheduling/overview")
    public Overview overview() {
        return scheduling.overview();
    }
}
