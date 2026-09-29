-- Telling Meta what became of its leads (Conversions API for CRM): contacted, visited, bought.
-- Meta's ads then look for more people like the ones who buy, not just the ones who fill forms.

ALTER TABLE crm_settings ADD COLUMN capi_on TINYINT(1) NOT NULL DEFAULT 0 AFTER visit_done_stage;
ALTER TABLE crm_settings ADD COLUMN capi_dataset VARCHAR(40) NULL AFTER capi_on;
ALTER TABLE crm_settings ADD COLUMN capi_token_enc TEXT NULL AFTER capi_dataset;          -- encrypted
ALTER TABLE crm_settings ADD COLUMN capi_test_code VARCHAR(40) NULL AFTER capi_token_enc;  -- while checking the setup in Events Manager
ALTER TABLE crm_settings ADD COLUMN capi_events TEXT NULL AFTER capi_test_code;           -- JSON {stage id: event name}
ALTER TABLE crm_settings ADD COLUMN capi_visit_event VARCHAR(60) NULL AFTER capi_events;  -- when they come to a site visit

-- Each event once per lead, and what Meta said.
CREATE TABLE IF NOT EXISTS crm_capi_events (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    contact_id  INT          NOT NULL,
    event_name  VARCHAR(60)  NOT NULL,
    event_time  INT          NOT NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'queued',    -- queued | sent | failed | skipped
    error       VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL,
    sent_at     DATETIME     NULL,
    UNIQUE KEY uq_event (contact_id, event_name),
    KEY idx_status (status, client_id),
    CONSTRAINT fk_crm_capi_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
