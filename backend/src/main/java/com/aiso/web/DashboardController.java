package com.aiso.web;

import com.aiso.repo.AuditEventRepository;
import com.aiso.repo.NotificationRepository;
import com.aiso.security.CurrentUser;
import com.aiso.service.DashboardService;
import com.aiso.service.Dashboards.ManagerDashboard;
import com.aiso.service.Dashboards.OwnerDashboard;
import com.aiso.service.Dashboards.UserDashboard;
import com.aiso.service.Dashboards.UserPerformance;
import com.aiso.service.ExcelService;
import com.aiso.service.LanguageService;
import com.aiso.service.Views.AuditView;
import com.aiso.service.Views.NotificationView;
import org.springframework.data.domain.PageRequest;
import org.springframework.http.ResponseEntity;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import java.time.Instant;
import java.util.List;

@RestController
@RequestMapping("/api")
public class DashboardController {

    private final DashboardService dashboards;
    private final ExcelService excel;
    private final AuditEventRepository audit;
    private final NotificationRepository notifications;
    private final LanguageService language;

    public DashboardController(DashboardService dashboards, ExcelService excel, AuditEventRepository audit,
                               NotificationRepository notifications, LanguageService language) {
        this.language = language;
        this.dashboards = dashboards;
        this.excel = excel;
        this.audit = audit;
        this.notifications = notifications;
    }

    @GetMapping("/dashboard/manager")
    @PreAuthorize(Access.MANAGEMENT)
    public ManagerDashboard manager() {
        return dashboards.manager();
    }

    @GetMapping("/dashboard/owner")
    @PreAuthorize(Access.OWNER)
    public OwnerDashboard owner() {
        return dashboards.owner();
    }

    @GetMapping("/dashboard/user")
    @PreAuthorize(Access.EXECUTOR)
    public UserDashboard user(Authentication auth) {
        return dashboards.user(CurrentUser.from(auth).id());
    }

    @GetMapping("/reports/users")
    @PreAuthorize(Access.MANAGEMENT)
    public List<UserPerformance> userPerformance() {
        return dashboards.performance();
    }

    @GetMapping("/reports/export")
    @PreAuthorize(Access.MANAGEMENT)
    public ResponseEntity<byte[]> export(@RequestParam(required = false) String lang) {
        boolean fa = lang == null ? language.requestIsPersian() : "fa".equalsIgnoreCase(lang);
        return ImportController.download(excel.report(fa), fa ? "AISO-گزارش.xlsx" : "AISO-report.xlsx");
    }

    @GetMapping("/audit")
    @PreAuthorize(Access.MANAGEMENT)
    public List<AuditView> audit(@RequestParam(defaultValue = "100") int limit,
                                 @RequestParam(required = false) String entityType,
                                 @RequestParam(required = false) String entityId,
                                 @RequestParam(required = false) String actorId) {
        PageRequest page = PageRequest.of(0, Math.min(Math.max(limit, 1), 500));
        var events = entityType != null && entityId != null
                ? audit.findByEntityTypeAndEntityIdOrderByOccurredAtDesc(entityType, entityId, page)
                : actorId != null
                ? audit.findByActorIdOrderByOccurredAtDesc(actorId, page)
                : audit.findAllByOrderByOccurredAtDesc(page);
        return events.stream().map(DashboardService::auditView).toList();
    }

    // ---- notifications (own inbox) ----------------------------------------------------------------------------

    @GetMapping("/notifications")
    public List<NotificationView> myNotifications(Authentication auth) {
        return notifications.findByRecipientIdOrderByCreatedAtDesc(CurrentUser.from(auth).id(), PageRequest.of(0, 50)).stream()
                .map(DashboardService::notificationView).toList();
    }

    @PostMapping("/notifications/read-all")
    @Transactional
    public void readAll(Authentication auth) {
        notifications.findByRecipientIdAndReadAtIsNull(CurrentUser.from(auth).id()).forEach(n -> n.setReadAt(Instant.now()));
    }

    @PostMapping("/notifications/{id}/read")
    @Transactional
    public void read(Authentication auth, @PathVariable Long id) {
        notifications.findById(id)
                .filter(n -> n.getRecipientId().equals(CurrentUser.from(auth).id()))
                .ifPresent(n -> n.setReadAt(Instant.now()));
    }

    /** Messenger deliveries that failed - visible to the owner and manager. */
    @GetMapping("/notifications/failed")
    @PreAuthorize(Access.MANAGEMENT)
    public List<NotificationView> failed() {
        return notifications.findByDeliveryStatusOrderByCreatedAtDesc("FAILED", PageRequest.of(0, 100)).stream()
                .map(DashboardService::notificationView).toList();
    }
}
