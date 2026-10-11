-- Campaign opt-in; a single durable continuation per agent, consumed with the lead reservation.
ALTER TABLE v2_dialer_campaigns ADD COLUMN IF NOT EXISTS manual_autodial_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE v2_dialer_agent_status
    ADD COLUMN IF NOT EXISTS selected_campaign_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS autodial_source_call_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS autodial_due_at DATETIME NULL,
    ADD INDEX IF NOT EXISTS idx_manual_autodial (selected_campaign_id,autodial_due_at);
