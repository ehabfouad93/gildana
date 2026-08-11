-- ─────────────────────────────────────────────
-- 1) Meta media handle cache.
--    Template header media used to be sent as a public `link` on EVERY message, so a
--    bulk campaign made Meta download the same image once per recipient — which throttles
--    on shared hosting and surfaces as #131053 / #130472 media errors that get worse the
--    bigger the send. We now upload the file ONCE to the Cloud API and reuse the returned
--    media id for the whole campaign.
--    Media ids are scoped to a phone number and expire (~30d), so cache per client and
--    treat rows older than 25 days as stale (see wa_resolve_media()).
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS media_cache (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    file_hash   CHAR(64)     NOT NULL,          -- sha256 of the file bytes
    file_url    VARCHAR(500) NOT NULL DEFAULT '',
    media_id    VARCHAR(128) NOT NULL,
    mime        VARCHAR(100) NOT NULL DEFAULT '',
    uploaded_at DATETIME     NOT NULL,
    UNIQUE KEY uq_client_hash (client_id, file_hash),
    KEY idx_client (client_id),
    CONSTRAINT fk_media_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
-- 2) Human handoff / live takeover.
--    When an agent replies by hand in the Inbox the bot pauses for this contact until
--    this timestamp, so the automation can't talk over a human. NULL = bot active.
-- ─────────────────────────────────────────────
ALTER TABLE contacts ADD COLUMN bot_paused_until DATETIME NULL;
