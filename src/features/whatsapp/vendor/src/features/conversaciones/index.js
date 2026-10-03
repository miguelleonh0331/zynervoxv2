'use strict';

// Módulo conversaciones — núcleo operativo: contactos, mensajes, entrantes del
// webhook, bandeja, notas, etiquetas/carpetas, Socket.IO.
// Publica en ctx: classifyIncoming, touchContactFromWebhook, emitContactRefresh.

function register(ctx) {
  const {
    api, db, io, config, requireUser, requireAdmin, requireSupervisor,
    resolveEmpresaId, canAccess, saveMediaBuffer, MEDIA_DIR, sendMeta,
  } = ctx;

  // ------------------------------------------------------------ contactos util
  async function touchContactFromWebhook({ line, waId, profileName }) {
    const existing = await db.prepare(
      'SELECT * FROM contacts WHERE phone=? AND line_id=?'
    ).get(String(waId), line.id);
    if (existing) return existing;
    const r = await db.prepare(
      `INSERT INTO contacts (wa_id, phone, name, line_id, status)
       VALUES (?,?,?,?,'open')`
    ).run(String(waId), String(waId), profileName || null, line.id);
    return db.prepare('SELECT * FROM contacts WHERE id=?').get(r.lastInsertRowid);
  }

  // Clasificación del original: autoanswer (primer entrante >10 palabras),
  // NO INTERESADO (baja + cierre), o humano con alertas.
  const NO_INTEREST = new Set(['no me interesa', 'no estoy interesado', 'no estoy interesada']);
  const normalize = (v) => String(v || '').trim().toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[.!¡¿?]+/g, '').replace(/\s+/g, ' ');
  const wordCount = (v) => { const t = String(v || '').trim(); return t ? t.split(/\s+/u).length : 0; };

  async function classifyIncoming({ line, waId, profileName, message, timestampMs }) {
    if (message.type !== 'text') {
      // Media/otros: se registra como mensaje normal (soporte básico).
      return insertIncoming({ line, waId, profileName, message, timestampMs, isAuto: 0, isOptout: 0 });
    }
    const body = message.text?.body || '';
    const norm = normalize(body);

    if (NO_INTEREST.has(norm)) {
      const contact = await insertIncoming({ line, waId, profileName, message, timestampMs, isAuto: 0, isOptout: 1 });
      await db.prepare("UPDATE contacts SET status='closed' WHERE id=?").run(contact.id);
      if (ctx.markOptout) {
        await ctx.markOptout(String(waId), line.empresa_id, 'No me interesa (respuesta automática)');
      }
      await emitContactRefresh(contact.id, { status: 'closed' });
      return contact;
    }

    const contact = await touchContactFromWebhook({ line, waId, profileName });
    const yaHayAuto = await db.prepare(
      `SELECT COUNT(*) AS n FROM messages
       WHERE contact_id=? AND is_auto_response=1`
    ).get(contact.id);
    let isAuto = 0;
    if (Number(yaHayAuto.n) === 0 && wordCount(body) > 10) isAuto = 1;
    return insertIncoming({ line, waId, profileName, message: { ...message, text: { body } }, timestampMs, isAuto, isOptout: 0, contactId: contact.id });
  }

  async function insertIncoming({ line, waId, profileName, message, timestampMs, isAuto, isOptout, contactId }) {
    const contact = contactId
      ? await db.prepare('SELECT * FROM contacts WHERE id=?').get(contactId)
      : await touchContactFromWebhook({ line, waId, profileName });
    const body = message.text?.body || (message.type === 'text' ? '' : `[${message.type}]`);
    const createdAt = new Date(timestampMs || Date.now()).toISOString().slice(0, 19).replace('T', ' ');
    const r = await db.prepare(
      `INSERT INTO messages (contact_id, direction, type, body, wa_message_id, status,
        created_at, is_auto_response, is_optout_response)
       VALUES (?, 'in', ?, ?, ?, 'received', ?, ?, ?)`
    ).run(
      contact.id, message.type || 'text', body, message.id || null,
      createdAt,
      isAuto, isOptout
    );
    await db.prepare('UPDATE contacts SET last_message_at=CURRENT_TIMESTAMP WHERE id=?').run(contact.id);
    await emitContactRefresh(contact.id, { last_message_at: 'now' });
    emitToContactRooms(
      line.empresa_id,
      contact.owner_user_id,
      'message:new',
      {
        contact_id: contact.id,
        message: {
          id: r.lastInsertRowid,
          contact_id: contact.id,
          direction: 'in',
          type: message.type || 'text',
          body,
          status: 'received',
          created_at: createdAt,
          is_auto_response: isAuto,
          is_optout_response: isOptout,
        },
      },
    );
    return contact;
  }

  // -------------------------------------------------------------- socket emit
  function roomForContact(contact) {
    return `empresa:${contact.empresa_id || lineEmpresa(contact)}`;
  }
  function lineEmpresa(contact) { return contact.empresa_id ?? 0; }

  function emitToContactRooms(empresaId, ownerUserId, event, payload) {
    try {
      io.to(`empresa:${empresaId}:admins`).emit(event, payload);
      if (ownerUserId) {
        io.to(`empresa:${empresaId}:user:${ownerUserId}`).emit(event, payload);
      }
    } catch (_err) { /* no interrumpir persistencia por un fallo de socket */ }
  }

  async function emitContactRefresh(contactId, patch) {
    try {
      const row = await db.prepare(
        `SELECT c.*, l.empresa_id AS empresa_id FROM contacts c
         JOIN \`lines\` l ON l.id=c.line_id WHERE c.id=?`
      ).get(contactId);
      if (!row) return;
      const payload = { contact: row, patch: patch || null };
      emitToContactRooms(row.empresa_id, row.owner_user_id, 'contact:refresh', payload);
      emitToContactRooms(row.empresa_id, row.owner_user_id, 'contacts:refresh', payload);
    } catch (_err) { /* no interrumpir el flujo por un fallo de emisión */ }
  }

  // ------------------------------------------------------------------- API
  // Bandeja: contactos visibles según rol.
  api.get('/contacts', requireUser, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      if (!empresaId) return res.status(400).json({ error: 'Falta contexto de empresa' });
      const { status, stage, q } = req.query;
      const where = ['l.empresa_id=?'];
      const params = [empresaId];
      if (req.user.role === 'agent') { where.push('c.owner_user_id=?'); params.push(req.user.id); }
      if (status) { where.push('c.status=?'); params.push(String(status)); }
      if (q) { where.push('(c.phone LIKE ? OR c.name LIKE ?)'); params.push(`%${q}%`, `%${q}%`); }
      const rows = await db.prepare(
        `SELECT c.*, l.name AS line_name, l.color AS line_color
         FROM contacts c JOIN \`lines\` l ON l.id=c.line_id
         WHERE ${where.join(' AND ')}
         ORDER BY c.last_message_at DESC, c.id DESC LIMIT 300`
      ).all(...params);
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.get('/contacts/:id/messages', requireUser, async (req, res) => {
    try {
      const contact = await db.prepare(
        `SELECT c.*, l.empresa_id AS empresa_id FROM contacts c
         JOIN \`lines\` l ON l.id=c.line_id WHERE c.id=?`
      ).get(Number(req.params.id));
      if (!contact) return res.status(404).json({ error: 'No encontrado' });
      if (!canAccess(req.user, contact, resolveEmpresaId(req))) {
        return res.status(403).json({ error: 'Sin acceso' });
      }
      const rows = await db.prepare(
        `SELECT m.*, u.display_name AS user_name FROM messages m
         LEFT JOIN users u ON u.id=m.user_id
         WHERE m.contact_id=? ORDER BY m.id ASC LIMIT 500`
      ).all(contact.id);
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Envío de texto dentro de la ventana de servicio.
  api.post('/contacts/:id/messages', requireUser, async (req, res) => {
    try {
      const contact = await db.prepare(
        `SELECT c.*, l.* , l.id AS line_id_num, l.empresa_id AS empresa_id
         FROM contacts c JOIN \`lines\` l ON l.id=c.line_id WHERE c.id=?`
      ).get(Number(req.params.id));
      if (!contact) return res.status(404).json({ error: 'No encontrado' });
      if (!canAccess(req.user, contact, resolveEmpresaId(req))) {
        return res.status(403).json({ error: 'Sin acceso' });
      }
      const { body, overrideToken } = req.body || {};
      if (!body || !String(body).trim()) return res.status(400).json({ error: 'Mensaje vacío' });
      if (ctx.assertSendable) await ctx.assertSendable(contact, overrideToken);

      const line = { id: contact.line_id, phone_number_id: contact.phone_number_id, empresa_id: contact.empresa_id };
      const payload = {
        messaging_product: 'whatsapp',
        to: contact.phone,
        type: 'text',
        text: { body: String(body) },
      };
      const graphResp = await sendMeta({ line, payload });
      const waId = graphResp?.messages?.[0]?.id || null;
      const r = await db.prepare(
        `INSERT INTO messages (contact_id, user_id, direction, type, body, wa_message_id, status)
         VALUES (?, ?, 'out', 'text', ?, ?, 'sent')`
      ).run(contact.id, req.user.id, String(body), waId);
      await db.prepare('UPDATE contacts SET last_message_at=CURRENT_TIMESTAMP WHERE id=?').run(contact.id);
      await emitContactRefresh(contact.id, { last_message_at: 'now' });
      res.status(201).json({ id: r.lastInsertRowid, wa_message_id: waId });
    } catch (err) {
      if (err.code === 'OUT_OF_WINDOW') return res.status(409).json({ error: err.message, code: err.code });
      if (err.code === 'OVERRIDE_INVALID' || err.code === 'OVERRIDE_EXPIRED') {
        return res.status(403).json({ error: err.message, code: err.code });
      }
      res.status(500).json({ error: err.message });
    }
  });

  // Asignación y liberación de agente (patrón del original: new ↔ in_progress).
  api.post('/contacts/:id/assign', requireSupervisor, async (req, res) => {
    try {
      const { userId } = req.body || {};
      const target = userId ? Number(userId) : req.user.id;
      const r = await db.prepare(
        `UPDATE contacts SET owner_user_id=?, assigned_at=CURRENT_TIMESTAMP,
         assigned_by_user_id=? WHERE id=?`
      ).run(target, req.user.id, Number(req.params.id));
      if (!r.changes) return res.status(404).json({ error: 'No encontrado' });
      await emitContactRefresh(Number(req.params.id), { owner_user_id: target });
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Notas internas.
  api.post('/contacts/:id/notes', requireUser, async (req, res) => {
    try {
      const { body } = req.body || {};
      if (!body || !String(body).trim()) return res.status(400).json({ error: 'Nota vacía' });
      const r = await db.prepare(
        `INSERT INTO internal_notes (empresa_id, contact_id, user_id, body) VALUES (?,?,?,?)`
      ).run(resolveEmpresaId(req), Number(req.params.id), req.user.id, String(body));
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.get('/contacts/:id/notes', requireUser, async (req, res) => {
    try {
      const rows = await db.prepare(
        `SELECT n.*, u.display_name AS user_name FROM internal_notes n
         LEFT JOIN users u ON u.id=n.user_id WHERE n.contact_id=? ORDER BY n.id DESC`
      ).all(Number(req.params.id));
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Cierre / reapertura.
  api.post('/contacts/:id/close', requireUser, async (req, res) => {
    try {
      await db.prepare("UPDATE contacts SET status='closed' WHERE id=?").run(Number(req.params.id));
      await emitContactRefresh(Number(req.params.id), { status: 'closed' });
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.post('/contacts/:id/reopen', requireUser, async (req, res) => {
    try {
      await db.prepare("UPDATE contacts SET status='open' WHERE id=?").run(Number(req.params.id));
      await emitContactRefresh(Number(req.params.id), { status: 'open' });
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Contadores por etapa (esqueleto operativo; SLA completo en mejoras).
  api.get('/stages', requireUser, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      if (!empresaId) return res.status(400).json({ error: 'Falta contexto de empresa' });
      const ownerFilter = req.user.role === 'agent' ? 'AND c.owner_user_id=' + Number(req.user.id) : '';
      const rows = await db.prepare(
        `SELECT
           SUM(c.status='open' AND c.owner_user_id IS NULL) AS nuevas,
           SUM(c.status='open' AND c.owner_user_id IS NOT NULL
               AND (c.last_message_at IS NULL OR c.last_message_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))) AS sin_responder,
           SUM(c.status='open' AND c.owner_user_id IS NOT NULL) AS en_curso,
           SUM(c.status='closed' AND DATE(c.last_message_at)=CURDATE()) AS cerradas_hoy
         FROM contacts c JOIN \`lines\` l ON l.id=c.line_id
         WHERE l.empresa_id=? ${ownerFilter}`
      ).get(empresaId);
      res.json(rows || {});
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  Object.assign(ctx, { classifyIncoming, touchContactFromWebhook, emitContactRefresh });
  return { classifyIncoming, touchContactFromWebhook, emitContactRefresh };
}

module.exports = { register };
