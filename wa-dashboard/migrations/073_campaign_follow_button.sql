-- A campaign can continue into an automation when a recipient taps one of the template's
-- quick-reply buttons: which button ('' or NULL = any of them).
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS follow_button VARCHAR(60) NULL AFTER follow_audience;
