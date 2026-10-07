-- Multiple projects scheduled together on shared resources.
-- Existing databases keep working: when operations already exist they all move into one project that takes its
-- id and name from the former single-project settings.

CREATE TABLE project (
    id          VARCHAR(64)  PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    priority    INTEGER      NOT NULL,
    due_date    TIMESTAMP WITH TIME ZONE,
    status      VARCHAR(20)  NOT NULL,
    description VARCHAR(1000),
    created_at  TIMESTAMP WITH TIME ZONE NOT NULL
);

INSERT INTO project (id, name, priority, status, created_at)
SELECT COALESCE(project_id, 'P-001'), COALESCE(project_name, 'AISO Project'), 1, 'ACTIVE', CURRENT_TIMESTAMP
FROM system_setting
WHERE id = 1 AND EXISTS (SELECT 1 FROM operation);

ALTER TABLE operation ADD COLUMN project_id VARCHAR(64);
UPDATE operation SET project_id = (SELECT MIN(id) FROM project);
ALTER TABLE operation ALTER COLUMN project_id SET NOT NULL;
ALTER TABLE operation ADD CONSTRAINT fk_operation_project FOREIGN KEY (project_id) REFERENCES project (id);
CREATE INDEX idx_operation_project ON operation (project_id);
