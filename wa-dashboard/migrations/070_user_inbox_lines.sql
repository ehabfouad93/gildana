-- Which WhatsApp numbers' conversations a person sees in the Inbox (Team page).
-- NULL = the numbers they may send from (as before); otherwise a comma list of lines (api, phone, n<id>).
ALTER TABLE users ADD COLUMN IF NOT EXISTS inbox_lines VARCHAR(255) NULL AFTER send_via;
