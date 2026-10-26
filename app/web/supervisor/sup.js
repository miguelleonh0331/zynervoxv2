'use strict';
/* Maqueta de supervisión — dibujado y comportamiento.

   Una sola página, diez vistas, sin servidor. Todo lo que se ve sale de
   datos.js. El recorrido guiado (guion.js) usa las mismas funciones que
   usa una persona haciendo clic: no hay un camino especial para la demo,
   así lo que se narra es exactamente lo que se puede repetir a mano. */

/* ====================================================== utilidades ==== */
const $  = id => document.getElementById(id);
const $$ = s  => Array.from(document.querySelectorAll(s));

function el(tag, cls, txt) {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (txt !== undefined && txt !== null) n.textContent = txt;
  return n;
}

/* Los iconos son SVG y SVG vive en otro espacio de nombres. Con
   createElement('svg') el navegador crea un elemento HTML desconocido que
   nunca se dibuja: el texto aparece, el icono no. Fue un defecto real en
   la consola del asesor; acá se nace con createElementNS. */
const NS = 'http://www.w3.org/2000/svg';
function svg(nombre, cls) {
  const s = document.createElementNS(NS, 'svg');
  s.setAttribute('class', cls || 'ico');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  s.innerHTML = ICO[nombre] || '';
  return s;
}
function svgNodo(tag, attrs) {
  const n = document.createElementNS(NS, tag);
  for (const k in attrs) n.setAttribute(k, attrs[k]);
  return n;
}

const ICO = {
  piso:       '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  campana:    '<path d="M3 11v2a1 1 0 0 0 1 1h2l4 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M16 8a5 5 0 0 1 0 8"/><path d="M19 5a9 9 0 0 1 0 14"/>',
  base:       '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
  filtro:     '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
  clientes:   '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M17 11a2.8 2.8 0 1 0 0-5.6"/><path d="M18 20c0-2.4-1-4.2-2.6-5.2"/>',
  chat:       '<path d="M21 12a8 8 0 0 1-8 8H7l-4 3v-6.5A8 8 0 0 1 11 4h2a8 8 0 0 1 8 8z"/>',
  calidad:    '<path d="M12 3l7 3v6c0 4.4-3 8-7 9-4-1-7-4.6-7-9V6z"/><path d="M9.2 12.2l2 2 3.6-4"/>',
  equipo:     '<circle cx="12" cy="7" r="3.2"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/>',
  megafono:   '<path d="M4 10v4h3l6 4V6L7 10z"/><path d="M17 9.5a3.5 3.5 0 0 1 0 5"/><circle cx="6" cy="18" r="2"/>',
  reportes:   '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
  buscar:     '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
  tel:        '<path d="M6.5 3h3l1.6 4-2 1.4a12 12 0 0 0 5.5 5.5l1.4-2 4 1.6v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.2 2 2 0 0 1 6.5 3z"/>',
  wa:         '<path d="M4 20l1.3-4A8 8 0 1 1 8.5 19z"/><path d="M9 10.5c.6 2 2.5 3.9 4.5 4.5l1-1.4 2 .8v1.4c-2.6.5-6.6-2.6-7.6-5.8z" fill="currentColor" stroke="none"/>',
  mail:       '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/>',
  sms:        '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21l3-4"/>',
  nota:       '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h4"/>',
  oreja:      '<path d="M4 12a8 8 0 0 1 16 0v5a3 3 0 0 1-3 3h-1v-7h4"/><path d="M4 13H8v7H7a3 3 0 0 1-3-3z"/>',
  micro:      '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/>',
  colgar:     '<path d="M3 11c5-4 13-4 18 0l-2.5 3-3.5-1v-2.3a11 11 0 0 0-6 0V13l-3.5 1z"/>',
  play:       '<path d="M8 5l12 7-12 7z" fill="currentColor" stroke="none"/>',
  stop:       '<rect x="6" y="6" width="12" height="12" rx="1.5" fill="currentColor" stroke="none"/>',
  pausa:      '<rect x="7" y="5" width="3.6" height="14" rx="1" fill="currentColor" stroke="none"/><rect x="13.4" y="5" width="3.6" height="14" rx="1" fill="currentColor" stroke="none"/>',
  reloj:      '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  alerta:     '<path d="M12 4l9 15.5H3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
  check:      '<path d="M5 12.5l4.5 4.5L19 7"/>',
  equis:      '<path d="M6 6l12 12M18 6L6 18"/>',
  tv:         '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8"/>',
  menu:       '<path d="M4 7h16M4 12h16M4 17h16"/>',
  subir:      '<path d="M12 16V5"/><path d="M7.5 9.5L12 5l4.5 4.5"/><path d="M4 19h16"/>',
  imagen:     '<rect x="3" y="4" width="18" height="15" rx="2"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="M4 17l5-4.5 3.5 3L16 12l4 4.5"/>',
  enviar:     '<path d="M21 3L3 10.5l7 3 3 7z"/><path d="M10 13.5L21 3"/>',
  mano:       '<path d="M8 12V5.5a1.5 1.5 0 0 1 3 0V11"/><path d="M11 11V4.5a1.5 1.5 0 0 1 3 0V11"/><path d="M14 11.5V6.5a1.5 1.5 0 0 1 3 0V14a6 6 0 0 1-6 6H10a5 5 0 0 1-5-5v-3.5a1.5 1.5 0 0 1 3 0z"/>',
  estrella:   '<path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.7l5.9-.8z"/>',
  libro:      '<path d="M4 4.5h6a3 3 0 0 1 3 3V20a2.5 2.5 0 0 0-2.5-2.5H4z"/><path d="M20 4.5h-6a3 3 0 0 0-3 3V20a2.5 2.5 0 0 1 2.5-2.5H20z"/>',
  flecha:     '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
  fuego:      '<path d="M12 3s5 4 5 8a5 5 0 0 1-10 0c0-1.6.8-3 1.6-4 .2 1.2.9 2 1.9 2 1.4 0 1.5-1.6 1.5-6z"/>',
  mas:        '<path d="M12 5v14M5 12h14"/>',
};

/* Retrato dibujado a partir del nombre. Mismo nombre, misma cara, siempre.
   No se descarga nada: en una demostración no puede haber una foto que no
   cargue porque la red del cliente bloquea el dominio. */
function hash(t) { let h = 0; for (const c of String(t)) h = (h * 31 + c.charCodeAt(0)) >>> 0; return h; }
function caraSVG(nombre) {
  const h = hash(nombre);
  const piel = ['#F2D3B8','#E6BE9C','#D9A77B','#C08A5E','#A86E46','#8A5633'][h % 6];
  const pelo = ['#2F2A26','#4A3728','#6B4A2F','#8C6239','#1F1B18','#5B4636'][(h >> 3) % 6];
  const fondo= ['#DBEAFE','#DCFCE7','#FEF3C7','#F3E8FF','#E0F2FE','#FFE4E6'][(h >> 6) % 6];
  const larga = (h >> 9) % 2 === 0;
  const ropa = ['#2563EB','#0F766E','#7C3AED','#B45309','#334155','#BE123C'][(h >> 11) % 6];
  return '<svg viewBox="0 0 40 40" xmlns="' + NS + '">' +
    '<rect width="40" height="40" fill="' + fondo + '"/>' +
    (larga ? '<path d="M8 22c0-9 3-13 12-13s12 4 12 13v10H8z" fill="' + pelo + '"/>' : '') +
    '<path d="M20 31c7 0 11 3.5 11 9H9c0-5.5 4-9 11-9z" fill="' + ropa + '"/>' +
    '<ellipse cx="20" cy="19" rx="8.6" ry="9.4" fill="' + piel + '"/>' +
    '<path d="M11 17c0-6 4-9 9-9s9 3 9 9c0-3-3.5-4.5-9-4.5S11 14 11 17z" fill="' + pelo + '"/>' +
    '<circle cx="16.8" cy="19" r="1.15" fill="#1F2937"/>' +
    '<circle cx="23.2" cy="19" r="1.15" fill="#1F2937"/>' +
    '<path d="M17.4 23.4c1.6 1.2 3.6 1.2 5.2 0" stroke="#8B5E4A" stroke-width="1.1" fill="none" stroke-linecap="round"/>' +
    '</svg>';
}
function avatar(nombre, cls) { const s = el('span', cls || 'quien-cara'); s.innerHTML = caraSVG(nombre); return s; }

const miles = n => Math.round(n).toLocaleString('es-PE');
const money = n => 'S/ ' + miles(n);
const pct   = n => String(n).replace('.', ',') + ' %';
const mmss  = s => Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');

/* ======================================================== estado ===== */
const S = {
  vista:'piso', plegado:false, escucha:null, modo:null,
  cliente:4820, chatCon:'5003', tab:'resumen',
  seg:[{campo:'tip', op:'es', val:'NO CONTESTA, REINTENTAR'},
       {campo:'intentos', op:'entre', val:'1 y 3'},
       {campo:'ultima', op:'hace más de', val:'48 horas'}],
  cargaPaso:1, tour:false,
};

/* ========================================================= menú ====== */
const VISTAS = [
  {k:'piso',       t:'Piso en vivo',    i:'piso',     g:'Operación'},
  {k:'campanas',   t:'Campañas',        i:'campana',  g:'Operación'},
  {k:'base',       t:'Carga de base',   i:'base',     g:'Base'},
  {k:'segmentos',  t:'Segmentación',    i:'filtro',   g:'Base'},
  {k:'clientes',   t:'Clientes',        i:'clientes', g:'Contacto'},
  {k:'conversa',   t:'Conversaciones',  i:'chat',     g:'Contacto'},
  {k:'comunica',   t:'Comunicación',    i:'megafono', g:'Contacto'},
  {k:'calidad',    t:'Calidad',         i:'calidad',  g:'Gestión'},
  {k:'monitoreo',  t:'Monitoreo remoto',i:'monitor',  g:'Gestión'},
  {k:'equipo',     t:'Equipo',          i:'equipo',   g:'Gestión'},
  {k:'reportes',   t:'Reportes',        i:'reportes', g:'Gestión'},
];

function pintarNav() {
  const nav = $('sideNav'); nav.replaceChildren();
  let grupo = '';
  for (const v of VISTAS) {
    if (v.g !== grupo) { grupo = v.g; nav.append(el('div', 'side-sep', grupo)); }
    const b = el('button', 'side-link' + (S.vista === v.k ? ' is-on' : ''));
    b.type = 'button'; b.id = 'nav-' + v.k; b.dataset.vista = v.k;
    b.append(svg(v.i), el('span', null, v.t));
    if (v.k === 'conversa') { const c = el('b', 'side-count side-count--alerta', '2'); b.append(c); }
    if (v.k === 'piso')     { const c = el('b', 'side-count', String(EN_CURSO.filter(x => !x.cola).length)); b.append(c); }
    b.onclick = () => irA(v.k);
    nav.append(b);
  }
}

function irA(k) {
  S.vista = k;
  pintarNav();
  $('canvas').scrollTop = 0;
  const f = {piso:vPiso, campanas:vCampanas, base:vBase, segmentos:vSegmentos,
             clientes:vClientes, conversa:vConversa, comunica:vComunica,
             calidad:vCalidad, monitoreo:vMonitoreo, equipo:vEquipo,
             reportes:vReportes}[k];
  const c = $('canvas'); c.replaceChildren();
  if (f) f(c);
  const v = VISTAS.find(x => x.k === k);
  $('stVista').textContent = v ? v.t : k;
}

/* ====================================================== piezas ======= */
function tarjeta(tit, sub, opts) {
  opts = opts || {};
  const t = el('section', 'tarj' + (opts.cls ? ' ' + opts.cls : ''));
  if (opts.id) t.id = opts.id;
  if (tit) {
    const h = el('header');
    h.append(el('h2', null, tit));
    if (sub) h.append(el('span', 'sub', sub));
    if (opts.sello) h.append(el('span', 'sello', opts.sello));
    if (opts.der) { const d = el('div', 'der'); opts.der.forEach(x => d.append(x)); h.append(d); }
    t.append(h);
  }
  const c = el('div', opts.plano ? '' : 'tarj-cuerpo');
  t.append(c); t.cuerpo = c;
  if (opts.pie) { const p = el('div', 'tarj-pie'); p.append(opts.pie); t.append(p); }
  return t;
}

function kpi(rot, val, pie, tono, ico, color) {
  const k = el('div', 'kpi');
  const i = el('div', 'kpi-ico'); i.style.background = color || 'var(--primary)';
  i.append(svg(ico || 'piso'));
  const t = el('div', 'kpi-txt');
  t.append(el('div', 'kpi-rot', rot), el('div', 'kpi-val', val));
  if (pie) t.append(el('div', 'kpi-pie ' + (tono || 'neutro'), pie));
  k.append(i, t);
  return k;
}

function boton(txt, cls, fn, ico) {
  const b = el('button', 'btn ' + (cls || '')); b.type = 'button';
  if (ico) b.append(svg(ico));
  b.append(el('span', null, txt));
  if (fn) b.onclick = fn;
  return b;
}

function pill(estado) {
  const e = ESTADOS[estado] || ESTADOS.off;
  return el('span', 'pill pill--' + e.c, e.t);
}

function anillo(valor, tam, color) {
  const r = 46, c = 2 * Math.PI * r;
  const s = svgNodo('svg', {viewBox:'0 0 100 100'});
  s.append(svgNodo('circle', {cx:50, cy:50, r, fill:'none', stroke:'#E2E8F0', 'stroke-width':9}));
  s.append(svgNodo('circle', {cx:50, cy:50, r, fill:'none', stroke:color || 'var(--ok)',
    'stroke-width':9, 'stroke-linecap':'round',
    'stroke-dasharray':c, 'stroke-dashoffset':c * (1 - valor / 100)}));
  const w = el('div', 'anillo'); w.style.width = w.style.height = (tam || 118) + 'px';
  w.append(s);
  return w;
}

/* ================================================== VISTA: piso ====== */
function vPiso(c) {
  const enLinea = AGENTES.filter(a => a.estado !== 'off').length;
  const enCall  = AGENTES.filter(a => a.estado === 'call').length;

  const k = el('div', 'kpis'); k.id = 'pisoKpis';
  k.append(
    kpi('Agentes conectados', enLinea + ' / ' + AGENTES.length, 'En llamada: ' + enCall, 'neutro', 'equipo', '#2563EB'),
    kpi('Llamadas hoy', '1 847', '+12 % vs. ayer', 'sube', 'tel', '#0891B2'),
    kpi('Contactabilidad', '36,4 %', '673 personas atendieron', 'neutro', 'check', '#16A34A'),
    kpi('Ventas', '31', '+17 % vs. ayer', 'sube', 'estrella', '#7C3AED'),
    kpi('Producción', money(REAL_AHORA), Math.round(REAL_AHORA / META_DIA * 100) + ' % de la meta', 'sube', 'reportes', '#D97706'),
    kpi('Abandono', '4,2 %', 'Umbral: 3 %', 'baja', 'alerta', '#DC2626'),
  );
  c.append(k);

  const g = el('div', 'piso'); g.style.marginTop = '12px';
  const izq = el('div', 'rejilla'), der = el('div', 'rejilla');

  izq.append(semaforoMotor(), lineaDelPiso(), tablaEquipo());
  der.append(tarjEnCurso(), tarjAlertas());
  g.append(izq, der);
  c.append(g);

  $('stAgentes').textContent = enLinea + ' agentes conectados';
  $('stLlamadas').textContent = enCall + ' llamadas en curso';
}

/* ---- semáforo del marcador ---- */
function semaforoMotor() {
  const camp = CAMPANAS[0] || {id:'', nombre:'—', nivel:1, canales:0, abandono:0};
  const t = tarjeta('Marcador en vivo', camp.nombre, {id:'cardMotor', der:[
    boton('Detener', 'btn--mini', () => aviso('El marcador se detuvo (maqueta)'), 'stop')
  ]});
  const m = el('div', 'motor');

  /* Aguja de abandono: lo único que puede costar plata si se descuida. */
  const ab = camp.abandono, tope = 8;
  const a = el('div', 'aguja'); a.id = 'aguja';
  const s = svgNodo('svg', {viewBox:'0 0 150 84'});
  const arco = (de, a2, col, w) => {
    const p = (ang) => [75 + 62 * Math.cos(Math.PI * (1 - ang)), 72 - 62 * Math.sin(Math.PI * (1 - ang))];
    const [x1, y1] = p(de), [x2, y2] = p(a2);
    return svgNodo('path', {d:`M${x1} ${y1} A 62 62 0 0 1 ${x2} ${y2}`, stroke:col,
      'stroke-width':w || 10, fill:'none', 'stroke-linecap':'round'});
  };
  s.append(arco(0, 3 / tope, '#16A34A'), arco(3 / tope, 5 / tope, '#D97706'), arco(5 / tope, 1, '#DC2626'));
  const ang = Math.PI * (1 - Math.min(ab / tope, 1));
  s.append(svgNodo('line', {x1:75, y1:72, x2:75 + 50 * Math.cos(ang), y2:72 - 50 * Math.sin(ang),
    stroke:'#0F172A', 'stroke-width':3, 'stroke-linecap':'round'}));
  s.append(svgNodo('circle', {cx:75, cy:72, r:5, fill:'#0F172A'}));
  a.append(s);
  const av = el('div', 'aguja-val');
  av.append(el('b', null, pct(ab)), el('span', null, 'abandono · umbral 3 %'));
  a.append(av);

  const d = el('div', 'motor-datos');
  const dato = (rot, val, mal) => { const x = el('div', 'mdato' + (mal ? ' mdato--mal' : ''));
    x.append(el('span', null, rot), el('b', null, val)); return x; };
  d.append(
    dato('Nivel de marcado', String(camp.nivel).replace('.', ',')),
    dato('Canales en uso', '9 / ' + camp.canales),
    dato('Timbrando', '6'),
    dato('Colgadas por el motor', '28', true),
    dato('Espera promedio', '7,4 s'),
    dato('Ocupación', '71 %'),
  );

  const ctl = el('div', 'motor-ctl');
  const per = el('div', 'perilla');
  const rango = el('input'); rango.type = 'range'; rango.min = '1'; rango.max = '4';
  rango.step = '0.1'; rango.value = String(camp.nivel); rango.id = 'rangoNivel';
  const vnum = el('b', null, String(camp.nivel).replace('.', ','));
  rango.oninput = () => { vnum.textContent = rango.value.replace('.', ','); };
  rango.onchange = () => aviso('Nivel de marcado en ' + rango.value.replace('.', ',') + ' (maqueta)');
  per.append(el('span', null, 'Agresividad'), rango, vnum);
  ctl.append(per);
  const pan = boton('Bajar a 1:1 ahora', 'btn--danger', () => {
    rango.value = '1'; vnum.textContent = '1';
    aviso('Marcado en 1:1. El abandono baja en los próximos minutos.');
  }, 'alerta');
  pan.id = 'btnPanico';
  ctl.append(pan);
  ctl.append(el('div', 'esc-nota', 'El botón de pánico deja un agente por llamada. Se usa cuando el abandono se dispara.'));

  m.append(a, d, ctl);
  t.cuerpo.append(m);
  return t;
}

