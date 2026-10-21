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
  // La narración grabada: acá no suena nada, pero el guion la espera, así
  // que el doble termina de inmediato. Así se comprueba que el recorrido no
  // dependa del audio para avanzar.
  Audio: class {
    constructor(src) { this.src = src; this._l = {}; }
    addEventListener(t, fn) { (this._l[t] ||= []).push(fn); }
    pause() {}
    play() { setTimeout(() => (this._l.ended || []).forEach(f => f()), 0); return Promise.resolve(); }
  },
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
// El guion respeta la pausa: sin esto, las esperas no avanzarían nunca.
sandbox.__P.corriendo = true;

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
check('tiene los capítulos esperados', CAP.length === 17, String(CAP.length));

const rotos = [];
for (let i = 0; i < CAP.length; i++) {
  sandbox.__P.i = i;
  try { sandbox.__fn.reiniciarEscena(); await CAP[i].corre(); }
  catch (e) { rotos.push(CAP[i].n + ': ' + e.message); }
}
check('ningún capítulo revienta', rotos.length === 0, rotos.join(' · '));

console.log('\n== Cada capítulo deja lo que promete ==');
const correr = async i => { sandbox.__P.i = i; sandbox.__fn.reiniciarEscena(); await CAP[i].corre(); };

await correr(1);   // la pantalla: el expediente tiene que tener datos
/* Antes se iluminaba el expediente mientras la voz describía los datos de
   un cliente, pero no había ninguno abierto: se veía la bienvenida. */
check('al hablar del expediente hay un cliente abierto',
  !/Para empezar|Hola, Ana/.test(txt('detBody')), txt('detBody').slice(0, 40));
check('con nombre, documento y monto',
  /Héctor Chávez/.test(txt('detHead'))
  && /43887120/.test(txt('detBody')) && /18.000|18,000/.test(txt('detBody')));
check('y su historial de contactos debajo',
  /Historial de interacciones/.test(txt('detFoot')) && /Llamada realizada/.test(txt('detFoot')));

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

await correr(14);  // aviso del supervisor
check('el aviso del supervisor llegó a la consola',
  /yapeamos 10 soles/.test(txt('bandejaAvisos')) || /yapeamos 10 soles/.test(txt('avisos')));
check('y quedó guardado en la campanita tras confirmarlo',
  /confirmado/.test(txt('bandejaAvisos')) && !/sin confirmar/.test(txt('bandejaAvisos')),
  txt('bandejaAvisos').slice(0, 70));
check('la campanita guarda también el aviso anterior',
  /tasa preferente/.test(txt('bandejaAvisos')));
check('la campanita no deja deuda pendiente', $('campanaN').hidden);
check('el aviso dice a quién se le mandó',
  /6 asesores/.test(txt('bandejaAvisos')) || /6 asesores/.test(txt('avisos')));

await correr(15);  // conocimiento
check('Conocimiento abre la base con sus fichas',
  vista().querySelectorAll('.battle-col').length === 4
  || vista().querySelectorAll('.wiki-lista li').length >= 10);

await correr(16);  // cierre
check('el cierre resume los canales', (() => {
  const c = document.querySelector('.cierre');
  return !!c && ['Llamadas', 'WhatsApp', 'Correo', 'SMS'].every(x => c.textContent.includes(x));
})());

console.log('\n== Nada se duplica ==');
/* Pausar y reanudar llegó a lanzar una segunda copia del capítulo sobre la
   primera, y la transcripción salía con cada frase repetida. */
{
  sandbox.__P.i = 4;
  sandbox.__fn.reiniciarEscena();
  const correrCap = CAP[4].corre();
  sandbox.__P.corriendo = false;            // pausa en medio
  await new Promise(r => setTimeout(r, 60));
  sandbox.__P.corriendo = true;             // y reanuda
  await correrCap;
  const lineas = $('trxBody').querySelectorAll('.trx-line').map(n => n.textContent);
  const repetidas = lineas.filter((t, i) => lineas.indexOf(t) !== i);
  check('pausar y reanudar no repite líneas', repetidas.length === 0,
    repetidas.length + ' repetidas');
  check('la pausa no se come las líneas', lineas.length === 4, String(lineas.length));
}
{
  // Saltar de capítulo abandona la pasada anterior en vez de encimarla.
  sandbox.__P.i = 4;
  sandbox.__fn.reiniciarEscena();
  CAP[4].corre();
  sandbox.__P.gen++;                        // como hace saltarA
  await new Promise(r => setTimeout(r, 400));
  const n = $('trxBody').querySelectorAll('.trx-line').length;
  await new Promise(r => setTimeout(r, 400));
  check('saltar detiene la pasada anterior',
    $('trxBody').querySelectorAll('.trx-line').length === n,
    n + ' -> ' + $('trxBody').querySelectorAll('.trx-line').length);
}

