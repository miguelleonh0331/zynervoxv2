'use strict';

const assert = require('assert');
const crypto = require('crypto');
const whatsapp = require('../vendor/src/features/whatsapp');

async function main() {
  const appSecret = 'late-handler-regression-app-secret';
  const rawBody = Buffer.from(JSON.stringify({
    object: 'whatsapp_business_account',
    entry: [{
      changes: [{
        field: 'messages',
        value: {
          metadata: { phone_number_id: '123456789' },
          contacts: [{ profile: { name: 'Test User' } }],
          messages: [{
            id: 'wamid.regression',
            from: '51999999999',
            timestamp: '1791060497',
            type: 'text',
            text: { body: 'late handler regression' },
          }],
        },
      }],
    }],
  }));
  let postHandler = null;
  let incomingCalls = 0;
  let healthCalls = 0;

  const ctx = {
    app: {
      get() {},
      post(_route, _middleware, handler) { postHandler = handler; },
    },
    api: { post() {} },
    db: {
      prepare(sql) {
        return {
          async get() {
            if (sql.includes('FROM `lines`')) {
              return { id: 1, empresa_id: 2, phone_number_id: '123456789', active: 1 };
            }
            return undefined;
          },
        };
      },
    },
    config: { BASE: '/zynerwabav2' },
    upload: { single() { return (_req, _res, next) => next(); } },
    saveMediaBuffer() {},
    MEDIA_DIR: '/tmp',
    async credentialsForLine() { return { appSecret }; },
  };

  whatsapp.register(ctx);
  assert.strictEqual(typeof postHandler, 'function', 'webhook POST handler not registered');

  // Estos callbacks se publican después de registrar whatsapp, igual que en server.js.
  ctx.classifyIncoming = async () => { incomingCalls += 1; };
  ctx.applyWebhookHealthUpdate = async () => { healthCalls += 1; };

  const signature = 'sha256=' + crypto.createHmac('sha256', appSecret).update(rawBody).digest('hex');
  const response = { sendStatus(code) { this.statusCode = code; return this; } };
  await postHandler({
    body: JSON.parse(rawBody.toString('utf8')),
    rawBody,
    headers: { 'x-hub-signature-256': signature },
  }, response);

  assert.strictEqual(response.statusCode, 200);
  assert.strictEqual(incomingCalls, 1, 'late classifyIncoming callback was not invoked');
  assert.strictEqual(healthCalls, 1, 'late applyWebhookHealthUpdate callback was not invoked');
  console.log('webhook late handlers regression: OK');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
