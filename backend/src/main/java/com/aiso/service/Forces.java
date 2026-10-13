package com.aiso.service;

import com.aiso.domain.OperationStatus;

import java.time.Instant;
import java.util.List;

/** Views of the manager FORCE feature. All {@code message} texts are already in the language of the request. */
public final class Forces {

    private Forces() {
    }

    /**
     * @param kind    machine-readable reason, e.g. CAPACITY, PRECEDENCE, START_IN_PAST, NOT_FOUND, ALREADY_STARTED,
     *                OPERATION_FINISHED, RESOURCE_INACTIVE, USER_NOT_ALLOWED, EXECUTOR_LOCKED, BREAKS_FORCE (conflicts);
     *                OPC_EXECUTOR_OVERRIDDEN, OVER_TASK_LIMIT, PRIORITY_DELAYED, REASSIGNS, COMMITTED_MOVED,
     *                ESCALATED (warnings)
     * @param proven  for a conflict: true = impossible for every schedule; false = the heuristic found no schedule
     * @param related operations involved (the clashing ones, or the binding predecessor chain)
     */
    public record Finding(String kind, String operationId, boolean proven, List<String> related, String message) {
    }

    public record MovedOp(String operationId, String name, String projectName, boolean committed,
                          Instant before, Instant after, double shiftHours) {
    }

    public record ProjectShift(String projectId, String name, int priority, Instant before, Instant after,
                               double deltaHours) {
    }

    /**
     * What applying a FORCE would do. Nothing is stored.
     *
     * @param feasible  false when any conflict exists; such a FORCE is refused
     * @param currentStart projected start of the operation now
     * @param plannedStart projected start of the operation with the FORCE (plannedEnd likewise)
     * @param moved     operations whose projected start changes (largest shift first, at most 100)
     */
    public record Impact(boolean feasible, String operationId, Instant currentStart, Instant plannedStart,
                         Instant plannedEnd, String forcedUserId, List<Finding> conflicts, List<Finding> warnings,
                         int movedCount, int committedMoved, double totalShiftHours, List<MovedOp> moved,
                         List<ProjectShift> projects, double wasteBefore, double wasteAfter) {
    }

    /**
     * @param state HONOURED (in force and met), NOT_HONOURED (see {@code conflict}), DONE (the operation has started
     *              or finished, so the FORCE no longer constrains the plan)
     */
    public record ForceView(String operationId, String operationName, String projectId, String projectName,
                            int projectRank, String resourceName, OperationStatus operationStatus,
                            Instant forcedStart, String forcedUserId, String forcedUserName, String reason,
                            String createdBy, Instant createdAt, String state, Instant plannedStart,
                            Instant plannedEnd, Finding conflict) {
    }

    /** @param escalated operations that currently run ahead of project priority so that FORCEs can be met */
    public record Board(List<ForceView> forces, List<Finding> conflicts, int escalated, Instant baselineSavedAt) {
    }
}
