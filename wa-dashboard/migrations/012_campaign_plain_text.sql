-- A campaign sent from a PERSONAL WhatsApp number has no approved templates to draw on —
-- the number simply sends ordinary messages. Requiring a template there was artificial
-- friction, so such campaigns carry their message text directly instead.
--
-- Cloud API campaigns are unaffected: they keep using template_id and leave this NULL.
ALTER TABLE campaigns
    ADD COLUMN body_text TEXT NULL AFTER template_id;

-- template_id is already nullable, but make the intent explicit: a personal-channel
-- campaign has body_text and no template.
