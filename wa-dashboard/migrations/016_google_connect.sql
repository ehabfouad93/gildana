-- A client's own Google account, connected by them in one click.
--
-- Only the REFRESH token is worth persisting: access tokens expire in an hour and are cheap
-- to mint again, so they are cached with their expiry and re-fetched rather than treated as
-- state. Both are encrypted with the same key as the WhatsApp tokens — a refresh token is a
-- standing grant to the files the client picked, so it is a credential, not a preference.
ALTER TABLE clients ADD COLUMN google_refresh_enc  TEXT     NULL AFTER ai_api_key_enc;
ALTER TABLE clients ADD COLUMN google_access_enc   TEXT     NULL AFTER google_refresh_enc;
ALTER TABLE clients ADD COLUMN google_expires_at   DATETIME NULL AFTER google_access_enc;
ALTER TABLE clients ADD COLUMN google_email        VARCHAR(190) NULL AFTER google_expires_at;
ALTER TABLE clients ADD COLUMN google_connected_at DATETIME NULL AFTER google_email;

-- Short-lived, single-use state for the OAuth round trip. Kept server-side rather than in the
-- session so the callback still matches when the browser returns in a different tab, and
-- expired rows are simply ignored.
CREATE TABLE IF NOT EXISTS google_oauth_state (
    state      VARCHAR(64) NOT NULL,
    client_id  INT         NOT NULL,
    user_id    INT         NULL,
    return_to  VARCHAR(190) NULL,
    created_at DATETIME    NOT NULL,
    PRIMARY KEY (state),
    KEY idx_gos_created (created_at),
    CONSTRAINT fk_gos_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
