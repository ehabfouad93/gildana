-- Facebook Messenger, Instagram DMs, Facebook & Instagram comments — in the same Inbox,
-- automations, AI agent, campaigns and CRM as WhatsApp.

-- Which channels the platform admin offers this client. NULL = WhatsApp only (every client
-- that existed before this keeps exactly what it had).
ALTER TABLE clients ADD COLUMN channels VARCHAR(255) NULL AFTER modules;

-- A person who writes on Messenger or Instagram has no phone number until they give one.
ALTER TABLE contacts MODIFY phone_e164 VARCHAR(20) NULL;
ALTER TABLE contacts ADD COLUMN fb_psid VARCHAR(40) NULL AFTER wa_jid;
ALTER TABLE contacts ADD COLUMN ig_sid VARCHAR(40) NULL AFTER fb_psid;
ALTER TABLE contacts ADD COLUMN ig_username VARCHAR(80) NULL AFTER ig_sid;
ALTER TABLE contacts ADD COLUMN social_avatar VARCHAR(600) NULL AFTER ig_username;
ALTER TABLE contacts ADD COLUMN fb_last_in_at DATETIME NULL AFTER last_inbound_at;    -- Messenger 24-hour window
ALTER TABLE contacts ADD COLUMN ig_last_in_at DATETIME NULL AFTER fb_last_in_at;      -- Instagram 24-hour window
ALTER TABLE contacts ADD COLUMN social_page_id VARCHAR(40) NULL AFTER ig_last_in_at;  -- the Page they wrote to
CREATE UNIQUE INDEX uq_client_psid ON contacts (client_id, fb_psid);
CREATE UNIQUE INDEX uq_client_igsid ON contacts (client_id, ig_sid);

-- Each message says which channel it went over.
ALTER TABLE messages ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT 'whatsapp' AFTER direction;
ALTER TABLE messages ADD COLUMN ext_id VARCHAR(190) NULL AFTER wa_message_id;
CREATE INDEX idx_msg_ext ON messages (ext_id);

-- The connected Page's Instagram account, and what this Page is used for.
ALTER TABLE meta_pages ADD COLUMN ig_user_id VARCHAR(40) NULL AFTER name;
ALTER TABLE meta_pages ADD COLUMN ig_username VARCHAR(80) NULL AFTER ig_user_id;
ALTER TABLE meta_pages ADD COLUMN leads_on TINYINT(1) NOT NULL DEFAULT 1 AFTER subscribed;
ALTER TABLE meta_pages ADD COLUMN msg_on TINYINT(1) NOT NULL DEFAULT 0 AFTER leads_on;
ALTER TABLE meta_pages ADD COLUMN ig_msg_on TINYINT(1) NOT NULL DEFAULT 0 AFTER msg_on;
ALTER TABLE meta_pages ADD COLUMN comments_on TINYINT(1) NOT NULL DEFAULT 0 AFTER ig_msg_on;
ALTER TABLE meta_pages ADD COLUMN ig_comments_on TINYINT(1) NOT NULL DEFAULT 0 AFTER comments_on;
ALTER TABLE meta_pages ADD COLUMN scopes TEXT NULL AFTER token_enc;
CREATE INDEX idx_meta_pages_ig ON meta_pages (ig_user_id);

