-- Control over the lead data: who sees phone numbers, who exports and deletes, a recycle bin,
-- requests that a manager approves, imports that can be undone, and a history of every field.

-- What happened to the account's leads that is not about one lead's sale: exports, phone
-- numbers shown, deletions, restores, undone imports. Kept so a manager can see who took what.
CREATE TABLE IF NOT EXISTS crm_audit (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT           NOT NULL,
    user_id    INT           NULL,
    action     VARCHAR(20)   NOT NULL,              -- export | reveal | delete | restore | purge | undo_import | request
    contact_id INT           NULL,
    detail     VARCHAR(1000) NULL,
    n          INT           NULL,
    created_at DATETIME      NOT NULL,
    KEY idx_client_time (client_id, created_at),
    CONSTRAINT fk_crm_audit_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The recycle bin. A deleted lead leaves the pipeline (stage_id NULL, so every report and list
-- already leaves it out) and remembers where it was, to come back to on restore.
ALTER TABLE contacts ADD COLUMN deleted_at DATETIME NULL AFTER crm_added_at;
ALTER TABLE contacts ADD COLUMN deleted_by INT NULL AFTER deleted_at;
ALTER TABLE contacts ADD COLUMN deleted_stage_id INT NULL AFTER deleted_by;
ALTER TABLE contacts ADD KEY idx_crm_deleted (client_id, deleted_at);

-- A salesperson asks; a manager approves or refuses.
--   kind delete: remove this lead          kind create: add this new lead (when the account asks for approval)
CREATE TABLE IF NOT EXISTS crm_requests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT           NOT NULL,
    kind        VARCHAR(8)    NOT NULL,
    user_id     INT           NOT NULL,
    contact_id  INT           NULL,
    payload     TEXT          NULL,                  -- create: the lead as typed
    reason      VARCHAR(255)  NULL,
    status      VARCHAR(10)   NOT NULL DEFAULT 'pending',   -- pending | approved | refused
    decided_by  INT           NULL,
    decided_at  DATETIME      NULL,
    answer      VARCHAR(255)  NULL,
    created_at  DATETIME      NOT NULL,
    KEY idx_client_status (client_id, status, created_at),
    CONSTRAINT fk_crm_requests_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every import, and the leads it made, so it can be undone.
CREATE TABLE IF NOT EXISTS crm_imports (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT           NOT NULL,
    user_id     INT           NULL,
    filename    VARCHAR(255)  NULL,
    mode        VARCHAR(8)    NOT NULL DEFAULT 'add',   -- add | update
    total       INT           NOT NULL DEFAULT 0,
    added       INT           NOT NULL DEFAULT 0,
    updated     INT           NOT NULL DEFAULT 0,
    leads       INT           NOT NULL DEFAULT 0,
    skipped     INT           NOT NULL DEFAULT 0,
    problems    TEXT          NULL,
    created_at  DATETIME      NOT NULL,
    undone_at   DATETIME      NULL,
    undone_by   INT           NULL,
    undo_note   VARCHAR(255)  NULL,
    KEY idx_client (client_id, created_at),
    CONSTRAINT fk_crm_imports_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_import_items (
    import_id   INT  NOT NULL,
    contact_id  INT  NOT NULL,
    new_contact TINYINT(1) NOT NULL DEFAULT 0,
    new_lead    TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (import_id, contact_id),
    CONSTRAINT fk_crm_import_items_import FOREIGN KEY (import_id) REFERENCES crm_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which field a history line is about ("budget", "cf:nationality"), for field changes.
ALTER TABLE crm_events ADD COLUMN field VARCHAR(40) NULL AFTER kind;
ALTER TABLE crm_events MODIFY from_val VARCHAR(255) NULL;
ALTER TABLE crm_events MODIFY to_val VARCHAR(255) NULL;

-- Phone numbers hidden from Sales (they still call and WhatsApp from the buttons); new leads
-- added by Sales waiting for a manager's approval.
ALTER TABLE crm_settings ADD COLUMN hide_phones TINYINT(1) NOT NULL DEFAULT 0 AFTER fresh_days;
ALTER TABLE crm_settings ADD COLUMN approve_new_leads TINYINT(1) NOT NULL DEFAULT 0 AFTER hide_phones;
