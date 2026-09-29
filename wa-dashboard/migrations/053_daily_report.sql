-- The daily report, sent to managers every evening: at what hour (NULL = not sent), and the day
-- it last went, so it goes once.
ALTER TABLE crm_settings ADD COLUMN daily_hour TINYINT NULL AFTER approve_new_leads;
ALTER TABLE crm_settings ADD COLUMN daily_sent_on DATE NULL AFTER daily_hour;
-- The daily report's text rides in the notice; a salesperson list does not fit 255 characters.
ALTER TABLE crm_notices MODIFY data TEXT NULL;
