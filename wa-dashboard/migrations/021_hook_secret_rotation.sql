-- Rotate a personal-number webhook secret without the client noticing.
--
-- Rotation is two systems agreeing on a new value, and there is a gap between the moment we
-- store it and the moment the gateway starts using it. A message arriving in that gap would
-- be looked up by a secret we no longer hold and rejected with 403 — lost, silently.
--
-- Keeping the previous secret valid closes the gap: the gateway may post under either while
-- the change settles. The old one stops being accepted as soon as a request proves the
-- gateway has moved to the new one (see webhook_personal.php).
ALTER TABLE clients
    ADD COLUMN personal_hook_secret_prev VARCHAR(64) NULL DEFAULT NULL AFTER personal_hook_secret,
    ADD COLUMN personal_hook_rotated_at  DATETIME    NULL DEFAULT NULL AFTER personal_hook_secret_prev;

-- Inbound lookups match on either column, so both need an index.
ALTER TABLE clients ADD KEY idx_hook_prev (personal_hook_secret_prev);
