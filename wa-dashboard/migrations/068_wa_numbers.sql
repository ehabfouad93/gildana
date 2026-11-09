-- Several WhatsApp numbers per account: more Business API numbers and more linked phones.
--
-- An account's own two numbers stay where they are (clients.phone_number_id… and
-- clients.personal_*), as the "main" API number and the "main" phone. Every extra number is a row
-- here. Each number — main, extra, or a salesperson's own phone — is called a LINE, with a short
-- key written on every WhatsApp message:
--   api     the main Business API number          phone  the main linked phone
--   n<id>   an extra number in wa_numbers           u<id>  a salesperson's own phone (user_channels)

CREATE TABLE IF NOT EXISTS wa_numbers (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    client_id        INT          NOT NULL,
    kind             VARCHAR(10)  NOT NULL,                 -- cloud | personal
    label            VARCHAR(60)  NOT NULL DEFAULT '',      -- "Sales", "Support", "Branch 2"…
    -- Business API number
    phone_number_id  VARCHAR(40)  NULL,
    waba_id          VARCHAR(40)  NULL,
    display_phone    VARCHAR(30)  NULL,
    access_token_enc TEXT         NULL,                     -- NULL = the account's token (same Meta business)
    app_secret_enc   TEXT         NULL,
    -- Linked phone (same names as user_channels, mapped by pw_store())
    instance         VARCHAR(64)  NULL,
    status           VARCHAR(16)  NOT NULL DEFAULT 'disconnected',
    msisdn           VARCHAR(20)  NULL,
    hook_secret      VARCHAR(64)  NULL,
    hook_secret_prev VARCHAR(64)  NULL,
    hook_rotated_at  DATETIME     NULL,
    connected_at     DATETIME     NULL,
    active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_by       INT          NULL,
    created_at       DATETIME     NOT NULL,
    UNIQUE KEY uq_wa_numbers_pnid (phone_number_id),
    KEY idx_wa_numbers_client (client_id, kind),
    KEY idx_wa_numbers_hook (hook_secret),
    KEY idx_wa_numbers_hook_prev (hook_secret_prev),
    CONSTRAINT fk_wa_numbers_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- How many EXTRA numbers the account may add (set by the platform admin). 0 = only the main ones.
ALTER TABLE clients ADD COLUMN max_numbers INT NOT NULL DEFAULT 0;

-- Which line each WhatsApp message went through or came in on. NULL on Messenger / Instagram.
ALTER TABLE messages ADD COLUMN line VARCHAR(12) NULL AFTER via;
ALTER TABLE messages ADD KEY idx_messages_line (client_id, contact_id, line, id);

-- The line the contact last wrote to or was written from: where a new message to them goes by default.
ALTER TABLE contacts ADD COLUMN last_line VARCHAR(12) NULL;

-- One conversation per number in the Inbox: when each (contact, line) thread was last read.
CREATE TABLE IF NOT EXISTS inbox_reads (
    contact_id INT          NOT NULL,
    line       VARCHAR(12)  NOT NULL,
    read_at    DATETIME     NOT NULL,
    PRIMARY KEY (contact_id, line),
    CONSTRAINT fk_inbox_reads_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What sends from which line ('' / NULL = the default for that module).
ALTER TABLE campaigns    ADD COLUMN line VARCHAR(12) NULL;
ALTER TABLE flows        ADD COLUMN line VARCHAR(12) NULL;
ALTER TABLE flow_runs    ADD COLUMN line VARCHAR(12) NULL;
ALTER TABLE crm_msg_queue ADD COLUMN line VARCHAR(12) NULL;

-- Templates belong to a WhatsApp Business Account; an extra number may sit in another one.
-- NULL = the account's main WABA.
ALTER TABLE templates ADD COLUMN waba_id VARCHAR(40) NULL;

-- Existing WhatsApp messages: which line they were on. Sent by a person → what they sent through;
-- everything else → the account's own channel.
UPDATE messages m JOIN clients c ON c.id = m.client_id
   SET m.line = CASE
         WHEN m.via = 'api' THEN 'api'
         WHEN m.via = 'company_personal' THEN 'phone'
         WHEN m.via = 'own' AND m.sent_by_user_id IS NOT NULL THEN CONCAT('u', m.sent_by_user_id)
         WHEN c.channel = 'personal' THEN 'phone'
         ELSE 'api' END
 WHERE m.line IS NULL AND (m.channel IS NULL OR m.channel = 'whatsapp');

UPDATE contacts ct JOIN clients c ON c.id = ct.client_id
   SET ct.last_line = IF(c.channel = 'personal', 'phone', 'api')
 WHERE ct.last_line IS NULL;

-- The number the CRM's automatic messages go from ('' / NULL = the lead's own Business API number).
ALTER TABLE crm_settings ADD COLUMN auto_line VARCHAR(12) NULL;
