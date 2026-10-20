'use strict';
/* Banco de pruebas de la maqueta de supervisión (app/web/demo-sup).

   En este servidor no hay navegador, así que la página se carga en el
   mini-DOM de dom.js y se la hace funcionar: se navega cada vista, se
   aprietan botones, se recorre el guion entero y se comprueba que lo que
   queda en pantalla sea lo que la voz está diciendo.

   Lo que más se vigila son los defectos que ya nos pasaron una vez:
     · iconos creados sin espacio de nombres, que nunca se dibujan
     · "null" impreso en pantalla por un replaceChildren(null)
     · el foco apuntando a un elemento que en ese momento no existe
     · una frase narrada sin su archivo de audio

   Uso:  node sup-check.js
*/

const fs = require('fs'), vm = require('vm'), path = require('path');
const {makeDocument, El} = require('./dom');
const DEMO = path.resolve(__dirname, '../../../../../app/web/demo-sup');

/* ---------------------------------------------- lo que al DOM le falta */
El.prototype.getBoundingClientRect = () => ({left:10, top:10, width:150, height:60, right:160, bottom:70});
El.prototype.dispatchEvent = function (ev) {
  for (const fn of (this._listeners[ev.type] || [])) fn({type:ev.type, target:this});
  return true;
};
El.prototype.setSelectionRange = () => {};
El.prototype.prepend = function (...nodes) {
  const antes = this.childNodes.slice();
  this.childNodes = [];
  this.append(...nodes);
  antes.forEach(n => { this.childNodes.push(n); n.parentNode = this; });
  return this;
};

const document = makeDocument(fs.readFileSync(path.join(DEMO, 'index.html'), 'utf8'));

/* Reloj acelerado: el recorrido dura minutos reales y acá tiene que
   terminar en segundos. Los temporizadores se disparan de inmediato pero
   en orden, que es lo que importa para medir qué se ve cuándo. */
const pendientes = [];
let idT = 1;
const sandbox = {
  document, console, Math, Date, JSON, Number, String, Boolean, Array, Object,
  Set, Map, RegExp, isFinite, parseInt, parseFloat, Promise, Error, TypeError,
  Event: class { constructor(t) { this.type = t; } },
  requestAnimationFrame: fn => { setTimeout(fn, 0); return 0; },
  setTimeout: (fn) => { const id = idT++; pendientes.push({id, fn}); setTimeout(fn, 0); return id; },
  clearTimeout: () => {},
  setInterval: () => idT++, clearInterval: () => {},
  window: {addEventListener() {}, __tour:false},
  /* Doble del reproductor: informa la frase y termina enseguida. */
  Audio: class {
    constructor(src) { this.src = src; this._l = {}; AUDIOS.push(src); }
    addEventListener(t, fn) { (this._l[t] ||= []).push(fn); }
    pause() {}
    play() { setTimeout(() => (this._l.ended || []).forEach(f => f()), 0); return Promise.resolve(); }
  },
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);

const AUDIOS = [];
const fuente = ['datos.js', 'sup.js', 'guion.js']
  .map(f => fs.readFileSync(path.join(DEMO, f), 'utf8')).join('\n;\n');

let arranqueOk = true, arranqueErr = '';
try {
  vm.runInContext(fuente + `
;globalThis.__api = {
  irA, S, AGENTES, CAPITULOS, P, VOZ, hashTexto, correr, entrarApp, accion,
  abrirEscucha, cerrarEscucha, abrirTV, cerrarTV, fichaAgente, aplicarFoco,
  pintarResultados, buscarGlobal, CAMPANAS, CLIENTES, recontar, ARCHIVO,
  get foco(){ return __foco; }, set foco(v){ __foco = v; },
};
globalThis.__foco = null;
const __ap = aplicarFoco;
aplicarFoco = function (sel) { globalThis.__foco = sel; __FOCOS.push(sel); return __ap(sel); };
const __nar = narrar;
/* Se anota el foco DESPUÉS de drenar la cola de voz, que es cuando el
   espectador lo ve. Anotarlo al llamar a narrar() mide la intención del
   guion, no la pantalla, y esconde justo el desajuste que se busca. */
narrar = async function (t) {
  await VOZ.cola;
  __FRASES.push({t, foco:globalThis.__foco});
  return __nar(t);
};
globalThis.__FOCOS = []; globalThis.__FRASES = [];
`, sandbox, {filename:'demo-sup.js'});
} catch (e) { arranqueOk = false; arranqueErr = e.message; }

