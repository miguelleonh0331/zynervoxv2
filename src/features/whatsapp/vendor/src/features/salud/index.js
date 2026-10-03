'use strict';

// Módulo salud — observabilidad de números: semáforo basado solo en Meta,
// sondeo periódico escalonado, alertas abiertas y actualización por webhook.

function register(ctx) {
  const {
    api, db, io, requireAdmin, requireSupervisor, requireSuperadmin,
    resolveEmpresaId, credentialsForLine,
  } = ctx;

  // Normaliza la respuesta de Graph (campos opcionales tolerados).
  function normalizeMeta(data) {
    const quality = data?.quality_rating?.rating || data?.quality_rating || null;
    const status = data?.account_mode || data?.platform_type || null;
    return {
      quality_rating: quality ? String(quality).toUpperCase() : null,
      meta_status: data?.account_update_status || status || null,
      name_status: data?.name_status || data?.verified_name ? (data.name_status || 'APPROVED') : null,
      messaging_limit_tier: data?.messaging_limit_tier || null,
      code_verification_status: data?.code_verification_status || null,
      throughput: data?.throughput?.level || null,
      platform_type: data?.platform_type || null,
    };
  }

  function semaforoFrom(m) {
    if (!m || (!m.quality_rating && !m.meta_status)) return 'sin_datos';
    if (String(m.meta_status || '').toUpperCase() === 'RESTRICTED' ||
        String(m.meta_status || '').toUpperCase() === 'BANNED') return 'rojo';
    if (m.quality_rating === 'RED') return 'rojo';
    if (m.quality_rating === 'YELLOW') return 'ambar';
    if (m.quality_rating === 'GREEN') return 'verde';
    return 'sin_datos';
  }

  async function sondear(line, empresaId) {
    const cred = await credentialsForLine(line);
    const version = cred.graphVersion || 'v25.0';
    let metricas = null;
    let error = null;
    try {
      const resp = await fetch(
        `https://graph.facebook.com/${version}/${line.phone_number_id}` +
        '?fields=quality_rating,account_mode,messaging_limit_tier,name_status,code_verification_status,throughput,platform_type',
        { headers: { Authorization: `Bearer ${cred.accessToken}` } }
      );
      const data = await resp.json();
      if (!resp.ok) throw new Error(data?.error?.message || `HTTP ${resp.status}`);
      metricas = normalizeMeta(data);
    } catch (err) {
      error = err.message;
    }
    const semaforo = error ? 'sin_datos' : semaforoFrom(metricas);
    await db.prepare(
      `INSERT INTO numero_salud
       (empresa_id, line_id, phone_number_id, quality_rating, meta_status, name_status,
        messaging_limit_tier, code_verification_status, throughput, platform_type,
        token_ok, error, semaforo, motivo)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`
    ).run(
      empresaId, line.id, String(line.phone_number_id),
      metricas?.quality_rating || null, metricas?.meta_status || null, metricas?.name_status || null,
      metricas?.messaging_limit_tier || null, metricas?.code_verification_status || null,
      metricas?.throughput || null, metricas?.platform_type || null,
      error ? 0 : 1, error, semaforo, error || null
    );
    if (semaforo === 'rojo') {
      await abrirAlerta(empresaId, line.phone_number_id, 'numero_rojo', 'critico',
        `El número ${line.phone_number_id} está en rojo: ${error || metricas?.meta_status || 'calidad RED'}`);
    }
    io.to(`empresa:${empresaId}`).emit('salud:update', { phone_number_id: line.phone_number_id, semaforo });
    return semaforo;
  }

  async function abrirAlerta(empresaId, phoneNumberId, tipo, severidad, mensaje) {
    const abierta = await db.prepare(
      `SELECT id FROM salud_alertas
       WHERE empresa_id=? AND phone_number_id=? AND tipo=? AND resuelta_at IS NULL`
    ).get(empresaId, String(phoneNumberId), tipo);
    if (abierta) return;
    await db.prepare(
      `INSERT INTO salud_alertas (empresa_id, phone_number_id, tipo, severidad, mensaje)
       VALUES (?,?,?,?,?)`
    ).run(empresaId, String(phoneNumberId), tipo, severidad, mensaje);
  }

  // Webhook en tiempo real → actualiza sin esperar sondeo.
  async function applyWebhookHealthUpdate(value) {
    const phoneNumberId = value?.metadata?.phone_number_id;
    const quality = value?.quality_update?.quality || null;
    const accountUpdate = value?.account_update?.event || null;
    if (!phoneNumberId || (!quality && !accountUpdate)) return;
    const line = await db.prepare('SELECT * FROM \`lines\` WHERE phone_number_id=?').get(String(phoneNumberId));
    if (!line || line.empresa_id == null) return;
    const metricas = normalizeMeta({
      quality_rating: quality,
      account_update_status: accountUpdate,
    });
    const semaforo = semaforoFrom(metricas);
    await db.prepare(
      `INSERT INTO numero_salud (empresa_id, line_id, phone_number_id, quality_rating,
        meta_status, semaforo, motivo)
       VALUES (?,?,?,?,?,?,?)`
    ).run(line.empresa_id, line.id, String(phoneNumberId), metricas.quality_rating,
      metricas.meta_status, semaforo, 'webhook en tiempo real');
    io.to(`empresa:${line.empresa_id}`).emit('salud:update', { phone_number_id: String(phoneNumberId), semaforo });
  }

  // Sondeo periódico cada 30 minutos (respaldo del webhook).
  let timer = null;
  function startPolling() {
    if (timer) return;
    timer = setInterval(async () => {
      try {
        const lines = await db.prepare(
          'SELECT * FROM \`lines\` WHERE active=1 AND empresa_id IS NOT NULL'
        ).all();
        let lastEmpresa = null;
        for (const line of lines) {
          // Escalonado por empresa: pausa breve cuando cambia la empresa.
          if (lastEmpresa !== null && lastEmpresa !== line.empresa_id) {
            await new Promise((r) => setTimeout(r, 2000));
          }
          lastEmpresa = line.empresa_id;
          await sondear(line, line.empresa_id).catch(() => {});
        }
      } catch (_err) { /* reintento en el próximo ciclo */ }
    }, 30 * 60 * 1000);
  }

  // ------------------------------------------------------------------- API
  api.get('/salud', requireSupervisor, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      if (!empresaId) return res.status(400).json({ error: 'Falta contexto de empresa' });
      const rows = await db.prepare(
        `SELECT s.* FROM numero_salud s
         JOIN (SELECT phone_number_id, MAX(id) AS max_id FROM numero_salud GROUP BY phone_number_id) ult
           ON ult.phone_number_id=s.phone_number_id AND ult.max_id=s.id
         WHERE s.empresa_id=?`
      ).all(empresaId);
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.get('/salud/global', requireSuperadmin, async (_req, res) => {
    try {
      const rows = await db.prepare(
        `SELECT s.*, e.nombre AS empresa_nombre FROM numero_salud s
         JOIN empresas e ON e.id=s.empresa_id
         JOIN (SELECT phone_number_id, MAX(id) AS max_id FROM numero_salud GROUP BY phone_number_id) ult
           ON ult.phone_number_id=s.phone_number_id AND ult.max_id=s.id
         ORDER BY FIELD(s.semaforo,'rojo','ambar','sin_datos','verde')`
      ).all();
      res.json(rows);
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  api.post('/salud/:phoneNumberId/sondear', requireAdmin, async (req, res) => {
    try {
      const empresaId = resolveEmpresaId(req);
      const line = await db.prepare(
        'SELECT * FROM \`lines\` WHERE phone_number_id=? AND empresa_id=?'
      ).get(String(req.params.phoneNumberId), empresaId);
      if (!line) return res.status(404).json({ error: 'Línea no encontrada' });
      const semaforo = await sondear(line, empresaId);
      res.json({ ok: true, semaforo });
    } catch (err) { res.status(500).json({ error: err.message }); }
  });

  Object.assign(ctx, { lineSemaphoro: null, applyWebhookHealthUpdate });
  return { applyWebhookHealthUpdate, startPolling, sondear };
}

module.exports = { register };