/* ---- línea de tiempo del piso ---- */
function lineaDelPiso() {
  const leyenda = el('div', 'tl-leyenda');
  [['call','En llamada','#16A34A'],['wrap','Tipificando','#60A5FA'],
   ['espera','Esperando','#CBD5E1'],['pausa','En pausa','#F59E0B'],
   ['off','Sin conectar','#E2E8F0']].forEach(([, t, c]) => {
    const s = el('span'); const i = el('i'); i.style.background = c;
    s.append(i, document.createTextNode(t)); leyenda.append(s);
  });

  const t = tarjeta('La mañana del piso', HOY.fecha, {id:'cardTL', pie:leyenda});
  const cont = el('div', 'tl'); const grid = el('div', 'tl-grid');
  const span = HOY.fin - HOY.ini;
  const pos = m => ((m - HOY.ini) / span * 100);

  // Regla de horas.
  const head = el('div', 'tl-fila tl-fila--head');
  head.append(el('div', 'tl-quien'));
  const pistaH = el('div', 'tl-pista');
  for (let h = 8; h <= 17; h++) {
    const x = el('span', 'tl-hora', h + ':00');
    x.style.left = pos(h * 60) + '%';
    if (h > 8 && h < 17) x.append(el('i'));
    pistaH.append(x);
  }
  head.append(pistaH); grid.append(head);

  for (const ag of AGENTES) {
    const f = el('div', 'tl-fila'); f.dataset.ag = ag.id;
    const q = el('div', 'tl-quien');
    q.append(avatar(ag.nombre, 'ag-chip-cara'), el('b', null, ag.nombre));
    const pista = el('div', 'tl-pista');
    for (const b of (PISTAS[ag.id] || [])) {
      const d = el('i', 'tl-b tl-b--' + b.t);
      d.style.left = pos(b.a) + '%';
      d.style.width = Math.max(0.35, b.d / span * 100) + '%';
      d.title = b.q + ' · ' + reloj(b.a) + '–' + reloj(b.a + b.d) + ' (' + b.d + ' min)';
      pista.append(d);
    }
    const ahora = el('i', 'tl-ahora'); ahora.style.left = pos(HOY.ahora) + '%';
    pista.append(ahora);
    f.append(q, pista);
    f.onclick = () => fichaAgente(ag.id);
    grid.append(f);
  }
  cont.append(grid); t.cuerpo.append(cont);
  return t;
}

/* ---- tabla del equipo ---- */
function tablaEquipo() {
  const t = tarjeta('Actividad del equipo', 'Al día de hoy', {plano:true, id:'cardEquipo', der:[
    boton('Exportar', 'btn--mini', () => aviso('Se descargó actividad_equipo.csv (maqueta)'), 'subir')
  ]});
  const tb = el('table', 'tabla');
  const cab = el('tr');
  ['Asesor','Campaña','Estado','Llamadas','Contactados','Ventas','Producción','Ocupación','']
    .forEach((h, i) => { const th = el('th', i >= 3 && i <= 7 ? 'num' : '', h); cab.append(th); });
  const thead = el('thead'); thead.append(cab); tb.append(thead);

  const body = el('tbody'); body.id = 'equipoBody';
  for (const a of AGENTES) {
    const tr = el('tr'); tr.dataset.ag = a.id;
    const td1 = el('td'); const q = el('div', 'quien');
    const tx = el('div', 'quien-txt'); tx.append(el('b', null, a.nombre), el('em', null, 'Anexo ' + a.id));
    q.append(avatar(a.nombre), tx); td1.append(q);

    const td2 = el('td');
    td2.append(document.createTextNode(a.activa || 'Sin asignar'));
    if ((a.camps || []).length > 1) {
      const mas = el('span', 'pill pill--sin pill--wrap', '+' + (a.camps.length - 1));
      mas.style.marginLeft = '5px';
      mas.title = 'Habilitado en: ' + (a.camps || []).map(nombreCamp).join(' · ');
      td2.append(mas);
    }
    if (esMixto(a)) {
      const mx = el('span', 'pill pill--sin pill--mixto', 'MIXTO');
      mx.style.marginLeft = '4px';
      mx.title = 'Atiende entrantes y salientes';
      td2.append(mx);
    }
    const td3 = el('td'); td3.append(pill(a.estado));
    if (a.estado === 'pausa') {
      const m = HOY.ahora - a.desde;
      const e = el('em', null, ' ' + a.motivo + ' · ' + m + ' min');
      e.style.cssText = 'font-style:normal;font-size:10px;color:' + (m > 20 ? 'var(--danger)' : 'var(--text-muted)');
      td3.append(e);
    }
    const td4 = el('td', 'num', String(a.llam));
    const td5 = el('td', 'num', String(a.cont));
    const td6 = el('td', 'num', String(a.vent));
    const td7 = el('td', 'num', a.monto ? money(a.monto) : '—');
    const td8 = el('td', 'num'); const bb = el('div', 'barra'); const bi = el('i');
    bi.style.width = a.ocup + '%';
    bi.style.background = a.ocup >= 70 ? 'var(--ok)' : a.ocup >= 55 ? 'var(--warn)' : 'var(--danger)';
    bb.append(bi); const bw = el('div'); bw.style.cssText = 'display:flex;align-items:center;gap:7px;justify-content:flex-end';
    bw.append(bb, el('span', null, a.ocup + '%')); td8.append(bw);

    const td9 = el('td'); const acc = el('div', 'acc');
    if (a.estado === 'call') {
      const o = el('button', 'mini'); o.type = 'button'; o.title = 'Escuchar la llamada';
      o.append(svg('oreja')); o.onclick = e => { e.stopPropagation(); abrirEscucha(a.id); };
      o.id = 'oir-' + a.id;
      acc.append(o);
    }
    const ch = el('button', 'mini'); ch.type = 'button'; ch.title = 'Chat interno';
    ch.append(svg('chat')); ch.onclick = e => { e.stopPropagation(); abrirChatFlot(a.id); };
    acc.append(ch); td9.append(acc);

    tr.append(td1, td2, td3, td4, td5, td6, td7, td8, td9);
    tr.onclick = () => fichaAgente(a.id);
    body.append(tr);
  }
  tb.append(body); t.cuerpo.append(tb);
  return t;
}

/* ---- llamadas en curso ---- */
function tarjEnCurso() {
  const t = tarjeta('Llamadas en curso', null, {plano:true, id:'cardCurso'});
  const l = el('div', 'lives');
  for (const c of EN_CURSO) {
    const f = el('div', 'live');
    const ag = porId(c.ag);
    f.append(avatar(ag ? ag.nombre : c.cliente, 'live-cara'));
    const tx = el('div', 'live-txt');
    tx.append(el('b', null, c.cliente),
              el('em', null, (ag ? ag.nombre : 'Sin asignar') + ' · ' + c.etapa));
    const der = el('div', 'live-der');
    const tt = el('span', 'live-t', mmss(c.seg));
    if (c.cola) tt.style.color = 'var(--warn)';
    der.append(tt);
    if (!c.cola) {
      const o = el('button', 'mini'); o.type = 'button'; o.title = 'Entrar a la llamada';
      o.append(svg('oreja')); o.onclick = () => abrirEscucha(c.ag);
      der.append(o);
    }
    f.append(tx, der);
    l.append(f);
  }
  t.cuerpo.append(l);
  return t;
}

/* ---- alertas accionables ---- */
function tarjAlertas() {
  const t = tarjeta('Alertas', ALERTAS.length + ' sin atender', {plano:true, id:'cardAlertas'});
  const l = el('div', 'alertas');
  for (const a of ALERTAS) {
    const f = el('div', 'alerta alerta--' + a.sev); f.id = 'alerta-' + a.n;
    const i = el('div', 'alerta-ico'); i.append(svg(a.sev === 'alta' ? 'alerta' : a.sev === 'media' ? 'reloj' : 'base'));
    const tx = el('div', 'alerta-txt');
    tx.append(el('b', null, a.tit), el('p', null, a.txt));
    const acc = el('div', 'alerta-acc');
    for (const b of (a.acc || [])) acc.append(boton(b.t, 'btn--mini', () => accion(b.a)));
    acc.append(el('span', 'alerta-hora', a.hora));
    tx.append(acc);
    f.append(i, tx);
    l.append(f);
  }
  t.cuerpo.append(l);
  return t;
}

/* ================================================ VISTA: campañas ==== */
function vCampanas(c) {
  c.append(titulo('Campañas', 'Quién trabaja en qué. Arrastre un agente de una campaña a otra.'));

  const g = el('div', 'camps'); g.id = 'camps';
  for (const k of CAMPANAS) g.append(tarjCampana(k));
  c.append(g);

  // Quién no está en ninguna. También es donde se suelta a quien se saca.
  const libres = AGENTES.filter(a => !a.camps.length);
  const sz = el('div', 'sin-asignar'); sz.id = 'sinAsignar';
  sz.append(el('span', 'rot', 'Sin asignar:'));
  if (!libres.length) sz.append(el('span', 'rot', 'nadie — arrastre aquí para sacar a alguien de su campaña'));
  libres.forEach(a => sz.append(chipAgente(a, null)));
  sz.ondragover = e => { e.preventDefault(); sz.classList.add('is-drop'); };
  sz.ondragleave = () => sz.classList.remove('is-drop');
  sz.ondrop = e => {
    e.preventDefault(); sz.classList.remove('is-drop');
    const [id, orig] = String(e.dataTransfer.getData('text/plain')).split('|');
    const a = porId(id);
    if (!a || !orig) return;
    a.camps = a.camps.filter(x => x !== orig);
    normalizar(a);
    irA('campanas');
    aviso(a.nombre + ' salió de ' + nombreCamp(orig));
  };
  const envoltorio = el('div'); envoltorio.style.margin = '12px 0';
  envoltorio.append(sz);
  c.append(envoltorio);

  // El panel explícito: la grilla de agentes por campañas.
  c.append(panelAsignacion());

  const abajo = el('div', 'piso'); abajo.style.marginTop = '12px';

  const banco = tarjeta('Cómo se asigna', null, {id:'cardBanco'});
  const ayuda = el('div'); ayuda.style.cssText = 'font-size:11.5px;line-height:1.7';
  ayuda.innerHTML =
    '<b>Para moverlo de campaña:</b> arrastre su pastilla a otra tarjeta. Sale de una y entra en la otra.<br>' +
    '<b>Para sacarlo:</b> la ✕ de su pastilla, o arrástrelo a «Sin asignar».<br>' +
    '<b>Para que esté en dos a la vez:</b> use la grilla de arriba y marque las dos casillas.<br>' +
    '<b>El punto lleno</b> ● es la campaña en la que está trabajando en este momento; ' +
    'la elige el agente al entrar, entre las que usted le habilitó.';
  banco.cuerpo.append(ayuda);

  const cola = tarjeta('Cola de entrantes', 'Nivel de servicio: 91 % atendidas en menos de 20 s', {plano:true, id:'cardCola'});
  const cl = el('div', 'cola-in');
  if (!COLA_IN.length) cl.append(el('div', 'vacio', 'Nadie esperando.'));
  for (const q of COLA_IN) {
    const f = el('div', 'cola-fila');
    const w = el('div'); w.append(el('b', null, q.quien));
    w.append(el('div', null, q.tel + ' · ' + q.motivo));
    f.append(w);
    f.append(el('span', 'cola-t' + (q.esp > 30 ? ' urgente' : ''), q.esp + ' s'));
    f.append(boton('Asignar', 'btn--mini', () => aviso('Llamada asignada a María Chávez (maqueta)')));
    cl.append(f);
  }
  cola.cuerpo.append(cl);

  abajo.append(banco, cola);
  c.append(abajo);
}

/* Si el agente deja de estar habilitado donde estaba trabajando, hay que
   moverlo a otra o dejarlo sin ninguna: una «activa» que ya no figura en
   camps es un estado imposible. */
function normalizar(a) {
  if (!a.camps.includes(a.activa)) a.activa = a.camps[0] || null;
}

function chipAgente(a, campId) {
  const ch = el('div', 'ag-chip' + (campId && a.activa === campId ? ' ag-chip--activa' : ''));
  ch.draggable = true; ch.dataset.ag = a.id;
  ch.title = a.activa === campId ? 'Está trabajando en esta campaña ahora' : 'Habilitado, no es su campaña activa';
  ch.append(avatar(a.nombre, 'ag-chip-cara'),
            el('span', null, a.nombre.split(' ')[0] + ' ' + a.nombre.split(' ')[1]));
  if (campId) {
    const x = el('button', 'quitar', '✕'); x.type = 'button';
    x.title = 'Quitar de esta campaña';
    x.onclick = e => {
      e.stopPropagation();
      a.camps = a.camps.filter(c => c !== campId);
      normalizar(a);
      irA('campanas');
      aviso(a.nombre + ' salió de ' + nombreCamp(campId));
    };
    ch.append(x);
  }
  ch.ondragstart = e => { e.dataTransfer.setData('text/plain', a.id + '|' + (campId || '')); ch.classList.add('is-arrastre'); };
  ch.ondragend = () => ch.classList.remove('is-arrastre');
  return ch;
}

/* ---- panel de asignación: quién puede trabajar en qué ---- */
function panelAsignacion() {
  const t = tarjeta('Asignación de agentes',
    'Marque en qué campañas puede trabajar cada uno', {plano:true, id:'cardAsignacion'});
  const tb = el('table', 'asig');

  const h = el('tr');
  h.append(el('th', null, 'Asesor'));
  for (const c of CAMPANAS) {
    const th = el('th');
    th.append(document.createTextNode(String(c.nombre || c.id || '—').split(' — ')[0]));
    th.append(el('span', 'ct ct--' + c.tipo, c.tipo === 'out' ? 'SALIENTE' : 'ENTRANTE'));
    h.append(th);
  }
  h.append(el('th', null, ''));
  const th0 = el('thead'); th0.append(h); tb.append(th0);

  const body = el('tbody'); body.id = 'asigBody';
  for (const a of AGENTES) {
    const tr = el('tr'); tr.dataset.ag = a.id;
    const td1 = el('td'); const q = el('div', 'quien');
    const tx = el('div', 'quien-txt');
    tx.append(el('b', null, a.nombre), el('em', null, 'Anexo ' + a.id));
    q.append(avatar(a.nombre), tx); td1.append(q); tr.append(td1);

    for (const c of CAMPANAS) {
      const td = el('td');
      const puesto = a.camps.includes(c.id);
      const activa = a.activa === c.id;
      const b = el('button', 'asig-cel' + (activa ? ' is-activa' : puesto ? ' is-on' : ''));
      b.type = 'button';
      b.id = 'asig-' + a.id + '-' + c.id;
      b.textContent = activa ? '●' : puesto ? '✓' : '';
      b.title = activa ? 'Trabajando aquí ahora' : puesto ? 'Habilitado' : 'Sin habilitar';
      b.onclick = () => {
        if (puesto) a.camps = a.camps.filter(x => x !== c.id);
        else a.camps = a.camps.concat(c.id);
        normalizar(a);
        irA('campanas');
        aviso(a.nombre + (puesto ? ' salió de ' : ' quedó habilitado en ') + nombreCamp(c.id) +
              (!puesto && esMixto(a) ? ' · ahora es un agente mixto' : ''));
      };
      td.append(b); tr.append(td);
    }

    const td9 = el('td');
    if (esMixto(a)) td9.append(el('span', 'pill pill--sin pill--mixto', 'MIXTO'));
    else if (!a.camps.length) td9.append(el('span', 'pill pill--sin pill--off', 'Sin asignar'));
    tr.append(td9);
    body.append(tr);
  }
  tb.append(body);
  t.cuerpo.append(tb);

  const pie = el('div'); pie.style.padding = '11px 12px';
  const ley = el('div', 'asig-leyenda');
  const ej = (cls, car, txt2) => { const s = el('span');
    const c0 = el('i', 'asig-cel ' + cls); c0.textContent = car; s.append(c0, document.createTextNode(txt2));
    return s; };
  ley.append(ej('', '', 'sin habilitar'), ej('is-on', '✓', 'habilitado'),
             ej('is-activa', '●', 'trabajando ahí ahora'));
  pie.append(ley);

  const r = el('div', 'regla-mixto');
  r.append(svg('alerta'));
  const rw = el('div');
  rw.innerHTML = '<b>Agente mixto.</b> Quien queda habilitado a la vez en una saliente y una ' +
    'entrante atiende las dos. La regla es: <b>mientras haya alguien esperando en la cola ' +
    'entrante, el marcador no le entrega llamadas salientes</b>; cuando la cola se vacía, ' +
    'vuelve a marcar.';
  r.append(rw);
  r.append(el('span', 'sello', 'PRÓXIMAMENTE'));
  pie.append(r);
  t.cuerpo.append(pie);
  return t;
}

function tarjCampana(k) {
  const t = el('section', 'camp'); t.id = 'camp-' + k.id;
  const h = el('div', 'camp-head');
  h.append(el('b', null, k.nombre));
  h.append(el('span', 'camp-tipo camp-tipo--' + k.tipo, k.tipo === 'out' ? 'SALIENTE' : 'ENTRANTE'));
  const der = el('div'); der.style.cssText = 'margin-left:auto;display:flex;gap:6px;align-items:center';
  if (k.tipo === 'out') {
    const on = k.estado === 'on';
    const b = el('button', 'mini'); b.type = 'button'; b.title = on ? 'Detener' : 'Iniciar';
    b.append(svg(on ? 'stop' : 'play'));
    b.style.cssText = on ? 'background:var(--danger);border-color:var(--danger);color:#fff'
                         : 'background:var(--ok);border-color:var(--ok);color:#fff';
    b.onclick = () => aviso((on ? 'Campaña detenida: ' : 'Campaña iniciada: ') + k.nombre + ' (maqueta)');
    der.append(b);
  }
  h.append(der);
  t.append(h);

  const b = el('div', 'camp-body');
  const n = el('div', 'camp-nums');
  const num = (v, r) => { const x = el('div', 'camp-num'); x.append(el('b', null, v), el('span', null, r)); return x; };
  if (k.tipo === 'out') {
    n.append(num(miles(k.leads), 'leads'), num(miles(k.pend), 'sin llamar'),
             num(miles(k.cont), 'contactados'), num(String(k.vent), 'ventas'));
  } else {
    n.append(num(String(k.recibidas), 'recibidas'), num(String(k.atendidas), 'atendidas'),
             num(String(k.abandonadas), 'perdidas'), num(k.nivelServicio + '%', 'nivel serv.'));
  }
  b.append(n);

  if (k.tipo === 'out') {
    const pro = el('div'); pro.style.cssText = 'display:flex;align-items:center;gap:9px;font-size:10.5px;color:var(--text-muted)';
    const bar = el('div', 'barra'); bar.style.flex = '1';
    const bi = el('i'); bi.style.width = (k.hechas / k.leads * 100) + '%'; bar.append(bi);
    pro.append(bar, el('span', null, Math.round(k.hechas / k.leads * 100) + '% recorrida'));
    b.append(pro);
  }

  const zona = el('div', 'camp-agentes'); zona.dataset.camp = k.id;
  const suyos = agentesDe(k.id);
  if (!suyos.length) {
    const v = el('span'); v.style.cssText = 'font-size:10.5px;color:var(--text-muted);padding:0 4px';
    v.textContent = 'Sin agentes asignados'; zona.append(v);
  }
  suyos.forEach(a => zona.append(chipAgente(a, k.id)));
  zona.ondragover = e => { e.preventDefault(); zona.classList.add('is-drop'); };
  zona.ondragleave = () => zona.classList.remove('is-drop');
  /* Arrastrar MUEVE: sale de donde estaba y entra acá. Para que trabaje en
     dos a la vez está el panel de asignación, que es explícito. */
  zona.ondrop = e => {
    e.preventDefault(); zona.classList.remove('is-drop');
    const [id, orig] = String(e.dataTransfer.getData('text/plain')).split('|');
    const a = porId(id);
    if (!a || a.camps.includes(k.id)) return;
    if (orig) a.camps = a.camps.filter(x => x !== orig);
    a.camps = a.camps.concat(k.id);
    if (orig ? a.activa === orig : !a.activa) a.activa = k.id;
    normalizar(a);
    irA('campanas');
    aviso(a.nombre + (orig ? ' pasó de ' + nombreCamp(orig) + ' a ' : ' entró a ') + k.nombre);
  };
  b.append(zona);
  t.append(b);
  return t;
}

