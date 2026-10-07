package com.aiso.imports;

import java.time.Instant;
import java.util.List;

/**
 * Which project an Excel file belongs to.
 * <ul>
 *   <li>{@code projectId} set: import into that existing project.</li>
 *   <li>{@code newProjectName} set: create a new project (id optional).</li>
 *   <li>neither: the only active project, or a default project when there is none. With several active projects the
 *       caller has to choose.</li>
 * </ul>
 * {@code ranking} is the complete priority order of all active projects after the import, first = most important, with
 * the literal {@code NEW} standing for the project being created. It is required when a new project joins projects that
 * are already active.
 */
public record ImportTarget(String projectId, String newProjectName, String newProjectId, Instant dueDate, List<String> ranking) {

    public static final String NEW = "NEW";

    public static ImportTarget none() {
        return new ImportTarget(null, null, null, null, null);
    }
}
