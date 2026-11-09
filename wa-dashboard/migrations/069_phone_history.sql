-- Past chats of a linked phone, brought in when it links (and again on demand from Settings).
-- One row per import of one line (main phone, an extra phone, a salesperson's own phone).
CREATE TABLE IF NOT EXISTS pw_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    line        VARCHAR(12)  NOT NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'queued',   -- queued | running | done | failed
    page        INT          NOT NULL DEFAULT 1,           -- next page of the gateway's stored messages
    pages       INT          NULL,
    imported    INT          NOT NULL DEFAULT 0,
    skipped     INT          NOT NULL DEFAULT 0,
    tries       INT          NOT NULL DEFAULT 0,
    error       VARCHAR(255) NULL,
    next_at     DATETIME     NOT NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL,
    KEY idx_pw_history_due (status, next_at),
    KEY idx_pw_history_line (client_id, line),
    CONSTRAINT fk_pw_history_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The Inbox lists conversations by when their last message happened (not by row id): past chats
-- are stored after newer ones, and must not jump to the top.
CREATE INDEX IF NOT EXISTS idx_msg_thread_time ON messages (client_id, contact_id, created_at);
CREATE INDEX IF NOT EXISTS idx_msg_line_time ON messages (client_id, contact_id, line, created_at);
