'use strict';

// Zynerwaba v2 — entrypoint delgado.
// Solo: configura Express/Socket.IO, construye el ctx compartido, registra los
// módulos en orden de dependencias, monta frontend y arranca. Cero lógica de
// negocio aquí: eso vive en src/features/<módulo>.

const fs = require('fs');
const path = require('path');
const http = require('http');
const express = require('express');
const session = require('express-session');
const multer = require('multer');
const crypto = require('crypto');
const { Server } = require('socket.io');

const config = require('./src/shared/config');
const db = require('./src/shared/db');
const { MySQLSessionStore } = require('./src/shared/sessionStore');
const { buildContext } = require('./src/shared/moduleContext');

const app = express();
const server = http.createServer(app);
const io = new Server(server, { path: `${config.BASE}/socket.io` });

app.set('trust proxy', 1);
app.use(express.json({
  limit: '2mb',
  verify: (req, _res, buf) => { req.rawBody = Buffer.from(buf); },
}));
app.use(express.urlencoded({ extended: false }));

// ------------------------------------------------------------- sesiones
const sessionMiddleware = session({
  name: config.SESSION_COOKIE,
  secret: config.SESSION_SECRET || 'insecure-dev-secret',
  resave: false,
  saveUninitialized: false,
  rolling: true,
  cookie: { path: config.BASE, httpOnly: true, sameSite: 'lax', maxAge: config.SESSION_TTL_MS },
  store: new MySQLSessionStore(db),
});
app.use(sessionMiddleware);

// ----------------------------------------------------------------- uploads
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 20 * 1024 * 1024 },
});

function saveMediaBuffer(dir, buffer, mime) {
  fs.mkdirSync(dir, { recursive: true });
  const ext = mime.includes('png') ? '.png'
    : mime.includes('jpeg') ? '.jpg'
    : mime.includes('webp') ? '.webp'
    : mime.includes('gif') ? '.gif'
    : mime.includes('pdf') ? '.pdf'
    : mime.includes('mp4') ? '.mp4'
    : mime.includes('ogg') ? '.ogg'
    : mime.includes('mpeg') ? '.mp3'
    : '.bin';
  const name = `${Date.now()}-${crypto.randomBytes(6).toString('hex')}${ext}`;
  const full = path.join(dir, name);
  fs.writeFileSync(full, buffer);
  return { relativePath: path.relative(config.DB_PATH_MEDIA, full), fileName: name, mime };
}

// Servicio de media con autorización por empresa (patrón del original).
app.use(`${config.BASE}/media`, express.static(config.DB_PATH_MEDIA, { index: false }));

// ------------------------------------------------------------- sendAppHtml
// Misma técnica del original: inyección de BASE protegiendo el nombre de la
// variable window.__APP_BASE__ (incidente documentado en PROYECTO.md).
function sendAppHtml(res, relativePath) {
  const html = fs.readFileSync(path.join(__dirname, relativePath), 'utf8')
    .replaceAll('window.__APP_BASE__', '\u0000APPBASEVAR\u0000')
    .replaceAll('__APP_BASE__', config.BASE)
    .replaceAll('\u0000APPBASEVAR\u0000', 'window.__APP_BASE__');
  res.type('html').send(html);
}

// ------------------------------------------------------------- contexto
const api = express.Router();
api.use((req, res, next) => {
  // Carga de usuario para toda la API (los módulos deciden qué exige qué).
  if (req.session && req.session.userId) {
    db.prepare(
      `SELECT id, username, display_name, role, empresa_id FROM users
       WHERE id=? AND active=1`
    ).get(req.session.userId)
      .then((user) => { if (user) req.user = user; next(); })
      .catch(next);
  } else next();
});

const ctx = buildContext({ app, io, upload, saveMediaBuffer, sendAppHtml });
ctx.api = api;

// ------------------------------------------------- registro de módulos
// Orden crítico: auth primero (publica middlewares), empresas después
// (publica credenciales), whatsapp necesita empresas, conversaciones necesita
// whatsapp y broadcasts (optout), salud necesita whatsapp.
async function registerModules() {
  require('./src/features/auth').register(ctx);
  const empresas = require('./src/features/empresas').register(ctx);
  Object.assign(ctx, empresas); // credentialsForLine, empresaAudit

  const broadcasts = require('./src/features/broadcasts').register(ctx);
  Object.assign(ctx, broadcasts); // runBroadcast, markOptout, onDeliveryReceipt

  const whatsapp = require('./src/features/whatsapp').register(ctx);
  Object.assign(ctx, whatsapp); // sendMeta, downloadMetaMedia, assertSendable

  const conversaciones = require('./src/features/conversaciones').register(ctx);
  Object.assign(ctx, conversaciones); // classifyIncoming, emitContactRefresh...

  const salud = require('./src/features/salud').register(ctx);
  Object.assign(ctx, salud);
  if (salud.startPolling) salud.startPolling();

  require('./src/features/crm').register(ctx);
  const automatizaciones = require('./src/features/automatizaciones').register(ctx);
  Object.assign(ctx, automatizaciones); // runFlowsForMessage
}

