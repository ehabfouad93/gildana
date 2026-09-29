-- Leads whose "deal value" is really their phone number — put there by a browser's autofill in
-- the Add-lead form — get the value cleared. A real deal whose last nine digits happen to be the
-- lead's own phone number does not exist.
UPDATE contacts
   SET deal_value = NULL
 WHERE deal_value IS NOT NULL
   AND deal_value >= 100000000
   AND RIGHT(CAST(FLOOR(deal_value) AS CHAR), 9) = RIGHT(phone_e164, 9);
