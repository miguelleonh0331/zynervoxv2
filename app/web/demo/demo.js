'use strict';
/* Motor del recorrido y guion.

   Esto NO es la consola. Es una réplica que se maneja sola, para mostrarle
   a alguien que nunca la vio cómo trabaja un asesor. No hay servidor, ni
   base de datos, ni discador: cada cosa que pasa está escrita más abajo.

   El recorrido avanza solo. Los controles existen para pausar y repetir,
   pero nadie necesita tocarlos.                                           */

/* ====================================================================== */
/*  DATOS DE LA ESCENA                                                    */
/* ====================================================================== */

const CLIENTE = {
  id:4821, leadId:323, nombre:'Betty Ramírez Nizama', telefono:'980910856',
  documento:'44218905', correo:'betty.ramirez@gmail.com',
  profesion:'Comerciante', empresa:'Bodega Santa Rosa', ubicacion:'Piura',
  direccion:'Av. Grau 450, Piura', producto:'Compra de deuda',
  monto:12000, moneda:'S/', origen:'Base propia', campania:'carsa0001',
  base:'carsa_piura', poliza:'Sin póliza', vigencia:'—', prioridad:'Alta',
  estadoLead:'CONTACTED'
};

/* Cliente ya trabajado, para mostrar el expediente en el recorrido inicial.
   No puede ser el mismo que llama después: abrir su ficha antes le quitaría
   todo el efecto a «la ficha se abre sola» cuando entra la llamada. */
const CLIENTE_PREVIO = {
  id:4820, nombre:'Héctor Chávez Olaya', telefono:'987130189',
  documento:'43887120', correo:'hector.chavez@gmail.com',
  profesion:'Transportista', empresa:'Transportes Olmos EIRL', ubicacion:'Chiclayo',
  direccion:'Av. Balta 1280, Chiclayo', producto:'Crédito personal',
  monto:18000, moneda:'S/', origen:'Referido', campania:'carsa0001',
  base:'carsa_chiclayo', poliza:'Vigente', vigencia:'12/04/2027', prioridad:'Alta',
  estadoLead:'CONTACTED'
};

const INTERACCIONES_PREVIO = [
  {tipo:'llamada',  t:'Llamada realizada', d:'Aprobado condicional por S/ 18 000. Falta la tercera boleta.', w:'Hoy 10:11', dur:'06:02'},
  {tipo:'whatsapp', t:'WhatsApp enviado',  d:'Lista de documentos pendientes.',                               w:'Hoy 10:14'},
  {tipo:'email',    t:'Correo enviado',    d:'Documentos para completar su solicitud.',                       w:'Ayer 16:05'},
  {tipo:'llamada',  t:'Llamada realizada', d:'Primer contacto. Interesado en consolidar.',                    w:'Ayer 15:40', dur:'03:18'},
  {tipo:'sms',      t:'SMS enviado',       d:'Código de verificación.',                                       w:'Ayer 16:02'},
  {tipo:'nota',     t:'Nota del asesor',   d:'Trabaja de noche: llamar antes de las 11.',                     w:'Ayer 15:50'}
];

const CARTERA = [
  {id:4821, nombre:'Betty Ramírez Nizama', tel:'980910856', hora:'10:24 a. m.', cod:null},
  {id:4820, nombre:'Héctor Chávez Olaya',  tel:'987130189', hora:'10:11 a. m.', cod:'010101'},
  {id:4819, nombre:'Sabina Vásquez Peña',  tel:'961679915', hora:'09:52 a. m.', cod:'010201'},
  {id:4818, nombre:'Tomás Calderón Castro',tel:'902925295', hora:'09:40 a. m.', cod:'020101'},
  {id:4817, nombre:'Mónica Farfán Rivas',  tel:'962878679', hora:'09:21 a. m.', cod:'010102'}
];

// Diálogo de la llamada. `espera` son los milisegundos antes de cada línea.
const DIALOGO = [
  {q:'agente',  t:'Buenos días, le habla Ana Torres de Carsa. ¿Hablo con la señora Betty Ramírez?', espera:600},
  {q:'cliente', t:'Sí, con ella. ¿De qué se trata?', espera:1700},
  {q:'agente',  t:'La llamo porque usted figura preaprobada para consolidar sus deudas en una sola cuota. ¿Tiene dos minutos?', espera:1500},
  {q:'cliente', t:'Mire, la verdad es que no me interesa, ya tengo varias deudas.', espera:2200, objecion:true},
  {q:'agente',  t:'La entiendo perfectamente. Justamente por eso la llamo: no es una deuda nueva, es juntar las que ya tiene en una sola cuota más baja.', espera:2600},
  {q:'cliente', t:'¿Y cuánto sería la cuota?', espera:2000},
  {q:'agente',  t:'Permítame un segundo que reviso su caso.', espera:1400, consulta:true},
  {q:'agente',  t:'Por doce mil soles a veinticuatro meses, su cuota quedaría en setecientos treinta soles. Hoy usted paga novecientos veinte entre las tres.', espera:2600},
  {q:'cliente', t:'O sea que pagaría menos al mes.', espera:1900},
  {q:'agente',  t:'Ciento noventa soles menos, cada mes. ¿Le envío la cotización por WhatsApp para que la vea con calma?', espera:2100},
  {q:'cliente', t:'Sí, mándemela por favor.', espera:1900}
];

const WHATSAPP = [
  {q:'agente',  t:'Hola Betty, le escribo de Carsa. Le comparto la cotización que conversamos.', hora:'10:31'},
  {q:'agente',  t:'📄 Cotización_CompraDeuda_BettyRamirez.pdf', hora:'10:31', archivo:true},
  {q:'agente',  t:'Son S/ 12 000 a 24 meses, cuota de S/ 730. Cualquier duda me escribe por acá.', hora:'10:31'},
  {q:'cliente', t:'Gracias, la estoy viendo', hora:'10:34'},
  {q:'cliente', t:'¿Y si quiero pagarlo antes, hay penalidad?', hora:'10:35'},
  {q:'agente',  t:'No hay penalidad por pago adelantado. Puede cancelar cuando quiera.', hora:'10:36'},
  {q:'cliente', t:'Perfecto. Lo converso con mi esposo hoy y le confirmo', hora:'10:38'}
];

const CORREOS = [
  {asunto:'Su cotización de compra de deuda', fecha:'Hoy 10:32', estado:'Abierto',
   extracto:'Estimada Betty, adjuntamos el detalle de la propuesta conversada…'},
  {asunto:'Documentos para completar su solicitud', fecha:'Ayer 16:05', estado:'Abierto',
   extracto:'Para avanzar necesitamos DNI vigente y un recibo de servicio…'},
  {asunto:'Bienvenida a Carsa', fecha:'12 oct. 09:14', estado:'Abierto',
   extracto:'Gracias por su interés. Su asesora asignada es Ana Torres…'}
];

const SMS = [
  {t:'Betty, su cotización fue enviada a su WhatsApp. Carsa.', fecha:'Hoy 10:33', estado:'Entregado'},
  {t:'Recordatorio: su asesora la llamará hoy a las 10:00. Carsa.', fecha:'Hoy 08:00', estado:'Entregado'},
  {t:'Codigo de verificacion: 4821. No lo comparta.', fecha:'Ayer 16:02', estado:'Entregado'}
];

const INTERACCIONES = [
  {tipo:'llamada',  t:'Llamada realizada',  d:'Interesada en compra de deuda. Se envió cotización.', w:'Hoy 10:24', dur:'04:28'},
  {tipo:'whatsapp', t:'WhatsApp enviado',   d:'Cotización PDF + condiciones de pago adelantado.',    w:'Hoy 10:31'},
  {tipo:'sms',      t:'SMS enviado',        d:'Aviso de envío de cotización.',                        w:'Hoy 10:33'},
  {tipo:'email',    t:'Correo enviado',     d:'Su cotización de compra de deuda.',                    w:'Hoy 10:32'},
  {tipo:'llamada',  t:'Llamada entrante',   d:'Consultó por los documentos requeridos.',              w:'Ayer 16:12', dur:'02:11'},
  {tipo:'nota',     t:'Nota del asesor',    d:'Lo conversa con su esposo. Rellamar mañana 10:00.',    w:'Ayer 16:20'}
];

