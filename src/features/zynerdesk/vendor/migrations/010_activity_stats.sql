-- Agregados diarios de actividad y latidos livianos del agente.
-- No guarda teclas ni contenido del usuario.

CREATE TABLE IF NOT EXISTS agent_activity_reports (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id           VARCHAR(120) NOT NULL,
  reported_at        DATETIME(3) NOT NULL,
  received_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  input_state        VARCHAR(16),
  idle_ms            BIGINT UNSIGNED,
  open_apps_json     JSON,
  tracked_apps_json  JSON,
  PRIMARY KEY (id),
  INDEX idx_activity_reports_agent_time (agent_id, reported_at),
  INDEX idx_activity_reports_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_daily_stats (
  agent_id                    VARCHAR(120) NOT NULL,
  work_day                    DATE NOT NULL,
  timezone                    VARCHAR(64) NOT NULL DEFAULT 'America/Lima',
  first_reported_at           DATETIME(3),
  last_reported_at            DATETIME(3),
  report_count                INT UNSIGNED NOT NULL DEFAULT 0,
  first_active_at             DATETIME(3),
  last_active_at              DATETIME(3),
  keypress_count              INT UNSIGNED NOT NULL DEFAULT 0,
  active_blocks               INT UNSIGNED NOT NULL DEFAULT 0,
  inactive_blocks             INT UNSIGNED NOT NULL DEFAULT 0,
  span_seconds                INT UNSIGNED NOT NULL DEFAULT 0,
  evaluated_seconds           INT UNSIGNED NOT NULL DEFAULT 0,
  active_seconds              INT UNSIGNED NOT NULL DEFAULT 0,
  keyboard_seconds            INT UNSIGNED NOT NULL DEFAULT 0,
  foreground_seconds          INT UNSIGNED NOT NULL DEFAULT 0,
  inactive_seconds            INT UNSIGNED NOT NULL DEFAULT 0,
  max_inactive_seconds        INT UNSIGNED NOT NULL DEFAULT 0,
  authorized_break_seconds    INT UNSIGNED NOT NULL DEFAULT 0,
  unclassified_break_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
  activity_pct                DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  semaphore                   ENUM('green','yellow','red','gray') NOT NULL DEFAULT 'gray',
  calculation_version         VARCHAR(16) NOT NULL DEFAULT 'v1',
  calculated_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (agent_id, work_day),
  INDEX idx_daily_stats_day (work_day),
  INDEX idx_daily_stats_semaphore (work_day, semaphore)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
