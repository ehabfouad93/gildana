-- Ad spend from Meta, per ad per day — so the reports can say what a lead, a visit and a sale cost.
--   token_enc   the token that reads this ad account: the Facebook login's (60 days) or a pasted
--               System User token (does not expire). Encrypted, never shown back.
CREATE TABLE IF NOT EXISTS meta_ad_accounts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT           NOT NULL,
    account_id     VARCHAR(40)   NOT NULL,              -- act_123…
    name           VARCHAR(190)  NOT NULL DEFAULT '',
    currency       VARCHAR(3)    NULL,
    token_enc      TEXT          NULL,
    token_kind     VARCHAR(8)    NOT NULL DEFAULT 'login', -- login | system
    token_expires  DATETIME      NULL,
    enabled        TINYINT(1)    NOT NULL DEFAULT 1,
    synced_from    DATE          NULL,
    last_synced_at DATETIME      NULL,
    last_error     VARCHAR(255)  NULL,
    created_at     DATETIME      NOT NULL,
    UNIQUE KEY uq_client_account (client_id, account_id),
    CONSTRAINT fk_meta_ad_accounts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meta_ad_spend (
    client_id     INT           NOT NULL,
    account_id    VARCHAR(40)   NOT NULL,
    day           DATE          NOT NULL,
    ad_id         VARCHAR(32)   NOT NULL,
    ad_name       VARCHAR(190)  NULL,
    adset_id      VARCHAR(32)   NULL,
    adset_name    VARCHAR(190)  NULL,
    campaign_id   VARCHAR(32)   NULL,
    campaign_name VARCHAR(190)  NULL,
    spend         DECIMAL(14,2) NOT NULL DEFAULT 0,
    impressions   INT           NOT NULL DEFAULT 0,
    clicks        INT           NOT NULL DEFAULT 0,
    PRIMARY KEY (client_id, day, ad_id),
    KEY idx_campaign (client_id, campaign_id, day),
    CONSTRAINT fk_meta_ad_spend_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE contacts ADD KEY idx_meta_campaign (client_id, meta_campaign_id);
ALTER TABLE contacts ADD KEY idx_meta_ad (client_id, meta_ad_id);
