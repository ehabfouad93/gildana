-- Wider grade column to hold new outcomes (not_interested, no_answer) and support the
-- inbound AI Chat Agent module (kind='agent', reuses the flows/flow_steps/flow_runs tables).
ALTER TABLE flow_runs      MODIFY COLUMN grade VARCHAR(16) NULL;  -- hot|warm|cold|not_interested|no_answer
ALTER TABLE flow_collected MODIFY COLUMN grade VARCHAR(16) NULL;
