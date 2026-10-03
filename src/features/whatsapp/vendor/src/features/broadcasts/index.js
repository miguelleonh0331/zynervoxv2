'use strict';

// Módulo broadcasts — campañas, listas, envíos masivos por plantilla, optouts
// y métricas por destinatario. Motor de envío secuencial con pausa/reanudación.

function register(ctx) {
  const { api, db, io, requireAdmin, requireUser, resolveEmpresaId, sendMeta } = ctx;

  // Publicado para conversaciones (NO INTERESADO) y panel de bajas.
  async function markOptout(phone, empresaId, motivo) {
    await db.prepare(
      `INSERT INTO broadcast_optouts (phone, empresa_id, reason)
       VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE reason=VALUES(reason)`
    ).run(String(phone), Number(empresaId), motivo || null);
  }

  // Receipts desde el webhook de whatsapp (delivered/read/failed).
  async function onDeliveryReceipt(waMessageId, status) {
    if (!waMessageId) return;
    const map = { delivered: 'delivered_at', read: 'read_at' };
    const col = map[status];
    if (col) {
      await db.prepare(
        `UPDATE broadcast_recipients SET ${col}=CURRENT_TIMESTAMP WHERE wa_message_id=?`
      ).run(String(waMessageId));
    }
  }

  // ------------------------------------------------------------- motor de envío
  const running = new Set();

  async function runBroadcast(broadcastId) {
    if (running.has(Number(broadcastId))) return;
    running.add(Number(broadcastId));
    (async () => {
      try {
        const b = await db.prepare('SELECT * FROM broadcasts WHERE id=?').get(Number(broadcastId));
        if (!b || b.status !== 'running') return;
        const line = await db.prepare('SELECT * FROM \`lines\` WHERE id=?').get(b.line_id);
        if (!line) throw new Error('Línea inexistente');
        // Marcar running solo si estaba pending/paused.
        await db.prepare(
          "UPDATE broadcasts SET status='running', started_at=COALESCE(started_at,CURRENT_TIMESTAMP) WHERE id=? AND status IN ('pending','paused')"
        ).run(b.id);

        const recipients = await db.prepare(
          `SELECT r.* FROM broadcast_recipients r
           WHERE r.broadcast_id=? AND r.status='pending' ORDER BY r.id LIMIT 500`
        ).all(b.id);

        for (const rcpt of recipients) {
          const again = await db.prepare('SELECT status FROM broadcasts WHERE id=?').get(b.id);
          if (!again || again.status !== 'running') break;
          // Optout global por empresa.
          const optout = await db.prepare(
            'SELECT id FROM broadcast_optouts WHERE phone=? AND empresa_id=?'
          ).get(rcpt.phone, line.empresa_id);
          if (optout) {
            await db.prepare("UPDATE broadcast_recipients SET status='failed', error='optout' WHERE id=?").run(rcpt.id);
            await db.prepare('UPDATE broadcasts SET skipped=skipped+1 WHERE id=?').run(b.id);
            continue;
          }
          const payload = {
            messaging_product: 'whatsapp',
            to: rcpt.phone,
            type: 'template',
            template: {
              name: b.template_name,
              language: { code: b.template_language },
            },
          };
          try {
            const resp = await sendMeta({ line, payload });
            await db.prepare(
              `UPDATE broadcast_recipients SET status='sent', wa_message_id=?, sent_at=CURRENT_TIMESTAMP WHERE id=?`
            ).run(resp?.messages?.[0]?.id || null, rcpt.id);
            await db.prepare('UPDATE broadcasts SET sent=sent+1 WHERE id=?').run(b.id);
          } catch (err) {
            await db.prepare(
              "UPDATE broadcast_recipients SET status='failed', error=? WHERE id=?"
            ).run(String(err.message).slice(0, 500), rcpt.id);
            await db.prepare('UPDATE broadcasts SET failed=failed+1 WHERE id=?').run(b.id);
          }
          // Cortesía anti-spam entre envíos.
          await new Promise((r2) => setTimeout(r2, 250));
        }

        const pending = await db.prepare(
          "SELECT COUNT(*) AS n FROM broadcast_recipients WHERE broadcast_id=? AND status='pending'"
        ).get(b.id);
        if (Number(pending.n) === 0) {
          await db.prepare(
            "UPDATE broadcasts SET status='done', finished_at=CURRENT_TIMESTAMP WHERE id=?"
          ).run(b.id);
          io.to(`empresa:${line.empresa_id}:admins`).emit('broadcast:progreso', { id: b.id, status: 'done' });
        }
      } catch (err) {
        await db.prepare("UPDATE broadcasts SET status='failed', error=? WHERE id=?")
          .run(String(err.message).slice(0, 500), Number(broadcastId)).catch(() => {});
      } finally {
        running.delete(Number(broadcastId));
      }
    })();
  }

  // ------------------------------------------------------------------- API
  api.get('/campaigns', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      if (!empresaId) return res.status(400).json({ error: 'Falta contexto de empresa' });
      const rows = await db.prepare(
        `SELECT ca.* FROM campaigns ca JOIN \`lines\` l ON l.id=ca.line_id
         WHERE l.empresa_id=? AND ca.status='active' ORDER BY ca.id DESC`
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.post('/campaigns', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      const { name, line_id, template_name, template_language } = req.body || {};
      const line = await db.prepare('SELECT * FROM \`lines\` WHERE id=? AND empresa_id=?')
        .get(Number(line_id), empresaId);
      if (!line) return res.status(400).json({ error: 'Línea inválida para la empresa' });
      const r = await db.prepare(
        `INSERT INTO campaigns (name, line_id, template_name, template_language, created_by_user_id)
         VALUES (?,?,?,?,?)`
      ).run(name, Number(line_id), template_name, template_language || 'es', req.user.id);
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.get('/broadcast-lists', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      if (!empresaId) return res.status(400).json({ error: 'Falta contexto de empresa' });
      const rows = await db.prepare(
        `SELECT bl.*, (SELECT COUNT(*) FROM broadcast_list_contacts c WHERE c.list_id=bl.id) AS contactos
         FROM broadcast_lists bl WHERE bl.empresa_id=? ORDER BY bl.id DESC`
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.post('/broadcasts', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      const { campaign_id, list_id, name } = req.body || {};
      const campaign = await db.prepare(
        `SELECT ca.* FROM campaigns ca JOIN \`lines\` l ON l.id=ca.line_id
         WHERE ca.id=? AND l.empresa_id=?`
      ).get(Number(campaign_id), empresaId);
      const list = await db.prepare(
        'SELECT * FROM broadcast_lists WHERE id=? AND empresa_id=?'
      ).get(Number(list_id), empresaId);
      if (!campaign || !list) return res.status(400).json({ error: 'Campaña o lista inválida' });

      const r = await db.prepare(
        `INSERT INTO broadcasts (name, line_id, template_name, template_language,
          campaign_id, list_id, created_by_user_id)
         VALUES (?,?,?,?,?,?,?)`
      ).run(name || `Envío ${campaign.name}`, campaign.line_id, campaign.template_name,
        campaign.template_language, campaign.id, list.id, req.user.id);
      const broadcastId = r.lastInsertRowid;

      // Destinatarios: lista menos optouts de la empresa.
      await db.prepare(
        `INSERT IGNORE INTO broadcast_recipients (broadcast_id, phone)
         SELECT ?, c.phone FROM broadcast_list_contacts c
         WHERE c.list_id=?
           AND NOT EXISTS (
             SELECT 1 FROM broadcast_optouts o
             WHERE o.phone=c.phone AND o.empresa_id=?
           )`
      ).run(broadcastId, list.id, empresaId);
      await db.prepare(
        `UPDATE broadcasts SET total=(SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id=?)
         WHERE id=?`
      ).run(broadcastId, broadcastId);
      res.status(201).json({ id: broadcastId });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.post('/broadcasts/:id/start', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      const b = await db.prepare(
        `SELECT b.* FROM broadcasts b JOIN \`lines\` l ON l.id=b.line_id
         WHERE b.id=? AND l.empresa_id=?`
      ).get(Number(req.params.id), empresaId);
      if (!b) return res.status(404).json({ error: 'No encontrado' });
      if (!['pending', 'paused'].includes(b.status)) {
        return res.status(409).json({ error: `Estado ${b.status} no permite inicio` });
      }
      await db.prepare("UPDATE broadcasts SET status='running' WHERE id=?").run(b.id);
      runBroadcast(b.id);
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.post('/broadcasts/:id/pause', requireAdmin, async (req, res) => {
    try {
      await db.prepare(
        "UPDATE broadcasts SET status='paused' WHERE id=? AND status='running'"
      ).run(Number(req.params.id));
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.get('/broadcast-stats/:id', requireAdmin, async (req, res) => {
    try {
      const row = await db.prepare(
        `SELECT
           SUM(r.status='pending') AS pendientes,
           SUM(r.status='sent') AS enviados,
           SUM(r.status='failed') AS fallidos,
           SUM(r.delivered_at IS NOT NULL) AS entregados,
           SUM(r.read_at IS NOT NULL) AS leidos,
           SUM(r.replied_at IS NOT NULL) AS respondidos
         FROM broadcast_recipients r
         JOIN broadcasts b ON b.id=r.broadcast_id
         JOIN \`lines\` l ON l.id=b.line_id
         WHERE r.broadcast_id=? AND l.empresa_id=?`
      ).get(Number(req.params.id), resolveEmpresaId(req));
      res.json(row || {});
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.get('/broadcast-optouts', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      if (!empresaId) return res.status(400).json({ error: 'Falta contexto de empresa' });
      const rows = await db.prepare(
        'SELECT phone, reason, created_at FROM broadcast_optouts WHERE empresa_id=? ORDER BY id DESC LIMIT 1000'
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.post('/broadcast-optouts', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      const { phone, reason } = req.body || {};
      if (!phone) return res.status(400).json({ error: 'Falta phone' });
      await markOptout(phone, empresaId, reason || 'alta manual');
      res.status(201).json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  Object.assign(ctx, { runBroadcast, markOptout, onDeliveryReceipt });
  return { runBroadcast, markOptout, onDeliveryReceipt };
}

module.exports = { register };
