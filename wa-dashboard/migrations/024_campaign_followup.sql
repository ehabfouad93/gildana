-- Let a campaign hand its recipients to an automation once the message has landed.
--
-- Everything needed to decide *when* already exists on campaign_messages: status carries
-- sent/delivered/read/failed and sent_at / delivered_at / read_at give the clock. So this
-- adds only the intent — which flow, on what event, after how long, and for whom.

ALTER TABLE campaigns
    -- NULL = do nothing after sending, which is the existing behaviour for every campaign.
    ADD COLUMN follow_flow_id       INT         NULL DEFAULT NULL AFTER list_id,
    -- sent | delivered | read | replied | no_reply
    ADD COLUMN follow_trigger       VARCHAR(16) NOT NULL DEFAULT 'replied' AFTER follow_flow_id,
    -- Grace period before the trigger counts. For no_reply this IS the waiting time.
    ADD COLUMN follow_delay_minutes INT         NOT NULL DEFAULT 0 AFTER follow_trigger,
    -- all | delivered | replied | not_replied  (failed recipients are never enrolled)
    ADD COLUMN follow_audience      VARCHAR(16) NOT NULL DEFAULT 'all' AFTER follow_delay_minutes,
    ADD CONSTRAINT fk_campaigns_follow_flow FOREIGN KEY (follow_flow_id)
        REFERENCES flows(id) ON DELETE SET NULL;

-- Stamped when this recipient has been handed over, so the worker never looks at them again.
-- The enrol_key unique index from migration 020 is the hard guarantee against a double
-- enrolment; this column is what makes the sweep cheap instead of re-testing every row.
ALTER TABLE campaign_messages
    ADD COLUMN follow_enrolled_at DATETIME NULL DEFAULT NULL AFTER read_at,
    ADD KEY idx_follow_pending (campaign_id, follow_enrolled_at);
