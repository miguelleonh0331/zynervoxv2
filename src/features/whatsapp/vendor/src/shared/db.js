'use strict';

// Adaptador de acceso a datos (MySQL) con superficie compatible con el estilo
// better-sqlite3 del original, para minimizar el diff al convertir módulos.
//
// SQLite (original):            const row = db.prepare('...').get(a, b);
// Aquí (async, misma forma):    const row = await db.prepare('...').get(a, b);
//
// - get()  → primera fila o undefined
// - all()  → array de filas
// - run()  → { changes, lastInsertRowid } (compatibilidad con el original)
// - exec() → sentencias múltiples (solo para DDL de arranque)
// - transaction(fn) → ejecuta fn con un cliente dedicado; rollback en error.

const mysql = require('mysql2/promise');
const config = require('./config');

const pool = mysql.createPool(config.DB);

// Traduce placeholders `?` (idénticos) y preserva la firma de better-sqlite3.
function wrapStatement(sql) {
  return {
    async get(...params) {
      const [rows] = await pool.execute(sql, params);
      return rows[0];
    },
    async all(...params) {
      const [rows] = await pool.execute(sql, params);
      return rows;
    },
    async run(...params) {
      const [result] = await pool.execute(sql, params);
      return {
        changes: result.affectedRows,
        lastInsertRowid: result.insertId,
      };
    },
  };
}

const db = {
  prepare: (sql) => wrapStatement(sql),

  async exec(sql) {
    await pool.query(sql);
  },

  async transaction(fn) {
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();
      const txDb = {
        prepare: (sql) => ({
          async get(...params) {
            const [rows] = await conn.execute(sql, params);
            return rows[0];
          },
          async all(...params) {
            const [rows] = await conn.execute(sql, params);
            return rows;
          },
          async run(...params) {
            const [result] = await conn.execute(sql, params);
            return { changes: result.affectedRows, lastInsertRowid: result.insertId };
          },
        }),
        exec: (sql) => conn.query(sql),
      };
      const out = await fn(txDb);
      await conn.commit();
      return out;
    } catch (err) {
      try { await conn.rollback(); } catch (_) { /* ya falló la transacción */ }
      throw err;
    } finally {
      conn.release();
    }
  },

  // Utilidad directa para consultas sueltas (mismo convenio de retorno que run).
  async query(sql, params = []) {
    const [result] = await pool.query(sql, params);
    return result;
  },

  pool,
};

module.exports = db;
