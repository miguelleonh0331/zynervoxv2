"use strict";

require("dotenv").config();
const fs = require("fs");
const path = require("path");
const bcrypt = require("bcrypt");
const mysql = require("mysql2/promise");
const config = require("../src/shared/config");

const MIGRATIONS = [
  "001_schema.sql", "003_user_campaigns.sql", "004_agent_activity.sql",
  "005_keyboard_blocks.sql", "006_audio_listening_audit.sql",
  "007_camera_viewing_sessions.sql", "008_monitored_apps.sql",
  "009_optimize_activity_events_indexes.sql", "010_activity_stats.sql",
  "011_pause_allowance.sql", "012_semaphore_override.sql",
  "013_agents_network.sql", "014_commands.sql", "015_retired_agents.sql"
];

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function connectWithRetry() {
  let lastError;
  for (let attempt = 1; attempt <= 30; attempt += 1) {
    try {
      return await mysql.createConnection({ ...config.DB, multipleStatements: true });
    } catch (error) {
      lastError = error;
      console.log(`[startup] MySQL no disponible (${attempt}/30)`);
      await delay(2000);
    }
  }
  throw lastError;
}

async function prepareDatabase(connection) {
  await connection.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(160) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);

  for (const name of MIGRATIONS) {
    const [[applied]] = await connection.query("SELECT name FROM schema_migrations WHERE name=?", [name]);
    if (applied) continue;
    const sql = fs.readFileSync(path.join(__dirname, "..", "migrations", name), "utf8");
    await connection.query(sql);
    await connection.query("INSERT INTO schema_migrations (name) VALUES (?)", [name]);
    console.log(`[startup] migracion aplicada: ${name}`);
  }

  if (config.INITIAL_ADMIN_PASSWORD.length < 12) {
    throw new Error("INITIAL_ADMIN_PASSWORD debe tener al menos 12 caracteres");
  }
  const hash = await bcrypt.hash(config.INITIAL_ADMIN_PASSWORD, 12);
  await connection.query(
    `INSERT INTO users (username,password_hash,role,active) VALUES (?,?,'admin',1)
     ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role='admin', active=1`,
    [config.INITIAL_ADMIN_USER, hash]
  );
}

(async () => {
  const connection = await connectWithRetry();
  try {
    await prepareDatabase(connection);
  } finally {
    await connection.end();
  }
  require("../server");
})().catch((error) => {
  console.error("[startup fatal]", error.message);
  process.exit(1);
});
