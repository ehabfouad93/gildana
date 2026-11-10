-- What people did after a message: the replies and button taps that answer a campaign or an
-- automation step. One row per inbound message that answers something (reports, Inbox filter).
CREATE TABLE IF NOT EXISTS reply_events (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id           INT          NOT NULL,
    contact_id          INT          NOT NULL,
    message_id          BIGINT       NULL,                   -- the inbound row in `messages`
    kind                VARCHAR(10)  NOT NULL DEFAULT 'reply',   -- reply | button | stop
    body                VARCHAR(500) NULL,                   -- what they wrote, or the button they tapped
    campaign_id         INT          NULL,
    campaign_message_id BIGINT       NULL,
    flow_id             INT          NULL,
    run_id              BIGINT       NULL,
    step_id             INT          NULL,
    created_at          DATETIME     NOT NULL,
    KEY idx_re_campaign (campaign_id, kind),
    KEY idx_re_cm (campaign_message_id),
    KEY idx_re_flow (flow_id, step_id),
    KEY idx_re_run (run_id),
    KEY idx_re_contact (client_id, contact_id),
    CONSTRAINT fk_re_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_cm_contact_sent ON campaign_messages (contact_id, sent_at);

-- Campaigns of the last 90 days: an answer within 3 days of a campaign message counts as a reply
-- to it (the newest such message). From now on answers are matched exactly as they arrive.
INSERT INTO reply_events (client_id, contact_id, message_id, kind, body, campaign_id, campaign_message_id, created_at)
SELECT i.client_id, i.contact_id, i.id, IF(i.type IN ('button','interactive'), 'button', 'reply'), LEFT(i.body, 500), cm.campaign_id, cm.id, i.created_at
  FROM messages i
  JOIN campaign_messages cm ON cm.id = (SELECT x.id FROM campaign_messages x
                                         WHERE x.contact_id = i.contact_id AND x.sent_at IS NOT NULL
                                           AND x.sent_at <= i.created_at AND x.sent_at >= i.created_at - INTERVAL 3 DAY
                                         ORDER BY x.sent_at DESC LIMIT 1)
 WHERE i.direction = 'in' AND COALESCE(i.source, '') <> 'history' AND i.created_at >= NOW() - INTERVAL 90 DAY
   AND NOT EXISTS (SELECT 1 FROM reply_events r WHERE r.message_id = i.id);
