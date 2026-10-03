'use strict';

// Contexto compartido que se inyecta a cada módulo en register(ctx).
// Equivalente al patrón del original:
//   require('./src/enhancements')({ api, db, io, ... })
// pero unificado: todos los módulos reciben el mismo ctx y solo consumen
// vía contrato lo que otros módulos publican en ctx.

const path = require('path');
const config = require('./config');
const db = require('./db');
const { encryptSecret, decryptSecret } = require('./crypto');

function buildContext({ app, api, io, upload, saveMediaBuffer, sendAppHtml }) {
  const ctx = {
    // Infraestructura
    app,            // express app (rutas no-API, vistas privadas)
    api,            // router montado en BASE con auth global
    io,             // servidor Socket.IO
    db,
    config,
    BASE: config.BASE,
    MEDIA_DIR: config.DB_PATH_MEDIA,
    upload,
    saveMediaBuffer,
    sendAppHtml,
    crypto: { encryptSecret, decryptSecret },

    // Publicados por módulos (se rellenan en server.js en orden de registro):
    // requireUser, requireAdmin, requireSupervisor, requireSuperadmin,
    // canAccess, resolveEmpresaId, emitContactRefresh, classifyIncoming,
    // touchContactFromWebhook, credentialsForLine, empresaAudit, sendMeta,
    // downloadMetaMedia, assertSendable, runBroadcast, markOptout,
    // onDeliveryReceipt, lineSemaphoro, applyWebhookHealthUpdate,
    // runFlowsForMessage
  };
  return ctx;
}

// Router "api" base: todos los endpoints /api/* cuelgan de aquí con sesión.
// Los middlewares específicos por rol se aplican dentro de cada módulo.
function createApiRouter(express, sessionMiddleware, isAuthenticated) {
  const api = express.Router();
  api.use(sessionMiddleware);
  api.use(isAuthenticated);
  return api;
}

module.exports = { buildContext, createApiRouter, path };
