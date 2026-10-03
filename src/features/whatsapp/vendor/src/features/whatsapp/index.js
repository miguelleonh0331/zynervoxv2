'use strict';

// Módulo whatsapp — webhook firmado de Meta, envío a Graph API, descarga de
// media y ventana de servicio con overrides de un solo uso.
// Publica en ctx: sendMeta, downloadMetaMedia, assertSendable.
// Publica entrantes en ctx para conversaciones: handleIncomingValue.

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

function register(ctx) {
  const {
    app, api, db, config, upload, saveMediaBuffer, MEDIA_DIR,
    credentialsForLine, classifyIncoming, onDeliveryReceipt, applyWebhookHealthUpdate,
  } = ctx;

  // ------------------------------------------------------------ webhook GET
  app.get(`${config.BASE}/webhook/meta`, async (req, res) => {
    try {
      const mode = req.query['hub.mode'];
      const token = req.query['hub.verify_token'];
      const challenge = req.query['hub.challenge'];
      if (mode !== 'subscribe' || !token) return res.sendStatus(404);
      // El verify_token está cifrado por empresa: probamos contra todas.
      const rows = await db.prepare(
        `SELECT c.verify_token_enc, c.verify_token_iv, c.verify_token_tag
         FROM empresa_credenciales c WHERE c.verify_token_enc IS NOT NULL`
      ).all();
      for (const row of rows) {
        const stored = decryptVerify(row);
        if (stored && crypto.timingSafeEqual(Buffer.from(stored), Buffer.from(String(token)))) {
          return res.status(200).send(String(challenge || ''));
        }
      }
      return res.sendStatus(403);
    } catch (err) {
      console.error('[whatsapp] verify:', err.message);
      return res.sendStatus(500);
    }
  });

  function decryptVerify(row) {
    try {
      const { decryptSecret } = require('../shared/crypto');
      return decryptSecret({ enc: row.verify_token_enc, iv: row.verify_token_iv, tag: row.verify_token_tag });
    } catch (_e) { return null; }
  }

  // ------------------------------------------------------------- webhook POST
  app.post(`${config.BASE}/webhook/meta`, expressRawJson(ctx), async (req, res) => {
    // Responder 200 rápido y procesar en segundo plano (Meta reintenta si tarda).
    res.sendStatus(200);
    try {
      const body = req.body;
      const entries = body?.entry || [];
      for (const entry of entries) {
        for (const change of entry.changes || []) {
          const value = change.value || {};
          const phoneNumberId = value.metadata?.phone_number_id;
          if (!phoneNumberId) continue;
          const line = await db.prepare(
            'SELECT * FROM \`lines\` WHERE phone_number_id=? AND active=1'
          ).get(String(phoneNumberId));
          if (!line) {
            console.warn(`[whatsapp] línea desconocida: ${phoneNumberId}`);
            continue;
          }
          // Validar firma con el app secret de la empresa resuelta.
          const cred = await credentialsForLine(line).catch(() => null);
          if (cred && cred.appSecret) {
            const signature = req.headers['x-hub-signature-256'] || '';
            const expected = 'sha256=' +
              crypto.createHmac('sha256', cred.appSecret).update(req.rawBody).digest('hex');
            const a = Buffer.from(String(signature));
            const b = Buffer.from(expected);
            if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
              console.warn('[whatsapp] firma inválida (procesamiento abortado)');
              continue;
            }
          }

          // Estados de mensajes (sent/delivered/read/failed) → destinatarios.
          for (const st of value.statuses || []) {
            if (onDeliveryReceipt) await onDeliveryReceipt(st.id, st.status, st.errors).catch(() => {});
          }

          // Actualizaciones de salud en tiempo real → módulo salud.
          if (applyWebhookHealthUpdate) await applyWebhookHealthUpdate(value).catch(() => {});

          // Mensajes entrantes → módulo conversaciones.
          for (const msg of value.messages || []) {
            if (classifyIncoming) {
              await classifyIncoming({
                line,
                waId: msg.from,
                profileName: value.contacts?.[0]?.profile?.name || null,
                message: msg,
                timestampMs: Number(msg.timestamp || 0) * 1000,
              }).catch((err) => console.error('[whatsapp] entrante:', err.message));
            }
          }
        }
      }
    } catch (err) {
      console.error('[whatsapp] webhook:', err.message);
    }
  });

  // El webhook necesita el cuerpo crudo para validar la firma; el JSON global
  // del app ya lo guarda en req.rawBody (verify callback en server.js).
  function expressRawJson(ctxLocal) { return (req, res, next) => next(); }

  // ----------------------------------------------------------------- sendMeta
  async function sendMeta({ line, payload }) {
    const cred = await credentialsForLine(line);
    const version = cred.graphVersion || 'v25.0';
    const url = `https://graph.facebook.com/${version}/${line.phone_number_id}/messages`;
    const resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${cred.accessToken}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      const err = new Error(data?.error?.message || `Graph API HTTP ${resp.status}`);
      err.graph = data?.error;
      throw err;
    }
    return data;
  }

  // --------------------------------------------------------------- ventana 24h
  // Margen propio sobre la ventana real de Meta (config.SERVICE_WINDOW_HOURS).
  async function lastInboundAt(contactId) {
    const row = await db.prepare(
      `SELECT created_at FROM messages
       WHERE contact_id=? AND direction='in'
         AND is_auto_response=0 AND is_optout_response=0
       ORDER BY id DESC LIMIT 1`
    ).get(contactId);
    return row ? row.created_at : null;
  }

  async function assertSendable(contact, overrideToken) {
    const last = await lastInboundAt(contact.id);
    if (!last) {
      // Nunca escribió: solo plantillas (las maneja broadcasts con sendMeta).
      const err = new Error('Fuera de ventana: el contacto no tiene mensajes entrantes');
      err.code = 'OUT_OF_WINDOW';
      throw err;
    }
    const lastMs = new Date(String(last).replace(' ', 'T') + 'Z').getTime();
    const limitMs = Date.now() - config.SERVICE_WINDOW_HOURS * 3600_000;
    if (lastMs >= limitMs) return; // Dentro del margen propio.

    // Fuera de margen: requiere override de un solo uso.
    if (overrideToken) {
      const row = await db.prepare(
        `SELECT id, expires_at FROM window_overrides
         WHERE contact_id=? AND used_at IS NULL AND id=?`
      ).get(contact.id, Number(overrideToken));
      if (!row) { const e = new Error('Override inválido'); e.code = 'OVERRIDE_INVALID'; throw e; }
      const expMs = new Date(String(row.expires_at).replace(' ', 'T') + 'Z').getTime();
      if (expMs < Date.now()) { const e = new Error('Override caducado'); e.code = 'OVERRIDE_EXPIRED'; throw e; }
      await db.prepare('UPDATE window_overrides SET used_at=CURRENT_TIMESTAMP WHERE id=?').run(row.id);
      return;
    }
    const err = new Error('Fuera de ventana de servicio: requiere autorización');
    err.code = 'OUT_OF_WINDOW';
    throw err;
  }

  // ------------------------------------------------------------------- media
  async function downloadMetaMedia(mediaId, line) {
    const cred = await credentialsForLine(line);
    const version = cred.graphVersion || 'v25.0';
    const infoResp = await fetch(`https://graph.facebook.com/${version}/${mediaId}`, {
      headers: { 'Authorization': `Bearer ${cred.accessToken}` },
    });
    const info = await infoResp.json();
    if (!infoResp.ok || !info.url) throw new Error('No se pudo resolver la URL del media');
    const binResp = await fetch(info.url, {
      headers: { 'Authorization': `Bearer ${cred.accessToken}` },
    });
    if (!binResp.ok) throw new Error(`Descarga de media HTTP ${binResp.status}`);
    const buffer = Buffer.from(await binResp.arrayBuffer());
    const mime = binResp.headers.get('content-type') || info.mime_type || 'application/octet-stream';
    const empresaDir = path.join(MEDIA_DIR, String(line.empresa_id ?? '0'));
    fs.mkdirSync(empresaDir, { recursive: true });
    const saved = saveMediaBuffer(empresaDir, buffer, mime);
    return saved; // { relativePath, fileName, mime }
  }

  // Endpoints de media saliente (upload usado por conversaciones/broadcasts).
  api.post('/media/upload', ctx.requireUser, upload.single('file'), async (req, res) => {
    try {
      if (!req.file) return res.status(400).json({ error: 'Sin archivo' });
      const saved = saveMediaBuffer(path.join(MEDIA_DIR, String(req.user.empresa_id ?? 0)), req.file.buffer, req.file.mimetype);
      res.json(saved);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  Object.assign(ctx, { sendMeta, downloadMetaMedia, assertSendable });
  return { sendMeta, downloadMetaMedia, assertSendable };
}

module.exports = { register };
