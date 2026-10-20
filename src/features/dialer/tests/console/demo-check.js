'use strict';
/* Verifica la presentación de app/web/demo: que cargue, que el guion corra
   de principio a fin sin reventar y que cada capítulo deje en pantalla lo
   que dice la narración.

   La presentación no tiene servidor ni base, así que no hay otra forma de
   comprobarla sin abrir un navegador — y en este servidor no hay ninguno.
   El reloj se acelera: los `setTimeout` del guion se resuelven al instante,
   de modo que los seis minutos de recorrido corren en segundos.          */

const fs = require('fs'), vm = require('vm'), path = require('path');
const {makeDocument, El} = require('./dom');
const RAIZ = process.env.ZYNERVOX_WEB || path.resolve(__dirname, '../../../../../app/web');
const DEMO = path.join(RAIZ, 'demo');

const ok = [], fallos = [];
const check = (n, c, d) => { (c ? ok : fallos).push(n); console.log((c ? '  ok   ' : '  FALLO') + ' ' + n + (d ? ' — ' + d : '')); };

/* --- Lo que el navegador tiene y el DOM de pruebas no ------------------ */
El.prototype.getBoundingClientRect = function () { return {left:10, top:10, width:120, height:40}; };
El.prototype.remove = function () {
  if (!this.parentNode) return;
  const i = this.parentNode.childNodes.indexOf(this);
  if (i >= 0) this.parentNode.childNodes.splice(i, 1);
  this.parentNode = null;
};
El.prototype.dispatchEvent = function (ev) {
  for (const fn of (this._listeners[ev.type] || [])) fn({type:ev.type, target:this});
  return true;
};
El.prototype.focus = function () {};
El.prototype.setSelectionRange = function () {};

const html = fs.readFileSync(path.join(DEMO, 'index.html'), 'utf8');
check('la presentación no lleva PHP', !/<\?php/.test(html));
check('no carga nada de modules/agc', !/modules\/agc/.test(html.replace(/<!--[\s\S]*?-->/g, '')));
check('trae sus propias hojas y guiones',
  ['consola.css', 'demo.css', 'base.js', 'secciones.js', 'demo.js'].every(f => html.includes(f)));

const document = makeDocument(html);
document.body.dataset = document.body.dataset || {};

const sandbox = {
  document, console, Math, Date, JSON, Number, String, Boolean, Array, Object, Set, Map,
  RegExp, isFinite, parseInt, parseFloat, Promise, Error,
  Event: class { constructor(t) { this.type = t; } },
  requestAnimationFrame: fn => fn(),
  // Reloj acelerado: cada espera del guion se resuelve en el siguiente
  // turno, así los seis minutos de recorrido corren en segundos. Tiene que
  // ser un temporizador real: el guion usa `await` y las continuaciones no
  // ocurren si se llama al callback a mano.
  setTimeout: fn => setTimeout(fn, 0),
  clearTimeout: () => {}, setInterval: () => ++idTimer, clearInterval: () => {},
  window: {addEventListener() {}},
};
let idTimer = 0;
sandbox.globalThis = sandbox;
vm.createContext(sandbox);

const codigo = ['base.js', 'secciones.js', 'demo.js']
  .map(f => fs.readFileSync(path.join(DEMO, f), 'utf8')).join('\n;\n')
  + '\n;globalThis.__P=P;globalThis.__CAP=CAPITULOS;globalThis.__fn={pintarMarco,pintarBienvenida,ondaMontar,irA,reiniciarEscena,pintarFicha,pintarCartera};\n';
try { vm.runInContext(codigo, sandbox, {filename:'demo.js'}); }
catch (e) { console.log('FALLO al cargar: ' + e.message + '\n' + e.stack); process.exit(1); }

const $ = id => document.getElementById(id);
const txt = id => ($(id) ? $(id).textContent.trim() : '<sin nodo>');
const vista = () => $('vista');

// Arranque, como hace el navegador al terminar de leer la página.
(document._l && document._l.DOMContentLoaded || []).forEach(fn => fn());