/* ================================================ VISTA: carga ======= */
function vBase(c) {
  c.append(titulo('Carga de base', 'Del archivo a la campaña, viendo lo que entra y lo que se rechaza.'));

  const pasos = el('div', 'chips'); pasos.style.marginBottom = '11px';
  ['1. Archivo', '2. Mapeo de columnas', '3. Validación', '4. Destino'].forEach((t, i) => {
    const ch = el('button', 'chip' + (S.cargaPaso === i + 1 ? ' is-on' : '')); ch.type = 'button';
    ch.textContent = t; ch.id = 'paso-' + (i + 1);
    ch.onclick = () => { S.cargaPaso = i + 1; irA('base'); };
    pasos.append(ch);
  });
  c.append(pasos);

  if (S.cargaPaso === 1) c.append(pasoArchivo());
  if (S.cargaPaso === 2) c.append(pasoMapeo());
  if (S.cargaPaso === 3) c.append(pasoValidacion());
  if (S.cargaPaso === 4) c.append(pasoDestino());

  c.append(tarjHistorialCargas());
}

function pasoArchivo() {
  const t = tarjeta('El archivo', null, {id:'cardArchivo'});
  const z = el('div', 'suelta'); z.id = 'zonaSuelta';
  z.append(svg('subir', 'ico'));
  z.append(el('b', null, ARCHIVO.nombre + ' · ' + miles(ARCHIVO.filas) + ' filas · ' + ARCHIVO.peso));
  z.append(el('span', null, 'Arrastre aquí un Excel o CSV. También se puede pegar desde el portapapeles.'));
  const b = boton('Continuar al mapeo', 'btn--primary', () => { S.cargaPaso = 2; irA('base'); }, 'flecha');
  b.id = 'btnMapeo'; b.style.marginTop = '13px';
  z.append(b);
  z.ondragover = e => { e.preventDefault(); z.classList.add('is-drop'); };
  z.ondragleave = () => z.classList.remove('is-drop');
  z.ondrop = e => { e.preventDefault(); z.classList.remove('is-drop'); S.cargaPaso = 2; irA('base'); };
  t.cuerpo.append(z);
  return t;
}

const CAMPOS_DESTINO = ['', 'telefono', 'nombre', 'apellidos', 'documento', 'correo', 'producto',
  'monto', 'ciudad', 'direccion', 'profesion', 'empresa', 'origen', 'no cargar'];

function pasoMapeo() {
  const t = tarjeta('Mapeo de columnas', 'El sistema propone el calce; usted corrige lo que haga falta', {
    id:'cardMapeo',
    der:[boton('Guardar como plantilla', 'btn--mini', () => aviso('Plantilla “Carsa mensual” guardada'))]
  });
  const tb = el('table', 'mapeo');
  const h = el('tr');
  ['Columna del archivo', 'Primer valor', 'Campo en Zynervox', ''].forEach(x => h.append(el('th', null, x)));
  const th = el('thead'); th.append(h); tb.append(th);
  const body = el('tbody');
  for (const col of ARCHIVO.columnas) {
    const tr = el('tr');
    tr.append(el('td', null, col.col));
    const td2 = el('td'); td2.append(el('code', null, col.ej)); tr.append(td2);
    const td3 = el('td'); const sel = el('select');
    CAMPOS_DESTINO.forEach(cd => { const o = el('option', null, cd || '— elegir —'); o.value = cd;
      if (cd === col.campo) o.selected = true; sel.append(o); });
    sel.onchange = () => aviso('«' + col.col + '» ahora carga en ' + (sel.value || 'ningún campo'));
    td3.append(sel); tr.append(td3);
    const td4 = el('td');
    if (col.auto) td4.append(el('span', 'auto', 'detectado'));
    tr.append(td4);
    body.append(tr);
  }
  tb.append(body);
  t.cuerpo.append(tb);
  const pie = el('div'); pie.style.cssText = 'margin-top:12px;display:flex;gap:8px;justify-content:flex-end';
  pie.append(boton('Validar las 4 820 filas', 'btn--primary', () => { S.cargaPaso = 3; irA('base'); }, 'check'));
  t.cuerpo.append(pie);
  return t;
}

function pasoValidacion() {
  const t = tarjeta('Validación', 'Antes de cargar, no después', {id:'cardValida'});
  const barra = el('div', 'barra-apilada');
  const colores = {ok:'#16A34A', warn:'#D97706', mal:'#DC2626'};
  ARCHIVO.validacion.forEach(v => { const i = el('i'); i.style.width = (v.n / ARCHIVO.filas * 100) + '%';
    i.style.background = colores[v.k]; i.title = v.t + ': ' + v.n; barra.append(i); });
  t.cuerpo.append(barra);

  const l = el('div', 'valid'); l.style.marginTop = '12px';
  for (const v of ARCHIVO.validacion) {
    const f = el('div', 'vfila vfila--' + v.k);
    const i = el('div', 'vfila-ico'); i.append(svg(v.k === 'ok' ? 'check' : v.k === 'warn' ? 'alerta' : 'equis'));
    const w = el('div'); w.append(el('span', null, v.t)); w.append(el('em', null, v.d));
    const der = el('div'); der.style.cssText = 'display:flex;gap:7px;align-items:center';
    if (v.k !== 'ok') der.append(boton('Ver', 'btn--mini', () => aviso('Se descargó el detalle de ' + v.n + ' filas')));
    f.append(i, el('b', null, miles(v.n)), w, der);
    l.append(f);
  }
  t.cuerpo.append(l);

  const n = el('div'); n.style.cssText = 'margin-top:12px;padding:10px 12px;border-radius:6px;background:var(--primary-soft);font-size:11.5px';
  n.innerHTML = '<b>' + miles(ARCHIVO.normalizados) + ' teléfonos normalizados.</b> ' +
    'Los números locales se guardan con código de país — <code>939178487</code> pasa a ' +
    '<code>51939178487</code> — para que WhatsApp pueda abrir la conversación.';
  t.cuerpo.append(n);

  const dup = el('div'); dup.style.cssText = 'margin-top:12px;display:flex;gap:9px;align-items:center;flex-wrap:wrap';
  dup.append(el('span', null, 'Con los repetidos:'));
  ['Omitirlos', 'Actualizar el que ya está', 'Cargar igual'].forEach((x, i) => {
    const ch = el('button', 'chip' + (i === 0 ? ' is-on' : '')); ch.type = 'button'; ch.textContent = x;
    ch.onclick = () => { dup.querySelectorAll('.chip').forEach(y => y.classList.remove('is-on')); ch.classList.add('is-on'); };
    dup.append(ch);
  });
  t.cuerpo.append(dup);

  const pie = el('div'); pie.style.cssText = 'margin-top:13px;display:flex;gap:8px;justify-content:flex-end';
  pie.append(boton('Elegir destino', 'btn--primary', () => { S.cargaPaso = 4; irA('base'); }, 'flecha'));
  t.cuerpo.append(pie);
  return t;
}

function pasoDestino() {
  const t = tarjeta('Destino de la carga', null, {id:'cardDestino'});
  const g = el('div'); g.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:11px';
  const campo = (rot, opciones) => {
    const w = el('div');
    w.append(el('div', 'kpi-rot', rot));
    const s = el('select'); s.style.cssText = 'width:100%;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 8px;margin-top:4px;background:#fff';
    opciones.forEach(o => s.append(el('option', null, o)));
    w.append(s); return w;
  };
  g.append(campo('Campaña', ['carsa0001 — Crédito personal', 'renovaciones', 'cobranza']));
  g.append(campo('Lista', ['Crear lista nueva: carsa_noviembre', 'carsa_chiclayo', 'renov_norte']));
  g.append(campo('Prioridad', ['Normal', 'Alta', 'Baja']));
  g.append(campo('Empezar a llamar', ['Ahora mismo', 'Mañana 8:00', 'No llamar: solo cargar']));
  t.cuerpo.append(g);

  const pie = el('div'); pie.style.cssText = 'margin-top:14px;display:flex;gap:8px;justify-content:flex-end;align-items:center';
  const entran = (ARCHIVO.validacion[0] || {n:0}).n;
  pie.append(el('span', 'kpi-rot', 'Van a entrar ' + miles(entran) + ' leads'));
  const b = boton('Cargar a la campaña', 'btn--primary', () => {
    aviso(miles(entran) + ' leads cargados en carsa_noviembre'); S.cargaPaso = 1; irA('base');
  }, 'check');
  b.id = 'btnCargar';
  pie.append(b);
  t.cuerpo.append(pie);
  return t;
}

function tarjHistorialCargas() {
  const t = tarjeta('Cargas anteriores', null, {plano:true, id:'cardCargas'});
  const tb = el('table', 'tabla');
  const h = el('tr'); ['Fecha','Archivo','Lista','Filas','Entraron','Quién',''].forEach((x, i) =>
    h.append(el('th', i === 3 || i === 4 ? 'num' : '', x)));
  const th = el('thead'); th.append(h); tb.append(th);
  const body = el('tbody');
  for (const c of CARGAS) {
    const tr = el('tr');
    tr.append(el('td', null, c.f), el('td', null, c.arch), el('td', null, c.lista),
              el('td', 'num', miles(c.filas)), el('td', 'num', miles(c.ok)), el('td', null, c.quien));
    const td = el('td'); const acc = el('div', 'acc');
    acc.append(boton('Revertir', 'btn--mini', () => confirmar(
      'Revertir la carga',
      'Se van a quitar ' + miles(c.ok) + ' leads de la lista ' + c.lista + '. Las llamadas ya hechas no se borran.',
      () => aviso('Carga revertida: ' + c.arch))));
    td.append(acc); tr.append(td);
    body.append(tr);
  }
  tb.append(body); t.cuerpo.append(tb);
  return t;
}

/* ============================================= VISTA: segmentación === */
function vSegmentos(c) {
  c.append(titulo('Segmentación y reciclaje',
    'Volver a llamar solo a quien conviene, sin duplicar la lista.'));

  const g = el('div', 'segu');
  const izq = el('div', 'rejilla'), der = el('div', 'rejilla');

  /* -- constructor -- */
  const t = tarjeta('Condiciones', 'Lista: carsa_chiclayo', {id:'cardCond', der:[
    boton('Guardar segmento', 'btn--mini', () => aviso('Segmento guardado'))
  ]});
  const zona = el('div'); zona.id = 'condiciones';
  const repintar = () => {
    zona.replaceChildren();
    S.seg.forEach((cond, i) => {
      if (i) zona.append(el('div', 'cond-y', 'y'));
      const f = el('div', 'cond');
      const sc = el('select');
      CAMPOS_SEG.forEach(x => { const o = el('option', null, x.t); o.value = x.k;
        if (x.k === cond.campo) o.selected = true; sc.append(o); });
      sc.onchange = () => { cond.campo = sc.value; recontar(); };
      const so = el('select');
      ['es', 'no es', 'entre', 'mayor a', 'menor a', 'hace más de'].forEach(x => {
        const o = el('option', null, x); if (x === cond.op) o.selected = true; so.append(o); });
      so.onchange = () => { cond.op = so.value; recontar(); };
      const iv = el('input'); iv.value = cond.val;
      iv.oninput = () => { cond.val = iv.value; recontar(); };
      const x = el('button', 'cond-x', '✕'); x.type = 'button';
      x.onclick = () => { S.seg.splice(i, 1); repintar(); recontar(); };
      f.append(sc, so, iv, x);
      zona.append(f);
    });
    const mas = boton('Agregar condición', 'btn--mini', () => {
      S.seg.push({campo:'ciudad', op:'es', val:'Chiclayo'}); repintar(); recontar();
    }, 'mas');
    mas.style.marginTop = '9px';
    zona.append(mas);
  };
  repintar();
  t.cuerpo.append(zona);
  izq.append(t);

  /* -- reglas de reintento -- */
  const r = tarjeta('Qué hace el sistema con cada resultado',
    'Reglas de reintento por tipificación', {plano:true, id:'cardReglas'});
  const tb = el('table', 'tabla');
  const h = el('tr'); ['Tipificación','¿Contacto?','¿Venta?','¿Reintenta?','Cuándo','En la lista'].forEach((x, i) =>
    h.append(el('th', i === 5 ? 'num' : '', x)));
  const th = el('thead'); th.append(h); tb.append(th);
  const body = el('tbody');
  for (const d of TIPIFICACIONES) {
    const tr = el('tr');
    tr.append(el('td', null, d.t));
    const si = v => { const td = el('td'); td.append(el('span', 'pill pill--sin ' + (v ? 'pill--call' : 'pill--off'), v ? 'Sí' : 'No')); return td; };
    tr.append(si(d.cont), si(d.venta), si(d.reint));
    tr.append(el('td', null, d.reint ? 'a las ' + d.horas + ' h' : '—'));
    tr.append(el('td', 'num', miles(d.n)));
    body.append(tr);
  }
  tb.append(body); r.cuerpo.append(tb);
  izq.append(r);

  /* -- mapa de calor -- */
  const mc = tarjeta('A qué hora contesta este segmento', 'Contactabilidad por día y hora', {id:'cardCalor'});
  const wrap = el('div'); wrap.style.overflow = 'auto';
  const grid = el('div', 'calor');
  grid.append(el('div'));
  CALOR_H.forEach(h2 => grid.append(el('div', 'calor-h', h2)));
  CALOR_D.forEach((d, i) => {
    grid.append(el('div', 'calor-d', d));
    (CALOR[i] || CALOR_H.map(() => 0)).forEach((v, j) => {
      const cel = el('div', 'calor-c');
      cel.style.opacity = (0.14 + v * 0.86).toFixed(2);
      cel.title = d + ' ' + CALOR_H[j] + ':00 · ' + Math.round(v * 100) + '% contesta';
      grid.append(cel);
    });
  });
  wrap.append(grid);
  mc.cuerpo.append(wrap);
  const nota = el('div'); nota.style.cssText = 'margin-top:10px;font-size:11.5px;color:var(--text-muted)';
  nota.innerHTML = 'El mejor momento es el <b>miércoles entre 10 y 11</b>: contesta el 94 %. ' +
    'El peor, el <b>sábado al mediodía</b>: 21 %.';
  mc.cuerpo.append(nota);
  izq.append(mc);

  /* -- columna derecha -- */
  const cont = el('div', 'contador'); cont.id = 'contador';
  cont.append(el('b', null, '1 284'), el('span', null, 'leads coinciden'));
  der.append(cont);

  const acc = tarjeta('Qué hacer con ellos', null, {id:'cardAcciones'});
  const col = el('div'); col.style.cssText = 'display:grid;gap:7px';
  [['Volver a llamarlos en esta misma lista', 'primary', 'Reactivados. Entran a la cola de carsa0001.'],
   ['Crear una lista nueva', '', 'Lista «reciclado_20oct» creada con 1 284 leads.'],
   ['Asignar a un agente', '', 'Asignados a Ana Torres.'],
   ['Enviar WhatsApp masivo', '', 'Campaña de WhatsApp en cola para 1 284 contactos.'],
   ['Marcar como no llamar', '', '1 284 leads pasaron a lista negra.']
  ].forEach(([t2, cls, msg]) => {
    const b = boton(t2, cls === 'primary' ? 'btn--primary' : '', () => aviso(msg));
    b.style.width = '100%'; b.style.justifyContent = 'center';
    if (cls === 'primary') b.id = 'btnReactivar';
    col.append(b);
  });
  acc.cuerpo.append(col);
  const sim = el('div'); sim.style.cssText = 'margin-top:11px;padding:9px 11px;border-radius:6px;background:#FBFCFE;border:1px solid var(--border);font-size:11px;color:var(--text-muted)';
  sim.innerHTML = 'Con <b>4 agentes</b> y marcado 2,4, este segmento toma <b>3 h 20 min</b>.';
  acc.cuerpo.append(sim);
  der.append(acc);

  const gu = tarjeta('Segmentos guardados', null, {plano:true, id:'cardGuardados'});
  const lg = el('div');
  for (const s of SEG_GUARDADOS) {
    const f = el('button', 'chat-ag'); f.type = 'button';
    const w = el('div'); w.style.minWidth = '0';
    w.append(el('b', null, s.t));
    const e = el('div'); e.style.cssText = 'font-size:10px;color:var(--text-muted)'; e.textContent = s.desc;
    w.append(e);
    f.append(w);
    const n = el('span'); n.style.cssText = 'margin-left:auto;font-size:11px;font-weight:700';
    n.textContent = miles(s.n); f.append(n);
    f.onclick = () => aviso('Segmento cargado: ' + s.t);
    lg.append(f);
  }
  gu.cuerpo.append(lg);
  der.append(gu);

  const sal = tarjeta('Salud de las listas', null, {id:'cardSalud'});
  const sl = el('div', 'salud');
  for (const l of SALUD_LISTAS) {
    const f = el('div', 'salud-fila');
    const w = el('div');
    w.append(el('div', null, l.t));
    const bar = el('div', 'barra'); const bi = el('i');
    bi.style.width = l.agot + '%';
    bi.style.background = l.agot > 70 ? 'var(--danger)' : l.agot > 40 ? 'var(--warn)' : 'var(--ok)';
    bar.append(bi); bar.style.marginTop = '4px';
    w.append(bar);
    f.append(w, el('b', null, l.agot + '%'));
    sl.append(f);
  }
  sal.cuerpo.append(sl);
  const n2 = el('div'); n2.style.cssText = 'margin-top:9px;font-size:10.5px;color:var(--text-muted);line-height:1.5';
  n2.innerHTML = '«Agotada» es el porcentaje de leads con 3 intentos o más. ' +
    '<b>carsa_chiclayo</b> ya está al 78 %: conviene reciclar o traer base nueva.';
  sal.cuerpo.append(n2);
  der.append(sal);

  g.append(izq, der);
  c.append(g);
  recontar();
}

