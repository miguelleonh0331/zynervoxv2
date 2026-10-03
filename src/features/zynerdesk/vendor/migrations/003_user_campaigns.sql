-- Acceso por grupos/campañas para usuarios del panel.
CREATE TABLE IF NOT EXISTS user_campaigns (
  user_id    BIGINT NOT NULL,
  campaign   VARCHAR(120) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, campaign),
  INDEX idx_user_campaigns_campaign (campaign),
  CONSTRAINT fk_user_campaigns_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
