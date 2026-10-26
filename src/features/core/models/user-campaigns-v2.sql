-- Campanas que un GTR (7) o supervisor (8) puede ver en el monitor.
-- Sin asignacion no ve ninguna: el filtro cierra por defecto.
-- campaign_id apunta a v2_dialer_campaigns cuando origin='agents' y a las
-- campanas de Bot IVR cuando origin='bot_ivr', asi que no lleva clave foranea.
CREATE TABLE IF NOT EXISTS v2_core_user_campaigns (
  user VARCHAR(60) NOT NULL,
  origin VARCHAR(20) NOT NULL,
  campaign_id INT NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user, origin, campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
