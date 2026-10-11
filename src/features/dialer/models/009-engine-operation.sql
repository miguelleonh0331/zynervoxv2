-- Additive, restart-safe upgrade. Existing campaigns never start automatically.
ALTER TABLE v2_dialer_campaigns
 MODIFY dial_method ENUM('MANUAL','RATIO','ADAPTIVE','PROGRESSIVE') NOT NULL DEFAULT 'MANUAL',
 ADD COLUMN IF NOT EXISTS engine_state ENUM('STOPPED','RUNNING') NOT NULL DEFAULT 'STOPPED',
 ADD COLUMN IF NOT EXISTS worker_seen_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS worker_error VARCHAR(255) NULL;
ALTER TABLE v2_dialer_call_log
 ADD COLUMN IF NOT EXISTS reserved_agent_user VARCHAR(60) NULL,
 ADD COLUMN IF NOT EXISTS agent_slot VARCHAR(60) NULL,
 ADD UNIQUE INDEX IF NOT EXISTS dialer_open_agent_slot (agent_slot);
CREATE TABLE IF NOT EXISTS v2_dialer_agent_intervals (
 interval_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 campaign_id BIGINT UNSIGNED NOT NULL,
 agent_user VARCHAR(60) NOT NULL,
 state ENUM('ACTIVE','PAUSED','WRAPUP') NOT NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ended_at DATETIME NULL,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 open_slot VARCHAR(100) NULL,
 UNIQUE KEY dialer_agent_interval_open (open_slot),
 KEY dialer_interval_report (campaign_id, started_at),
 CONSTRAINT dialer_interval_campaign FOREIGN KEY (campaign_id) REFERENCES v2_dialer_campaigns(campaign_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
