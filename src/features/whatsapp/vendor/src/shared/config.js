'use strict';

// Configuración central de zynerwabav2. Fuente única de verdad para variables
// de entorno. Nada del resto del código lee process.env directamente.

const path = require('path');

const BASE = process.env.BASE_PATH || '/zynerwabav2';
const PORT = Number(process.env.PORT || 3021);
const HOST = process.env.HOST || '127.0.0.1';

module.exports = {
  PORT,
  HOST,
  BASE,
  DB_PATH_MEDIA: path.join(__dirname, '..', '..', 'data', 'media'),

  // MySQL
  DB: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'zynerwabav2',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'zynerwabav2',
    connectionLimit: Number(process.env.DB_POOL || 10),
    // DATETIME de MySQL se devuelve como string 'YYYY-MM-DD HH:MM:SS',
    // igual que SQLite: simplifica la portabilidad del código original.
    dateStrings: true,
    charset: 'utf8mb4_unicode_ci',
  },

  // Sesiones
  SESSION_SECRET: process.env.SESSION_SECRET || '',
  SESSION_COOKIE: `zynerwabav2.sid`,
  SESSION_TTL_MS: 1000 * 60 * 60 * 24 * 7, // 7 días

  // Cifrado de credenciales por empresa (AES-256-GCM)
  CREDENTIALS_KEY: process.env.CREDENTIALS_KEY || '',

  // Bootstrap del superadmin inicial (solo si la BD no tiene ninguno)
  INITIAL_ADMIN_USER: process.env.INITIAL_ADMIN_USER || '',
  INITIAL_ADMIN_PASSWORD: process.env.INITIAL_ADMIN_PASSWORD || '',

  // Ventana de servicio de WhatsApp (control de costo, heredado del original)
  SERVICE_WINDOW_HOURS: Math.min(24, Math.max(1, Number(process.env.SERVICE_WINDOW_HOURS) || 20)),
  WINDOW_OVERRIDE_MINUTES: Math.min(60, Math.max(1, Number(process.env.WINDOW_OVERRIDE_MINUTES) || 5)),
};
