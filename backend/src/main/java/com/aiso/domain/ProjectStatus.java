package com.aiso.domain;

public enum ProjectStatus {
    /** Scheduled together with the other active projects. */
    ACTIVE,
    /** Finished and put away; no longer scheduled or ranked. Only possible when every operation is completed or cancelled. */
    ARCHIVED
}