/* ------------------------------------------------------- marcador ---- */
let ok = 0; const fallos = [];
function check(nombre, cond, detalle) {
  if (cond) { ok++; return true; }
  fallos.push(nombre + (detalle ? '  → ' + detalle : ''));
  return false;
}
const $ = id => document.getElementById(id);
const txt = id => { const n = $(id); return n ? n.textContent : ''; };
const todo = () => document.body.textContent;
const cuantos = sel => document.querySelectorAll(sel).length;

/* ======================================================= arranque ==== */
check('los tres archivos cargan sin reventar', arranqueOk, arranqueErr);
if (!arranqueOk) { informe(); process.exit(1); }

const api = sandbox.__api;
sandbox.__api.entrarApp();

check('la portada se va al entrar', $('portada').getAttribute('class').includes('is-ido'));
check('la aplicación queda visible', !$('app').hidden);
check('el menú trae las diez secciones', cuantos('.side-link') === 10, cuantos('.side-link') + ' encontradas');
check('el supervisor tiene cara', ($('yoCara').innerHTML || '').includes('<svg'));
check('la barra superior tiene buscador', !!$('qGlobal'));

/* ================================================== vista: piso ====== */
api.irA('piso');
check('piso: seis indicadores arriba', cuantos('#pisoKpis .kpi') === 6, cuantos('#pisoKpis .kpi') + '');
check('piso: el abandono aparece en rojo', /4,2 %/.test(txt('pisoKpis')));
check('piso: está el marcador en vivo', !!$('cardMotor'));
check('piso: la aguja se dibujó', cuantos('#aguja svg') === 1);
check('piso: hay perilla de agresividad', !!$('rangoNivel'));
check('piso: hay botón de pánico', !!$('btnPanico'));
check('piso: la línea de tiempo existe', !!$('cardTL'));
check('piso: una pista por agente', cuantos('.tl-fila[data-ag]') === 8, cuantos('.tl-fila[data-ag]') + '');
check('piso: las pistas tienen bloques', cuantos('.tl-b') > 100, cuantos('.tl-b') + ' bloques');
check('piso: la línea de ahora está marcada', cuantos('.tl-ahora') === 8);
check('piso: la tabla del equipo trae ocho filas', cuantos('#equipoBody tr') === 8);
check('piso: Diana figura con sus 23 minutos', /23 min/.test(txt('equipoBody')));
check('piso: hay llamadas en curso', cuantos('#cardCurso .live') === 4);
check('piso: hay cinco alertas', cuantos('.alerta') === 5);
check('piso: las alertas traen botón de acción', cuantos('.alerta-acc .btn') >= 6);

/* El defecto histórico: iconos creados sin espacio de nombres. */
const svgs = document.querySelectorAll('svg');
check('todos los iconos son SVG de verdad', svgs.length > 40, svgs.length + ' svg');
const vacios = svgs.filter(s => !(s.innerHTML || '').trim() && !s.childNodes.length);
check('ningún icono quedó hueco', vacios.length === 0, vacios.length + ' vacíos');

/* El otro defecto histórico: imprimir null en pantalla. */
check('no se imprime "null" en el piso', !/\bnull\b|\bundefined\b|\bNaN\b/.test(todo()),
  (todo().match(/\bnull\b|\bundefined\b|\bNaN\b/) || [''])[0]);

/* ---- acciones ---- */
api.accion('despausar:5006');
check('sacar de pausa deja a Diana disponible',
  api.AGENTES.find(a => a.id === '5006').estado === 'libre');
api.accion('bajarNivel');
check('bajar el marcado corrige el abandono', api.CAMPANAS[0].abandono === 1.4);
check('y el marcado queda en 1', api.CAMPANAS[0].nivel === 1);

/* ---- escucha ---- */
api.abrirEscucha('5000');
check('escucha: la ventana se abre', !$('escucha').hidden);
check('escucha: ofrece los tres modos', cuantos('.esc-modo') === 3);
check('escucha: susurrar está disponible', !!$('modo-susurro'));
check('escucha: avisa que queda registrado', /Queda registrado/.test(txt('escucha')));
check('escucha: lleva sello de pendiente', /PRÓXIMAMENTE/.test(txt('escucha')));
$('modo-susurro').click();
check('escucha: al susurrar cambia el modo', api.S.modo === 'susurro');
api.cerrarEscucha();
check('escucha: se cierra', $('escucha').hidden);

/* ================================================ vista: campañas ==== */
api.irA('campanas');
check('campañas: cinco campañas', cuantos('.camp') === 5, cuantos('.camp') + '');
check('campañas: hay salientes y entrantes',
  cuantos('.camp-tipo--out') === 3 && cuantos('.camp-tipo--in') === 2);