const PAUSAS = [
  {id:1, et:'Almuerzo'}, {id:2, et:'Baño'}, {id:3, et:'Reunión de equipo'},
  {id:4, et:'Capacitación'}, {id:5, et:'Soporte técnico'}
];

const ARBOL = [
  {code:'010000', label:'CONTACTADO', hijos:[
    {code:'010100', label:'SEGUIMIENTO', hijos:[
      {code:'010101', label:'INTERESADO', kind:'normal'},
      {code:'010102', label:'VOLVER A LLAMAR', kind:'recall'}]},
    {code:'010200', label:'VENTA', hijos:[
      {code:'010201', label:'VENTA CONFIRMADA', kind:'sale'}]}]},
  {code:'020000', label:'NO CONTACTADO', hijos:[
    {code:'020100', label:'NO CONTESTA', hijos:[
      {code:'020101', label:'REINTENTAR', kind:'recall'}]}]}
];

/* ====================================================================== */
/*  SUPLENTES de lo que en la consola hace crm.js                         */
/* ====================================================================== */

const estado = t => { const n = $('msg'); if (n) n.textContent = t; };

function aviso(titulo, texto) {
  const capa = $('layer');
  capa.replaceChildren(el('div', {cls:'sheet'},
    el('header', null, el('span', {txt:titulo})),
    el('div', {cls:'sheet-body'}, el('div', {cls:'sheet-msg', txt:texto})),
    el('div', {cls:'bar bar--end'},
      el('button', {cls:'btn-sm btn-sm--primary', txt:'Aceptar', onclick:cerrarCapa}))));
  capa.hidden = false;
}
function cerrarCapa() { const c = $('layer'); c.hidden = true; c.replaceChildren(); }

function irA(vista) {
  document.body.dataset.vista = vista;
  for (const b of document.querySelectorAll('.side-link')) {
    if (!b.dataset || !b.dataset.vista) continue;
    const on = b.dataset.vista === vista;
    b.classList.toggle('is-on', on);
    if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
  }
  const lienzo = $('vista');
  if (vista === 'clientes') { lienzo.hidden = true; lienzo.replaceChildren(); return; }
  lienzo.hidden = false;
  if (vista === 'tareas') pintarTareas();
  if (vista === 'reportes') pintarReportes();
  if (vista === 'conocimiento') pintarWiki();
  lienzo.scrollTop = 0;
}

/* ====================================================================== */
/*  PINTADO de la réplica                                                 */
/* ====================================================================== */

const CANAL_ICO = {llamada:'tel', whatsapp:'wa', email:'mail', sms:'sms', nota:'nota'};

function pintarMarco() {
  $('opName').textContent = 'Ana Torres';
  $('opRole').textContent = 'Asesor de ventas';
  const chapa = $('whoAv');
  chapa.textContent = '';
  chapa.innerHTML = caraSVG('Ana Torres');

  $('ctlCampaign').replaceChildren(
    el('option', {value:'', txt:'Seleccione campaña'}),
    el('option', {value:'55', txt:'carsa0001 · Compra de deuda'}),
    el('option', {value:'56', txt:'carsa0002 · Crédito personal'}),
    el('option', {value:'57', txt:'carsa0003 · Ampliación de línea'}));

  $('ctlPauseType').replaceChildren(
    el('option', {value:'', txt:'PAUSA'}),
    ...PAUSAS.map(p => el('option', {value:String(p.id), txt:p.et})));

  $('agentState').textContent = 'Disponible';
  $('sideCount').textContent = String(CARTERA.length);
  $('sideTareas').textContent = '2';
  pintarTabsLista();
  pintarCartera();
  pintarGestionArbol();
}

function pintarTabsLista() {
  $('listTabs').replaceChildren(
    ...[['Hoy', true], ['Sin tipificar', false], ['Todas', false]].map(([t, on]) =>
      el('button', {type:'button', 'aria-pressed':String(on)}, t,
        el('span', {cls:'n', txt:' (' + (on ? CARTERA.length : t === 'Todas' ? CARTERA.length : 1) + ')'}))));
}

function pintarCartera(seleccion) {
  $('listBody').replaceChildren(...CARTERA.map(c =>
    el('div', {cls:'cli', 'aria-current':String(seleccion === c.id)},
      avatar(c.nombre),
      el('div', {cls:'cli-name', txt:c.nombre}),
      el('div', {cls:'cli-tel', txt:c.tel}),
      el('div', {cls:'cli-meta'},
        el('span', {cls:'prod', txt:'#' + c.id + ' · carsa0001'}),
        el('span', {cls:'when', txt:c.hora})),
      el('span', {cls:'tag tag--' + (c.cod ? 'baja' : 'alta'), txt:c.cod || 'sin tip'}))));
  $('listCount').textContent = CARTERA.length + ' de ' + CARTERA.length + ' llamadas';
}

function pintarBienvenida() {
  $('detHead').replaceChildren(
    el('div', {cls:'det-title'}, el('h1', {txt:'Hola, Ana Torres'})),
    el('div', {cls:'det-contact'},
      el('span', {txt:'5 llamadas hoy'}),
      el('span', {txt:'1 sin tipificar'}),
      el('span', {txt:'Ninguna ficha abierta'})));
  $('detTabs').replaceChildren();
  $('detFoot').replaceChildren();
  $('detBody').replaceChildren(
    el('div', {cls:'panel'},
      el('h3', null, el('span', {txt:'Para empezar'})),
      el('div', {cls:'panel-body'},
        el('ol', {cls:'pasos'},
          el('li', {txt:'Elija la campaña en la barra superior y pulse «Marcar siguiente lead».'}),
          el('li', {txt:'Atienda en el softphone: la ficha del cliente se abre sola.'}),
          el('li', {txt:'Al colgar, tipifique los tres niveles y grabe.'}),
          el('li', {txt:'La siguiente llamada sale sin que usted haga nada.'})))));
}

const TABS_DET = ['Resumen', 'Interacciones', 'Transacciones', 'Documentos', 'Notas'];
let tabActual = 'Resumen';

let clienteEnFicha = CLIENTE;

function pintarFicha(tab, cliente) {
  tabActual = tab || tabActual;
  if (cliente) clienteEnFicha = cliente;
  const c = clienteEnFicha;
  const inter = c === CLIENTE_PREVIO ? INTERACCIONES_PREVIO : INTERACCIONES;

  $('detHead').replaceChildren(
    avatar(c.nombre, 'avatar--lg'),
    el('div', {cls:'det-title'},
      el('h1', {txt:c.nombre}),
      el('span', {cls:'tag tag--alta', txt:'Alta prioridad'})),
    el('div', {cls:'det-contact'},
      el('span', null, svg('tel'), el('b', {txt:c.telefono})),
      el('span', null, svg('mail'), el('b', {txt:c.correo})),
      el('span', null, svg('pin'), el('b', {txt:c.ubicacion}))),
    el('div', {cls:'det-acts'},
      el('button', {cls:'btn-sm', type:'button', txt:'Editar'})));

  $('detTabs').replaceChildren(...TABS_DET.map(t =>
    el('button', {type:'button', 'aria-pressed':String(t === tabActual)}, t,
      el('span', {cls:'n', txt:t === 'Interacciones' ? ' (' + INTERACCIONES.length + ')'
        : t === 'Resumen' ? '' : ' (0)'}))));

  if (tabActual === 'Resumen') pintarResumen();
  else if (tabActual === 'Interacciones') pintarInteracciones();
  else {
    $('detBody').replaceChildren(el('div', {cls:'panel'},
      el('h3', null, el('span', {txt:tabActual})),
      el('div', {cls:'panel-body'}, el('div', {cls:'empty', txt:'Sin registros para este cliente.'}))));
    $('detFoot').replaceChildren(botonera());
  }
}

