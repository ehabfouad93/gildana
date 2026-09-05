-- A display name and picture for the person signed in.
--
-- Accounts were identified only by email, so the topbar could show nothing friendlier than
-- the raw address. Both are optional: the header falls back to initials drawn from the name,
-- or from the email when there is no name yet.
ALTER TABLE users ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT '' AFTER email;
ALTER TABLE users ADD COLUMN avatar VARCHAR(160) NULL AFTER name;
