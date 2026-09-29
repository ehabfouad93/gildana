-- WhatsApp the CRM sends by itself: a template when a lead reaches a stage, and sequences that
-- follow up with leads who went quiet. Every message waits in one queue, so working hours,
-- opt-outs and "they replied, stop" are checked in one place, right before sending.

-- One message per stage: which template, and what fills each {{n}}.
CREATE TABLE IF NOT EXISTS crm_stage_msgs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NOT NULL,
    stage_id      INT          NOT NULL,
    template_id   INT          NOT NULL,
    vars          TEXT         NULL,              -- JSON list: what fills {{1}}, {{2}}… (see crm_tpl_tokens)
    header_media  VARCHAR(500) NULL,
    delay_minutes INT          NOT NULL DEFAULT 0,
    on_arrival    TINYINT(1)   NOT NULL DEFAULT 0, -- also when a new lead lands straight in this stage
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_stage (client_id, stage_id),
    CONSTRAINT fk_crm_stage_msgs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_sequences (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    name       VARCHAR(120) NOT NULL,
    trigger_kind VARCHAR(16) NOT NULL,            -- no_answer | stale | stage | new_lead
    trigger_n  INT          NOT NULL DEFAULT 0,   -- how many no-answers / days quiet / which stage
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL,
    KEY idx_client (client_id),
    CONSTRAINT fk_crm_sequences_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_seq_steps (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    sequence_id   INT          NOT NULL,
    sort          INT          NOT NULL DEFAULT 0,
    delay_hours   INT          NOT NULL DEFAULT 0,  -- after joining (first step) or after the previous step
    template_id   INT          NOT NULL,
    vars          TEXT         NULL,
    header_media  VARCHAR(500) NULL,
    KEY idx_seq (sequence_id, sort),
    CONSTRAINT fk_crm_seq_steps_seq FOREIGN KEY (sequence_id) REFERENCES crm_sequences(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A lead in a sequence: which step is next and when. A lead goes through a sequence once.
CREATE TABLE IF NOT EXISTS crm_seq_runs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    sequence_id INT          NOT NULL,
    contact_id  INT          NOT NULL,
    step_idx    INT          NOT NULL DEFAULT 0,
    next_at     DATETIME     NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'active',   -- active | done | stopped
    stop_reason VARCHAR(60)  NULL,
    started_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_run (sequence_id, contact_id),
    KEY idx_due (status, next_at),
    CONSTRAINT fk_crm_seq_runs_seq FOREIGN KEY (sequence_id) REFERENCES crm_sequences(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_seq_runs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Everything the CRM sends by itself, and what became of it.
CREATE TABLE IF NOT EXISTS crm_msg_queue (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id    INT          NOT NULL,
    contact_id   INT          NOT NULL,
    template_id  INT          NOT NULL,
    vars         TEXT         NULL,
    header_media VARCHAR(500) NULL,
    context      VARCHAR(255) NULL,               -- JSON: e.g. the visit a confirmation is about
    reason       VARCHAR(40)  NOT NULL,           -- stage:<id> | seq:<id>:<step> | visit_confirm:<id> | visit_remind:<id>
    due_at       DATETIME     NOT NULL,
    status       VARCHAR(10)  NOT NULL DEFAULT 'queued',   -- queued | sending | sent | failed | skipped | cancelled
    error        VARCHAR(255) NULL,
    message_id   INT          NULL,
    created_at   DATETIME     NOT NULL,
    sent_at      DATETIME     NULL,
    KEY idx_due (status, due_at),
    KEY idx_contact (contact_id),
    CONSTRAINT fk_crm_msg_queue_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