function filaKv(dl, k, v, extra) {
  dl.append(el('dt', {txt:k}));
  dl.append(el('dd', null, el('span', {txt:v}), extra || null));
}

function pintarResumen() {
  const c = clienteEnFicha;
  const inter = c === CLIENTE_PREVIO ? INTERACCIONES_PREVIO : INTERACCIONES;
  const dl = el('dl', {cls:'kv kv--doble'});
  filaKv(dl, 'Nombre completo', c.nombre);
  filaKv(dl, 'Documento', c.documento);
  filaKv(dl, 'Teléfono', c.telefono,
    el('button', {cls:'btn-sm', style:'height:15px;padding:0 5px;font-size:9px;margin-left:auto', txt:'Gestionar'}));
  filaKv(dl, 'Correo', c.correo);
  filaKv(dl, 'Profesión', c.profesion);
  filaKv(dl, 'Empresa', c.empresa);
  filaKv(dl, 'Ubicación', c.ubicacion);
  filaKv(dl, 'Dirección', c.direccion);
  filaKv(dl, 'Producto de interés', c.producto);
  filaKv(dl, 'Monto estimado', money(c.monto, c.moneda));
  filaKv(dl, 'Origen', c.origen);
  filaKv(dl, 'Campaña', c.campania);
  filaKv(dl, 'Base', c.base);
  filaKv(dl, 'Estado del lead', c.estadoLead);

  $('detBody').replaceChildren(el('div', {cls:'panel'},
    el('h3', null, el('span', {txt:'Información del cliente'})),
    el('div', {cls:'panel-body'}, dl)));

  $('detFoot').replaceChildren(botonera(),
    el('div', {cls:'panel'},
      el('h3', null, el('span', {txt:'Historial de interacciones'})),
      el('div', {cls:'panel-body'}, listaInteracciones(inter.slice(0, 3)))));
}

function pintarInteracciones() {
  $('detBody').replaceChildren(el('div', {cls:'panel'},
    el('h3', null, el('span', {txt:'Historial de interacciones'}),
      el('span', {cls:'spacer'}),
      el('div', {cls:'chips'}, ['Todas', 'Llamadas', 'Mensajes', 'Correos', 'Notas'].map((k, i) =>
        el('button', {cls:'chip', type:'button', 'aria-pressed':String(i === 0), txt:k})))),
    el('div', {cls:'panel-body'},
      listaInteracciones(clienteEnFicha === CLIENTE_PREVIO ? INTERACCIONES_PREVIO : INTERACCIONES))));
  $('detFoot').replaceChildren(botonera());
}

function listaInteracciones(items) {
  return el('ul', {cls:'tl'}, items.map(i =>
    el('li', null,
      el('span', {cls:'mark m-' + i.tipo}, svg(CANAL_ICO[i.tipo])),
      el('span', {cls:'t', txt:i.t}),
      el('span', {cls:'w', txt:i.w}),
      el('span', {cls:'d', txt:i.d}),
      el('span', {cls:'who2', txt:i.dur ? i.dur : 'Ana Torres'}))));
}

function botonera(chatAbierto) {
  const b = (cls, ico, txt, id) =>
    el('button', {cls:'ch ' + cls, type:'button', id:id || null}, svg(ico, 'ico ico--fill'), txt);
  return el('div', {cls:'channels'},
    b('ch--call', 'tel-f', 'Llamar', 'dLlamar'),
    el('button', {cls:'ch ch--wa' + (chatAbierto ? ' is-on' : ''), type:'button', id:'dWa'},
      svg('wa-f', 'ico ico--fill'), chatAbierto ? 'Cerrar chat' : 'WhatsApp'),
    b('ch--mail', 'mail-f', 'Email', 'dMail'),
    b('ch--sms', 'sms-f', 'SMS', 'dSms'),
    el('button', {cls:'ch ch--more', type:'button', id:'dMas'}, svg('mas'), 'Más acciones'));
}

/* --------------------------------------------- WhatsApp, correo y SMS */

function pintarChat(mensajes) {
  const hilo = el('div', {cls:'wa-hilo'}, mensajes.map(m =>
    el('div', {cls:'wa-msg wa-msg--' + m.q + (m.archivo ? ' wa-msg--file' : '')},
      el('span', {txt:m.t}),
      el('em', {txt:m.hora}))));
  $('detBody').replaceChildren(el('div', {cls:'panel'},
    el('h3', null, el('span', {txt:'WhatsApp · ' + clienteEnFicha.telefono}),
      el('span', {cls:'spacer'}),
      el('span', {cls:'wa-en-linea', txt:'en línea'})),
    el('div', {cls:'panel-body wa-body'}, hilo,
      el('div', {cls:'wa-barra'},
        el('input', {type:'text', placeholder:'Escriba un mensaje…', id:'waTexto'}),
        el('button', {cls:'btn-sm btn-sm--primary', type:'button', txt:'Enviar'})))));
  $('detFoot').replaceChildren(botonera(true));
  const caja = $('detBody').querySelector('.wa-hilo');
  if (caja) caja.scrollTop = caja.scrollHeight;
}

function pintarCorreos() {
  $('detBody').replaceChildren(el('div', {cls:'panel'},
    el('h3', null, el('span', {txt:'Correos con este cliente'})),
    el('div', {cls:'panel-body'},
      el('ul', {cls:'bandeja'}, CORREOS.map(c =>
        el('li', null,
          el('span', {cls:'mark m-email'}, svg('mail')),
          el('b', {txt:c.asunto}),
          el('span', {cls:'bandeja-ext', txt:c.extracto}),
          el('span', {cls:'bandeja-w', txt:c.fecha}),
          el('span', {cls:'tag tag--baja', txt:c.estado})))))));
  $('detFoot').replaceChildren(botonera());
}

function pintarSms() {
  $('detBody').replaceChildren(el('div', {cls:'panel'},
    el('h3', null, el('span', {txt:'SMS enviados'})),
    el('div', {cls:'panel-body'},
      el('ul', {cls:'bandeja'}, SMS.map(s =>
        el('li', null,
          el('span', {cls:'mark m-sms'}, svg('sms')),
          el('b', {txt:s.t}),
          el('span', {cls:'bandeja-w', txt:s.fecha}),
          el('span', {cls:'tag tag--baja', txt:s.estado})))))));
  $('detFoot').replaceChildren(botonera());
}

/* ------------------------------------------------- tipificar gestión */

function pintarGestionArbol(n1, n2, n3) {
  const sel = (id, ops, val, hab) => {
    const s = $(id);
    s.replaceChildren(el('option', {txt:ops.length ? '— Seleccionar —' : 'Depende del nivel anterior'}),
      ...ops.map(o => el('option', {txt:o.label, selected:o.label === val})));
    s.disabled = !hab;
    s.closest('.field').classList.toggle('field--on', !!val);
  };
  const h1 = ARBOL.find(x => x.label === n1);
  const h2 = h1 && (h1.hijos || []).find(x => x.label === n2);
  sel('tip0', ARBOL, n1, true);
  sel('tip1', h1 ? h1.hijos : [], n2, !!h1);
  sel('tip2', h2 ? h2.hijos : [], n3, !!h2);
  const hoja = h2 && (h2.hijos || []).find(x => x.label === n3);
  const out = $('tipOut');
  out.textContent = hoja
    ? (hoja.kind === 'sale' ? 'Se registrará como VENTA.'
      : hoja.kind === 'recall' ? 'Se programará una rellamada.' : 'Se registra sin trámite adicional.')
    : 'Elija los tres niveles para habilitar el registro.';
  if (hoja) out.dataset.kind = hoja.kind; else delete out.dataset.kind;
  $('btnGrabar').disabled = !hoja;
}

