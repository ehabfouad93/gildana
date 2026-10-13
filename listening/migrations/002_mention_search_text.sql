-- Normalized copy of the mention's text, for search.
--
-- Searching title/content directly does not work for Arabic: "جيلدانا",
-- "جِيلدانا" and "الجيلدانا" are three different byte strings, so a LIKE on the
-- raw text silently misses most of what the user is looking for. This column
-- holds sent_normalize(title + content) — diacritics stripped, hamza and
-- ta-marbuta folded, Latin lowercased — and the feed searches that instead,
-- normalizing the query the same way before comparing.
ALTER TABLE mentions
    ADD COLUMN search_text MEDIUMTEXT NULL AFTER snippet;

-- Backfill is deliberately not attempted here: normalization lives in PHP, not
-- SQL. Existing rows are backfilled in batches by the cron worker, which picks
-- up anything still NULL.