/* El contador se mueve mientras se arman las condiciones. */
function recontar() {
  const c = $('contador'); if (!c) return;
  c.classList.add('is-calc');
  const base = 1000;
  let n = base;
  for (const cond of S.seg) {
    if (cond.campo === 'tip')      n = Math.round(n * 0.58);
    if (cond.campo === 'intentos') n = Math.round(n * 0.71);
    if (cond.campo === 'ultima')   n = Math.round(n * 0.86);
    if (cond.campo === 'monto')    n = Math.round(n * 0.64);
    if (cond.campo === 'ciudad')   n = Math.round(n * 0.77);
    if (cond.campo === 'producto') n = Math.round(n * 0.81);
    if (cond.campo === 'origen')   n = Math.round(n * 0.69);
  }
  n = Math.round(n * 2.48);                     // la lista real tiene 2 480 históricos
  // El número cambia en el acto y la opacidad se recupera después: se ve
  // «calculando» sin que el dato llegue tarde.
  c.querySelector('b').textContent = miles(n);
  setTimeout(() => c.classList.remove('is-calc'), 220);
}

/* ================================================ VISTA: clientes ==== */
function vClientes(c) {
  c.append(titulo('Clientes', 'Buscar a cualquiera y ver todo lo que pasó con él.'));

  const bus = tarjeta(null, null, {id:'cardBuscar'});
  const fila = el('div'); fila.style.cssText = 'display:flex;gap:9px;align-items:center';
  const inp = el('input'); inp.id = 'qCliente'; inp.placeholder = 'Nombre, teléfono o documento…';
  inp.style.cssText = 'flex:1;height:32px;border:1px solid var(--border);border-radius:6px;padding:0 11px';
  inp.oninput = () => pintarResultados(inp.value);
  fila.append(svg('buscar'), inp);
  bus.cuerpo.append(fila);
  const res = el('div'); res.id = 'resultados'; res.style.marginTop = '9px';
  bus.cuerpo.append(res);
  c.append(bus);

  const ficha = el('div'); ficha.id = 'fichaCliente'; ficha.style.marginTop = '12px';
  c.append(ficha);
  pintarResultados('');
  pintarFichaCliente(S.cliente);
}

function pintarResultados(q) {
  const res = $('resultados'); if (!res) return;
  res.replaceChildren();
  const t = q.trim().toLowerCase();
  const lista = CLIENTES.filter(x => !t || x.nombre.toLowerCase().includes(t) ||
    x.tel.includes(t) || x.doc.includes(t));
  if (!lista.length) { res.append(el('div', 'vacio', 'Nadie coincide con «' + q + '».')); return; }
  for (const cl of lista) {
    const b = el('button', 'chat-ag'); b.type = 'button'; b.id = 'res-' + cl.id;
    if (cl.id === S.cliente) b.classList.add('is-on');
    b.append(avatar(cl.nombre, 'chat-ag-cara'));
    const w = el('div'); w.style.minWidth = '0';
    w.append(el('b', null, cl.nombre));
    const e = el('div'); e.style.cssText = 'font-size:10.5px;color:var(--text-muted)';
    e.textContent = cl.tel + ' · ' + cl.ciudad + ' · ' + cl.prod;
    w.append(e); b.append(w);
    const p = el('span', 'pill pill--sin pill--wrap', cl.tip);
    p.style.marginLeft = 'auto'; b.append(p);
    b.onclick = () => { S.cliente = cl.id; pintarResultados(q); pintarFichaCliente(cl.id); };
    res.append(b);
  }
}

function pintarFichaCliente(id) {
  const host = $('fichaCliente'); if (!host) return;
  host.replaceChildren();
  const cl = CLIENTES.find(x => x.id === id); if (!cl) return;

  const g = el('div', 'piso');
  const izq = el('div', 'rejilla'), der = el('div', 'rejilla');

  const t = tarjeta(null, null, {plano:true, id:'cardFicha'});
  const cab = el('div', 'cli-cab');
  cab.append(avatar(cl.nombre, 'cli-cab-cara'));
  const w = el('div'); w.append(el('b', null, cl.nombre));
  w.append(el('em', null, cl.prof + ' · ' + cl.empresa + ' · ' + cl.ciudad));
  cab.append(w);
  const ag = porId(cl.agente);
  const chip = el('span', 'pill pill--sin pill--wrap', ag ? 'Lo lleva ' + ag.nombre : 'Sin asignar');
  chip.style.marginLeft = 'auto'; cab.append(chip);
  t.cuerpo.append(cab);

  const dos = el('div', 'cli-ficha');
  const kv1 = el('dl', 'kv'), kv2 = el('dl', 'kv');
  const par = (dl, k, v) => { dl.append(el('dt', null, k), el('dd', null, v)); };
  par(kv1, 'Documento', cl.doc); par(kv1, 'Teléfono', cl.tel);
  par(kv1, 'Correo', cl.correo); par(kv1, 'Dirección', cl.dir);
  par(kv1, 'Origen', cl.origen);
  par(kv2, 'Producto', cl.prod); par(kv2, 'Monto', money(cl.monto));
  par(kv2, 'Campaña', cl.camp); par(kv2, 'Lista', cl.lista);
  par(kv2, 'Último resultado', cl.tip + ' · ' + cl.intentos + ' intentos');
  dos.append(kv1, kv2);
  t.cuerpo.append(dos);
  izq.append(t);

  const hh = tarjeta('Historial de contacto', 'Todos los canales, en orden', {plano:true, id:'cardHist'});
  const ul = el('ul', 'hist');
  for (const h of (HISTORIAS[cl.id] || [])) {
    const li = el('li');
    const i = el('div', 'hist-ico hist-ico--' + h.v);
    i.append(svg(h.v === 'llamada' ? 'tel' : h.v === 'whatsapp' ? 'wa' : h.v === 'email' ? 'mail' : h.v === 'sms' ? 'sms' : 'nota'));
    const w2 = el('div');
    w2.append(el('b', null, h.t));
    w2.append(el('p', null, h.d + ' — ' + h.q));
    li.append(i, w2, el('time', null, h.h));
    ul.append(li);
  }
  if (!ul.childNodes.length) ul.append(el('div', 'vacio', 'Sin interacciones registradas.'));
  hh.cuerpo.append(ul);
  izq.append(hh);

  der.append(tarjWhatsApp(cl));
  g.append(izq, der);
  host.append(g);
}

/* ---- WhatsApp del cliente, con el supervisor pudiendo responder ---- */
function tarjWhatsApp(cl) {
  const ag = porId(cl.agente);
  const t = tarjeta('WhatsApp', cl.tel, {plano:true, id:'cardWA', der:[
    el('span', 'pill pill--sin pill--call', 'En línea')
  ]});
  const av = el('div', 'suplantar');
  av.append(svg('alerta'));
  av.append(el('span', null, 'Si responde acá, el mensaje sale a nombre de ' +
    (ag ? ag.nombre : 'el agente') + ' y queda registrado como intervención suya.'));
  t.cuerpo.append(av);

  const hilo = el('div', 'hilo'); hilo.id = 'hiloWA';
  const msgs = WA[cl.id] || [];
  if (!msgs.length) hilo.append(el('div', 'vacio', 'Todavía no hay conversación.'));
  for (const m of msgs) {
    const b = el('div', 'msg msg--' + (m.de === 'ag' ? 'ag' : m.de === 'sup' ? 'sup' : 'cl'));
    b.append(document.createTextNode(m.t));
    b.append(el('em', null, m.h));
    hilo.append(b);
  }
  t.cuerpo.append(hilo);

  const red = el('div', 'redactar');
  const inp = el('input'); inp.id = 'waTexto'; inp.placeholder = 'Responder como ' + (ag ? ag.nombre.split(' ')[0] : 'el agente') + '…';
  const env = el('button', 'mini'); env.type = 'button'; env.id = 'waEnviar';
  env.style.cssText = 'background:var(--wa);border-color:var(--wa);color:#fff;width:31px;height:31px';
  env.append(svg('enviar'));
  const mandar = () => {
    const txt = inp.value.trim(); if (!txt) return;
    (WA[cl.id] = WA[cl.id] || []).push({de:'sup', t:txt, h:reloj(HOY.ahora)});
    inp.value = '';
    pintarFichaCliente(cl.id);
    aviso('Mensaje enviado por ' + (ag ? ag.nombre : 'el agente'));
    const h = $('hiloWA'); if (h) h.scrollTop = h.scrollHeight;
  };
  env.onclick = mandar;
  inp.onkeydown = e => { if (e.key === 'Enter') mandar(); };
  red.append(inp, env);
  t.cuerpo.append(red);
  return t;
}

/* ============================================ VISTA: conversaciones == */
function vConversa(c) {
  c.append(titulo('Conversaciones', 'Todo el WhatsApp del piso en una bandeja.'));
  const t = tarjeta('Bandeja del equipo', '2 sin responder', {plano:true, id:'cardBandeja'});
  const tb = el('table', 'tabla');
  const h = el('tr'); ['Cliente','Lo lleva','Último mensaje','Hora',''].forEach(x => h.append(el('th', null, x)));
  const th = el('thead'); th.append(h); tb.append(th);
  const body = el('tbody');
  for (const b of BANDEJA) {
    const cl = CLIENTES.find(x => x.id === b.cli); if (!cl) continue;
    const ag = porId(b.ag);
    const tr = el('tr'); tr.id = 'band-' + b.cli;
    const td1 = el('td'); const q = el('div', 'quien');
    const tx = el('div', 'quien-txt'); tx.append(el('b', null, cl.nombre), el('em', null, cl.tel));
    q.append(avatar(cl.nombre), tx); td1.append(q);
    tr.append(td1);
    tr.append(el('td', null, ag ? ag.nombre : '—'));
    const td3 = el('td'); td3.append(document.createTextNode(b.ult));
    if (b.alerta) { const a = el('div'); a.style.cssText = 'font-size:10px;color:var(--danger);font-weight:700;margin-top:2px';
      a.textContent = b.alerta; td3.append(a); }
    tr.append(td3);
    tr.append(el('td', null, b.h));
    const td5 = el('td'); const acc = el('div', 'acc');
    acc.append(boton('Abrir', 'btn--mini', () => { S.cliente = b.cli; irA('clientes'); }));
    td5.append(acc); tr.append(td5);
    body.append(tr);
  }
  tb.append(body); t.cuerpo.append(tb);
  c.append(t);
}

/* ============================================= VISTA: comunicación === */
function vComunica(c) {
  c.append(titulo('Comunicación con el piso', 'Un anuncio para todos, o una palabra con uno solo.'));

  const g = el('div', 'bcast');
  const izq = el('div', 'rejilla'), der = el('div', 'rejilla');

  /* -- broadcast -- */
  const t = tarjeta('Mensaje a los agentes', null, {plano:true, id:'cardBcast', sello:'PRÓXIMAMENTE'});
  const comp = el('div', 'compon');
  const ta = el('textarea'); ta.id = 'bcastTexto';
  ta.value = 'Promoción del día: tasa preferente 11,9 % para créditos sobre S/ 15 000. Vence hoy a las 6 p.m.';
  comp.append(ta);

  const adj = el('div', 'adjunto');
  const vp = el('div', 'adjunto-vp'); vp.textContent = 'TASA 11,9%';
  adj.append(vp);
  const aw = el('div'); aw.append(el('b', null, 'promo_tasa_noviembre.png'));
  const ae = el('div'); ae.style.cssText = 'font-size:10.5px;color:var(--text-muted)'; ae.textContent = '840 × 480 · 68 KB';
  aw.append(ae); adj.append(aw);
  const quitar = boton('Quitar', 'btn--mini', () => aviso('Imagen quitada'));
  quitar.style.marginLeft = 'auto'; adj.append(quitar);
  comp.append(adj);

  const seg = el('div'); seg.style.cssText = 'display:flex;gap:9px;align-items:center;flex-wrap:wrap';
  seg.append(el('span', 'kpi-rot', 'Para:'));
  ['Todo el piso', 'Solo carsa0001', 'Solo los que están en pausa'].forEach((x, i) => {
    const ch = el('button', 'chip' + (i === 0 ? ' is-on' : '')); ch.type = 'button'; ch.textContent = x;
    ch.onclick = () => { seg.querySelectorAll('.chip').forEach(y => y.classList.remove('is-on')); ch.classList.add('is-on'); };
    seg.append(ch);
  });
  comp.append(seg);

  const pie = el('div'); pie.style.cssText = 'display:flex;gap:8px;align-items:center';
  pie.append(boton('Programar', '', () => aviso('Programado para hoy 14:00'), 'reloj'));
  const env = boton('Enviar ahora', 'btn--primary', () => aviso('Mensaje enviado a 8 agentes'), 'enviar');
  env.id = 'btnBcast'; env.style.marginLeft = 'auto';
  pie.append(env);
  comp.append(pie);
  t.cuerpo.append(comp);
  izq.append(t);

  /* -- acuse de recibo -- */
  const ac = tarjeta('Quién lo leyó', 'Promoción enviada a las 09:05', {id:'cardAcuse'});
  const b0 = BROADCASTS[0] || {vistos:[], total:0};
  const grid = el('div', 'acuse');
  for (const a of AGENTES) {
    const visto = b0.vistos.includes(a.id);
    const f = el('div', 'acuse-ag ' + (visto ? 'visto' : 'no'));
    f.append(el('i', 'pt'), avatar(a.nombre, 'ag-chip-cara'), el('span', null, a.nombre.split(' ')[0]));
    grid.append(f);
  }
  ac.cuerpo.append(grid);
  const n = el('div'); n.style.cssText = 'margin-top:10px;font-size:11.5px';
  n.innerHTML = '<b>' + b0.vistos.length + ' de ' + AGENTES.length + ' lo leyeron.</b> ' +
    'Faltan: ' + AGENTES.filter(a => !b0.vistos.includes(a.id)).map(a => a.nombre.split(' ')[0]).join(', ') + '.';
  ac.cuerpo.append(n);
  ac.cuerpo.append(boton('Reenviar a los que faltan', 'btn--mini', () => aviso('Reenviado a 3 agentes')));
  izq.append(ac);

  /* -- manos levantadas -- */
  if (MANOS.length) {
    const m = tarjeta('Piden ayuda', null, {id:'cardManos'});
    for (const x of MANOS) {
      const ag = porId(x.ag);
      const f = el('div', 'mano');
      f.append(svg('mano'));
      const w = el('div'); w.style.flex = '1';
      w.append(el('b', null, (ag ? ag.nombre : x.ag) + ' levantó la mano'));
      w.append(el('p', null, x.motivo + ' · cliente ' + x.cli + ' · ' + x.h));
      f.append(w);
      f.append(boton('Atender', 'btn--primary btn--mini', () => { abrirChatFlot(x.ag); aviso('Chat abierto con ' + (ag ? ag.nombre : x.ag)); }));
      m.cuerpo.append(f);
    }
    izq.append(m);
  }

  /* -- chat interno: la conversacion vive ahora en la burbuja flotante -- */
  const ch = tarjeta('Chat interno', 'Disponible desde cualquier vista', {id:'cardChat'});
  const cajaCh = el('div');
  cajaCh.style.cssText = 'display:flex;align-items:center;gap:14px;flex-wrap:wrap';
  const txtCh = el('p');
  txtCh.style.cssText = 'margin:0;font-size:12px;color:var(--text-muted);flex:1;min-width:210px';
  txtCh.textContent = 'Se abre en una ventana propia, sin salir de la pantalla que esté mirando. '
    + 'También está la burbuja de abajo a la derecha, desde cualquier vista.';
  cajaCh.append(txtCh, boton('Abrir el chat', 'btn--primary', () => abrirChatFlot(), 'chat'));
  ch.cuerpo.append(cajaCh);
  der.append(ch);

  g.append(izq, der);
  c.append(g);
  const h = $('chatHilo'); if (h) h.scrollTop = h.scrollHeight;
}

/* ================================================ VISTA: calidad ===== */
function vCalidad(c) {
  c.append(titulo('Calidad', 'Escuchar lo que ya pasó y convertirlo en entrenamiento.'));

  const g = el('div', 'piso');
  const izq = el('div', 'rejilla'), der = el('div', 'rejilla');

  const t = tarjeta('Grabaciones por revisar', 'Últimas 50 llamadas', {plano:true, id:'cardGrab'});
  const tb = el('table', 'tabla');
  const h = el('tr'); ['Asesor','Cliente','Duración','Resultado','Puntaje',''].forEach((x, i) =>
    h.append(el('th', i === 4 ? 'num' : '', x)));
  const th = el('thead'); th.append(h); tb.append(th);
  const body = el('tbody');
  for (const r of GRABACIONES) {
    const ag = porId(r.ag);
    const tr = el('tr'); tr.id = 'grab-' + r.id;
    const td1 = el('td'); const q = el('div', 'quien');
    const tx = el('div', 'quien-txt'); tx.append(el('b', null, ag ? ag.nombre : r.ag), el('em', null, r.h));
    q.append(avatar(ag ? ag.nombre : r.ag), tx); td1.append(q);
    tr.append(td1, el('td', null, r.cli), el('td', null, r.dur), el('td', null, r.tip));
    const td5 = el('td', 'num');
    const p = el('span', 'pill pill--sin', String(r.score));
    p.style.cssText = r.score >= 85 ? 'background:#DCFCE7;color:#15803D'
                    : r.score >= 70 ? 'background:#FEF3C7;color:#B45309'
                                    : 'background:#FEE2E2;color:#B91C1C';
    td5.append(p);
    if (r.joya) { const e = el('span'); e.style.marginLeft = '5px'; e.title = 'Ejemplo destacado'; e.textContent = '★'; td5.append(e); }
    tr.append(td5);
    const td6 = el('td'); const acc = el('div', 'acc');
    acc.append(boton('Escuchar', 'btn--mini', () => hojaGrabacion(r)));
    td6.append(acc); tr.append(td6);
    body.append(tr);
  }
  tb.append(body); t.cuerpo.append(tb);
  izq.append(t);

  const sc = tarjeta('Puntaje del equipo', 'Promedio de las últimas 50', {id:'cardScore', sello:'PRÓXIMAMENTE'});
  const s = el('div', 'score');
  const a = anillo(92, 118, '#16A34A');
  const av = el('div', 'anillo-val'); av.append(el('b', null, '92%'), el('span', null, 'promedio'));
  a.append(av);
  const cr = el('div', 'criterios');
  for (const x of CRITERIOS) {
    const f = el('div', 'crit');
    f.append(el('span', null, x.t));
    const bar = el('div', 'barra'); const bi = el('i'); bi.style.width = x.v + '%';
    bi.style.background = x.v >= 90 ? 'var(--ok)' : 'var(--warn)';
    bar.append(bi); f.append(bar);
    f.append(el('b', null, x.v + '%'));
    cr.append(f);
  }
  s.append(a, cr);
  sc.cuerpo.append(s);
  der.append(sc);

  const mo = tarjeta('Momentos guardados', 'Fragmentos que se volvieron material de entrenamiento', {id:'cardMomentos'});
  [['Rosa Jiménez · 6:10–7:40', 'Cierre impecable tras tres objeciones', 'bueno'],
   ['Héctor Chávez · 2:05–2:50', 'Se adelantó al precio sin presentar beneficio', 'malo']
  ].forEach(([t2, d, k]) => {
    const f = el('div'); f.style.cssText = 'display:flex;gap:9px;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--rule)';
    const i = el('div', 'hist-ico'); i.style.background = k === 'bueno' ? 'var(--ok)' : 'var(--warn)';
    i.append(svg(k === 'bueno' ? 'estrella' : 'alerta'));
    const w = el('div'); w.append(el('b', null, t2));
    const e = el('div'); e.style.cssText = 'font-size:10.5px;color:var(--text-muted)'; e.textContent = d;
    w.append(e);
    f.append(i, w);
    mo.cuerpo.append(f);
  });
  mo.cuerpo.append(boton('Enviar a la base de conocimiento', 'btn--mini',
    () => aviso('Los dos fragmentos se publicaron en la wiki del agente')));
  der.append(mo);

  g.append(izq, der);
  c.append(g);
}

