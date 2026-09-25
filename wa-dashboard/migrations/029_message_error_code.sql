-- Keep the WhatsApp error CODE on the message, not just Meta's wording.
--
-- messages.error_title already held strings like "This message was not delivered to maintain
-- healthy ecosystem engagement." — Meta's own developer-facing wording, shown raw in the Inbox
-- with no explanation and no way to act on it. Explaining it meant matching on that wording,
-- which is fragile: Meta rewrites these strings, and they arrive localised on some accounts.
--
-- The code (131049, 131026, …) is stable and is what wa_error_explain() keys off, so the Inbox
-- can say what actually went wrong and whether sending again is worth a credit.
ALTER TABLE messages ADD COLUMN error_code VARCHAR(32) NULL AFTER status;

-- Same for a qualifier's outreach. A failed qualifier send recorded Meta's wording and nothing
-- else, so the Lead Qualifier page could not say how many failed or why, and the only way to
-- resend was "Send now", which also re-imports the Google Sheet.
ALTER TABLE flow_messages ADD COLUMN error_code VARCHAR(32) NULL AFTER status;
