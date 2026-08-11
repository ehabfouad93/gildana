-- Allow many clients to exist without a phone number yet.
-- A UNIQUE index permits multiple NULLs but not multiple ''.
UPDATE clients SET phone_number_id = NULL WHERE phone_number_id = '';
ALTER TABLE clients MODIFY phone_number_id VARCHAR(64) NULL DEFAULT NULL;
