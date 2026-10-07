package com.aiso.assign;

import com.aiso.assign.AssignmentContext.ReadyOp;
import com.aiso.assign.AssignmentContext.ResourceInfo;
import com.aiso.assign.AssignmentContext.UserInfo;

import java.util.HashMap;
import java.util.Map;
import java.util.Optional;

/**
 * Mutable working copy of the hard constraints. Both strategies use it, so a proposal that is acceptable to one
 * is acceptable to the other:
 * <ol>
 *   <li>the user must be an active executive user;</li>
 *   <li>if the OPC sheet fixes an executor for the operation, only that user is allowed;</li>
 *   <li>the resource must be active and have a free slot (ASSIGNED + IN_PROGRESS &lt; capacity);</li>
 *   <li>the user must stay at or below the configured active-task limit.</li>
 * </ol>
 */
public final class AssignmentState {

    private final Map<String, ReadyOp> ops = new HashMap<>();
    private final Map<String, UserInfo> users = new HashMap<>();
    private final Map<String, ResourceInfo> resources = new HashMap<>();
    private final Map<String, Integer> resourceUsed = new HashMap<>();
    private final Map<String, Integer> userTasks = new HashMap<>();
    private final Map<String, Double> userLoad = new HashMap<>();
    private final int maxActive;

    public AssignmentState(AssignmentContext ctx) {
        ctx.ready().forEach(o -> ops.put(o.id(), o));
        ctx.users().forEach(u -> {
            users.put(u.id(), u);
            userTasks.put(u.id(), u.activeTasks());
            userLoad.put(u.id(), u.loadHours());
        });
        ctx.resources().forEach(r -> {
            resources.put(r.id(), r);
            resourceUsed.put(r.id(), r.used());
        });
        this.maxActive = ctx.maxActiveTasksPerUser();
    }

    /** @return the reason the pair violates a hard constraint, or empty if it is allowed */
    public Optional<String> reject(String operationId, String userId) {
        ReadyOp op = ops.get(operationId);
        if (op == null) {
            return Optional.of("Operation " + operationId + " is not READY");
        }
        UserInfo user = users.get(userId);
        if (user == null || !user.active()) {
            return Optional.of("User " + userId + " is unknown, inactive or not an executive user");
        }
        if (op.fixedUserId() != null && !op.fixedUserId().equals(userId)) {
            return Optional.of("Operation " + operationId + " is fixed to executor " + op.fixedUserId());
        }
        ResourceInfo res = resources.get(op.resourceId());
        if (res == null || !res.active()) {
            return Optional.of("Resource " + op.resourceId() + " is inactive");
        }
        if (freeSlots(op.resourceId()) <= 0) {
            return Optional.of("Resource " + res.name() + " is at capacity (" + res.capacity() + "/" + res.capacity() + ")");
        }
        if (tasksOf(userId) >= maxActive) {
            return Optional.of("User " + userId + " already has " + tasksOf(userId) + " active tasks (limit " + maxActive + ")");
        }
        return Optional.empty();
    }

    public void apply(String operationId, String userId) {
        ReadyOp op = ops.get(operationId);
        resourceUsed.merge(op.resourceId(), 1, Integer::sum);
        userTasks.merge(userId, 1, Integer::sum);
        userLoad.merge(userId, op.hours(), Double::sum);
    }

    public int freeSlots(String resourceId) {
        ResourceInfo r = resources.get(resourceId);
        return r == null ? 0 : r.capacity() - resourceUsed.getOrDefault(resourceId, 0);
    }

    public int usedSlots(String resourceId) {
        return resourceUsed.getOrDefault(resourceId, 0);
    }

    public int tasksOf(String userId) {
        return userTasks.getOrDefault(userId, 0);
    }

    public double loadOf(String userId) {
        return userLoad.getOrDefault(userId, 0.0);
    }

    public Map<String, UserInfo> users() {
        return users;
    }

    public Map<String, ResourceInfo> resources() {
        return resources;
    }

    public Map<String, ReadyOp> ops() {
        return ops;
    }
}