function abrirHojaGestion() {
  const bloque = $('gestionHost').querySelector('.block');
  const capa = $('layer');
  capa.replaceChildren(el('div', {cls:'sheet sheet--gestion'}, bloque));
  capa.hidden = false;
}
function guardarHojaGestion() {
  const hoja = $('layer').querySelector('.sheet--gestion');
  if (hoja) { const b = hoja.querySelector('.block'); if (b) $('gestionHost').append(b); }
  cerrarCapa();
}

/* ------------------------------------------------- transcripción e IA */

let ondaLazo = null, ondaEnergia = 0, ondaFase = 0, ondaBarras = null;

function ondaMontar() {
  const caja = $('onda');
  caja.replaceChildren(...Array.from({length:26}, () => el('i')));
  ondaBarras = caja.children;
}
function ondaHabla(largo) {
  ondaEnergia = Math.min(1, 0.5 + Math.min(1, largo / 90) * 0.5);
  if (ondaLazo) return;
  $('onda').classList.add('is-viva');
  ondaLazo = setInterval(() => {
    ondaFase += 0.35; ondaEnergia *= 0.94;
    for (let i = 0; i < ondaBarras.length; i++) {
      const centro = 1 - Math.abs(i - 12.5) / 12.5;
      const forma = (Math.sin(ondaFase + i * 0.55) + Math.sin(ondaFase * 0.7 + i * 0.23)) / 2;
      ondaBarras[i].style.transform = 'scaleY(' + (0.12 + ondaEnergia * (0.25 + 0.75 * centro) * Math.abs(forma)).toFixed(3) + ')';
    }
    if (ondaEnergia < 0.02) {
      for (const b of ondaBarras) b.style.transform = 'scaleY(0.12)';
      $('onda').classList.remove('is-viva');
      clearInterval(ondaLazo); ondaLazo = null;
    }
  }, 90);
}

function trxLinea(quien, texto) {
  // Guarda contra repeticiones: si la última línea es idéntica, no se suma.
  const ult = $('trxBody').lastChild;
  if (ult && ult.textContent && ult.textContent.indexOf(texto) >= 0) return;
  const et = {agente:'AG', cliente:'CLI', sistema:'··'}[quien];
  const hora = new Date();
  $('trxBody').append(el('div', {cls:'trx-line trx-line--' + quien},
    el('span', {cls:'trx-quien', txt:et}),
    el('span', {cls:'trx-txt', txt:texto}),
    el('span', {cls:'trx-hora',
      txt:String(hora.getHours()).padStart(2, '0') + ':' + String(hora.getMinutes()).padStart(2, '0')})));
  $('trxBody').scrollTop = $('trxBody').scrollHeight;
  ondaHabla(texto.length);
}

function asisBurbuja(cls, nodos) { return el('div', {cls:'bur ' + cls}, nodos); }

function asisTextoNodos(txt) {
  const out = [];
  String(txt).split('\n').forEach((linea, i) => {
    if (i) out.push(el('br'));
    linea.split(/\*\*(.+?)\*\*/).forEach((t, k) => { if (t) out.push(k % 2 ? el('b', {txt:t}) : t); });
  });
  return out;
}

function asisPregunta(txt) { $('asisBody').append(asisBurbuja('bur--yo', [txt])); asisScroll(); }
function asisPensando() {
  const b = asisBurbuja('bur--ia bur--pensando', [el('i'), el('i'), el('i')]);
  $('asisBody').append(b); asisScroll(); return b;
}
function asisRespuesta(texto, fuente, pensando) {
  if (pensando) pensando.remove();
  const b = asisBurbuja('bur--ia', asisTextoNodos(texto));
  if (fuente) b.append(el('div', {cls:'bur-fuente', txt:'Fuente: ' + fuente}));
  $('asisBody').append(b); asisScroll();
}
function asisScroll() { $('asisBody').scrollTop = $('asisBody').scrollHeight; }

/* ====================================================================== */
/*  MOTOR DEL RECORRIDO                                                   */
/* ====================================================================== */

/* `gen` es el número de pasada. Cada vez que alguien salta de capítulo o
   reinicia, sube. Un capítulo que venía corriendo con un número viejo se
   abandona en su siguiente espera, en vez de seguir escribiendo encima del
   nuevo — que es lo que duplicaba las líneas de la transcripción. */
const P = {i:0, corriendo:false, gen:0, lazo:false};

const NUNCA = () => new Promise(() => {});   // abandona la pasada vieja

/* Espera que respeta la pausa de verdad: no consume el tiempo mientras el
   recorrido está detenido, y se abandona sola si cambió la pasada. */
async function dormir(ms) {
  const gen = P.gen;
  let resta = ms;
  while (resta > 0) {
    if (gen !== P.gen) return NUNCA();
    if (!P.corriendo) { await new Promise(r => setTimeout(r, 100)); continue; }
    const paso = Math.min(100, resta);
    await new Promise(r => setTimeout(r, paso));
    resta -= paso;
  }
  return gen === P.gen ? undefined : NUNCA();
}

/* Mueve el puntero hasta un elemento y simula el clic. Sin esto el
   recorrido se ve como una pantalla que cambia sola; con esto se entiende
   que alguien la está operando. */
async function clicEn(sel, ms) {
  const n = typeof sel === 'string' ? document.querySelector(sel) : sel;
  if (!n) return;
  const caja = n.getBoundingClientRect(), marco = $('pantalla').getBoundingClientRect();
  const p = $('puntero');
  p.hidden = false;
  p.style.transform = 'translate(' + (caja.left - marco.left + caja.width / 2) + 'px,'
                                   + (caja.top - marco.top + caja.height / 2) + 'px)';
  await dormir(620);
  p.classList.add('is-click');
  await dormir(ms || 260);
  p.classList.remove('is-click');
}

/* Recuadro que ilumina la zona de la que habla la narración.

   Va en la MISMA fila que la voz. En el guion el foco se escribe antes de
   su frase —se prepara la escena y después se habla— pero la frase espera
   a que termine la anterior. Si el foco se aplicara en el acto, saltaría
   una frase antes: la voz hablando de la cartera y el recuadro ya en el
   expediente. Encolándolo, cada zona se ilumina justo cuando empieza la
   frase que la explica. */
function enfocar(sel) {
  const gen = P.gen;
  VOZ.cola = VOZ.cola.then(() => { if (gen === P.gen) aplicarFoco(sel); });
}

function aplicarFoco(sel) {
  const f = $('foco');
  if (!sel) { f.hidden = true; return; }
  const n = typeof sel === 'string' ? document.querySelector(sel) : sel;
  if (!n) { f.hidden = true; return; }
  const c = n.getBoundingClientRect(), m = $('pantalla').getBoundingClientRect();
  f.hidden = false;
  f.style.left = (c.left - m.left - 4) + 'px';
  f.style.top = (c.top - m.top - 4) + 'px';
  f.style.width = (c.width + 8) + 'px';
  f.style.height = (c.height + 8) + 'px';
}

async function escribir(id, texto, ms) {
  const n = $(id);
  n.value = '';
  for (const ch of texto) { n.value += ch; await dormir(ms || 45); }
}

/* --------------------------------------------------------------- voz

   La narración está grabada: son archivos sueltos en demo/audio, uno por
   frase. La página NO llama a ningún servicio — funciona sin internet y no
   gasta créditos cada vez que alguien la mira.

   El nombre del archivo sale del propio texto, con la misma cuenta que usa
   el generador. Así no hay una lista de correspondencias que mantener al
   día: si la frase cambia, cambia el archivo, y si falta, la escena sigue
   muda en vez de romperse.                                               */

/* La voz corre en su propia fila, en paralelo con la escena.

   Antes la escena esperaba a que terminara cada frase para recién mover el
   puntero: se oía narración y después acción muda, como dos películas
   pegadas. Un expositor habla MIENTRAS opera.

   Ahora las frases se encolan —nunca se pisan— y la escena avanza por su
   cuenta. Lo único que se espera es el final del capítulo: ahí sí hay que
   dejar que termine de hablar antes de cambiar de tema.                  */
