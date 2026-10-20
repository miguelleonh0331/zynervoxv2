'use strict';
/* Reconstruye la línea de tiempo de la presentación: qué se está diciendo
   en cada segundo y qué está iluminado en pantalla en ese mismo momento.

   En este servidor no hay navegador, así que no se puede mirar la demo.
   Esto es lo más cerca: un reloj virtual que avanza saltando de evento en
   evento, con la duración REAL de cada audio tomada del archivo. Permite
   encontrar el defecto que da más vergüenza en una presentación —la voz
   habla de la derecha mientras se ilumina la izquierda— sin verla.

   Uso:  node demo-timeline.js            línea de tiempo completa
         node demo-timeline.js --revisar  sólo los desajustes sospechosos
*/

const fs = require('fs'), vm = require('vm'), path = require('path');
const {makeDocument, El} = require('./dom');
const DEMO = path.resolve(__dirname, '../../../../../app/web/demo');
const RITMO = 1.3;        // la página acelera la voz un 30 %
const KBPS = 128;         // ElevenLabs entrega mp3 de tasa constante

El.prototype.getBoundingClientRect = () => ({left:10, top:10, width:120, height:40});
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
El.prototype.focus = () => {}; El.prototype.setSelectionRange = () => {};

/* ------------------------------------------------- reloj virtual ----- */
let ahora = 0, sig = 1;
const cola = [];
const programar = (fn, ms) => { cola.push({t:ahora + (ms || 0), i:sig, fn}); return sig++; };
function avanzar() {
  cola.sort((a, b) => a.t - b.t || a.i - b.i);
  const e = cola.shift();
  if (!e) return false;
  ahora = Math.max(ahora, e.t);
  e.fn();
  return true;
}

const document = makeDocument(fs.readFileSync(path.join(DEMO, 'index.html'), 'utf8'));
const linea = [];           // {t, tipo, dato}
let focoActual = '(ninguno)';

const duracion = src => {
  const f = path.join(DEMO, src);
  if (!fs.existsSync(f)) return 0;
  return (fs.statSync(f).size * 8 / (KBPS * 1000)) / RITMO * 1000;   // ms
};

const sandbox = {
  document, console, Math, Date, JSON, Number, String, Boolean, Array, Object,
  Set, Map, RegExp, isFinite, parseInt, parseFloat, Promise, Error,
  Event: class { constructor(t) { this.type = t; } },
  requestAnimationFrame: fn => programar(fn, 16),
  setTimeout: (fn, ms) => programar(fn, ms), clearTimeout: () => {},
  setInterval: () => 0, clearInterval: () => {},
  window: {addEventListener() {}},
  Audio: class {
    constructor(src) { this.src = src; this._l = {}; }
    addEventListener(t, fn) { (this._l[t] ||= []).push(fn); }
    pause() {}
    play() {
      const ms = duracion(this.src);
      linea.push({t:ahora, tipo:'voz-inicio', dato:this.src, dur:ms});
      programar(() => {
        linea.push({t:ahora, tipo:'voz-fin', dato:this.src});
        (this._l.ended || []).forEach(f => f());
      }, ms || 1);
      return Promise.resolve();
    }
  },
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);

const fuente = ['base.js', 'secciones.js', 'demo.js']
  .map(f => fs.readFileSync(path.join(DEMO, f), 'utf8')).join('\n;\n');
vm.runInContext(fuente + `
;globalThis.__P = P; globalThis.__CAP = CAPITULOS; globalThis.__re = reiniciarEscena;
globalThis.__hash = hashTexto; globalThis.__correr = correr;
/* Se instrumenta lo que el espectador VE, no lo que el guion pide: el foco
   y la frase se encolan, así que lo que importa es cuándo se aplican, no
   cuándo se llamó a la función. Medir la llamada fue un error que escondía
   justamente el desajuste que se busca. */
const __narrar = narrar, __aplicarFoco = aplicarFoco;
globalThis.__espiar = (apunta) => {
  narrar = async function (txt, puntos, activo) {
    const r = await __narrar(txt, puntos, activo);
    apunta('texto', txt);
    return r;
  };
  aplicarFoco = function (sel) { apunta('foco', sel || '(ninguno)'); return __aplicarFoco(sel); };
};
`, sandbox, {filename:'demo.js'});

sandbox.__espiar((tipo, dato) => linea.push({t:ahora, tipo, dato}));
(document._l && document._l.DOMContentLoaded || []).forEach(fn => fn());
for (let i = 0; i < 200 && cola.length; i++) avanzar();   // arranque
linea.length = 0; ahora = 0;

/* ---------------------------------------------------- recorrido ------ */
const CAP = sandbox.__CAP;
sandbox.__P.corriendo = true;

/* El reloj avanza de a un evento y después cede el turno, porque las
   continuaciones de `await` son microtareas: si se drena la cola de un
   tirón, el guion nunca llega a programar el evento siguiente. */
