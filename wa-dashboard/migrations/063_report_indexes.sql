-- Dashboards and reports read messages, runs, credits and contacts by client and date range.
CREATE INDEX idx_msg_client_time ON messages (client_id, created_at);
CREATE INDEX idx_msg_contact_dir_time ON messages (contact_id, direction, created_at);
CREATE INDEX idx_cm_client_sent ON campaign_messages (client_id, sent_at);
CREATE INDEX idx_runs_client_time ON flow_runs (client_id, created_at);
CREATE INDEX idx_ct_client_time ON credit_transactions (client_id, created_at);
CREATE INDEX idx_contacts_client_created ON contacts (client_id, created_at);
CREATE INDEX idx_campaigns_client_created ON campaigns (client_id, created_at);
