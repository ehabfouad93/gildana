-- Counting each qualifier lead as sent / read / unread / failed looks up the messages of each run.
CREATE INDEX idx_fmsg_run ON flow_messages (run_id, status);