console.log('\n== La voz ==');
/* Los audios se generan aparte, con scripts/demo_voz.py. Acá sólo se
   comprueba que cada frase tenga el suyo y que el nombre coincida con el
   que la página va a pedir: si no coinciden, la presentación corre muda y
   nadie se entera hasta la reunión. */
{
  const hashTexto = t => {
    let h = 0;
    for (const c of String(t)) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    return h.toString(36);
  };
  const js = fs.readFileSync(path.join(DEMO, 'demo.js'), 'utf8');
  const textos = [...js.matchAll(/narrar\(\s*'((?:[^'\\]|\\.)*)'/g)]
    .map(m => m[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\'));
  check('hay una frase de narración por escena', textos.length === 50, String(textos.length));

  const dirAudio = path.join(DEMO, 'audio');
  const faltan = textos.filter(t => !fs.existsSync(path.join(dirAudio, 'v' + hashTexto(t) + '.mp3')));
  check('cada frase tiene su audio', faltan.length === 0,
    faltan.length ? faltan.length + ' sin audio: ' + faltan[0].slice(0, 40) : '');

  const vacios = textos.filter(t => {
    const f = path.join(dirAudio, 'v' + hashTexto(t) + '.mp3');
    return fs.existsSync(f) && fs.statSync(f).size < 4000;
  });
  check('ninguno quedó vacío o cortado', vacios.length === 0, String(vacios.length));

  check('la página no llama a la API de voz',
    !/elevenlabs|xi-api-key/i.test(js + fs.readFileSync(path.join(DEMO, 'index.html'), 'utf8')));
  check('se puede silenciar', /btnVoz/.test(js));
  // La voz va acelerada al reproducir, no al grabar: no cuesta créditos y
  // el tono tiene que mantenerse o suena a dibujo animado.
  check('la voz va un 30% más rápida', /ritmo:1\.3/.test(js) && /playbackRate = VOZ\.ritmo/.test(js));
  check('y conserva el tono', /preservesPitch = true/.test(js));
  // El producto se instala: es una aplicación que trae el softphone dentro.
  check('no promete que no haya que instalar nada', !/Sin instalar nada/.test(js));
  check('y dice que el teléfono viene incluido', /El teléfono viene incluido/.test(js));
  check('y una frase sin audio no detiene el recorrido', /addEventListener\('error'/.test(js));
  /* La voz tiene que acompañar la acción, no turnarse con ella: las frases
     se encolan para no pisarse, el texto en pantalla espera a la anterior
     —así el foco no se adelanta a lo que se está diciendo— y las acciones
     del tramo corren en paralelo. */
  check('las frases se encolan, no se pisan', /VOZ\.cola = VOZ\.cola\.then/.test(js));
  check('el texto no se adelanta a la voz',
    /async function narrar/.test(js) && /await vozAlDia\(\);/.test(js));
  check('la escena no espera a la voz para moverse',
    !/await VOZ\.promesa/.test(js));
  check('y el capítulo no cambia a media frase',
    /await vozAlDia\(\);\s*\/\/ que termine de hablar/.test(js));
  const sinAwait = (js.match(/(?<!await )(?<![.\w])narrar\(/g) || [])
    .filter(x => true).length;
  check('todas las narraciones se esperan',
    (js.match(/await narrar\(/g) || []).length === textos.length,
    (js.match(/await narrar\(/g) || []).length + ' de ' + textos.length);
  // El foco va en la misma fila que la voz: si se aplicara al pedirlo,
  // saltaría una frase antes de tiempo.
  check('el foco se encola con la voz', /VOZ\.cola = VOZ\.cola\.then\(\(\) => \{ if \(gen === P\.gen\) aplicarFoco/.test(js));
  // El cierre tiene que nombrar los canales, no sólo mostrarlos.
  check('el cierre nombra los canales integrados',
    /el WhatsApp, el correo y el mensaje de texto son el mismo expediente/.test(js));
}

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
