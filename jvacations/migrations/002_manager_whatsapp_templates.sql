-- v2: sales-manager arrival step, WhatsApp automations, uploaded contract templates.

ALTER TABLE users
  MODIFY role ENUM('admin','advisor','booker','communicator','sales_manager','sales','accountant','owner_services') NOT NULL;

-- New stage between "confirmed" and "contracted": the client is at the office
-- and the sales manager has handed them to a sales rep.
ALTER TABLE clients
  MODIFY stage ENUM('new','booked','confirmed','arrived','contracted','lost','cancelled') NOT NULL DEFAULT 'new',
  ADD COLUMN manager_id INT UNSIGNED NULL AFTER confirmed_at,
  ADD COLUMN arrived_at DATETIME NULL AFTER manager_id,
  ADD KEY idx_phone (phone),
  ADD KEY idx_phone2 (phone2);

ALTER TABLE contracts
  ADD KEY idx_national_id (national_id),
  ADD KEY idx_contract_phone (phone);

-- "When a client reaches <trigger>, send WhatsApp template <name> with these variables."
CREATE TABLE IF NOT EXISTS wa_automations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trigger_key   VARCHAR(40)  NOT NULL,
  template_name VARCHAR(120) NOT NULL,
  language      VARCHAR(12)  NOT NULL DEFAULT 'ar',
  params        TEXT NULL,          -- variable keys for {{1}}, {{2}} … one per line
  header_param  VARCHAR(60) NULL,   -- optional variable for a text header {{1}}
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL,
  KEY idx_trigger (trigger_key, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wa_messages (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id     INT UNSIGNED NULL,
  automation_id INT UNSIGNED NULL,
  trigger_key   VARCHAR(40) NULL,
  to_phone      VARCHAR(32) NOT NULL,
  template_name VARCHAR(120) NOT NULL,
  language      VARCHAR(12) NOT NULL,
  params_json   TEXT NULL,
  status        ENUM('sent','failed','skipped') NOT NULL,
  wa_message_id VARCHAR(120) NULL,
  error         VARCHAR(500) NULL,
  sent_by       INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL,
  KEY idx_client (client_id, id),
  KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Word (.docx) contract templates uploaded by the admin, filled from the reservation.
CREATE TABLE IF NOT EXISTS contract_templates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  language    VARCHAR(5) NOT NULL DEFAULT 'ar',
  project_id  INT UNSIGNED NULL,     -- NULL = any project
  file_name   VARCHAR(190) NOT NULL, -- stored name under storage/templates
  active      TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_by INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
