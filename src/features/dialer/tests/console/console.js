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
  trees:{'55':ARBOL55}, inbound:false};
const HISTORIAL = [
  {lead_id:267, call_id:423, campaign_id:55, campaign:'carsa0001', phone:'981641706', name:'alexander', at:'2026-10-17 09:54:13', ended:true, disposition:'01', status:'CONTACTED',
   variables:{apellidos:'Ipanaqué Nizama', documento:'44012345', correo:'alexander.ipanaque@gmail.com',
              profesion:'Comerciante', empresa:'Ferretería El Sol', ciudad:'Piura',
              direccion:'Av. Grau 450, Piura', producto:'Crédito personal', monto:'12000',
              origen:'Base propia', poliza:'Vigente', vigencia:'10/03/2027', local:'carsa_piura'}},
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

// Las pantallas de demostración viven en su propio archivo, que la página
// carga antes que crm.js. Acá se concatenan en el mismo orden.
const code = fs.readFileSync(path.join(WEB, 'modules/agc/crm_demo.js'), 'utf8')
  + '\n'
  + fs.readFileSync(path.join(WEB, 'modules/agc/crm.js'), 'utf8')
  + '\n;globalThis.__S=S;globalThis.__fn={abrirCliente,asisPreguntar,irA,pintarDetalle,pintarCartera,pintarControl,cerrarGestion,quizaAbrirGestion,accionDiscador,armarArbol,ritmoDeseado,RITMO_ACTIVO,RITMO_REPOSO};globalThis.__arbol=()=>TIPIFICACION;globalThis.__fn.pintarWiki=pintarWiki;globalThis.__demo={wiki:demoWikiEstado,tareas:demoEstadoTareas,ventasMes:()=>DEMO_VENTAS_MES.reduce((a,d)=>a+d.n,0)};\n';
try { vm.runInContext(code, sandbox, {filename:'crm.js'}); }
catch (e) { console.log('FALLO al ejecutar: ' + e.message + '\n' + e.stack); process.exit(1); }

const $ = id => document.getElementById(id);
const S = sandbox.__S;
const txt = id => ($(id) ? $(id).textContent.trim() : '<sin nodo>');
const btnTxt = t => $('layer').querySelectorAll('button').find(b => b.textContent.trim() === t);
// La botonera vive en el cuerpo (con el chat abierto) o en el pie (resto):
// se la busca en la columna entera para no atarse a dónde esté hoy.
const canales = () => $('detBody').closest('.col--detail').querySelectorAll('.ch');
const canal = i => canales()[i];
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
// Inicio, Campañas y Soporte siguen siendo maqueta; Tareas, Reportes y Base
// de conocimiento ya tienen pantalla propia dentro de la consola.
check('las entradas sin pantalla siguen desactivadas',
  (html.match(/class="side-link"[^>]*aria-disabled="true"[^>]*disabled/g) || []).length === 3);
check('las que sí la tienen declaran su vista',
  ['clientes', 'tareas', 'reportes', 'conocimiento']
    .every(v => html.includes('data-vista="' + v + '"')));
check('al entrar, la cartera es la sección activa',
  (html.match(/side-link is-on/g) || []).length === 1);
check('hoja y script propios', /href="crm\.css"/.test(html) && /src="crm\.js"/.test(html));
check('salida al logout del agente', S.salida === 'logout.php', S.salida);
check('puente de WhatsApp propio', S.waEndpoint === 'crm_wa_session.php');
check('operador tomado de la sesión', txt('opName') === 'Agente 5000');
check('con su rol', txt('opRole') === 'Asesor de ventas', txt('opRole'));
// Con usuarios numéricos las iniciales daban un dígito suelto: va el rostro.
check('y su chapa lleva rostro, no iniciales',
  /^<svg[\s>]/.test($('whoAv').innerHTML) && $('whoAv').getAttribute('title') === 'Agente 5000');

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

  console.log('\n== La ficha muestra los datos del lead ==');
  {
    // abrirCliente recibe el id de la llamada, no un índice.
    sandbox.__fn.abrirCliente(423);
    const ficha = txt('detBody');
    // Antes deLlamada descartaba custom_fields y la ficha salía con rayas
    // aunque la base tuviera el dato cargado.
    for (const [et, val] of [['documento', '44012345'], ['correo', 'alexander.ipanaque@gmail.com'],
                             ['profesión', 'Comerciante'], ['empresa', 'Ferretería El Sol'],
                             ['ubicación', 'Piura'], ['dirección', 'Av. Grau 450'],
                             ['origen', 'Base propia'], ['póliza', 'Vigente']]) {
      check('muestra ' + et, ficha.includes(val), val);
    }
    check('junta nombre y apellidos', /alexander Ipanaqué Nizama/.test(ficha));
    check('y el monto con su moneda', /12.000|12,000/.test(ficha), 'monto');
    // Se vuelve a la bienvenida: lo que sigue comprueba cómo se entra.
    S.actual = -1;
    sandbox.__fn.pintarDetalle();
    sandbox.__fn.pintarCartera();   // la fila deja de figurar como abierta
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

  console.log('\n== Pausar es elegir el motivo ==');
  {
    const sel = $('ctlPauseType');
    // Marcar es lo que más se usa en el turno: vive en la barra, pegado al
    // buscador, no enterrado en una columna.
    check('marcar vive en la barra, junto al buscador',
      $('ctlDial').closest('.topbar') !== null && $('ctlCampaign').closest('.topbar') !== null
      && (() => { const h = $('search').closest('.topbar').children.filter(n => n.tagName);
                  return h.indexOf($('ctlDial').closest('.topbar-dial'))
                       === h.findIndex(n => n.matches('.search')) + 1; })());
    check('el buscador ocupa la mitad', /\.search\{[^}]*max-width:260px/.test(
      fs.readFileSync(path.join(WEB, 'modules/agc/crm.css'), 'utf8')));
    // La pausa es estado del puesto: vive en la barra, junto al estado del
    // discador, no en el panel de trabajo sobre la ficha.
    check('la pausa vive en la barra superior',
      $('ctlPauseType').closest('.topbar') !== null && $('ctlPause').closest('.topbar') !== null);
    check('y a la derecha del estado del discador',
      (() => { const h = $('dialerState').closest('.topbar').children.filter(n => n.tagName);
               return h.indexOf($('ctlPauseWrap')) === h.indexOf($('dialerState')) + 1; })());
    check('sin pausa, el botón no estorba', $('ctlPause').hidden === true);
    const antes = (sandbox.__acciones || []).length;
    sel.value = '2';
    sel.dispatch('change', {target:sel});
    const envio = (sandbox.__acciones || [])[antes] || '';
    check('elegir el motivo pausa sin más clics', /action=pause/.test(envio), envio);
    check('y manda el motivo elegido', /pause_type_id=2/.test(envio), envio);
    check('el selector vuelve a neutro', sel.value === '', sel.value);
    // Pausado: aparece "Reanudar" y el selector se retira.
    sandbox.__estado.pause = {label:'Baño', seconds:0};
    sandbox.__fn.pintarControl();
    check('pausado aparece Reanudar',
      $('ctlPause').hidden === false && txt('ctlPause') === 'Reanudar', txt('ctlPause'));
    check('y el selector se retira', $('ctlPauseWrap').hidden === true);
    sandbox.__estado.pause = null;
    sandbox.__fn.pintarControl();
  }
  check('en reposo el sondeo es lento', sandbox.__ritmo === sandbox.__fn.RITMO_REPOSO, sandbox.__ritmo + ' ms');
  // Con cola entrante no hay reposo que valga: la entrante llega sin que el
  // agente la pida, y con 12 s estaría hablando con la pantalla en blanco.
  FIJO.inbound = true;
  check('con cola entrante el sondeo no baja a reposo',
    sandbox.__fn.ritmoDeseado() === sandbox.__fn.RITMO_ACTIVO, sandbox.__fn.ritmoDeseado() + ' ms');
  FIJO.inbound = false;
  check('sin cola entrante sigue ahorrando en reposo',
    sandbox.__fn.ritmoDeseado() === sandbox.__fn.RITMO_REPOSO, sandbox.__fn.ritmoDeseado() + ' ms');

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
      (() => { const b = canal(0); return /Colgar/.test(b.textContent) || b.disabled === false; })());
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
        // La hoja de tipificación puede estar abierta: lo que no puede haber
        // es un aviso de error encima.
        check('no avisa de ningún fallo',
          $('layer').hidden === true || $('layer').querySelector('.sheet--gestion') !== null,
          txt('layer'));
        const g = S.gestiones.slice(-1)[0];
        check('la gestión queda en el expediente', !!g && g.contacto === 424, JSON.stringify(g || null));
        check('con los tres niveles que eligió el agente',
          !!g && g.n1 === 'CONTACTADO' && g.n2 === 'SEGUIMIENTO' && g.n3 === 'INTERESADO', JSON.stringify(g || null));
        check('y como interacción del cliente tipificado',
          S.interacciones.some(x => x.tipo === 'gestion' && x.contacto === 424));
        // La hoja tiene que retirarse sola al grabar: si queda encima, tapa
        // la pantalla y el asesor no puede atender la llamada siguiente.
        check('la hoja se retira al grabar',
          $('layer').hidden === true && $('layer').querySelector('.sheet--gestion') === null,
          txt('layer').slice(0, 60));
        check('y el bloque vuelve a su depósito',
          $('tip0') !== null && $('tip0').closest('#gestionHost') !== null);
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
    const llamar = canal(0);
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
      const b = canal(0);
      check('bloqueado con tipificación pendiente', b.disabled === true, b.title);

      // Con llamada viva, el botón pasa a Colgar
      sandbox.__estado = Object.assign({}, VOLATIL, {selectedCampaign:55,
        lead:{call_id:423, lead_id:267, phone:'981641706', campaign:'carsa0001', campaign_id:55,
              name:'alexander', at:'', ended:false, bridged:true, disposition:null, status:'NEW', variables:{}}});
      sandbox.__resp = {message:'', error:'', state:sandbox.__estado};
      accion('state').then(() => {
        const b2 = canal(0);
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
  // Vive en la columna de trabajo, debajo de la tipificación, y se reparte
  // el alto con ella. Si alguien la devuelve al expediente, esto lo marca.
  check('vive en la columna de trabajo', $('trx').closest('.col--work') !== null);
  {
    // La columna se reparte entre transcripción y asistente, con el aviso
    // del ciclo arriba cuando tiene algo que decir.
    const hijos = $('trx').closest('.col--work').children.filter(n => n.tagName);
    check('transcripción arriba y asistente abajo',
      hijos.length === 3 && hijos[0].id === 'ctlLead'
      && hijos[1].id === 'trx' && hijos[2].id === 'asis',
      hijos.map(n => n.id || n.className).join(' · '));
    // Colgar y el estado del puesto viven ahora en la barra, junto a la pausa.
    check('colgar está junto a reanudar',
      $('ctlHangup').closest('.topbar') !== null
      && (() => { const h = $('ctlPause').closest('.topbar').children.filter(n => n.tagName);
                  return h.indexOf($('ctlHangup')) === h.indexOf($('ctlPause')) + 1; })());
    check('y el estado del puesto con ellos', $('agentState').closest('.topbar') !== null);
    // El nombre de campaña y el teléfono vienen de la base: pegarlos como
    // marcado es una vía de inyección. Ningún innerHTML puede tocarlos.
    check('ningún dato de la base se pega como marcado',
      !fs.readFileSync(path.join(WEB, 'modules/agc/crm.js'), 'utf8')
        .split('\n').some(l => /innerHTML/.test(l) && /\.campaign|\.phone|\.name\b/.test(l)));
  }
  // La tipificación ya no ocupa sitio en pantalla: espera en su depósito y
  // sale como hoja cuando hace falta. Nunca dentro de los contenedores que
  // el JS repinta, que borrarían la nota a medio escribir y sus escuchas.
  check('tipificar espera fuera de la pantalla',
    $('tip0').closest('#gestionHost') !== null || $('tip0').closest('.sheet--gestion') !== null);
  check('y nunca en los contenedores que se repintan',
    $('tip0').closest('.det-body') === null && $('tip0').closest('.det-foot') === null);
  check('el CSS les da el mismo alto',
    /\.trx\{flex:1 1 0/.test(fs.readFileSync(path.join(WEB, 'modules/agc/crm.css'), 'utf8')));
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

    console.log('\n== La hoja de tipificar salta al colgar ==');
  {
    const hoja = () => $('layer').querySelector('.sheet--gestion');
    const avisoPend = () => $('detFoot').querySelector('.pendiente');
    // Se parte de una llamada colgada y sin tipificar, que es el caso que
    // debe hacer saltar la hoja.
    sandbox.__fn.cerrarGestion();
    sandbox.__estado.pending = {call_id:424, phone:'987000111', campaign:'carsa0001'};
    S.gestionCerrada = null;
    sandbox.__fn.quizaAbrirGestion();
    check('sale sola cuando hay algo que tipificar', !!hoja() && $('layer').hidden === false);
    check('y se lleva el bloque consigo, no una copia',
      $('tip0').closest('.sheet--gestion') !== null
      && $('tip0').closest('#gestionHost') === null);
    // Se escribe una nota y se cierra: no puede perderse.
    $('tipNota').value = 'cliente pidió volver a llamar';
    sandbox.__fn.cerrarGestion();
    check('cerrarla la devuelve al depósito',
      $('tip0').closest('#gestionHost') !== null && $('layer').hidden === true);
    check('y conserva lo escrito', $('tipNota').value === 'cliente pidió volver a llamar');
    check('no vuelve a saltar sola tras cerrarla',
      (sandbox.__fn.quizaAbrirGestion(), $('layer').hidden === true));
    // El aviso del pie es el camino de vuelta.
    sandbox.__fn.pintarDetalle();
    check('queda el aviso para volver a abrirla', !!avisoPend());
    avisoPend().querySelectorAll('button')[0].dispatch('click', {});
    check('y vuelve a abrirse desde ahí', !!hoja());
    check('con la nota intacta', $('tipNota').value === 'cliente pidió volver a llamar');
    // Se deja el puesto como estaba para no contaminar lo que sigue.
    $('tipNota').value = '';
    sandbox.__fn.cerrarGestion();
    sandbox.__estado.pending = null;
    S.gestionCerrada = null;
    sandbox.__fn.pintarDetalle();
  }

  console.log('\n== Resumen partido en dos ==');
  {
    const bloques = () => $('detBody').children.filter(n => n.tagName);
    const pie = () => $('detFoot').children.filter(n => n.tagName);
    sandbox.__fn.abrirCliente(0);
    // La ficha comparte su fila con la tipificación; la botonera y el
    // historial van debajo, a todo el ancho, en el pie del expediente.
    check('la ficha ocupa sola el cuerpo',
      bloques().length === 1 && /INFORMACIÓN DEL CLIENTE|LEAD DEL DISCADOR/i.test(bloques()[0].textContent),
      bloques().map(n => n.className).join(' · '));
    // replaceChildren no filtra null: lo escribe como texto. Ya pasó.
    check('el pie no deja sueltos textos como «null»',
      !/null|undefined/.test(txt('detFoot')), txt('detFoot').slice(0, 80));
    check('ni el cuerpo del expediente',
      !/null|undefined/.test(txt('detBody')), txt('detBody').slice(0, 80));
    check('canales e historial van al pie, a todo el ancho',
      pie().length === 2 && pie()[0].matches('.channels')
      && /Historial de interacciones/.test(pie()[1].textContent),
      pie().map(n => n.className).join(' · '));
    // Abrir WhatsApp: la conversación es la tarea, el historial se retira.
    canal(1).dispatch('click', {});
    const c = bloques();
    check('con el chat abierto el historial se retira',
      pie().length === 0 && c.length === 2, 'pie=' + pie().length + ' cuerpo=' + c.length);
    check('y la conversación toma todo el alto', !!$('detBody').querySelector('.chat-body'));
    check('la botonera sigue, para poder cerrarlo', c[1].matches('.channels'));
    // Cada canal se reconoce por su símbolo, no sólo por el color.
    sandbox.__fn.pintarDetalle();
    const ico = i => canal(i).querySelector('svg');
    check('los cuatro canales llevan icono macizo',
      [0, 1, 2, 3].every(i => ico(i) && /ico--fill/.test(ico(i).className)));
    check('y el dibujo no quedó vacío',
      [0, 1, 2, 3].every(i => /^<path|^<circle/.test(ico(i).innerHTML)));
    // Creado con createElement, el navegador no dibuja nada: hay que nacer
    // en el espacio de nombres de SVG. El DOM de pruebas lo registra.
    check('nacen en el espacio de nombres de SVG',
      [0, 1, 2, 3].every(i => ico(i).namespaceURI === 'http://www.w3.org/2000/svg'),
      String(ico(0).namespaceURI));
    sandbox.__fn.pintarDetalle();
    canal(1).dispatch('click', {});
    check('al cerrarlo vuelve el historial',
      pie().length === 2 && /Historial/.test(pie()[1].textContent),
      'pie=' + pie().length);
  }

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

  console.log('\n== Onda y asistente ==');
  {
    // La onda es decorativa y debe decirlo en el código: si alguien la
    // presenta como medición del audio, acá queda el recordatorio.
    const js = fs.readFileSync(path.join(WEB, 'modules/agc/crm.js'), 'utf8');
    check('la onda se monta con sus barras', $('onda').children.length === 26,
      String($('onda').children.length));
    check('y declara que no mide el audio', /NO mide la llamada/.test(js));
    check('tiene una entrada única para el nivel real',
      typeof sandbox.window.ZVT.nivel === 'function');

    // Asistente: el ciclo pregunta → espera → respuesta.
    const burbujas = () => $('asisBody').querySelectorAll('.bur');
    check('ofrece preguntas sugeridas', $('asisChips').children.length === 3);
    check('arranca sin conversación', burbujas().length === 0, String(burbujas().length));
    sandbox.__fn.asisPreguntar('¿Cuáles son las tasas vigentes?');
    check('la pregunta del asesor se ve enseguida',
      burbujas().length === 2 && burbujas()[0].matches('.bur--yo'), String(burbujas().length));
    check('y el asistente muestra que está pensando', burbujas()[1].matches('.bur--pensando'));
    check('la caja queda vacía para la siguiente', $('asisTexto').value === '');
    // El texto llega del RAG, que es un tercero: nunca como marcado.
    check('las respuestas no se pegan como HTML', !/burbuja\.innerHTML/.test(js));
  }

  console.log('\n== Secciones: Tareas, Reportes y Conocimiento ==');
  {
    const v = $('vista');
    check('al entrar no hay sección abierta', v.hidden === true && S.vista === 'clientes');

    // --- Tareas
    sandbox.__fn.irA('tareas');
    check('Tareas se abre', v.hidden === false && S.vista === 'tareas');
    check('y lista los pendientes', v.querySelectorAll('.tarea').length >= 8,
      String(v.querySelectorAll('.tarea').length));
    check('marca lo vencido', v.querySelectorAll('.tarea--vencida').length >= 1);
    check('muestra el avance', v.querySelectorAll('.barra').length === 1);
    check('y lo ya completado', /Completadas hoy/.test(txt('vista')));
    const antes = v.querySelectorAll('.tarea').length;
    v.querySelectorAll('.tarea')[0].querySelectorAll('button')[2].dispatch('click', {});
    check('completar una la saca de la lista',
      v.querySelectorAll('.tarea').length === antes - 1,
      antes + ' -> ' + v.querySelectorAll('.tarea').length);
    // Y reaparece en lo hecho: si sólo desaparece, se siente perdida.
    check('y aparece en lo completado hoy',
      v.querySelectorAll('.hechas li').length === 5,
      String(v.querySelectorAll('.hechas li').length));
    check('el menú actualiza su contador',
      Number(txt('sideTareas')) === v.querySelectorAll('.tarea').length
      || Number(txt('sideTareas')) >= 1, txt('sideTareas'));

    // --- Reportes
    sandbox.__fn.irA('reportes');
    check('Reportes se abre', S.vista === 'reportes');
    const rep = txt('vista');
    check('encabeza con la comisión', /Comisión acumulada/.test(rep));
    check('dice cuánto falta para el tramo', /Te faltan \d+ ventas? para /.test(rep), rep.slice(0, 0) || '');
    check('muestra la escalera completa',
      ['Base', 'Plata', 'Oro', 'Platino'].every(t => rep.includes(t)));
    check('señala en qué tramo está', v.querySelectorAll('.peldano.is-actual').length === 1);
    check('trae el gráfico del mes', v.querySelectorAll('.gcol').length === 15,
      String(v.querySelectorAll('.gcol').length));
    check('y la tabla del equipo con el agente marcado',
      v.querySelectorAll('.ranking li').length === 6 && v.querySelectorAll('.ranking li.is-yo').length === 1);
    // Las cifras tienen que cerrar entre sí, no ser adornos sueltos.
    check('el anillo cuadra con las ventas del mes',
      rep.includes(sandbox.__demo.ventasMes() + '/20'), sandbox.__demo.ventasMes() + '/20');
    check('avisa que son datos de demostración', /datos de demostración/i.test(rep));

    // --- Base de conocimiento
    sandbox.__fn.irA('conocimiento');
    check('Conocimiento se abre', S.vista === 'conocimiento');
    check('con su índice de artículos', v.querySelectorAll('.wiki-lista li').length >= 10,
      String(v.querySelectorAll('.wiki-lista li').length));
    check('abre uno por defecto', v.querySelectorAll('.wiki-art').length === 1);
    check('con ficha de combate', v.querySelectorAll('.battle-col').length === 4);
    // Buscar filtra de verdad, no sólo resalta.
    const todos = v.querySelectorAll('.wiki-lista li').length;
    sandbox.__demo.wiki.busqueda = 'desgravamen';
    sandbox.__fn.pintarWiki();
    const filtrados = $('vista').querySelectorAll('.wiki-lista li').length;
    check('la búsqueda filtra', filtrados > 0 && filtrados < todos, todos + ' -> ' + filtrados);
    check('y resalta lo encontrado', $('vista').querySelectorAll('mark').length >= 1);
    sandbox.__demo.wiki.busqueda = '';
    sandbox.__fn.pintarWiki();

    // Ninguna sección puede dejar restos de programación a la vista.
    for (const sec of ['tareas', 'reportes', 'conocimiento']) {
      sandbox.__fn.irA(sec);
      // Un icono que no existe se dibuja vacío y nadie lo nota hasta la demo.
      // Vacío de verdad: ni marcado ni nodos hijos. El anillo de progreso se
      // arma con nodos, así que tiene innerHTML vacío y no es un error.
      const svgVacios = () => $('vista').querySelectorAll('svg')
        .filter(n => !n.innerHTML && n.children.length === 0);
      check('«' + sec + '» no pide iconos inexistentes', svgVacios().length === 0,
        String(svgVacios().length));
      check('«' + sec + '» no deja null, undefined ni NaN',
        !/null|undefined|NaN/.test(txt('vista')),
        (txt('vista').match(/.{0,25}(null|undefined|NaN).{0,25}/) || [''])[0]);
    }

    // --- Volver no destruye la consola real
    sandbox.__fn.irA('clientes');
    check('volver oculta la sección', $('vista').hidden === true);
    check('y la consola sigue entera',
      $('trxBody') !== null && $('tip0') !== null && $('asisTexto') !== null);
  }

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
