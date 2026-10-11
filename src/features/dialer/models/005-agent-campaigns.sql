CREATE TABLE IF NOT EXISTS v2_dialer_agent_campaigns (
  agent_user VARCHAR(60) NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_user, campaign_id),
  KEY idx_dialer_agent_campaigns_campaign (campaign_id),
  CONSTRAINT fk_dialer_agent_campaigns_campaign FOREIGN KEY (campaign_id)
    REFERENCES v2_dialer_campaigns (campaign_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
