package com.aiso.service;

import com.aiso.config.AisoProperties;
import com.aiso.domain.AppUser;
import com.aiso.domain.AuditEvent;
import com.aiso.domain.ImportLog;
import com.aiso.domain.Notification;
import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.ProposalStatus;
import com.aiso.domain.ReportType;
import com.aiso.domain.Role;
import com.aiso.domain.SystemSetting;
import com.aiso.domain.WorkResource;
import com.aiso.repo.AssignmentProposalRepository;
import com.aiso.repo.AuditEventRepository;
import com.aiso.repo.ImportLogRepository;
import com.aiso.repo.NotificationRepository;
import com.aiso.repo.OperationReportRepository;
import com.aiso.service.Dashboards.ImportSummary;
import com.aiso.service.Dashboards.ManagerDashboard;
import com.aiso.service.Dashboards.OwnerDashboard;
import com.aiso.service.Dashboards.Progress;
import com.aiso.service.Dashboards.ResourceLoad;
import com.aiso.service.Dashboards.UserDashboard;
import com.aiso.service.Dashboards.UserLoad;
import com.aiso.service.Dashboards.UserPerformance;
import com.aiso.service.Views.AuditView;
import com.aiso.service.Views.NotificationView;
import com.aiso.service.Views.OperationView;
import com.aiso.service.Views.ReportView;
import org.springframework.data.domain.PageRequest;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Duration;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.EnumMap;
import java.util.List;
import java.util.Map;
import java.util.function.Predicate;

@Service
@Transactional
public class DashboardService {

    private final ProjectDataService dataService;
    private final PlanService planService;
    private final OperationViewService viewService;
    private final AssignmentProposalRepository proposals;
    private final OperationReportRepository reports;
    private final AuditEventRepository auditEvents;
    private final ImportLogRepository imports;
    private final NotificationRepository notifications;
    private final AisoProperties props;
    private final SchedulingService scheduling;

    public DashboardService(ProjectDataService dataService, PlanService planService, OperationViewService viewService,
                            AssignmentProposalRepository proposals, OperationReportRepository reports,
                            AuditEventRepository auditEvents, ImportLogRepository imports,
                            NotificationRepository notifications, AisoProperties props, SchedulingService scheduling) {
        this.dataService = dataService;
        this.planService = planService;
        this.viewService = viewService;
        this.proposals = proposals;
        this.reports = reports;
        this.auditEvents = auditEvents;
        this.imports = imports;
        this.notifications = notifications;
        this.props = props;
        this.scheduling = scheduling;
    }

    // ---- manager ----------------------------------------------------------------------------------------------

    public ManagerDashboard manager() {
        ProjectData data = dataService.load();
        PlanService.PlanView plan = planService.plan(data);
        List<OperationView> all = data.ops().values().stream().map(o -> viewService.toView(o, data, plan)).toList();

        Map<OperationStatus, Long> counts = statusCounts(data.ops().values().stream());
        List<OperationView> ready = filter(all, v -> v.status() == OperationStatus.READY);
        List<OperationView> blocked = filter(all, v -> v.status() == OperationStatus.BLOCKED);
        List<OperationView> delayed = filter(all, OperationView::delayed);
        List<OperationView> inProgress = filter(all, v -> v.status() == OperationStatus.IN_PROGRESS);
        List<OperationView> critical = all.stream().filter(v -> !v.status().isTerminal())
                .sorted(Comparator.comparingDouble(OperationView::tailHours).reversed())
                .limit(6).toList();

        List<ResourceLoad> resources = new ArrayList<>();
        for (WorkResource r : data.resources().values()) {
            int busy = 0;
            int readyWaiting = 0;
            int notReady = 0;
            for (Operation o : data.ops().values()) {
                if (!o.getResourceId().equals(r.getId())) {
                    continue;
                }
                if (o.getStatus().isActive()) busy++;
                else if (o.getStatus() == OperationStatus.READY) readyWaiting++;
                else if (o.getStatus() == OperationStatus.NOT_READY) notReady++;
            }
            resources.add(new ResourceLoad(r.getId(), r.getName(), r.getCapacity(), busy, readyWaiting, notReady, r.isActive()));
        }

        List<UserLoad> userLoads = new ArrayList<>();
        for (AppUser u : data.users().values()) {
            if (u.getRole().isExecutor()) {
                userLoads.add(userLoad(u, data));
            }
        }
        List<ReportView> recent = reports.findAllByOrderByCreatedAtDesc(PageRequest.of(0, 12)).stream()
                .map(r -> new ReportView(r.getId(), r.getUserId(), data.userName(r.getUserId()), r.getReportType(),
                        r.getProgressPercent(), r.getNote(), r.getCreatedAt()))
                .toList();

        SystemSetting s = data.settings();
        return new ManagerDashboard(counts, progress(data, plan), s.getAssignmentMode(), props.llm().configured(),
                props.llm().model(), proposals.findByStatus(ProposalStatus.PENDING).size(),
                ready, blocked, delayed, inProgress, critical, resources, userLoads, recent,
                scheduling.summaries(data, plan), scheduling.metrics(data, plan),
                scheduling.metrics(data, planService.planNaive(data, null)));
    }

