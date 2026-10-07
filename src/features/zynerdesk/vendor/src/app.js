// Synervox Remoteo - backend liviano
// Solo: login (MySQL) + historico de agentes (con campana) + senalizacion WebRTC (WS).
// Sin telemetria, STT, grabacion ni RBAC pesado. Agentes en MySQL para historico.
const http = require("http");
const crypto = require("crypto");
const bcrypt = require("bcrypt");
require("dotenv").config();
const { registerSignaling } = require("./features/signaling");
const { createActivityService } = require("./features/activity");
const { createAuthService } = require("./features/auth");
const { createUsersService } = require("./features/users");
const { createWebService } = require("./features/web");
const { createGeoService } = require("./features/geo");
const { createAgentsService } = require("./features/agents");
const config = require("./shared/config");
const { pool } = require("./shared/db");
const { verifyZynervoxSso } = require("../zynervox-sso");

const { PORT, SESSION_DAYS, ACTIVITY_INGEST_TOKEN } = config;
const ACTIVITY_MAX_BODY_BYTES = 256 * 1024;
const ACTIVITY_MAX_EVENTS = 100;

// Cache de geolocalizacion por IP publica (no por agente: varios equipos
// detras del mismo NAT comparten IP y comparten cache). Sin esto, cada
// recarga del panel de cada tecnico volvia a pedir TODAS las IPs a
// ipapi.is, y el volumen hizo que el servicio empezara a rechazar la IP
// del VPS (rate-limit). 24h para resultados buenos; 5 min para errores,
// asi se recupera solo si el servicio externo vuelve a andar.
const { lookup: lookupGeo } = createGeoService();

// ---------- helpers HTTP ----------
function send(res, code, data, type = "application/json", extra = {}) {
  const body = type === "application/json" ? JSON.stringify(data) : data;
  const origin = res.req && res.req.headers && res.req.headers.origin;
  res.writeHead(code, {
    "content-type": type,
    "access-control-allow-origin": origin || "*",
    "access-control-allow-headers": "content-type, authorization",
    "access-control-allow-methods": "GET,POST,PUT,OPTIONS",
    "access-control-allow-credentials": "true",
    ...extra
  });
  res.end(body);
}

function readBody(req) {
  return new Promise((resolve) => {
    let raw = "";
    req.on("data", (c) => (raw += c));
    req.on("end", () => { try { resolve(raw ? JSON.parse(raw) : {}); } catch { resolve({}); } });
  });
}

function readLimitedJsonBody(req, maxBytes) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let bytes = 0;
    let tooLarge = false;
    req.on("data", (chunk) => {
      bytes += chunk.length;
      if (bytes > maxBytes) {
        tooLarge = true;
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => {
      if (tooLarge) {
        const error = new Error("payload demasiado grande");
        error.statusCode = 413;
        reject(error);
        return;
      }
      try {
        resolve(chunks.length ? JSON.parse(Buffer.concat(chunks).toString("utf8")) : {});
      } catch {
        const error = new Error("JSON invalido");
        error.statusCode = 400;
        reject(error);
      }
    });
    req.on("error", reject);
  });
}

const {
  setCookie,
  clientIp,
  getSessionUser,
  isAdmin,
  isActivityAgentAuthorized,
  isAgentTokenAuthorized
} = createAuthService({ pool });

function limitedString(value, maxLength) {
  if (value === null || value === undefined) return null;
  return String(value).trim().slice(0, maxLength) || null;
}

const { normalizeProcessName, normalizeActivityEvent, recalculateAgentDailyStats, limaDate } =
  createActivityService({ pool, limitedString });

const { getUserCampaigns, canAccessAgent, replaceUserCampaigns } =
  createUsersService({ pool });

const { setRetired } = createAgentsService({ pool });

