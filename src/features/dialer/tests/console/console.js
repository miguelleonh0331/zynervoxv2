'use strict';
/* Verifica la consola del agente (modules/agc): entorno aislado, cartera
   alimentada por el discador y ciclo manual progresivo. */
const fs = require('fs'), vm = require('vm'), path = require('path');
const {makeDocument, El} = require('./dom');
// La raíz sale de la ubicación de este archivo, no de una ruta fija: el repo
// se ha movido de montaje más de una vez. ZYNERVOX_WEB la sobrescribe.
const WEB = process.env.ZYNERVOX_WEB || path.resolve(__dirname, '../../../../../app/web');
const ok = [], fallos = [];
const check = (n, c, d) => { (c ? ok : fallos).push(n); console.log((c ? '  ok   ' : '  FALLO') + ' ' + n + (d ? ' — ' + d : '')); };

let html = fs.readFileSync(path.join(WEB, 'modules/agc/crm.php'), 'utf8')
  .replace(/^[\s\S]*?\?>/, '')
  .replace(/<\?php\s*echo\s+crm_h\(\$operador\);\s*\?>/g, 'Agente 5000')
  .replace(/<\?php\s*echo\s+crm_h\(\$rol\);\s*\?>/g, 'Asesor de ventas')
  .replace(/<\?php\s*echo\s+crm_h\(\$_SESSION\['dialer_csrf'\]\);\s*\?>/g, 'TOKEN123');
if (/<\?php/.test(html)) { console.log('FALLO: PHP sin resolver'); process.exit(1); }

const VOLATIL = {pause:null, lead:null, dispositions:[], pending:null,
  autodial:{enabled:true, waiting:false, seconds:null, running:true}, selectedCampaign:0};
const ARBOL55 = [
  {code:'010000', label:'CONTACTADO',       level:1, parent:'',       contact:false, sale:false, retry:false},
  {code:'010100', label:'SEGUIMIENTO',      level:2, parent:'010000', contact:true,  sale:false, retry:false},
  {code:'010101', label:'INTERESADO',       level:3, parent:'010100', contact:true,  sale:false, retry:false},
  {code:'010102', label:'VOLVER A LLAMAR',  level:3, parent:'010100', contact:true,  sale:false, retry:true},
  {code:'010200', label:'VENTA',            level:2, parent:'010000', contact:true,  sale:false, retry:false},
  {code:'010201', label:'VENTA CONFIRMADA', level:3, parent:'010200', contact:true,  sale:true,  retry:false},
  {code:'020000', label:'NO CONTACTADO',    level:1, parent:'',       contact:false, sale:false, retry:true},
  {code:'020100', label:'NO CONTESTA',      level:2, parent:'020000', contact:false, sale:false, retry:true},
  {code:'020101', label:'REINTENTAR',       level:3, parent:'020100', contact:false, sale:false, retry:true},
];
const FIJO = {campaigns:[{id:55, name:'carsa0001'}], pauseTypes:[{id:1, label:'Almuerzo'}, {id:2, label:'Baño'}],
  trees:{'55':ARBOL55}};
const HISTORIAL = [
  {lead_id:267, call_id:423, campaign_id:55, campaign:'carsa0001', phone:'981641706', name:'alexander', at:'2026-10-17 09:54:13', ended:true, disposition:'01', status:'CONTACTED'},
  {lead_id:266, call_id:422, campaign_id:55, campaign:'carsa0001', phone:'994683441', name:'maria',     at:'2026-10-17 09:53:52', ended:true, disposition:'01', status:'CONTACTED'},
  {lead_id:265, call_id:421, campaign_id:55, campaign:'carsa0001', phone:'960592479', name:'percy',     at:'2026-10-17 09:50:00', ended:true, disposition:null, status:'NEW'},
];

