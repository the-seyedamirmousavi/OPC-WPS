package com.aiso.service;

import com.aiso.config.AisoProperties;
import com.aiso.domain.AssignmentMode;
import com.aiso.domain.SystemSetting;
import com.aiso.domain.SystemStatus;
import com.aiso.repo.AssignmentProposalRepository;
import com.aiso.repo.AssignmentRunRepository;
import com.aiso.repo.ItemRepository;
import com.aiso.repo.NotificationRepository;
import com.aiso.repo.OperationReportRepository;
import com.aiso.repo.OperationRepository;
import com.aiso.repo.PredecessorRepository;
import com.aiso.repo.ProjectRepository;
import com.aiso.repo.WorkResourceRepository;
import com.aiso.security.CurrentUser;
import com.aiso.web.ApiException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

@Service
@Transactional
public class AdminService {

    public record SettingsView(String projectId, String projectName, String ownerId, String managerId,
                               String messengerPlatform, SystemStatus systemStatus, String dataVersion,
                               int configurationVersion, AssignmentMode assignmentMode, int maxActiveTasksPerUser,
                               boolean requireCompletionApproval, boolean llmAvailable, String llmModel, String language) {
    }

    private final SettingsService settings;
    private final AuditService audit;
    private final DependencyService dependencies;
    private final AisoProperties props;
    private final AssignmentProposalRepository proposals;
    private final AssignmentRunRepository runs;
    private final OperationReportRepository reports;
    private final NotificationRepository notifications;
    private final PredecessorRepository predecessors;
    private final OperationRepository operations;
    private final ItemRepository items;
    private final WorkResourceRepository resources;
    private final ProjectRepository projectRepo;

    public AdminService(SettingsService settings, AuditService audit, DependencyService dependencies, AisoProperties props,
                        AssignmentProposalRepository proposals, AssignmentRunRepository runs,
                        OperationReportRepository reports, NotificationRepository notifications,
                        PredecessorRepository predecessors, OperationRepository operations, ItemRepository items,
                        WorkResourceRepository resources, ProjectRepository projectRepo) {
        this.settings = settings;
        this.audit = audit;
        this.dependencies = dependencies;
        this.props = props;
        this.proposals = proposals;
        this.runs = runs;
        this.reports = reports;
        this.notifications = notifications;
        this.predecessors = predecessors;
        this.operations = operations;
        this.items = items;
        this.resources = resources;
        this.projectRepo = projectRepo;
    }

    @Transactional(readOnly = true)
    public SettingsView view() {
        return toView(settings.get());
    }

    public SettingsView update(String projectName, String messengerPlatform, Integer maxActiveTasks,
                               Boolean requireApproval, String language, CurrentUser actor) {
        SystemSetting s = settings.get();
        String before = summary(s);
        boolean approvalChanged = false;
        if (projectName != null && !projectName.isBlank()) s.setProjectName(projectName.trim());
        if (messengerPlatform != null && !messengerPlatform.isBlank()) s.setMessengerPlatform(messengerPlatform.trim().toUpperCase());
        if (language != null && !language.isBlank()) {
            String l = language.trim().toLowerCase();
            if (!l.equals("fa") && !l.equals("en")) {
                throw ApiException.badRequest("language must be fa or en");
            }
            s.setLanguage(l);
        }
        if (maxActiveTasks != null) {
            if (maxActiveTasks < 1 || maxActiveTasks > 50) {
                throw ApiException.badRequest("maxActiveTasksPerUser must be between 1 and 50");
            }
            s.setMaxActiveTasksPerUser(maxActiveTasks);
        }
        if (requireApproval != null && requireApproval != s.isRequireCompletionApproval()) {
            s.setRequireCompletionApproval(requireApproval);
            approvalChanged = true;
        }
        settings.saveChanged(s);
        audit.record(actor.id(), "SETTINGS_CHANGED", "Settings", "1", before, summary(s), null);
        if (approvalChanged) {
            dependencies.evaluateAll(actor.id());
        }
        return toView(s);
    }

    public SettingsView setAssignmentMode(AssignmentMode mode, CurrentUser actor) {
        SystemSetting s = settings.get();
        AssignmentMode before = s.getAssignmentMode();
        if (before != mode) {
            s.setAssignmentMode(mode);
            settings.saveChanged(s);
            audit.record(actor.id(), "ASSIGNMENT_MODE_CHANGED", "Settings", "1", before, mode, null);
        }
        return toView(s);
    }

    public SettingsView setSystemStatus(SystemStatus status, String reason, CurrentUser actor) {
        if (reason == null || reason.isBlank()) {
            throw ApiException.badRequest("A reason is required");
        }
        SystemSetting s = settings.get();
        SystemStatus before = s.getSystemStatus();
        if (before != status) {
            s.setSystemStatus(status);
            settings.saveChanged(s);
            audit.record(actor.id(), "SYSTEM_STATUS_CHANGED", "Settings", "1", before, status, reason);
        }
        return toView(s);
    }

    /**
     * Controlled reset: removes operational data (projects, operations, resources, items, predecessors, reports, proposals,
     * notifications). Users, settings and the audit trail are kept; the reset itself is recorded with its reason.
     */
    public void reset(String reason, CurrentUser actor) {
        if (reason == null || reason.isBlank()) {
            throw ApiException.badRequest("A reason is required for a reset");
        }
        proposals.deleteAllInBatch();
        runs.deleteAllInBatch();
        reports.deleteAllInBatch();
        notifications.deleteAllInBatch();
        predecessors.deleteAllInBatch();
        operations.deleteAllInBatch();
        projectRepo.deleteAllInBatch();
        items.deleteAllInBatch();
        resources.deleteAllInBatch();
        SystemSetting s = settings.get();
        String before = s.getDataVersion();
        s.setDataVersion("0");
        settings.saveChanged(s);
        audit.record(actor.id(), "SYSTEM_RESET", "System", "1", before, "0", reason);
    }

    private SettingsView toView(SystemSetting s) {
        return new SettingsView(s.getProjectId(), s.getProjectName(), s.getOwnerId(), s.getManagerId(),
                s.getMessengerPlatform(), s.getSystemStatus(), s.getDataVersion(), s.getConfigurationVersion(),
                s.getAssignmentMode(), s.getMaxActiveTasksPerUser(), s.isRequireCompletionApproval(),
                props.llm().configured(), props.llm().model(), s.getLanguage());
    }

    private static String summary(SystemSetting s) {
        return "project=" + s.getProjectName() + ", messenger=" + s.getMessengerPlatform() + ", maxActive="
                + s.getMaxActiveTasksPerUser() + ", requireApproval=" + s.isRequireCompletionApproval() + ", language=" + s.getLanguage();
    }
}
