-- Sending from a salesperson's own WhatsApp, and choosing per person how they send.
--
-- The personal-number channel used to be one per account (clients.personal_*). A salesperson who
-- messages leads from their own phone needs their own linked session: their own gateway instance,
-- their own webhook secret (so replies to THEIR number come back to THEM), their own status.
-- Column names mirror the clients.personal_* ones they stand in for; pw_store() maps between them.

CREATE TABLE IF NOT EXISTS user_channels (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT          NOT NULL,
    client_id        INT          NOT NULL,
    instance         VARCHAR(64)  NULL,
    status           VARCHAR(16)  NOT NULL DEFAULT 'disconnected',
    msisdn           VARCHAR(20)  NULL,
    hook_secret      VARCHAR(64)  NULL,
    hook_secret_prev VARCHAR(64)  NULL,
    hook_rotated_at  DATETIME     NULL,
    connected_at     DATETIME     NULL,
    created_at       DATETIME     NOT NULL,
    UNIQUE KEY uq_user (user_id),
    KEY idx_hook (hook_secret),
    KEY idx_hook_prev (hook_secret_prev),
    CONSTRAINT fk_user_channels_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_user_channels_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- How this person's messages go out. NULL = the account's own channel, which is how every existing
-- user behaves today, so nothing changes until an Admin chooses otherwise.
--   api               the WhatsApp Business API number
--   company_personal  the company's linked personal number
--   own               their own linked number (user_channels)
--   none              they may read but not send
ALTER TABLE users ADD COLUMN send_via VARCHAR(20) NULL AFTER modules;

-- Who sent each message and through what — so when a customer asks "who messaged me from this
-- number", the answer is on the message rather than in someone's memory.
ALTER TABLE messages ADD COLUMN sent_by_user_id INT NULL AFTER template_id;
ALTER TABLE messages ADD COLUMN via VARCHAR(20) NULL AFTER sent_by_user_id;
