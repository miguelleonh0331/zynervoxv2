CREATE TABLE IF NOT EXISTS v2_dialer_dnc (
  phone_number VARCHAR(20) NOT NULL,
  campaign_id BIGINT UNSIGNED NULL,
  added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(160) NOT NULL DEFAULT '',
  PRIMARY KEY (phone_number),
  KEY idx_dialer_dnc_campaign (campaign_id),
  CONSTRAINT fk_dialer_dnc_campaign FOREIGN KEY (campaign_id)
    REFERENCES v2_dialer_campaigns (campaign_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