function hojaGrabacion(r) {
  const ag = porId(r.ag);
  const cuerpo = el('div');
  const onda = el('div'); onda.style.cssText = 'display:flex;align-items:center;gap:10px;padding:11px;border:1px solid var(--border);border-radius:7px;background:#FBFCFE';
  const play = el('button', 'mini'); play.type = 'button';
  play.style.cssText = 'width:34px;height:34px;background:var(--primary);border-color:var(--primary);color:#fff';
  play.append(svg('play'));
  const w = el('div', 'onda');
  const rr = sembrar(hash(r.id));
  for (let i = 0; i < 64; i++) { const b = el('i'); b.style.height = (12 + rr() * 78) + '%';
    if (i < 22) b.classList.add('is-on'); w.append(b); }
  onda.append(play, w, el('span', null, '1:28 / ' + r.dur));
  play.onclick = () => aviso('Reproduciendo (maqueta)');
  cuerpo.append(onda);

  const sc = el('div'); sc.style.cssText = 'margin-top:13px;display:grid;grid-template-columns:118px minmax(0,1fr);gap:16px;align-items:center';
  const a = anillo(r.score, 118, r.score >= 85 ? '#16A34A' : r.score >= 70 ? '#D97706' : '#DC2626');
  const av = el('div', 'anillo-val'); av.append(el('b', null, r.score + '%'), el('span', null, 'de esta llamada'));
  a.append(av);
  const cr = el('div', 'criterios');
  const rr2 = sembrar(hash(r.id) + 7);
  for (const x of CRITERIOS) {
    const v = Math.max(40, Math.min(100, Math.round(r.score + (rr2() - .5) * 26)));
    const f = el('div', 'crit');
    f.append(el('span', null, x.t));
    const bar = el('div', 'barra'); const bi = el('i'); bi.style.width = v + '%';
    bi.style.background = v >= 85 ? 'var(--ok)' : v >= 70 ? 'var(--warn)' : 'var(--danger)';
    bar.append(bi); f.append(bar, el('b', null, v + '%'));
    cr.append(f);
  }
  sc.append(a, cr);
  cuerpo.append(sc);

  const tr = el('div'); tr.style.cssText = 'margin-top:13px;padding:11px;border:1px solid var(--border);border-radius:7px;max-height:150px;overflow:auto;font-size:11.5px;line-height:1.6';
  tr.innerHTML = '<b>Transcripción</b><br>' +
    '<span style="color:var(--text-muted)">0:12</span> <b>' + (ag ? ag.nombre.split(' ')[0] : 'Asesor') + ':</b> Buenas tardes, le llamo de Carsa por su solicitud de crédito.<br>' +
    '<span style="color:var(--text-muted)">0:19</span> <b>Cliente:</b> Ah sí, ¿ya salió?<br>' +
    '<span style="color:var(--text-muted)">0:22</span> <b>' + (ag ? ag.nombre.split(' ')[0] : 'Asesor') + ':</b> Salió aprobado condicional. Me falta un documento suyo.<br>' +
    '<span style="color:var(--text-muted)">0:31</span> <b>Cliente:</b> ¿Y en cuánto me queda la cuota?';
  cuerpo.append(tr);

  hoja({
    tit:'Grabación ' + r.id, sub:(ag ? ag.nombre : '') + ' con ' + r.cli + ' · ' + r.h,
    sello:'PRÓXIMAMENTE', cuerpo,
    pie:[boton('Marcar como ejemplo', '', () => { cerrarHoja(); aviso('Guardado en momentos destacados'); }, 'estrella'),
         boton('Agendar coaching', 'btn--primary', () => { cerrarHoja(); aviso('Coaching agendado con ' + (ag ? ag.nombre : '')); })]
  });
}

/* ================================================= VISTA: equipo ===== */
function vEquipo(c) {
  c.append(titulo('Equipo', 'Puntualidad, pausas y seguimiento de cada persona.'));

  const g = el('div', 'piso');
  const izq = el('div', 'rejilla'), der = el('div', 'rejilla');

  /* -- asistencia -- */
  const t = tarjeta('Asistencia', 'Últimos 7 turnos', {plano:true, id:'cardAsist'});
  const tb = el('table', 'tabla');
  const h = el('tr'); h.append(el('th', null, 'Asesor'));
  SEMANA.forEach(d => h.append(el('th', null, d)));
  h.append(el('th', null, 'Entró hoy'));
  const th = el('thead'); th.append(h); tb.append(th);
  const body = el('tbody');
  for (const a of AGENTES) {
    const tr = el('tr');
    const td1 = el('td'); const q = el('div', 'quien');
    const tx = el('div', 'quien-txt'); tx.append(el('b', null, a.nombre));
    q.append(avatar(a.nombre), tx); td1.append(q); tr.append(td1);
    (ASISTENCIA[a.id] || []).forEach(v => {
      const td = el('td');
      const s = el('span', 'asist-cel asist--' + v, v === 'ok' ? '✓' : v === 'tarde' ? 'T' : v === 'falta' ? 'F' : '—');
      s.title = {ok:'A tiempo', tarde:'Llegó tarde', falta:'Faltó', libre:'Día libre'}[v];
      td.append(s); tr.append(td);
    });
    const td9 = el('td');
    const p = el('span'); p.textContent = a.login;
    p.style.color = a.punt === 'tarde' ? 'var(--warn)' : a.punt === 'falta' ? 'var(--danger)' : 'var(--text)';
    if (a.punt !== 'ok') p.style.fontWeight = '700';
    td9.append(p); tr.append(td9);
    tr.onclick = () => fichaAgente(a.id);
    body.append(tr);
  }
  tb.append(body); t.cuerpo.append(tb);
  izq.append(t);

  /* -- pausas -- */
  const pa = tarjeta('Pausas de hoy', 'Contra el tope permitido por motivo', {plano:true, id:'cardPausas'});
  const tb2 = el('table', 'tabla');
  const h2 = el('tr'); ['Asesor','Reparto','Total','Sobre el tope'].forEach((x, i) =>
    h2.append(el('th', i >= 2 ? 'num' : '', x)));
  const th2 = el('thead'); th2.append(h2); tb2.append(th2);
  const b2 = el('tbody');
  for (const a of AGENTES) {
    const mins = PAUSAS_HOY[a.id] || [0, 0, 0];
    const total = mins.reduce((x, y) => x + y, 0);
    const tope = PAUSAS_TIPO.reduce((s2, p) => s2 + p.tope, 0);
    const exceso = mins.reduce((s2, m, i) => s2 + Math.max(0, m - (PAUSAS_TIPO[i] || {tope:0}).tope), 0);
    const tr = el('tr');
    const td1 = el('td'); const q = el('div', 'quien');
    const tx = el('div', 'quien-txt'); tx.append(el('b', null, a.nombre));
    q.append(avatar(a.nombre), tx); td1.append(q); tr.append(td1);
    const td2 = el('td'); const bar = el('div', 'pausa-barra');
    mins.forEach((m, i) => { if (!m) return; const x = el('i');
      x.style.width = (m / Math.max(tope, total) * 100) + '%';
      x.style.background = (PAUSAS_TIPO[i] || {color:'#CBD5E1'}).color;
      x.title = (PAUSAS_TIPO[i] || {t:''}).t + ': ' + m + ' min (tope ' + (PAUSAS_TIPO[i] || {tope:0}).tope + ')';
      bar.append(x); });
    td2.append(bar); tr.append(td2);
    tr.append(el('td', 'num', total + ' min'));
    const td4 = el('td', 'num');
    if (exceso > 0) { const s2 = el('b', null, '+' + exceso + ' min'); s2.style.color = 'var(--danger)'; td4.append(s2); }
    else td4.append(document.createTextNode('—'));
    tr.append(td4);
    b2.append(tr);
  }
  tb2.append(b2); pa.cuerpo.append(tb2);
  const ley = el('div', 'tl-leyenda'); ley.style.padding = '9px 12px';
  PAUSAS_TIPO.forEach(p => { const s2 = el('span'); const i = el('i'); i.style.background = p.color;
    s2.append(i, document.createTextNode(p.t + ' (tope ' + p.tope + ' min)')); ley.append(s2); });
  pa.cuerpo.append(ley);
  izq.append(pa);

  /* -- ranking -- */
  const rk = tarjeta('Ranking del día', null, {plano:true, id:'cardRank'});
  const ord = AGENTES.slice().sort((a, b) => b.monto - a.monto);
  ord.forEach((a, i) => {
    const f = el('div', 'live');
    const p = el('span'); p.style.cssText = 'width:20px;text-align:center;font-weight:700;color:var(--text-muted)';
    p.textContent = i + 1;
    f.prepend(p);
    f.append(avatar(a.nombre, 'live-cara'));
    const tx = el('div', 'live-txt');
    tx.append(el('b', null, a.nombre), el('em', null, a.vent + ' ventas · ' + a.cont + ' contactos'));
    f.append(tx);
    const d = el('div', 'live-der'); d.append(el('span', 'live-t', a.monto ? money(a.monto) : '—'));
    f.append(d);
    f.style.gridTemplateColumns = '20px 28px minmax(0,1fr) auto';
    rk.cuerpo.append(f);
  });
  der.append(rk);

  /* -- coaching -- */
  const co = tarjeta('Seguimiento de coaching', null, {id:'cardCoach'});
  const ag = porId('5003') || AGENTES[0] || {id:'', nombre:'—', estado:'off'};
  const cab = el('div'); cab.style.cssText = 'display:flex;align-items:center;gap:9px;margin-bottom:10px';
  cab.append(avatar(ag.nombre, 'chat-ag-cara'));
  const w = el('div'); w.append(el('b', null, ag.nombre));
  const e = el('div'); e.style.cssText = 'font-size:10.5px;color:var(--danger)';
  e.textContent = '3 intervenciones en 15 días · mismo motivo';
  w.append(e); cab.append(w);
  co.cuerpo.append(cab);
  for (const x of (COACHING['5003'] || [])) {
    const f = el('div'); f.style.cssText = 'display:grid;grid-template-columns:62px minmax(0,1fr);gap:9px;padding:7px 0;border-top:1px solid var(--rule);font-size:11.5px';
    const d = el('div'); d.style.color = 'var(--text-muted)'; d.textContent = x.f;
    const w2 = el('div'); w2.append(el('b', null, x.t));
    const p = el('div'); p.style.color = 'var(--text-muted)'; p.textContent = x.d;
    w2.append(p);
    f.append(d, w2);
    co.cuerpo.append(f);
  }
  const n = el('div'); n.style.cssText = 'margin-top:10px;padding:9px 11px;border-radius:6px;background:#FEF3C7;font-size:11px;color:#92400E';
  n.textContent = 'Tres veces por lo mismo deja de ser un susurro y pasa a ser un plan de entrenamiento.';
  co.cuerpo.append(n);
  der.append(co);

  g.append(izq, der);
  c.append(g);
}

/* =============================================== VISTA: reportes ===== */
function vReportes(c) {
  c.append(titulo('Reportes', 'Lo que el motor ya sabe calcular, sin inventar nada.'));

  const g = el('div', 'piso');
  const izq = el('div', 'rejilla'), der = el('div', 'rejilla');

  /* -- curva vs meta -- */
  const t = tarjeta('Producción contra la meta', HOY.fecha, {id:'cardCurva'});
  t.cuerpo.append(graficoCurva());
  izq.append(t);

  /* -- los 20 indicadores -- */
  const k = tarjeta('Indicadores de la campaña', 'carsa0001 · hoy', {id:'cardKpis', der:[
    boton('Exportar', 'btn--mini', () => aviso('Se descargó reporte_carsa0001.csv'), 'subir')
  ]});
  const gk = el('div'); gk.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(142px,1fr));gap:8px';
  for (const x of KPIS_MOTOR) {
    const f = el('div', 'mdato');
    f.append(el('span', null, x.t), el('b', null, x.v));
    gk.append(f);
  }
  k.cuerpo.append(gk);
  const n = el('div'); n.style.cssText = 'margin-top:11px;font-size:11px;color:var(--text-muted);line-height:1.55';
  n.innerHTML = 'La <b>ocupación</b> se marca como «observada», «parcial» o «sin datos» según cuántos ' +
    'intervalos de agente cubran el periodo: si falta información, el sistema lo dice en vez de inventar un número.';
  k.cuerpo.append(n);
  izq.append(k);

  /* -- embudo -- */
  const em = tarjeta('Embudo comercial', null, {id:'cardEmbudo'});
  const ew = el('div', 'embudo');
  const base = (EMBUDO[0] && EMBUDO[0].n) || 1;
  for (const e of EMBUDO) {
    const f = el('div', 'emb-f');
    const b = el('div', 'emb-b');
    b.style.width = Math.max(8, e.n / base * 100) + '%';
    b.style.background = e.c;
    b.textContent = e.t;
    f.append(b, el('b', null, miles(e.n)), el('em', null, (e.n / base * 100).toFixed(1).replace('.', ',') + '%'));
    ew.append(f);
  }
  em.cuerpo.append(ew);
  der.append(em);

  /* -- productos -- */
  const pr = tarjeta('Ventas por producto', 'Hoy', {id:'cardProd'});
  const pw = el('div', 'prod');
  const max = Math.max(...PRODUCTOS.map(p => p.s));
  for (const p of PRODUCTOS) {
    const f = el('div', 'prod-f');
    f.append(el('span', null, p.t));
    const bar = el('div', 'prod-b'); bar.style.width = (p.s / max * 100) + '%'; bar.style.background = p.c;
    const bw = el('div'); bw.style.cssText = 'display:flex;align-items:center;gap:7px';
    bw.append(bar); bw.append(el('span', null, p.n));
    f.append(bw, el('b', null, money(p.s)));
    pw.append(f);
  }
  pr.cuerpo.append(pw);
  der.append(pr);

  g.append(izq, der);
  c.append(g);
}

function graficoCurva() {
  const caja = el('div');
  const w = el('div', 'grafo');
  const W = 620, H = 198, pl = 48, pr = 12, pt = 12, pb = 24;
  const s = svgNodo('svg', {viewBox:`0 0 ${W} ${H}`, preserveAspectRatio:'none'});
  const maxY = META_DIA;
  const X = i => pl + (W - pl - pr) * (i / (CURVA.length - 1));
  const Y = v => pt + (H - pt - pb) * (1 - v / maxY);

  for (let i = 0; i <= 4; i++) {
    const y = pt + (H - pt - pb) * (i / 4);
    s.append(svgNodo('line', {x1:pl, y1:y, x2:W - pr, y2:y, stroke:'#EEF2F7', 'stroke-width':1}));
    const tx = svgNodo('text', {x:pl - 7, y:y + 3.5, 'text-anchor':'end',
      'font-size':9, fill:'#94A3B8'});
    tx.textContent = 'S/ ' + Math.round(maxY * (1 - i / 4) / 1000) + 'k';
    s.append(tx);
  }
  CURVA.forEach((p, i) => {
    const tx = svgNodo('text', {x:X(i), y:H - 7, 'text-anchor':'middle', 'font-size':9, fill:'#94A3B8'});
    tx.textContent = p[0] + 'h'; s.append(tx);
  });
  s.append(svgNodo('path', {d:CURVA.map((p, i) => (i ? 'L' : 'M') + X(i) + ' ' + Y(p[2])).join(' '),
    stroke:'#CBD5E1', 'stroke-width':2, fill:'none', 'stroke-dasharray':'5 4'}));
  const reales = CURVA.filter(p => p[1] !== null);
  s.append(svgNodo('path', {d:reales.map((p, i) => (i ? 'L' : 'M') + X(i) + ' ' + Y(p[1])).join(' '),
    stroke:'#2563EB', 'stroke-width':2.6, fill:'none', 'stroke-linejoin':'round'}));
  reales.forEach((p, i) => s.append(svgNodo('circle', {cx:X(i), cy:Y(p[1]), r:3.2, fill:'#2563EB'})));
  const ult = reales.length - 1;
  if (ult >= 0) s.append(svgNodo('circle', {cx:X(ult), cy:Y(reales[ult][1]), r:6, fill:'#2563EB',
    'fill-opacity':.22}));
  w.append(s);

  const pie = el('div'); pie.style.cssText = 'display:flex;gap:16px;align-items:center;margin-top:9px;font-size:11px;flex-wrap:wrap';
  const l1 = el('span'); l1.innerHTML = '<b style="color:#2563EB">●</b> Hoy: <b>' + money(REAL_AHORA) + '</b>';
  const l2 = el('span'); l2.style.color = 'var(--text-muted)';
  l2.innerHTML = '┈ Meta: <b>' + money(META_DIA) + '</b>';
  const l3 = el('span', 'pill pill--sin pill--call', Math.round(REAL_AHORA / 224000 * 100) + '% de lo esperado a esta hora');
  pie.append(l1, l2, l3);
  caja.append(w, pie);
  return caja;
}

/* ====================================================== capas ======== */
function titulo(t, sub) {
  const h = el('div', 'vista-tit');
  const w = el('div');
  w.append(el('h1', null, t));
  if (sub) w.append(el('p', null, sub));
  h.append(w);
  return h;
}

function hoja(o) {
  const capa = $('capa'); capa.replaceChildren(); capa.hidden = false;
  const h = el('div', 'hoja' + (o.ancha ? ' hoja--ancha' : o.angosta ? ' hoja--angosta' : ''));
  const head = el('div', 'hoja-head');
  head.append(el('h2', null, o.tit));
  if (o.sub) head.append(el('span', 'sub', o.sub));
  if (o.sello) head.append(el('span', 'sello', o.sello));
  const x = el('button', 'hoja-x', '✕'); x.type = 'button'; x.onclick = cerrarHoja;
  head.append(x);
  const cu = el('div', 'hoja-cuerpo'); cu.append(o.cuerpo);
  h.append(head, cu);
  if (o.pie) { const p = el('div', 'hoja-pie'); o.pie.forEach(b => p.append(b)); h.append(p); }
  capa.append(h);
  capa.onclick = e => { if (e.target === capa) cerrarHoja(); };
}
function cerrarHoja() { const c = $('capa'); c.hidden = true; c.replaceChildren(); }

