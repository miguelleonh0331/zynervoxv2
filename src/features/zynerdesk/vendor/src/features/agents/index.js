"use strict";

function createAgentsService({ pool }) {
  async function setRetired(agentId, userId, retired) {
    const sql = retired
      ? `UPDATE agents
            SET retired_at=COALESCE(retired_at, NOW()),
                retired_by_user_id=COALESCE(retired_by_user_id, ?)
          WHERE agent_id=?`
      : `UPDATE agents
            SET retired_at=NULL, retired_by_user_id=NULL
          WHERE agent_id=?`;
    const params = retired ? [userId, agentId] : [agentId];
    const [result] = await pool.query(sql, params);
    if (result.affectedRows > 0) return true;
    const [[existing]] = await pool.query(`SELECT 1 AS found FROM agents WHERE agent_id=?`, [agentId]);
    return Boolean(existing);
  }

  return { setRetired };
}

module.exports = { createAgentsService };
