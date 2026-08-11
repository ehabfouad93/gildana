-- ─────────────────────────────────────────────
-- Richer client profiles + admin management
-- ─────────────────────────────────────────────

ALTER TABLE clients
    ADD COLUMN company              VARCHAR(190) NOT NULL DEFAULT '' AFTER sender_display,
    ADD COLUMN contact_person       VARCHAR(160) NOT NULL DEFAULT '' AFTER company,
    ADD COLUMN contact_phone        VARCHAR(40)  NOT NULL DEFAULT '' AFTER contact_person,
    ADD COLUMN contact_email        VARCHAR(190) NOT NULL DEFAULT '' AFTER contact_phone,
    ADD COLUMN category             VARCHAR(80)  NOT NULL DEFAULT '' AFTER contact_email,
    ADD COLUMN timezone             VARCHAR(64)  NOT NULL DEFAULT '' AFTER category,
    ADD COLUMN notes                TEXT         NULL             AFTER timezone,
    ADD COLUMN low_credit_threshold INT          NOT NULL DEFAULT 100 AFTER credits_balance;

ALTER TABLE users
    ADD COLUMN last_login_at DATETIME NULL AFTER status;
