-- Link an Inbox message back to the thing that produced it, so it can be resent from the thread.
--
-- messages recorded WHAT was sent and that it failed, but nothing about where it came from. A
-- failed send in the Inbox was therefore a dead end: the thread could explain the error but had
-- no way to act on it, because resending means re-queueing the original campaign_messages row
-- or flow_run — and there was no path from the message back to either.
--
-- messages.source already says which pipeline sent it ('campaign', 'qualifier', 'automation',
-- 'manual'); this holds the id WITHIN that pipeline:
--
--   campaign             → campaign_messages.id
--   qualifier/automation → flow_runs.id
--   manual               → NULL (an agent retypes it; there is nothing queued to re-run)
--
-- Deliberately not a foreign key: the referenced table depends on `source`, and a campaign
-- purged for retention should leave its Inbox history readable rather than cascade it away.
ALTER TABLE messages ADD COLUMN source_ref_id INT NULL AFTER source;
CREATE INDEX idx_msg_source_ref ON messages (client_id, source, source_ref_id);
