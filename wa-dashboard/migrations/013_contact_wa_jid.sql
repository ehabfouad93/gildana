-- WhatsApp's LID migration: an inbound message's remoteJid is often an opaque
-- "<id>@lid" rather than the sender's phone number. Storing those digits as the phone
-- made every reply fail with {"exists":false} — the id is not a dialable number.
--
-- wa_jid keeps the EXACT address a contact last messaged from, so a reply can go back to
-- that address instead of a number reconstructed from it, and so the same person is
-- recognised as one contact when WhatsApp swaps them between their @lid and phone form.
ALTER TABLE contacts ADD COLUMN wa_jid VARCHAR(64) NULL AFTER phone_e164;

-- Inbound lookup is by JID first, so it has to be indexed. Not UNIQUE: a contact created
-- by import or by the Cloud channel legitimately has no JID yet.
CREATE INDEX idx_contacts_wa_jid ON contacts (client_id, wa_jid);