const document = makeDocument(html);
const salida = {href:null};
const sandbox = {
  document, console, URLSearchParams,
  window:{_l:{}, addEventListener(t, fn) { (this._l[t] = this._l[t] || []).push(fn); }},
  top:{get location() { return salida; }, set location(v) { salida.href = v; }},
  setTimeout, clearTimeout, setInterval:(fn, ms) => { sandbox.__ritmo = ms; return ++sandbox.__timers; },
  clearInterval:() => {}, __timers:0,
  fetch:(u, o) => {
    const cuerpo = String((o && o.body) || '');
    if (String(u).startsWith('crm.php')) {
      sandbox.__acciones = (sandbox.__acciones || []).concat([cuerpo]);
      const r = sandbox.__resp || {message:'Conectado · 1 campaña', error:'', state:sandbox.__estado};
      if (/action=bootstrap/.test(cuerpo)) { r.fixed = FIJO; r.history = HISTORIAL; }
      return Promise.resolve({ok:!r.error, json:() => Promise.resolve(r)});
    }
    sandbox.__url = u;
    return Promise.resolve({ok:true, json:() => Promise.resolve({phones:['981641706']})});
  },
  Promise, JSON, Math, Date, Map, Set, Number, String, Boolean, Array, Object, isFinite, RegExp,
};
sandbox.__estado = JSON.parse(JSON.stringify(VOLATIL));
sandbox.globalThis = sandbox;
vm.createContext(sandbox);

const code = fs.readFileSync(path.join(WEB, 'modules/agc/crm.js'), 'utf8')
  + '\n;globalThis.__S=S;globalThis.__fn={abrirCliente,pintarDetalle,pintarCartera,accionDiscador,armarArbol,RITMO_ACTIVO,RITMO_REPOSO};globalThis.__arbol=()=>TIPIFICACION;\n';
try { vm.runInContext(code, sandbox, {filename:'crm.js'}); }
catch (e) { console.log('FALLO al ejecutar: ' + e.message + '\n' + e.stack); process.exit(1); }

const $ = id => document.getElementById(id);
const S = sandbox.__S;
const txt = id => ($(id) ? $(id).textContent.trim() : '<sin nodo>');
const btnTxt = t => $('layer').querySelectorAll('button').find(b => b.textContent.trim() === t);
const canal = i => $('detBody').querySelectorAll('.ch')[i];
const filas = () => $('listBody').querySelectorAll('.cli');
const accion = a => sandbox.__fn.accionDiscador(a);
const elegir = (i, label) => {
  const sel = $('tip' + i), opts = sel.children.filter(o => o.tagName === 'OPTION');
  const idx = opts.findIndex(o => o.textContent === label);
  if (idx < 0) throw new Error('falta ' + label + ' en nivel ' + (i + 1));
  sel.value = opts[idx].getAttribute('value');
  sel.dispatch('change', {target:sel});
};

