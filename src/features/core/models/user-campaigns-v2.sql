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

-- zynervoxv2_core no tiene privilegios sobre el esquema: se otorgan tabla por
-- tabla. Sin estas dos lineas la tabla existe pero la aplicacion recibe
-- "1142 SELECT command denied" y la pantalla del supervisor queda en blanco.
-- Solo los tres privilegios que el codigo usa: la asignacion se reescribe
-- borrando e insertando, nunca con UPDATE.
GRANT SELECT, INSERT, DELETE ON zynervox_core.v2_core_user_campaigns TO 'zynervoxv2_core'@'localhost';
GRANT SELECT, INSERT, DELETE ON zynervox_core.v2_core_user_campaigns TO 'zynervoxv2_core'@'127.0.0.1';
