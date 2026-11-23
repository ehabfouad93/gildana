-- Which campaign each Meta ad belongs to. A click-to-WhatsApp message carries only the ad's id,
-- so an automation set to "this whole campaign" looks the ad up here (filled when ads are picked
-- in the automation's ad picker, or fetched from Meta the first time an unknown ad sends someone).
CREATE TABLE IF NOT EXISTS meta_ad_map (
    client_id     INT           NOT NULL,
    ad_id         VARCHAR(40)   NOT NULL,
    campaign_id   VARCHAR(40)   NOT NULL DEFAULT '',     -- '' = looked up, Meta did not say
    campaign_name VARCHAR(190)  NULL,
    ad_name       VARCHAR(190)  NULL,
    account_id    VARCHAR(40)   NULL,
    updated_at    DATETIME      NOT NULL,
    PRIMARY KEY (client_id, ad_id),
    KEY idx_ad_map_campaign (client_id, campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
