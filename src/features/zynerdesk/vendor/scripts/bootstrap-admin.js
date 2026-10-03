const path = require("path");
const bcrypt = require("bcrypt");
const mysql = require("mysql2/promise");
require("dotenv").config({ path: path.join(__dirname, "..", ".env") });

async function main() {
  const username = String(process.env.ADMIN_USERNAME || "admin").trim();
  const password = String(process.env.ADMIN_PASSWORD || "");
  if (!username || password.length < 12) {
    throw new Error("ADMIN_USERNAME y ADMIN_PASSWORD (mínimo 12 caracteres) son obligatorios");
  }
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST || "127.0.0.1",
    user: process.env.DB_USER || "syner_remoteo",
    password: process.env.DB_PASS || "",
    database: process.env.DB_NAME || "syner_remoteo",
    charset: "utf8mb4"
  });
  try {
    const [[existing]] = await connection.query("SELECT id FROM users WHERE username = ?", [username]);
    if (existing) {
      console.log(`[bootstrap-admin] ${username} ya existe`);
      return;
    }
    const hash = await bcrypt.hash(password, 12);
    await connection.query(
      "INSERT INTO users (username, password_hash, role, active) VALUES (?, ?, 'admin', 1)",
      [username, hash]);
    console.log(`[bootstrap-admin] ${username} creado`);
  } finally {
    await connection.end();
  }
}

main().catch((error) => {
  console.error("[bootstrap-admin] ERROR", error.message);
  process.exit(1);
});
