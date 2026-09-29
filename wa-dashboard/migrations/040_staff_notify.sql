-- Telling salespeople about their leads: in the app (the bell) and on their own WhatsApp.

-- A salesperson's own WhatsApp number, for alerts, and whether they want them.
ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER name;
ALTER TABLE users ADD COLUMN wa_alerts TINYINT(1) NOT NULL DEFAULT 1 AFTER phone;

-- Each notice is shown in the app until read, and may also go to the person's WhatsApp.
ALTER TABLE crm_notices ADD COLUMN read_at DATETIME NULL AFTER seen_at;
ALTER TABLE crm_notices ADD COLUMN wa_status VARCHAR(10) NULL AFTER read_at;     -- sent | failed | skipped
ALTER TABLE crm_notices ADD COLUMN wa_error VARCHAR(255) NULL AFTER wa_status;
ALTER TABLE crm_notices ADD KEY idx_wa (wa_status, created_at);

-- How the account sends those WhatsApp alerts: off, or on — through the company's linked phone
-- when there is one, otherwise with an approved template (the Business API cannot start a chat
-- with free text).
ALTER TABLE crm_settings ADD COLUMN staff_wa_on TINYINT(1) NOT NULL DEFAULT 0 AFTER require_lost_reason;
ALTER TABLE crm_settings ADD COLUMN staff_wa_template INT NULL AFTER staff_wa_on;
ALTER TABLE crm_settings ADD COLUMN staff_wa_kinds VARCHAR(120) NOT NULL DEFAULT 'assigned,sla,followup,reclaimed,visit' AFTER staff_wa_template;
