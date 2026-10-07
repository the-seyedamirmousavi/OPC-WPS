package com.aiso.assign;

import com.aiso.domain.AssignmentMode;

/** A way of turning READY operations into assignment proposals. Selected at runtime by {@link AssignmentMode}. */
public interface AssignmentStrategy {

    AssignmentMode mode();

    Suggestion suggest(AssignmentContext context);
}
