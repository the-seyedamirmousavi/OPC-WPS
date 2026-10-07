package com.aiso.service;

import com.aiso.domain.AssignmentMode;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Role;
import com.aiso.domain.SystemStatus;
import com.aiso.service.Views.AuditView;
import com.aiso.service.Views.NotificationView;
import com.aiso.service.Views.OperationView;
import com.aiso.service.Scheduling.PlanMetrics;
import com.aiso.service.Scheduling.ProjectSummary;
import com.aiso.service.Views.ReportView;

import java.time.Instant;
import java.util.List;
import java.util.Map;

/** Read models for the three dashboards and the reports. */
public final class Dashboards {

    private Dashboards() {
    }

    /**
     * @param progressPercent completed hours / total hours of all non-cancelled operations x 100
     * @param projectedEnd    end of the capacity-constrained plan for all unfinished work, measured from now
     */
    public record Progress(long totalOperations, double totalHours, double completedHours, double progressPercent,
                           Instant projectedEnd, double remainingCriticalHours) {
    }

    public record ResourceLoad(String id, String name, int capacity, int busy, int readyWaiting, int notReady,
                               boolean active) {
    }

    public record UserLoad(String userId, String name, boolean active, int assigned, int inProgress, int blocked,
                           double loadHours, int completed, double completedHours) {
    }

    /**
     * @param onTimeRate      completed on or before the baseline due time / completed with a due time (null if none)
     * @param actualToPlanned total elapsed hours between start and completion / total planned hours (null if none)
     */
    public record UserPerformance(String userId, String name, int assigned, int inProgress, int blocked, int completed,
                                  double completedHours, Double onTimeRate, Double actualToPlanned, int lateCount,
                                  int blockReports) {
    }

    public record ManagerDashboard(
            Map<OperationStatus, Long> statusCounts,
            Progress progress,
            AssignmentMode assignmentMode, boolean llmAvailable, String llmModel, int pendingProposals,
            List<OperationView> ready, List<OperationView> blocked, List<OperationView> delayed,
            List<OperationView> inProgress, List<OperationView> criticalPath,
            List<ResourceLoad> resources, List<UserLoad> users,
            List<ReportView> recentReports,
            List<ProjectSummary> projects, PlanMetrics schedule, PlanMetrics naiveSchedule) {
    }

    public record OwnerDashboard(
            String projectId, String projectName, SystemStatus systemStatus, String dataVersion, int configurationVersion,
            AssignmentMode assignmentMode, boolean llmAvailable, String llmModel, String messengerPlatform,
            boolean requireCompletionApproval, int maxActiveTasksPerUser,
            Map<Role, Long> usersByRole, long activeUsers,
            Map<OperationStatus, Long> statusCounts, Progress progress,
            long failedNotifications, List<ImportSummary> recentImports, List<AuditView> recentAudit) {
    }

    public record ImportSummary(Long id, Instant importedAt, String actorId, String fileName, String format,
                                String status, int errorCount, String summary) {
    }

    public record UserDashboard(
            String userId, String name,
            Map<OperationStatus, Long> statusCounts,
            List<OperationView> activeTasks, List<OperationView> recentCompleted,
            UserPerformance performance,
            long unreadNotifications, List<NotificationView> notifications) {
    }
}
