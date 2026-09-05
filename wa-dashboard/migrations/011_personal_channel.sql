-- ─────────────────────────────────────────────
-- Second sending channel: a client's own PERSONAL WhatsApp number.
--
-- Meta's Cloud API cannot drive a personal number, so those clients send through a
-- gateway that holds a WhatsApp Web session. That is against WhatsApp's Terms and a
-- number can be banned for sending too fast or too uniformly — so sending on this
-- channel is paced hard: slot_size messages, then a slot_pause_sec cooldown, repeat.
--
-- The gateway itself is configured ONCE for the whole platform (app_settings), never
-- per client: a client only ever scans a QR. Per client we keep just the instance
-- name, its connection state and the linked number.
-- ─────────────────────────────────────────────
ALTER TABLE clients
    -- 'cloud' = Meta Cloud API (unchanged default) | 'personal' = gateway
    ADD COLUMN channel               VARCHAR(12)  NOT NULL DEFAULT 'cloud' AFTER status,
    -- gateway-side session id for this client (assigned when they first connect)
    ADD COLUMN personal_instance     VARCHAR(64)  NULL     AFTER channel,
    -- disconnected | qr_pending | connected
    ADD COLUMN personal_status       VARCHAR(16)  NOT NULL DEFAULT 'disconnected' AFTER personal_instance,
    -- the number they actually linked, as reported by the gateway
    ADD COLUMN personal_msisdn       VARCHAR(20)  NULL     AFTER personal_status,
    ADD COLUMN personal_connected_at DATETIME     NULL     AFTER personal_msisdn,
    -- shared secret in this client's inbound webhook URL
    ADD COLUMN personal_hook_secret  VARCHAR(64)  NULL     AFTER personal_connected_at,
    -- pacing: how many messages per slot, and how long to wait before the next slot
    ADD COLUMN slot_size             INT          NOT NULL DEFAULT 15  AFTER personal_hook_secret,
    ADD COLUMN slot_pause_sec        INT          NOT NULL DEFAULT 180 AFTER slot_size,
    -- set after a slot is spent; the worker skips this client until it passes
    ADD COLUMN next_slot_at          DATETIME     NULL     AFTER slot_pause_sec;

-- The worker looks up "who is allowed to send right now" on every run.
CREATE INDEX idx_clients_channel_slot ON clients (channel, next_slot_at);

-- Inbound lookup by webhook secret must be fast and unambiguous.
CREATE UNIQUE INDEX uq_clients_hook_secret ON clients (personal_hook_secret);
