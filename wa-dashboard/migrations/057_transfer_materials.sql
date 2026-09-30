-- Bulk transfer with its options (quotas, smart rotation, history visibility), and a materials library.

-- Who may see a lead's history from before its last transfer: everyone (NULL), or not the new
-- salesperson ('sales'), or not the salesperson nor their team leader ('leaders'). history_from is
-- the moment of that transfer; prev_status is what the lead was at, shown when asked to.
ALTER TABLE contacts ADD COLUMN history_hide VARCHAR(10) NULL AFTER deleted_stage_id;
ALTER TABLE contacts ADD COLUMN history_from DATETIME NULL AFTER history_hide;
ALTER TABLE contacts ADD COLUMN prev_status VARCHAR(255) NULL AFTER history_from;

-- One row per lead moved: from whom, to whom, why, and in which batch.
CREATE TABLE IF NOT EXISTS crm_transfers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    client_id    INT          NOT NULL,
    batch        VARCHAR(20)  NOT NULL,
    contact_id   INT          NOT NULL,
    from_user_id INT          NULL,
    to_user_id   INT          NULL,
    reason       VARCHAR(255) NULL,
    notes        VARCHAR(1000) NULL,
    options      VARCHAR(255) NULL,
    by_user_id   INT          NULL,
    created_at   DATETIME     NOT NULL,
    KEY idx_client_time (client_id, created_at),
    KEY idx_contact (contact_id),
    KEY idx_batch (batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales material: brochures, price lists, contracts, payment plans — for the team to use.
CREATE TABLE IF NOT EXISTS crm_materials (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    client_id    INT          NOT NULL,
    category     VARCHAR(12)  NOT NULL,              -- marketing | legal | sales | financial
    title        VARCHAR(160) NOT NULL,
    description  VARCHAR(500) NULL,
    project_id   INT          NULL,
    file_path    VARCHAR(255) NULL,                  -- stored under uploads/materials, never served directly
    file_name    VARCHAR(200) NULL,
    mime         VARCHAR(100) NULL,
    size_bytes   INT          NULL,
    link_url     VARCHAR(500) NULL,                  -- or a link (a video, a Drive folder)
    created_by   INT          NULL,
    created_at   DATETIME     NOT NULL,
    KEY idx_client_cat (client_id, category),
    CONSTRAINT fk_materials_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
