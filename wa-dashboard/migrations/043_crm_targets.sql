-- Monthly targets per salesperson: what the manager expects, beside what happened.
CREATE TABLE IF NOT EXISTS crm_targets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT           NOT NULL,
    user_id     INT           NOT NULL,
    month       CHAR(7)       NOT NULL,              -- 2026-10
    contacted   INT           NULL,                  -- new leads contacted
    visits      INT           NULL,                  -- site visits they came to
    sales       INT           NULL,                  -- deals won
    sales_value DECIMAL(16,2) NULL,                  -- value of deals won
    updated_at  DATETIME      NOT NULL,
    UNIQUE KEY uq_target (client_id, user_id, month),
    CONSTRAINT fk_crm_targets_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_targets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
