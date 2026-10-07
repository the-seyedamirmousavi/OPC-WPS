package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Operation;
import com.aiso.domain.OperationReport;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.ReportType;
import com.aiso.repo.OperationReportRepository;
import com.aiso.repo.OperationRepository;
import com.aiso.repo.UserRepository;
import com.aiso.security.CurrentUser;
import com.aiso.web.ApiException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;

/** Operation lifecycle commands. Every state change is validated, audited and reported. */
@Service
@Transactional
public class OperationService {

    private final OperationRepository operations;
    private final OperationReportRepository reports;
    private final UserRepository users;
    private final DependencyService dependencies;
    private final AuditService audit;
    private final NotificationService notifications;
    private final LanguageService lang;

    public OperationService(OperationRepository operations, OperationReportRepository reports, UserRepository users,
                            DependencyService dependencies, AuditService audit, NotificationService notifications,
                            LanguageService lang) {
        this.operations = operations;
        this.reports = reports;
        this.users = users;
        this.dependencies = dependencies;
        this.audit = audit;
        this.notifications = notifications;
        this.lang = lang;
    }

    // ---- executor actions -------------------------------------------------------------------------------------

    public void start(String id, CurrentUser user) {
        Operation op = ownTask(id, user);
        require(op, OperationStatus.ASSIGNED);
        change(op, OperationStatus.IN_PROGRESS, user.id(), null);
        op.setStartedAt(Instant.now());
        op.setProgressPercent(0);
        save(op);
        report(op, user.id(), ReportType.START, 0, null);
        dependencies.evaluateAll(user.id()); // START_TO_START successors may unlock
    }

    public void progress(String id, int percent, String note, CurrentUser user) {
        Operation op = ownTask(id, user);
        require(op, OperationStatus.IN_PROGRESS);
        if (percent < 0 || percent > 100) {
            throw ApiException.badRequest("Progress must be between 0 and 100");
        }
        int before = op.getProgressPercent();
        op.setProgressPercent(percent);
        save(op);
        audit.record(user.id(), "PROGRESS", "Operation", id, before, percent, note);
        report(op, user.id(), ReportType.PROGRESS, percent, note);
    }

    public void complete(String id, String note, CurrentUser user) {
        Operation op = ownTask(id, user);
        require(op, OperationStatus.IN_PROGRESS);
        change(op, OperationStatus.COMPLETED, user.id(), note);
        op.setProgressPercent(100);
        op.setCompletedAt(Instant.now());
        op.setCompletionApproved(false);
        save(op);
        report(op, user.id(), ReportType.COMPLETE, 100, note);
        notifications.notifyManagers("OPERATION_COMPLETED",
                user.id() + " completed " + op.getId() + " (" + op.getName() + ")", id);
        dependencies.evaluateAll(user.id());
    }

    public void block(String id, String reason, CurrentUser user) {
        Operation op = ownTask(id, user);
        if (op.getStatus() != OperationStatus.ASSIGNED && op.getStatus() != OperationStatus.IN_PROGRESS) {
            throw ApiException.conflict("Only assigned or in-progress operations can be reported as blocked");
        }
        if (reason == null || reason.isBlank()) {
            throw ApiException.badRequest("A reason is required");
        }
        op.setStatusBeforeBlock(op.getStatus());
        op.setBlockReason(reason.trim());
        change(op, OperationStatus.BLOCKED, user.id(), reason);
        save(op);
        report(op, user.id(), ReportType.BLOCK, null, reason);
        notifications.notifyManagers("OPERATION_BLOCKED",
                op.getId() + " (" + op.getName() + ") is blocked: " + reason, id);
    }

    public void comment(String id, String note, CurrentUser user) {
        Operation op = find(id);
        if (user.role().isExecutor() && !user.id().equals(op.getAssignedUserId())) {
            throw ApiException.forbidden("This operation is not assigned to you");
        }
        if (note == null || note.isBlank()) {
            throw ApiException.badRequest("A note is required");
        }
        report(op, user.id(), ReportType.COMMENT, null, note.trim());
        audit.record(user.id(), "COMMENT", "Operation", id, null, null, note);
    }

    // ---- manager actions --------------------------------------------------------------------------------------

    public void unblock(String id, String note, CurrentUser user) {
        Operation op = find(id);
        require(op, OperationStatus.BLOCKED);
        OperationStatus back = op.getStatusBeforeBlock() == null ? OperationStatus.ASSIGNED : op.getStatusBeforeBlock();
        change(op, back, user.id(), note);
        op.setBlockReason(null);
        op.setStatusBeforeBlock(null);
        save(op);
        report(op, user.id(), ReportType.UNBLOCK, null, note);
        notifications.notifyUser(op.getAssignedUserId(), "OPERATION_UNBLOCKED",
                op.getId() + " (" + op.getName() + ") was unblocked.", id);
    }

