-- Manager FORCE: a hard decision that one operation starts at a fixed time and/or is done by a fixed executor.
-- At most one FORCE per operation; replacing it overwrites the row, removing it deletes the row (the audit trail keeps
-- the history).
CREATE TABLE schedule_force (
    operation_id    VARCHAR(64)  PRIMARY KEY REFERENCES operation (id),
    forced_start    TIMESTAMP WITH TIME ZONE,
    forced_user_id  VARCHAR(64) REFERENCES app_user (id),
    reason          VARCHAR(500),
    created_by      VARCHAR(64)  NOT NULL,
    created_at      TIMESTAMP WITH TIME ZONE NOT NULL,
    CONSTRAINT schedule_force_not_empty CHECK (forced_start IS NOT NULL OR forced_user_id IS NOT NULL)
);

-- Reference plan: the projected start/end of every unfinished operation, saved whenever the manager changes a FORCE
-- or re-optimises. Later re-planning prefers, among equally good schedules, the one closest to it (minimum churn).
CREATE TABLE plan_baseline (
    operation_id    VARCHAR(64)  PRIMARY KEY,
    planned_start   TIMESTAMP WITH TIME ZONE NOT NULL,
    planned_end     TIMESTAMP WITH TIME ZONE NOT NULL,
    saved_at        TIMESTAMP WITH TIME ZONE NOT NULL
);
