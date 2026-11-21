-- Website chat: an AI Chat Agent on the client's own website and landing pages, through a
-- chat bubble (one script line), an iframe, or the JSON API. Each widget has its own look and
-- words, so every landing page can have its own; the chats land in the Inbox like any channel.

CREATE TABLE IF NOT EXISTS web_widgets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT          NOT NULL,
    flow_id         INT          NULL,                    -- the AI Chat Agent that answers
    name            VARCHAR(120) NOT NULL,                -- for the client only ("Porto Golf landing")
    public_key      VARCHAR(40)  NOT NULL,                -- in the embed code; not a secret
    settings        TEXT         NULL,                    -- JSON: title, colours, welcome, quick actions…
    allowed_domains TEXT         NULL,                    -- one per line; empty = any site
    status          VARCHAR(10)  NOT NULL DEFAULT 'active', -- active | paused
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    UNIQUE KEY uq_widget_key (public_key),
    KEY idx_widget_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A website visitor: no phone number until they give one. web_vid is the random id the
-- visitor's browser keeps; it is what lets them carry on the same conversation.
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS web_vid VARCHAR(40) NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS web_widget_id INT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS web_last_in_at DATETIME NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS web_page VARCHAR(500) NULL;   -- the page they last wrote from
CREATE UNIQUE INDEX IF NOT EXISTS uq_client_webvid ON contacts (client_id, web_vid);

-- Choices sent with a message on the website (buttons from an automation step), by message ext_id.
CREATE TABLE IF NOT EXISTS web_message_extras (
    ext_id      VARCHAR(64)  NOT NULL PRIMARY KEY,
    data        TEXT         NOT NULL,
    created_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
