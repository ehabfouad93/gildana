-- Which of the CRM's own pages a person may open (Dashboard, Site visits, Reports…), set on the
-- Team page under the CRM tick. NULL = all of them, which is how everyone behaves until an Admin
-- chooses otherwise.
ALTER TABLE users ADD COLUMN crm_pages TEXT NULL AFTER modules;