    public void cancel(String id, String reason, CurrentUser user) {
        Operation op = find(id);
        if (op.getStatus().isTerminal()) {
            throw ApiException.conflict("Operation is already " + op.getStatus());
        }
        if (reason == null || reason.isBlank()) {
            throw ApiException.badRequest("A reason is required to cancel an operation");
        }
        notifications.notifyUser(op.getAssignedUserId(), "OPERATION_CANCELLED",
                op.getId() + " (" + op.getName() + ") was cancelled: " + reason, id);
        change(op, OperationStatus.CANCELLED, user.id(), reason);
        op.setCancelReason(reason.trim());
        save(op);
        report(op, user.id(), ReportType.CANCEL, null, reason);
        dependencies.evaluateAll(user.id());
    }

    /** Separate manager confirmation of a user-reported completion. */
    public void approve(String id, String note, CurrentUser user) {
        Operation op = find(id);
        require(op, OperationStatus.COMPLETED);
        if (op.isCompletionApproved()) {
            throw ApiException.conflict("Completion is already approved");
        }
        op.setCompletionApproved(true);
        op.setCompletionApprovedBy(user.id());
        save(op);
        audit.record(user.id(), "COMPLETION_APPROVED", "Operation", id, false, true, note);
        report(op, user.id(), ReportType.APPROVE, null, note);
        dependencies.evaluateAll(user.id());
    }

    public void unassign(String id, CurrentUser user) {
        Operation op = find(id);
        require(op, OperationStatus.ASSIGNED);
        String previous = op.getAssignedUserId();
        change(op, OperationStatus.READY, user.id(), "Unassigned");
        op.setAssignedUserId(null);
        op.setAssignedAt(null);
        save(op);
        report(op, user.id(), ReportType.UNASSIGN, null, "Unassigned from " + previous);
        notifications.notifyUser(previous, "OPERATION_UNASSIGNED", op.getId() + " was taken off your list.", id);
    }

    /**
     * Assigns a READY (or re-assigns an ASSIGNED) operation. Used by approved proposals and by manual assignment.
     */
    public void assign(String id, String userId, Instant plannedStart, Instant plannedEnd, String actorId, String reason) {
        Operation op = find(id);
        if (op.getStatus() != OperationStatus.READY && op.getStatus() != OperationStatus.ASSIGNED) {
            throw ApiException.conflict("Operation " + id + " is " + op.getStatus() + " and cannot be assigned");
        }
        AppUser target = users.findById(userId).orElseThrow(() -> ApiException.badRequest("Unknown user " + userId));
        if (!target.isActive() || !target.getRole().isExecutor()) {
            throw ApiException.badRequest("User " + userId + " is not an active executive user");
        }
        String previous = op.getAssignedUserId();
        if (op.getStatus() != OperationStatus.ASSIGNED) {
            change(op, OperationStatus.ASSIGNED, actorId, reason);
        }
        op.setAssignedUserId(userId);
        op.setAssignedAt(Instant.now());
        op.setPlannedStart(plannedStart);
        op.setPlannedEnd(plannedEnd);
        save(op);
        audit.record(actorId, "ASSIGN", "Operation", id, previous, userId, reason);
        report(op, actorId, ReportType.ASSIGN, null, "Assigned to " + userId + (reason == null ? "" : " - " + reason));
        notifications.notifyUser(userId, "OPERATION_ASSIGNED",
                "New task: " + op.getId() + " (" + op.getName() + ")", id);
        if (previous != null && !previous.equals(userId)) {
            notifications.notifyUser(previous, "OPERATION_UNASSIGNED", op.getId() + " was reassigned.", id);
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    private Operation find(String id) {
        return operations.findById(id).orElseThrow(() -> ApiException.notFound("Operation " + id + " not found"));
    }

    private Operation ownTask(String id, CurrentUser user) {
        Operation op = find(id);
        if (!user.id().equals(op.getAssignedUserId())) {
            throw ApiException.forbidden("This operation is not assigned to you");
        }
        return op;
    }

    private static void require(Operation op, OperationStatus expected) {
        if (op.getStatus() != expected) {
            throw ApiException.conflict("Operation " + op.getId() + " is " + op.getStatus() + ", expected " + expected);
        }
    }

    private void change(Operation op, OperationStatus to, String actor, String reason) {
        OperationStatus from = op.getStatus();
        op.setStatus(to);
        audit.record(actor, "STATUS_CHANGE", "Operation", op.getId(), from, to, reason);
    }

    private void save(Operation op) {
        op.setUpdatedAt(Instant.now());
        operations.save(op);
    }

    private void report(Operation op, String userId, ReportType type, Integer percent, String note) {
        OperationReport r = new OperationReport();
        r.setOperationId(op.getId());
        r.setUserId(userId);
        r.setReportType(type);
        r.setProgressPercent(percent);
        r.setNote(note == null ? null : lang.forStorage(note));
        reports.save(r);
    }
}
