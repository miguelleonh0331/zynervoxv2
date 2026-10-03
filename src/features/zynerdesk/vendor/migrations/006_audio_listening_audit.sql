-- Auditoria central de escucha remota bajo demanda.
-- Nunca almacena contenido de audio.

CREATE TABLE IF NOT EXISTS audio_listening_sessions (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id             VARCHAR(120) NOT NULL,
  user_id              BIGINT NULL,
  supervisor_username  VARCHAR(80) NOT NULL,
  status               VARCHAR(32) NOT NULL DEFAULT 'requested',
  requested_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  started_at           DATETIME(3),
  ended_at             DATETIME(3),
  end_reason           VARCHAR(120),
  error_detail         VARCHAR(256),
  last_state_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  INDEX idx_audio_agent_requested (agent_id, requested_at),
  INDEX idx_audio_user_requested (user_id, requested_at),
  INDEX idx_audio_status (status, requested_at),
  CONSTRAINT fk_audio_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