check('campañas: zonas para soltar agentes', cuantos('.camp-agentes') === 5);
check('campañas: banco de agentes disponible', !!$('banco'));
check('campañas: la cola de entrantes está', !!$('cardCola'));
check('campañas: muestra el nivel de servicio', /91 %/.test(txt('cardCola')));

/* ================================================== vista: base ====== */
api.S.cargaPaso = 1; api.irA('base');
check('base: cuatro pasos', cuantos('.chips .chip') === 4);
check('base: zona para soltar el archivo', !!$('zonaSuelta'));
check('base: nombra el archivo y las filas', /4,820 filas/.test(txt('cardArchivo')));
$('btnMapeo').click();
check('base: el botón lleva al mapeo', api.S.cargaPaso === 2);
api.irA('base');
check('base: el mapeo lista las columnas', cuantos('.mapeo tbody tr') === 9);
check('base: propone el calce solo', cuantos('.mapeo .auto') === 7, cuantos('.mapeo .auto') + '');
api.S.cargaPaso = 3; api.irA('base');
check('base: la validación tiene cinco renglones', cuantos('.vfila') === 5);
const sumaV = api.ARCHIVO.validacion.reduce((a, v) => a + v.n, 0);
check('base: los renglones suman las 4 820 filas', sumaV === api.ARCHIVO.filas, sumaV + '');
check('base: explica la normalización de teléfonos',
  /51939178487/.test(txt('cardValida')) && /1,204/.test(txt('cardValida')));
check('base: deja decidir qué hacer con los repetidos', /Omitirlos/.test(txt('cardValida')));
api.S.cargaPaso = 4; api.irA('base');
check('base: el destino pide campaña y lista', cuantos('#cardDestino select') === 4);
check('base: hay historial de cargas', cuantos('#cardCargas tbody tr') === 3);
check('base: cada carga se puede revertir', /Revertir/.test(txt('cardCargas')));

/* ============================================= vista: segmentación === */
api.irA('segmentos');
check('segmentos: arranca con tres condiciones', cuantos('#condiciones .cond') === 3);
check('segmentos: hay un contador grande', !!$('contador'));
const antes = txt('contador');
api.S.seg.push({campo:'monto', op:'mayor a', val:'10000'});
api.irA('segmentos');
check('segmentos: al agregar condición el total baja', txt('contador') !== antes,
  antes + ' → ' + txt('contador'));
check('segmentos: la tabla de reglas trae las ocho tipificaciones',
  cuantos('#cardReglas tbody tr') === 8);
check('segmentos: dice cuándo reintenta cada una', /a las 2 h/.test(txt('cardReglas')));
check('segmentos: el mapa de calor está completo',
  cuantos('.calor-c') === 66, cuantos('.calor-c') + ' celdas');
check('segmentos: se puede reactivar en la misma lista', !!$('btnReactivar'));
check('segmentos: hay segmentos guardados', cuantos('#cardGuardados .chat-ag') === 4);
check('segmentos: la salud de las listas avisa del agotamiento',
  /78%/.test(txt('cardSalud')));
check('segmentos: simula cuánto va a tardar', /3 h 20 min/.test(txt('cardAcciones')));

/* ================================================ vista: clientes ==== */
api.S.cliente = 4820; api.irA('clientes');
check('clientes: el buscador está', !!$('qCliente'));
check('clientes: lista los cuatro clientes', cuantos('#resultados .chat-ag') === 4);
api.pintarResultados('chávez');
check('clientes: buscar filtra', cuantos('#resultados .chat-ag') === 1);
api.pintarResultados('');
check('clientes: la ficha trae documento y monto',
  /43887120/.test(txt('cardFicha')) && /18.000/.test(txt('cardFicha')));
check('clientes: la ficha dice quién lo lleva', /Carlos Díaz/.test(txt('cardFicha')));
check('clientes: el historial trae los cuatro canales',
  cuantos('.hist-ico--llamada') >= 1 && cuantos('.hist-ico--whatsapp') >= 1 &&
  cuantos('.hist-ico--email') >= 1 && cuantos('.hist-ico--sms') >= 1);
check('clientes: hay conversación de WhatsApp', cuantos('#hiloWA .msg') === 4);
check('clientes: avisa que se responde por el agente', /a nombre de Carlos Díaz/.test(txt('cardWA')));

