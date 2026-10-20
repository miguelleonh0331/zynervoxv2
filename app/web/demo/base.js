'use strict';
/* Ayudantes tomados de modules/agc/crm.js para que la réplica se vea
   idéntica a la consola. Es una COPIA deliberada: la presentación no debe
   depender de archivos de producción. */

const $ = id => document.getElementById(id);

function el(tag, attrs, ...hijos) {
  const n = document.createElement(tag);
  for (const k in (attrs || {})) {
    const v = attrs[k];
    if (v === null || v === undefined || v === false) continue;
    if (k === 'cls') n.className = v;
    else if (k === 'txt') n.textContent = v;
    else if (k === 'html') n.innerHTML = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v === true ? '' : v);
  }
  for (const h of hijos.flat()) if (h !== null && h !== undefined && h !== false) n.append(h);
  return n;
}

const ICO = {
  tel:'<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.1 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7A2 2 0 0 1 22 16.9z"/>',
  wa:'<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/>',
  mail:'<rect x="2" y="4" width="20" height="16" rx="1"/><polyline points="2,6 12,13 22,6"/>',
  sms:'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  nota:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
  gestion:'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
  prod:'<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
  pin:'<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
  doc:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/>',
  mas:'<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
  buscar:'<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
  reloj:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  escudo:'<path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6z"/>',
  rayo:'<path d="M13 2L4 14h6l-1 8 9-12h-6z"/>',
  /* Versiones macizas para los botones de canal: a 15px el trazo fino se
     deshace sobre un fondo de color. Llevan el sufijo -f y se piden con la
     clase 'ico ico--fill', que cambia relleno por trazo. */
  'tel-f':'<path d="M6.6 2.5a1.8 1.8 0 0 1 2.4.6l1.8 2.7a1.8 1.8 0 0 1-.3 2.3L9.2 9.3a12.3 12.3 0 0 0 5.5 5.5l1.2-1.3a1.8 1.8 0 0 1 2.3-.3l2.7 1.8a1.8 1.8 0 0 1 .6 2.4l-1 1.7a2.6 2.6 0 0 1-2.9 1.2C12.2 19.1 4.9 11.8 3.7 5.4A2.6 2.6 0 0 1 4.9 2.5z"/>',
  /* WhatsApp: el globo con la muesca y el auricular calado, no un bocadillo
     genérico. El agujero sale con fill-rule, sin segundo trazo. */
  'wa-f':'<path fill-rule="evenodd" clip-rule="evenodd" d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.8 5-1.3A10 10 0 1 0 12 2zm5.1 13.9c-.2.6-1.2 1.2-1.7 1.2-.5.1-1 .1-1.6-.1a14 14 0 0 1-2.8-1.3 11 11 0 0 1-3.4-4.2c-.4-.8-.5-1.6-.3-2.3.1-.5.6-1.2 1-1.4.2-.1.5-.1.7-.1h.5c.2 0 .4 0 .6.5l.8 1.9c0 .2 0 .3-.1.5l-.4.5c-.1.2-.3.3-.1.6a9 9 0 0 0 1.4 1.8c.7.6 1.3.9 1.6 1 .2.1.4.1.5-.1l.7-.8c.2-.2.3-.2.5-.1l1.8.9c.2.1.4.2.4.3s0 .7-.2 1.3z"/>',
  'mail-f':'<path d="M3 5h18a1 1 0 0 1 1 1v.4l-10 5.8-10-5.8V6a1 1 0 0 1 1-1z"/><path d="M2 8.5V18a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V8.5l-9.5 5.5a1 1 0 0 1-1 0z"/>',
  'sms-f':'<path d="M4 3h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 5V5a2 2 0 0 1 2-2z"/><circle cx="8.5" cy="9.5" r="1.1" fill="#fff"/><circle cx="12" cy="9.5" r="1.1" fill="#fff"/><circle cx="15.5" cy="9.5" r="1.1" fill="#fff"/>'
};

const NS_SVG = 'http://www.w3.org/2000/svg';

function svg(name, cls) {
  const s = document.createElementNS(NS_SVG, 'svg');
  s.setAttribute('class', cls || 'ico');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  s.innerHTML = ICO[name] || '';
  return s;
}

const iniciales = s => String(s || '?').trim().split(/\s+/).slice(0, 2).map(x => x[0]).join('').toUpperCase();

