-- Telemetria de actividad para agentes nuevos.
-- Migracion estrictamente aditiva: no modifica las tablas historicas.

CREATE TABLE IF NOT EXISTS agent_activity_status (
  agent_id           VARCHAR(120) PRIMARY KEY,
  agent_version      VARCHAR(40),
  capabilities_json  JSON,
  input_state        VARCHAR(16),
  idle_ms            BIGINT UNSIGNED,
  last_reported_at   DATETIME(3) NOT NULL,
  INDEX idx_activity_status_last_reported (last_reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_app_state (
  agent_id           VARCHAR(120) NOT NULL,
  process_name       VARCHAR(160) NOT NULL,
  is_open            TINYINT(1) NOT NULL DEFAULT 0,
  opened_at          DATETIME(3),
  closed_at          DATETIME(3),
  last_event_at      DATETIME(3) NOT NULL,
  last_reported_at   DATETIME(3) NOT NULL,
  PRIMARY KEY (agent_id, process_name),
  INDEX idx_app_state_open (is_open, process_name),
  INDEX idx_app_state_agent_reported (agent_id, last_reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_activity_events (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id              CHAR(64) NOT NULL,
  agent_id              VARCHAR(120) NOT NULL,
  event_type            VARCHAR(32) NOT NULL,
  event_time            DATETIME(3) NOT NULL,
  process_name          VARCHAR(160),
  window_title          VARCHAR(500),
  state                 VARCHAR(16),
  duration_ms           BIGINT UNSIGNED,
  idle_ms               BIGINT UNSIGNED,
  char_count            INT UNSIGNED,
  clipboard_sha256      CHAR(64),
  clipboard_text        TEXT,
  source_process        VARCHAR(160),
  source_window_title   VARCHAR(500),
  received_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_activity_event_id (event_id),
  INDEX idx_activity_agent_time (agent_id, event_time),
  INDEX idx_activity_agent_type_time (agent_id, event_type, event_time),
  INDEX idx_activity_process_time (process_name, event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
