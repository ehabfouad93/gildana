-- Each person's own way of seeing the app: language (en / ar, right-to-left), light or dark
-- (auto follows the phone or computer), and the pages they starred in the menu.
ALTER TABLE users ADD COLUMN lang VARCHAR(2) NULL AFTER report_favs;
ALTER TABLE users ADD COLUMN theme VARCHAR(5) NULL AFTER lang;
ALTER TABLE users ADD COLUMN nav_favs TEXT NULL AFTER theme;
