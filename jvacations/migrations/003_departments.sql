-- v3: department tabs. Secretary attendance, free-text sales rep,
-- owner-services welcome call, finance contract review.

ALTER TABLE users
  MODIFY role ENUM('admin','advisor','booker','communicator','secretary','sales_manager','sales','accountant','owner_services') NOT NULL;

-- Attendance is recorded by the secretary (optionally handing the client to a
-- sales manager); the sales manager may then pick a sales user or just type the
-- rep's name. Both steps are optional.
ALTER TABLE clients
  ADD COLUMN secretary_id   INT UNSIGNED NULL AFTER manager_id,
  ADD COLUMN sales_rep_name VARCHAR(120) NULL AFTER sales_id;

-- Owner services welcome call, and finance review, per contract.
ALTER TABLE contracts
  ADD COLUMN welcome_status ENUM('pending','done') NOT NULL DEFAULT 'pending',
  ADD COLUMN welcome_at     DATETIME NULL,
  ADD COLUMN welcome_by     INT UNSIGNED NULL,
  ADD COLUMN finance_status ENUM('pending','approved','returned') NOT NULL DEFAULT 'pending',
  ADD COLUMN finance_at     DATETIME NULL,
  ADD COLUMN finance_by     INT UNSIGNED NULL,
  ADD COLUMN finance_note   VARCHAR(255) NULL,
  ADD KEY idx_welcome (welcome_status),
  ADD KEY idx_finance (finance_status);
