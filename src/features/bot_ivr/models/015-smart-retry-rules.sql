-- MariaDB 10.6+. Run against the configured Bot IVR database.
-- Example: mysql zynervox_core < 015-smart-retry-rules.sql
-- Motor de reintentos inteligentes: migración 100% aditiva.
-- Toda campaña existente queda con retry_policy_enabled=0 (comportamiento
-- idéntico al actual: un intento por lead/run, sin próxima fecha calculada).
-- No ejecutar en producción sin autorización explícita (Fase 5 del plan).

-- 1) Interruptor global y límites por campaña (opt-in explícito).
ALTER TABLE `zynervox_bot_campaigns`
  ADD COLUMN IF NOT EXISTS `retry_policy_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `max_attempts_global` INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_attempts_window_days` INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_attempts_window_count` INT UNSIGNED NULL DEFAULT NULL;

-- 2) Estado del lead frente al motor de reintentos.
-- called_count/last_call_at/next_call_at ya existen (migración 005) y se
-- reutilizan; este motor es el primer código de producción que escribe
-- next_call_at.
ALTER TABLE `zynervox_bot_list`
  ADD COLUMN IF NOT EXISTS `retry_attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `retry_excluded_at` DATETIME NULL DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_bot_list_phone_lookup` (`phone`, `status`, `next_call_at`);

-- 3) Reglas por estado, por campaña. Ausencia de fila = reintento
-- deshabilitado para ese estado (compatible con campañas sin ninguna regla).
CREATE TABLE IF NOT EXISTS `zynervox_bot_retry_rules` (
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(80) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `weight` INT UNSIGNED NOT NULL DEFAULT 0,
  `wait_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` INT UNSIGNED NULL DEFAULT NULL,
  `is_effective_contact` TINYINT(1) NOT NULL DEFAULT 0,
  `is_terminal` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`campaign_id`, `status`),
  CONSTRAINT `fk_retry_rules_campaign` FOREIGN KEY (`campaign_id`)
    REFERENCES `zynervox_bot_campaigns` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Catálogo de exclusiones de conteo (errores técnicos que no deben
-- agotar el límite de intentos del cliente). Carga inicial de códigos sin
-- activar ningún comportamiento todavía: su uso real llega en una fase
-- posterior, con autorización explícita.
CREATE TABLE IF NOT EXISTS `zynervox_bot_retry_exclusions` (
  `code` VARCHAR(40) NOT NULL PRIMARY KEY,
  `description` VARCHAR(255) NOT NULL,
  `counts_as_attempt` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `zynervox_bot_retry_exclusions` (`code`,`description`,`counts_as_attempt`) VALUES
  ('TECH_ERROR_AMI','Fallo de confirmación AMI al originar',0),
  ('TECH_ERROR_SSH','Fallo de confirmación SSH/broker al originar',0),
  ('OUT_OF_HOURS','Bloqueado por horario de campaña',0),
  ('INVALID_NUMBER','Número inválido detectado antes de originar',0);
