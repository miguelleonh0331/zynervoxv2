"use strict";

const GEO_TTL_MS = 24 * 60 * 60 * 1000;
const GEO_ERROR_TTL_MS = 5 * 60 * 1000;

function createGeoService() {
  const cache = new Map();

  async function lookup(ip) {
    const now = Date.now();
    const cached = cache.get(ip);
    if (cached && now - cached.ts < (cached.error ? GEO_ERROR_TTL_MS : GEO_TTL_MS)) {
      if (cached.error) {
        const error = new Error(cached.error);
        error.statusCode = 502;
        throw error;
      }
      return { data: cached.data, cached: true };
    }

    try {
      const response = await fetch(`https://api.ipapi.is/?q=${encodeURIComponent(ip)}`);
      const data = await response.json();
      cache.set(ip, { data, ts: now });
      return { data, cached: false };
    } catch (cause) {
      const message = `geo fallo: ${cause.message}`;
      cache.set(ip, { error: message, ts: now });
      const error = new Error(message);
      error.statusCode = 502;
      throw error;
    }
  }

  return { lookup };
}

module.exports = { createGeoService };
