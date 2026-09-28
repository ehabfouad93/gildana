-- Meta Lead Ads: leads from Facebook and Instagram instant forms, straight into the CRM.
--
-- A client connects their Facebook Page once; after that every form submission arrives by
-- webhook, is fetched with the Page's token, and becomes an assigned lead. A poller re-reads each
-- form every few minutes as a safety net, so a webhook Meta never delivered does not lose a lead.

CREATE TABLE IF NOT EXISTS meta_oauth_state (
    state      VARCHAR(64) NOT NULL PRIMARY KEY,
    client_id  INT         NOT NULL,
    user_id    INT         NULL,
    created_at DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meta_pages (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NOT NULL,
    page_id        VARCHAR(40)  NOT NULL,
    name           VARCHAR(190) NOT NULL DEFAULT '',
    -- A Page token derived from a long-lived user token does not expire on a timer; it stops
    -- working only if the person loses admin rights or removes the app. Encrypted at rest.
    token_enc      TEXT         NULL,
    subscribed     TINYINT(1)   NOT NULL DEFAULT 0,
    -- 1 = import from every form on the Page, including ones created later.
    all_forms      TINYINT(1)   NOT NULL DEFAULT 1,
    owner_rule     VARCHAR(16)  NOT NULL DEFAULT 'auto',     -- auto | none | <user id>
    stage_id       INT          NULL,
    connected_at   DATETIME     NOT NULL,
    last_error     VARCHAR(255) NULL,
    UNIQUE KEY uq_client_page (client_id, page_id),
    KEY idx_page (page_id),
    CONSTRAINT fk_meta_pages_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meta_forms (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NOT NULL,
    page_id        VARCHAR(40)  NOT NULL,
    form_id        VARCHAR(40)  NOT NULL,
    name           VARCHAR(190) NOT NULL DEFAULT '',
    enabled        TINYINT(1)   NOT NULL DEFAULT 1,
    owner_rule     VARCHAR(16)  NULL,                        -- NULL = use the Page's rule
    stage_id       INT          NULL,
    last_polled_at DATETIME     NULL,
    UNIQUE KEY uq_client_form (client_id, form_id),
    CONSTRAINT fk_meta_forms_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every lead Meta told us about, and what became of it.
--
-- This is also the dedupe: the unique key on (client, leadgen_id) is claimed BEFORE the lead is
-- processed, so the webhook and the poller can both see the same lead at the same moment and only
-- one of them will import it. It is keyed on the lead, not stored on the contact, because one
-- person can fill in several forms — each is a real submission and must not be dropped as a
-- duplicate of the first.
CREATE TABLE IF NOT EXISTS meta_lead_log (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT          NOT NULL,
    page_id     VARCHAR(40)  NULL,
    form_id     VARCHAR(40)  NULL,
    leadgen_id  VARCHAR(40)  NOT NULL,
    via         VARCHAR(8)   NOT NULL,                       -- webhook | poll
    outcome     VARCHAR(12)  NOT NULL,                       -- processing | imported | skipped | error
    detail      VARCHAR(255) NULL,
    contact_id  INT          NULL,
    created_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_client_lead (client_id, leadgen_id),
    KEY idx_client_time (client_id, created_at),
    CONSTRAINT fk_meta_lead_log_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
