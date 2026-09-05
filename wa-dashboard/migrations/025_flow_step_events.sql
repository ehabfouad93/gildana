-- Where do people drop out of a flow?
--
-- flow_messages already records what each step SENT, which answers "did this message go out".
-- It cannot answer "how many people reached this step and never got past it" — and that is
-- the question that tells a client their flow is broken. A step can be reached and stall
-- without ever sending anything (out of credits, outside the 24-hour window, an AI step with
-- no key), and those are exactly the failures that were invisible.
--
-- One row per run per step, updated in place, so the table grows with distinct paths taken
-- rather than with every retry.
CREATE TABLE IF NOT EXISTS flow_step_events (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    flow_id     INT      NOT NULL,
    step_id     INT      NOT NULL,
    run_id      BIGINT   NOT NULL,
    client_id   INT      NOT NULL,
    -- reached  : the engine started this step
    -- advanced : it finished and moved on (so the customer saw it work)
    -- stalled  : it could not proceed — this is the drop-off worth showing
    outcome     ENUM('reached','advanced','stalled') NOT NULL DEFAULT 'reached',
    reason      VARCHAR(64) NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    UNIQUE KEY uq_run_step (run_id, step_id),
    KEY idx_flow_step (flow_id, step_id, outcome),
    CONSTRAINT fk_fse_flow   FOREIGN KEY (flow_id)   REFERENCES flows(id)   ON DELETE CASCADE,
    CONSTRAINT fk_fse_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
