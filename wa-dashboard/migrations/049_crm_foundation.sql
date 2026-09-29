-- The data the leads list, the reports and the dashboards filter and count by.
--
--   code           a short code for the lead (#4DE2F2), so people can talk about a lead without
--                  its phone number. Worked out from the id, so it is unique without a lookup.
--   substatus      the detail under the stage: Contacted → No answer / Phone off / Busy …
--   data_type      fresh (new from an ad, a form, a message) or cold (old data, imported)
--   qualification  qualified / not_qualified — set by the salesperson
--   campaign …     where the lead came from on Meta, for campaign and platform reports
--   custom         the account's own fields, as JSON keyed by the field's key
ALTER TABLE contacts ADD COLUMN code CHAR(6) NULL AFTER id;
ALTER TABLE contacts ADD COLUMN substatus VARCHAR(80) NULL AFTER stage_id;
ALTER TABLE contacts ADD COLUMN data_type VARCHAR(5) NULL AFTER source;
ALTER TABLE contacts ADD COLUMN qualification VARCHAR(16) NULL AFTER substatus;
ALTER TABLE contacts ADD COLUMN platform VARCHAR(16) NULL AFTER data_type;
ALTER TABLE contacts ADD COLUMN campaign VARCHAR(160) NULL AFTER platform;
ALTER TABLE contacts ADD COLUMN meta_campaign_id VARCHAR(32) NULL AFTER campaign;
ALTER TABLE contacts ADD COLUMN adset VARCHAR(160) NULL AFTER meta_campaign_id;
ALTER TABLE contacts ADD COLUMN meta_adset_id VARCHAR(32) NULL AFTER adset;
ALTER TABLE contacts ADD COLUMN ad_name VARCHAR(160) NULL AFTER meta_adset_id;
ALTER TABLE contacts ADD COLUMN meta_ad_id VARCHAR(32) NULL AFTER ad_name;
ALTER TABLE contacts ADD COLUMN custom LONGTEXT NULL AFTER attributes;

UPDATE contacts SET code = LPAD(HEX((id * 10368889) % 16777216), 6, '0') WHERE code IS NULL;
ALTER TABLE contacts ADD KEY idx_client_code (client_id, code);
ALTER TABLE contacts ADD KEY idx_crm_campaign (client_id, campaign);
ALTER TABLE contacts ADD KEY idx_crm_added (client_id, crm_added_at);

-- Everyone already in the pipeline: imported leads are cold data, everything else came in fresh.
UPDATE contacts SET data_type = IF(source IN ('import', 'sheet'), 'cold', 'fresh') WHERE stage_id IS NOT NULL AND data_type IS NULL;
-- Platform, where the source already says it.
UPDATE contacts SET platform = CASE
    WHEN source = 'ctwa' THEN 'whatsapp_ad'
    WHEN source = 'inbound' THEN 'whatsapp'
    WHEN source = 'meta_form' THEN 'facebook'
    WHEN source IN ('import', 'sheet') THEN 'import'
    WHEN source = 'manual' THEN 'manual'
    ELSE NULL END
 WHERE stage_id IS NOT NULL AND platform IS NULL;

-- The detail under each stage. The Lost stage's detail is its lost reason, kept where it is.
CREATE TABLE IF NOT EXISTS crm_substatuses (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    stage_id   INT          NOT NULL,
    label      VARCHAR(80)  NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    KEY idx_stage (client_id, stage_id, sort),
    CONSTRAINT fk_crm_sub_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_sub_stage FOREIGN KEY (stage_id) REFERENCES crm_stages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Teams: a leader sees and can pass around the leads of the people on their team.
CREATE TABLE IF NOT EXISTS crm_teams (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NOT NULL,
    name           VARCHAR(80)  NOT NULL,
    leader_user_id INT          NULL,
    sort           INT          NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL,
    KEY idx_client (client_id),
    CONSTRAINT fk_crm_teams_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE users ADD COLUMN team_id INT NULL AFTER client_role;

-- The account's own fields on a lead: text, number, date, or a pick-list.
CREATE TABLE IF NOT EXISTS crm_fields (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    fkey       VARCHAR(40)  NOT NULL,
    label      VARCHAR(80)  NOT NULL,
    type       VARCHAR(8)   NOT NULL DEFAULT 'text',          -- text | number | date | list
    options    TEXT         NULL,                             -- list: one choice per line
    sort       INT          NOT NULL DEFAULT 0,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL,
    UNIQUE KEY uq_client_key (client_id, fkey),
    CONSTRAINT fk_crm_fields_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Money shown in the account's currency; "fresh" leads that arrive as an old contact count as cold
-- after this many days.
ALTER TABLE crm_settings ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'EGP' AFTER require_lost_reason;
ALTER TABLE crm_settings ADD COLUMN fresh_days INT NOT NULL DEFAULT 30 AFTER currency;
