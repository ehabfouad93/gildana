-- Lead forms chosen one by one, each with its own question → field mapping.
--
-- mapping: JSON {question key: target}, target one of phone | name | first_name | last_name |
--          email | attr (keep as a detail on the lead) | ignore. NULL = recognise fields automatically,
--          which is how forms connected before this behave.
-- leads_count: how many leads Meta holds for the form, as of the last look (Meta keeps 90 days).
ALTER TABLE meta_forms ADD COLUMN mapping TEXT NULL AFTER stage_id;
ALTER TABLE meta_forms ADD COLUMN leads_count INT NULL AFTER mapping;
ALTER TABLE meta_forms ADD COLUMN last_synced_at DATETIME NULL AFTER last_polled_at;
