-- Templates created and edited from Revenect (Templates → Create template).
-- meta_id: Meta's id for the template (to edit or delete it); rejected_reason: why Meta refused it.
ALTER TABLE templates ADD COLUMN IF NOT EXISTS meta_id VARCHAR(40) NULL;
ALTER TABLE templates ADD COLUMN IF NOT EXISTS rejected_reason VARCHAR(255) NULL;
