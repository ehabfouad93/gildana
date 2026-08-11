-- Split flows into two modules (bot | qualifier) and store canvas node positions.
ALTER TABLE flows
    ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'bot' AFTER name;  -- bot | qualifier

ALTER TABLE flow_steps
    ADD COLUMN pos_x INT NOT NULL DEFAULT 0 AFTER sort,
    ADD COLUMN pos_y INT NOT NULL DEFAULT 0 AFTER pos_x;

-- Existing google_sheet flows become qualifiers.
UPDATE flows SET kind='qualifier' WHERE trigger_type='google_sheet';
