-- ============================================================================
-- Zynerwaba v2 — Esquema MySQL 8.x (estado final, sin migraciones históricas)
-- Traducción fiel del DDL SQLite del proyecto original (server.js + src/*.js).
--
-- Uso:
--   mysql -u root -p < scripts/schema.sql
--
-- Notas de conversión (ver docs/DECISIONS.md):
--   INTEGER PRIMARY KEY AUTOINCREMENT → BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
--   TEXT con UNIQUE/índice           → VARCHAR(191) (límite de índice utf8mb4)
--   TEXT libre                       → TEXT
--   INTEGER booleano 0/1             → TINYINT(1)
--   datetime('now') DEFAULT          → DATETIME DEFAULT CURRENT_TIMESTAMP
--   REAL                             → DOUBLE
--   Los timestamps se guardan en UTC (el servidor MySQL opera en UTC; la UI
--   presenta en la zona horaria del negocio, p. ej. America/Lima).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS zynerwabav2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Usuario dedicado: ajusta la contraseña antes de ejecutar.
-- El contenedor crea usuario y permisos desde variables generadas localmente.
USE zynerwabav2;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- identidad --

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(191) NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name TEXT NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'agent',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NULL,
  CONSTRAINT chk_users_role CHECK (role IN ('superadmin','admin','supervisor','agent')),
  CONSTRAINT fk_users_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_lines (
  user_id BIGINT UNSIGNED NOT NULL,
  line_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, line_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  sid VARCHAR(191) PRIMARY KEY,
  data TEXT NOT NULL,
  expires_at BIGINT NOT NULL,
  INDEX idx_sessions_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_meta (
  clave VARCHAR(191) PRIMARY KEY,
  valor TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------------- multiempresa --

CREATE TABLE IF NOT EXISTS empresas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(191) NOT NULL,
  slug VARCHAR(191) NOT NULL UNIQUE,
  contacto_email TEXT,
  contacto_telefono TEXT,
  estado VARCHAR(20) NOT NULL DEFAULT 'activa',
  plan VARCHAR(50) NOT NULL DEFAULT 'basico',
  notas TEXT,
  synaptix_forward_url TEXT,
  salud_corte_automatico TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_empresas_estado CHECK (estado IN ('activa','suspendida','baja')),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS empresa_credenciales (
  empresa_id BIGINT UNSIGNED PRIMARY KEY,
  waba_id VARCHAR(191),
  phone_number_id_default VARCHAR(191),
  graph_version VARCHAR(20) NOT NULL DEFAULT 'v25.0',
  access_token_enc TEXT, access_token_iv TEXT, access_token_tag TEXT, access_token_hint TEXT,
  app_secret_enc TEXT, app_secret_iv TEXT, app_secret_tag TEXT,
  verify_token_enc TEXT, verify_token_iv TEXT, verify_token_tag TEXT,
  app_id TEXT,
  ultima_prueba_at DATETIME NULL, ultima_prueba_ok TINYINT(1) NULL, ultima_prueba_msg TEXT,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS empresa_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  accion VARCHAR(191) NOT NULL,
  detalle TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_empresa_audit (empresa_id, id)
) ENGINE=InnoDB;

-- -------------------------------------------------------------------- líneas --

CREATE TABLE IF NOT EXISTS `lines` (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  phone_number_id VARCHAR(191) NOT NULL UNIQUE,
  color VARCHAR(20) NOT NULL DEFAULT '#25d366',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NULL,
  INDEX idx_lines_empresa (empresa_id),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- --------------------------------------------------------------- conversaciones --

CREATE TABLE IF NOT EXISTS contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wa_id VARCHAR(191) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  name TEXT,
  owner_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  notes TEXT,
  last_message_at DATETIME NULL,
  assigned_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  folder_id BIGINT UNSIGNED NULL,
  assigned_by_user_id BIGINT UNSIGNED NULL,
  lead_id VARCHAR(191),
  line_id BIGINT UNSIGNED NOT NULL,
  snoozed_until DATETIME NULL,
  snoozed_at DATETIME NULL,
  snoozed_by_user_id BIGINT UNSIGNED NULL,
  UNIQUE KEY idx_contacts_phone_line (phone, line_id),
  INDEX idx_contacts_line (line_id),
  INDEX idx_contacts_owner (owner_user_id, last_message_at),
  INDEX idx_contacts_wa (wa_id),
  CONSTRAINT chk_contacts_status CHECK (status IN ('open','pending','closed')),
  FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (snoozed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE RESTRICT,
  FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  direction VARCHAR(4) NOT NULL,
  type VARCHAR(50) NOT NULL DEFAULT 'text',
  body TEXT,
  button_id TEXT,
  wa_message_id VARCHAR(191) UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'received',
  error TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  media_path TEXT,
  media_name TEXT,
  mime_type TEXT,
  is_auto_response TINYINT(1) NOT NULL DEFAULT 0,
  is_optout_response TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT chk_messages_dir CHECK (direction IN ('in','out')),
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE INDEX idx_messages_contact ON messages(contact_id, id);
CREATE INDEX idx_messages_wa ON messages(wa_message_id);

CREATE TABLE IF NOT EXISTS quick_replies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  label TEXT NOT NULL,
  body TEXT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS folders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#f58a1f',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NULL,
  INDEX idx_folders_empresa (empresa_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#607d8b',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NULL,
  INDEX idx_tags_empresa (empresa_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_tags (
  contact_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (contact_id, tag_id),
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auto_replies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name TEXT NOT NULL,
  body TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NULL,
  button_1_title VARCHAR(191) NOT NULL DEFAULT 'Me interesa',
  button_2_title VARCHAR(191) NOT NULL DEFAULT 'No me interesa',
  INDEX idx_auto_replies_empresa (empresa_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignment_reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(50) NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  lead_id VARCHAR(191),
  line_id BIGINT UNSIGNED NOT NULL,
  fulfilled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_reservations_phone_line (phone, line_id),
  INDEX idx_reservations_lead_id (lead_id),
  FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignment_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NULL,
  phone VARCHAR(50) NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  lead_id VARCHAR(191),
  line_id BIGINT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS calls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  call_id VARCHAR(191) NOT NULL UNIQUE,
  contact_id BIGINT UNSIGNED NULL,
  line_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(4) NOT NULL DEFAULT 'in',
  status VARCHAR(20) NOT NULL DEFAULT 'ringing',
  claimed_by_user_id BIGINT UNSIGNED NULL,
  offer_sdp LONGTEXT,
  answer_sdp LONGTEXT,
  error TEXT,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_at DATETIME NULL,
  ended_at DATETIME NULL,
  CONSTRAINT chk_calls_dir CHECK (direction IN ('in','out')),
  CONSTRAINT chk_calls_status CHECK (status IN ('ringing','answered','missed','rejected','ended','failed')),
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  FOREIGN KEY (claimed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE INDEX idx_calls_contact ON calls(contact_id, id);

CREATE TABLE IF NOT EXISTS window_overrides (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  reason TEXT,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_window_overrides_pendiente (contact_id, used_at),
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Mejoras MC-01..MC-16: estados, notas, equipos, reglas, SLA -------------------

CREATE TABLE IF NOT EXISTS conversation_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(50) NOT NULL,
  from_value TEXT,
  to_value TEXT,
  detail TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE INDEX idx_conversation_events_contact ON conversation_events(empresa_id, contact_id, id DESC);

CREATE TABLE IF NOT EXISTS internal_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_settings (
  empresa_id BIGINT UNSIGNED PRIMARY KEY,
  auto_assign TINYINT(1) NOT NULL DEFAULT 0,
  max_active_default INT NULL,
  sla_first_minutes INT NOT NULL DEFAULT 5,
  sla_waiting_minutes INT NOT NULL DEFAULT 15,
  auto_reopen TINYINT(1) NOT NULL DEFAULT 1,
  business_start VARCHAR(5) NOT NULL DEFAULT '08:00',
  business_end VARCHAR(5) NOT NULL DEFAULT '20:00',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Lima',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_operation_settings (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  max_active INT NULL,
  available TINYINT(1) NOT NULL DEFAULT 1,
  last_assigned_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS operation_teams (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_team_empresa_nombre (empresa_id, name),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS operation_team_users (
  team_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (team_id, user_id),
  FOREIGN KEY (team_id) REFERENCES operation_teams(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignment_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  line_id BIGINT UNSIGNED NULL,
  tag_id BIGINT UNSIGNED NULL,
  team_id BIGINT UNSIGNED NULL,
  start_time TEXT,
  end_time TEXT,
  priority INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE SET NULL,
  FOREIGN KEY (team_id) REFERENCES operation_teams(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------ broadcasts y campañas --

CREATE TABLE IF NOT EXISTS plantillas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  meta_template_id TEXT,
  nombre VARCHAR(191) NOT NULL,
  idioma VARCHAR(20) NOT NULL,
  categoria VARCHAR(20) NOT NULL,
  header_tipo VARCHAR(10) NOT NULL DEFAULT 'NONE',
  header_texto TEXT,
  header_media_path TEXT,
  cuerpo TEXT NOT NULL,
  pie TEXT,
  botones_json TEXT,
  estado VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  motivo_rechazo TEXT,
  creado_por_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sincronizado_at DATETIME NULL,
  UNIQUE KEY idx_plantilla_empresa_nombre (empresa_id, nombre, idioma),
  INDEX idx_plantilla_estado (empresa_id, estado),
  CONSTRAINT chk_plantilla_cat CHECK (categoria IN ('MARKETING','UTILITY')),
  CONSTRAINT chk_plantilla_header CHECK (header_tipo IN ('NONE','TEXT','IMAGE')),
  CONSTRAINT chk_plantilla_estado CHECK (estado IN ('PENDING','APPROVED','REJECTED','PAUSED','DISABLED','DELETED')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (creado_por_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  line_id BIGINT UNSIGNED NOT NULL,
  template_name VARCHAR(191) NOT NULL,
  template_language VARCHAR(20) NOT NULL,
  header_format VARCHAR(10) NOT NULL DEFAULT 'NONE',
  header_media_id TEXT,
  header_media_name TEXT,
  header_media_path TEXT,
  header_media_mime TEXT,
  header_media_uploaded_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_campaigns_status CHECK (status IN ('active','archived')),
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS broadcast_lists (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  description TEXT,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NULL,
  INDEX idx_broadcast_lists_empresa (empresa_id),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS broadcast_list_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_list_contact (list_id, phone),
  FOREIGN KEY (list_id) REFERENCES broadcast_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS broadcasts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  line_id BIGINT UNSIGNED NOT NULL,
  template_name VARCHAR(191) NOT NULL,
  template_language VARCHAR(20) NOT NULL,
  header_media_id TEXT,
  header_media_name TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  total INT NOT NULL DEFAULT 0,
  sent INT NOT NULL DEFAULT 0,
  failed INT NOT NULL DEFAULT 0,
  skipped INT NOT NULL DEFAULT 0,
  created_by_user_id BIGINT UNSIGNED NULL,
  error TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  campaign_id BIGINT UNSIGNED NULL,
  list_id BIGINT UNSIGNED NULL,
  CONSTRAINT chk_broadcasts_status CHECK (status IN ('pending','running','paused','done','failed','cancelled')),
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (list_id) REFERENCES broadcast_lists(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE INDEX idx_broadcasts_campaign ON broadcasts(campaign_id, id);

CREATE TABLE IF NOT EXISTS broadcast_recipients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  broadcast_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  wa_message_id VARCHAR(191),
  error TEXT,
  sent_at DATETIME NULL,
  delivered_at DATETIME NULL,
  read_at DATETIME NULL,
  replied_at DATETIME NULL,
  UNIQUE KEY idx_broadcast_recipient (broadcast_id, phone),
  INDEX idx_broadcast_recipient_pending (broadcast_id, status, id),
  INDEX idx_recipient_wa_message (wa_message_id),
  INDEX idx_recipient_phone (phone, sent_at),
  CONSTRAINT chk_recipient_status CHECK (status IN ('pending','sent','failed')),
  FOREIGN KEY (broadcast_id) REFERENCES broadcasts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS broadcast_optouts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(50) NOT NULL,
  reason TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NOT NULL,
  line_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_optout_phone_empresa (phone, empresa_id),
  INDEX idx_broadcast_optouts_empresa (empresa_id),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- piloto ADB --

CREATE TABLE IF NOT EXISTS adb_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  description TEXT,
  token_hash VARCHAR(191) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL,
  agent_version TEXT,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  empresa_id BIGINT UNSIGNED NULL,
  INDEX idx_adb_devices_empresa (empresa_id),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS adb_campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  message TEXT NOT NULL,
  image_key VARCHAR(50) NOT NULL DEFAULT 'fixed',
  delay_min INT NOT NULL DEFAULT 15,
  delay_max INT NOT NULL DEFAULT 45,
  status VARCHAR(20) NOT NULL DEFAULT 'running',
  total INT NOT NULL DEFAULT 0,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  CONSTRAINT chk_adbc_status CHECK (status IN ('running','paused','done','cancelled')),
  FOREIGN KEY (device_id) REFERENCES adb_devices(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS adb_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  claimed_at DATETIME NULL,
  finished_at DATETIME NULL,
  detail TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_adb_job_campaign_phone (campaign_id, phone),
  INDEX idx_adb_job_claim (device_id, status, id),
  CONSTRAINT chk_adbj_status CHECK (status IN ('pending','processing','sent','failed')),
  FOREIGN KEY (campaign_id) REFERENCES adb_campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES adb_devices(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- --------------------------------------------------------------------- salud --

CREATE TABLE IF NOT EXISTS numero_salud (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  line_id BIGINT UNSIGNED NULL,
  phone_number_id VARCHAR(191) NOT NULL,
  display_phone_number TEXT,
  verified_name TEXT,
  quality_rating VARCHAR(20),
  messaging_limit_tier TEXT,
  name_status TEXT,
  code_verification_status TEXT,
  throughput TEXT,
  meta_status TEXT,
  platform_type TEXT,
  token_ok TINYINT(1),
  error TEXT,
  enviados INT DEFAULT 0,
  entregados INT DEFAULT 0,
  leidos INT DEFAULT 0,
  respondidos INT DEFAULT 0,
  fallidos INT DEFAULT 0,
  bajas INT DEFAULT 0,
  semaforo VARCHAR(20) NOT NULL,
  motivo TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_salud_numero (phone_number_id, id),
  INDEX idx_salud_empresa (empresa_id, id),
  CONSTRAINT chk_salud_semaforo CHECK (semaforo IN ('verde','ambar','rojo','sin_datos')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (line_id) REFERENCES `lines`(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salud_alertas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  phone_number_id VARCHAR(191) NOT NULL,
  tipo VARCHAR(50) NOT NULL,
  severidad VARCHAR(10) NOT NULL,
  mensaje TEXT NOT NULL,
  resuelta_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_salud_alertas_abiertas (empresa_id, resuelta_at, id),
  CONSTRAINT chk_salud_sev CHECK (severidad IN ('info','aviso','critico')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------ business suite --

CREATE TABLE IF NOT EXISTS crm_stages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#607d8b',
  position INT NOT NULL DEFAULT 0,
  is_won TINYINT(1) NOT NULL DEFAULT 0,
  is_lost TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_stage_empresa_nombre (empresa_id, name),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS opportunities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  stage_id BIGINT UNSIGNED NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(191) NOT NULL,
  value DOUBLE NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL DEFAULT 'PEN',
  source TEXT,
  loss_reason TEXT,
  next_action TEXT,
  next_action_at DATETIME NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_opp_empresa_contact (empresa_id, contact_id),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (stage_id) REFERENCES crm_stages(id) ON DELETE SET NULL,
  FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  opportunity_id BIGINT UNSIGNED NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(191) NOT NULL,
  description TEXT,
  due_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  task_type VARCHAR(30) NOT NULL DEFAULT 'followup',
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tasks_due (empresa_id, status, due_at),
  CONSTRAINT chk_tasks_status CHECK (status IN ('pending','done','cancelled')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_fields (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  field_key VARCHAR(191) NOT NULL,
  field_type VARCHAR(20) NOT NULL DEFAULT 'text',
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_field_empresa_key (empresa_id, field_key),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_field_values (
  contact_id BIGINT UNSIGNED NOT NULL,
  field_id BIGINT UNSIGNED NOT NULL,
  value TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (contact_id, field_id),
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (field_id) REFERENCES contact_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dynamic_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  filters_json LONGTEXT NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_segment_empresa_nombre (empresa_id, name),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS followup_flows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  stop_on_reply TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS followup_steps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flow_id BIGINT UNSIGNED NOT NULL,
  position INT NOT NULL,
  delay_minutes INT NOT NULL DEFAULT 0,
  action_type VARCHAR(20) NOT NULL,
  payload_json LONGTEXT,
  UNIQUE KEY uq_step_flow_pos (flow_id, position),
  CONSTRAINT chk_followup_action CHECK (action_type IN ('message','task','tag')),
  FOREIGN KEY (flow_id) REFERENCES followup_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS followup_enrollments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  flow_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  current_position INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  next_run_at DATETIME NULL,
  last_error TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enroll_flow_contact (flow_id, contact_id),
  INDEX idx_followup_due (status, next_run_at),
  CONSTRAINT chk_enroll_status CHECK (status IN ('active','stopped','done','failed')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (flow_id) REFERENCES followup_flows(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scheduled_sends (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  list_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  scheduled_at DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Lima',
  status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  created_by_user_id BIGINT UNSIGNED NULL,
  broadcast_id BIGINT UNSIGNED NULL,
  error TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_schedule_due (status, scheduled_at),
  CONSTRAINT chk_sched_status CHECK (status IN ('scheduled','running','done','cancelled','failed')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (list_id) REFERENCES broadcast_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (broadcast_id) REFERENCES broadcasts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(191) NOT NULL,
  entity_type TEXT,
  entity_id TEXT,
  detail_json LONGTEXT,
  ip VARCHAR(64),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_company (empresa_id, id),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS report_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  provider VARCHAR(20) NOT NULL,
  api_key_enc TEXT NOT NULL,
  from_email VARCHAR(191) NOT NULL,
  to_email VARCHAR(191) NOT NULL,
  frequency VARCHAR(10) NOT NULL DEFAULT 'weekly',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  next_run_at DATETIME NOT NULL,
  last_sent_at DATETIME NULL,
  last_error TEXT,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_report_provider CHECK (provider IN ('resend','sendgrid')),
  CONSTRAINT chk_report_freq CHECK (frequency IN ('daily','weekly')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------- automation suite --

CREATE TABLE IF NOT EXISTS automation_flows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  trigger_type VARCHAR(30) NOT NULL DEFAULT 'incoming',
  trigger_json LONGTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS automation_blocks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flow_id BIGINT UNSIGNED NOT NULL,
  position INT NOT NULL,
  block_type VARCHAR(20) NOT NULL,
  config_json LONGTEXT,
  UNIQUE KEY uq_block_flow_pos (flow_id, position),
  CONSTRAINT chk_block_type CHECK (block_type IN ('message','question','condition','wait','tag','assign','webhook','flow')),
  FOREIGN KEY (flow_id) REFERENCES automation_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS automation_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  flow_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  current_position INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'running',
  next_run_at DATETIME NULL,
  error TEXT,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_automation_due (status, next_run_at),
  CONSTRAINT chk_run_status CHECK (status IN ('running','waiting','done','failed','stopped')),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (flow_id) REFERENCES automation_flows(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS outbound_webhooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  url TEXT NOT NULL,
  secret_enc TEXT NOT NULL,
  events_json LONGTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webhook_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(191) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_retry_at DATETIME NULL,
  error TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  INDEX idx_webhook_due (status, next_retry_at),
  FOREIGN KEY (webhook_id) REFERENCES outbound_webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS company_api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  key_hash VARCHAR(191) NOT NULL UNIQUE,
  key_prefix VARCHAR(20) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS integration_connectors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(50) NOT NULL,
  name VARCHAR(191) NOT NULL,
  base_url TEXT,
  config_json LONGTEXT,
  secret_enc TEXT,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_test_at DATETIME NULL,
  last_test_ok TINYINT(1) NULL,
  last_test_error TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wa_forms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  schema_json LONGTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_form_empresa_slug (empresa_id, slug),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wa_form_responses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  form_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  response_json LONGTEXT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (form_id) REFERENCES wa_forms(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_settings (
  empresa_id BIGINT UNSIGNED PRIMARY KEY,
  provider VARCHAR(30) NOT NULL DEFAULT 'openai_compatible',
  endpoint TEXT NOT NULL,
  model VARCHAR(191) NOT NULL,
  api_key_enc TEXT,
  system_prompt TEXT,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transcription_settings (
  empresa_id BIGINT UNSIGNED PRIMARY KEY,
  api_key_enc TEXT,
  model VARCHAR(50) NOT NULL DEFAULT 'whisper-large-v3-turbo',
  language VARCHAR(10) NOT NULL DEFAULT 'auto',
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  auto_transcribe TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS knowledge_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  content LONGTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_ai_insights (
  contact_id BIGINT UNSIGNED PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  intent TEXT,
  topic TEXT,
  urgency TEXT,
  sentiment TEXT,
  churn_risk TEXT,
  raw_json LONGTEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------ semilla --

-- Empresa interna (la migración del original la creaba al arranque).
INSERT INTO empresas (nombre, slug, plan, notas)
  SELECT 'Interna','interna','interno','Empresa interna de la instancia'
  WHERE NOT EXISTS (SELECT 1 FROM empresas WHERE slug='interna');

-- Marca de bootstrap: el primer arranque NO promoverá admins automáticamente.
INSERT INTO app_meta (clave, valor)
  SELECT 'bootstrap_superadmin','semilla-manual'
  WHERE NOT EXISTS (SELECT 1 FROM app_meta WHERE clave='bootstrap_superadmin');
SET FOREIGN_KEY_CHECKS = 1;
