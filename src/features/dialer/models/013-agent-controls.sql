CREATE TABLE IF NOT EXISTS v2_dialer_pause_types (
 pause_type_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(30) NOT NULL UNIQUE,
 label VARCHAR(80) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO v2_dialer_pause_types (code,label) VALUES ('REFRIGERIO','Refrigerio'),('BANO','Baño'),('CAPACITACION','Capacitación');
CREATE TABLE IF NOT EXISTS v2_dialer_agent_status (
 agent_user VARCHAR(60) NOT NULL PRIMARY KEY,
 logged_in TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS v2_dialer_agent_pauses (
 pause_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 agent_user VARCHAR(60) NOT NULL,
 pause_type_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(80) NOT NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ended_at DATETIME NULL,
 open_slot VARCHAR(60) NULL,
 UNIQUE KEY dialer_open_pause (open_slot),
 KEY dialer_pause_history (agent_user,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO v2_dialer_agent_status (agent_user,logged_in)
SELECT DISTINCT agent_user,1 FROM v2_dialer_agent_intervals WHERE open_slot IS NOT NULL;
