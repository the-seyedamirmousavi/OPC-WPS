package com.aiso.imports;

import com.aiso.service.Scheduling.Impact;

import java.util.List;

/**
 * @param status         REJECTED (errors, nothing changed), VALID (dry run passed, nothing changed),
 *                       PRIORITY_REQUIRED (file is fine, but a new project joins active ones and needs a ranking) or APPLIED
 * @param newUsers       temporary passwords of users created by this import - shown once, never stored in clear text
 * @param projectId      project the file was (or would be) imported into
 * @param newProject     true when the import creates that project
 * @param activeProjects the other active projects in their current priority order (to be ranked together with the new one)
 * @param impact         effect on the schedule: current vs. after this import (null when it cannot be computed)
 */
public record ImportResult(String status, String format, List<ImportIssue> errors, List<String> warnings,
                           List<Count> counts, List<NewUser> newUsers,
                           String projectId, String projectName, boolean newProject, List<ProjectRef> activeProjects,
                           Impact impact) {

    public record Count(String entity, int created, int updated) {
    }

    public record NewUser(String userId, String fullName, String temporaryPassword) {
    }

    public record ProjectRef(String id, String name, int priority) {
    }
}
