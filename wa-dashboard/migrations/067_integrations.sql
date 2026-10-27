-- Connected apps: built-in connections to Shopify, Zoho CRM, Zoho Books and Zoho Inventory.

-- One connected account per row (a client may connect more than one store or Zoho org).
CREATE TABLE IF NOT EXISTS integrations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NOT NULL,
    provider       VARCHAR(20)  NOT NULL,                  -- shopify | zoho_crm | zoho_books | zoho_inventory
    account        VARCHAR(190) NOT NULL,                  -- shop domain (x.myshopify.com) or Zoho org id / user
    display_name   VARCHAR(190) NULL,                      -- the store's or org's own name
    data_center    VARCHAR(10)  NULL,                      -- Zoho: com | eu | in | com.au | jp | ca | sa
    auth_mode      VARCHAR(8)   NOT NULL DEFAULT 'oauth',  -- oauth | key
    tokens_enc     TEXT         NULL,                      -- encrypted JSON: access, refresh, expires_at, client id/secret (keys mode)
    webhook_secret_enc TEXT     NULL,                      -- encrypted secret used to verify incoming events
    hook_token     VARCHAR(64)  NULL,                      -- random token in our webhook URL (Zoho)
    settings       MEDIUMTEXT   NULL,                      -- JSON: what syncs, mappings, message rules
    status         VARCHAR(12)  NOT NULL DEFAULT 'active', -- active | paused | error | disconnected
    last_error     VARCHAR(500) NULL,
    last_sync_at   DATETIME     NULL,
    last_event_at  DATETIME     NULL,
    cursor_data    TEXT         NULL,                      -- JSON: per-feed "modified since" markers for polling
    connected_by   INT          NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NULL,
    UNIQUE KEY uq_integration (client_id, provider, account),
    KEY idx_integration_provider (provider, status),
    KEY idx_integration_hook (hook_token),
    CONSTRAINT fk_integrations_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outside record <-> Revenect contact. One contact can be a Shopify customer and a Zoho lead at once.
CREATE TABLE IF NOT EXISTS integration_links (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    integration_id INT          NOT NULL,
    client_id      INT          NOT NULL,
    kind           VARCHAR(16)  NOT NULL,                  -- customer | lead | contact | deal | account
    ext_id         VARCHAR(100) NOT NULL,
    contact_id     INT          NULL,
    sync_hash      CHAR(40)     NULL,                      -- what we last sent / received, so echoes are ignored
    data           TEXT         NULL,                      -- small JSON extras (deal stage, amount…)
    updated_at     DATETIME     NOT NULL,
    UNIQUE KEY uq_link (integration_id, kind, ext_id),
    KEY idx_link_contact (contact_id, kind),
    CONSTRAINT fk_links_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orders, checkouts, invoices and sales orders from connected apps.
CREATE TABLE IF NOT EXISTS ext_orders (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NOT NULL,
    integration_id INT          NOT NULL,
    contact_id     INT          NULL,
    source         VARCHAR(20)  NOT NULL,                  -- shopify | zoho_books | zoho_inventory
    kind           VARCHAR(12)  NOT NULL,                  -- order | checkout | invoice | salesorder
    ext_id         VARCHAR(100) NOT NULL,
    number         VARCHAR(60)  NULL,
    status         VARCHAR(30)  NULL,                      -- our words: placed | paid | shipped | delivered | cancelled | refunded | open | abandoned | recovered | sent | overdue | void
    financial      VARCHAR(30)  NULL,                      -- the app's own payment status
    fulfillment    VARCHAR(30)  NULL,                      -- the app's own delivery status
    total          DECIMAL(14,2) NULL,
    currency       VARCHAR(8)   NULL,
    items          TEXT         NULL,                      -- "2 × Blue shirt, 1 × Cap"
    phone          VARCHAR(40)  NULL,
    email          VARCHAR(190) NULL,
    name           VARCHAR(150) NULL,
    tracking_url   VARCHAR(500) NULL,
    link_url       VARCHAR(700) NULL,                      -- order status page / checkout recovery URL / invoice link
    notified       VARCHAR(255) NULL,                      -- which events already messaged (comma list) — never twice
    reminders      TINYINT      NOT NULL DEFAULT 0,        -- abandoned-cart reminders sent
    recovered_order_id BIGINT   NULL,
    placed_at      DATETIME     NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    UNIQUE KEY uq_ext_order (integration_id, kind, ext_id),
    KEY idx_ext_orders_contact (contact_id, placed_at),
    KEY idx_ext_orders_client (client_id, kind, status, placed_at),
    CONSTRAINT fk_ext_orders_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Calls to make to a connected app, retried until they work (or give up and say why).
CREATE TABLE IF NOT EXISTS integration_jobs (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    integration_id INT          NOT NULL,
    client_id      INT          NOT NULL,
    kind           VARCHAR(30)  NOT NULL,                  -- push_contact | push_deal | push_note | register_webhooks | backfill | …
    ref_id         BIGINT       NULL,                      -- contact id, order id…
    payload        MEDIUMTEXT   NULL,
    status         VARCHAR(10)  NOT NULL DEFAULT 'queued', -- queued | done | failed
    attempts       INT          NOT NULL DEFAULT 0,
    next_at        DATETIME     NOT NULL,
    last_error     VARCHAR(500) NULL,
    created_at     DATETIME     NOT NULL,
    done_at        DATETIME     NULL,
    KEY idx_jobs_due (status, next_at),
    KEY idx_jobs_dedupe (integration_id, kind, ref_id, status),
    CONSTRAINT fk_jobs_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What happened, for the Log tab (kept short: the newest few hundred per integration).
CREATE TABLE IF NOT EXISTS integration_log (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    integration_id INT          NOT NULL,
    client_id      INT          NOT NULL,
    direction      VARCHAR(4)   NOT NULL,                  -- in | out
    event          VARCHAR(60)  NOT NULL,
    summary        VARCHAR(300) NULL,
    ok             TINYINT(1)   NOT NULL DEFAULT 1,
    event_key      VARCHAR(100) NULL,                      -- the app's own event id, to drop repeats
    created_at     DATETIME     NOT NULL,
    KEY idx_log_integration (integration_id, id),
    UNIQUE KEY uq_log_event (integration_id, event_key),
    CONSTRAINT fk_log_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-use OAuth state for Shopify and Zoho connect.
CREATE TABLE IF NOT EXISTS integration_oauth_state (
    state          CHAR(48)     NOT NULL PRIMARY KEY,
    client_id      INT          NOT NULL,
    user_id        INT          NULL,
    provider       VARCHAR(20)  NOT NULL,
    extra          VARCHAR(255) NULL,                      -- shop domain / data centre
    created_at     DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
