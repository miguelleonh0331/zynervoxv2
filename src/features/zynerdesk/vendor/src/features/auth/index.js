"use strict";

const crypto = require("crypto");
const config = require("../../shared/config");

function createAuthService({ pool }) {
  const SESSION_DAYS = config.SESSION_DAYS;
  const ACTIVITY_INGEST_TOKEN = config.ACTIVITY_INGEST_TOKEN;

  function parseCookies(req) {
    const h = req.headers.cookie || "";
    return Object.fromEntries(h.split(";").filter(Boolean).map((c) => {
      const [k, ...v] = c.trim().split("=");
      return [k, decodeURIComponent(v.join("="))];
    }));
  }

  function setCookie(name, value, maxAgeDays) {
    const transport = config.COOKIE_SECURE ? "; Secure; SameSite=None" : "; SameSite=Lax";
    return `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${Math.floor(maxAgeDays * 86400)}; HttpOnly${transport}`;
  }

  function clientIp(req) {
    const xff = (req.headers["x-forwarded-for"] || "").split(",")[0].trim();
    return xff || (req.socket && req.socket.remoteAddress) || null;
  }

  async function getSessionUser(req) {
    const token = parseCookies(req).sid;
    if (!token) return null;
    const [[row]] = await pool.query(
      `SELECT u.id, u.username, u.role, u.active
         FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token = ? AND s.expires_at > NOW()`, [token]);
    return row && row.active ? row : null;
  }

  function isAdmin(user) { return user && user.role === "admin"; }

  function isActivityAgentAuthorized(req) {
    if (!ACTIVITY_INGEST_TOKEN) return false;
    const header = String(req.headers.authorization || "");
    const supplied = header.startsWith("Bearer ") ? header.slice(7) : "";
    return isAgentTokenAuthorized(supplied);
  }

  function isAgentTokenAuthorized(supplied) {
    if (!ACTIVITY_INGEST_TOKEN) return false;
    const expectedBuffer = Buffer.from(ACTIVITY_INGEST_TOKEN);
    const suppliedBuffer = Buffer.from(String(supplied || ""));
    return expectedBuffer.length === suppliedBuffer.length
      && expectedBuffer.length > 0
      && crypto.timingSafeEqual(expectedBuffer, suppliedBuffer);
  }

  return {
    parseCookies,
    setCookie,
    clientIp,
    getSessionUser,
    isAdmin,
    isActivityAgentAuthorized,
    isAgentTokenAuthorized
  };
}

module.exports = { createAuthService };
