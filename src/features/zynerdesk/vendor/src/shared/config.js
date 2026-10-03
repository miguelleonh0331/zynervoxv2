"use strict";

const path = require("path");

const PROJECT_ROOT = path.resolve(__dirname, "..", "..");

module.exports = Object.freeze({
  HOST: process.env.HOST || "127.0.0.1",
  PORT: Number(process.env.PORT || 4011),
  SESSION_DAYS: 7,
  ACTIVITY_INGEST_TOKEN: String(process.env.ACTIVITY_INGEST_TOKEN || ""),
  ALLOW_CLIPBOARD_TEXT: process.env.ALLOW_CLIPBOARD_TEXT === "1",
  COOKIE_SECURE: process.env.COOKIE_SECURE !== "0",
  ACTIVITY_BREAK_START: process.env.ACTIVITY_BREAK_START || "13:00",
  ACTIVITY_BREAK_END: process.env.ACTIVITY_BREAK_END || "14:00",
  INITIAL_ADMIN_USER: process.env.INITIAL_ADMIN_USER || "admin",
  INITIAL_ADMIN_PASSWORD: process.env.INITIAL_ADMIN_PASSWORD || "",
  ZYNERVOX_SSO_SECRET: String(process.env.ZYNERVOX_SSO_SECRET || ""),
  ZYNERVOX_SSO_ADMIN_USER: String(process.env.ZYNERVOX_SSO_ADMIN_USER || process.env.INITIAL_ADMIN_USER || "admin"),
  WEB_DIR: path.join(PROJECT_ROOT, "web"),
  DB: {
    host: process.env.DB_HOST || "127.0.0.1",
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || "syner_remoteo",
    password: process.env.DB_PASS || "",
    database: process.env.DB_NAME || "syner_remoteo",
    connectionLimit: Number(process.env.DB_POOL || 10),
    charset: "utf8mb4"
  }
});