    // ---- owner ------------------------------------------------------------------------------------------------

    public OwnerDashboard owner() {
        ProjectData data = dataService.load();
        PlanService.PlanView plan = planService.plan(data);
        SystemSetting s = data.settings();
        Map<Role, Long> byRole = new EnumMap<>(Role.class);
        long active = 0;
        for (AppUser u : data.users().values()) {
            byRole.merge(u.getRole(), 1L, Long::sum);
            if (u.isActive()) active++;
        }
        List<ImportSummary> recentImports = imports.findAllByOrderByImportedAtDesc(PageRequest.of(0, 5)).stream()
                .map((ImportLog l) -> new ImportSummary(l.getId(), l.getImportedAt(), l.getActorId(), l.getFileName(),
                        l.getFormat(), l.getStatus(), l.getErrorCount(), l.getSummary()))
                .toList();
        List<AuditView> audit = auditEvents.findAllByOrderByOccurredAtDesc(PageRequest.of(0, 10)).stream()
                .map(DashboardService::auditView).toList();
        return new OwnerDashboard(s.getProjectId(), s.getProjectName(), s.getSystemStatus(), s.getDataVersion(),
                s.getConfigurationVersion(), s.getAssignmentMode(), props.llm().configured(), props.llm().model(),
                s.getMessengerPlatform(), s.isRequireCompletionApproval(), s.getMaxActiveTasksPerUser(),
                byRole, active, statusCounts(data.ops().values().stream()), progress(data, plan),
                notifications.countByDeliveryStatus("FAILED"), recentImports, audit);
    }

    // ---- executive user ---------------------------------------------------------------------------------------

    public UserDashboard user(String userId) {
        ProjectData data = dataService.load();
        PlanService.PlanView plan = planService.plan(data);
        List<Operation> mine = data.ops().values().stream().filter(o -> userId.equals(o.getAssignedUserId())).toList();
        List<OperationView> active = mine.stream().filter(o -> !o.getStatus().isTerminal())
                .map(o -> viewService.toView(o, data, plan))
                .sorted(Comparator.comparing((OperationView v) -> v.status() == OperationStatus.IN_PROGRESS ? 0 : 1)
                        .thenComparing(v -> v.plannedEnd() == null ? java.time.Instant.MAX : v.plannedEnd()))
                .toList();
        List<OperationView> done = mine.stream().filter(o -> o.getStatus() == OperationStatus.COMPLETED)
                .sorted(Comparator.comparing(Operation::getCompletedAt, Comparator.nullsLast(Comparator.reverseOrder())))
                .limit(8).map(o -> viewService.toView(o, data, plan)).toList();
        List<NotificationView> notes = notifications.findByRecipientIdOrderByCreatedAtDesc(userId, PageRequest.of(0, 15)).stream()
                .map(DashboardService::notificationView).toList();
        return new UserDashboard(userId, data.userName(userId), statusCounts(mine.stream()), active, done,
                performance(userId, data), notifications.countByRecipientIdAndReadAtIsNull(userId), notes);
    }

    // ---- reports ----------------------------------------------------------------------------------------------

    public List<UserPerformance> performance() {
        ProjectData data = dataService.load();
        return data.users().values().stream().filter(u -> u.getRole().isExecutor())
                .map(u -> performance(u.getId(), data)).toList();
    }