const PAL      = ['#1E3A8A', '#1D4ED8', '#15803D', '#B45309', '#6D28D9', '#BE123C', '#0F766E', '#9D174D'];

const AV_PIEL  = ['#F2D3B8', '#E7BE9B', '#D29C76', '#B57C52', '#8D5A3B', '#6B4328'];

const AV_PELO  = ['#1B1714', '#3A2B22', '#6B4A32', '#A6692F', '#C9A227', '#8A8F98', '#4A2C6B'];

const AV_ROPA  = ['#2563EB', '#0F766E', '#7C3AED', '#BE123C', '#B45309', '#334155', '#15803D'];

const AV_FONDO = ['#DBEAFE', '#DCFCE7', '#EDE9FE', '#FFE4E6', '#FEF3C7', '#E2E8F0', '#CCFBF1'];

const hash = s => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) >>> 0; return h; };

const elige = (lista, h, desp) => lista[(h >>> desp) % lista.length];

function caraSVG(nombre) {
  const h = hash(nombre);
  const piel = elige(AV_PIEL, h, 0), pelo = elige(AV_PELO, h, 3),
        ropa = elige(AV_ROPA, h, 6), fondo = elige(AV_FONDO, h, 9);
  const peinado = (h >>> 12) % 4;     // al ras · con flequillo · recogido · largo
  const vello   = (h >>> 14) % 4 === 0 ? `<path d="M12 23c2 2.6 10 2.6 12 0v2c0 3-12 3-12-2z" fill="${pelo}" opacity=".85"/>` : '';
  const ojoY    = 19 + ((h >>> 16) % 2);
  const sonrisa = (h >>> 18) % 3;

  const cabello = [
    `<path d="M8 17c0-7 4-10 10-10s10 3 10 10c0-4-4-5-10-5S8 13 8 17z" fill="${pelo}"/>`,
    `<path d="M7 18c0-8 5-11 11-11s11 3 11 11c-1-5-4-7-7-6-2 1-4 2-7 1-3-1-6 0-8 5z" fill="${pelo}"/>`,
    `<path d="M8 17c0-7 4-10 10-10s10 3 10 10c0-4-4-5-10-5S8 13 8 17z" fill="${pelo}"/>` +
      `<circle cx="27" cy="11" r="4" fill="${pelo}"/>`,
    `<path d="M7 19c0-8 5-12 11-12s11 4 11 12v9c-2 1-3-3-3-7 0-4-3-5-8-5s-8 1-8 5c0 4-1 8-3 7z" fill="${pelo}"/>`
  ][peinado];

  const boca = [
    `<path d="M15 25c1.6 1.6 4.4 1.6 6 0" stroke="#8B5E4B" stroke-width="1.4" fill="none" stroke-linecap="round"/>`,
    `<path d="M15 25c1.6 2.4 4.4 2.4 6 0" stroke="#8B5E4B" stroke-width="1.4" fill="none" stroke-linecap="round"/>`,
    `<path d="M15.5 25h5" stroke="#8B5E4B" stroke-width="1.4" fill="none" stroke-linecap="round"/>`
  ][sonrisa];

  return '<svg viewBox="0 0 36 36" role="img" aria-hidden="true">'
    + `<rect width="36" height="36" fill="${fondo}"/>`
    + `<path d="M4 36c0-7 6-10 14-10s14 3 14 10z" fill="${ropa}"/>`
    + `<circle cx="18" cy="15" r="2" fill="${piel}"/>`
    + `<path d="M11 16c0-5 3-8 7-8s7 3 7 8v3c0 5-3 8-7 8s-7-3-7-8z" fill="${piel}"/>`
    + vello
    + `<circle cx="14.6" cy="${ojoY}" r="1.15" fill="#2B2320"/>`
    + `<circle cx="21.4" cy="${ojoY}" r="1.15" fill="#2B2320"/>`
    + boca
    + cabello
    + '</svg>';
}


function avatar(nombre, cls, fallback) {
  const n = String(nombre || '').trim();
  const caja = el('span', {cls:'avatar ' + (cls || '')});
  if (!n || fallback) {
    caja.style.background = PAL[hash(n) % PAL.length];
    caja.textContent = iniciales(n);
    return caja;
  }
  caja.title = n;
  caja.innerHTML = caraSVG(n);   // cadena construida acá, sin dato del usuario
  return caja;
}


const money = (n, m) => (m || 'S/') + ' ' + Number(n || 0).toLocaleString('es-PE');
