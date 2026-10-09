-- Facebook & Instagram: when Revenect last fetched a Page's messages and comments itself, and when
-- Meta last pushed a real-time event for it (so the page can say whether webhooks are reaching us).
ALTER TABLE meta_pages ADD COLUMN social_synced_at DATETIME NULL;
ALTER TABLE meta_pages ADD COLUMN last_event_at DATETIME NULL;
