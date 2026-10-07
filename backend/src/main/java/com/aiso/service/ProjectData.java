package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Item;
import com.aiso.domain.Operation;
import com.aiso.domain.Predecessor;
import com.aiso.domain.Project;
import com.aiso.domain.SystemSetting;
import com.aiso.domain.WorkResource;

import java.time.Instant;
import java.util.List;
import java.util.Map;

/** Immutable in-memory snapshot of the project used by the engine, planner and dashboards. */
public record ProjectData(
        SystemSetting settings,
        Map<String, Project> projects,
        Map<String, Operation> ops,
        Map<String, WorkResource> resources,
        Map<String, AppUser> users,
        Map<String, Item> items,
        Map<String, List<Predecessor>> predecessors,
        Map<String, List<String>> successors,
        Instant now) {

    public List<Predecessor> predsOf(String operationId) {
        return predecessors.getOrDefault(operationId, List.of());
    }

    /** Priority rank of the project owning the operation (1 = most important); a large number if unknown. */
    public int rankOf(Operation op) {
        Project p = projects.get(op.getProjectId());
        return p == null ? 9999 : p.getPriority();
    }

    public String projectName(String projectId) {
        Project p = projects.get(projectId);
        return p == null ? projectId : p.getName();
    }

    public String userName(String userId) {
        AppUser u = userId == null ? null : users.get(userId);
        return u == null ? null : u.getFullName();
    }

    public String resourceName(String resourceId) {
        WorkResource r = resources.get(resourceId);
        return r == null ? resourceId : r.getName();
    }
}
