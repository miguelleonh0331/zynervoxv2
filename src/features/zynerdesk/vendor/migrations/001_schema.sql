-- Synervox Remoteo - esquema minimo
-- Solo lo necesario: login (users, sessions) + historico de agentes (con campana)

CREATE TABLE IF NOT EXISTS users (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(80) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','tecnico') NOT NULL DEFAULT 'tecnico',
  active        TINYINT(1) DEFAULT 1,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token      VARCHAR(80) PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historico de agentes. tag = id manual del equipo; campaign = agrupador.
CREATE TABLE IF NOT EXISTS agents (
  agent_id   VARCHAR(120) PRIMARY KEY,
  tag        VARCHAR(120),
  campaign   VARCHAR(120),
  hostname   VARCHAR(160),
  username   VARCHAR(160),
  platform   VARCHAR(80),
  first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_seen  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_campaign (campaign),
  INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- admin inicial. Password se define al desplegar con un hash bcrypt real.
-- Placeholder: bcrypt de 'admin' (CAMBIAR EN PRODUCCION).
INSERT IGNORE INTO users (id, username, password_hash, role, active)
VALUES (1, 'admin', '$2b$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);