function confirmar(tit, txt, fn) {
  const cu = el('div'); cu.style.fontSize = '12.5px'; cu.style.lineHeight = '1.6';
  cu.textContent = txt;
  hoja({tit, angosta:true, cuerpo:cu, pie:[
    boton('Cancelar', '', cerrarHoja),
    boton('Confirmar', 'btn--danger', () => { cerrarHoja(); fn(); })
  ]});
}

let avisoT = null;
function aviso(txt) {
  const t = $('toast'); t.replaceChildren();
  t.append(svg('check'), el('span', null, txt));
  t.hidden = false;
  clearTimeout(avisoT);
  avisoT = setTimeout(() => { t.hidden = true; }, 3200);
}

/* ---- ficha del agente ---- */
function fichaAgente(id) {
  const a = porId(id); if (!a) return;
  const cu = el('div');

  const cab = el('div'); cab.style.cssText = 'display:flex;gap:13px;align-items:center;margin-bottom:14px';
  const c0 = avatar(a.nombre, 'cli-cab-cara'); c0.style.width = c0.style.height = '54px';
  cab.append(c0);
  const w = el('div');
  const b0 = el('b'); b0.style.fontSize = '16px'; b0.textContent = a.nombre; w.append(b0);
  const e0 = el('div'); e0.style.cssText = 'font-size:11.5px;color:var(--text-muted)';
  e0.textContent = 'Anexo ' + a.id + ' · entró a las ' + a.login +
    ' · trabajando en ' + (a.activa ? nombreCamp(a.activa) : 'ninguna campaña');
  w.append(e0);
  if (a.camps.length) {
    const hab = el('div'); hab.style.cssText = 'font-size:10.5px;color:var(--text-muted);margin-top:2px';
    hab.textContent = 'Habilitado en: ' + a.camps.map(nombreCamp).join(' · ');
    w.append(hab);
  }
  cab.append(w);
  if (esMixto(a)) {
    const mx = el('span', 'pill pill--sin pill--mixto', 'MIXTO');
    mx.style.marginLeft = 'auto'; cab.append(mx);
  }
  const p = pill(a.estado); p.style.marginLeft = esMixto(a) ? '6px' : 'auto'; cab.append(p);
  cu.append(cab);

  const k = el('div', 'kpis');
  k.append(kpi('Llamadas', String(a.llam), null, null, 'tel', '#0891B2'));
  k.append(kpi('Contactados', String(a.cont), null, null, 'check', '#16A34A'));
  k.append(kpi('Ventas', String(a.vent), null, null, 'estrella', '#7C3AED'));
  k.append(kpi('Producción', a.monto ? money(a.monto) : '—', null, null, 'reportes', '#D97706'));
  k.append(kpi('Ocupación', a.ocup + '%', null, a.ocup >= 70 ? 'sube' : 'baja', 'reloj', '#2563EB'));
  cu.append(k);

  const tl = el('div'); tl.style.marginTop = '14px';
  tl.append(el('div', 'kpi-rot', 'Su mañana'));
  const pista = el('div', 'tl-pista'); pista.style.cssText = 'height:26px;margin-top:5px';
  const span = HOY.fin - HOY.ini;
  for (const b of (PISTAS[a.id] || [])) {
    const d = el('i', 'tl-b tl-b--' + b.t);
    d.style.left = ((b.a - HOY.ini) / span * 100) + '%';
    d.style.width = Math.max(0.35, b.d / span * 100) + '%';
    d.title = b.q + ' · ' + reloj(b.a) + '–' + reloj(b.a + b.d);
    pista.append(d);
  }
  tl.append(pista);
  cu.append(tl);

  const hist = COACHING[a.id] || [];
  const hc = el('div'); hc.style.marginTop = '14px';
  hc.append(el('div', 'kpi-rot', 'Intervenciones y coaching'));
  if (!hist.length) {
    const v = el('div'); v.style.cssText = 'font-size:11.5px;color:var(--text-muted);padding:7px 0';
    v.textContent = 'Sin intervenciones registradas.';
    hc.append(v);
  }
  for (const x of hist) {
    const f = el('div'); f.style.cssText = 'display:grid;grid-template-columns:70px 78px minmax(0,1fr);gap:9px;padding:6px 0;border-top:1px solid var(--rule);font-size:11.5px';
    const d = el('div'); d.style.color = 'var(--text-muted)'; d.textContent = x.f;
    f.append(d, el('b', null, x.t), el('span', null, x.d + ' — ' + x.q));
    hc.append(f);
  }
  cu.append(hc);

  const pie = [];
  if (a.estado === 'call') pie.push(boton('Entrar a la llamada', 'btn--primary', () => { cerrarHoja(); abrirEscucha(a.id); }, 'oreja'));
  if (a.estado === 'pausa') pie.push(boton('Sacar de pausa', 'btn--primary', () => { cerrarHoja(); accion('despausar:' + a.id); }));
  pie.push(boton('Abrir chat', '', () => { cerrarHoja(); abrirChatFlot(a.id); }, 'chat'));
  hoja({tit:a.nombre, sub:'Ficha del asesor', ancha:true, cuerpo:cu, pie});
}

/* ---- escucha en vivo: oír, susurrar, entrar ---- */
let ondaTimer = null;
function abrirEscucha(agId) {
  const a = porId(agId); if (!a) return;
  const llam = EN_CURSO.find(x => x.ag === agId);
  S.escucha = agId; S.modo = 'oir';
  const e = $('escucha'); e.hidden = false; e.replaceChildren();

  const head = el('div', 'esc-head');
  const pul = el('i', 'pulso'); pul.style.color = 'var(--danger)';
  head.append(pul, el('b', null, 'En la llamada'));
  head.append(el('span', 'sello', 'PRÓXIMAMENTE'));
  const x = el('button', 'hoja-x', '✕'); x.type = 'button'; x.style.color = '#94A3B8';
  x.onclick = cerrarEscucha;
  head.append(x);
  e.append(head);

  const cu = el('div', 'esc-cuerpo');
  const q = el('div', 'esc-quien');
  q.append(avatar(a.nombre, 'esc-quien-cara'));
  const w = el('div');
  w.append(el('b', null, a.nombre));
  w.append(el('em', null, llam ? 'con ' + llam.cliente + ' · ' + mmss(llam.seg) : 'en llamada'));
  q.append(w);
  cu.append(q);

  const onda = el('div', 'esc-onda'); onda.id = 'escOnda';
  for (let i = 0; i < 34; i++) onda.append(el('i'));
  cu.append(onda);

  const modos = el('div', 'esc-modos');
  const defs = [
    ['oir',      'oreja', 'Escuchar',  'Nadie lo oye a usted.'],
    ['susurro',  'micro', 'Susurrar',  'Solo el agente lo escucha. El cliente no.'],
    ['entrar',   'tel',   'Entrar',    'Los tres hablan. El cliente lo escucha.'],
  ];
  const nota = el('div', 'esc-nota'); nota.id = 'escNota';
  for (const [k, ico, txt, desc] of defs) {
    const b = el('button', 'esc-modo' + (k === 'oir' ? ' is-on' : '') + (k === 'entrar' ? ' rojo' : ''));
    b.type = 'button'; b.id = 'modo-' + k;
    b.append(svg(ico), el('span', null, txt));
    b.onclick = () => {
      S.modo = k;
      modos.querySelectorAll('.esc-modo').forEach(y => y.classList.remove('is-on'));
      b.classList.add('is-on');
      nota.textContent = desc;
      aviso(k === 'oir' ? 'Escuchando en silencio' : k === 'susurro'
        ? 'Susurro activo: solo ' + a.nombre.split(' ')[0] + ' lo escucha'
        : 'Entró a la llamada. Los tres se escuchan.');
    };
    modos.append(b);
  }
  nota.textContent = defs[0][3];
  cu.append(modos, nota);

  const aud = el('div'); aud.style.cssText = 'font-size:9.5px;color:#64748B;border-top:1px solid rgba(255,255,255,.1);padding-top:8px';
  aud.textContent = 'Queda registrado: Rodrigo Valdez escuchó a ' + a.nombre + ' a las ' + reloj(HOY.ahora) + '.';
  cu.append(aud);
  e.append(cu);

  clearInterval(ondaTimer);
  ondaTimer = setInterval(() => {
    const o = $('escOnda'); if (!o) return clearInterval(ondaTimer);
    Array.from(o.children).forEach((b, i) => {
      const v = Math.abs(Math.sin(Date.now() / 190 + i * 0.6)) * (S.modo === 'oir' ? 0.8 : 1);
      b.style.height = (8 + v * 88) + '%';
      b.classList.toggle('is-on', v > 0.35);
    });
  }, 90);
}
function cerrarEscucha() {
  S.escucha = null; clearInterval(ondaTimer);
  const e = $('escucha'); e.hidden = true; e.replaceChildren();
}

/* ---- wallboard ---- */
let tvTimer = null, ventaTimer = null;

/* Carlos Díaz viene cuarto con 16 800. Con esta venta llega a 31 000 y pasa
   al primer puesto: es el salto más grande posible sin inventar una cifra
   absurda. Y es justamente el agente del seguimiento de coaching, así que
   la historia cierra sola: el que venía costando es el que cierra. */
const VENTA_DEMO = {ag:'5003', monto:14200};

/* ------------------------------- la venta que entra en el wallboard ---
   El tablero de la pared no está para informar: está para mover el piso.
   Cuando alguien cierra, se entera todo el mundo —confeti, el nombre en
   grande, y el ranking reordenándose a la vista— y el que está a dos
   ventas del primero levanta el teléfono una vez más.

   Las cifras extra viven en S.tvBonus y se borran al cerrar el Modo TV:
   la maqueta vuelve sola a su estado de siempre y el recorrido se puede
   repetir cuantas veces haga falta. */

const montoTV = a => a.monto + ((S.tvBonus || {})[a.id] || 0);

function confeti(host, piezas) {
  const colores = ['#4ADE80', '#FBBF24', '#60A5FA', '#F472B6', '#A78BFA', '#FB923C', '#fff'];
  const capa = el('div', 'confeti');
  for (let i = 0; i < (piezas || 90); i++) {
    const p = el('i');
    p.style.left = (Math.random() * 100).toFixed(2) + '%';
    p.style.background = colores[Math.floor(Math.random() * colores.length)];
    p.style.animationDuration = (2.4 + Math.random() * 2.2).toFixed(2) + 's';
    p.style.animationDelay = (Math.random() * 1.1).toFixed(2) + 's';
    p.style.width = (6 + Math.random() * 7).toFixed(0) + 'px';
    p.style.height = (10 + Math.random() * 10).toFixed(0) + 'px';
    p.style.opacity = (0.75 + Math.random() * 0.25).toFixed(2);
    capa.append(p);
  }
  host.append(capa);
  setTimeout(() => capa.remove(), 6200);
  return capa;
}

/* Reordena la lista animando el recorrido de cada fila: se mide dónde
   estaba cada una, se cambia el orden, y se la deja volver desde su sitio
   viejo. Sin esto el ranking salta de golpe y no se entiende quién subió. */
function reordenarRanking(ul, orden) {
  const antes = {};
  Array.from(ul.children).forEach(li => {
    antes[li.dataset.ag] = li.getBoundingClientRect().top;
  });
  orden.forEach(id => { const li = ul.querySelector('[data-ag="' + id + '"]'); if (li) ul.append(li); });
  Array.from(ul.children).forEach((li, i) => {
    const pos = li.querySelector('.pos'); if (pos) pos.textContent = String(i + 1);
    const d = (antes[li.dataset.ag] || 0) - li.getBoundingClientRect().top;
    if (!d) return;
    li.style.transition = 'none';
    li.style.transform = 'translateY(' + d + 'px)';
  });
  // Un cuadro después se sueltan todas y el navegador las lleva a su sitio.
  requestAnimationFrame(() => {
    Array.from(ul.children).forEach(li => {
      li.style.transition = '';
      li.style.transform = '';
    });
  });
}

/* `demora` existe para las pruebas: en pantalla el ranking se reordena 900
   ms después, para que primero salte el importe y recién entonces se mueva
   la fila —si pasa todo junto no se entiende quién subió—. Con demora 0 se
   reordena en el acto y la comprobación puede leer el resultado. */
function ventaEnPantalla(agId, monto, demora) {
  const t = $('tv');
  if (!t || t.hidden) return;
  const a = porId(agId); if (!a) return;

  const ul = $('tvRank'); if (!ul) return;
  const puestoAntes = Array.from(ul.children).findIndex(li => li.dataset.ag === agId) + 1;

  S.tvBonus = S.tvBonus || {};
  S.tvBonus[agId] = (S.tvBonus[agId] || 0) + monto;

  const orden = AGENTES.slice().sort((x, y) => montoTV(y) - montoTV(x)).map(x => x.id);
  const puestoAhora = orden.indexOf(agId) + 1;

  confeti(t);

  /* El cartel. */
  const cartel = el('div', 'tv-venta'); cartel.id = 'tvVenta';
  const cara = el('span', 'cara'); cara.innerHTML = caraSVG(a.nombre);
  const tx = el('div', 'tv-venta-txt');
  tx.append(el('span', null, 'VENTA CONFIRMADA'));
  tx.append(el('b', null, a.nombre));
  tx.append(el('em', null, money(monto)));
  if (puestoAntes && puestoAhora && puestoAhora < puestoAntes) {
    const sube = el('div', 'tv-venta-sube');
    sube.append(document.createTextNode('▲ del ' + puestoAntes + '.º al ' + puestoAhora + '.º puesto'));
    tx.append(sube);
  }
  cartel.append(cara, tx);
  t.append(cartel);

  /* El importe de su fila y el ranking. */
  const li = ul.querySelector('[data-ag="' + agId + '"]');
  if (li) {
    const b = li.querySelector('b');
    if (b) { b.textContent = money(montoTV(a)); b.classList.add('salta'); }
    li.classList.add('sube');
  }
  const reordenar = () => reordenarRanking(ul, orden.slice(0, 6));
  if (demora === 0) reordenar(); else setTimeout(reordenar, demora === undefined ? 900 : demora);

  /* Los números de arriba y el termómetro de la meta. */
  const kpis = $('tv') ? $('tv').querySelectorAll('.tv-kpi') : [];
  if (kpis.length) {
    const prod = kpis[0].querySelector('b');
    if (prod) { prod.textContent = money(REAL_AHORA + totalBonus()); prod.classList.add('salta'); }
    const vent = kpis[1].querySelector('b');
    if (vent) { vent.textContent = String(31 + Object.keys(S.tvBonus).length); vent.classList.add('salta'); }
  }
  pintarTermometro();

  setTimeout(() => {
    const c = $('tvVenta'); if (c) { c.classList.add('is-yendo'); setTimeout(() => c.remove(), 520); }
    if (li) li.classList.remove('sube');
    Array.from(ul.querySelectorAll('b.salta')).forEach(x => x.classList.remove('salta'));
    Array.from(t.querySelectorAll('.tv-kpi b.salta')).forEach(x => x.classList.remove('salta'));
  }, 5200);
}

const totalBonus = () => Object.values(S.tvBonus || {}).reduce((a, b) => a + b, 0);

/* El anillo de la meta se vuelve a dibujar con el total nuevo. */
function pintarTermometro() {
  const aro = $('tvAro'); if (!aro) return;
  const pct = Math.min(100, Math.round((REAL_AHORA + totalBonus()) / META_DIA * 100));
  const rr = 44, cc = 2 * Math.PI * rr;
  const arco = aro.querySelectorAll('circle')[1];
  if (arco) arco.setAttribute('stroke-dashoffset', String(cc * (1 - pct / 100)));
  const v = aro.querySelector('.tv-term-val b'); if (v) v.textContent = pct + '%';
  const f = $('tvFalta');
  if (f) f.innerHTML = 'Faltan <b style="color:#fff">' +
    money(Math.max(0, META_DIA - REAL_AHORA - totalBonus())) + '</b><br>y quedan 2 h 18 min';
}

function abrirTV() {
  const t = $('tv'); t.hidden = false; t.replaceChildren();

  const head = el('div', 'tv-head');
  head.append(el('h2', null, 'Producción del día'));
  const r = el('div', 'reloj'); r.id = 'tvReloj'; r.textContent = reloj(HOY.ahora);
  head.append(r);
  const x = el('button', 'tv-x', '✕'); x.type = 'button'; x.onclick = cerrarTV;
  head.append(x);
  t.append(head);

  const k = el('div', 'tv-kpis');
  const tk = (rot, val, cls) => { const d = el('div', 'tv-kpi' + (cls ? ' ' + cls : ''));
    d.append(el('span', null, rot), el('b', null, val)); return d; };
  k.append(tk('Producción', money(REAL_AHORA), 'meta'), tk('Ventas', '31'),
           tk('Contactados', '673'), tk('En llamada', String(AGENTES.filter(a => a.estado === 'call').length)),
           tk('Abandono', '4,2 %'));
  t.append(k);

  const cuerpo = el('div', 'tv-cuerpo');
  const ul = el('ul', 'tv-rank'); ul.id = 'tvRank';
  AGENTES.slice().sort((a, b) => montoTV(b) - montoTV(a)).slice(0, 6).forEach((a, i) => {
    const li = el('li'); li.id = 'tvr-' + a.id; li.dataset.ag = a.id;
    li.append(el('span', 'pos', String(i + 1)));
    li.append(avatar(a.nombre, 'cara'));
    li.append(el('span', null, a.nombre));
    li.append(el('b', null, montoTV(a) ? money(montoTV(a)) : '—'));
    ul.append(li);
  });
  cuerpo.append(ul);

  const term = el('div', 'tv-term');
  const pctMeta = Math.round(REAL_AHORA / META_DIA * 100);
  const aro = el('div', 'tv-term-aro'); aro.id = 'tvAro';
  const rr = 44, cc = 2 * Math.PI * rr;
  const s = svgNodo('svg', {viewBox:'0 0 100 100'});
  s.append(svgNodo('circle', {cx:50, cy:50, r:rr, fill:'none', stroke:'rgba(255,255,255,.12)', 'stroke-width':10}));
  s.append(svgNodo('circle', {cx:50, cy:50, r:rr, fill:'none', stroke:'#4ADE80', 'stroke-width':10,
    'stroke-linecap':'round', 'stroke-dasharray':cc, 'stroke-dashoffset':cc * (1 - pctMeta / 100)}));
  aro.append(s);
  const av = el('div', 'tv-term-val');
  av.append(el('b', null, pctMeta + '%'), el('span', null, 'de la meta'));
  aro.append(av);
  term.append(aro);
  const falta = el('div'); falta.id = 'tvFalta';
  falta.style.cssText = 'font-size:16px;color:#94A3B8;text-align:center';
  falta.innerHTML = 'Faltan <b style="color:#fff">' + money(META_DIA - REAL_AHORA) + '</b><br>y quedan 2 h 18 min';
  term.append(falta);
  cuerpo.append(term);
  t.append(cuerpo);

  const pie = el('div', 'tv-pie');
  pie.innerHTML = '<span>Contactabilidad <b>36,4 %</b></span><span>Conversión <b>4,6 %</b></span>' +
    '<span>Ocupación <b>71 %</b></span><span>Nivel de servicio entrantes <b>91 %</b></span>';
  t.append(pie);

  /* La venta entra sola a los pocos segundos de abrir la pared: en el
     recorrido guiado tiene que ocurrir en cámara, sin que nadie apriete
     nada. Con la tecla V se repite, por si el gerente quiere verla otra
     vez — un botón visible en un wallboard delataría que es una maqueta. */
  clearTimeout(ventaTimer);
  ventaTimer = setTimeout(() => ventaEnPantalla(VENTA_DEMO.ag, VENTA_DEMO.monto), 4200);

  clearInterval(tvTimer);
  tvTimer = setInterval(() => {
    const e = $('tvReloj'); if (!e) return clearInterval(tvTimer);
    const s2 = new Date().getSeconds();
    e.textContent = reloj(HOY.ahora) + (s2 % 2 ? ' ' : ':') + String(s2).padStart(2, '0');
  }, 1000);
}
function cerrarTV() {
  clearInterval(tvTimer); clearTimeout(ventaTimer);
  // Las cifras vuelven a su sitio: el recorrido se puede repetir igual.
  S.tvBonus = {};
  const t = $('tv'); t.hidden = true; t.replaceChildren();
}

