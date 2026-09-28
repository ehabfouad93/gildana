-- Modules per client, and roles per user inside a client.
--
-- Until now every login inside a client account could see and do everything: users.role only
-- ever held 'admin' (the platform operator) or 'client', and the sidebar was a fixed list. That
-- made two things impossible — selling a client part of the product (Campaigns only, say), and
-- running a sales team where a salesperson should not see billing, settings, or each other's
-- leads.
--
-- Both are NULL-means-everything on purpose, so this migration changes nothing for anyone on
-- the day it runs: every existing client keeps every module, and every existing client login
-- becomes an Admin of its account. Narrowing only happens when someone chooses to.

-- Comma-separated module keys (see includes/permissions.php). NULL = all modules.
ALTER TABLE clients ADD COLUMN modules TEXT NULL AFTER status;

-- A user's role WITHIN their client account. Separate from users.role, which still says whether
-- this is a platform admin or a client login — conflating the two would let a client "admin"
-- read as a platform admin anywhere role === 'admin' is checked.
ALTER TABLE users ADD COLUMN client_role VARCHAR(16) NOT NULL DEFAULT 'admin' AFTER role;

-- Per-user module override. NULL = the role's default set. Always intersected with what the
-- client has, so a client can never hand a user a module the operator has switched off.
ALTER TABLE users ADD COLUMN modules TEXT NULL AFTER client_role;
