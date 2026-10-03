"use strict";

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
