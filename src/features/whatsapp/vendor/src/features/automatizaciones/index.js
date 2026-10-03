'use strict';

// Módulo automatizaciones — flujos automatizados, API pública con claves,
// webhooks firmados y configuración IA. Esqueleto funcional seguro:
// CRUD base + disparador de flujos por mensaje entrante.

const crypto = require('crypto');

function register(ctx) {
  const { api, db, io, requireAdmin, resolveEmpresaId, encryptSecret, decryptSecret } = ctx;

  function guardEmpresa(req) {
    const empresaId = resolveEmpresaId(req);
    if (!empresaId) { const e = new Error('Falta contexto de empresa'); e.status = 400; throw e; }
    return empresaId;
  }

  // ------------------------------------------------------------------ flujos
  api.get('/flows', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const rows = await db.prepare(
        'SELECT * FROM automation_flows WHERE empresa_id=? ORDER BY id DESC'
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.post('/flows', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const { name, trigger_type, trigger_json } = req.body || {};
      if (!name) return res.status(400).json({ error: 'name es obligatorio' });
      const r = await db.prepare(
        `INSERT INTO automation_flows (empresa_id, name, trigger_type, trigger_json, created_by_user_id)
         VALUES (?,?,?,?,?)`
      ).run(empresaId, name, trigger_type || 'incoming', JSON.stringify(trigger_json || {}), req.user.id);
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.patch('/flows/:id', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const sets = [];
      const params = [];
      if ('name' in req.body) { sets.push('name=?'); params.push(req.body.name); }
      if ('active' in req.body) { sets.push('active=?'); params.push(req.body.active ? 1 : 0); }
      if ('trigger_json' in req.body) { sets.push('trigger_json=?'); params.push(JSON.stringify(req.body.trigger_json)); }
      if (!sets.length) return res.status(400).json({ error: 'Nada que actualizar' });
      params.push(Number(req.params.id), empresaId);
      const r = await db.prepare(
        `UPDATE automation_flows SET ${sets.join(',')} WHERE id=? AND empresa_id=?`
      ).run(...params);
      if (!r.changes) return res.status(404).json({ error: 'No encontrado' });
      res.json({ ok: true });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  // ------------------------------------------------------------ claves API
  api.get('/public-keys', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const rows = await db.prepare(
        `SELECT id, name, key_prefix, active, last_used_at, created_at
         FROM company_api_keys WHERE empresa_id=? ORDER BY id DESC`
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.post('/public-keys', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const { name } = req.body || {};
      if (!name) return res.status(400).json({ error: 'name es obligatorio' });
      const raw = `znb2_${crypto.randomBytes(24).toString('hex')}`;
      const keyHash = crypto.createHash('sha256').update(raw).digest('hex');
      const r = await db.prepare(
        `INSERT INTO company_api_keys (empresa_id, name, key_hash, key_prefix, created_by_user_id)
         VALUES (?,?,?,?,?)`
      ).run(empresaId, name, keyHash, raw.slice(0, 12), req.user.id);
      // La clave en claro se muestra UNA sola vez.
      res.status(201).json({ id: r.lastInsertRowid, key: raw });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.delete('/public-keys/:id', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const r = await db.prepare(
        'DELETE FROM company_api_keys WHERE id=? AND empresa_id=?'
      ).run(Number(req.params.id), empresaId);
      if (!r.changes) return res.status(404).json({ error: 'No encontrada' });
      res.json({ ok: true });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  // ------------------------------------------------- disparador de flujos
  // Punto de entrada para conversaciones: cada mensaje entrante humano
  // activa flujos 'incoming' activos de la empresa. Registro mínimo de runs.
  async function runFlowsForMessage({ line, contact, body }) {
    try {
      const flows = await db.prepare(
        `SELECT * FROM automation_flows
         WHERE empresa_id=? AND active=1 AND trigger_type='incoming'`
      ).all(line.empresa_id);
      for (const flow of flows) {
        const blocks = await db.prepare(
          'SELECT * FROM automation_blocks WHERE flow_id=? ORDER BY position LIMIT 1'
        ).all(flow.id);
        const first = blocks[0];
        if (!first || first.block_type !== 'message') continue;
        const config = JSON.parse(first.config_json || '{}');
        if (!config.body) continue;
        if (ctx.sendMeta) {
          await ctx.sendMeta({
            line,
            payload: {
              messaging_product: 'whatsapp',
              to: contact.phone,
              type: 'text',
              text: { body: String(config.body) },
            },
          });
          await db.prepare(
            `INSERT INTO automation_runs (empresa_id, flow_id, contact_id, status, current_position)
             VALUES (?,?,?, 'done', 1)`
          ).run(line.empresa_id, flow.id, contact.id);
        }
      }
    } catch (err) {
      console.error('[automatizaciones] runFlowsForMessage:', err.message);
    }
  }

  Object.assign(ctx, { runFlowsForMessage });
  return { runFlowsForMessage };
}

module.exports = { register };
