-- Direccion del intento y el DID por el que entro. Las filas previas son salientes.
ALTER TABLE v2_dialer_call_log
 ADD COLUMN IF NOT EXISTS direction ENUM('OUT','IN') NOT NULL DEFAULT 'OUT',
 ADD COLUMN IF NOT EXISTS inbound_did VARCHAR(20) NULL,
 ADD INDEX IF NOT EXISTS dialer_inbound_did (direction,inbound_did,originated_at);

-- Un DID apunta a la lista donde aterrizan los leads nuevos; la campana de esa
-- lista aporta la cola y las disposiciones. Sin columna de cola propia: el
-- formato v2dialer_<id> lo valida Dialer::dialplanBody por regex.
CREATE TABLE IF NOT EXISTS v2_dialer_inbound_dids (
  did VARCHAR(20) NOT NULL,
  list_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (did),
  KEY idx_dialer_inbound_dids_list (list_id),
  CONSTRAINT fk_dialer_inbound_dids_list FOREIGN KEY (list_id)
    REFERENCES v2_dialer_lists (list_id) ON DELETE RESTRICT,
  CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
