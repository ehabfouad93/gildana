-- ─────────────────────────────────────────────
-- AI-driven automation flow builder
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS flows (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NOT NULL,
    name           VARCHAR(190) NOT NULL,
    status         VARCHAR(12)  NOT NULL DEFAULT 'draft',   -- draft|active|paused
    trigger_type   VARCHAR(20)  NOT NULL DEFAULT 'keyword', -- keyword|welcome|button|google_sheet
    trigger_config JSON         NULL,                       -- {keywords:[], match_type}
    source_config  JSON         NULL,                       -- google_sheet: {csv_url, column_map, fetch_interval_min, last_fetched_at, last_seen_hash, outreach_template_id, outreach_lang}
    first_step_id  INT          NULL,
    hot_min        INT          NOT NULL DEFAULT 70,
    warm_min       INT          NOT NULL DEFAULT 40,
    runs_count     INT          NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NULL,
    KEY idx_client (client_id),
    KEY idx_status (status, trigger_type),
    CONSTRAINT fk_flows_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flow_steps (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    flow_id      INT          NOT NULL,
    client_id    INT          NOT NULL,
    sort         INT          NOT NULL DEFAULT 0,
    type         VARCHAR(20)  NOT NULL,   -- text|image|template|buttons|ai_branch|question|ai_score|score|wait|tag|list_add|notify|collect
    config       JSON         NULL,
    next_step_id INT          NULL,       -- default next; NULL = End. (No FK: forward refs + reordering.)
    created_at   DATETIME     NOT NULL,
    KEY idx_flow (flow_id, sort),
    CONSTRAINT fk_steps_flow FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flow_runs (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    flow_id         INT          NOT NULL,
    client_id       INT          NOT NULL,
    contact_id      INT          NOT NULL,
    current_step_id INT          NULL,
    status          VARCHAR(16)  NOT NULL DEFAULT 'active', -- active|waiting_input|waiting_timer|completed|blocked|stopped
    wait_until      DATETIME     NULL,
    score           INT          NOT NULL DEFAULT 0,
    grade           VARCHAR(8)   NULL,     -- hot|warm|cold
    context         JSON         NULL,     -- {fields:{}, transcript:[]}
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    KEY idx_flow (flow_id),
    KEY idx_contact (contact_id),
    KEY idx_timer (status, wait_until),
    CONSTRAINT fk_runs_flow    FOREIGN KEY (flow_id)    REFERENCES flows(id)    ON DELETE CASCADE,
    CONSTRAINT fk_runs_client  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    CONSTRAINT fk_runs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flow_messages (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    flow_id       INT          NOT NULL,
    step_id       INT          NULL,
    run_id        BIGINT       NULL,
    client_id     INT          NOT NULL,
    contact_id    INT          NULL,
    wa_message_id VARCHAR(128) NULL,
    status        VARCHAR(12)  NOT NULL DEFAULT 'sent', -- sent|delivered|read|failed
    error_title   VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL,
    KEY idx_flow (flow_id),
    KEY idx_wamid (wa_message_id),
    CONSTRAINT fk_fmsg_flow   FOREIGN KEY (flow_id)   REFERENCES flows(id)   ON DELETE CASCADE,
    CONSTRAINT fk_fmsg_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flow_collected (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    flow_id     INT          NOT NULL,
    step_id     INT          NULL,
    run_id      BIGINT       NULL,
    contact_id  INT          NULL,
    sheet_name  VARCHAR(120) NOT NULL DEFAULT 'Leads',
    phone_e164  VARCHAR(20)  NULL,
    name        VARCHAR(160) NULL,
    last_reply  TEXT         NULL,
    score       INT          NULL,
    grade       VARCHAR(8)   NULL,
    tags        VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL,
    KEY idx_flow_sheet (flow_id, sheet_name),
    CONSTRAINT fk_fcol_flow   FOREIGN KEY (flow_id)   REFERENCES flows(id)   ON DELETE CASCADE,
    CONSTRAINT fk_fcol_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE clients
    ADD COLUMN ai_provider    VARCHAR(12) NOT NULL DEFAULT '' AFTER app_secret_enc,
    ADD COLUMN ai_api_key_enc TEXT        NULL             AFTER ai_provider,
    ADD COLUMN ai_model       VARCHAR(64) NOT NULL DEFAULT '' AFTER ai_api_key_enc;

ALTER TABLE contacts
    ADD COLUMN last_inbound_at DATETIME    NULL AFTER opted_out_at,
    ADD COLUMN tags            VARCHAR(255) NOT NULL DEFAULT '' AFTER last_inbound_at;
