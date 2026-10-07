package com.aiso.assign;

import java.util.List;

/** Immutable input shared by every assignment strategy. */
public record AssignmentContext(
        List<ReadyOp> ready,
        List<UserInfo> users,
        List<ResourceInfo> resources,
        int maxActiveTasksPerUser,
        double responsibleBonusHours) {

    /**
     * @param projectRank priority of the owning project, 1 = most important (0 when projects are not used)
     * @param plannedStartHours start of the operation in the optimised multi-project plan (hours from now)
     * @param tailHours   longest remaining chain of work that depends on this operation, itself included
     *                    (larger = more critical)
     * @param fixedUserId executor fixed in the OPC sheet; a hard constraint when not null
     */
    public record ReadyOp(String id, String name, String itemName, String resourceId, String resourceName,
                          double hours, double tailHours, String fixedUserId,
                          String projectId, String projectName, int projectRank, double plannedStartHours) {

        /** Single-project form: no project priority, planned start unknown. */
        public ReadyOp(String id, String name, String itemName, String resourceId, String resourceName,
                       double hours, double tailHours, String fixedUserId) {
            this(id, name, itemName, resourceId, resourceName, hours, tailHours, fixedUserId, "", "", 0, 0);
        }
    }

    /** @param activeTasks ASSIGNED + IN_PROGRESS tasks; @param loadHours their total remaining hours */
    public record UserInfo(String id, String fullName, boolean active, int activeTasks, double loadHours) {
    }

    /** @param used operations currently ASSIGNED or IN_PROGRESS on this resource */
    public record ResourceInfo(String id, String name, int capacity, int used, String responsibleUserId, boolean active) {
    }
}