console.log('\n== Entorno aislado ==');
check('sin sidebar de administración', !/nav-item|sidebar/.test(html));
check('nada apunta a modules/admin', !/\.\.\/admin\//.test(html));
// El menú propio del asesor existe, pero no es una puerta al panel: las
// entradas sin pantalla propia tienen que seguir inertes. Si alguien les
// pone un href sin declarar la página en IsolatedGate, esto lo marca.
check('el menú del asesor no enlaza a ningún lado',
  !/<a[^>]*class="side-link/.test(html) && !/side-link[^>]*href=/.test(html));
check('las entradas de maqueta están desactivadas',
  (html.match(/class="side-link"[^>]*aria-disabled="true"[^>]*disabled/g) || []).length === 6);
check('sólo "Mis clientes" está activa',
  (html.match(/side-link is-on/g) || []).length === 1);
check('hoja y script propios', /href="crm\.css"/.test(html) && /src="crm\.js"/.test(html));
check('salida al logout del agente', S.salida === 'logout.php', S.salida);
check('puente de WhatsApp propio', S.waEndpoint === 'crm_wa_session.php');
check('operador tomado de la sesión', txt('opName') === 'Agente 5000');

console.log('\n== Lo que se quitó de la maqueta ==');
check('sin botón "Terminar atención"', $('btnTerminar') === null);
check('sin filtros de producto ni orden', $('fProducto') === null && $('fOrden') === null);
check('sin contactos ficticios precargados', S.contactos.length === 0 || !/Víctor Andrés/.test($('listBody').textContent));
check('pestañas Hoy · Sin tipificar · Todas',
  /Hoy/.test(txt('listTabs')) && /Sin tipificar/.test(txt('listTabs')) && /Todas/.test(txt('listTabs')), txt('listTabs'));

setTimeout(() => {
  console.log('\n== Cartera sembrada por el discador ==');
  check('una sola consulta de historial al conectar',
    (sandbox.__acciones || []).filter(a => /action=bootstrap/.test(a)).length === 1);
  check('la cartera trae las llamadas de hoy', filas().length === 3, filas().length + ' llamadas');
  check('con nombre y teléfono del lead', /alexander/.test(txt('listBody')) && /981641706/.test(txt('listBody')));
  check('marca las no tipificadas', /SIN TIP/.test(txt('listBody')));
  check('y el código de las tipificadas', /01/.test(txt('listBody')));
  check('la más reciente primero', filas()[0].textContent.includes('alexander'));

  // Avatares: rostros dibujados en el navegador, sin pedir nada a la red.
  {
    const av = () => filas()[0].querySelector('.avatar');
    // El DOM de pruebas guarda innerHTML como texto y no lo parsea, así que
    // se comprueba el marcado emitido, no el nodo resultante.
    check('cada cliente trae su rostro', !!av() && /^<svg[\s>]/.test(av().innerHTML));
    check('dibujado acá, sin pedidos a la red',
      !/<img|src=|url\(|https?:/i.test(av().innerHTML));
    // El nombre nunca entra al SVG como marcado: sólo elige colores y formas.
    const antes = av().innerHTML;
    sandbox.__fn.pintarCartera();
    check('la misma persona tiene siempre la misma cara', av().innerHTML === antes);
    check('el nombre no se interpola en el dibujo', !/alexander/i.test(antes));
    check('y queda accesible por texto', av().getAttribute('title') !== null
      && /alexander/i.test(filas()[0].textContent));
  }

  console.log('\n== Al entrar: bienvenida, no la última llamada ==');
  check('ninguna ficha queda abierta', S.actual === -1, String(S.actual));
  check('saluda al agente', /Hola, Agente 5000/.test(txt('detHead')), txt('detHead'));
  check('explica cómo empezar', !!$('detBody').querySelector('.pasos'));
  check('resume lo del día', /3 llamadas hoy/.test(txt('detHead')) && /1 sin tipificar/.test(txt('detHead')), txt('detHead'));
  check('ninguna fila de la cartera aparece abierta',
    !filas().some(f => f.getAttribute('aria-current') === 'true'));
  check('sin pestañas de expediente', $('detTabs').children.length === 0);

  console.log('\n== Sin llamada no se tipifica ==');
  check('la cascada queda inerte', $('tip0').disabled === true && $('tip1').disabled === true && $('tip2').disabled === true);
  check('y lo dice', txt('tipOut') === 'Sin llamada que tipificar.', txt('tipOut'));
  check('no se puede grabar', $('btnGrabar').disabled === true);
  check('sin aviso de trámite pendiente', $('tipPend').hidden === true && $('tipPendBtn').hidden === true);

  console.log('\n== Pestaña "Sin tipificar" ==');
  $('listTabs').children[1].dispatch('click');
  check('filtra sólo las pendientes', filas().length === 1 && /percy/.test(txt('listBody')), filas().length + '');
  $('listTabs').children[0].dispatch('click');
  check('vuelve a mostrarlas todas', filas().length === 3);

  console.log('\n== Control de llamada con datos fijos ==');
  check('campañas vienen del bloque fijo', $('ctlCampaign').children.filter(o => o.getAttribute('value')).length === 1);
  check('motivos de pausa también', $('ctlPauseType').children.filter(o => o.getAttribute('value')).length === 2);
  check('en reposo el sondeo es lento', sandbox.__ritmo === sandbox.__fn.RITMO_REPOSO, sandbox.__ritmo + ' ms');

  console.log('\n== Entra una llamada: se suma sola a la cartera ==');
  const consultasAntes = (sandbox.__acciones || []).length;
  sandbox.__estado = Object.assign({}, VOLATIL, {selectedCampaign:55,
    dispositions:[{code:'01', label:'contacto'}, {code:'02', label:'NoContacto'}],
    lead:{call_id:424, lead_id:267, phone:'987000111', campaign:'carsa0001', campaign_id:55, name:'JUAN PEREZ',
          at:'2026-10-17 10:10:00', ended:false, bridged:true, disposition:null, status:'NEW', variables:{dni:'12345678'}}});
  sandbox.__resp = {message:'', error:'', state:sandbox.__estado};
  accion('state').then(() => {
    check('la nueva llamada entra en la cartera', filas().length === 4, filas().length + '');
    check('sin consultar el historial otra vez',
      (sandbox.__acciones || []).filter(a => /action=bootstrap/.test(a)).length === 1);
    check('queda seleccionada', S.contactos[S.actual].id === 424, String(S.contactos[S.actual].id));
    check('la ficha muestra el lead del discador', /lead del discador/i.test(txt('detBody')));
    // El lead que entra por el sondeo debe traer su lead_id: sin él, el
    // botón Llamar quedaría muerto justo para el cliente recién atendido.
    check('el lead del sondeo conserva su lead_id', S.contactos[S.actual].leadId === 267, String(S.contactos[S.actual].leadId));
    check('y su botón Llamar no queda muerto tras tipificar',
      (() => { const b = $('detBody').querySelectorAll('.ch')[0]; return /Colgar/.test(b.textContent) || b.disabled === false; })());
    check('con sus variables', /12345678/.test(txt('detBody')));
    check('ahora sí se puede tipificar', $('tip0').disabled === false && txt('tipOut') !== 'Sin llamada que tipificar.');
    check('con llamada activa el sondeo acelera', sandbox.__ritmo === sandbox.__fn.RITMO_ACTIVO, sandbox.__ritmo + ' ms');

    console.log('\n== Colgar deja pendiente y bloquea marcar ==');
    sandbox.__estado = Object.assign({}, VOLATIL, {selectedCampaign:55,
      dispositions:[{code:'01', label:'contacto'}, {code:'02', label:'NoContacto'}],
      pending:{call_id:424, phone:'987000111', campaign:'carsa0001'}});
    sandbox.__resp = {message:'', error:'', state:sandbox.__estado};
    accion('state').then(() => {
      check('no se puede marcar sin tipificar', $('ctlDial').disabled === true);
      check('y lo explica', /Tipifica la llamada anterior/.test($('ctlDial').title || ''), $('ctlDial').title);

      console.log('\n== Tipificar cierra el intento y encadena ==');
      elegir(0, 'CONTACTADO'); elegir(1, 'SEGUIMIENTO'); elegir(2, 'INTERESADO');
      sandbox.__resp = {message:'Llamada tipificada.', error:'',
        state:Object.assign({}, VOLATIL, {selectedCampaign:55, autodial:{enabled:true, waiting:true, seconds:3, running:true}})};
      $('btnGrabar').dispatch('click');
      const envio = (sandbox.__acciones || []).slice(-1)[0];
      check('manda dispose con el código de la hoja', /action=dispose/.test(envio) && /call_id=424/.test(envio) && /code=010101/.test(envio), envio);
      check('y los tres niveles para guardar en el lead',
        /n1=CONTACTADO/.test(envio) && /n2=SEGUIMIENTO/.test(envio) && /n3=INTERESADO/.test(envio), envio);

      setTimeout(() => {
        check('anuncia la siguiente llamada', /Siguiente llamada en/.test(txt('ctlLead')), txt('ctlLead'));
        // Cerrado el ciclo, la cascada vuelve a quedar inerte y limpia: lo
        // elegido era de la llamada que acaba de cerrarse.
        check('la cascada se apaga al cerrarse el ciclo', $('tip0').disabled === true
          && txt('tipOut') === 'Sin llamada que tipificar.', txt('tipOut'));
        check('y no conserva lo elegido', S.tip.every(v => v === null), JSON.stringify(S.tip));
        // El discador confirmó: no puede salir un aviso de fallo. Vaciar la
        // cascada al cerrarse el ciclo llegó a romper el registro posterior y
        // eso se anunciaba como «no se pudo contactar el discador».
        check('no avisa de ningún fallo', $('layer').hidden === true, txt('layer'));
        const g = S.gestiones.slice(-1)[0];
        check('la gestión queda en el expediente', !!g && g.contacto === 424, JSON.stringify(g || null));
        check('con los tres niveles que eligió el agente',
          !!g && g.n1 === 'CONTACTADO' && g.n2 === 'SEGUIMIENTO' && g.n3 === 'INTERESADO', JSON.stringify(g || null));
        check('y como interacción del cliente tipificado',
          S.interacciones.some(x => x.tipo === 'gestion' && x.contacto === 424));
        check('vuelve a permitir marcar', $('ctlDial').disabled === false);
        // La llamada tipificada deja de viajar en el sondeo: su fila debe
        // quedar marcada igual, sin volver a consultar el historial.
        const fila = filas().find(f => f.textContent.includes('JUAN PEREZ'));
        check('la cartera deja de mostrarla «sin tipificar»', !!fila && !/SIN TIP/.test(fila.textContent), fila && fila.textContent.replace(/\s+/g,' ').trim());
        check('y muestra el código aplicado', !!fila && /010101/.test(fila.textContent));
        $('listTabs').children[1].dispatch('click');
        check('sale de la pestaña «Sin tipificar»', !filas().some(f => f.textContent.includes('JUAN PEREZ')));
        $('listTabs').children[0].dispatch('click');

        console.log('\n== Si el discador rechaza, no se registra ==');
        sandbox.__estado = Object.assign({}, VOLATIL, {pending:{call_id:425, phone:'9', campaign:'c'},
          dispositions:[{code:'01', label:'contacto'}]});
        sandbox.__resp = {message:'', error:'', state:sandbox.__estado};
        accion('state').then(() => {
          const antes = S.gestiones.length;
          elegir(0, 'CONTACTADO'); elegir(1, 'SEGUIMIENTO'); elegir(2, 'INTERESADO');
          sandbox.__resp = {message:'', error:'Llamada ya tipificada.', state:sandbox.__estado};
          $('btnGrabar').dispatch('click');
          setTimeout(() => {
            check('avisa del rechazo', $('layer').hidden === false && /ya tipificada/i.test(txt('layer')));
            check('no registra la gestión', S.gestiones.length === antes);
            btnTxt('Aceptar').dispatch('click');
            cerrar();
          }, 30);
        });
      }, 30);
    });
  });
}, 60);

function cerrar() {
  console.log('\n== Volver a llamar a un cliente ==');
  sandbox.__estado = Object.assign({}, VOLATIL, {selectedCampaign:55});
  sandbox.__resp = {message:'', error:'', state:sandbox.__estado};
  accion('state').then(() => {
    sandbox.__fn.abrirCliente(423); sandbox.__fn.pintarDetalle();
    const llamar = $('detBody').querySelectorAll('.ch')[0];
    check('el botón Llamar está activo sin llamada en curso', llamar.disabled === false, llamar.title);
    sandbox.__resp = {message:'Rellamada #500 enviada. Contesta en tu softphone.', error:'', state:sandbox.__estado};
    llamar.dispatch('click');
    const env = (sandbox.__acciones || []).slice(-1)[0];
    check('manda redial con campaña y lead', /action=redial/.test(env) && /campaign_id=55/.test(env) && /lead_id=267/.test(env), env);

    // Con tipificación pendiente no debe dejar marcar
    sandbox.__estado = Object.assign({}, VOLATIL, {selectedCampaign:55, pending:{call_id:423, phone:'9', campaign:'c'}});
    sandbox.__resp = {message:'', error:'', state:sandbox.__estado};
    accion('state').then(() => {
      sandbox.__fn.pintarDetalle();
      const b = $('detBody').querySelectorAll('.ch')[0];
      check('bloqueado con tipificación pendiente', b.disabled === true, b.title);

      // Con llamada viva, el botón pasa a Colgar
      sandbox.__estado = Object.assign({}, VOLATIL, {selectedCampaign:55,
        lead:{call_id:423, lead_id:267, phone:'981641706', campaign:'carsa0001', campaign_id:55,
              name:'alexander', at:'', ended:false, bridged:true, disposition:null, status:'NEW', variables:{}}});
      sandbox.__resp = {message:'', error:'', state:sandbox.__estado};
      accion('state').then(() => {
        const b2 = $('detBody').querySelectorAll('.ch')[0];
        check('con llamada viva ofrece Colgar', /Colgar/.test(b2.textContent) && b2.disabled === false, b2.textContent);
        seguir();
      });
    });
  });
}

function seguir() {
  console.log('\n== El árbol viene de la campaña ==');
  const arbol = sandbox.__arbol();
  check('se reconstruye desde los códigos', arbol.length === 2, arbol.map(n => n.label).join(' | '));
  check('nivel 2 cuelga de su padre', arbol[0].hijos.length === 2, arbol[0].hijos.map(n => n.label).join(' | '));
  check('nivel 3 también', arbol[0].hijos[0].hijos.length === 2);
  check('cada nodo conserva su código', arbol[0].hijos[0].hijos[0].code === '010101', arbol[0].hijos[0].hijos[0].code);
  check('la marca Venta define el desenlace', arbol[0].hijos[1].hijos[0].kind === 'sale');
  check('la marca Reintenta define rellamada', arbol[0].hijos[0].hijos[1].kind === 'recall');
  check('sin árbol la cascada queda vacía', sandbox.__fn.armarArbol([]).length === 0);

  console.log('\n== Resumen del turno con datos reales ==');
  const php = fs.readFileSync(path.join(WEB, 'modules/agc/crm.php'), 'utf8');
  check('la campaña envía la marca de contacto', /'contact' => \(bool\)\$d\['is_contact'\]/.test(php));
  check('sin KPI inventados en la plantilla', !/Reuniones/.test(php) && !/performance hoy/i.test(php));
  check('la barra ya no se declara maqueta de datos', !/datos ficticios/.test(php));

  const pares = $('perfKpis').children;
  const kpi = {};
  for (let i = 0; i + 1 < pares.length; i += 2) kpi[pares[i].textContent.trim()] = pares[i + 1].textContent.trim();
  const total = S.contactos.length;
  const tip = S.contactos.filter(c => c.disposition);
  const cont = tip.filter(c => (ARBOL55.find(n => n.code === c.disposition) || {}).contact).length;
  check('cuenta las llamadas reales del agente', kpi['Llamadas'] === String(total), JSON.stringify(kpi));
  check('contactados sale de la marca de la tipificación', kpi['Contactados'] === String(cont));
  check('ventas también', kpi['Ventas'] === '0');
  check('y lo que falta tipificar', kpi['Sin tipificar'] === String(total - tip.length),
    kpi['Sin tipificar'] + ' vs ' + (total - tip.length) + ' (total ' + total + ')');
  check('ningún KPI quedó en la maqueta', !('Reuniones' in kpi) && !('Contactos' in kpi), Object.keys(kpi).join(','));
  check('el anillo mide contactabilidad, no una meta inventada',
    txt('perfRatio') === Math.round(cont / total * 100) + '%', txt('perfRatio'));

  const desglose = $('perfTip').querySelectorAll('.perf-row')
    .map(r => ({et:r.querySelector('span').textContent.trim(), n:r.querySelector('b').textContent.trim()}));
  const resumen = desglose.map(d => d.et + '=' + d.n).join(' / ');
  check('desglosa cada tipificación usada hoy', desglose.length === new Set(tip.map(c => c.disposition)).size, resumen);
  check('con el nombre del árbol, no el código', desglose.some(d => d.et === 'INTERESADO' && d.n === '1'), resumen);
  check('y los códigos viejos no se pierden', desglose.some(d => d.et === '01'), resumen);
  check('la más usada va primero', desglose[0].n >= desglose[desglose.length - 1].n, resumen);
  check('el resumen no consultó la base', (sandbox.__acciones || []).filter(a => /action=bootstrap/.test(a)).length === 1);

  console.log('\n== Transcripción en vivo (área de pruebas) ==');
  const ZVT = sandbox.window.ZVT;
  const lineas = () => $('trxBody').querySelectorAll('.trx-line');
  const ultima = () => { const l = lineas(); return l.length ? l[l.length - 1] : null; };
  check('el panel existe', !!$('trx') && !!$('trxBody'));
  check('vive fuera del expediente, que se repinta entero', $('trx').closest('.det-body') === null);
  // Va al costado de la ficha, no debajo: ambos cuelgan del mismo reparto de
  // ancho. Si alguien devuelve la transcripción al pie, esto lo marca.
  check('comparte la fila con el expediente', !!$('trx').closest('.det-split')
    && $('trx').closest('.det-split') === $('detBody').closest('.det-split'));
  check('el CSS reparte el ancho entre los dos',
    /\.det-split\{[^}]*grid-template-columns/.test(
      fs.readFileSync(path.join(WEB, 'modules/agc/crm.css'), 'utf8')));
  check('la API queda expuesta', !!ZVT && typeof ZVT.linea === 'function' && typeof ZVT.parcial === 'function');
  check('nadie escribe en el recuadro por su cuenta', ZVT.volcado().length === 0,
    JSON.stringify(ZVT.volcado().map(l => l.texto)));

  // El sistema externo vuelca texto crudo: nada del CRM debe pisárselo.
  $('trxBody').textContent = 'texto que escribe el sistema externo';
  sandbox.__fn.pintarDetalle();
  check('el texto escrito desde fuera sobrevive al expediente',
    /sistema externo/.test(txt('trxBody')), txt('trxBody'));
  $('trxBody').textContent = '';
  check('el aviso de vacío no es un nodo, es CSS', $('trxBody').childNodes.length === 0);

  ZVT.limpiar();
  check('limpiar deja el recuadro vacío', lineas().length === 0 && $('trxBody').childNodes.length === 0);
  ZVT.linea('buenos días, le habla Miguel de carsa');
  check('una línea del agente se pinta', lineas().length === 1 && /le habla Miguel/.test(txt('trxBody')));
  check('etiquetada como agente', ultima().querySelector('.trx-quien').textContent === 'AG');
  check('con hora', /^\d\d:\d\d:\d\d$/.test(ultima().querySelector('.trx-hora').textContent));
  ZVT.linea('¿con quién hablo?', 'cliente');
  check('el cliente se distingue', ultima().querySelector('.trx-quien').textContent === 'CLI'
    && ultima().matches('.trx-line--cliente'));

  ZVT.parcial('estoy diciendo esto');
  ZVT.parcial('estoy diciendo esto ahora');
  check('la hipótesis en curso no se acumula', lineas().length === 3, lineas().length + '');
  check('y se marca como parcial', ultima().matches('.trx-line--parcial') && /ahora/.test(ultima().textContent));
  ZVT.linea('estoy diciendo esto ahora mismo');
  check('al cerrarse reemplaza a la parcial', lineas().length === 3 && !ultima().matches('.trx-line--parcial'),
    lineas().length + '');

  ZVT.estado('escuchando');
  check('el indicador de cabecera se puede mover', txt('trxEstado') === 'escuchando');

  const puente = (sandbox.window._l.message || [])[0];
  check('hay puente postMessage', typeof puente === 'function');
  puente({data:{zvt:{texto:'llega desde fuera', quien:'cliente'}}});
  check('y entrega lo que recibe', /llega desde fuera/.test(txt('trxBody')) && ultima().matches('.trx-line--cliente'));
  puente({data:{sin:'zvt'}});
  check('ignora mensajes ajenos', lineas().length === 4, lineas().length + '');

  const antes = lineas().length;
  sandbox.__fn.pintarDetalle();
  check('sobrevive a un repintado del expediente', lineas().length === antes, lineas().length + ' vs ' + antes);

  ZVT.limpiar();
  for (let i = 0; i < 405; i++) ZVT.linea('línea ' + i);
  check('recorta lo viejo en vez de crecer sin fin', ZVT.volcado().length === 400, String(ZVT.volcado().length));
  check('y conserva lo último dicho', /línea 404/.test(txt('trxBody')));
  check('el CSS declara el aviso de vacío y respeta saltos de línea',
    (() => { const c = fs.readFileSync(path.join(WEB, 'modules/agc/crm.css'), 'utf8');
             return /\.trx-body:empty::before/.test(c) && /\.trx-body\{[^}]*white-space:pre-wrap/.test(c); })());
  ZVT.limpiar();

    console.log('\n== Menú lateral ==');
  check('el resumen del turno vive en el menú',
    !!$('side') && $('perfDonut').closest('.side') !== null);
  check('y ya no cuelga de la columna de trabajo',
    $('perfDonut').closest('.col--work') === null);
  check('cerrar sesión está en el menú', $('exit').closest('.side') !== null);
  check('el contador refleja la cartera entera',
    txt('sideCount') === String(S.contactos.length), txt('sideCount'));
  // Plegar es sólo comodidad de pantalla: ni consulta ni dato del agente.
  const app = document.getElementById('app');
  check('el menú arranca desplegado', !app.classList.contains('is-plegado'));
  $('sideToggle').dispatch('click', {});
  check('y se pliega', app.classList.contains('is-plegado')
    && $('sideToggle').getAttribute('aria-expanded') === 'false');
  $('sideToggle').dispatch('click', {});
  check('y vuelve', !app.classList.contains('is-plegado'));
  check('plegar no consulta al discador',
    (sandbox.__acciones || []).filter(a => /sideToggle|menu/.test(a)).length === 0);

console.log('\n== Salida ==');
  $('exit').dispatch('click');
  check('"Salir" va al logout del agente', salida.href === 'logout.php');

  console.log('\n== Archivos del entorno ==');
  for (const r of ['modules/agc/crm.css', 'modules/agc/crm.js', 'modules/agc/crm_wa_session.php',
                   'modules/agc/login_view.php', 'modules/agc/logout.php'])
    check('existe ' + r, fs.existsSync(path.join(WEB, r)));

  console.log('\n' + '='.repeat(56));
  console.log(ok.length + ' OK, ' + fallos.length + ' fallos');
  if (fallos.length) { console.log('FALLOS:\n- ' + fallos.join('\n- ')); process.exit(1); }
  console.log('RESULTADO: OK');
}
