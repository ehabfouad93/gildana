-- Idempotent sending: make a crash mid-send unable to re-send or re-charge.
--
-- Before this, a worker that died after Meta accepted a message but before the status
-- UPDATE left the row in 'sending'; the reclaim sweep put it back to 'queued' five
-- minutes later and it was sent AND billed a second time. Nothing recorded that an
-- attempt had ever been made, so there was no way to tell "never sent" from
-- "sent, outcome unknown".

ALTER TABLE campaign_messages
    ADD COLUMN attempt_count   INT       NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN next_attempt_at DATETIME  NULL     DEFAULT NULL AFTER attempt_count,
    ADD COLUMN claimed_at      DATETIME  NULL     DEFAULT NULL AFTER next_attempt_at,
    ADD COLUMN claimed_by      VARCHAR(40) NULL   DEFAULT NULL AFTER claimed_at,
    ADD COLUMN credit_txn_id   BIGINT    NULL     DEFAULT NULL AFTER claimed_by;

-- Retry scheduling and the reclaim sweep both scan on these.
ALTER TABLE campaign_messages
    ADD KEY idx_retry (status, next_attempt_at),
    ADD KEY idx_claimed (status, claimed_at);

-- The bug this migration fixes may already have written duplicate wamids, which would
-- make the unique index below fail to build. Clear the later copies first: keep the
-- lowest id (the original send) and null the rest, so history is preserved as rows but
-- the id points at one message only.
UPDATE campaign_messages m
  JOIN (SELECT wa_message_id, MIN(id) AS keep_id
          FROM campaign_messages
         WHERE wa_message_id IS NOT NULL AND wa_message_id <> ''
         GROUP BY wa_message_id
        HAVING COUNT(*) > 1) d
    ON d.wa_message_id = m.wa_message_id AND m.id <> d.keep_id
   SET m.wa_message_id = NULL;

-- Empty strings would all collide with each other under a unique index.
UPDATE campaign_messages SET wa_message_id = NULL WHERE wa_message_id = '';

-- One Meta message id can only ever belong to one row. NULLs don't collide, so
-- unsent rows are unaffected. This is the hard backstop: even if every other guard
-- fails, recording the same wamid twice becomes impossible.
ALTER TABLE campaign_messages
    DROP INDEX idx_wamid,
    ADD UNIQUE KEY uq_wamid (wa_message_id);

-- One row per real API call. Written BEFORE the send, so a process that dies at any
-- point still leaves evidence the attempt happened.
--
-- outcome:
--   ok      — the API returned success and we recorded the wamid
--   failed  — the API returned a definite error; safe to retry if transient
--   unknown — the call was made but the result never came back (crash, timeout).
--             NEVER retried automatically: the message may well have been delivered.
--             Surfaced for human review instead.
CREATE TABLE IF NOT EXISTS send_attempts (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_message_id BIGINT       NOT NULL,
    client_id           INT          NOT NULL,
    attempt_no          INT          NOT NULL,
    outcome             ENUM('ok','failed','unknown') NOT NULL DEFAULT 'unknown',
    wa_message_id       VARCHAR(128) NULL,
    error_code          VARCHAR(32)  NULL,
    error_title         VARCHAR(255) NULL,
    started_at          DATETIME     NOT NULL,
    finished_at         DATETIME     NULL,
    UNIQUE KEY uq_attempt (campaign_message_id, attempt_no),
    KEY idx_client_outcome (client_id, outcome),
    CONSTRAINT fk_attempt_msg FOREIGN KEY (campaign_message_id)
        REFERENCES campaign_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