const VOZ = {cola:Promise.resolve(), audio:null, silencio:false, ritmo:1.3};

function hashTexto(t) {
  let h = 0;
  for (const c of String(t)) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return h.toString(36);
}

function reproducir(texto, gen) {
  return new Promise(res => {
    if (VOZ.silencio || gen !== P.gen) return res();
    const a = new Audio('audio/v' + hashTexto(texto) + '.mp3');
    a.playbackRate = VOZ.ritmo;
    // El navegador conserva el tono al acelerar; sin esto la voz subiría de
    // registro y sonaría a dibujo animado.
    a.preservesPitch = true;
    a.mozPreservesPitch = true;
    a.webkitPreservesPitch = true;
    VOZ.audio = a;
    let listo = false;
    const fin = () => { if (!listo) { listo = true; VOZ.audio = null; res(); } };
    a.addEventListener('ended', fin);
    a.addEventListener('error', fin);          // sin archivo, la escena sigue
    // Red de seguridad: un audio que nunca dispara 'ended' no puede dejar
    // la presentación colgada delante de un cliente.
    setTimeout(fin, 30000);
    a.play().catch(fin);                       // el navegador puede negarlo
  });
}

// Encola la frase detrás de la anterior. No devuelve espera: la escena
// sigue su curso mientras se dice.
function decir(texto) {
  const gen = P.gen;
  VOZ.cola = VOZ.cola.then(() => reproducir(texto, gen));
}

// Lo que se espera al cerrar un capítulo, para no cambiar de tema a media
// frase.
const vozAlDia = () => VOZ.cola;

/* Cambiar de frase espera a que termine la anterior, pero NO espera a la
   propia: el texto y el foco se sincronizan con lo que se está diciendo,
   mientras las acciones de ese tramo —el puntero, lo que se escribe, la
   transcripción— ocurren en paralelo. Eso es un expositor operando. */
async function narrar(txt, puntos, activo) {
  const gen = P.gen;
  await vozAlDia();
  if (gen !== P.gen) return NUNCA();
  $('narraTxt').textContent = txt;
  if (puntos) {
    $('narraPuntos').replaceChildren(...puntos.map((p, i) =>
      el('li', {cls:i === activo ? 'is-on' : '', txt:p})));
  }
  decir(txt);
}

/* ====================================================================== */
/*  EL GUION                                                              */
/*                                                                        */
/*  Cada capítulo es una función que se ejecuta de principio a fin. El     */
/*  hilo conductor es siempre el mismo: el asesor NO SALE DE LA PANTALLA.  */
/* ====================================================================== */