/* Responder por el agente deja marca propia. */
$('waTexto').value = 'Prueba del supervisor';
$('waEnviar').click();
check('clientes: el supervisor puede responder', cuantos('#hiloWA .msg--sup') === 1);
check('clientes: el mensaje queda marcado como intervención',
  /Prueba del supervisor/.test(txt('hiloWA')));

/* =========================================== vista: conversaciones === */
api.irA('conversa');
check('conversaciones: la bandeja trae las cuatro', cuantos('#cardBandeja tbody tr') === 4);
check('conversaciones: marca lo que lleva sin responder', /Sin responder hace 5 min/.test(txt('cardBandeja')));

/* ============================================= vista: comunicación === */
api.S.chatCon = '5003'; api.irA('comunica');
check('comunicación: el redactor trae texto e imagen',
  !!$('bcastTexto') && /promo_tasa_noviembre/.test(txt('cardBcast')));
check('comunicación: se puede segmentar el envío', /Solo los que están en pausa/.test(txt('cardBcast')));
check('comunicación: el broadcast lleva sello de pendiente', /PRÓXIMAMENTE/.test(txt('cardBcast')));
check('comunicación: hay acuse de recibo por agente', cuantos('.acuse-ag') === 8);
check('comunicación: dice cuántos lo leyeron', /5 de 8 lo leyeron/.test(txt('cardAcuse')));
check('comunicación: alguien levantó la mano', !!$('cardManos'));
check('comunicación: el chat lista a todo el piso', cuantos('#cardChat .chat-ag') === 8);
check('comunicación: el hilo de Carlos tiene mensajes', cuantos('#chatHilo .chat-msg') === 3);
$('chatTexto').value = 'Dale nomás';
$('chatEnviar').click();
check('comunicación: se puede responder por chat', cuantos('#chatHilo .chat-msg') === 4);

/* ================================================= vista: calidad ==== */
api.irA('calidad');
check('calidad: cinco grabaciones', cuantos('#cardGrab tbody tr') === 5);
check('calidad: el puntaje del equipo se dibuja', cuantos('#cardScore .anillo svg') === 1);
check('calidad: cinco criterios evaluados', cuantos('#cardScore .crit') === 5);
check('calidad: lleva sello de pendiente', /PRÓXIMAMENTE/.test(txt('cardScore')));
check('calidad: hay momentos guardados', /base de conocimiento/.test(txt('cardMomentos')));

/* ================================================== vista: equipo ==== */
api.irA('equipo');
check('equipo: asistencia de ocho personas por siete turnos',
  cuantos('.asist-cel') === 56, cuantos('.asist-cel') + '');
check('equipo: marca las tardanzas', cuantos('.asist--tarde') >= 4);
check('equipo: pausas contra el tope', cuantos('.pausa-barra') === 8);
check('equipo: marca el exceso de Diana', /\+23 min|\+\d+ min/.test(txt('cardPausas')));
check('equipo: hay ranking del día', cuantos('#cardRank .live') === 8);
check('equipo: el seguimiento de coaching está', /3 intervenciones en 15 días/.test(txt('cardCoach')));

/* ================================================ vista: reportes ==== */
api.irA('reportes');
check('reportes: los veinte indicadores del motor', cuantos('#cardKpis .mdato') === 20);
check('reportes: aclara la cobertura del dato', /Observada/.test(txt('cardKpis')));
check('reportes: el embudo tiene cinco peldaños', cuantos('#cardEmbudo .emb-f') === 5);
check('reportes: ventas por producto', cuantos('#cardProd .prod-f') === 4);
check('reportes: la curva contra la meta se dibuja', cuantos('#cardCurva svg') === 1);
check('reportes: se puede exportar', /Exportar/.test(txt('cardKpis')));

/* ================================================== modo TV ========== */
api.abrirTV();
check('TV: el wallboard se abre', !$('tv').hidden);
check('TV: cinco números grandes', cuantos('.tv-kpi') === 5);
check('TV: ranking de seis', cuantos('.tv-rank li') === 6);
check('TV: termómetro de la meta', cuantos('.tv-term-aro svg') === 1);
api.cerrarTV();
check('TV: se cierra', $('tv').hidden);

/* ============================================ ficha del agente ======= */
api.fichaAgente('5003');
check('ficha: la hoja se abre', !$('capa').hidden);
check('ficha: trae los números de la persona', cuantos('.hoja .kpi') === 5);
check('ficha: muestra su mañana', cuantos('.hoja .tl-b') > 5);
check('ficha: muestra el historial de coaching', /Coaching/.test(txt('capa')));

