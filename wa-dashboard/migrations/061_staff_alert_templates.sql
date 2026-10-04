-- WhatsApp alerts to salespeople, editable per alert: its own template and what fills each {{n}},
-- or its own wording when it goes from the company's linked phone. And a new alert: a lead of
-- theirs reaches one of the stages chosen here.
ALTER TABLE crm_settings ADD COLUMN staff_wa_custom TEXT NULL AFTER staff_wa_kinds;
ALTER TABLE crm_settings ADD COLUMN staff_wa_stages VARCHAR(255) NULL AFTER staff_wa_custom;
