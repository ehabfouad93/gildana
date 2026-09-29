-- AI on the lead page: a summary of the conversation, and what the lead said they want.
ALTER TABLE contacts ADD COLUMN ai_summary TEXT NULL AFTER reclaims;
ALTER TABLE contacts ADD COLUMN ai_facts TEXT NULL AFTER ai_summary;        -- JSON: project, unit type, budget, payment, language, next step
ALTER TABLE contacts ADD COLUMN ai_summary_at DATETIME NULL AFTER ai_facts;
ALTER TABLE contacts ADD COLUMN ai_msg_id BIGINT NULL AFTER ai_summary_at;   -- the last message the summary covers