-- Comments on the client's Facebook posts and Instagram posts / reels.
CREATE TABLE IF NOT EXISTS social_comments (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT          NOT NULL,
    page_id         VARCHAR(40)  NOT NULL,
    platform        VARCHAR(4)   NOT NULL,                 -- fb | ig
    post_id         VARCHAR(80)  NOT NULL,
    post_link       VARCHAR(600) NULL,
    post_text       VARCHAR(600) NULL,
    post_image      VARCHAR(600) NULL,
    comment_id      VARCHAR(80)  NOT NULL,
    parent_id       VARCHAR(80)  NULL,                     -- a reply to another comment
    author_id       VARCHAR(40)  NULL,
    author_name     VARCHAR(160) NULL,
    contact_id      INT          NULL,
    body            TEXT         NULL,
    from_page       TINYINT(1)   NOT NULL DEFAULT 0,       -- written by the business itself
    status          VARCHAR(10)  NOT NULL DEFAULT 'visible', -- visible | hidden | deleted
    replied_public  TINYINT(1)   NOT NULL DEFAULT 0,
    replied_private TINYINT(1)   NOT NULL DEFAULT 0,
    flagged         VARCHAR(40)  NULL,                     -- why moderation acted: keyword:…, phone, link, ai:spam…
    acted_by        VARCHAR(40)  NULL,                     -- rule:<id> | user:<id> | flow:<id>
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    UNIQUE KEY uq_comment (client_id, comment_id),
    KEY idx_comments_feed (client_id, created_at),
    KEY idx_comments_post (client_id, post_id),
    CONSTRAINT fk_social_comments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Comment moderation and simple auto-replies, applied as comments arrive.
CREATE TABLE IF NOT EXISTS social_rules (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    name        VARCHAR(120) NOT NULL DEFAULT '',
    kind        VARCHAR(12)  NOT NULL,                     -- moderate | reply
    platform    VARCHAR(4)   NOT NULL DEFAULT 'both',      -- fb | ig | both
    posts       TEXT         NULL,                         -- comma list of post ids; NULL = every post
    match_kind  VARCHAR(12)  NOT NULL DEFAULT 'keywords',  -- keywords | phone | link | any | ai
    keywords    TEXT         NULL,
    action      VARCHAR(12)  NOT NULL DEFAULT 'hide',      -- hide | delete (moderate)
    reply_text  TEXT         NULL,                         -- public reply (reply rules)
    dm_text     TEXT         NULL,                         -- private reply (reply rules)
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort        INT          NOT NULL DEFAULT 0,
    hits        INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    KEY idx_rules_client (client_id, active, sort),
    CONSTRAINT fk_social_rules_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Messenger Marketing Messages: people who agreed to receive offers, with Meta's token.
CREATE TABLE IF NOT EXISTS social_optins (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    contact_id  INT          NOT NULL,
    page_id     VARCHAR(40)  NOT NULL,
    token       VARCHAR(255) NULL,                         -- notification_messages_token
    topic       VARCHAR(80)  NULL,
    frequency   VARCHAR(12)  NULL,                         -- DAILY | WEEKLY | MONTHLY
    status      VARCHAR(10)  NOT NULL DEFAULT 'asked',     -- asked | active | stopped | expired
    asked_at    DATETIME     NULL,
    opted_in_at DATETIME     NULL,
    expires_at  DATETIME     NULL,
    stopped_at  DATETIME     NULL,
    UNIQUE KEY uq_optin (client_id, contact_id, page_id),
    KEY idx_optins_active (client_id, status),
    CONSTRAINT fk_social_optins_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Messenger / Instagram people become CRM leads automatically, or only when someone adds them.
ALTER TABLE crm_settings ADD COLUMN social_auto_lead TINYINT(1) NOT NULL DEFAULT 0 AFTER staff_wa_stages;
ALTER TABLE crm_settings ADD COLUMN social_lead_stage INT NULL AFTER social_auto_lead;

-- A workflow can start from several triggers, on any channel. The flow's own trigger stays in
-- flows.trigger_type exactly as before; these are the extra ones ("also start when…").
CREATE TABLE IF NOT EXISTS flow_triggers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    flow_id    INT          NOT NULL,
    client_id  INT          NOT NULL,
    kind       VARCHAR(32)  NOT NULL,
    config     TEXT         NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    sort       INT          NOT NULL DEFAULT 0,
    KEY idx_triggers_kind (client_id, kind, active),
    KEY idx_triggers_flow (flow_id),
    CONSTRAINT fk_flow_triggers_flow FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which channel a run talks on, and the comment it answers when it started from one.
ALTER TABLE flow_runs ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT 'whatsapp' AFTER status;
ALTER TABLE flow_runs ADD COLUMN trigger_kind VARCHAR(32) NULL AFTER channel;
ALTER TABLE flow_runs ADD COLUMN comment_id VARCHAR(80) NULL AFTER trigger_kind;

-- What an "Export data" step sent, per flow: viewable and downloadable from the flow.
CREATE TABLE IF NOT EXISTS flow_exports (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    flow_id     INT          NOT NULL,
    step_id     INT          NULL,
    run_id      INT          NULL,
    contact_id  INT          NULL,
    channel     VARCHAR(16)  NULL,
    data        TEXT         NOT NULL,                     -- JSON: label → value, in the order chosen
    sent_to     VARCHAR(120) NULL,                         -- sheet,crm,webhook,email — and how each went
    created_at  DATETIME     NOT NULL,
    KEY idx_exports_flow (flow_id, id),
    CONSTRAINT fk_flow_exports_flow FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Campaigns on Messenger and Instagram.
ALTER TABLE campaigns ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT 'whatsapp' AFTER name;
ALTER TABLE campaigns ADD COLUMN audience_kind VARCHAR(12) NULL AFTER channel;    -- window24 | optin
ALTER TABLE campaigns ADD COLUMN page_id VARCHAR(40) NULL AFTER audience_kind;
ALTER TABLE campaign_messages MODIFY phone_e164 VARCHAR(20) NULL;
ALTER TABLE campaign_messages ADD COLUMN recipient_ext VARCHAR(255) NULL AFTER phone_e164;   -- PSID / IGSID / opt-in token
