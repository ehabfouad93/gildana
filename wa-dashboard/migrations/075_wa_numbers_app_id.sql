-- An extra Business API number can belong to a different Meta app than the main number:
-- its App ID (with its own token and app secret, already stored) — used to upload template media.
ALTER TABLE wa_numbers ADD COLUMN IF NOT EXISTS app_id VARCHAR(40) NULL AFTER waba_id;