/* ---- acciones de las alertas ---- */
function accion(a) {
  const [k, arg] = String(a).split(':');
  if (k === 'despausar') {
    const ag = porId(arg); if (!ag) return;
    ag.estado = 'libre'; delete ag.motivo;
    aviso(ag.nombre + ' volvió a estar disponible');
    irA('piso');
  } else if (k === 'bajarNivel') {
    if (CAMPANAS[0]) { CAMPANAS[0].nivel = 1; CAMPANAS[0].abandono = 1.4; }
    aviso('Marcado en 1:1. El abandono bajó a 1,4 %.');
    irA('piso');
  } else if (k === 'escuchar') {
    abrirEscucha(arg);
  } else if (k === 'chat') {
    abrirChatFlot(arg);
  } else if (k === 'ir') {
    irA(arg);
  }
}

/* ================================================= buscador ========== */
function buscarGlobal(q) {
  const caja = $('sugerencias');
  const t = q.trim().toLowerCase();
  if (!t) { caja.hidden = true; return; }
  caja.replaceChildren();
  const añadir = (grupo, items) => {
    if (!items.length) return;
    caja.append(el('div', 'sug-grupo', grupo));
    items.slice(0, 4).forEach(it => {
      const b = el('button', 'sug-item'); b.type = 'button';
      b.append(avatar(it.n, 'chat-ag-cara'));
      const w = el('div'); w.style.minWidth = '0';
      w.append(el('b', null, it.n));
      const e = el('div'); e.style.cssText = 'font-size:10.5px;color:var(--text-muted)'; e.textContent = it.d;
      w.append(e);
      b.append(w);
      b.onclick = () => { caja.hidden = true; $('qGlobal').value = ''; it.fn(); };
      caja.append(b);
    });
  };
  añadir('Clientes', CLIENTES.filter(c => c.nombre.toLowerCase().includes(t) || c.tel.includes(t) || c.doc.includes(t))
    .map(c => ({n:c.nombre, d:c.tel + ' · ' + c.prod, fn:() => { S.cliente = c.id; irA('clientes'); }})));
  añadir('Agentes', AGENTES.filter(a => a.nombre.toLowerCase().includes(t) || a.id.includes(t))
    .map(a => ({n:a.nombre, d:'Anexo ' + a.id + ' · ' + ESTADOS[a.estado].t, fn:() => fichaAgente(a.id)})));
  añadir('Campañas', CAMPANAS.filter(c => c.nombre.toLowerCase().includes(t) || c.id.includes(t))
    .map(c => ({n:c.nombre, d:c.tipo === 'out' ? 'Saliente' : 'Entrante', fn:() => irA('campanas')})));
  caja.hidden = !caja.childNodes.length;
}

/* ================================================== arranque ========= */
function arrancar() {
  const yo = (typeof YO === 'object' && YO && YO.nombre) ? YO.nombre : 'Supervisión';
  $('yoCara').innerHTML = caraSVG(yo);
  $('topYo').innerHTML = caraSVG(yo);
  $('badgeAlertas').textContent = String(ALERTAS.length);
  $('topReloj').textContent = HOY.fecha + ' · ' + reloj(HOY.ahora);

  $('btnPlegar').append(svg('menu'));
  $('btnAlertas').append(svg('alerta'));
  $('buscador').prepend(svg('buscar'));

  $('btnPlegar').onclick = () => {
    S.plegado = !S.plegado;
    $('app').classList.toggle('is-plegado', S.plegado);
  };
  $('btnAlertas').onclick = () => { irA('piso');
    setTimeout(() => { const a = $('cardAlertas'); if (a) a.scrollIntoView({behavior:'smooth', block:'center'}); }, 60); };
  $('btnTV').onclick = abrirTV;
  $('qGlobal').oninput = e => buscarGlobal(e.target.value);
  $('qGlobal').onblur = () => setTimeout(() => { $('sugerencias').hidden = true; }, 180);

  document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement !== $('qGlobal')) { e.preventDefault(); $('qGlobal').focus(); }
    if (e.key === 'Escape') { cerrarHoja(); cerrarTV(); cerrarChatFlot(); }
  });

  pintarNav();
  pintarBurbuja();
  irA('piso');
}

function entrarApp() {
  $('portada').classList.add('is-ido');
  setTimeout(() => { $('portada').hidden = true; }, 460);
  $('app').hidden = false;
  arrancar();
}

document.addEventListener('DOMContentLoaded', () => {
  // El puesto real no tiene portada ni recorrido: entra directo al piso.
  if (!$('portada')) { $('app').hidden = false; arrancar(); return; }
  $('btnLibre').onclick = entrarApp;
  // El botón del recorrido lo engancha guion.js; si no cargó, al menos entra.
  $('btnTour').onclick = () => { if (!window.__tour) entrarApp(); };
});

/* ==================================================== MONITOREO REMOTO
   Cuatro pestañas bajo una sola entrada de menú: lista, mapa, mosaico y
   detalle. El mapa de Lima y las miniaturas de escritorio se dibujan en
   SVG acá mismo: ni una imagen, ni una CDN, ni una dependencia. La demo
   tiene que abrir aunque la sala no tenga internet. */

Object.assign(ICO, {
  monitor:    '<rect x="2.5" y="4" width="19" height="13" rx="2"/><path d="M8.5 21h7"/><path d="M12 17v4"/>',
  energia:    '<path d="M12 3v9"/><path d="M7.5 6.2a7.5 7.5 0 1 0 9 0"/>',
  procesos:   '<rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M8 9h8M8 12.5h8M8 16h5"/>',
  ubicacion:  '<path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
  inactividad:'<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3.5 2"/><path d="M3 3l18 18" opacity=".45"/>',
  lupa:       '<path d="M4 9V5a1 1 0 0 1 1-1h4"/><path d="M20 9V5a1 1 0 0 0-1-1h-4"/><path d="M4 15v4a1 1 0 0 0 1 1h4"/><path d="M20 15v4a1 1 0 0 1-1 1h-4"/>',
  lista:      '<path d="M4 6h16M4 12h16M4 18h16"/>',
  cuadros:    '<rect x="3" y="3" width="7.5" height="7.5" rx="1"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1"/>',
});

/* ------------------------------------------------- el mapa, en SVG --- */
/* Lima estilizada: el océano a la izquierda, el río Rímac cruzando, y los
   distritos como manchas. No pretende ser cartografía: tiene que dejar
   claro de un vistazo que los equipos están repartidos por la ciudad. */
function mapaLimaSVG() {
  const s = svgNodo('svg', {viewBox:'0 0 600 420', preserveAspectRatio:'none'});
  s.append(svgNodo('rect', {x:0, y:0, width:600, height:420, fill:'#DCEAF5'}));
  // Tierra.
  s.append(svgNodo('path', {fill:'#E8EEE4', stroke:'#C9D6C4', 'stroke-width':1.5,
    d:'M96 0 L600 0 L600 420 L168 420 C150 372 132 330 120 288 C108 240 96 192 104 144 C110 96 96 48 96 0 Z'}));
  // Manchas urbanas.
  [[230,70,70,44],[330,120,86,52],[200,170,64,40],[300,230,96,58],
   [420,180,78,46],[460,300,70,42],[250,320,84,50],[380,350,66,38]].forEach(([x,y,w,h],i) => {
    s.append(svgNodo('ellipse', {cx:x, cy:y, rx:w, ry:h, fill:'#DDE6D7', opacity:.75}));
  });
  // Río Rímac.
  s.append(svgNodo('path', {fill:'none', stroke:'#A8C6DE', 'stroke-width':4, 'stroke-linecap':'round',
    d:'M132 196 C200 182 262 196 318 176 C382 154 448 166 600 132'}));
  // Costa.
  s.append(svgNodo('path', {fill:'none', stroke:'#8FB3CC', 'stroke-width':2.5,
    d:'M96 0 C110 96 104 144 104 144 C96 192 108 240 120 288 C132 330 150 372 168 420'}));
  [['Callao',150,120],['Cercado',300,170],['San Isidro',250,250],
   ['La Molina',470,210],['S.J. Lurigancho',430,96],['V.M.T.',300,360]]
    .forEach(([t, x, y]) => {
      const n = svgNodo('text', {x, y, 'font-size':13, fill:'#7E9488',
        'text-anchor':'middle', 'font-family':'Arial'});
      n.textContent = t; s.append(n);
    });
  return s;
}

/* ------------------------------- escritorios falsos, también en SVG --- */
/* Seis variantes. Son dibujos: nadie puede confundirlos con la captura
   real de la pantalla de una persona, y eso es deliberado. */
function pantallaSVG(v, titulo) {
  const fondos = ['#1E3A5F', '#2A4365', '#1F3B2E', '#3A2E52', '#4A3728', '#263445'];
  const s = svgNodo('svg', {viewBox:'0 0 320 190'});
  s.append(svgNodo('rect', {x:0, y:0, width:320, height:190, fill:fondos[v % 6]}));
  // Ventana principal (navegador con el CRM).
  s.append(svgNodo('rect', {x:14, y:14, width:214, height:142, rx:4, fill:'#F8FAFC'}));
  s.append(svgNodo('rect', {x:14, y:14, width:214, height:15, rx:4, fill:'#E2E8F0'}));
  [22, 31, 40].forEach((cx, i) => s.append(svgNodo('circle',
    {cx, cy:21.5, r:2.6, fill:['#EF4444','#F59E0B','#22C55E'][i]})));
  s.append(svgNodo('rect', {x:14, y:29, width:54, height:127, fill:'#0F172A'}));
  [36, 48, 60, 72, 84].forEach(y => s.append(svgNodo('rect',
    {x:20, y, width:42, height:5, rx:2.5, fill:'#334155'})));
  s.append(svgNodo('rect', {x:74, y:36, width:146, height:9, rx:2, fill:'#CBD5E1'}));
  [52, 64, 76, 88, 100].forEach((y, i) => {
    s.append(svgNodo('rect', {x:74, y, width:(i % 2 ? 120 : 146), height:6, rx:2, fill:'#E2E8F0'}));
  });
  s.append(svgNodo('rect', {x:74, y:114, width:62, height:16, rx:3, fill:'#2563EB'}));
  // Ventana secundaria: el teléfono del asesor.
  s.append(svgNodo('rect', {x:236, y:(v % 2 ? 24 : 52), width:70, height:96, rx:4, fill:'#0F172A'}));
  s.append(svgNodo('rect', {x:244, y:(v % 2 ? 32 : 60), width:54, height:12, rx:2, fill:'#1E293B'}));
  [0, 1, 2, 3].forEach(r2 => [0, 1, 2].forEach(c2 => s.append(svgNodo('rect',
    {x:244 + c2 * 19, y:(v % 2 ? 50 : 78) + r2 * 15, width:15, height:11, rx:2, fill:'#334155'}))));
  // Barra de tareas.
  s.append(svgNodo('rect', {x:0, y:168, width:320, height:22, fill:'rgba(0,0,0,.45)'}));
  [10, 32, 54, 76, 98].forEach(x => s.append(svgNodo('rect',
    {x, y:174, width:15, height:11, rx:2, fill:'#64748B'})));
  const t = svgNodo('text', {x:312, y:182, 'font-size':9, fill:'#CBD5E1',
    'text-anchor':'end', 'font-family':'Arial'});
  t.textContent = titulo || '';
  s.append(t);
  return s;
}

/* ---------------------------------------------------------- sellos --- */
function selloMon(clave) {
  const t = MON_SELLOS[clave];
  if (!t) return null;
  const s = el('span', 'sello', t);
  s.title = 'Está en el plan de desarrollo. Se muestra para que se vea a dónde va el producto.';
  return s;
}

const haceCuanto = s => s < 60 ? 'hace ' + s + 's'
  : s < 3600 ? 'hace ' + Math.floor(s / 60) + ' min'
  : 'hace ' + Math.floor(s / 3600) + ' h';

const equipoPorId = id => EQUIPOS.find(e => e.id === id);

function equiposFiltrados() {
  const q = (S.monQ || '').trim().toLowerCase();
  if (!q) return EQUIPOS;
  return EQUIPOS.filter(e =>
    e.nombre.toLowerCase().includes(q) || e.host.toLowerCase().includes(q) ||
    e.usuario.toLowerCase().includes(q) || e.ip_local.includes(q) ||
    e.ip_publica.includes(q) || e.tags.join(' ').toLowerCase().includes(q));
}

/* ====================================================== la vista ===== */
function vMonitoreo(c) {
  S.monTab = S.monTab || 'lista';
  S.monEquipo = S.monEquipo || (EQUIPOS[0] || {}).id || null;
  S.monControl = S.monControl || {};
  S.monOcultas = S.monOcultas || {};

  /* ---- encabezado fijo ---- */
  const head = el('div', 'mon-head'); head.id = 'monHead';
  head.append(el('h1', null, 'Monitoreo remoto'));
  const cuenta = el('span', 'mon-cuenta'); cuenta.id = 'monCuenta';
  cuenta.append(el('i', 'pt'), document.createTextNode(''));
  cuenta.append(el('b', null, String(MON_TOTAL)), document.createTextNode(' equipos · '),
                el('b', null, String(MON_EN_LINEA)), document.createTextNode(' en línea'));
  head.append(cuenta);

  const busca = el('div', 'mon-busca');
  const inp = el('input'); inp.id = 'monQ'; inp.type = 'text';
  inp.placeholder = 'Buscar por tag / host / IP…';
  inp.value = S.monQ || '';
  inp.oninput = () => { S.monQ = inp.value; repintarMon(); };
  busca.append(svg('buscar'), inp);
  head.append(busca);

  const banner = el('span', 'banner-proto', 'DATOS DE EJEMPLO');
  banner.id = 'bannerProto';
  banner.title = 'Los equipos y las cifras de esta pantalla son de ejemplo.';
  head.append(banner);
  c.append(head);

  /* ---- pestañas ---- */
  const tabs = el('div', 'mon-tabs'); tabs.id = 'monTabs';
  [['lista', 'Lista de equipos', 'lista'], ['mapa', 'Mapa', 'ubicacion'],
   ['mosaico', 'Mosaico', 'cuadros'], ['detalle', 'Detalle del equipo', 'monitor']
  ].forEach(([k, t, ico]) => {
    const b = el('button', 'mon-tab' + (S.monTab === k ? ' is-on' : ''));
    b.type = 'button'; b.id = 'tab-' + k;
    b.append(svg(ico), el('span', null, t));
    b.onclick = () => { S.monTab = k; repintarMon(); };
    tabs.append(b);
  });
  c.append(tabs);

  const host = el('div'); host.id = 'monCuerpo';
  c.append(host);
  pintarPestanaMon(host);
}

function repintarMon() {
  const h = $('monCuerpo');
  if (!h) return irA('monitoreo');
  // Las pestañas se repintan sin recrear el encabezado: así el cursor no
  // se sale del buscador mientras se escribe.
  $$('#monTabs .mon-tab').forEach(b => b.classList.toggle('is-on', b.id === 'tab-' + S.monTab));
  h.replaceChildren();
  pintarPestanaMon(h);
}

function pintarPestanaMon(host) {
  if (S.monTab === 'lista')   return host.append(monLista());
  if (S.monTab === 'mapa')    return host.append(monMapa());
  if (S.monTab === 'mosaico') return host.append(monMosaico());
  return host.append(monDetalle());
}

function abrirEquipo(id) { S.monEquipo = id; S.monTab = 'detalle'; repintarMon(); }

/* ---------------------------------------------------- 1. la lista --- */
function monLista() {
  const lista = equiposFiltrados();
  const t = tarjeta('Equipos', lista.length + ' de ' + MON_TOTAL + ' visibles',
    {plano:true, id:'cardMonLista'});
  const tb = el('table', 'mon-tabla');
  const h = el('tr');
  ['', 'Nombre', 'Latencia', 'Tags', 'Visto', 'Host / usuario', 'IPs', 'Speedtest', 'Acciones']
    .forEach(x => h.append(el('th', null, x)));
  const th = el('thead'); th.append(h); tb.append(th);

  const body = el('tbody'); body.id = 'monBody';
  if (!lista.length) {
    const tr = el('tr'); const td = el('td');
    td.colSpan = 9; td.append(el('div', 'vacio', 'Ningún equipo coincide con «' + S.monQ + '».'));
    tr.append(td); body.append(tr);
  }
  for (const e of lista) {
    const tr = el('tr'); tr.dataset.eq = e.id;
    tr.onclick = () => abrirEquipo(e.id);

    const td0 = el('td');
    td0.append(el('i', 'pt-estado pt-estado--' + (e.estado === 'online' ? 'on' : 'off')));
    tr.append(td0);

    tr.append(el('td', 'mon-nombre', e.nombre));

    const td2 = el('td');
    if (e.latencia_ms === null) td2.append(el('span', 'mon-ips', '—'));
    else td2.append(el('span', 'mon-lat' + (e.latencia_ms > 200 ? ' mon-lat--mal' : ''),
      e.latencia_ms + ' ms'));
    tr.append(td2);

    const td3 = el('td');
    e.tags.forEach(x => td3.append(el('span',
      'mon-chip' + (x === 'SIN DATOS' || x === 'RED LENTA' ? ' mon-chip--alerta' : ''), x)));
    tr.append(td3);

    tr.append(el('td', 'mon-ips', haceCuanto(e.visto_hace_s)));

    const td5 = el('td');
    td5.append(el('div', 'mon-host', e.host), el('div', 'mon-ips', e.usuario));
    tr.append(td5);

    const td6 = el('td', 'mon-ips');
    td6.append(el('div', null, 'LOC ' + e.ip_local), el('div', null, 'PUB ' + e.ip_publica));
    tr.append(td6);

    tr.append(el('td', 'mon-ips', e.speedtest
      ? e.speedtest.bajada + ' / ' + e.speedtest.subida + ' Mbps' : 'Sin speedtest'));

    const td8 = el('td');
    const acc = el('div', 'mon-acc');
    [['monitor', 'Ver pantalla'], ['energia', 'Energía'], ['procesos', 'Procesos'],
     ['ubicacion', 'Ubicación'], ['inactividad', 'Inactividad']].forEach(([ico, tit]) => {
      const b = el('button', 'mon-ico-btn'); b.type = 'button'; b.title = tit;
      b.append(svg(ico));
      b.onclick = ev => { ev.stopPropagation(); abrirEquipo(e.id); };
      acc.append(b);
    });
    td8.append(acc); tr.append(td8);
    body.append(tr);
  }
  tb.append(body);
  t.cuerpo.append(tb);
  return t;
}