const CAPITULOS = [

{ n:'Acceso', titulo:'Un solo inicio de sesión', corre: async () => {
  await narrar('El asesor abre una sola aplicación y entra. Esa aplicación trae el teléfono adentro: no hay un programa para llamar, otro para WhatsApp y otro para el correo. Una instalación, una pantalla.',
    ['Una sola aplicación instalada', 'El teléfono viene incluido', 'Un usuario y una contraseña'], 0);
  $('loginDemo').classList.remove('is-ido');
  $('loginDemo').hidden = false;
  enfocar('#loginDemo .login-box');
  await dormir(1600);
  await clicEn('#dUser');
  await escribir('dUser', '5000', 90);
  await dormir(300);
  await clicEn('#dPass');
  await escribir('dPass', '••••••••', 70);
  await dormir(500);
  await clicEn('#dEntrar');
  enfocar(null);
  $('loginDemo').classList.add('is-ido');
  await dormir(500);
  $('loginDemo').hidden = true;
}},

{ n:'La pantalla', titulo:'Todo a la vista, sin pestañas', corre: async () => {
  await narrar('Al entrar ya está todo. A la izquierda su cartera del día; al centro el expediente del cliente; a la derecha lo que pasa en la llamada. Nada de esto obliga a cambiar de ventana.',
    ['Cartera del día', 'Expediente completo', 'Transcripción y asistente'], 0);
  await dormir(1500);
  enfocar('.col--list');
  await narrar('La cartera: cada persona con la que habló hoy, con la hora y el resultado. Lo que todavía no cerró queda marcado en rojo para que no se le pase.',
    ['Cartera del día', 'Expediente completo', 'Transcripción y asistente'], 0);
  await dormir(1200);
  /* Antes se iluminaba el expediente vacío mientras la voz describía los
     datos de un cliente: había que hacer clic en alguien para que hubiera
     algo que mirar. Se abre uno ya trabajado hoy — no el que va a llamar
     después, para no gastarle el efecto a «la ficha se abre sola». */
  await clicEn('#listBody .cli:nth-child(2)');
  pintarCartera(CLIENTE_PREVIO.id);
  pintarFicha('Resumen', CLIENTE_PREVIO);
  enfocar('.col--detail');
  await narrar('Si abre a cualquiera de ellos, ve el expediente completo: nombre, documento, teléfono, correo, dirección, el producto que le interesa y el monto. Debajo, todo lo que se habló antes y por qué canal.',
    ['Cartera del día', 'Expediente completo', 'Transcripción y asistente'], 1);
  await dormir(3000);
  enfocar('.col--work');
  await narrar('Y la columna de la derecha, que es la novedad: lo que se está diciendo en la llamada, transcrito en vivo, y un asistente con inteligencia artificial listo para ayudar.',
    ['Cartera del día', 'Expediente completo', 'Transcripción y asistente'], 2);
  await dormir(3400);
  enfocar(null);
}},

{ n:'Campañas', titulo:'Elige campaña y el sistema marca', corre: async () => {
  await narrar('El asesor no disca números. Elige la campaña y el sistema le entrega el siguiente cliente que corresponde llamar, respetando horarios e intentos permitidos.',
    ['Varias campañas por asesor', 'El sistema elige a quién llamar', 'Nadie marca a mano'], 0);
  enfocar('.topbar-dial');
  await dormir(1600);
  await clicEn('#ctlCampaign');
  $('ctlCampaign').value = '55';
  $('ctlCampaign').classList.add('resalta');
  await narrar('Tiene tres campañas asignadas. Elige "compra de deuda" y a partir de ahí el sistema solo le va a entregar clientes de esa campaña.',
    ['Varias campañas por asesor', 'El sistema elige a quién llamar', 'Nadie marca a mano'], 1);
  await dormir(2600);
  $('ctlCampaign').classList.remove('resalta');
  await clicEn('#ctlDial');
  await narrar('Pulsa una sola vez. De acá en adelante, el sistema encadena las llamadas: al cerrar una, sale la siguiente sin que nadie haga nada.',
    ['Varias campañas por asesor', 'El sistema elige a quién llamar', 'Nadie marca a mano'], 2);
  enfocar(null);
  await dormir(1800);
}},

{ n:'La llamada', titulo:'Suena y la ficha se abre sola', corre: async () => {
  $('dialerLabel').textContent = 'Marcando 980910856…';
  $('callState').dataset.on = 'false';
  $('callLabel').textContent = 'Timbrando';
  await narrar('El sistema marca. Mientras timbra, el asesor no hace nada: ya está buscando la ficha.',
    ['Marca el sistema', 'La ficha se abre sola', 'Habla con el nombre en pantalla'], 0);
  await dormir(2200);

  $('callState').dataset.on = 'true';
  $('callLabel').textContent = 'En llamada · Betty';
  $('dialerLabel').textContent = 'Conectado · 1 campaña';
  cronoArrancar();
  pintarCartera(CLIENTE.id);
  pintarFicha('Resumen', CLIENTE);
  await narrar('Contestó. Y antes de decir una palabra, el asesor ya tiene en pantalla quién es, cuánto debe, dónde vive y todo lo que se conversó antes.',
    ['Marca el sistema', 'La ficha se abre sola', 'Habla con el nombre en pantalla'], 1);
  enfocar('.det-head');
  await dormir(3000);
  enfocar('.kv');
  await narrar('Nombre, documento, teléfono, correo, dirección, producto de interés y monto. Sin buscar en otro sistema, sin pedirle los datos al cliente.',
    ['Marca el sistema', 'La ficha se abre sola', 'Habla con el nombre en pantalla'], 2);
  await dormir(3600);
  enfocar(null);
}},

{ n:'Transcripción', titulo:'La conversación se escribe sola', corre: async () => {
  await narrar('Mientras hablan, el sistema transcribe en vivo. El asesor no toma notas: queda registrado solo.',
    ['Transcripción en vivo', 'Queda guardada', 'El supervisor puede revisarla'], 0);
  enfocar('#trx');
  $('trxEstado').textContent = 'escuchando';
  for (let i = 0; i < 4; i++) {
    await dormir(DIALOGO[i].espera);
    trxLinea(DIALOGO[i].q, DIALOGO[i].t);
    if (i === 2) await narrar('El asesor presenta el producto. Cada frase queda escrita, de los dos lados de la línea.',
      ['Transcripción en vivo', 'Queda guardada', 'El supervisor puede revisarla'], 1);
  }
  enfocar(null);
}},

{ n:'La objeción', titulo:'El cliente dice que no, y la IA ayuda', corre: async () => {
  await narrar('Acá está lo importante. La clienta acaba de decir que no le interesa. En cualquier otro sistema, el asesor queda solo con su experiencia.',
    ['Detecta la objeción sola', 'Sugiere qué decir', 'Con material aprobado'], 0);
  enfocar('.trx-line--cliente:last-child');
  await dormir(2600);

  enfocar('#asis');
  const p1 = asisPensando();
  await narrar('El asistente escuchó lo mismo que el asesor, reconoció la objeción y le está preparando una respuesta.',
    ['Detecta la objeción sola', 'Sugiere qué decir', 'Con material aprobado'], 1);
  await dormir(2000);
  asisRespuesta(
    '**Objeción detectada: «ya tengo deudas».**\n'
    + 'Eso no es una objeción: es el argumento de venta.\n\n'
    + 'Diga: «La entiendo. Justamente por eso la llamo: no es una deuda nueva, es juntar las que ya tiene en una sola cuota más baja.»',
    'Guía de objeciones · compra de deuda', p1);
  await narrar('Le dice exactamente qué responder, con material aprobado por la empresa. No es una idea del asesor: es el guion de la compañía.',
    ['Detecta la objeción sola', 'Sugiere qué decir', 'Con material aprobado'], 2);
  await dormir(4200);

  trxLinea('agente', DIALOGO[4].t);
  await dormir(1800);
  trxLinea('cliente', DIALOGO[5].t);
  await narrar('El asesor usa la respuesta sugerida y la conversación sigue. La clienta ahora pregunta por la cuota.',
    ['Detecta la objeción sola', 'Sugiere qué decir', 'Con material aprobado'], 2);
  await dormir(2400);
  enfocar(null);
}},

{ n:'Consulta a la IA', titulo:'Pregunta y responde al instante', corre: async () => {
  await narrar('La clienta pregunta por la tasa. En vez de pedirle que espere mientras busca, el asesor le pregunta al asistente.',
    ['Pregunta en lenguaje normal', 'Responde en segundos', 'Cita la fuente'], 0);
  enfocar('.asis-form');
  await dormir(1600);
  await clicEn('#asisTexto');
  await escribir('asisTexto', '¿Cuál es la tasa para compra de deuda?', 38);
  await dormir(500);
  await clicEn('#asisEnviar');
  asisPregunta('¿Cuál es la tasa para compra de deuda?');
  $('asisTexto').value = '';
  const p = asisPensando();
  enfocar('#asis');
  await dormir(1500);
  asisRespuesta(
    'Para **compra de deuda** la TEA vigente arranca en **38,9 %** y baja a **32,5 %** si domicilia su haber.\n\n'
    + 'Por **S/ 12 000 a 24 meses**, la cuota queda en **S/ 730**.\n'
    + 'Mencione la TCEA antes de cerrar: incluye el desgravamen.',
    'Tarifario vigente · carsa0001', p);
  await narrar('Dos segundos. Con el número exacto, la cuota ya calculada y de dónde salió el dato. El cliente no escuchó ni un silencio.',
    ['Pregunta en lenguaje normal', 'Responde en segundos', 'Cita la fuente'], 2);
  await dormir(4200);

  for (let i = 7; i < 11; i++) { trxLinea(DIALOGO[i].q, DIALOGO[i].t); await dormir(1500); }
  enfocar(null);
}},

{ n:'WhatsApp', titulo:'El mismo cliente, sin cambiar de ventana', corre: async () => {
  await narrar('La clienta pidió la cotización por WhatsApp. El asesor no abre el teléfono ni otra aplicación: la conversación está acá, en la misma pantalla.',
    ['WhatsApp dentro del CRM', 'El historial queda en la ficha', 'Un solo lugar'], 0);
  enfocar('.channels');
  await dormir(2200);
  await clicEn('#dWa');
  pintarChat(WHATSAPP.slice(0, 3));
  enfocar('.wa-body');
  await narrar('Le envía la cotización. El mensaje sale desde el sistema y queda pegado al expediente de la clienta.',
    ['WhatsApp dentro del CRM', 'El historial queda en la ficha', 'Un solo lugar'], 1);
  await dormir(3000);
  pintarChat(WHATSAPP.slice(0, 5));
  await narrar('Y la clienta responde. Sin salir de la pantalla, el asesor ve la respuesta y sigue la conversación por escrito.',
    ['WhatsApp dentro del CRM', 'El historial queda en la ficha', 'Un solo lugar'], 2);
  await dormir(3000);
  pintarChat(WHATSAPP);
  await dormir(2800);
  enfocar(null);
}},

{ n:'Correo y SMS', titulo:'Los otros canales, en el mismo lugar', corre: async () => {
  await clicEn('#dWa');          // cierra el chat con el que terminó el capítulo anterior
  pintarFicha('Resumen');
  await narrar('Lo mismo con el correo. Todo lo que se le envió a esta clienta está acá, con fecha y estado.',
    ['Correos enviados', 'SMS enviados', 'Todo contra la misma ficha'], 0);
  await clicEn('#dMail');
  pintarCorreos();
  enfocar('.bandeja');
  await dormir(3400);
  await narrar('Y los mensajes de texto: los avisos, el recordatorio de la llamada, el código de verificación. Nada de esto vive en otro sistema.',
    ['Correos enviados', 'SMS enviados', 'Todo contra la misma ficha'], 1);
  await clicEn('#dSms');
  pintarSms();
  await dormir(3400);
  await narrar('Llamada, WhatsApp, correo y SMS: cuatro canales, una sola ficha, una sola pantalla. Eso es lo que cambia el día del asesor.',
    ['Correos enviados', 'SMS enviados', 'Todo contra la misma ficha'], 2);
  await dormir(2600);
  enfocar(null);
}},

{ n:'Historial', titulo:'Todo lo que pasó con este cliente', corre: async () => {
  await narrar('Y si alguien quiere ver la historia completa, está en una pestaña: cada llamada, cada mensaje, cada correo y cada nota, en orden.',
    ['Un solo historial', 'Sin importar el canal', 'Con quién lo atendió'], 0);
  await clicEn('.det-tabs button:nth-child(2)');
  pintarFicha('Interacciones');
  enfocar('.tl');
  await dormir(4000);
  await narrar('Si mañana atiende otro asesor, no empieza de cero: ve exactamente lo que se conversó y por dónde.',
    ['Un solo historial', 'Sin importar el canal', 'Con quién lo atendió'], 2);
  await dormir(3000);
  enfocar(null);
}},

{ n:'Pausas', titulo:'Los estados del asesor, medidos', corre: async () => {
  await narrar('El asesor necesita parar. Lo hace desde la barra superior, y el sistema registra en qué estado está en cada momento.',
    ['Un clic para pausar', 'Motivos configurables', 'Todo queda medido'], 0);
  enfocar('.topbar-pause');
  await dormir(2200);
  await clicEn('#ctlPauseType');
  await narrar('Elige el motivo y la pausa se aplica sola. Estos motivos los configura el supervisor: almuerzo, baño, reunión, capacitación, soporte. Cada empresa define los suyos.',
    ['Un clic para pausar', 'Motivos configurables', 'Todo queda medido'], 1);
  await dormir(3600);
  $('ctlPauseType').value = '1';
  $('ctlPauseWrap').hidden = true;
  $('ctlPause').hidden = false;
  $('agentState').textContent = 'Pausado · Almuerzo';
  enfocar('#agentState');
  await narrar('Queda en pausa, y el estado se ve en la barra. Mientras esté así, el sistema no le entrega llamadas — y el tiempo se contabiliza para el reporte del supervisor.',
    ['Un clic para pausar', 'Motivos configurables', 'Todo queda medido'], 2);
  await dormir(4000);
  await clicEn('#ctlPause');
  $('ctlPause').hidden = true;
  $('ctlPauseWrap').hidden = false;
  $('ctlPauseType').value = '';
  $('agentState').textContent = 'Disponible';
  await narrar('Vuelve a estar disponible con un clic, y las llamadas se reanudan.',
    ['Un clic para pausar', 'Motivos configurables', 'Todo queda medido'], 2);
  await dormir(2200);
  enfocar(null);
}},

{ n:'Cierre', titulo:'Tipifica y entra la siguiente', corre: async () => {
  pintarFicha('Resumen');        // vuelve del historial a la ficha
  await narrar('Termina la llamada. Acá es donde la mayoría de los sistemas pierden información: el asesor cuelga y se olvida de registrar.',
    ['No se puede saltear', 'Tres niveles', 'La siguiente sale sola'], 0);
  enfocar('#ctlHangup');
  $('ctlHangup').hidden = false;
  await dormir(1800);
  await clicEn('#ctlHangup');
  $('ctlHangup').hidden = true;
  $('callState').dataset.on = 'false';
  $('callLabel').textContent = 'Sin llamada';
  cronoParar();
  $('trxEstado').textContent = 'en espera';

  abrirHojaGestion();
  enfocar('.sheet--gestion');
  await narrar('Al colgar, el sistema le pide el resultado. No es opcional: hasta que no lo registre, no recibe la siguiente llamada.',
    ['No se puede saltear', 'Tres niveles', 'La siguiente sale sola'], 0);
  await dormir(3000);

  await narrar('Tres niveles, de lo general a lo concreto. Contactado… seguimiento… interesado. El árbol lo define cada campaña.',
    ['No se puede saltear', 'Tres niveles', 'La siguiente sale sola'], 1);
  await clicEn('#tip0');
  pintarGestionArbol('CONTACTADO');
  await dormir(1200);
  await clicEn('#tip1');
  pintarGestionArbol('CONTACTADO', 'SEGUIMIENTO');
  await dormir(1200);
  await clicEn('#tip2');
  pintarGestionArbol('CONTACTADO', 'SEGUIMIENTO', 'INTERESADO');
  await dormir(1400);

  await clicEn('#tipNota');
  await escribir('tipNota', 'Envió cotización por WhatsApp. Lo conversa con su esposo y confirma mañana.', 26);
  await dormir(700);
  await clicEn('#btnGrabar');
  guardarHojaGestion();
  enfocar(null);
  CARTERA[0].cod = '010101';
  pintarCartera(CLIENTE.id);
  await narrar('Registrado. La gestión queda en el expediente, la rellamada se agenda sola como tarea, y el sistema ya está marcando al siguiente cliente.',
    ['No se puede saltear', 'Tres niveles', 'La siguiente sale sola'], 2);
  $('ctlLead').hidden = false;
  $('ctlLead').replaceChildren(el('b', {txt:'Siguiente llamada en 5 s'}));
  await dormir(3600);
  $('ctlLead').hidden = true;
}},

{ n:'Tareas', titulo:'Nada se pierde entre llamada y llamada', corre: async () => {
  await narrar('Lo que el asesor prometió, queda. Las rellamadas comprometidas, los documentos que faltan, los cierres pendientes: todo en una lista con vencimiento.',
    ['Compromisos con el cliente', 'Ordenadas por vencimiento', 'Lo vencido, en rojo'], 0);
  await clicEn('#navTareas');
  irA('tareas');
  await dormir(2600);
  enfocar('.tarea--vencida');
  await narrar('Lo vencido salta a la vista. El supervisor no tiene que perseguir a nadie: el sistema lo muestra.',
    ['Compromisos con el cliente', 'Ordenadas por vencimiento', 'Lo vencido, en rojo'], 2);
  await dormir(3200);
  enfocar(null);
  await dormir(1400);
}},

{ n:'Reportes', titulo:'Su comisión, en tiempo real', corre: async () => {
  await narrar('El asesor ve cuánto lleva ganado sin preguntarle a nadie. Y, sobre todo, cuánto le falta para el siguiente tramo.',
    ['Comisión al día', 'Cuánto falta para el próximo tramo', 'Tabla del equipo'], 0);
  await clicEn('#navReportes');
  irA('reportes');
  await dormir(2200);
  enfocar('.hero-salto');
  await narrar('«Le falta una venta para el tramo Oro, y eso suma cuatro mil soles más.» Un asesor que ve ese número, llama una vez más.',
    ['Comisión al día', 'Cuánto falta para el próximo tramo', 'Tabla del equipo'], 1);
  await dormir(4000);
  enfocar('.ranking');
  await narrar('Y se ve en la tabla del equipo. En un piso de ventas, eso mueve más que cualquier reunión.',
    ['Comisión al día', 'Cuánto falta para el próximo tramo', 'Tabla del equipo'], 2);
  await dormir(3400);
  enfocar(null);
}},

{ n:'Conocimiento', titulo:'La respuesta, siempre a mano', corre: async () => {
  await narrar('Todo lo que el asesor necesita saber está adentro: productos, tasas, requisitos, objeciones y procesos.',
    ['Buscador', 'Fichas de una sola mirada', 'Conectado con el asistente'], 0);
  await clicEn('#navWiki');
  irA('conocimiento');
  await dormir(2400);
  enfocar('.battle');
  await narrar('Cada producto tiene su ficha de combate: por qué ganamos, qué objeciones esperar, dónde no aplica y cuál es el paso siguiente. Se lee en menos de un minuto, que es lo que dura la atención en medio de una llamada.',
    ['Buscador', 'Fichas de una sola mirada', 'Conectado con el asistente'], 1);
  await dormir(5000);
  enfocar('.wiki-busca');
  await narrar('Y se busca escribiendo. Lo que encuentra, lo resalta.',
    ['Buscador', 'Fichas de una sola mirada', 'Conectado con el asistente'], 1);
  await clicEn('#wikiQ');
  for (const ch of 'desgravamen') {
    $('wikiQ').value += ch;
    $('wikiQ').dispatchEvent(new Event('input', {bubbles:true}));
    await dormir(110);
  }
  await dormir(2600);
  enfocar('.wiki-art-meta');
  await narrar('Y lo mejor: desde cualquier artículo el asesor se lo puede pedir resumido al asistente, para explicárselo al cliente con sus palabras.',
    ['Buscador', 'Fichas de una sola mirada', 'Conectado con el asistente'], 2);
  await dormir(3000);
  enfocar(null);
}},

{ n:'Cierre', titulo:'Todo conectado, en un solo lugar', corre: async () => {
  irA('clientes');               // vuelve a la consola para el cartel final
  await narrar('Eso es la consola. Un asesor, una pantalla, y todo lo que necesita para vender sin salir de ella.',
    ['Un solo inicio de sesión', 'Cuatro canales y una ficha', 'Con IA que lo acompaña'], 0);
  mostrarCierre();
  // El mensaje de toda la presentación, dicho con todas las letras: no
  // alcanza con mostrar los íconos del cierre, hay que nombrarlos.
  await narrar('La llamada, el WhatsApp, el correo y el mensaje de texto son el mismo expediente. Lo que se conversa por un canal queda a la vista en los otros, y cualquier asesor que atienda después ve la historia completa.',
    ['Un solo inicio de sesión', 'Cuatro canales y una ficha', 'Con IA que lo acompaña'], 1);
  await narrar('Y encima de todo eso, un asistente que escucha la llamada, sugiere qué responder y busca la respuesta en el material de la empresa, sin que el asesor deje de hablar con su cliente.',
    ['Un solo inicio de sesión', 'Cuatro canales y una ficha', 'Con IA que lo acompaña'], 2);
  await dormir(4000);
}}

];

