-- What a salesperson did with a lead, not just what they wrote down.
--
-- crm_notes grows into the activity log: a note is one kind of activity; a call, a meeting, a site
-- visit, a WhatsApp or an email are the others, each with how it went. Existing notes stay notes.
ALTER TABLE crm_notes ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'note' AFTER user_id;
ALTER TABLE crm_notes ADD COLUMN outcome VARCHAR(40) NULL AFTER kind;

-- What the next follow-up is FOR ("send the payment plan"), shown beside its date and time.
ALTER TABLE contacts ADD COLUMN followup_note VARCHAR(255) NULL AFTER next_followup_at;
