-- Retiro logico reversible de equipos. El historial permanece intacto y el
-- agente no se reactiva automaticamente cuando vuelve a reportar.
ALTER TABLE agents
  ADD COLUMN retired_at DATETIME NULL AFTER last_seen,
  ADD COLUMN retired_by_user_id BIGINT NULL AFTER retired_at,
  ADD INDEX idx_agents_retired_at (retired_at),
  ADD CONSTRAINT fk_agents_retired_by_user
    FOREIGN KEY (retired_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
