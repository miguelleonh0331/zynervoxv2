'use strict';

// Store de sesiones de express-session sobre MySQL. Equivalente funcional del
// SQLiteSessionStore del original: sesiones persistentes que sobreviven
// reinicios, sin MemoryStore ni dependencias externas.

const session = require('express-session');
const config = require('./config');
const db = require('./db');

class MySQLSessionStore extends session.Store {
  constructor(storeDb) {
    super();
    this.db = storeDb;
    // Limpieza perezosa de sesiones expiradas: máximo una vez por minuto.
    this._lastSweep = 0;
  }

  expiry(sessionData) {
    const expires = sessionData?.cookie?.expires ? new Date(sessionData.cookie.expires).getTime() : 0;
    return Number.isFinite(expires) && expires > 0 ? expires : Date.now() + config.SESSION_TTL_MS;
  }

  async sweep() {
    const now = Date.now();
    if (now - this._lastSweep < 60_000) return;
    this._lastSweep = now;
    try {
      await this.db.prepare('DELETE FROM sessions WHERE expires_at<=?').run(now);
    } catch (_err) { /* no bloquea el tráfico por un fallo de limpieza */ }
  }

  async get(sid, callback) {
    try {
      await this.sweep();
      const row = await this.db.prepare('SELECT data, expires_at FROM sessions WHERE sid=?').get(sid);
      if (!row) return callback(null, null);
      if (Number(row.expires_at) <= Date.now()) {
        await this.db.prepare('DELETE FROM sessions WHERE sid=?').run(sid);
        return callback(null, null);
      }
      callback(null, JSON.parse(row.data));
    } catch (err) {
      callback(err);
    }
  }

  async set(sid, sessionData, callback) {
    try {
      const expires = this.expiry(sessionData);
      await this.db.prepare(
        `INSERT INTO sessions (sid, data, expires_at) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE data=VALUES(data), expires_at=VALUES(expires_at)`
      ).run(sid, JSON.stringify(sessionData), expires);
      callback && callback(null);
    } catch (err) {
      callback && callback(err);
    }
  }

  async destroy(sid, callback) {
    try {
      await this.db.prepare('DELETE FROM sessions WHERE sid=?').run(sid);
      callback && callback(null);
    } catch (err) {
      callback && callback(err);
    }
  }

  async touch(sid, sessionData, callback) {
    try {
      await this.db.prepare('UPDATE sessions SET expires_at=? WHERE sid=?')
        .run(this.expiry(sessionData), sid);
      callback && callback(null);
    } catch (err) {
      callback && callback(err);
    }
  }
}

module.exports = { MySQLSessionStore };
