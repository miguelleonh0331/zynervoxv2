-- Bloques anonimos de actividad de teclado.
-- No almacena tecla, codigo, combinacion ni texto escrito.

CREATE TABLE IF NOT EXISTS agent_keyboard_blocks (
  event_id          CHAR(64) NOT NULL,
  agent_id          VARCHAR(120) NOT NULL,
  block_start       DATETIME(3) NOT NULL,
  block_end         DATETIME(3) NOT NULL,
  keypress_count    INT UNSIGNED NOT NULL DEFAULT 0,
  first_key_at      DATETIME(3),
  last_key_at       DATETIME(3),
  complete          TINYINT(1) NOT NULL DEFAULT 1,
  received_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (event_id),
  INDEX idx_keyboard_agent_start (agent_id, block_start),
  INDEX idx_keyboard_agent_end (agent_id, block_end),
  INDEX idx_keyboard_first_key (agent_id, first_key_at),
  INDEX idx_keyboard_last_key (agent_id, last_key_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
