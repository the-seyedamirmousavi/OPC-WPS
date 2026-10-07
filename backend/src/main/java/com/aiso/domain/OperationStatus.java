package com.aiso.domain;

public enum OperationStatus {
    NOT_READY, READY, ASSIGNED, IN_PROGRESS, BLOCKED, COMPLETED, CANCELLED;

    /** Occupies a resource slot and counts toward the user's active task limit. */
    public boolean isActive() {
        return this == ASSIGNED || this == IN_PROGRESS;
    }

    public boolean isTerminal() {
        return this == COMPLETED || this == CANCELLED;
    }
}
