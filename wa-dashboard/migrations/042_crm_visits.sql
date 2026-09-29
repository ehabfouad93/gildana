-- Site visits: booked on a lead, confirmed and reminded on WhatsApp, marked came / didn't come.

CREATE TABLE IF NOT EXISTS crm_visits (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT          NOT NULL,
    contact_id      INT          NOT NULL,
    user_id         INT          NULL,               -- who meets them: the lead's owner unless chosen
    project_id      INT          NULL,
    starts_at       DATETIME     NOT NULL,
    duration_min    INT          NOT NULL DEFAULT 60,
    place           VARCHAR(255) NULL,
    notes           VARCHAR(500) NULL,
    status          VARCHAR(10)  NOT NULL DEFAULT 'scheduled',  -- scheduled | done | no_show | cancelled
    created_by      INT          NULL,
    created_at      DATETIME     NOT NULL,
    outcome_at      DATETIME     NULL,
    lead_reminded   TINYINT(1)   NOT NULL DEFAULT 0,
    staff_reminded  TINYINT(1)   NOT NULL DEFAULT 0,
    KEY idx_client_time (client_id, starts_at),
    KEY idx_contact (contact_id),
    CONSTRAINT fk_crm_visits_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_visits_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Where each project is, so a visit's place fills itself in.
ALTER TABLE crm_projects ADD COLUMN address VARCHAR(255) NULL AFTER name;

-- The messages around a visit, and what a visit does to the lead's stage.
ALTER TABLE crm_settings ADD COLUMN visit_confirm_tpl INT NULL AFTER staff_wa_kinds;
ALTER TABLE crm_settings ADD COLUMN visit_confirm_vars TEXT NULL AFTER visit_confirm_tpl;
ALTER TABLE crm_settings ADD COLUMN visit_remind_tpl INT NULL AFTER visit_confirm_vars;
ALTER TABLE crm_settings ADD COLUMN visit_remind_vars TEXT NULL AFTER visit_remind_tpl;
ALTER TABLE crm_settings ADD COLUMN visit_remind_hour TINYINT NOT NULL DEFAULT 18 AFTER visit_remind_vars;   -- the day before, at
ALTER TABLE crm_settings ADD COLUMN visit_staff_minutes INT NOT NULL DEFAULT 60 AFTER visit_remind_hour;     -- remind the salesperson
ALTER TABLE crm_settings ADD COLUMN visit_booked_stage INT NULL AFTER visit_staff_minutes;   -- move the lead here when a visit is booked
ALTER TABLE crm_settings ADD COLUMN visit_done_stage INT NULL AFTER visit_booked_stage;      -- …and here when they came
