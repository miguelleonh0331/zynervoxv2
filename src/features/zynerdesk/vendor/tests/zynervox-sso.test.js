"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const crypto = require("crypto");
const { verifyZynervoxSso } = require("../zynervox-sso");

test("acepta un token SSO valido y rechaza una firma alterada", () => {
  const secret = "0123456789abcdef0123456789abcdef";
  const claims = { user: "admin", name: "Admin", level: 9, exp: 1060 };
  const payload = Buffer.from(JSON.stringify(claims)).toString("base64url");
  const signature = crypto.createHmac("sha256", secret).update(payload).digest("hex");

  assert.deepEqual(verifyZynervoxSso({ payload, signature }, secret, 1000), claims);
  assert.equal(verifyZynervoxSso({ payload, signature: "0".repeat(64) }, secret, 1000), null);
});
