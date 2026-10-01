'use strict';

// Módulo empresas — gestión multiempresa, credenciales Meta cifradas, líneas
// de WhatsApp y usuarios administradores por empresa.
// Publica en ctx: credentialsForLine, empresaAudit.

const { encryptSecret, decryptSecret } = require('../../shared/crypto');

function register(ctx) {
  const { db, api, requireSuperadmin, requireAdmin, resolveEmpresaId } = ctx;

  // Router local. La guardia se aplica por ruta para no bloquear endpoints
  // posteriores del API que pertenecen a administradores de empresa.
  const express = require('express');
  const sa = express.Router();
  api.use('/', sa);

  // ------------------------------------------------------------ servicios

  // Credenciales Meta descifradas para una línea (o la empresa de la línea).
  async function credentialsForLine(line) {
    if (!line || line.empresa_id == null) {
      throw new Error('La línea no pertenece a ninguna empresa con credenciales');
    }
    const cred = await db.prepare(
      'SELECT * FROM empresa_credenciales WHERE empresa_id=?'
    ).get(line.empresa_id);
    if (!cred || !cred.access_token_enc) {
      throw new Error('La empresa de la línea no tiene credenciales configuradas');
    }
    return {
      empresaId: Number(line.empresa_id),
      wabaId: cred.waba_id || null,
      appId: cred.app_id || null,
      graphVersion: cred.graph_version || 'v25.0',
      accessToken: decryptSecret({
        enc: cred.access_token_enc, iv: cred.access_token_iv, tag: cred.access_token_tag,
      }),
      appSecret: decryptSecret({
        enc: cred.app_secret_enc, iv: cred.app_secret_iv, tag: cred.app_secret_tag,
      }),
      verifyToken: decryptSecret({
        enc: cred.verify_token_enc, iv: cred.verify_token_iv, tag: cred.verify_token_tag,
      }),
    };
  }

  async function empresaAudit(empresaId, actorUserId, accion, detalle) {
    await db.prepare(
      'INSERT INTO empresa_audit (empresa_id, actor_user_id, accion, detalle) VALUES (?,?,?,?)'
    ).run(empresaId, actorUserId ?? null, accion, detalle ? JSON.stringify(detalle) : null);
  }

  // ------------------------------------------------------------------ API

  sa.get('/empresas', requireSuperadmin, async (_req, res) => {
    try {
      const rows = await db.prepare(
        `SELECT e.*, c.access_token_hint,
                (SELECT COUNT(*) FROM \`lines\` l WHERE l.empresa_id=e.id) AS lines_count
         FROM empresas e
         LEFT JOIN empresa_credenciales c ON c.empresa_id=e.id
         ORDER BY e.id`
      ).all();
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  sa.post('/empresas', requireSuperadmin, async (req, res) => {
    try {
      const { nombre, slug, contacto_email, contacto_telefono, plan, notas } = req.body || {};
      if (!nombre || !slug) return res.status(400).json({ error: 'nombre y slug son obligatorios' });
      const r = await db.prepare(
        `INSERT INTO empresas (nombre, slug, contacto_email, contacto_telefono, plan, notas, created_by_user_id)
         VALUES (?,?,?,?,?,?,?)`
      ).run(nombre, String(slug).toLowerCase(), contacto_email || null, contacto_telefono || null,
        plan || 'basico', notas || null, req.user.id);
      await empresaAudit(r.lastInsertRowid, req.user.id, 'empresa_creada', { nombre, slug });
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) {
      if (err.code === 'ER_DUP_ENTRY') return res.status(409).json({ error: 'slug duplicado' });
      res.status(500).json({ error: err.message });
    }
  });

  sa.get('/empresas/:id', requireSuperadmin, async (req, res) => {
    try {
      const row = await db.prepare('SELECT * FROM empresas WHERE id=?').get(req.params.id);
      if (!row) return res.status(404).json({ error: 'No encontrada' });
      res.json(row);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  sa.patch('/empresas/:id', requireSuperadmin, async (req, res) => {
    try {
      const allowed = ['nombre', 'contacto_email', 'contacto_telefono', 'plan', 'notas', 'estado', 'salud_corte_automatico'];
      const sets = [];
      const params = [];
      for (const k of allowed) {
        if (k in (req.body || {})) { sets.push(`${k}=?`); params.push(req.body[k]); }
      }
      if (!sets.length) return res.status(400).json({ error: 'Nada que actualizar' });
      params.push(req.params.id);
      const r = await db.prepare(`UPDATE empresas SET ${sets.join(',')} WHERE id=?`).run(...params);
      if (!r.changes) return res.status(404).json({ error: 'No encontrada' });
      await empresaAudit(req.params.id, req.user.id, 'empresa_editada', req.body);
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Credenciales Meta por empresa (siempre cifradas; nunca se devuelven).
  sa.put('/empresas/:id/credenciales', requireSuperadmin, async (req, res) => {
    try {
      const empresaId = Number(req.params.id);
      const exists = await db.prepare('SELECT id FROM empresas WHERE id=?').get(empresaId);
      if (!exists) return res.status(404).json({ error: 'No encontrada' });
      const fields = {
        waba_id: req.body.waba_id || null,
        phone_number_id_default: req.body.phone_number_id_default || null,
        graph_version: req.body.graph_version || 'v25.0',
        app_id: req.body.app_id || null,
      };
      for (const [k, v] of Object.entries({ access_token: req.body.access_token, app_secret: req.body.app_secret, verify_token: req.body.verify_token })) {
        if (!v) continue;
        const s = encryptSecret(v);
        const map = {
          access_token: ['access_token_enc', 'access_token_iv', 'access_token_tag', 'access_token_hint'],
          app_secret: ['app_secret_enc', 'app_secret_iv', 'app_secret_tag'],
          verify_token: ['verify_token_enc', 'verify_token_iv', 'verify_token_tag'],
        }[k];
        fields[map[0]] = s.enc; fields[map[1]] = s.iv; fields[map[2]] = s.tag;
        if (map[3]) fields[map[3]] = s.hint;
      }
      fields.updated_by_user_id = req.user.id;
      const cols = Object.keys(fields);
      const params = [empresaId, ...cols.map((c) => fields[c])];
      const updates = cols.filter((c) => c !== 'empresa_id')
        .map((c) => `${c}=VALUES(${c})`).join(', ');
      await db.prepare(
        `INSERT INTO empresa_credenciales (empresa_id, ${cols.join(', ')})
         VALUES (${['?'].repeat(cols.length + 1).join(',')})
         ON DUPLICATE KEY UPDATE ${updates}, updated_at=CURRENT_TIMESTAMP`
      ).run(...params);
      await empresaAudit(empresaId, req.user.id, 'credenciales_actualizadas', {
        campos: Object.keys(fields).filter((c) => c.endsWith('_enc')),
      });
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Probar credenciales contra Graph API (sin exponer secretos).
  sa.post('/empresas/:id/probar', requireSuperadmin, async (req, res) => {
    try {
      const empresaId = Number(req.params.id);
      const line = { empresa_id: empresaId };
      const cred = await credentialsForLine(line);
      const url = `https://graph.facebook.com/${cred.graphVersion}/oauth/access_token_info` +
        `?access_token=${encodeURIComponent(cred.accessToken)}`;
      const resp = await fetch(url);
      const ok = resp.ok;
      const msg = ok ? 'Token válido' : `HTTP ${resp.status}`;
      await db.prepare(
        `UPDATE empresa_credenciales SET ultima_prueba_at=CURRENT_TIMESTAMP,
         ultima_prueba_ok=?, ultima_prueba_msg=? WHERE empresa_id=?`
      ).run(ok ? 1 : 0, msg, empresaId);
      await empresaAudit(empresaId, req.user.id, 'credenciales_probadas', { ok, msg });
      res.json({ ok, msg });
    } catch (err) {
      res.status(500).json({ ok: false, error: err.message });
    }
  });

  // Líneas por empresa (superadmin).
  sa.get('/empresas/:id/lines', requireSuperadmin, async (req, res) => {
    try {
      const rows = await db.prepare(
        `SELECT l.*,
           (SELECT COUNT(*) FROM contacts c WHERE c.line_id=l.id) AS contacts_count,
           (SELECT COUNT(*) FROM user_lines ul JOIN users u ON u.id=ul.user_id
             WHERE ul.line_id=l.id AND u.role='agent' AND u.active=1) AS agents_count
         FROM \`lines\` l WHERE l.empresa_id=? ORDER BY l.id`
      ).all(Number(req.params.id));
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  sa.post('/empresas/:id/lines', requireSuperadmin, async (req, res) => {
    try {
      const { name, phone_number_id, color } = req.body || {};
      if (!name || !phone_number_id) {
        return res.status(400).json({ error: 'name y phone_number_id son obligatorios' });
      }
      if (!/^\d+$/.test(String(phone_number_id))) {
        return res.status(400).json({ error: 'Phone Number ID debe ser numérico' });
      }
      const r = await db.prepare(
        'INSERT INTO \`lines\` (name, phone_number_id, color, empresa_id) VALUES (?,?,?,?)'
      ).run(name, String(phone_number_id), color || '#25d366', Number(req.params.id));
      await empresaAudit(req.params.id, req.user.id, 'linea_creada', { name, phone_number_id });
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) {
      if (err.code === 'ER_DUP_ENTRY') return res.status(409).json({ error: 'Phone Number ID ya registrado' });
      res.status(500).json({ error: err.message });
    }
  });

  sa.patch('/empresas/:id/lines/:lineId', requireSuperadmin, async (req, res) => {
    try {
      const allowed = ['name', 'color', 'active', 'phone_number_id'];
      if ('phone_number_id' in (req.body || {}) && !/^\d+$/.test(String(req.body.phone_number_id))) {
        return res.status(400).json({ error: 'Phone Number ID debe ser numérico' });
      }
      const sets = [];
      const params = [];
      for (const k of allowed) {
        if (k in (req.body || {})) { sets.push(`${k}=?`); params.push(req.body[k]); }
      }
      if (!sets.length) return res.status(400).json({ error: 'Nada que actualizar' });
      params.push(req.params.lineId, Number(req.params.id));
      const r = await db.prepare(
        `UPDATE \`lines\` SET ${sets.join(',')} WHERE id=? AND empresa_id=?`
      ).run(...params);
      if (!r.changes) return res.status(404).json({ error: 'Línea no encontrada' });
      await empresaAudit(req.params.id, req.user.id, 'linea_editada', req.body);
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  sa.delete('/empresas/:id/lines/:lineId', requireSuperadmin, async (req, res) => {
    try {
      // Borrado lógico si tiene datos; físico solo si no tiene contactos.
      const uso = await db.prepare(
        'SELECT COUNT(*) AS n FROM contacts WHERE line_id=?'
      ).get(Number(req.params.lineId));
      if (Number(uso.n) > 0) {
        await db.prepare('UPDATE \`lines\` SET active=0 WHERE id=? AND empresa_id=?')
          .run(Number(req.params.lineId), Number(req.params.id));
        await empresaAudit(req.params.id, req.user.id, 'linea_desactivada', { id: req.params.lineId });
        return res.json({ ok: true, desactivada: true });
      }
      await db.prepare('DELETE FROM \`lines\` WHERE id=? AND empresa_id=?')
        .run(Number(req.params.lineId), Number(req.params.id));
      await empresaAudit(req.params.id, req.user.id, 'linea_eliminada', { id: req.params.lineId });
      res.json({ ok: true, eliminada: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Usuarios admin por empresa (el superadmin gestiona solo admins, no agentes).
  sa.get('/empresas/:id/admins', requireSuperadmin, async (req, res) => {
    try {
      const rows = await db.prepare(
        `SELECT id, username, display_name, active, created_at
         FROM users WHERE empresa_id=? AND role='admin' ORDER BY id`
      ).all(Number(req.params.id));
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  sa.post('/empresas/:id/admins', requireSuperadmin, async (req, res) => {
    try {
      const bcrypt = require('bcryptjs');
      const { username, password, display_name } = req.body || {};
      if (!username || !password) {
        return res.status(400).json({ error: 'username y password son obligatorios' });
      }
      const hash = bcrypt.hashSync(String(password), 10);
      const r = await db.prepare(
        `INSERT INTO users (username, password_hash, display_name, role, empresa_id)
         VALUES (?,?,?,'admin',?)`
      ).run(String(username).trim(), hash, display_name || username, Number(req.params.id));
      await empresaAudit(req.params.id, req.user.id, 'admin_creado', { username });
      res.status(201).json({ id: r.lastInsertRowid });
    } catch (err) {
      if (err.code === 'ER_DUP_ENTRY') return res.status(409).json({ error: 'usuario ya existe' });
      res.status(500).json({ error: err.message });
    }
  });

  sa.patch('/empresas/:id/admins/:userId', requireSuperadmin, async (req, res) => {
    try {
      const sets = [];
      const params = [];
      if ('display_name' in (req.body || {})) { sets.push('display_name=?'); params.push(req.body.display_name); }
      if ('active' in (req.body || {})) { sets.push('active=?'); params.push(req.body.active ? 1 : 0); }
      if (req.body && req.body.password) {
        const bcrypt = require('bcryptjs');
        sets.push('password_hash=?');
        params.push(bcrypt.hashSync(String(req.body.password), 10));
      }
      if (!sets.length) return res.status(400).json({ error: 'Nada que actualizar' });
      params.push(Number(req.params.userId), Number(req.params.id));
      const r = await db.prepare(
        `UPDATE users SET ${sets.join(',')} WHERE id=? AND empresa_id=? AND role='admin'`
      ).run(...params);
      if (!r.changes) return res.status(404).json({ error: 'Admin no encontrado' });
      await empresaAudit(req.params.id, req.user.id, 'admin_editado', { id: req.params.userId });
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  sa.delete('/empresas/:id/admins/:userId', requireSuperadmin, async (req, res) => {
    try {
      const r = await db.prepare(
        "DELETE FROM users WHERE id=? AND empresa_id=? AND role='admin'"
      ).run(Number(req.params.userId), Number(req.params.id));
      if (!r.changes) return res.status(404).json({ error: 'Admin no encontrado' });
      await empresaAudit(req.params.id, req.user.id, 'admin_eliminado', { id: req.params.userId });
      res.json({ ok: true });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  // Lectura de líneas para admins de empresa (solo lectura, mismo esquema).
  api.get('/my-lines', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      if (!empresaId) return res.status(400).json({ error: 'Falta contexto de empresa' });
      const rows = await db.prepare(
        'SELECT id, name, color, phone_number_id, active FROM \`lines\` WHERE empresa_id=? ORDER BY id'
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  return { credentialsForLine, empresaAudit };
}

module.exports = { register };
