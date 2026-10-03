'use strict';

const assert = require('assert');

process.env.CREDENTIALS_KEY = 'webhook-verify-token-regression-test-key';

const { encryptSecret } = require('../vendor/src/shared/crypto');
const whatsapp = require('../vendor/src/features/whatsapp');

async function main() {
  const verifyToken = 'verify-token-regression-value';
  const encrypted = encryptSecret(verifyToken);
  let getHandler = null;

  const ctx = {
    app: {
      get(_route, handler) { getHandler = handler; },
      post() {},
    },
    api: { post() {} },
    db: {
      prepare() {
        return {
          async all() {
            return [{
              verify_token_enc: encrypted.enc,
              verify_token_iv: encrypted.iv,
              verify_token_tag: encrypted.tag,
            }];
          },
        };
      },
    },
    config: { BASE: '/zynerwabav2' },
    upload: { single() { return (_req, _res, next) => next(); } },
    saveMediaBuffer() {},
    MEDIA_DIR: '/tmp',
  };

  whatsapp.register(ctx);
  assert.strictEqual(typeof getHandler, 'function', 'webhook GET handler not registered');

  const response = {
    statusCode: null,
    body: null,
    status(code) { this.statusCode = code; return this; },
    send(body) { this.body = body; return this; },
    sendStatus(code) { this.statusCode = code; this.body = String(code); return this; },
  };

  await getHandler({
    query: {
      'hub.mode': 'subscribe',
      'hub.verify_token': verifyToken,
      'hub.challenge': '842731',
    },
  }, response);

  assert.strictEqual(response.statusCode, 200);
  assert.strictEqual(response.body, '842731');
  console.log('webhook verify token regression: OK');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
