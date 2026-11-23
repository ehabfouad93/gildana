-- An automation that just sent a picture (or a template with one) waits for Meta to report it
-- sent before its next step goes out: Meta fetches the picture first, so a text or buttons sent
-- straight after it would arrive BEFORE it. The id of the message being waited for.
ALTER TABLE flow_runs ADD COLUMN IF NOT EXISTS await_wamid VARCHAR(190) NULL;
CREATE INDEX IF NOT EXISTS idx_flow_runs_await ON flow_runs (await_wamid);
