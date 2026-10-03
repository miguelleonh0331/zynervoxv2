"use strict";

// Mismo criterio que el servicio de auth. Antes se usaba isAdmin(user) dentro de
// canAccessAgent sin estar definido ni importado en este modulo: cualquier
// llamada a canAccessAgent (session:join de remoteo, audio:start, camera:start,
// control:input y los detalles por-agente del panel) lanzaba
// "ReferenceError: isAdmin is not defined" y, al ocurrir dentro del handler
// async del WebSocket, tumbaba TODO el proceso del servidor. Resultado: el
// remoteo nunca podia iniciarse.
function isAdmin(user) {
  return Boolean(user) && user.role === "admin";
}

function createUsersService({ pool }) {
  async function getUserCampaigns(userId) {
    const [rows] = await pool.query(
      `SELECT campaign FROM user_campaigns WHERE user_id = ? ORDER BY campaign`, [userId]);
    return rows.map((row) => row.campaign);
  }

  async function canAccessAgent(user, agentId) {
    if (!user || !agentId) return false;
    if (isAdmin(user)) {
      const [[agent]] = await pool.query(`SELECT 1 FROM agents WHERE agent_id = ?`, [agentId]);
      return Boolean(agent);
    }
    const [[agent]] = await pool.query(
      `SELECT 1
         FROM agents a
         JOIN user_campaigns uc ON uc.campaign = a.campaign
        WHERE a.agent_id = ? AND uc.user_id = ?`, [agentId, user.id]);
    return Boolean(agent);
  }

  async function replaceUserCampaigns(connection, userId, campaigns) {
    const clean = [...new Set((Array.isArray(campaigns) ? campaigns : [])
      .map((value) => String(value || "").trim()).filter(Boolean))];
    await connection.query(`DELETE FROM user_campaigns WHERE user_id = ?`, [userId]);
    for (const campaign of clean) {
      await connection.query(
        `INSERT INTO user_campaigns (user_id, campaign) VALUES (?, ?)`, [userId, campaign]);
    }
  }

  // ---------- estatico ----------

  return { getUserCampaigns, canAccessAgent, replaceUserCampaigns };
}

module.exports = { createUsersService };