/* ----------------------------------------------------- 2. el mapa --- */
const CAJA_MAPA = {latMin:-12.20, latMax:-11.90, lonMin:-77.15, lonMax:-76.90};
const aPct = g => ({
  x: (g.lon - CAJA_MAPA.lonMin) / (CAJA_MAPA.lonMax - CAJA_MAPA.lonMin) * 100,
  y: (CAJA_MAPA.latMax - g.lat) / (CAJA_MAPA.latMax - CAJA_MAPA.latMin) * 100,
});

function monMapa() {
  const env = el('div', 'mon-mapa'); env.id = 'cardMonMapa';
  const sello = selloMon('mapa');
  const t = tarjeta('Dónde está cada equipo', 'Lima Metropolitana',
    {plano:true, id:'cardMapa', der:[sello, boton('Ocultar mapa', 'btn--mini',
      () => { S.monTab = 'lista'; repintarMon(); })].filter(Boolean)});

  const lienzo = el('div', 'lienzo-mapa'); lienzo.id = 'lienzoMapa';
  lienzo.append(mapaLimaSVG());

  for (const e of equiposFiltrados()) {
    const p = aPct(e.gps);
    const m = el('button', 'marca marca--' + (e.estado === 'online' ? 'on' : 'off') +
      (e.id === S.monEquipo ? ' is-sel' : ''));
    m.type = 'button'; m.id = 'marca-' + e.id;
    m.style.left = p.x + '%'; m.style.top = p.y + '%';
    m.title = e.nombre + ' · ' + e.host;
    m.onclick = ev => { ev.stopPropagation(); S.monEquipo = e.id; repintarMon(); };
    lienzo.append(m);
  }

  // Globo del equipo seleccionado.
  const sel = equipoPorId(S.monEquipo);
  if (sel && equiposFiltrados().includes(sel)) {
    const p = aPct(sel.gps);
    const g = el('div', 'globo-mapa'); g.id = 'globoMapa';
    g.style.left = p.x + '%'; g.style.top = p.y + '%';
    g.append(el('b', null, sel.nombre), el('span', 'mon-host', sel.host));
    g.append(boton('Ver detalle', 'btn--primary btn--mini', () => abrirEquipo(sel.id)));
    lienzo.append(g);
  }

  const ley = el('div', 'mapa-ley');
  [['#16A34A', 'en línea'], ['#94A3B8', 'fuera de línea']].forEach(([c, txt]) => {
    const s = el('span'); const i = el('i'); i.style.background = c;
    s.append(i, document.createTextNode(txt)); ley.append(s);
  });
  lienzo.append(ley);
  t.cuerpo.append(lienzo);

  const lado = tarjeta('Equipos', null, {plano:true, id:'cardMapaLado'});
  const ul = el('div');
  for (const e of equiposFiltrados()) {
    const b = el('button', 'chat-ag' + (e.id === S.monEquipo ? ' is-on' : ''));
    b.type = 'button'; b.id = 'lado-' + e.id;
    b.append(el('i', 'pt-estado pt-estado--' + (e.estado === 'online' ? 'on' : 'off')));
    const w = el('div'); w.style.minWidth = '0';
    w.append(el('b', null, e.nombre));
    const sub = el('div', 'mon-host'); sub.textContent = e.host;
    w.append(sub);
    b.append(w);
    b.onclick = () => { S.monEquipo = e.id; repintarMon(); };
    ul.append(b);
  }
  lado.cuerpo.append(ul);

  env.append(t, lado);
  return env;
}

/* -------------------------------------------------- 3. el mosaico --- */
function monMosaico() {
  const sello = selloMon('mosaico');
  const t = tarjeta('Pantallas del equipo', 'Miniaturas en vivo',
    {id:'cardMonMosaico', der:[sello, boton('Mostrar todas', 'btn--mini',
      () => { S.monOcultas = {}; repintarMon(); })].filter(Boolean)});

  const g = el('div', 'mosaico'); g.id = 'mosaico';
  const lista = equiposFiltrados().filter(e => !S.monOcultas[e.id]);
  if (!lista.length) g.append(el('div', 'vacio', 'No queda ninguna pantalla visible.'));

  for (const e of lista) {
    const ctrl = !!S.monControl[e.id];
    const caja = el('div', 'mos' + (ctrl ? ' is-control' : '')); caja.id = 'mos-' + e.id;

    const barra = el('div', 'mos-barra');
    barra.append(el('i', 'pt-estado pt-estado--' + (e.estado === 'online' ? 'on' : 'off')));
    const w = el('div'); w.style.minWidth = '0';
    w.append(el('b', null, e.nombre));
    const hh = el('div', 'mon-host'); hh.textContent = e.host; w.append(hh);
    barra.append(w);

    const btns = el('div', 'mos-btns');
    const bc = el('button', 'mos-btn' + (ctrl ? ' is-on' : '')); bc.type = 'button';
    bc.id = 'ctrl-' + e.id;
    bc.textContent = ctrl ? 'Control ON' : 'Control OFF';
    bc.title = 'Tomar el control del escritorio del asesor';
    bc.onclick = () => {
      S.monControl[e.id] = !ctrl;
      repintarMon();
      aviso(ctrl ? 'Control devuelto a ' + e.persona
        : 'Control tomado sobre ' + e.nombre + ' — queda registrado');
    };
    const ba = el('button', 'mos-btn'); ba.type = 'button'; ba.textContent = 'Ampliar';
    ba.onclick = () => ampliarPantalla(e.id);
    const bx = el('button', 'mos-btn'); bx.type = 'button'; bx.textContent = 'Cerrar';
    bx.onclick = () => { S.monOcultas[e.id] = true; repintarMon(); };
    btns.append(bc, ba, bx);
    barra.append(btns);

    const pant = el('div', 'mos-pantalla');
    pant.append(pantallaSVG(e.pantalla, e.usuario));
    pant.onclick = () => ampliarPantalla(e.id);

    const pie = el('div', 'mos-pie' + (e.sesion !== 'Conectado' ? ' mos-pie--mal' : ''));
    pie.append(el('i', 'pt-estado pt-estado--' + (e.sesion === 'Conectado' ? 'on' : 'off')),
               document.createTextNode(e.sesion));
    const lat = el('span'); lat.style.marginLeft = 'auto';
    lat.textContent = e.latencia_ms === null ? '—' : e.latencia_ms + ' ms';
    pie.append(lat);

    caja.append(barra, pant, pie);
    g.append(caja);
  }
  t.cuerpo.append(g);
  return t;
}

function ampliarPantalla(id) {
  const e = equipoPorId(id); if (!e) return;
  const capa = $('capa'); capa.replaceChildren(); capa.hidden = false;
  capa.className = 'lupa';
  const caja = el('div', 'lupa-caja');
  const barra = el('div', 'lupa-barra');
  barra.append(el('b', null, e.nombre));
  barra.append(el('span', 'mon-host', e.host + ' · ' + e.usuario));
  const s = selloMon('pantalla'); if (s) barra.append(s);
  const x = el('button', 'lupa-x', '✕'); x.type = 'button'; x.onclick = cerrarLupa;
  barra.append(x);
  caja.append(barra, pantallaSVG(e.pantalla, e.usuario));
  capa.append(caja);
  capa.onclick = ev => { if (ev.target === capa) cerrarLupa(); };
}
function cerrarLupa() {
  const c = $('capa'); c.hidden = true; c.className = 'capa'; c.replaceChildren();
}

/* -------------------------------------------------- 4. el detalle --- */
function monDetalle() {
  const e = equipoPorId(S.monEquipo) || EQUIPOS[0];
  if (!e) return el('div', 'vacio', 'No hay equipos para mostrar.');
  const env = el('div', 'mon-det'); env.id = 'cardMonDetalle';

  /* a) identidad */
  const id = tarjeta(e.nombre, e.persona, {id:'cardMonId', der:[
    el('span', 'pill pill--sin ' + (e.estado === 'online' ? 'pill--call' : 'pill--off'),
      e.estado === 'online' ? 'En línea' : 'Fuera de línea')]});
  const g = el('div', 'det-id');
  const dato = (rot, val, mono) => {
    const d = el('div', 'det-dato');
    d.append(el('span', null, rot));
    if (mono) { const b = el('b'); b.append(el('code', null, val)); d.append(b); }
    else d.append(el('b', null, val));
    return d;
  };
  g.append(
    dato('Equipo', e.host, true), dato('Usuario', e.usuario),
    dato('Campaña', e.campana), dato('IP local', e.ip_local, true),
    dato('IP pública', e.ip_publica, true),
    dato('Latencia', e.latencia_ms === null ? '—' : e.latencia_ms + ' ms'),
    dato('Agente', 'v' + e.version), dato('Último latido', haceCuanto(e.visto_hace_s)),
    dato('Speedtest', e.speedtest ? e.speedtest.bajada + ' / ' + e.speedtest.subida + ' Mbps' : 'Sin speedtest'),
    dato('Sesión', e.sesion));
  id.cuerpo.append(g);
  const tg = el('div', 'chips'); tg.style.marginTop = '9px';
  e.tags.forEach(x => tg.append(el('span',
    'mon-chip' + (x === 'SIN DATOS' || x === 'RED LENTA' ? ' mon-chip--alerta' : ''), x)));
  id.cuerpo.append(tg);
  env.append(id);

  /* b) pantalla */
  const pa = tarjeta('Pantalla', 'Lo que está viendo ahora', {id:'cardMonPantalla', der:[
    selloMon('pantalla'),
    boton('Ampliar', 'btn--mini', () => ampliarPantalla(e.id), 'lupa'),
    boton('Tomar el control', 'btn--mini',
      () => aviso('Control tomado sobre ' + e.nombre + ' — queda registrado')),
  ].filter(Boolean)});
  const gr = el('div', 'mon-grande');
  gr.append(pantallaSVG(e.pantalla, e.usuario));
  pa.cuerpo.append(gr);
  env.append(pa);

  /* c) procesos */
  const pr = tarjeta('Procesos en ejecución', e.procesos.length + ' activos',
    {plano:true, id:'cardMonProcesos', der:[selloMon('procesos')].filter(Boolean)});
  const tb = el('table', 'mon-tabla');
  const h = el('tr');
  ['Proceso', 'Para qué', 'PID', 'CPU %', 'RAM MB', 'Tiempo abierto']
    .forEach(x => h.append(el('th', null, x)));
  const th = el('thead'); th.append(h); tb.append(th);
  const body = el('tbody'); body.id = 'monProcBody';
  for (const p of e.procesos) {
    const tr = el('tr'); tr.style.cursor = 'default';
    tr.append(el('td', 'mon-host', p.nombre), el('td', null, p.que),
      el('td', 'mon-ips', String(p.pid)), el('td', 'mon-lat', p.cpu.toFixed(1)),
      el('td', 'mon-lat', String(p.ram_mb)), el('td', 'mon-ips', p.abierto));
    body.append(tr);
  }
  tb.append(body); pr.cuerpo.append(tb);
  env.append(pr);

  /* d) actividad */
  const act = el('div', 'act2');
  const cl = tarjeta('Portapapeles', 'Últimas copias',
    {id:'cardMonClip', der:[selloMon('clip')].filter(Boolean)});
  const ul = el('ul', 'clip');
  e.portapapeles.slice(0, 5).forEach(x => {
    const li = el('li');
    li.append(el('time', null, x.hora), el('p', null, x.texto));
    ul.append(li);
  });
  cl.cuerpo.append(ul);

  const min = Math.round(e.inactividad_s / 60);
  const oc = tarjeta('Tiempo sin usar la PC', null,
    {id:'cardMonOcio', der:[selloMon('inactivo')].filter(Boolean)});
  const caja = el('div', 'ocio' + (min >= 15 ? ' mucho' : ''));
  caja.append(el('b', null, min + ' min'), el('span', null, 'sin actividad'));
  const det = el('div', 'ocio-detalle');
  det.innerHTML = 'Se mide con dos señales: el <b>último movimiento del mouse o tecla</b> ' +
    'y la <b>última interacción con la ventana en primer plano</b>. Se toma la más reciente ' +
    'de las dos.';
  caja.append(det);
  oc.cuerpo.append(caja);

  act.append(cl, oc);
  env.append(act);
  return env;
}

/* ==================================================== CHAT FLOTANTE ===
   Una burbuja siempre visible y un panel grande al abrirla. Reemplaza al
   chat apretado que vivia dentro de la tarjeta de Comunicacion: desde
   cualquier vista se le habla a un agente sin perder lo que se estaba
   mirando.

   No hay transporte: el sistema no tiene canal entre supervisor y agente,
   asi que el mensaje se queda en la memoria de esta pagina. La nota al pie
   del panel lo dice, para no prometer lo que no hace. */

function sinLeerChat() {
  return Object.keys(CHAT).reduce((s, k) => s + (CHAT[k] || []).length, 0);
}

function pintarBurbuja() {
  const b = $('burbuja'); if (!b) return;
  b.innerHTML = '';
  b.append(svg('chat'));
  const n = sinLeerChat();
  if (n) b.append(el('span', 'burbuja-n', String(n)));
  b.classList.toggle('is-on', !!S.chatAbierto);
  b.title = S.chatAbierto ? 'Cerrar el chat interno' : 'Chat interno';
  b.onclick = () => (S.chatAbierto ? cerrarChatFlot() : abrirChatFlot());
}

function cerrarChatFlot() {
  S.chatAbierto = false;
  const p = $('chatFlot'); if (p) { p.hidden = true; p.innerHTML = ''; }
  pintarBurbuja();
}

function abrirChatFlot(agId) {
  if (agId) S.chatCon = agId;
  if (!porId(S.chatCon)) S.chatCon = (AGENTES[0] || {}).id || null;
  S.chatAbierto = true;
  pintarChatFlot();
  pintarBurbuja();
}

function pintarChatFlot() {
  const p = $('chatFlot'); if (!p) return;
  p.hidden = false;
  p.innerHTML = '';

  const top = el('div', 'chatflot-top');
  const tw = el('div');
  tw.append(el('b', null, 'Chat interno'));
  tw.append(el('div', 'chatflot-sub', AGENTES.length
    ? AGENTES.length + (AGENTES.length === 1 ? ' agente en el piso' : ' agentes en el piso')
    : 'Sin agentes en el piso'));
  top.append(tw);
  const x = el('button', 'chatflot-x', '×'); x.type = 'button';
  x.title = 'Cerrar'; x.setAttribute('aria-label', 'Cerrar el chat');
  x.onclick = cerrarChatFlot;
  top.append(x);
  p.append(top);

  const cuerpo = el('div', 'chatflot-cuerpo');

  /* -- columna izquierda: con quien hablar -- */
  const lista = el('div', 'chatflot-lista');
  if (!AGENTES.length) lista.append(el('div', 'vacio', 'No hay agentes asignados a sus campañas.'));
  for (const a of AGENTES) {
    const b = el('button', 'chatflot-ag' + (S.chatCon === a.id ? ' is-on' : '')); b.type = 'button';
    b.id = 'chatflot-' + a.id;
    const cara = avatar(a.nombre, 'chat-ag-cara');
    const pt = el('i', 'chat-pt');
    pt.style.background = a.estado === 'call' ? 'var(--ok)' : a.estado === 'pausa' ? 'var(--warn)'
      : a.estado === 'off' ? '#94A3B8' : 'var(--primary)';
    cara.append(pt);
    b.append(cara);
    const w = el('div'); w.style.minWidth = '0';
    w.append(el('b', null, a.nombre));
    w.append(el('div', 'chatflot-ag-est', (ESTADOS[a.estado] || ESTADOS.off).t));
    b.append(w);
    const n = (CHAT[a.id] || []).length;
    if (n) b.append(el('span', 'side-count', String(n)));
    b.onclick = () => abrirChatFlot(a.id);
    lista.append(b);
  }
  cuerpo.append(lista);

  /* -- columna derecha: la conversacion -- */
  const panel = el('div', 'chatflot-panel');
  const ag = porId(S.chatCon);
  if (!ag) {
    panel.append(el('div', 'vacio', 'Elija a alguien de la lista para escribirle.'));
    cuerpo.append(panel); p.append(cuerpo); return;
  }

  const cab = el('div', 'chatflot-cab');
  cab.append(avatar(ag.nombre, 'chat-ag-cara'));
  const cw = el('div');
  cw.append(el('b', null, ag.nombre));
  cw.append(el('div', 'chatflot-ag-est', (ESTADOS[ag.estado] || ESTADOS.off).t
    + (ag.estado === 'call' ? ' · no interrumpir salvo urgencia' : '')));
  cab.append(cw);
  panel.append(cab);

  const hilo = el('div', 'chatflot-hilo'); hilo.id = 'chatHilo';
  const mensajes = CHAT[S.chatCon] || [];
  for (const m of mensajes) {
    const b = el('div', 'chat-msg chat-msg--' + (m.de === 'yo' ? 'yo' : 'el'));
    b.append(document.createTextNode(m.t), el('em', null, m.h));
    hilo.append(b);
  }
  if (!mensajes.length) hilo.append(el('div', 'vacio', 'Sin mensajes todavía.'));
  panel.append(hilo);

  const pie = el('div', 'chatflot-pie');
  const red = el('div', 'redactar');
  const inp = el('input'); inp.id = 'chatTexto';
  inp.placeholder = 'Escribir a ' + String(ag.nombre || '').split(' ')[0] + '…';
  const env = el('button', 'mini'); env.type = 'button'; env.id = 'chatEnviar';
  env.style.cssText = 'background:var(--primary);border-color:var(--primary);color:#fff;width:31px;height:31px';
  env.append(svg('enviar'));
  const mandar = () => {
    const txt = inp.value.trim(); if (!txt) return;
    (CHAT[S.chatCon] = CHAT[S.chatCon] || []).push({de:'yo', t:txt, h:reloj(HOY.ahora)});
    inp.value = '';
    pintarChatFlot(); pintarBurbuja();
    const h = $('chatHilo'); if (h) h.scrollTop = h.scrollHeight;
    const i2 = $('chatTexto'); if (i2) i2.focus();
  };
  env.onclick = mandar;
  inp.onkeydown = e => { if (e.key === 'Enter') mandar(); };
  red.append(inp, env);
  pie.append(red);
  pie.append(el('div', 'chatflot-nota',
    'Todavía no hay canal con el agente: el mensaje queda en esta pantalla y no le llega.'));
  panel.append(pie);

  cuerpo.append(panel);
  p.append(cuerpo);
  const h = $('chatHilo'); if (h) h.scrollTop = h.scrollHeight;
}