/* ================================================== buscador ========= */
api.buscarGlobal('chávez');
check('buscador: encuentra al cliente', /Héctor Chávez/.test(txt('sugerencias')));
api.buscarGlobal('5003');
check('buscador: encuentra al agente por anexo', /Carlos Díaz/.test(txt('sugerencias')));
api.buscarGlobal('');
check('buscador: vacío no muestra nada', $('sugerencias').hidden);

/* =================================================== el recorrido ==== */
(async () => {
  // Cada capítulo se corre de a uno para saber dónde falla si falla.
  for (let i = 0; i < api.CAPITULOS.length; i++) {
    sandbox.__api.P.i = i;
    sandbox.__api.P.corriendo = true;
    sandbox.__api.P.gen++;
    let err = null;
    try { await api.CAPITULOS[i].correr(); } catch (e) { err = e.message; }
    check('capítulo ' + (i + 1) + ' (' + api.CAPITULOS[i].titulo + ') corre entero', !err, err);
  }

  /* El foco tiene que caer en algo que exista cuando la voz habla. */
  const focos = sandbox.__FOCOS.filter(Boolean);
  check('el recorrido mueve el foco', focos.length >= 20, focos.length + ' movimientos');
  const huerfanos = [];
  for (const sel of new Set(focos)) {
    // Se prueban contra todas las vistas —y los cuatro pasos de la carga—
    // porque el selector debe existir en alguna pantalla alcanzable.
    let hallado = false;
    for (const v of ['piso','campanas','base','segmentos','clientes','conversa',
                     'comunica','calidad','equipo','reportes']) {
      for (const paso of (v === 'base' ? [1, 2, 3, 4] : [0])) {
        if (paso) api.S.cargaPaso = paso;
        api.irA(v);
        if (document.querySelector(sel)) { hallado = true; break; }
      }
      if (hallado) break;
    }
    if (!hallado) huerfanos.push(sel);
  }
  check('ningún foco apunta a algo que no existe', huerfanos.length === 0, huerfanos.join(' '));

  /* Cada frase narrada necesita su archivo de audio. */
  const frases = sandbox.__FRASES.map(f => f.t);
  check('el recorrido narra 35 frases', frases.length === 35, frases.length + '');
  const sinAudio = [];
  for (const f of new Set(frases)) {
    const n = 'v' + sandbox.__api.hashTexto(f) + '.mp3';
    if (!fs.existsSync(path.join(DEMO, 'audio', n))) sinAudio.push(n + '  «' + f.slice(0, 42) + '…»');
  }
  check('todas las frases tienen audio', sinAudio.length === 0,
    sinAudio.length + ' sin grabar:\n      ' + sinAudio.join('\n      '));

  /* Ninguna frase hablando de una zona mientras se ilumina otra. */
  const pistas = [
    [/marcador en vivo|la aguja|perilla|una llamada por agente/, ['cardMotor','btnPanico']],
    [/mañana completa|franja|verde es llamada/, ['cardTL']],
    [/alertas/,                 ['cardAlertas','cardEquipo']],
    [/mapa de calor/,           ['cardCalor']],
    [/contador|condiciones/,    ['cardCond','contador']],
    [/conversación de whatsapp|whatsapp de todo el piso/i, ['cardWA','cardBandeja']],
    [/chat|levanta la mano/,    ['cardChat','cardAcuse']],
  ];
  const desajustes = [];
  for (const {t, foco} of sandbox.__FRASES) {
    if (!foco) continue;
    for (const [re, esperados] of pistas) {
      if (re.test(t.toLowerCase()) && !esperados.some(x => foco.includes(x))) {
        desajustes.push('«' + t.slice(0, 54) + '…» con el foco en ' + foco);
      }
    }
  }
  check('la voz y el foco hablan de lo mismo', desajustes.length === 0,
    desajustes.join('\n      '));

  /* Y nada de "null" en pantalla tras el recorrido completo. */
  check('no quedó "null" impreso al final', !/\bnull\b|\bundefined\b|\bNaN\b/.test(todo()),
    (todo().match(/.{0,28}(null|undefined|NaN).{0,28}/) || [''])[0]);

  informe();
})();

function informe() {
  console.log('\n' + '─'.repeat(62));
  if (!fallos.length) {
    console.log('maqueta de supervisión: %d comprobaciones, todo en orden', ok);
  } else {
    console.log('maqueta de supervisión: %d bien, %d MAL\n', ok, fallos.length);
    fallos.forEach(f => console.log('  ✗ ' + f));
  }
  console.log('─'.repeat(62));
  if (fallos.length) process.exitCode = 1;
}