/* ------------------------------------------------- reloj de la llamada */
let cronoT = null, cronoSeg = 0;
function cronoArrancar() {
  cronoSeg = 0;
  cronoT = setInterval(() => {
    cronoSeg++;
    $('callTimer').textContent = String(Math.floor(cronoSeg / 60)).padStart(2, '0')
      + ':' + String(cronoSeg % 60).padStart(2, '0');
  }, 1000);
}
function cronoParar() { if (cronoT) { clearInterval(cronoT); cronoT = null; } }

function mostrarCierre() {
  const canal = (ico, t) => el('span', {cls:'cierre-canal'}, svg(ico, 'ico ico--fill'), t);
  const c = el('div', {cls:'cierre'},
    el('div', null,
      el('h2', {txt:'Todo el contacto con su cliente, en una sola pantalla'}),
      el('p', {txt:'Sin cambiar de programa, sin copiar datos de un sistema a otro y sin perder una sola gestión.'}),
      el('div', {cls:'cierre-canales'},
        canal('tel-f', 'Llamadas'), canal('wa-f', 'WhatsApp'),
        canal('mail-f', 'Correo'), canal('sms-f', 'SMS'),
        canal('gestion', 'Tipificación'), canal('rayo', 'Asistente IA')),
      el('button', {cls:'cierre-rep', type:'button', txt:'Ver de nuevo',
        onclick:() => { c.remove(); reiniciar(); }})));
  $('pantalla').append(c);
  requestAnimationFrame(() => c.classList.add('is-ahi'));
}