const respirar = () => new Promise(r => setImmediate(r));
const setIntervalReal = setInterval;

async function correrHasta(promesa) {
  let fin = false, vueltas = 0;
  promesa.then(() => { fin = true; }, () => { fin = true; });
  while (!fin && vueltas++ < 400000) {
    if (cola.length) avanzar(); 
    await respirar();
  }
  /* Lo que quede programado al cerrar se ejecuta, pero sin mover el reloj
     más allá de unos segundos: el guion deja una red de seguridad de 30 s
     por audio que en el navegador no cuesta nada y acá inflaba el total. */
  let extra = 0;
  while (cola.length && extra++ < 5000) {
    const prox = cola.reduce((m, e) => Math.min(m, e.t), Infinity);
    if (prox > ahora + 5000) break;
    avanzar(); await respirar();
  }
}

(async () => {
  /* Se usa el reproductor de verdad —el mismo lazo que corre en la
     pantalla— en vez de llamar a los capítulos de a uno. Medir con un
     reinicio entre capítulos inventaba pausas que el espectador no ve. */
  let ultimo = -1;
  const vigilar = setIntervalReal(() => {}, 0);
  clearInterval(vigilar);
  sandbox.__P.i = 0;
  sandbox.__P.corriendo = true;
  const fin = sandbox.__correr();
  let vueltas = 0, terminado = false;
  fin.then(() => { terminado = true; }, () => { terminado = true; });
  while (!terminado && vueltas++ < 600000) {
    if (sandbox.__P.i !== ultimo && sandbox.__P.i < CAP.length) {
      ultimo = sandbox.__P.i;
      linea.push({t:ahora, tipo:'capitulo',
        dato:(ultimo + 1) + '. ' + CAP[ultimo].n + ' — ' + CAP[ultimo].titulo});
    }
    if (cola.length) {
      const prox = cola.reduce((m, e) => Math.min(m, e.t), Infinity);
      // La red de seguridad de 30 s por audio no es tiempo de la escena.
      if (prox > ahora + 20000 && cola.length === 1) break;
      avanzar();
    }
    await respirar();
  }

  // Cada frase con el foco que la acompaña.
  const reloj = ms => Math.floor(ms / 60000) + ':' + String(Math.floor(ms / 1000) % 60).padStart(2, '0');
  const revisar = process.argv.includes('--revisar');
  let foco = '(ninguno)', sospechas = 0;

  for (const e of linea) {
    if (e.tipo === 'foco') { foco = e.dato; if (!revisar) console.log('%s   foco  %s', reloj(e.t), foco); }
    else if (e.tipo === 'capitulo') { if (!revisar) console.log('\n== %s  [%s]', e.dato, reloj(e.t)); }
    else if (e.tipo === 'texto') {
      // ¿La frase habla de un lado y el foco está en otro?
      const t = e.dato.toLowerCase();
      const pistas = [
        [/\bderecha\b|columna de la derecha/, ['col--work', 'trx', 'asis', 'topbar']],
        [/\bizquierda\b|cartera/,             ['col--list']],
        [/\bal centro\b|expediente/,          ['col--detail', 'det-', 'kv', 'wa-', 'tl']],
        [/barra superior/,                    ['topbar']],
        [/asistente/,                         ['asis', 'col--work', 'wiki-art']],
      ];
      let mal = null;
      for (const [re, esperados] of pistas) {
        if (re.test(t) && foco !== '(ninguno)' && !esperados.some(x => foco.includes(x))) {
          mal = esperados.join(' | ');
          break;
        }
      }
      if (mal) sospechas++;
      if (!revisar) console.log('%s   VOZ   «%s»', reloj(e.t), e.dato.slice(0, 96));
      if (mal) console.log('%s   ⚠ DESAJUSTE: dice algo de [%s] pero el foco está en «%s»\n          «%s»',
        reloj(e.t), mal, foco, e.dato.slice(0, 110));
    }
  }
  /* Cuánto del recorrido tiene voz. El objetivo es que suene a alguien
     exponiendo, así que los huecos largos son un defecto, no un detalle. */
  let hablando = 0, ultimoFin = 0, huecos = [];
  for (const e of linea) {
    if (e.tipo === 'voz-inicio') {
      if (e.t - ultimoFin > 2500) huecos.push([ultimoFin, e.t - ultimoFin]);
      hablando += e.dur || 0;
    } else if (e.tipo === 'voz-fin') ultimoFin = e.t;
  }
  if (ahora - ultimoFin > 2500) huecos.push([ultimoFin, ahora - ultimoFin]);

  console.log('\nduración total : %s', reloj(ahora));
  console.log('con voz        : %s  (%d %%)', reloj(hablando), Math.round(hablando / ahora * 100));
  console.log('desajustes     : %d', sospechas);
  console.log('silencios > 2,5 s : %d', huecos.length);
  for (const [t, d] of huecos) console.log('   ' + reloj(t) + '   ' + (d / 1000).toFixed(1) + ' s sin voz');
})();
