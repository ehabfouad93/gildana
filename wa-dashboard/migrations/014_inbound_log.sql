-- Why an incoming message did or did not get an answer.
--
-- The engine has several silent exits — a human has taken over, the message carried no text
-- (a photo or voice note with no caption), nothing matched and there is no catch-all flow,
-- the conversation already ended. From the outside all of them look identical: the bot simply
-- says nothing, with no way to tell which happened or whether it is even a fault.
--
-- One row per inbound, recording the decision, so "it sometimes doesn't reply" becomes a
-- question with an answer.
CREATE TABLE IF NOT EXISTS inbound_log (
    id         BIGINT       NOT NULL AUTO_INCREMENT,
    client_id  INT          NOT NULL,
    contact_id INT          NULL,
    body       VARCHAR(200) NULL,      -- trimmed: this is a diagnostic, not a second Inbox
    decision   VARCHAR(32)  NOT NULL,  -- replied | no_flow | bot_paused | no_text | …
    detail     VARCHAR(255) NULL,      -- flow name, gateway error, whatever explains it
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_inbound_log_client (client_id, id),
    CONSTRAINT fk_inbound_log_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
