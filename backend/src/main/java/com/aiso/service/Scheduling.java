package com.aiso.service;

import com.aiso.domain.OperationStatus;
import com.aiso.domain.ProjectStatus;

import java.time.Instant;
import java.util.List;
import java.util.Map;

/** Read models of the multi-project schedule. All durations are hours from "now". */
public final class Scheduling {

    private Scheduling() {
    }

    /**
     * @param tardinessHours how far the projected finish is beyond the due date (0 if on time or no due date)
     * @param rule           dispatching rule that produced this project's placement
     */
    public record ProjectPlan(String projectId, String name, int rank, int openOperations, double startHours,
                              double finishHours, Instant finishAt, Instant dueDate, double tardinessHours,
                              PlanOptimizer.Rule rule) {
    }

    /** @param wasteHours time the resource's lanes sit idle although later work is still planned on them */
    public record ResourceMetric(String resourceId, String name, int capacity, double busyHours, double wasteHours,
                                 double utilizationPercent) {
    }

    /**
     * @param wasteHours         sum over all resource lanes of (last planned end - busy time)
     * @param utilizationPercent busy time / (busy time + waste) over all lanes
     */
    public record PlanMetrics(double makespanHours, Instant finishAt, double busyHours, double wasteHours,
                              double utilizationPercent, List<ProjectPlan> projects, List<ResourceMetric> resources,
                              int candidatesTried) {
    }

    /** @param currentFinishHours null for a project that is not scheduled yet */
    public record ProjectDelta(String projectId, String name, Integer currentRank, int proposedRank,
                               Double currentFinishHours, double proposedFinishHours, Double deltaHours) {
    }

    /**
     * Effect of a (re)ranking or of adding a project.
     *
     * @param current  schedule as it is now
     * @param proposed optimised schedule with the proposed ranking
     * @param naive    same ranking scheduled with the simple single-pass rule, for comparison
     */
    public record Impact(PlanMetrics current, PlanMetrics proposed, PlanMetrics naive, List<ProjectDelta> deltas,
                         double wasteSavedVsNaive, double makespanSavedVsNaive) {
    }

    public record ProjectSummary(String id, String name, int priority, ProjectStatus status, Instant dueDate,
                                 Instant createdAt, int totalOperations, Map<OperationStatus, Long> statusCounts,
                                 double totalHours, double completedHours, double progressPercent, int openOperations,
                                 double finishHours, Instant finishAt, double tardinessHours) {
    }

    public record Overview(PlanMetrics optimized, PlanMetrics naive, List<ProjectSummary> projects) {
    }
}
