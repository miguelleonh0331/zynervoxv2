const crypto = require("crypto");
const fs = require("fs");
const path = require("path");
const mysql = require("mysql2/promise");
require("dotenv").config({ path: path.join(__dirname, "..", ".env") });

const MIGRATIONS = [
  "001_schema.sql",
  "002_monitor.sql",
  "003_user_campaigns.sql",
  "004_agent_activity.sql",
  "005_keyboard_blocks.sql",
  "006_audio_listening_audit.sql",
  "007_camera_viewing_sessions.sql",
  "008_monitored_apps.sql",
  "009_optimize_activity_events_indexes.sql",
  "010_activity_stats.sql",
  "011_pause_allowance.sql",
  "012_semaphore_override.sql",
  "013_agents_network.sql",
  "014_commands.sql"
];

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function connect() {
  let lastError;
  for (let attempt = 1; attempt <= 30; attempt += 1) {
    try {
      return await mysql.createConnection({
        host: process.env.DB_HOST || "127.0.0.1",
        user: process.env.DB_USER || "syner_remoteo",
        password: process.env.DB_PASS || "",
        database: process.env.DB_NAME || "syner_remoteo",
        multipleStatements: true,
        charset: "utf8mb4"
      });
    } catch (error) {
      lastError = error;
      if (attempt < 30) await wait(2000);
    }
  }
  throw lastError;
}

async function main() {
  const connection = await connect();
  try {
    await connection.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
      name VARCHAR(160) PRIMARY KEY,
      checksum CHAR(64) NOT NULL,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    for (const name of MIGRATIONS) {
      const sql = fs.readFileSync(path.join(__dirname, "..", "migrations", name), "utf8");
      const checksum = crypto.createHash("sha256").update(sql).digest("hex");
      const [[applied]] = await connection.query(
        "SELECT checksum FROM schema_migrations WHERE name = ?", [name]);
      if (applied) {
        if (applied.checksum !== checksum) throw new Error(`La migración aplicada cambió: ${name}`);
        console.log(`[migrate] ya aplicada ${name}`);
        continue;
      }
      if (sql.trim()) await connection.query(sql);
      await connection.query(
        "INSERT INTO schema_migrations (name, checksum) VALUES (?, ?)", [name, checksum]);
      console.log(`[migrate] ${sql.trim() ? "aplicada" : "registrada vacia"} ${name}`);
    }
  } finally {
    await connection.end();
  }
}

main().catch((error) => {
  console.error("[migrate] ERROR", error.message);
  process.exit(1);
});
