"use strict";

const fs = require("fs");
const path = require("path");
const config = require("../../shared/config");

function createWebService({ send }) {
  const WEB_DIR = config.WEB_DIR;

  const MIME = { ".html": "text/html; charset=utf-8", ".js": "text/javascript", ".css": "text/css" };
  function serveStatic(res, file) {
    const full = path.join(WEB_DIR, file);
    if (!full.startsWith(WEB_DIR) || !fs.existsSync(full)) return send(res, 404, "no encontrado", "text/plain");
    const type = MIME[path.extname(full)] || "application/octet-stream";
    const cache = path.extname(full) === ".html" ? { "cache-control": "no-store, max-age=0" } : {};
    send(res, 200, fs.readFileSync(full), type, cache);
  }

  // ---------- servidor HTTP ----------

  return { serveStatic };
}

module.exports = { createWebService };
