-- schema.sql — DDL del módulo stt_providers_admin
-- Origen: SHOW CREATE TABLE en mirmidon (Fase 2). Sin datos productivos.
-- Engine InnoDB + utf8mb4, igual que producción.

-- Cuentas Deepgram (tabla histórica, no migrar)
CREATE TABLE IF NOT EXISTS `deepgram_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `api_key` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `usage_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `generated_chars` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `last_balance_amount` decimal(14,8) DEFAULT NULL,
  `last_balance_units` varchar(20) DEFAULT NULL,
  `last_balance_checked_at` datetime DEFAULT NULL,
  `last_balance_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- API keys compartidas por ElevenLabs, Groq y Speechmatics (y en la réplica: también AssemblyAI/Gladia vía columna provider)
CREATE TABLE IF NOT EXISTS `carsa_stt_api_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(40) NOT NULL DEFAULT 'groq',
  `label` varchar(120) NOT NULL,
  `api_key` text NOT NULL,
  `model` varchar(120) NOT NULL DEFAULT 'whisper-large-v3-turbo',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 100,
  `rpm_limit` int(10) unsigned NOT NULL DEFAULT 20,
  `notes` varchar(255) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `last_status` varchar(40) DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_provider_active_priority` (`provider`,`active`,`priority`),
  KEY `idx_last_used_at` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuentas principales (por email)
CREATE TABLE IF NOT EXISTS `carsa_stt_api_keys_account` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) NOT NULL,
  `label` varchar(120) NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carsa_stt_account_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapa cuenta <-> api_key (source_type: 'deepgram' | 'carsa')
CREATE TABLE IF NOT EXISTS `carsa_stt_api_keys_account_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `source_type` varchar(20) NOT NULL,
  `api_key_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carsa_stt_account_source` (`source_type`,`api_key_id`),
  KEY `idx_carsa_stt_account_map_account` (`account_id`),
  CONSTRAINT `fk_carsa_stt_account_map_account` FOREIGN KEY (`account_id`) REFERENCES `carsa_stt_api_keys_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (Opcional) filas sintéticas de ejemplo — sin API keys reales
-- INSERT INTO deepgram_profiles (name, api_key) VALUES ('ejemplo-local', 'dg_key_ficticia_de_prueba');
-- INSERT INTO carsa_stt_api_keys (provider, label, api_key, model) VALUES ('groq', 'ejemplo-groq', 'gsk_ficticia_de_prueba', 'whisper-large-v3-turbo');