(async () => {
console.log('\n== La consola replicada se arma ==');
check('el operador tiene nombre y rol', txt('opName') === 'Ana Torres' && txt('opRole') === 'Asesor de ventas');
check('la cartera trae las llamadas del día', $('listBody').querySelectorAll('.cli').length === 5,
  String($('listBody').querySelectorAll('.cli').length));
check('hay campañas para elegir', $('ctlCampaign').children.length === 4);
check('y motivos de pausa', $('ctlPauseType').children.length === 6);
check('la onda tiene sus barras', $('onda').children.length === 26);
check('arranca en la pantalla de bienvenida', /Para empezar/.test(txt('detBody')));
check('con el aviso de demostración', !!document.querySelector('.sello'));

console.log('\n== El guion corre entero ==');
const CAP = sandbox.__CAP;
check('tiene los capítulos esperados', CAP.length === 16, String(CAP.length));

const rotos = [];
for (let i = 0; i < CAP.length; i++) {
  sandbox.__P.i = i;
  try { sandbox.__fn.reiniciarEscena(); await CAP[i].corre(); }
  catch (e) { rotos.push(CAP[i].n + ': ' + e.message); }
}
check('ningún capítulo revienta', rotos.length === 0, rotos.join(' · '));

console.log('\n== Cada capítulo deja lo que promete ==');
const correr = async i => { sandbox.__P.i = i; sandbox.__fn.reiniciarEscena(); await CAP[i].corre(); };

await correr(3);   // la llamada
check('al contestar se abre la ficha del cliente', /Betty Ramírez/.test(txt('detHead')));
check('con sus datos cargados',
  /44218905/.test(txt('detBody')) && /betty\.ramirez@gmail\.com/.test(txt('detBody')));

await correr(4);   // transcripción
check('la transcripción escribe las dos voces',
  $('trxBody').querySelectorAll('.trx-line--agente').length >= 2
  && $('trxBody').querySelectorAll('.trx-line--cliente').length >= 2);

await correr(5);   // objeción
check('ante la objeción, el asistente propone un speech',
  /Objeción detectada/.test(txt('asisBody')) && /no es una deuda nueva/i.test(txt('asisBody')));
check('y cita de dónde sale', /Guía de objeciones/.test(txt('asisBody')));

await correr(6);   // consulta a la IA
check('la consulta a la IA responde con la tasa',
  /38,9/.test(txt('asisBody')) && /730/.test(txt('asisBody')));
check('y con su fuente', /Tarifario vigente/.test(txt('asisBody')));

await correr(7);   // WhatsApp
check('WhatsApp abre la conversación dentro de la ficha',
  $('detBody').querySelectorAll('.wa-msg').length >= 5,
  String($('detBody').querySelectorAll('.wa-msg').length));
check('y el cliente responde',
  $('detBody').querySelectorAll('.wa-msg--cliente').length >= 2);

await correr(8);   // correo y SMS
check('los SMS enviados se listan', $('detBody').querySelectorAll('.bandeja li').length === 3);

await correr(9);   // historial
check('el historial junta todos los canales', (() => {
  const t = txt('detBody');
  return ['Llamada realizada', 'WhatsApp enviado', 'SMS enviado', 'Correo enviado'].every(x => t.includes(x));
})());

await correr(10);  // pausas
check('la pausa deja al agente disponible al final', txt('agentState') === 'Disponible');

await correr(11);  // cierre de la gestión
check('tipificar deja los tres niveles elegidos',
  $('tip0').value !== '' && $('tip1').value !== '' && $('tip2').value !== '');
check('y la hoja se guarda sola', $('layer').hidden === true);
check('el bloque vuelve a su depósito', $('tip0').closest('#gestionHost') !== null);

await correr(12);  // tareas
check('Tareas muestra los pendientes', vista().querySelectorAll('.tarea').length >= 8,
  String(vista().querySelectorAll('.tarea').length));

await correr(13);  // reportes
check('Reportes encabeza con la comisión', /Comisión acumulada/.test(txt('vista')));
check('y dice cuánto falta para el tramo', /Te faltan/.test(txt('vista')));

await correr(14);  // conocimiento
check('Conocimiento abre la base con sus fichas',
  vista().querySelectorAll('.battle-col').length === 4
  || vista().querySelectorAll('.wiki-lista li').length >= 10);

await correr(15);  // cierre
check('el cierre resume los canales', (() => {
  const c = document.querySelector('.cierre');
  return !!c && ['Llamadas', 'WhatsApp', 'Correo', 'SMS'].every(x => c.textContent.includes(x));
})());

console.log('\n== Higiene ==');
for (const [et, id] of [['narración', 'narraTxt'], ['expediente', 'detBody'], ['asistente', 'asisBody']]) {
  check('la ' + et + ' no deja null ni undefined', !/null|undefined|NaN/.test(txt(id)),
    (txt(id).match(/.{0,20}(null|undefined|NaN).{0,20}/) || [''])[0]);
}
const svgVacios = document.querySelectorAll('svg').filter(n => !n.innerHTML && n.children.length === 0);
check('no se piden iconos inexistentes', svgVacios.length === 0, String(svgVacios.length));

console.log('\n========================================================');
console.log(ok.length + ' OK, ' + fallos.length + ' fallos');
if (fallos.length) { console.log('FALLOS:'); for (const f of fallos) console.log('- ' + f); }
console.log('RESULTADO: ' + (fallos.length ? 'CON FALLOS' : 'OK'));
process.exit(fallos.length ? 1 : 0);
})();
