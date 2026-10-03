const crypto = require("crypto");

function verifyZynervoxSso(body, secret, nowSeconds = Math.floor(Date.now() / 1000)) {
  const payload = String(body && body.payload || "");
  const signature = String(body && body.signature || "").toLowerCase();
  if (secret.length < 32 || !/^[A-Za-z0-9_-]+$/.test(payload) || !/^[a-f0-9]{64}$/.test(signature)) return null;
  const expected = crypto.createHmac("sha256", secret).update(payload).digest("hex");
  if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))) return null;
  let claims;
  try { claims = JSON.parse(Buffer.from(payload, "base64url").toString("utf8")); } catch { return null; }
  const user = String(claims.user || "").trim();
  const level = Number(claims.level);
  const exp = Number(claims.exp);
  if (!/^[A-Za-z0-9._-]{1,40}$/.test(user) || level < 9 || !Number.isInteger(exp)) return null;
  if (exp < nowSeconds || exp > nowSeconds + 90) return null;
  return { user, name: String(claims.name || user).slice(0, 120), level, exp };
}

module.exports = { verifyZynervoxSso };