// ------------------------------------------------------------------ vistas
// Vistas privadas (fuera de public/) servidas con BASE inyectada.
const VIEWS = {
  '/empresas': 'views/empresas/index.html',
  '/broadcast': 'views/broadcast.html',
  '/adb-pilot': 'views/adb-pilot/index.html',
  '/gestion': 'views/gestion/index.html',
  '/plantillas': 'views/plantillas/index.html',
  '/salud': 'views/salud/index.html',
};
for (const [route, file] of Object.entries(VIEWS)) {
  if (fs.existsSync(path.join(__dirname, file))) {
    app.get(`${config.BASE}${route}`, (req, res) => sendAppHtml(res, file));
  }
}

// Frontend público.
app.use(`${config.BASE}`, express.static(path.join(__dirname, 'public'), { index: false }));
app.get(`${config.BASE}/`, (_req, res) => sendAppHtml(res, 'public/index.html'));
app.get(`${config.BASE}/index.html`, (_req, res) => sendAppHtml(res, 'public/index.html'));
app.get(`${config.BASE}/chat.html`, (_req, res) => sendAppHtml(res, 'public/chat.html'));

// API.
app.use(`${config.BASE}/api`, api);

// ------------------------------------------------------------- sockets
// El front NO emite "auth": la identidad viene de la cookie de sesión en el
// handshake (patrón del original). Sin sesión válida → se rechaza la conexión.
io.use((socket, next) => {
  sessionMiddleware(socket.request, {}, () => {
    const session = socket.request.session;
    if (!session || !session.userId) return next(new Error('no session'));
    db.prepare(
      'SELECT id, username, display_name, role, empresa_id FROM users WHERE id=? AND active=1'
    ).get(session.userId).then((user) => {
      if (!user) return next(new Error('no session'));
      socket.request.session.user = user;
      next();
    }).catch(next);
  });
});

io.on('connection', (socket) => {
  const user = socket.request.session.user;
  if (user.role === 'superadmin') {
    socket.join('superadmins');
  } else if (user.empresa_id != null) {
    const eid = user.empresa_id;
    socket.join(`empresa:${eid}`);
    if (user.role === 'admin') socket.join(`empresa:${eid}:admins`);
    else socket.join(`empresa:${eid}:user:${user.id}`);
    if (user.role === 'supervisor') {
      db.prepare('SELECT line_id FROM user_lines WHERE user_id=?')
        .all(user.id).then((rows) => {
          for (const row of rows) socket.join(`empresa:${eid}:supervisors:line:${row.line_id}`);
        }).catch(() => {});
    }
  }

  // Relay de "está escribiendo": el front emite typing:start/typing:stop con
  // { contact_id }; se reenvía al resto de agentes de la misma empresa.
  socket.on('typing:start', (p) => {
    const contactId = Number(p && p.contact_id);
    if (user.empresa_id == null || !Number.isFinite(contactId)) return;
    socket.to(`empresa:${user.empresa_id}:admins`).emit('typing:start', {
      contact_id: contactId, user_id: user.id, user_name: user.display_name,
    });
  });
  socket.on('typing:stop', (p) => {
    const contactId = Number(p && p.contact_id);
    if (user.empresa_id == null || !Number.isFinite(contactId)) return;
    socket.to(`empresa:${user.empresa_id}:admins`).emit('typing:stop', {
      contact_id: contactId, user_id: user.id,
    });
  });
});

// ----------------------------------------------------------------- arranque
(async () => {
  try {
    await registerModules();

    // Bootstrap de auth (superadmin idempotente) tras registrar módulos.
    if (typeof ctx.authBootstrap === 'function') await ctx.authBootstrap();

    // Envíos 'running' huérfanos de un reinicio → paused (patrón del original).
    await db.prepare("UPDATE broadcasts SET status='paused' WHERE status='running'").run();

    app.use((req, res) => {
      if (req.path.startsWith(`${config.BASE}/api`)) {
        return res.status(404).json({ error: 'Endpoint no encontrado' });
      }
      res.status(404).send('No encontrado');
    });

    server.listen(config.PORT, config.HOST, () => {
      console.log(`zynerwabav2 escuchando en http://${config.HOST}:${config.PORT}${config.BASE}/`);
    });
  } catch (err) {
    console.error('Fallo el arranque:', err);
    process.exit(1);
  }
})();

process.on('unhandledRejection', (err) => console.error('[unhandledRejection]', err));
process.on('uncaughtException', (err) => console.error('[uncaughtException]', err));
