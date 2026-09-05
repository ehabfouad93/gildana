-- Per-client webhook signature enforcement, and a retention window for message payloads.

-- Each tenant brings their own Meta app, so each has their own signing secret (already
-- collected in clients.app_secret_enc). This flag turns hard rejection of unsigned
-- callbacks on per client, so it can be verified for one tenant before applying to all.
ALTER TABLE clients
    ADD COLUMN require_signed_webhook TINYINT(1) NOT NULL DEFAULT 0 AFTER app_secret_enc;

-- Turn it on automatically for every client that already has a secret configured: for
-- those, verification was going to happen anyway, so enforcement costs nothing.
UPDATE clients SET require_signed_webhook = 1
 WHERE app_secret_enc IS NOT NULL AND app_secret_enc <> '';

-- Raw callback payloads carry customers' message text and phone numbers, and were kept
-- forever. Pruned by the worker to app_settings.retention_days (default 30).
ALTER TABLE webhook_events ADD KEY idx_received (received_at);
