-- Saved views of the leads list (a name and its filters), and the columns each person shows.
--   user_id NULL = shared by an Admin with everyone on the account
CREATE TABLE IF NOT EXISTS crm_views (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    user_id    INT          NULL,
    name       VARCHAR(80)  NOT NULL,
    params     TEXT         NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL,
    KEY idx_client_user (client_id, user_id),
    CONSTRAINT fk_crm_views_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE users ADD COLUMN crm_columns TEXT NULL AFTER crm_pages;
-- The list sorts and filters on these; without them a 20,000-lead account scans every row.
ALTER TABLE contacts ADD KEY idx_crm_followup (client_id, next_followup_at);
ALTER TABLE contacts ADD KEY idx_crm_score (client_id, score);
