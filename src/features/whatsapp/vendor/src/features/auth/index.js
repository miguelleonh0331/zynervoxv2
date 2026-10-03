'use strict';

// Módulo auth — sesiones, roles y bootstrap idempotente del superadmin.
// Publica en ctx: requireUser, requireAdmin, requireSupervisor,
// requireSuperadmin, canAccess, resolveEmpresaId.

const bcrypt = require('bcryptjs');

function register(ctx) {
  const { db, api, config } = ctx;

  // ---------------------------------------------------------------- bootstrap
  async function bootstrap() {
    // Invariante de seguridad: superadmin nunca pertenece a una empresa.
    const corruptos = await db.prepare(
      "SELECT COUNT(*) AS n FROM users WHERE role='superadmin' AND empresa_id IS NOT NULL"
    ).get();
    if (Number(corruptos.n) > 0) {
      console.error(`[seguridad] ${corruptos.n} superadmin(es) con empresa_id no nulo: estado corrupto`);
    }

    // Bootstrap del superadmin inicial: solo actúa si NO existe ningún
    // superadmin (idempotente). El marcador en app_meta es informativo.
    const existente = await db.prepare(
      "SELECT id FROM users WHERE role='superadmin' ORDER BY id LIMIT 1"
    ).get();
    if (existente) {
      await db.prepare("INSERT INTO app_meta (clave,valor) VALUES ('bootstrap_superadmin',?) " +
        'ON DUPLICATE KEY UPDATE valor=VALUES(valor)').run(`existente:${existente.id}`);
    } else if (config.INITIAL_ADMIN_USER && config.INITIAL_ADMIN_PASSWORD) {
      const hash = bcrypt.hashSync(String(config.INITIAL_ADMIN_PASSWORD), 10);
      const res = await db.prepare(
        "INSERT INTO users (username,password_hash,display_name,role,empresa_id) VALUES (?,?,?,'superadmin',NULL)"
      ).run(config.INITIAL_ADMIN_USER, hash, 'Superadministrador');
      await db.prepare("INSERT INTO app_meta (clave,valor) VALUES ('bootstrap_superadmin',?) " +
        'ON DUPLICATE KEY UPDATE valor=VALUES(valor)').run(`creado:${res.lastInsertRowid}`);
      console.log(`[auth] superadmin inicial creado: ${config.INITIAL_ADMIN_USER}`);
    } else {
      console.warn('[auth] sin superadmin y sin INITIAL_ADMIN_* en el entorno: no se creó usuario inicial');
    }
  }

  // -------------------------------------------------------------- middleware
  function loadUser(req, _res, next) {
    if (!req.session || !req.session.userId) return next();
    db.prepare(
      `SELECT u.id, u.username, u.display_name, u.role, u.empresa_id, u.active
       FROM users u WHERE u.id=? AND u.active=1`
    ).get(req.session.userId).then((user) => {
      if (user) req.user = user;
      next();
    }).catch(next);
  }

  function isAuthenticated(req, res, next) {
    if (req.user) return next();
    if (req.path.startsWith('/public/') || req.path === '/login') return next();
    // El frontend decide mostrar login; la API responde 401 JSON.
    res.status(401).json({ error: 'No autenticado' });
  }

  function requireUser(req, res, next) {
    if (req.user) return next();
    res.status(401).json({ error: 'No autenticado' });
  }

  function requireAdmin(req, res, next) {
    if (!req.user) return res.status(401).json({ error: 'No autenticado' });
    if (!['admin', 'superadmin'].includes(req.user.role)) {
      return res.status(403).json({ error: 'Requiere rol administrador' });
    }
    next();
  }

  function requireSupervisor(req, res, next) {
    if (!req.user) return res.status(401).json({ error: 'No autenticado' });
    if (!['admin', 'superadmin', 'supervisor'].includes(req.user.role)) {
      return res.status(403).json({ error: 'Requiere rol supervisor o superior' });
    }
    next();
  }

  function requireSuperadmin(req, res, next) {
    if (!req.user) return res.status(401).json({ error: 'No autenticado' });
    if (req.user.role !== 'superadmin') {
      return res.status(403).json({ error: 'Requiere superadministrador' });
    }
    next();
  }

  // Empresa efectiva de la petición. Superadmin debe declararla (X-Empresa-Id);
  // el resto de roles usa la suya propia.
  function resolveEmpresaId(req) {
    if (!req.user) return null;
    if (req.user.role === 'superadmin') {
      const header = req.headers['x-empresa-id'];
      if (header && /^\d+$/.test(String(header))) return Number(header);
      return null;
    }
    return req.user.empresa_id != null ? Number(req.user.empresa_id) : null;
  }

  // Visibilidad de contactos por rol (agent → propios; supervisor → sus líneas;
  // admin/superadmin → todos los de la empresa efectiva).
  function canAccess(user, contact, empresaId) {
    if (!user) return false;
    if (user.role === 'admin' || user.role === 'superadmin') {
      return !empresaId || Number(contact.empresa_id) === Number(empresaId);
    }
    if (user.role === 'supervisor') {
      return Number(contact.empresa_id) === Number(user.empresa_id);
    }
    return Number(contact.owner_user_id) === Number(user.id) &&
      Number(contact.empresa_id) === Number(user.empresa_id);
  }

  // ------------------------------------------------------------------- API
  api.post('/login', async (req, res) => {
    try {
      const { username, password } = req.body || {};
      if (!username || !password) return res.status(400).json({ error: 'Faltan credenciales' });
      const user = await db.prepare(
        'SELECT * FROM users WHERE username=? AND active=1'
      ).get(String(username).trim());
      if (!user || !bcrypt.compareSync(String(password), user.password_hash)) {
        return res.status(401).json({ error: 'Credenciales incorrectas' });
      }
      // Empresa suspendida no inicia sesión (patrón del original).
      const empresa = user.empresa_id ? await db.prepare(
        'SELECT estado FROM empresas WHERE id=?'
      ).get(user.empresa_id) : null;
      if (empresa && empresa.estado !== 'activa') {
        return res.status(403).json({ error: 'Empresa suspendida' });
      }
      req.session.userId = user.id;
      const lines = user.role === 'superadmin' ? [] : await db.prepare(
        `SELECT l.id, l.name, l.color, l.phone_number_id
         FROM user_lines ul JOIN \`lines\` l ON l.id=ul.line_id
         WHERE ul.user_id=? AND l.active=1 ORDER BY l.id`
      ).all(user.id);
      // Respuesta anidada { user } — la espera el frontend (app.js hace .user).
      const empresaRow = user.empresa_id ? await db.prepare(
        'SELECT nombre FROM empresas WHERE id=?'
      ).get(user.empresa_id) : null;
      res.json({
        user: {
          id: user.id,
          username: user.username,
          display_name: user.display_name,
          role: user.role,
          empresa_id: user.empresa_id,
          empresa_name: empresaRow ? empresaRow.nombre : null,
          lines,
        },
      });
    } catch (err) {
      console.error('[auth] login:', err.message);
      res.status(500).json({ error: 'Error de servidor' });
    }
  });

  api.post('/logout', (req, res) => {
    req.session.destroy(() => res.json({ ok: true }));
  });

  // /me NUNCA responde 401: el frontend interpreta 401 como "sesión expirada"
  // y hace location.reload() → bucle de recarga. Sin sesión responde 200 con
  // { user: null } (patrón exacto del original), y el front muestra el login.
  api.get('/me', (req, res) => {
    if (!req.user) {
      return res.json({ user: null, service_window_hours: config.SERVICE_WINDOW_HOURS });
    }
    const u = req.user;
    Promise.all([
      db.prepare(
        `SELECT l.id, l.name, l.color, l.phone_number_id
         FROM user_lines ul JOIN \`lines\` l ON l.id=ul.line_id
         WHERE ul.user_id=? AND l.active=1 ORDER BY l.id`
      ).all(u.id),
      u.empresa_id ? db.prepare('SELECT nombre FROM empresas WHERE id=?').get(u.empresa_id) : Promise.resolve(null),
    ]).then(([lines, empresaRow]) => {
      res.json({
        user: {
          id: u.id, username: u.username, display_name: u.display_name,
          role: u.role, empresa_id: u.empresa_id,
          empresa_name: empresaRow ? empresaRow.nombre : null,
          lines,
        },
        service_window_hours: config.SERVICE_WINDOW_HOURS,
      });
    }).catch(() => res.status(500).json({ error: 'Error de servidor' }));
  });

  // Publicación en ctx (orden de registro: auth primero).
  Object.assign(ctx, {
    loadUser, isAuthenticated,
    requireUser, requireAdmin, requireSupervisor, requireSuperadmin,
    resolveEmpresaId, canAccess, authBootstrap: bootstrap,
  });

  return { bootstrap };
}

module.exports = { register };
