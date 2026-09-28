-- The CRM for managers: projects, lost reasons, lead scoring, assignment rules, response-time
-- timers, follow-up reminders — and the per-person notices that make the phone buzz for them.

-- What the lead wants, in the words a property sales team uses.
ALTER TABLE contacts ADD COLUMN project_id INT NULL AFTER deal_value;
ALTER TABLE contacts ADD COLUMN unit_type VARCHAR(80) NULL AFTER project_id;
ALTER TABLE contacts ADD COLUMN budget VARCHAR(80) NULL AFTER unit_type;
ALTER TABLE contacts ADD COLUMN payment_pref VARCHAR(16) NULL AFTER budget;          -- cash | installments
-- Why a lead was lost, asked when it is moved to a lost stage.
ALTER TABLE contacts ADD COLUMN lost_reason VARCHAR(80) NULL AFTER payment_pref;
ALTER TABLE contacts ADD COLUMN lost_note VARCHAR(255) NULL AFTER lost_reason;
-- 0–100: how likely this lead is to buy, recomputed as things happen. Hot ≥ 70, Warm ≥ 40.
ALTER TABLE contacts ADD COLUMN score TINYINT UNSIGNED NULL AFTER lost_note;
ALTER TABLE contacts ADD COLUMN score_at DATETIME NULL AFTER score;
-- The same person filling in a form again is a signal, not a duplicate to throw away.
ALTER TABLE contacts ADD COLUMN submissions INT NOT NULL DEFAULT 1 AFTER score_at;
ALTER TABLE contacts ADD COLUMN last_submitted_at DATETIME NULL AFTER submissions;
-- The last time a person did anything with the lead: a call logged, a message sent, a stage moved.
ALTER TABLE contacts ADD COLUMN last_touch_at DATETIME NULL AFTER last_submitted_at;
-- Timers: which follow-up time was already announced, and whether the response alert went out.
ALTER TABLE contacts ADD COLUMN followup_notified_for DATETIME NULL AFTER last_touch_at;
ALTER TABLE contacts ADD COLUMN sla_alerted_at DATETIME NULL AFTER followup_notified_for;
ALTER TABLE contacts ADD COLUMN stale_alerted_at DATETIME NULL AFTER sla_alerted_at;
ALTER TABLE contacts ADD COLUMN reclaims TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER stale_alerted_at;
ALTER TABLE contacts ADD KEY idx_crm_owner_stage (client_id, owner_user_id, stage_id);

-- A salesperson on leave takes no new leads; one at capacity takes no more until some close.
ALTER TABLE users ADD COLUMN crm_available TINYINT(1) NOT NULL DEFAULT 1 AFTER send_via;
ALTER TABLE users ADD COLUMN crm_capacity INT NULL AFTER crm_available;
ALTER TABLE users ADD COLUMN crm_digest_on DATE NULL AFTER crm_capacity;

CREATE TABLE IF NOT EXISTS crm_projects (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    name       VARCHAR(120) NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL,
    KEY idx_client (client_id),
    CONSTRAINT fk_crm_projects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Short pick-lists a client edits: unit types, lost reasons.
CREATE TABLE IF NOT EXISTS crm_options (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    kind       VARCHAR(16)  NOT NULL,                     -- unit_type | lost_reason
    label      VARCHAR(80)  NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    KEY idx_client_kind (client_id, kind),
    CONSTRAINT fk_crm_options_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who gets which leads. The first active rule that matches wins; no match → the whole sales team.
CREATE TABLE IF NOT EXISTS crm_rules (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    client_id    INT          NOT NULL,
    name         VARCHAR(120) NOT NULL,
    sort         INT          NOT NULL DEFAULT 0,
    active       TINYINT(1)   NOT NULL DEFAULT 1,
    match_project INT         NULL,
    match_source VARCHAR(20)  NULL,
    match_form   VARCHAR(40)  NULL,
    match_unit   VARCHAR(80)  NULL,
    users        VARCHAR(500) NOT NULL DEFAULT '',        -- comma-separated user ids
    method       VARCHAR(8)   NOT NULL DEFAULT 'rotate',  -- rotate | least
    rr_pointer   INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL,
    KEY idx_client (client_id),
    CONSTRAINT fk_crm_rules_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The timers, per account. NULL = off.
CREATE TABLE IF NOT EXISTS crm_settings (
    client_id             INT        NOT NULL PRIMARY KEY,
    first_contact_minutes INT        NULL,              -- alert when a new lead is not contacted in time
    reclaim_minutes       INT        NULL,              -- …and after this long, give it to someone else
    reclaim_max           TINYINT    NOT NULL DEFAULT 2,
    stale_days            INT        NULL,              -- alert when an open lead has had no activity
    stale_reassign        TINYINT(1) NOT NULL DEFAULT 0,
    work_start            TINYINT    NULL,              -- timers only count inside working hours
    work_end              TINYINT    NULL,
    digest_hour           TINYINT    NULL DEFAULT 9,    -- the morning summary; NULL = off
    followup_reminders    TINYINT(1) NOT NULL DEFAULT 1,
    require_lost_reason   TINYINT(1) NOT NULL DEFAULT 1,
    updated_at            DATETIME   NULL,
    CONSTRAINT fk_crm_settings_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What to tell a person's phone. Counts and kinds only — the push itself carries no lead details.
CREATE TABLE IF NOT EXISTS crm_notices (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    user_id    INT          NOT NULL,
    kind       VARCHAR(16)  NOT NULL,     -- followup | digest | sla | sla_team | reclaimed | stale | resubmit
    contact_id INT          NULL,
    data       VARCHAR(255) NULL,         -- JSON of counts, for the digest
    created_at DATETIME     NOT NULL,
    seen_at    DATETIME     NULL,
    KEY idx_user_unseen (user_id, seen_at),
    CONSTRAINT fk_crm_notices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A lead form can belong to a project, so its leads arrive already filed under it.
ALTER TABLE meta_forms ADD COLUMN project_id INT NULL AFTER stage_id;
