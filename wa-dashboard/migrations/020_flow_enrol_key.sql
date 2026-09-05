-- Stop concurrent lead imports from enrolling the same person twice.
--
-- The importer checked for an existing run and then inserted, which two requests can both
-- pass. A UNIQUE (flow_id, contact_id) would fix that but would ALSO break the chatbot:
-- auto_start() deliberately creates a fresh run every time a contact sends a keyword, and
-- that must keep working.
--
-- So the constraint is scoped to the path that needs it. enrol_key is set only by
-- automation_enqueue_lead() (sheet/CSV imports); auto_start() leaves it NULL, and NULLs do
-- not collide in a MySQL/MariaDB unique index, so chatbot re-entry is untouched.
ALTER TABLE flow_runs
    ADD COLUMN enrol_key VARCHAR(48) NULL DEFAULT NULL AFTER contact_id,
    ADD UNIQUE KEY uq_enrol (enrol_key);
