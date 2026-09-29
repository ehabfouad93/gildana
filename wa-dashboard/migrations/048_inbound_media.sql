-- Pictures, voice notes, videos and files people send: where to fetch them, and where the copy is.
--   media_ref   the Business API media id (valid 30 days at Meta), or 'pw' for the linked phone,
--               whose gateway finds the file by the message id
--   media_path  our saved copy, fetched the first time someone opens it
ALTER TABLE messages ADD COLUMN media_ref VARCHAR(255) NULL AFTER via;
ALTER TABLE messages ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_ref;
ALTER TABLE messages ADD COLUMN media_name VARCHAR(255) NULL AFTER media_mime;
ALTER TABLE messages ADD COLUMN media_path VARCHAR(255) NULL AFTER media_name;
