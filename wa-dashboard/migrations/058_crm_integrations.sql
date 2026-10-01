-- Connecting the CRM to another system, both ways:
--   in   an API with keys (create, read, update leads; add notes) and incoming webhook URLs whose
--        fields are mapped onto a lead — for systems that can only "POST a webhook";
--   out  webhooks: chosen events (lead created, stage changed, assigned, activity…) POSTed as signed
--        JSON to the other system, queued and retried.

-- The lead's id in the other system, so both sides recognise the same person.
ALTER TABLE contacts ADD COLUMN external_id VARCHAR(100) NULL AFTER prev_status;
ALTER TABLE contacts ADD COLUMN external_system VARCHAR(40) NULL AFTER external_id;
CREATE INDEX idx_contacts_external ON contacts (client_id, external_id);

CREATE TABLE IF NOT EXISTS crm_api_keys (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NOT NULL,
    name          VARCHAR(80)  NOT NULL,
    prefix        VARCHAR(16)  NOT NULL,                 -- shown, to tell keys apart
    key_hash      CHAR(64)     NOT NULL,                 -- sha256 of the whole key; the key itself is shown once
    access        VARCHAR(10)  NOT NULL DEFAULT 'read',  -- read | write
    system        VARCHAR(40)  NULL,                     -- the other system's name, stamped on what it creates
    last_used_at  DATETIME     NULL,
    window_start  DATETIME     NULL,                     -- simple rate limit: requests per minute
    window_count  INT          NOT NULL DEFAULT 0,
    created_by    INT          NULL,
    created_at    DATETIME     NOT NULL,
    revoked_at    DATETIME     NULL,
    UNIQUE KEY uq_key_hash (key_hash),
    KEY idx_client (client_id),
    CONSTRAINT fk_api_keys_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_webhooks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NOT NULL,
    name          VARCHAR(80)  NOT NULL,
    url           VARCHAR(500) NOT NULL,
    secret_enc    TEXT         NOT NULL,                 -- signs every delivery (HMAC-SHA256)
    events        VARCHAR(500) NOT NULL,                 -- comma list, or * for all
    skip_api      TINYINT(1)   NOT NULL DEFAULT 1,       -- don't echo back changes that came in through the API
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    fail_streak   INT          NOT NULL DEFAULT 0,
    last_status   VARCHAR(255) NULL,
    last_at       DATETIME     NULL,
    created_by    INT          NULL,
    created_at    DATETIME     NOT NULL,
    KEY idx_client (client_id),
    CONSTRAINT fk_webhooks_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_webhook_deliveries (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    webhook_id    INT          NOT NULL,
    client_id     INT          NOT NULL,
    event         VARCHAR(40)  NOT NULL,
    contact_id    INT          NULL,
    payload       MEDIUMTEXT   NOT NULL,
    status        VARCHAR(10)  NOT NULL DEFAULT 'queued', -- queued | sending | sent | failed
    attempts      INT          NOT NULL DEFAULT 0,
    next_at       DATETIME     NOT NULL,
    http_code     INT          NULL,
    response      VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL,
    sent_at       DATETIME     NULL,
    KEY idx_due (status, next_at),
    KEY idx_hook (webhook_id, id),
    CONSTRAINT fk_deliveries_hook FOREIGN KEY (webhook_id) REFERENCES crm_webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_inbound_hooks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NOT NULL,
    name          VARCHAR(80)  NOT NULL,
    token_hash    CHAR(64)     NOT NULL,                 -- the URL's secret part, hashed
    token_hint    VARCHAR(12)  NOT NULL,
    token_enc     TEXT         NULL,                     -- the same token, encrypted, so an Admin can see the URL again
    mapping       TEXT         NULL,                     -- JSON: our field => their field (dot path)
    system        VARCHAR(40)  NULL,
    owner_rule    VARCHAR(20)  NOT NULL DEFAULT 'auto',  -- auto | none | user id
    stage_id      INT          NULL,
    update_existing TINYINT(1) NOT NULL DEFAULT 1,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    received      INT          NOT NULL DEFAULT 0,
    last_at       DATETIME     NULL,
    last_payload  MEDIUMTEXT   NULL,                     -- the last thing received, to map fields from
    last_result   VARCHAR(255) NULL,
    created_by    INT          NULL,
    created_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_token (token_hash),
    KEY idx_client (client_id),
    CONSTRAINT fk_inbound_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
