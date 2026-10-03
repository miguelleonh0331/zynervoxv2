'use strict';

const assert = require('assert');
const conversaciones = require('../vendor/src/features/conversaciones');

async function main() {
  const emitted = [];
  const contact = {
    id: 7,
    line_id: 1,
    empresa_id: 2,
    owner_user_id: 5,
    status: 'open',
  };

  const db = {
    prepare(sql) {
      return {
        async get() {
          if (sql.includes('COUNT(*) AS n')) return { n: 1 };
          return contact;
        },
        async run() { return { changes: 1, lastInsertRowid: 11 }; },
        async all() { return []; },
      };
    },
  };

  const noop = (_req, _res, next) => { if (next) next(); };
  const ctx = {
    api: { get() {}, post() {} },
    db,
    io: {
      to(room) {
        return { emit(event, payload) { emitted.push({ room, event, payload }); } };
      },
    },
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
      id: 'wamid.realtime-regression',
      type: 'text',
      text: { body: 'realtime regression' },
    },
    timestampMs: 1791060497000,
  });

  for (const room of ['empresa:2:admins', 'empresa:2:user:5']) {
    const roomEvents = emitted.filter((item) => item.room === room);
    assert(roomEvents.some((item) => item.event === 'contact:refresh'));
    assert(roomEvents.some((item) => item.event === 'contacts:refresh'));
    const incoming = roomEvents.find((item) => item.event === 'message:new');
    assert(incoming, `message:new missing in ${room}`);
    assert.strictEqual(incoming.payload.contact_id, 7);
    assert.strictEqual(incoming.payload.message.id, 11);
    assert.strictEqual(incoming.payload.message.direction, 'in');
    assert.strictEqual(incoming.payload.message.body, 'realtime regression');
    assert.strictEqual(incoming.payload.message.is_auto_response, 0);
    assert.strictEqual(incoming.payload.message.is_optout_response, 0);
  }

  console.log('conversaciones realtime regression: OK');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
