-- A campaign can start a different automation for each quick-reply button of its template:
-- {"Yes": 12, "No": 15} (button text -> automation id). NULL = one automation (follow_flow_id).
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS follow_buttons TEXT NULL AFTER follow_button;
