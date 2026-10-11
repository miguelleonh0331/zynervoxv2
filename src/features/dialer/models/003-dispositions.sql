CREATE TABLE IF NOT EXISTS v2_dialer_dispositions (
  disposition_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  label VARCHAR(80) NOT NULL,
  is_contact TINYINT(1) NOT NULL DEFAULT 0,
  is_sale TINYINT(1) NOT NULL DEFAULT 0,
  retryable TINYINT(1) NOT NULL DEFAULT 1,
  retry_delay_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (disposition_id),
  UNIQUE KEY uq_dialer_dispositions_campaign_code (campaign_id, code),
  CONSTRAINT fk_dialer_dispositions_campaign FOREIGN KEY (campaign_id)
    REFERENCES v2_dialer_campaigns (campaign_id) ON DELETE RESTRICT,
  CHECK (is_contact IN (0,1)), CHECK (is_sale IN (0,1)), CHECK (retryable IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