/* ====================================================================== */
/*  REPRODUCTOR                                                           */
/* ====================================================================== */

function pintarIndice() {
  $('narraIdx').replaceChildren(...CAPITULOS.map((c, i) =>
    el('button', {type:'button', txt:c.n, 'aria-current':String(i === P.i),
      onclick:() => saltarA(i)})));
}

function pintarCabeceraCap() {
  const c = CAPITULOS[P.i];
  $('narraN').textContent = String(P.i + 1);
  $('narraTitulo').textContent = c.titulo;
  $('narraCap').textContent = 'Capítulo ' + (P.i + 1) + ' de ' + CAPITULOS.length;
  $('narraBarra').style.width = Math.round((P.i) / CAPITULOS.length * 100) + '%';
  pintarIndice();
}

/* Un solo lazo en toda la presentación. Antes, el botón de reanudar
   arrancaba otro encima del que ya estaba corriendo y los dos escribían a
   la vez: de ahí las frases repetidas. */
async function correr() {
  if (P.lazo) return;
  P.lazo = true;
  try {
    while (P.i < CAPITULOS.length) {
      if (!P.corriendo) { await new Promise(r => setTimeout(r, 120)); continue; }
      pintarCabeceraCap();
      const actual = P.i, gen = P.gen;
      await CAPITULOS[P.i].corre();
      await vozAlDia();                 // que termine de hablar antes de pasar
      if (gen !== P.gen) continue;      // alguien saltó mientras corría
      if (P.i === actual) P.i++;
    }
    P.corriendo = false;
    $('btnPausa').textContent = '⏵';
  } finally { P.lazo = false; }
}

function saltarA(i) {
  P.gen++;                              // la pasada anterior queda abandonada
  if (VOZ.audio) { VOZ.audio.pause(); VOZ.audio = null; }
  VOZ.cola = Promise.resolve();         // lo encolado de la pasada anterior se descarta
  P.i = Math.max(0, Math.min(CAPITULOS.length - 1, i));
  reiniciarEscena();
  P.corriendo = true;
  $('btnPausa').textContent = '⏸';
  correr();
}

/* Cada capítulo deja la pantalla en un estado; al saltar hay que devolverla
   a un punto conocido o el recorrido se ve roto. */
function reiniciarEscena() {
  cronoParar();
  enfocar(null);
  cerrarCapa();
  guardarHojaGestion();
  document.querySelector('.cierre')?.remove();
  $('puntero').hidden = true;
  $('loginDemo').hidden = P.i > 0;
  $('loginDemo').classList.toggle('is-ido', P.i > 0);
  $('trxBody').replaceChildren();
  $('asisBody').replaceChildren();
  $('asisTexto').value = '';
  $('tipNota').value = '';
  $('ctlLead').hidden = true;
  $('ctlHangup').hidden = true;
  $('ctlPause').hidden = true;
  $('ctlPauseWrap').hidden = false;
  $('agentState').textContent = 'Disponible';
  $('callState').dataset.on = 'false';
  $('callLabel').textContent = 'Sin llamada';
  $('callTimer').textContent = '00:00';
  $('trxEstado').textContent = 'en espera';
  pintarGestionArbol();
  irA('clientes');
  if (P.i >= 3) { pintarCartera(CLIENTE.id); pintarFicha('Resumen', CLIENTE); }
  else if (P.i === 2) { pintarCartera(CLIENTE_PREVIO.id); pintarFicha('Resumen', CLIENTE_PREVIO); }
  else { pintarCartera(); pintarBienvenida(); }
}

function reiniciar() { CARTERA[0].cod = null; saltarA(0); }

function arrancar() {
  $('portada').classList.add('is-ido');
  setTimeout(() => { $('portada').hidden = true; }, 500);
  $('escena').hidden = false;
  requestAnimationFrame(() => saltarA(0));
}

/* ---------------------------------------------------------- arranque */
document.addEventListener('DOMContentLoaded', () => {
  pintarMarco();
  pintarBienvenida();
  ondaMontar();
  irA('clientes');
  $('asisChips').replaceChildren(...[
    '¿Cuáles son las tasas vigentes?', '¿Qué documentos necesita?', '¿Cuál es el plazo máximo?'
  ].map(t => el('button', {type:'button', txt:t, title:t})));

  $('pantalla').append(el('span', {cls:'sello', txt:'Demostración'}));

  $('btnPlay').addEventListener('click', arrancar);
  // Pausar sólo levanta la bandera: el lazo ya está corriendo y la espera
  // la respeta. Arrancar otro acá fue lo que duplicó las líneas.
  $('btnPausa').addEventListener('click', () => {
    P.corriendo = !P.corriendo;
    $('btnPausa').textContent = P.corriendo ? '⏸' : '⏵';
    if (VOZ.audio) { if (P.corriendo) VOZ.audio.play().catch(() => {}); else VOZ.audio.pause(); }
    estado(P.corriendo ? '' : 'Recorrido en pausa.');
  });

  // Silencio: para quien prefiera narrar en vivo. Sin voz, los tiempos
  // escritos mandan y el recorrido dura lo mismo que antes de grabarla.
  $('btnVoz').addEventListener('click', () => {
    VOZ.silencio = !VOZ.silencio;
    $('btnVoz').textContent = VOZ.silencio ? '🔇' : '🔊';
    $('btnVoz').title = VOZ.silencio ? 'Activar la voz' : 'Silenciar';
    if (VOZ.silencio && VOZ.audio) { VOZ.audio.pause(); VOZ.audio = null; }
  });
  $('btnPrev').addEventListener('click', () => saltarA(P.i - 1));
  $('btnNext').addEventListener('click', () => saltarA(P.i + 1));
  pintarIndice();

  const mins = Math.round(CAPITULOS.length * 0.42);
  $('portadaDur').textContent = 'unos ' + mins + ' minutos';
});
