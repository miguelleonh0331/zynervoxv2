'use strict';

const assert = require('assert');
const conversaciones = require('../vendor/src/features/conversaciones');

async function main() {
  const timestampMs = 1791060497000;
  let insertedCreatedAt = null;
  const contact = { id: 7, line_id: 1, empresa_id: 2, status: 'open' };

  const db = {
    prepare(sql) {
      return {
        async get() {
          if (sql.includes('COUNT(*) AS n')) return { n: 1 };
          return contact;
        },
        async run(...params) {
          if (sql.includes('INSERT INTO messages')) insertedCreatedAt = params[4];
          return { changes: 1, lastInsertRowid: 11 };
        },
        async all() { return []; },
      };
    },
  };

  const noop = (_req, _res, next) => { if (next) next(); };
  const ctx = {
    api: { get() {}, post() {} },
    db,
    io: { to() { return { emit() {} }; } },
    config: {},
    requireUser: noop,
    requireAdmin: noop,
    requireSupervisor: noop,
    resolveEmpresaId() { return 2; },
    canAccess() { return true; },
    saveMediaBuffer() {},
    MEDIA_DIR: '/tmp',
    async sendMeta() {},
  };

  const api = conversaciones.register(ctx);
  await api.classifyIncoming({
    line: { id: 1, empresa_id: 2 },
    waId: '51999999999',
    profileName: 'Test User',
    message: {
      id: 'wamid.timestamp-regression',
      type: 'text',
      text: { body: 'timestamp regression' },
    },
    timestampMs,
  });

  assert.strictEqual(insertedCreatedAt, '2026-10-03 20:48:17');
  console.log('conversaciones timestamp regression: OK');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
