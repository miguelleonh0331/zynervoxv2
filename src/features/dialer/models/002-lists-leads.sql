CREATE TABLE IF NOT EXISTS v2_dialer_lists (
  list_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (list_id),
  KEY idx_dialer_lists_campaign_active (campaign_id, active),
  CONSTRAINT fk_dialer_lists_campaign FOREIGN KEY (campaign_id)
    REFERENCES v2_dialer_campaigns (campaign_id) ON DELETE RESTRICT,
  CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS v2_dialer_leads (
  lead_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_id BIGINT UNSIGNED NOT NULL,
  phone_number VARCHAR(20) NOT NULL,
  first_name VARCHAR(80) NOT NULL DEFAULT '',
  last_name VARCHAR(80) NOT NULL DEFAULT '',
  custom_fields LONGTEXT DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'NEW',
  called_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_call_time DATETIME NULL,
  next_eligible_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  gmt_offset DECIMAL(4,2) NOT NULL DEFAULT -5.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (lead_id),
  KEY idx_dialer_leads_list_status (list_id, status, next_eligible_at),
  KEY idx_dialer_leads_phone (phone_number),
  CONSTRAINT fk_dialer_leads_list FOREIGN KEY (list_id)
    REFERENCES v2_dialer_lists (list_id) ON DELETE RESTRICT,
  CHECK (custom_fields IS NULL OR JSON_VALID(custom_fields))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