    private UserPerformance performance(String userId, ProjectData data) {
        int assigned = 0, inProgress = 0, blocked = 0, completed = 0, late = 0, withDue = 0, onTime = 0;
        double completedHours = 0, actual = 0, planned = 0;
        for (Operation o : data.ops().values()) {
            if (!userId.equals(o.getAssignedUserId())) {
                continue;
            }
            switch (o.getStatus()) {
                case ASSIGNED -> assigned++;
                case IN_PROGRESS -> inProgress++;
                case BLOCKED -> blocked++;
                case COMPLETED -> {
                    completed++;
                    completedHours += o.totalHours();
                    if (o.getPlannedEnd() != null && o.getCompletedAt() != null) {
                        withDue++;
                        if (o.getCompletedAt().isAfter(o.getPlannedEnd())) late++;
                        else onTime++;
                    }
                    if (o.getStartedAt() != null && o.getCompletedAt() != null && o.totalHours() > 0) {
                        actual += Duration.between(o.getStartedAt(), o.getCompletedAt()).toSeconds() / 3600.0;
                        planned += o.totalHours();
                    }
                }
                default -> {
                }
            }
        }
        int blockReports = (int) reports.countByUserIdAndReportType(userId, ReportType.BLOCK);
        return new UserPerformance(userId, data.userName(userId), assigned, inProgress, blocked, completed, completedHours,
                withDue == 0 ? null : (double) onTime / withDue, planned == 0 ? null : actual / planned, late, blockReports);
    }

    // ---- shared helpers ---------------------------------------------------------------------------------------

    private UserLoad userLoad(AppUser u, ProjectData data) {
        int assigned = 0, inProgress = 0, blocked = 0, completed = 0;
        double load = 0, doneHours = 0;
        for (Operation o : data.ops().values()) {
            if (!u.getId().equals(o.getAssignedUserId())) {
                continue;
            }
            switch (o.getStatus()) {
                case ASSIGNED -> {
                    assigned++;
                    load += o.totalHours();
                }
                case IN_PROGRESS -> {
                    inProgress++;
                    load += o.totalHours() * (1 - o.getProgressPercent() / 100.0);
                }
                case BLOCKED -> blocked++;
                case COMPLETED -> {
                    completed++;
                    doneHours += o.totalHours();
                }
                default -> {
                }
            }
        }
        return new UserLoad(u.getId(), u.getFullName(), u.isActive(), assigned, inProgress, blocked, load, completed, doneHours);
    }

    private static Progress progress(ProjectData data, PlanService.PlanView plan) {
        double total = 0;
        double done = 0;
        long count = 0;
        for (Operation o : data.ops().values()) {
            if (o.getStatus() == OperationStatus.CANCELLED) {
                continue;
            }
            count++;
            total += o.totalHours();
            if (o.getStatus() == OperationStatus.COMPLETED) {
                done += o.totalHours();
            }
        }
        double pct = total == 0 ? 0 : done / total * 100.0;
        return new Progress(count, total, done, pct, plan.projectEnd(), plan.makespanHours());
    }

    private static Map<OperationStatus, Long> statusCounts(java.util.stream.Stream<Operation> ops) {
        Map<OperationStatus, Long> m = new EnumMap<>(OperationStatus.class);
        for (OperationStatus s : OperationStatus.values()) {
            m.put(s, 0L);
        }
        ops.forEach(o -> m.merge(o.getStatus(), 1L, Long::sum));
        return m;
    }

    private static List<OperationView> filter(List<OperationView> all, Predicate<OperationView> p) {
        return all.stream().filter(p).toList();
    }

    public static AuditView auditView(AuditEvent e) {
        return new AuditView(e.getEventId(), e.getOccurredAt(), e.getActorId(), e.getAction(), e.getEntityType(),
                e.getEntityId(), e.getPreviousValue(), e.getNewValue(), e.getReason());
    }

    public static NotificationView notificationView(Notification n) {
        return new NotificationView(n.getId(), n.getKind(), n.getMessage(), n.getRelatedOperationId(), n.getCreatedAt(),
                n.getReadAt(), n.getDeliveryStatus(), n.getDeliveryError());
    }
}
