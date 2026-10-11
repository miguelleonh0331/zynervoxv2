CREATE TABLE IF NOT EXISTS v2_dialer_call_log (
  call_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  lead_id BIGINT UNSIGNED NOT NULL,
  agent_user VARCHAR(60) NULL,
  phone_number VARCHAR(20) NOT NULL,
  originated_at DATETIME NOT NULL,
  answered_at DATETIME NULL,
  bridged_at DATETIME NULL,
  ended_at DATETIME NULL,
  duration_wait INT UNSIGNED NULL,
  duration_talk INT UNSIGNED NULL,
  duration_wrapup INT UNSIGNED NULL,
  amd_result VARCHAR(20) NULL,
  termination_reason VARCHAR(20) NULL,
  disposition_code VARCHAR(20) NULL,
  dial_level_at_origin DECIMAL(5,2) NULL,
  channel_uniqueid VARCHAR(80) NULL,
  recording_path VARCHAR(512) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (call_id),
  KEY idx_dialer_call_log_campaign_date (campaign_id, originated_at),
  KEY idx_dialer_call_log_lead (lead_id),
  KEY idx_dialer_call_log_agent (agent_user),
  CONSTRAINT fk_dialer_call_log_campaign FOREIGN KEY (campaign_id)
    REFERENCES v2_dialer_campaigns (campaign_id) ON DELETE RESTRICT,
  CONSTRAINT fk_dialer_call_log_lead FOREIGN KEY (lead_id)
    REFERENCES v2_dialer_leads (lead_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
