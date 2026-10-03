-- Cola de comandos remotos para el agente (speedtest / network-scan).
-- src/app.js la usa en 3 rutas (POST /api/agent/:id/command, GET
-- /api/agent/:id/commands, POST /api/agent/:id/commands/:cmdId/ack) y en el
-- enriquecimiento de GET /api/agents, pero nunca tuvo una migracion que la
-- creara: toda instalacion de zynerdesk fallaba con
-- "Table 'commands' doesn't exist" en cuanto habia al menos un agente
-- registrado (el JOIN de enriquecimiento tumbaba la respuesta completa de
-- /api/agents, por eso el panel mostraba "Sin agentes" aunque el registro
-- via /api/agent/report si funcionara).
CREATE TABLE IF NOT EXISTS commands (
  id          VARCHAR(40) PRIMARY KEY,
  agent_id    VARCHAR(120) NOT NULL,
  type        VARCHAR(40) NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'pending',
  result_json LONGTEXT,
  error       TEXT,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  acked_at    DATETIME NULL,
  INDEX idx_agent_status (agent_id, status),
  INDEX idx_agent_type_status (agent_id, type, status),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
