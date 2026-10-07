package com.aiso.service;

import com.aiso.domain.AssignmentMode;
import com.aiso.domain.DependencyType;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.ProposalStatus;
import com.aiso.domain.ReportType;
import com.aiso.domain.Role;

import java.time.Instant;
import java.util.List;
import java.util.Map;

/** API read models. Plain records so they serialize directly. */
public final class Views {

    private Views() {
    }

    public record PredView(String id, String name, OperationStatus status, DependencyType type, boolean mandatory,
                           boolean satisfied) {
    }

    public record OperationView(
            String id, String name,
            String projectId, String projectName, int projectRank,
            String itemId, String itemName,
            String resourceId, String resourceName,
            String responsibleUserId,
            String assignedUserId, String assignedUserName,
            OperationStatus status,
            double totalHours, double directHours,
            int progressPercent,
            Instant plannedStart, Instant plannedEnd,
            Instant projectedStart, Instant projectedEnd,
            Instant assignedAt, Instant startedAt, Instant completedAt,
            boolean completionApproved,
            String blockReason, String cancelReason,
            List<PredView> predecessors,
            List<PredView> waitingFor,
            boolean delayed, double delayHours, boolean late,
            double tailHours,
            String description) {
    }

    public record ReportView(Long id, String userId, String userName, ReportType type, Integer progressPercent,
                             String note, Instant createdAt) {
    }

    public record OperationDetail(OperationView operation, List<ReportView> reports, List<PredView> successors) {
    }

    public record UserView(String id, String fullName, Role role, String messengerId, boolean active,
                           String contactInfo, boolean mustChangePassword, Instant createdAt) {
    }

    public record ProposalView(Long id, String runId, String operationId, String operationName, String projectName,
                               int projectRank, String resourceName,
                               String userId, String userName, AssignmentMode source, String reason,
                               Instant plannedStart, Instant plannedEnd, ProposalStatus status, String decisionNote,
                               double hours, double tailHours) {
    }

    public record SkippedView(String operationId, String operationName, String reason) {
    }

    public record RunView(String id, AssignmentMode requestedMode, AssignmentMode effectiveMode, boolean fallback,
                          String model, String summary, List<String> warnings, List<SkippedView> unassigned,
                          List<ProposalView> proposals, Instant createdAt, long durationMs,
                          Long inputTokens, Long outputTokens, String requestedBy) {
    }

    public record NotificationView(Long id, String kind, String message, String relatedOperationId, Instant createdAt,
                                   Instant readAt, String deliveryStatus, String deliveryError) {
    }

    public record AuditView(String eventId, Instant occurredAt, String actorId, String action, String entityType,
                            String entityId, String previousValue, String newValue, String reason) {
    }

    public record Counts(Map<OperationStatus, Long> byStatus, long total) {
    }
}
