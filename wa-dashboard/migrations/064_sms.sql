-- SMS: provider gateways (the platform's and clients' own), every SMS sent, and per-client settings.

CREATE TABLE IF NOT EXISTS sms_gateways (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NULL,                      -- NULL = a platform gateway; else that client's own
    provider      VARCHAR(20)  NOT NULL,                  -- smsmisr | victorylink | twilio | mshastra | http
    name          VARCHAR(80)  NOT NULL,
    config_enc    TEXT         NULL,                      -- encrypted JSON: credentials, URLs, field mapping
    default_sender VARCHAR(40) NULL,
    senders       VARCHAR(500) NULL,                      -- comma list of sender names this gateway may use
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    is_default    TINYINT(1)   NOT NULL DEFAULT 0,
    rate_per_sec  INT          NOT NULL DEFAULT 10,
    dlr_token     CHAR(32)     NOT NULL,
    last_test_at  DATETIME     NULL,
    last_test_ok  TINYINT(1)   NULL,
    last_test_msg VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NULL,
    KEY idx_sms_gw_client (client_id, active),
    CONSTRAINT fk_sms_gw_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE clients ADD COLUMN sms_mode VARCHAR(10) NOT NULL DEFAULT 'platform';   -- platform | own
ALTER TABLE clients ADD COLUMN sms_gateway_id INT NULL;                            -- which platform gateway (NULL = the default)
ALTER TABLE clients ADD COLUMN sms_senders VARCHAR(500) NULL;                      -- sender names approved for this client
ALTER TABLE clients ADD COLUMN sms_rate INT NOT NULL DEFAULT 1;                    -- credits per SMS part

CREATE TABLE IF NOT EXISTS sms_messages (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id           INT          NOT NULL,
    contact_id          INT          NULL,
    to_e164             VARCHAR(20)  NOT NULL,
    sender              VARCHAR(40)  NULL,
    body                TEXT         NOT NULL,
    parts               INT          NOT NULL DEFAULT 1,
    encoding            VARCHAR(5)   NOT NULL DEFAULT 'gsm',   -- gsm | ucs2
    gateway_id          INT          NULL,
    provider_msg_id     VARCHAR(120) NULL,
    status              VARCHAR(12)  NOT NULL DEFAULT 'queued', -- queued | sending | sent | delivered | failed | undelivered | skipped
    error_code          VARCHAR(40)  NULL,
    error_title         VARCHAR(255) NULL,
    attempts            INT          NOT NULL DEFAULT 0,
    next_attempt_at     DATETIME     NULL,
    credits             INT          NOT NULL DEFAULT 0,
    source              VARCHAR(12)  NOT NULL DEFAULT 'manual', -- campaign | api | crm | automation | alert | test | manual
    campaign_id         INT          NULL,
    campaign_message_id BIGINT       NULL,
    api_key_id          INT          NULL,
    user_id             INT          NULL,
    reference           VARCHAR(120) NULL,                      -- the client's own id, from the API
    callback_url        VARCHAR(500) NULL,
    claimed_by          VARCHAR(40)  NULL,
    scheduled_at        DATETIME     NULL,
    sent_at             DATETIME     NULL,
    delivered_at        DATETIME     NULL,
    created_at          DATETIME     NOT NULL,
    updated_at          DATETIME     NULL,
    KEY idx_sms_client_time (client_id, created_at),
    KEY idx_sms_queue (status, scheduled_at, next_attempt_at),
    KEY idx_sms_provider_id (provider_msg_id),
    KEY idx_sms_reference (client_id, reference),
    KEY idx_sms_contact (contact_id),
    CONSTRAINT fk_sms_msg_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opting out of SMS is separate from WhatsApp.
ALTER TABLE contacts ADD COLUMN sms_opt_out_at DATETIME NULL;

-- API keys say what they may do: the CRM, SMS, or both. Existing keys stay CRM keys.
ALTER TABLE crm_api_keys ADD COLUMN scopes VARCHAR(40) NOT NULL DEFAULT 'crm' AFTER access;

-- "Send by" for the CRM's messages and alerts: whatsapp | sms | wa_then_sms | both.
ALTER TABLE crm_settings ADD COLUMN staff_send_by TEXT NULL;     -- JSON: alert kind → channel

-- Stage messages, follow-up steps and their queue can go by SMS (own text) or fall back to it.
ALTER TABLE crm_stage_msgs MODIFY template_id INT NULL;
ALTER TABLE crm_stage_msgs ADD COLUMN send_by VARCHAR(12) NOT NULL DEFAULT 'whatsapp' AFTER template_id;
ALTER TABLE crm_stage_msgs ADD COLUMN sms_text TEXT NULL AFTER send_by;
ALTER TABLE crm_seq_steps MODIFY template_id INT NULL;
ALTER TABLE crm_seq_steps ADD COLUMN send_by VARCHAR(12) NOT NULL DEFAULT 'whatsapp' AFTER template_id;
ALTER TABLE crm_seq_steps ADD COLUMN sms_text TEXT NULL AFTER send_by;
ALTER TABLE crm_msg_queue MODIFY template_id INT NULL;
ALTER TABLE crm_msg_queue ADD COLUMN send_by VARCHAR(12) NOT NULL DEFAULT 'whatsapp' AFTER template_id;
ALTER TABLE crm_msg_queue ADD COLUMN sms_text TEXT NULL AFTER send_by;

