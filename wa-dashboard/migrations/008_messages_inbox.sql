-- Unified message log powering the live Inbox (WhatsApp-style two-way conversations).
CREATE TABLE IF NOT EXISTS messages (
    id            BIGINT       NOT NULL AUTO_INCREMENT,
    client_id     INT          NOT NULL,
    contact_id    INT          NOT NULL,
    direction     VARCHAR(3)   NOT NULL,                 -- 'in' | 'out'
    type          VARCHAR(16)  NOT NULL DEFAULT 'text',  -- text|image|template|button|interactive|system
    body          TEXT         NULL,
    wa_message_id VARCHAR(128) NULL,
    status        VARCHAR(12)  NULL,                     -- out: sent|delivered|read|failed
    error_title   VARCHAR(255) NULL,
    source        VARCHAR(16)  NULL,                     -- inbound|automation|agent|qualifier|campaign|manual
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_msg_thread (client_id, contact_id, id),
    KEY idx_msg_wamid (wa_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-contact "last opened in inbox" marker → drives unread counts.
ALTER TABLE contacts ADD COLUMN inbox_read_at DATETIME NULL;
