'use strict';

// Cifrado AES-256-GCM para secretos por empresa (credenciales Meta, API keys).
// Portado del original src/crypto.js: mismo formato enc/iv/tag/hint.

const crypto = require('crypto');
const config = require('./config');

function keyMaterial() {
  if (!config.CREDENTIALS_KEY) {
    throw new Error('CREDENTIALS_KEY no configurada: no se pueden cifrar secretos');
  }
  // Deriva una clave de 32 bytes estable a partir del secreto configurado.
  return crypto.createHash('sha256').update(String(config.CREDENTIALS_KEY)).digest();
}

function encryptSecret(plain) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', keyMaterial(), iv);
  const enc = Buffer.concat([cipher.update(String(plain), 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return {
    enc: enc.toString('base64'),
    iv: iv.toString('base64'),
    tag: tag.toString('base64'),
    hint: String(plain).length > 6 ? `••••${String(plain).slice(-4)}` : '••••',
  };
}

function decryptSecret({ enc, iv, tag }) {
  if (!enc || !iv || !tag) return null;
  try {
    const decipher = crypto.createDecipheriv('aes-256-gcm', keyMaterial(), Buffer.from(iv, 'base64'));
    decipher.setAuthTag(Buffer.from(tag, 'base64'));
    return Buffer.concat([decipher.update(Buffer.from(enc, 'base64')), decipher.final()]).toString('utf8');
  } catch (_err) {
    return null; // Tag inválido o clave cambiada: nunca lanzar con datos corruptos.
  }
}

module.exports = { encryptSecret, decryptSecret };
