-- ─────────────────────────────────────────────
-- Web Push: notify a client's phone when a customer replies.
-- ─────────────────────────────────────────────

-- One row per subscribed browser/device. Deleted when the push service reports the
-- endpoint is gone (404/410) or the user turns notifications off.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    user_id     INT          NULL,
    endpoint    VARCHAR(500) NOT NULL,
    endpoint_hash CHAR(64)   NOT NULL,          -- sha256(endpoint): endpoints are too long to index
    p256dh      VARCHAR(255) NOT NULL DEFAULT '',
    auth        VARCHAR(255) NOT NULL DEFAULT '',
    user_agent  VARCHAR(255) NOT NULL DEFAULT '',
    fail_count  INT          NOT NULL DEFAULT 0,
    last_ok_at  DATETIME     NULL,
    created_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_endpoint (endpoint_hash),
    KEY idx_client (client_id),
    CONSTRAINT fk_push_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small key/value store. Needed because the VAPID keypair is generated from the browser
-- (the client deploys by uploading a ZIP and never edits config.php). The private key is
-- stored encrypted via encrypt_secret(), like the WhatsApp and AI keys.
CREATE TABLE IF NOT EXISTS app_settings (
    k          VARCHAR(64) NOT NULL PRIMARY KEY,
    v          TEXT        NULL,
    updated_at DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pending "this client has new messages" markers. ONE row per client (client_id is the
-- primary key), so a burst of inbound replies collapses into a single notification
-- instead of one per message. The worker drains it.
CREATE TABLE IF NOT EXISTS push_outbox (
    client_id INT      NOT NULL PRIMARY KEY,
    queued_at DATETIME NOT NULL,
    CONSTRAINT fk_pout_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
