-- The CRM: leads with a stage, an owner, and a history.
--
-- A lead IS a contact. A separate leads table would have to be kept in step with contacts on
-- every inbound message, import and merge, and would drift the first time one path forgot. So
-- the CRM is a view over contacts that have a stage: stage_id NULL = an ordinary contact, not in
-- the pipeline; anything else = a lead.

ALTER TABLE contacts
    ADD COLUMN email             VARCHAR(190)   NULL AFTER name,
    -- Who owns this lead. NULL = unassigned, visible to Admins only.
    ADD COLUMN owner_user_id     INT            NULL,
    ADD COLUMN assigned_at       DATETIME       NULL,
    ADD COLUMN stage_id          INT            NULL,
    ADD COLUMN deal_value        DECIMAL(14,2)  NULL,
    ADD COLUMN next_followup_at  DATETIME       NULL,
    -- The first time a PERSON answered after the lead was assigned — bot and campaign sends do
    -- not count. This is what "first response time" in the sales report measures.
    ADD COLUMN first_response_at DATETIME       NULL,
    ADD COLUMN crm_added_at      DATETIME       NULL,
    ADD INDEX idx_crm_owner (client_id, owner_user_id, stage_id),
    ADD INDEX idx_crm_stage (client_id, stage_id);

-- Stages are per client and editable. Seeded lazily with a real-estate default the first time a
-- client opens the CRM (includes/crm.php), not here, so a client created later still gets them.
CREATE TABLE IF NOT EXISTS crm_stages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    name       VARCHAR(80)  NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    -- won / lost close a lead. Reports count a win by KIND, so a client can rename "Won" to
    -- "Contract signed" without every conversion rate dropping to zero.
    kind       VARCHAR(8)   NOT NULL DEFAULT 'open',
    created_at DATETIME     NOT NULL,
    KEY idx_client_sort (client_id, sort),
    CONSTRAINT fk_crm_stages_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT      NOT NULL,
    contact_id INT      NOT NULL,
    user_id    INT      NULL,
    body       TEXT     NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_contact (contact_id, created_at),
    CONSTRAINT fk_crm_notes_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every assignment and stage move, in order. The reports are built from this: "how long do leads
-- sit in Viewing" and "who converts best" cannot be answered from a lead's CURRENT stage alone,
-- and cannot be reconstructed afterwards if the history was never kept.
CREATE TABLE IF NOT EXISTS crm_events (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NOT NULL,
    contact_id INT          NOT NULL,
    user_id    INT          NULL,                  -- who did it; NULL = the system (auto-assign)
    kind       VARCHAR(16)  NOT NULL,              -- added | assigned | stage
    from_val   VARCHAR(64)  NULL,
    to_val     VARCHAR(64)  NULL,
    created_at DATETIME     NOT NULL,
    KEY idx_client_time (client_id, created_at),
    KEY idx_contact (contact_id, created_at),
    CONSTRAINT fk_crm_events_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Round-robin position: the id of the salesperson who got the last lead.
ALTER TABLE clients ADD COLUMN rr_pointer INT NULL;

-- A push for ONE person ("a lead was assigned to you"). push_outbox is per client — every device
-- on the account — which is right for "a message arrived" and wrong for this. Kept separate so the
-- existing path is untouched.
CREATE TABLE IF NOT EXISTS push_outbox_user (
    user_id   INT      NOT NULL PRIMARY KEY,
    client_id INT      NOT NULL,
    queued_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
