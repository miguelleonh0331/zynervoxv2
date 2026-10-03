-- Catalogo de aplicaciones que el panel reconoce y que los agentes deben
-- vigilar, editable desde el boton de engranaje en index.html. Los agentes
-- leen process_name desde /api/agent/activity/config para saber que
-- procesos rastrear; un cambio aqui aplica recien la proxima vez que cada
-- agente se conecta/reinicia (no en caliente).

CREATE TABLE IF NOT EXISTS monitored_apps (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  process_name  VARCHAR(160) NOT NULL,
  label         VARCHAR(80) NOT NULL,
  code          VARCHAR(8) NOT NULL,
  color         VARCHAR(7) NOT NULL DEFAULT '#334760',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monitored_apps_process (process_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO monitored_apps (process_name, label, code, color) VALUES
  ('chrome.exe', 'Chrome', 'C', '#1b6d42'),
  ('ccvox.exe', 'CCVox', 'CC', '#a64f0f');
