-- ─────────────────────────────────────────────
-- Gildana WhatsApp Dashboard — initial schema
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS clients (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(160) NOT NULL,
    sender_display    VARCHAR(160) NOT NULL DEFAULT '',
    default_country   VARCHAR(8)   NOT NULL DEFAULT '',
    app_id            VARCHAR(64)  NOT NULL DEFAULT '',
    phone_number_id   VARCHAR(64)  NOT NULL DEFAULT '',
    waba_id           VARCHAR(64)  NOT NULL DEFAULT '',
    access_token_enc  TEXT         NULL,
    app_secret_enc    TEXT         NULL,
    token_updated_at  DATETIME     NULL,
    credits_balance   INT          NOT NULL DEFAULT 0,
    status            VARCHAR(16)  NOT NULL DEFAULT 'active',
    created_at        DATETIME     NOT NULL,
    UNIQUE KEY uq_phone_number_id (phone_number_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NULL,
    email          VARCHAR(190) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           VARCHAR(16)  NOT NULL DEFAULT 'client', -- 'admin' | 'client'
    status         VARCHAR(16)  NOT NULL DEFAULT 'active',
    created_at     DATETIME     NOT NULL,
    UNIQUE KEY uq_email (email),
    KEY idx_client (client_id),
    CONSTRAINT fk_users_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NOT NULL,
    phone_e164     VARCHAR(20)  NOT NULL,
    name           VARCHAR(160) NOT NULL DEFAULT '',
    attributes     JSON         NULL,
    opt_in_status  VARCHAR(10)  NOT NULL DEFAULT 'in', -- 'in' | 'out' | 'unknown'
    opted_out_at   DATETIME     NULL,
    source         VARCHAR(32)  NOT NULL DEFAULT 'manual',
    created_at     DATETIME     NOT NULL,
    UNIQUE KEY uq_client_phone (client_id, phone_e164),
    KEY idx_client_optin (client_id, opt_in_status),
    CONSTRAINT fk_contacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_lists (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    name        VARCHAR(160) NOT NULL,
    created_at  DATETIME     NOT NULL,
    KEY idx_client (client_id),
    CONSTRAINT fk_lists_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_list_members (
    list_id     INT NOT NULL,
    contact_id  INT NOT NULL,
    PRIMARY KEY (list_id, contact_id),
    KEY idx_contact (contact_id),
    CONSTRAINT fk_clm_list    FOREIGN KEY (list_id)    REFERENCES contact_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_clm_contact FOREIGN KEY (contact_id) REFERENCES contacts(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS templates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT          NOT NULL,
    wa_name         VARCHAR(190) NOT NULL,
    language        VARCHAR(16)  NOT NULL DEFAULT 'en',
    category        VARCHAR(32)  NOT NULL DEFAULT '',
    status          VARCHAR(24)  NOT NULL DEFAULT '',
    components      JSON         NULL,
    body_text       TEXT         NULL,
    variable_count  INT          NOT NULL DEFAULT 0,
    synced_at       DATETIME     NULL,
    UNIQUE KEY uq_client_name_lang (client_id, wa_name, language),
    KEY idx_client (client_id),
    CONSTRAINT fk_templates_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NOT NULL,
    name          VARCHAR(190) NOT NULL,
    template_id   INT          NULL,
    list_id       INT          NULL,
    variable_map  JSON         NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'draft',
        -- draft | scheduled | queued | sending | paused | completed | failed | canceled
    scheduled_at  DATETIME     NULL,
    total_count   INT          NOT NULL DEFAULT 0,
    sent_count    INT          NOT NULL DEFAULT 0,
    delivered_count INT        NOT NULL DEFAULT 0,
    read_count    INT          NOT NULL DEFAULT 0,
    failed_count  INT          NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL,
    started_at    DATETIME     NULL,
    completed_at  DATETIME     NULL,
    KEY idx_client (client_id),
    KEY idx_status_sched (status, scheduled_at),
    CONSTRAINT fk_campaigns_client   FOREIGN KEY (client_id)   REFERENCES clients(id)       ON DELETE CASCADE,
    CONSTRAINT fk_campaigns_template FOREIGN KEY (template_id) REFERENCES templates(id)     ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_list     FOREIGN KEY (list_id)     REFERENCES contact_lists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_messages (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id         INT          NOT NULL,
    client_id           INT          NOT NULL,
    contact_id          INT          NULL,
    phone_e164          VARCHAR(20)  NOT NULL,
    rendered_components  JSON        NULL,
    status              VARCHAR(12)  NOT NULL DEFAULT 'queued',
        -- queued | sending | sent | delivered | read | failed
    wa_message_id       VARCHAR(128) NULL,
    error_code          VARCHAR(32)  NULL,
    error_title         VARCHAR(255) NULL,
    sent_at             DATETIME     NULL,
    delivered_at        DATETIME     NULL,
    read_at             DATETIME     NULL,
    updated_at          DATETIME     NULL,
    KEY idx_campaign_status (campaign_id, status),
    KEY idx_status (status),
    KEY idx_wamid (wa_message_id),
    CONSTRAINT fk_cm_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_client   FOREIGN KEY (client_id)   REFERENCES clients(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credit_transactions (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NOT NULL,
    delta         INT          NOT NULL,
    balance_after INT          NOT NULL,
    reason        VARCHAR(64)  NOT NULL DEFAULT '',
    campaign_id   INT          NULL,
    created_at    DATETIME     NOT NULL,
    KEY idx_client (client_id, id),
    CONSTRAINT fk_ct_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_events (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_key    VARCHAR(190) NULL,
    payload      JSON         NULL,
    received_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_event_key (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
