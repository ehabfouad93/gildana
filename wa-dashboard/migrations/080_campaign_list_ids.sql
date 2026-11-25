-- A campaign can go to several lists at once: their ids (JSON). list_id keeps the first one,
-- for every place that shows or filters by a campaign's list.
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS list_ids TEXT NULL AFTER list_id;
