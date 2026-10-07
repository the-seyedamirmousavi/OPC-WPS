package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Predecessor;
import com.aiso.domain.Role;
import com.aiso.repo.OperationRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.ArrayList;
import java.util.List;

/**
 * Dependency (OPC) engine: decides which operations are READY. Rules are explicit and deterministic:
 * <ul>
 *   <li>An operation with no mandatory predecessors is READY.</li>
 *   <li>FINISH_TO_START needs the predecessor COMPLETED (and approved, when the setting requires it).</li>
 *   <li>START_TO_START needs the predecessor IN_PROGRESS or COMPLETED.</li>
 *   <li>A CANCELLED predecessor never satisfies a dependency; the manager has to decide.</li>
 *   <li>All mandatory predecessors must be satisfied; a single missing one keeps the operation NOT_READY.</li>
 * </ul>
 */
@Service
public class DependencyService {

    private final ProjectDataService dataService;
    private final OperationRepository operations;
    private final AuditService audit;
    private final NotificationService notifications;

    public DependencyService(ProjectDataService dataService, OperationRepository operations,
                             AuditService audit, NotificationService notifications) {
        this.dataService = dataService;
        this.operations = operations;
        this.audit = audit;
        this.notifications = notifications;
    }

    public static boolean satisfied(Predecessor p, Operation pred, boolean requireApproval) {
        if (!p.isMandatory()) {
            return true;
        }
        OperationStatus s = pred.getStatus();
        return switch (p.getDependencyType()) {
            case FINISH_TO_START -> s == OperationStatus.COMPLETED && (!requireApproval || pred.isCompletionApproved());
            case START_TO_START -> s == OperationStatus.IN_PROGRESS || s == OperationStatus.COMPLETED;
        };
    }

    /** Predecessors that currently hold the operation back. */
    public static List<Predecessor> unsatisfied(Operation op, ProjectData data) {
        List<Predecessor> out = new ArrayList<>();
        boolean requireApproval = data.settings().isRequireCompletionApproval();
        for (Predecessor p : data.predsOf(op.getId())) {
            Operation pred = data.ops().get(p.getPredecessorId());
            if (pred == null || !satisfied(p, pred, requireApproval)) {
                out.add(p);
            }
        }
        return out;
    }

    /**
     * Re-evaluates every NOT_READY / READY operation and moves it to the correct state.
     * Called after any event that can change predecessor state (import, start, completion, cancel, approval).
     *
     * @return ids of operations that became READY during this evaluation
     */
    @Transactional
    public List<String> evaluateAll(String actorId) {
        ProjectData data = dataService.load();
        List<String> becameReady = new ArrayList<>();
        for (Operation op : data.ops().values()) {
            OperationStatus s = op.getStatus();
            if (s != OperationStatus.NOT_READY && s != OperationStatus.READY) {
                continue;
            }
            boolean ready = unsatisfied(op, data).isEmpty();
            if (ready && s == OperationStatus.NOT_READY) {
                move(op, OperationStatus.READY, actorId, "All mandatory prerequisites satisfied");
                becameReady.add(op.getId());
            } else if (!ready && s == OperationStatus.READY) {
                move(op, OperationStatus.NOT_READY, actorId, "A prerequisite is no longer satisfied");
            }
        }
        if (!becameReady.isEmpty()) {
            announceReady(becameReady, data);
        }
        return becameReady;
    }

    private void move(Operation op, OperationStatus to, String actorId, String reason) {
        OperationStatus from = op.getStatus();
        op.setStatus(to);
        op.setUpdatedAt(Instant.now());
        operations.save(op);
        audit.record(actorId, "STATUS_CHANGE", "Operation", op.getId(), from, to, reason);
    }

    private void announceReady(List<String> ids, ProjectData data) {
        for (String id : ids) {
            Operation op = data.ops().get(id);
            String responsible = op.getResponsibleUserId() != null ? op.getResponsibleUserId()
                    : data.resources().get(op.getResourceId()).getResponsibleUserId();
            AppUser u = responsible == null ? null : data.users().get(responsible);
            if (u != null && u.isActive() && u.getRole().isExecutor()) {
                notifications.notifyUser(u.getId(), "OPERATION_READY",
                        "Operation " + op.getId() + " (" + op.getName() + ") is ready to be executed.", op.getId());
            }
        }
        String list = String.join(", ", ids.size() > 8 ? ids.subList(0, 8) : ids) + (ids.size() > 8 ? ", ..." : "");
        notifications.notifyManagers("OPERATIONS_READY", ids.size() + " operation(s) became READY: " + list, null);
    }
}
