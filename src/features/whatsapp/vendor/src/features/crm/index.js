'use strict';

// Módulo crm — business suite: oportunidades con pipeline, tareas, auditoría.
// Esqueleto funcional: CRUD base aislado por empresa y rol.

function register(ctx) {
  const { api, db, io, requireUser, requireSupervisor, requireAdmin, resolveEmpresaId } = ctx;

  function guardEmpresa(req) {
    const empresaId = resolveEmpresaId(req);
    if (!empresaId) { const e = new Error('Falta contexto de empresa'); e.status = 400; throw e; }
    return empresaId;
  }

  // ------------------------------------------------------------ oportunidades
  api.get('/suite/opportunities', requireUser, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const { archived } = req.query;
      const where = ['o.empresa_id=?'];
      const params = [empresaId];
      if (archived === '1') where.push('o.archived=1');
      else where.push('o.archived=0');
      if (req.user.role === 'agent') { where.push('o.owner_user_id=?'); params.push(req.user.id); }
      const rows = await db.prepare(
        `SELECT o.*, c.phone, c.name AS contact_name, s.name AS stage_name, s.color AS stage_color
         FROM opportunities o
         JOIN contacts c ON c.id=o.contact_id
         LEFT JOIN crm_stages s ON s.id=o.stage_id
         WHERE ${where.join(' AND ')}
         ORDER BY o.updated_at DESC LIMIT 500`
      ).all(...params);
      res.json(rows);
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.post('/suite/opportunities', requireUser, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const { contact_id, title, value, currency, source } = req.body || {};
      if (!contact_id || !title) {
        return res.status(400).json({ error: 'contact_id y title son obligatorios' });
      }
      // Reactivar si existía archivada para el mismo contacto (regla del original).
      const previa = await db.prepare(
        'SELECT id, archived FROM opportunities WHERE empresa_id=? AND contact_id=?'
      ).get(empresaId, Number(contact_id));
      if (previa) {
        await db.prepare(
          'UPDATE opportunities SET archived=0, title=?, updated_at=CURRENT_TIMESTAMP WHERE id=?'
        ).run(title, previa.id);
        return res.json({ id: previa.id, reactivada: true });
      }
      const r = await db.prepare(
        `INSERT INTO opportunities (empresa_id, contact_id, owner_user_id, title, value, currency, source)
         VALUES (?,?,?,?,?,?,?)`
      ).run(empresaId, Number(contact_id), req.user.id, title, Number(value || 0),
        currency || 'PEN', source || null);
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.post('/suite/opportunities/:id/archive', requireUser, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const r = await db.prepare(
        'UPDATE opportunities SET archived=1 WHERE id=? AND empresa_id=?'
      ).run(Number(req.params.id), empresaId);
      if (!r.changes) return res.status(404).json({ error: 'No encontrada' });
      res.json({ ok: true });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  // ------------------------------------------------------------------ tareas
  api.get('/suite/tasks', requireUser, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const { status } = req.query;
      const where = ['t.empresa_id=?'];
      const params = [empresaId];
      if (status) { where.push('t.status=?'); params.push(String(status)); }
      if (req.user.role === 'agent') { where.push('t.assigned_user_id=?'); params.push(req.user.id); }
      const rows = await db.prepare(
        `SELECT t.*, c.phone AS contact_phone, u.display_name AS assigned_name
         FROM tasks t
         LEFT JOIN contacts c ON c.id=t.contact_id
         LEFT JOIN users u ON u.id=t.assigned_user_id
         WHERE ${where.join(' AND ')}
         ORDER BY t.due_at IS NULL, t.due_at ASC LIMIT 500`
      ).all(...params);
      res.json(rows);
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.post('/suite/tasks', requireUser, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const { title, contact_id, assigned_user_id, due_at, description, task_type } = req.body || {};
      if (!title) return res.status(400).json({ error: 'title es obligatorio' });
      const r = await db.prepare(
        `INSERT INTO tasks (empresa_id, contact_id, assigned_user_id, created_by_user_id, title, description, due_at, task_type)
         VALUES (?,?,?,?,?,?,?,?)`
      ).run(empresaId, contact_id ? Number(contact_id) : null,
        assigned_user_id ? Number(assigned_user_id) : req.user.id, req.user.id,
        title, description || null, due_at || null, task_type || 'followup');
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.post('/suite/tasks/:id/complete', requireUser, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const r = await db.prepare(
        `UPDATE tasks SET status='done', completed_at=CURRENT_TIMESTAMP
         WHERE id=? AND empresa_id=?`
      ).run(Number(req.params.id), empresaId);
      if (!r.changes) return res.status(404).json({ error: 'No encontrada' });
      res.json({ ok: true });
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  // ------------------------------------------------------------- pipeline
  api.get('/suite/stages', requireUser, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const rows = await db.prepare(
        'SELECT * FROM crm_stages WHERE empresa_id=? ORDER BY position'
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  api.post('/suite/stages', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const { name, color, position, is_won, is_lost } = req.body || {};
      if (!name) return res.status(400).json({ error: 'name es obligatorio' });
      const r = await db.prepare(
        `INSERT INTO crm_stages (empresa_id, name, color, position, is_won, is_lost)
         VALUES (?,?,?,?,?,?)`
      ).run(empresaId, name, color || '#607d8b', Number(position || 0), is_won ? 1 : 0, is_lost ? 1 : 0);
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) {
      if (err.code === 'ER_DUP_ENTRY') return res.status(409).json({ error: 'Etapa duplicada' });
      res.status(err.status || 500).json({ error: err.message });
    }
  });

  // Auditoría mínima del suite.
  api.get('/suite/audit', requireAdmin, async (req, res) => {
    try {
      const empresaId = guardEmpresa(req);
      const rows = await db.prepare(
        `SELECT a.*, u.username FROM audit_log a
         LEFT JOIN users u ON u.id=a.user_id
         WHERE a.empresa_id=? ORDER BY a.id DESC LIMIT 200`
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(err.status || 500).json({ error: err.message }); }
  });

  return {};
}

module.exports = { register };