const { serveStatic } = createWebService({ send });

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, "http://x");
    const p = url.pathname.replace(/\/+$/, "") || "/";
    const method = req.method;
    if (method === "OPTIONS") return send(res, 204, "");

    // ----- AUTH -----
    if (p === "/api/auth/login" && method === "POST") {
      const b = await readBody(req);
      const [[u]] = await pool.query(`SELECT * FROM users WHERE username = ?`, [b.username]);
      if (!u || !u.active || !(await bcrypt.compare(b.password || "", u.password_hash))) {
        return send(res, 401, { error: "Credenciales incorrectas" });
      }
      const token = crypto.randomBytes(32).toString("hex");
      await pool.query(`INSERT INTO sessions (token,user_id,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ? DAY))`,
        [token, u.id, SESSION_DAYS]);
      return send(res, 200, { id: u.id, username: u.username, role: u.role }, "application/json",
        { "set-cookie": setCookie("sid", token, SESSION_DAYS) });
    }

    if (p === "/api/auth/zynervox-sso" && method === "POST") {
      const b = await readLimitedJsonBody(req, 4096);
      const claims = verifyZynervoxSso(b, config.ZYNERVOX_SSO_SECRET);
      if (!claims) return send(res, 403, { error: "SSO Zynervox inválido o vencido" });
      const [[u]] = await pool.query(
        `SELECT id, username, role, active FROM users WHERE username = ?`, [config.ZYNERVOX_SSO_ADMIN_USER]);
      if (!u || !u.active || u.role !== "admin") {
        return send(res, 503, { error: "Administrador SSO no disponible" });
      }
      const token = crypto.randomBytes(32).toString("hex");
      await pool.query(`INSERT INTO sessions (token,user_id,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ? DAY))`,
        [token, u.id, SESSION_DAYS]);
      return send(res, 200, { id: u.id, username: u.username, role: u.role, source: "zynervox", subject: claims.user },
        "application/json", { "set-cookie": setCookie("sid", token, SESSION_DAYS) });
    }

    if (p === "/api/auth/logout" && method === "POST") {
      const token = parseCookies(req).sid;
      if (token) await pool.query(`DELETE FROM sessions WHERE token = ?`, [token]);
      return send(res, 200, { ok: true }, "application/json", { "set-cookie": setCookie("sid", "", -1) });
    }

    if (p === "/api/auth/me" && method === "GET") {
      const user = await getSessionUser(req);
      return send(res, 200, { user: user ? { id: user.id, username: user.username, role: user.role } : null });
    }

    // ----- ADMINISTRACION DE USUARIOS (solo admin) -----
    if (p === "/api/admin/users" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      const [rows] = await pool.query(
        `SELECT u.id, u.username, u.role, u.active, u.created_at,
                GROUP_CONCAT(uc.campaign ORDER BY uc.campaign SEPARATOR '\n') AS campaigns
           FROM users u
           LEFT JOIN user_campaigns uc ON uc.user_id = u.id
          GROUP BY u.id, u.username, u.role, u.active, u.created_at
          ORDER BY u.username`);
      return send(res, 200, { users: rows.map((row) => ({
        ...row, campaigns: row.campaigns ? row.campaigns.split("\n") : []
      })) });
    }

    if (p === "/api/admin/users" && method === "POST") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      const b = await readBody(req);
      const username = String(b.username || "").trim();
      const password = String(b.password || "");
      const role = b.role === "admin" ? "admin" : "tecnico";
      if (username.length < 3) return send(res, 400, { error: "Usuario mínimo: 3 caracteres" });
      if (password.length < 8) return send(res, 400, { error: "Contraseña mínima: 8 caracteres" });
      const connection = await pool.getConnection();
      try {
        await connection.beginTransaction();
        const hash = await bcrypt.hash(password, 10);
        const [result] = await connection.query(
          `INSERT INTO users (username, password_hash, role, active) VALUES (?, ?, ?, 1)`,
          [username, hash, role]);
        await replaceUserCampaigns(connection, result.insertId, b.campaigns);
        await connection.commit();
        return send(res, 201, { ok: true, id: result.insertId });
      } catch (error) {
        await connection.rollback();
        if (error && error.code === "ER_DUP_ENTRY") return send(res, 409, { error: "El usuario ya existe" });
        throw error;
      } finally {
        connection.release();
      }
    }

    const adminUserMatch = p.match(/^\/api\/admin\/users\/(\d+)$/);
    if (adminUserMatch && method === "PUT") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      const targetId = Number(adminUserMatch[1]);
      const b = await readBody(req);
      const username = String(b.username || "").trim();
      const role = b.role === "admin" ? "admin" : "tecnico";
      const active = b.active ? 1 : 0;
      if (username.length < 3) return send(res, 400, { error: "Usuario mínimo: 3 caracteres" });
      if (targetId === user.id && (!active || role !== "admin")) {
        return send(res, 400, { error: "No puedes desactivar ni degradar tu propia cuenta" });
      }
      const connection = await pool.getConnection();
      try {
        await connection.beginTransaction();
        const [result] = await connection.query(
          `UPDATE users SET username = ?, role = ?, active = ? WHERE id = ?`,
          [username, role, active, targetId]);
        if (!result.affectedRows) {
          await connection.rollback();
          return send(res, 404, { error: "Usuario no encontrado" });
        }
        if (b.password) {
          if (String(b.password).length < 8) {
            await connection.rollback();
            return send(res, 400, { error: "Contraseña mínima: 8 caracteres" });
          }
          await connection.query(`UPDATE users SET password_hash = ? WHERE id = ?`,
            [await bcrypt.hash(String(b.password), 10), targetId]);
        }
        await replaceUserCampaigns(connection, targetId, b.campaigns);
        await connection.commit();
        if (!active) await pool.query(`DELETE FROM sessions WHERE user_id = ?`, [targetId]);
        return send(res, 200, { ok: true });
      } catch (error) {
        await connection.rollback();
        if (error && error.code === "ER_DUP_ENTRY") return send(res, 409, { error: "El usuario ya existe" });
        throw error;
      } finally {
        connection.release();
      }
    }

    // ----- CATALOGO DE APPS MONITOREADAS -----
    // Lectura: cualquier usuario logueado (se usa para dibujar los badges de
    // apps abiertas en el panel, sea admin o tecnico). Alta/baja: solo admin.
    if (p === "/api/monitored-apps" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const [rows] = await pool.query(
        `SELECT id, process_name, label, code, color FROM monitored_apps ORDER BY label`);
      return send(res, 200, { apps: rows });
    }

    if (p === "/api/admin/monitored-apps" && method === "POST") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      const b = await readBody(req);
      const processName = normalizeProcessName(b.processName);
      if (!processName) return send(res, 400, { error: "Nombre de proceso invalido (letras, numeros, punto o guion)" });
      const label = limitedString(b.label, 80) || processName;
      const rawCode = String(b.code || "").trim().toUpperCase().slice(0, 4);
      const code = rawCode || processName.replace(/\.exe$/, "").slice(0, 2).toUpperCase();
      const color = /^#[0-9a-f]{6}$/i.test(String(b.color || "")) ? String(b.color) : "#334760";
      try {
        const [result] = await pool.query(
          `INSERT INTO monitored_apps (process_name, label, code, color) VALUES (?,?,?,?)`,
          [processName, label, code, color]);
        return send(res, 201, { ok: true, id: result.insertId });
      } catch (error) {
        if (error && error.code === "ER_DUP_ENTRY") return send(res, 409, { error: "Esa aplicacion ya esta en la lista" });
        throw error;
      }
    }

    const monitoredAppMatch = p.match(/^\/api\/admin\/monitored-apps\/(\d+)$/);
    if (monitoredAppMatch && method === "DELETE") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      await pool.query(`DELETE FROM monitored_apps WHERE id=?`, [Number(monitoredAppMatch[1])]);
      return send(res, 200, { ok: true });
    }

    // ----- MANTENIMIENTO DE EQUIPOS (retiro logico reversible, solo admin) -----
    const agentRetirementMatch = p.match(/^\/api\/admin\/agents\/([^/]+)\/(retire|restore)$/);
    if (agentRetirementMatch && method === "POST") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      const agentId = limitedString(decodeURIComponent(agentRetirementMatch[1]), 120);
      if (!agentId || !/^[a-zA-Z0-9._:-]+$/.test(agentId)) {
        return send(res, 400, { error: "agentId invalido" });
      }
      const retired = agentRetirementMatch[2] === "retire";
      if (!(await setRetired(agentId, user.id, retired))) {
        return send(res, 404, { error: "Equipo no encontrado" });
      }
      return send(res, 200, { ok: true, agentId, retired });
    }

    // ----- AGENTE: reporte (guarda/actualiza en MySQL para historico) -----
    if (p === "/api/agent/report" && method === "POST") {
      const b = await readBody(req);
      if (!b.agentId) return send(res, 400, { ok: false, error: "sin agentId" });
      const net = b.networkInterface || {};
      await pool.query(
        `INSERT INTO agents (agent_id, tag, campaign, hostname, username, platform,
                             adapter, mac, kind, ip_local, ip_public, ping, first_seen, last_seen)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE
           tag=VALUES(tag), campaign=VALUES(campaign), hostname=VALUES(hostname),
           username=VALUES(username), platform=VALUES(platform),
           adapter=VALUES(adapter), mac=VALUES(mac), kind=VALUES(kind),
           ip_local=VALUES(ip_local), ip_public=VALUES(ip_public), ping=VALUES(ping), last_seen=NOW()`,
        [b.agentId, b.tag || null, b.campaign || null, b.hostname || null, b.username || null, b.platform || null,
         net.ifaceName || net.iface || null, net.mac || null, net.kind || null,
         net.ip4 || b.ipLocal || null, clientIp(req), (typeof b.pingMs === "number" ? b.pingMs : null)]);
      return send(res, 200, { ok: true });
    }

    // ----- AGENTE NUEVO: lista de apps a vigilar (editable desde el panel) -----
    // El agente la pide UNA vez al arrancar (no en caliente); si falla o no
    // hay token, el agente conserva su lista local de siempre.
    if (p === "/api/agent/activity/config" && method === "GET") {
      if (!ACTIVITY_INGEST_TOKEN) return send(res, 503, { ok: false, error: "telemetria no configurada" });
      if (!isActivityAgentAuthorized(req)) return send(res, 401, { ok: false, error: "agente no autorizado" });
      const [rows] = await pool.query(`SELECT process_name FROM monitored_apps ORDER BY process_name`);
      return send(res, 200, { ok: true, trackedProcesses: rows.map((row) => row.process_name) });
    }

    // ----- AGENTE NUEVO: telemetria de actividad (opcional y autenticada) -----
    // Los agentes antiguos no usan esta ruta y conservan el flujo anterior.
    if (p === "/api/agent/activity/report" && method === "POST") {
      if (!ACTIVITY_INGEST_TOKEN) return send(res, 503, { ok: false, error: "telemetria no configurada" });
      if (!isActivityAgentAuthorized(req)) return send(res, 401, { ok: false, error: "agente no autorizado" });

      let b;
      try {
        b = await readLimitedJsonBody(req, ACTIVITY_MAX_BODY_BYTES);
      } catch (error) {
        return send(res, error.statusCode || 400, { ok: false, error: error.message });
      }

      const agentId = limitedString(b.agentId, 120);
      if (!agentId || !/^[a-zA-Z0-9._:-]+$/.test(agentId)) {
        return send(res, 400, { ok: false, error: "agentId invalido" });
      }
      if (!Array.isArray(b.events) || b.events.length > ACTIVITY_MAX_EVENTS) {
        return send(res, 400, { ok: false, error: `events debe contener maximo ${ACTIVITY_MAX_EVENTS}` });
      }
      const events = b.events.map(normalizeActivityEvent);
      if (events.some((event) => !event)) {
        return send(res, 400, { ok: false, error: "evento de actividad invalido" });
      }

      const trackedProcesses = [...new Set((Array.isArray(b.trackedProcesses) ? b.trackedProcesses : [])
        .map(normalizeProcessName).filter(Boolean))].slice(0, 50);
      const openApps = new Set((Array.isArray(b.openApps) ? b.openApps : [])
        .map(normalizeProcessName).filter(Boolean));
      if ([...openApps].some((processName) => !trackedProcesses.includes(processName))) {
        return send(res, 400, { ok: false, error: "openApps contiene procesos no monitoreados" });
      }

      const [[knownAgent]] = await pool.query(`SELECT 1 FROM agents WHERE agent_id=?`, [agentId]);
      if (!knownAgent) return send(res, 409, { ok: false, error: "agente aun no registrado" });

      const reportedAt = new Date();
      const connection = await pool.getConnection();
      try {
        await connection.beginTransaction();
        const lastInput = [...events].reverse().find((event) => event.type === "input_state");
        await connection.query(
          `INSERT INTO agent_activity_reports
             (agent_id, reported_at, input_state, idle_ms, open_apps_json, tracked_apps_json)
           VALUES (?,?,?,?,?,?)`,
          [agentId, reportedAt, lastInput ? lastInput.state : null, lastInput ? lastInput.idleMs : null,
            JSON.stringify([...openApps]), JSON.stringify(trackedProcesses)]
        );
        const keyboardEvents = events.filter((event) => event.type === "keyboard_block");
        const activityEvents = events.filter((event) => event.type !== "keyboard_block");
        if (activityEvents.length) {
          const placeholders = activityEvents.map(() => "(?,?,?,?,?,?,?,?,?,?,?,?,?,?)").join(",");
          const params = activityEvents.flatMap((event) => [
            event.id, agentId, event.type, event.eventTime, event.processName, event.windowTitle,
            event.state, event.durationMs, event.idleMs, event.charCount, event.clipboardSha256,
            event.clipboardText, event.sourceProcess, event.sourceWindowTitle
          ]);
          await connection.query(
            `INSERT IGNORE INTO agent_activity_events
               (event_id, agent_id, event_type, event_time, process_name, window_title,
                state, duration_ms, idle_ms, char_count, clipboard_sha256, clipboard_text,
                source_process, source_window_title)
             VALUES ${placeholders}`,
            params);
        }
        if (keyboardEvents.length) {
          const placeholders = keyboardEvents.map(() => "(?,?,?,?,?,?,?,?,?)").join(",");
          const params = keyboardEvents.flatMap((event) => [
            event.id, agentId, event.keyboardBlock.blockStart, event.keyboardBlock.blockEnd,
            event.keyboardBlock.keypressCount, event.keyboardBlock.firstKeyAt,
            event.keyboardBlock.lastKeyAt, event.keyboardBlock.complete ? 1 : 0, reportedAt
          ]);
          await connection.query(
            `INSERT IGNORE INTO agent_keyboard_blocks
               (event_id, agent_id, block_start, block_end, keypress_count,
                first_key_at, last_key_at, complete, received_at)
             VALUES ${placeholders}`,
            params);
        }
        if (trackedProcesses.length) {
          const placeholders = trackedProcesses.map(() => "(?,?,?,?,?,?,?)").join(",");
          const params = trackedProcesses.flatMap((processName) => {
            const isOpen = openApps.has(processName) ? 1 : 0;
            return [agentId, processName, isOpen, isOpen ? reportedAt : null,
              isOpen ? null : reportedAt, reportedAt, reportedAt];
          });
          await connection.query(
            `INSERT INTO agent_app_state
               (agent_id, process_name, is_open, opened_at, closed_at, last_event_at, last_reported_at)
             VALUES ${placeholders}
             ON DUPLICATE KEY UPDATE
               opened_at=IF(VALUES(is_open)=1 AND agent_app_state.is_open=0, VALUES(opened_at), agent_app_state.opened_at),
               closed_at=IF(VALUES(is_open)=0 AND agent_app_state.is_open=1, VALUES(closed_at), agent_app_state.closed_at),
               is_open=VALUES(is_open),
               last_event_at=VALUES(last_event_at),
               last_reported_at=VALUES(last_reported_at)`,
            params);
        }

        await connection.query(
          `INSERT INTO agent_activity_status
             (agent_id, agent_version, capabilities_json, input_state, idle_ms, last_reported_at)
           VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE
             agent_version=VALUES(agent_version), capabilities_json=VALUES(capabilities_json),
             input_state=COALESCE(VALUES(input_state), input_state),
             idle_ms=COALESCE(VALUES(idle_ms), idle_ms), last_reported_at=VALUES(last_reported_at)`,
          [agentId, limitedString(b.agentVersion, 40), JSON.stringify(Array.isArray(b.capabilities) ? b.capabilities.slice(0, 20) : []),
           lastInput ? lastInput.state : null, lastInput ? lastInput.idleMs : null, reportedAt]);
        await connection.commit();
      } catch (error) {
        await connection.rollback();
        throw error;
      } finally {
        connection.release();
      }
      void recalculateAgentDailyStats(agentId, limaDate(reportedAt)).catch((error) => {
        console.error("[activity stats]", error.message);
      });
      return send(res, 200, { ok: true, accepted: events.length });
    }

    // ----- PANEL: lista de agentes (desde MySQL, filtro por campana) -----
    if (p === "/api/agents" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const campaign = url.searchParams.get("campaign");
      const retired = url.searchParams.get("retired") === "1";
      if (retired && !isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      let sql = `SELECT a.agent_id, a.tag, a.campaign, a.hostname, a.username, a.platform,
                        a.adapter, a.mac, a.kind, a.ip_local, a.ip_public, a.ping, a.first_seen, a.last_seen,
                        a.retired_at, a.retired_by_user_id,
                        (a.last_seen > (NOW() - INTERVAL 30 SECOND)) AS online,
                        COALESCE(ds.semaphore,'gray') AS activity_semaphore,
                        ds.activity_pct, COALESCE(ds.semaphore_overridden,0) AS semaphore_overridden
                   FROM agents a
                   LEFT JOIN agent_daily_stats ds
                     ON ds.agent_id=a.agent_id
                    AND ds.work_day=DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00'))`;
      const params = [];
      const filters = [retired ? `a.retired_at IS NOT NULL` : `a.retired_at IS NULL`];
      if (!isAdmin(user)) {
        filters.push(`a.campaign IN (SELECT campaign FROM user_campaigns WHERE user_id = ?)`);
        params.push(user.id);
      }
      if (campaign) { filters.push(`a.campaign = ?`); params.push(campaign); }
      sql += ` WHERE ` + filters.join(" AND ");
      sql += ` ORDER BY online DESC, a.last_seen DESC`;
      const [rows] = await pool.query(sql, params);
      // Adjuntar ultimos resultados de pruebas + pendientes + conteo historial.
      // Antes esto hacia 4 consultas SECUENCIALES POR AGENTE (N+1): con ~50
      // agentes y este endpoint sondeado cada 3s desde index.html/index_v2.html/
      // supervicion, eran 200+ round-trips a MySQL por refresco. Ahora son 4
      // consultas en total para TODOS los agentes, sin importar cuantos haya.
      if (rows.length) {
        const agentIds = rows.map((agent) => agent.agent_id);

        const [testRows] = await pool.query(
          `SELECT agent_id, type, result_json, status, error
             FROM commands
            WHERE agent_id IN (?) AND type IN ('speedtest','network-scan') AND status<>'pending'
            ORDER BY created_at DESC`, [agentIds]);
        const latestTestByKey = new Map(); // "agentId:type" -> primera fila vista = la mas reciente (por el ORDER BY)
        for (const row of testRows) {
          const key = `${row.agent_id}:${row.type}`;
          if (!latestTestByKey.has(key)) latestTestByKey.set(key, row);
        }

        const [countRows] = await pool.query(
          `SELECT agent_id, COUNT(*) AS c FROM commands WHERE agent_id IN (?) AND status<>'pending' GROUP BY agent_id`,
          [agentIds]);
        const historyCountByAgent = new Map(countRows.map((row) => [row.agent_id, row.c]));

        const [pendingRows] = await pool.query(
          `SELECT agent_id, type FROM commands WHERE agent_id IN (?) AND status='pending' ORDER BY created_at`,
          [agentIds]);
        const pendingByAgent = new Map();
        for (const row of pendingRows) {
          if (!pendingByAgent.has(row.agent_id)) pendingByAgent.set(row.agent_id, row.type);
        }

        for (const a of rows) {
          const st = latestTestByKey.get(`${a.agent_id}:speedtest`);
          const sc = latestTestByKey.get(`${a.agent_id}:network-scan`);
          a.speedtest = st ? { result_json: st.result_json, status: st.status, error: st.error } : null;
          a.scan = sc ? { result_json: sc.result_json, status: sc.status, error: sc.error } : null;
          a.historyCount = historyCountByAgent.get(a.agent_id) || 0;
          a.pending = pendingByAgent.get(a.agent_id) || null;
        }

        const [appRows] = await pool.query(
          `SELECT agent_id, process_name, opened_at, last_reported_at
             FROM agent_app_state
            WHERE agent_id IN (?) AND is_open=1
              AND last_reported_at > (NOW(3) - INTERVAL 60 SECOND)
            ORDER BY process_name`, [agentIds]);
        const [activityRows] = await pool.query(
          `SELECT agent_id, agent_version, capabilities_json, input_state, idle_ms, last_reported_at
             FROM agent_activity_status
            WHERE agent_id IN (?)`, [agentIds]);
        const appsByAgent = new Map();
        for (const appState of appRows) {
          if (!appsByAgent.has(appState.agent_id)) appsByAgent.set(appState.agent_id, []);
          appsByAgent.get(appState.agent_id).push({
            processName: appState.process_name,
            openedAt: appState.opened_at,
            lastReportedAt: appState.last_reported_at
          });
        }
        const activityByAgent = new Map(activityRows.map((status) => [status.agent_id, status]));
        for (const agent of rows) {
          agent.openApps = appsByAgent.get(agent.agent_id) || [];
          agent.activity = activityByAgent.get(agent.agent_id) || null;
        }
      }
      return send(res, 200, { agents: rows });
    }

    // ----- PANEL: campanas distintas (para el filtro) -----
    if (p === "/api/campaigns" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const params = [];
      let scope = `retired_at IS NULL AND campaign IS NOT NULL AND campaign <> ''`;
      if (!isAdmin(user)) {
        scope += ` AND campaign IN (SELECT campaign FROM user_campaigns WHERE user_id = ?)`;
        params.push(user.id);
      }
      const [rows] = await pool.query(
        `SELECT campaign, COUNT(*) AS total FROM agents WHERE ${scope} GROUP BY campaign ORDER BY campaign`, params);
      return send(res, 200, { campaigns: rows });
    }

    // ----- COMANDOS (speedtest / network-scan) -----
    // encolar (panel, requiere sesion)
    let mc = p.match(/^\/api\/agent\/([^/]+)\/command$/);
    if (mc && method === "POST") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!(await canAccessAgent(user, mc[1]))) return send(res, 403, { error: "Grupo no autorizado" });
      const b = await readBody(req);
      if (!["speedtest", "network-scan"].includes(b.type)) return send(res, 400, { error: "tipo invalido" });
      const id = "c" + Date.now() + Math.floor(Math.random() * 1000);
      await pool.query(`INSERT INTO commands (id, agent_id, type) VALUES (?,?,?)`, [id, mc[1], b.type]);
      return send(res, 200, { ok: true, id });
    }
    // agente pide pendientes
    mc = p.match(/^\/api\/agent\/([^/]+)\/commands$/);
    if (mc && method === "GET") {
      const [rows] = await pool.query(`SELECT id, type FROM commands WHERE agent_id=? AND status='pending' ORDER BY created_at`, [mc[1]]);
      return send(res, 200, { commands: rows });
    }
    // agente devuelve resultado
    mc = p.match(/^\/api\/agent\/([^/]+)\/commands\/([^/]+)\/ack$/);
    if (mc && method === "POST") {
      const b = await readBody(req);
      const ok = b.status !== "error";
      await pool.query(`UPDATE commands SET status=?, result_json=?, error=?, acked_at=NOW() WHERE id=?`,
        [ok ? "done" : "error", b.result ? JSON.stringify(b.result) : null, b.error || null, mc[2]]);
      return send(res, 200, { ok: true });
    }

    // ----- GEO (panel) -----
    if (p === "/api/geo" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const [[a]] = await pool.query(`SELECT ip_public FROM agents WHERE agent_id=?`, [requestedAgent]);
      if (!a || !a.ip_public) return send(res, 404, { error: "sin IP publica" });

      try {
        const result = await lookupGeo(a.ip_public);
        return send(res, 200, { ok: true, ip: a.ip_public, data: result.data, cached: result.cached });
      } catch (e) {
        return send(res, e.statusCode || 502, { error: e.message });
      }
    }

    // ----- HISTORIAL: Speed Test / diagnosticos, seccion independiente -----
    // Separado de /api/history a proposito: activity.html lo carga bajo
    // demanda (acordeon colapsado por defecto), solo cuando el admin lo
    // abre o cambia el rango, para no ejecutar esta consulta si nadie va a
    // ver el resultado. /api/history (mas abajo) NO se toca porque el panel
    // principal (index.html) todavia depende de su campo `history` para el
    // mini-resumen del detalle expandible de cada agente.
    if (p === "/api/history/commands" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const from = url.searchParams.get("from") ? new Date(url.searchParams.get("from")) : null;
      const to = url.searchParams.get("to") ? new Date(url.searchParams.get("to")) : null;
      if (!from || !to || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from > to) {
        return send(res, 400, { error: "rango de fechas invalido" });
      }
      const [rows] = await pool.query(
        `SELECT id, type, status, result_json, error, created_at, acked_at FROM commands
          WHERE agent_id=? AND status<>'pending' AND created_at BETWEEN ? AND ?
          ORDER BY created_at DESC LIMIT 50`, [requestedAgent, from, to]);
      return send(res, 200, { history: rows, range: { from: from.toISOString(), to: to.toISOString() } });
    }

    // ----- HISTORIAL: Auditoria de escucha remota, seccion independiente -----
    // Mismo criterio que /api/history/commands: activity.html la carga bajo
    // demanda (acordeon colapsado), solo admin, solo cuando se abre o se
    // pide "Actualizar". No toca /api/history.
    if (p === "/api/history/audio-sessions" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      if (!isAdmin(user)) return send(res, 403, { error: "Solo administradores" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const from = url.searchParams.get("from") ? new Date(url.searchParams.get("from")) : null;
      const to = url.searchParams.get("to") ? new Date(url.searchParams.get("to")) : null;
      if (!from || !to || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from > to) {
        return send(res, 400, { error: "rango de fechas invalido" });
      }
      const [rows] = await pool.query(
        `SELECT id, supervisor_username, status, requested_at, started_at, ended_at,
                end_reason, error_detail
           FROM audio_listening_sessions
          WHERE agent_id=? AND requested_at BETWEEN ? AND ?
          ORDER BY requested_at DESC LIMIT 250`, [requestedAgent, from, to]);
      return send(res, 200, { audioSessions: rows, range: { from: from.toISOString(), to: to.toISOString() } });
    }

    // ----- HISTORIAL: Actividad detallada, seccion independiente -----
    // Mismo criterio que /api/history/commands y /api/history/audio-sessions:
    // activity.html la carga bajo demanda (acordeon colapsado), solo cuando
    // se abre o se pide "Actualizar". No toca /api/history.
    if (p === "/api/history/activity" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const from = url.searchParams.get("from") ? new Date(url.searchParams.get("from")) : null;
      const to = url.searchParams.get("to") ? new Date(url.searchParams.get("to")) : null;
      if (!from || !to || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from > to) {
        return send(res, 400, { error: "rango de fechas invalido" });
      }
      const requestedLimit = Number(url.searchParams.get("limit") || 250);
      const limit = Number.isFinite(requestedLimit) ? Math.min(250, Math.max(1, Math.floor(requestedLimit))) : 250;
      const [rows] = await pool.query(
        `SELECT event_id, event_type, event_time, process_name, window_title, state,
                duration_ms, idle_ms, char_count, clipboard_sha256, clipboard_text,
                source_process, source_window_title
           FROM agent_activity_events
          WHERE agent_id=? AND event_time BETWEEN ? AND ?
          ORDER BY event_time DESC LIMIT ?`, [requestedAgent, from, to, limit]);
      const activity = rows.map((row) => ({ ...row, clipboard_text: isAdmin(user) ? row.clipboard_text : null }));
      return send(res, 200, { activity, range: { from: from.toISOString(), to: to.toISOString() } });
    }

    // ----- HISTORIAL: Bloques de teclado, seccion independiente -----
    // activity.html lo carga bajo demanda al abrir el acordeon; no toca /api/history.
    if (p === "/api/history/keyboard-blocks" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const from = url.searchParams.get("from") ? new Date(url.searchParams.get("from")) : null;
      const to = url.searchParams.get("to") ? new Date(url.searchParams.get("to")) : null;
      if (!from || !to || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from > to) {
        return send(res, 400, { error: "rango de fechas invalido" });
      }
      const [keyboardBlocks] = await pool.query(
        `SELECT MIN(event_id) AS event_id, block_start, MAX(block_end) AS block_end,
                SUM(keypress_count) AS keypress_count, MIN(first_key_at) AS first_key_at,
                MAX(last_key_at) AS last_key_at, MAX(complete) AS complete
           FROM agent_keyboard_blocks
          WHERE agent_id=? AND block_start BETWEEN ? AND ?
          GROUP BY block_start ORDER BY block_start DESC LIMIT 2500`, [requestedAgent, from, to]);
      const [keyboardDaily] = await pool.query(
        `SELECT work_day, first_active_at AS first_key_at, last_active_at AS last_key_at,
                keypress_count, active_blocks, inactive_blocks, span_seconds,
                evaluated_seconds, active_seconds, keyboard_seconds, foreground_seconds,
                assisted_seconds, inactive_seconds, allowed_pause_seconds, excess_pause_seconds,
                authorized_break_seconds, activity_pct, semaphore, automatic_semaphore,
                semaphore_overridden, semaphore_override_by, semaphore_override_at,
                max_inactive_seconds
           FROM agent_daily_stats
          WHERE agent_id=? AND work_day BETWEEN DATE(CONVERT_TZ(?,'+00:00','-05:00'))
            AND DATE(CONVERT_TZ(?,'+00:00','-05:00'))
          ORDER BY work_day DESC`, [requestedAgent, from, to]);
      return send(res, 200, { keyboardBlocks, keyboardDaily, range: { from: from.toISOString(), to: to.toISOString() } });
    }

    // ----- HISTORIAL: Jornada por dia, filtro independiente -----
    if (p === "/api/history/keyboard-daily" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const from = url.searchParams.get("from") ? new Date(url.searchParams.get("from")) : null;
      const to = url.searchParams.get("to") ? new Date(url.searchParams.get("to")) : null;
      if (!from || !to || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from > to) {
        return send(res, 400, { error: "rango de fechas invalido" });
      }
      const [keyboardDaily] = await pool.query(
        `SELECT work_day, first_active_at AS first_key_at, last_active_at AS last_key_at,
                keypress_count, active_blocks, inactive_blocks, span_seconds,
                evaluated_seconds, active_seconds, keyboard_seconds, foreground_seconds,
                assisted_seconds, inactive_seconds, allowed_pause_seconds, excess_pause_seconds,
                authorized_break_seconds, activity_pct, semaphore, automatic_semaphore,
                semaphore_overridden, semaphore_override_by, semaphore_override_at,
                max_inactive_seconds
           FROM agent_daily_stats
          WHERE agent_id=? AND work_day BETWEEN DATE(CONVERT_TZ(?,'+00:00','-05:00'))
            AND DATE(CONVERT_TZ(?,'+00:00','-05:00'))
          ORDER BY work_day DESC`, [requestedAgent, from, to]);
      return send(res, 200, { keyboardDaily, range: { from: from.toISOString(), to: to.toISOString() } });
    }

    // ----- HISTORIAL: correccion manual del semaforo diario -----
    if (p === "/api/history/keyboard-daily/semaphore" && method === "POST") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const body = await readBody(req);
      const requestedAgent = String(body.agentId || "").trim();
      const workDay = String(body.workDay || "").slice(0, 10);
      const requestedSemaphore = String(body.semaphore || "").toLowerCase();
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      if (!/^\d{4}-\d{2}-\d{2}$/.test(workDay)) return send(res, 400, { error: "Fecha invalida" });
      if (!["auto", "green", "yellow", "red", "gray"].includes(requestedSemaphore)) {
        return send(res, 400, { error: "Semaforo invalido" });
      }
      let result;
      if (requestedSemaphore === "auto") {
        [result] = await pool.query(
          `UPDATE agent_daily_stats
              SET semaphore=automatic_semaphore, semaphore_overridden=0,
                  semaphore_override_by=NULL, semaphore_override_at=NULL
            WHERE agent_id=? AND work_day=?`, [requestedAgent, workDay]);
      } else {
        [result] = await pool.query(
          `UPDATE agent_daily_stats
              SET semaphore=?, semaphore_overridden=1,
                  semaphore_override_by=?, semaphore_override_at=NOW(3)
            WHERE agent_id=? AND work_day=?`,
          [requestedSemaphore, user.username, requestedAgent, workDay]);
      }
      if (!result.affectedRows) return send(res, 404, { error: "Jornada no encontrada" });
      const [[updated]] = await pool.query(
        `SELECT work_day, semaphore, automatic_semaphore, semaphore_overridden,
                semaphore_override_by, semaphore_override_at
           FROM agent_daily_stats WHERE agent_id=? AND work_day=?`,
        [requestedAgent, workDay]);
      return send(res, 200, { ok: true, day: updated });
    }

    // ----- HISTORIAL: Resumen por aplicacion, seccion independiente -----
    if (p === "/api/history/app-summary" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const from = url.searchParams.get("from") ? new Date(url.searchParams.get("from")) : null;
      const to = url.searchParams.get("to") ? new Date(url.searchParams.get("to")) : null;
      if (!from || !to || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from > to) {
        return send(res, 400, { error: "rango de fechas invalido" });
      }
      const [appSummary] = await pool.query(
        `SELECT process_name,
                SUM(CASE WHEN event_type='app_closed' THEN COALESCE(duration_ms,0) ELSE 0 END) AS open_duration_ms,
                SUM(CASE WHEN event_type='foreground_ended' THEN COALESCE(duration_ms,0) ELSE 0 END) AS foreground_duration_ms,
                MAX(event_time) AS last_event_at
           FROM agent_activity_events
          WHERE agent_id=? AND event_time BETWEEN ? AND ? AND process_name IS NOT NULL
          GROUP BY process_name ORDER BY foreground_duration_ms DESC, open_duration_ms DESC`,
        [requestedAgent, from, to]);
      return send(res, 200, { appSummary, range: { from: from.toISOString(), to: to.toISOString() } });
    }

    // ----- ACTIVIDAD: estado liviano para la cabecera de activity.html -----
    if (p === "/api/history/current" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const [currentApps] = await pool.query(
        `SELECT process_name, is_open, opened_at, closed_at, last_reported_at
           FROM agent_app_state WHERE agent_id=? ORDER BY is_open DESC, process_name`, [requestedAgent]);
      const [[activityStatus]] = await pool.query(
        `SELECT agent_version, capabilities_json, input_state, idle_ms, last_reported_at
           FROM agent_activity_status WHERE agent_id=?`, [requestedAgent]);
      return send(res, 200, { currentApps, activityStatus: activityStatus || null });
    }

    // ----- HISTORIAL (panel) -----
    if (p === "/api/history" && method === "GET") {
      const user = await getSessionUser(req);
      if (!user) return send(res, 401, { error: "No autenticado" });
      const requestedAgent = url.searchParams.get("agent");
      if (!(await canAccessAgent(user, requestedAgent))) return send(res, 403, { error: "Grupo no autorizado" });
      const now = new Date();
      const defaultFrom = new Date(now.getTime() - 7 * 86400000);
      const from = url.searchParams.get("from") ? new Date(url.searchParams.get("from")) : defaultFrom;
      const to = url.searchParams.get("to") ? new Date(url.searchParams.get("to")) : now;
      const requestedLimit = Number(url.searchParams.get("limit") || 150);
      const limit = Number.isFinite(requestedLimit) ? Math.min(250, Math.max(1, Math.floor(requestedLimit))) : 150;
      if (!Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from > to) {
        return send(res, 400, { error: "rango de fechas invalido" });
      }
      const [rows] = await pool.query(
        `SELECT id, type, status, result_json, error, created_at, acked_at FROM commands
          WHERE agent_id=? AND status<>'pending' ORDER BY created_at DESC LIMIT 20`, [requestedAgent]);
      const [activityRows] = await pool.query(
        `SELECT event_id, event_type, event_time, process_name, window_title, state,
                duration_ms, idle_ms, char_count, clipboard_sha256, clipboard_text,
                source_process, source_window_title
           FROM agent_activity_events
          WHERE agent_id=? AND event_time BETWEEN ? AND ?
          ORDER BY event_time DESC LIMIT ?`, [requestedAgent, from, to, limit]);
      const [currentApps] = await pool.query(
        `SELECT process_name, is_open, opened_at, closed_at, last_reported_at
           FROM agent_app_state WHERE agent_id=? ORDER BY process_name`, [requestedAgent]);
      const [[activityStatus]] = await pool.query(
        `SELECT agent_version, capabilities_json, input_state, idle_ms, last_reported_at
           FROM agent_activity_status WHERE agent_id=?`, [requestedAgent]);
      let audioSessions = [];
      let cameraSessions = [];
      if (isAdmin(user)) {
        [audioSessions] = await pool.query(
          `SELECT id, supervisor_username, status, requested_at, started_at, ended_at,
                  end_reason, error_detail
             FROM audio_listening_sessions
            WHERE agent_id=? AND requested_at BETWEEN ? AND ?
            ORDER BY requested_at DESC LIMIT 250`, [requestedAgent, from, to]);
        [cameraSessions] = await pool.query(
          `SELECT id, supervisor_username, status, requested_at, started_at, ended_at,
                  end_reason, error_detail
             FROM camera_viewing_sessions
            WHERE agent_id=? AND requested_at BETWEEN ? AND ?
            ORDER BY requested_at DESC LIMIT 250`, [requestedAgent, from, to]);
      }
      const activity = activityRows.map((row) => ({
        ...row,
        clipboard_text: isAdmin(user) ? row.clipboard_text : null
      }));
      return send(res, 200, {
        history: rows,
        activity,
        currentApps,
        activityStatus: activityStatus || null,
        audioSessions,
        cameraSessions,
        range: { from: from.toISOString(), to: to.toISOString() }
      });
    }

    // ----- estatico / paginas (cualquier archivo html/js/css en web/) -----
    if (method === "GET") {
      const file = p === "/" ? "index.html"
        : p === "/supervicion" ? "supervicion/index.html"
        : p.replace(/^\/+/, "");
      if (/^[\w.-]+\.(html|js|css)$/.test(file)) return serveStatic(res, file);
      if (/^supervicion\/[\w.-]+\.(html|js|css)$/.test(file)) return serveStatic(res, file);
      if (/^downloads\/[\w.-]+\.exe$/.test(file)) return serveStatic(res, file);
    }

    send(res, 404, { error: "ruta no encontrada", path: p });
  } catch (err) {
    console.error("[http error]", err.message);
    send(res, 500, { error: err.message });
  }
});

// Señalización WebRTC aislada por contrato de módulo.
registerSignaling({
  server,
  pool,
  getSessionUser,
  canAccessAgent,
  isAdmin,
  isAgentTokenAuthorized,
  limitedString
});

// ---------- arranque (requiere MySQL) ----------
pool.query("SELECT 1").then(() => {
  server.listen(PORT, config.HOST, () => console.log(`[synervox-remoteov2] escuchando en ${config.HOST}:${PORT} (MySQL + WS /ws)`));
}).catch((err) => {
  console.error("[fatal] no conecta MySQL:", err.message);
  process.exit(1);
});
