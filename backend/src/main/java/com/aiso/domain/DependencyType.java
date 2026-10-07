package com.aiso.domain;

public enum DependencyType {
    /** Successor may start once the predecessor is completed. */
    FINISH_TO_START,
    /** Successor may start once the predecessor has started (or completed). */
    START_TO_START
}
