'use strict';
/* Consola de Atención Telefónica — Zynervox CRM (maqueta sin backend).
 *
 * Reescritura propia: reemplaza al motor genérico demo.js + gui-layout.json,
 * que reproducía un formulario WinForms con 353 controles en coordenadas
 * absolutas. Aquí la pantalla se construye con rejillas densas y la lógica de
 * negocio queda explícita.
 *
 * Se conserva la lógica del CRM original:
 *   - tipificación en cascada de tres niveles; el tercero decide el desenlace
 *     (venta / rellamada / simple), igual que el árbol `gestion` del backend;
 *   - "venta confirmada" exige propuesta; "volver a llamar" exige agenda;
 *   - las validaciones de la propuesta son las que aplicaba el servidor
 *     (campos exigidos, importes, % de retorno, fecha de nacimiento);
 *   - terminar la atención pasa al siguiente contacto de la cola.
 *
 * Nada se persiste: el estado vive en memoria mientras dura la visita.
 */

/* ------------------------------------------------------------------ datos */

// Árbol de tipificación. Reproduce el `gestion` del backend original: el nodo
// hoja lleva el desenlace, que es lo que dispara propuesta o agenda.
const TIPIFICACION = [
  {label:'CONTACTADO', hijos:[
    {label:'SEGUIMIENTO', hijos:[
      {label:'INTERESADO', kind:'normal'},
      {label:'VOLVER A LLAMAR', kind:'recall'}]},
    {label:'VENTA', hijos:[
      {label:'VENTA CONFIRMADA', kind:'sale'}]},
    {label:'SIN INTERÉS', hijos:[
      {label:'NO INTERESADO', kind:'normal'},
      {label:'NO AUTORIZA DATOS', kind:'normal'}]}]},
  {label:'NO CONTACTADO', hijos:[
    {label:'NO CONTESTA', hijos:[
      {label:'REINTENTAR', kind:'normal'},
      {label:'AGENDAR LLAMADA', kind:'recall'}]},
    {label:'NÚMERO ERRADO', hijos:[
      {label:'NO CORRESPONDE', kind:'normal'}]}]}
];

const DESENLACE = {
  sale:   'Requiere registrar la propuesta de venta.',
  recall: 'Requiere agendar fecha y hora de rellamada.',
  normal: 'Se registra sin trámite adicional.'
};

// Catálogos de la propuesta (los mismos del escritorio original).
const OPC = {
  CbTIPO_DOCUMENTO:['DNI','CE','PASAPORTE'],
  CbSexo:['FEMENINO','MASCULINO'],
  CbESTADO_CIVIL:['SOLTERO/A','CASADO/A','CONVIVIENTE','DIVORCIADO/A','VIUDO/A'],
  CbSituacionLaboral:['DEPENDIENTE','INDEPENDIENTE','OTRO'],
  CbNivelEducacion:['SECUNDARIA','TÉCNICA','UNIVERSITARIA'],
  CbDEPARTAMENTO:['LIMA','AREQUIPA'],
  CbPROVINCIA:['LIMA','AREQUIPA'],
  CbDISTRITO:['LIMA','MIRAFLORES','SAN ISIDRO'],
  CbTipoSeguro:['VIDA','INVERSION_SEGURA'],
  CbPROTECCION:['10,000','20,000','50,000'],
  CbTIEMPO:['5 AÑOS','10 AÑOS','20 AÑOS'],
  CmbMoneda:['SOLES','DOLARES'],
  CbFRECUENCIA_PAGO:['MENSUAL','TRIMESTRAL','SEMESTRAL','ANUAL'],
  CbCanalVentas:['TELEVENTAS','REFERIDO'],
  CbMedioLLamada:['ENTRANTE','SALIENTE'],
  CbMarcaTarjeta:['NO APLICA','VISA','MASTERCARD','AMERICAN EXPRESS'],
  CbTipoTarjeta:['NO APLICA','CRÉDITO','DÉBITO'],
  CmbBanco:['BANCO DEMO'],
  CbTipoEstado:['PENDIENTE','VALIDADO','OBSERVADO'],
  CbEstado:['POR REVISAR','APROBADO','RECHAZADO'],
  CbSubEstado:['EN PROCESO','COMPLETO','SUBSANAR'],
  cbTipoTelefono:['MÓVIL','FIJO','TRABAJO','REFERENCIA']
};

// Definición de la propuesta de venta, por secciones. `req` marca los campos
// que el servidor original exigía antes de aceptar la venta.
const VENTA = [
  {sec:'Titular', campos:[
    {id:'TxtPRIMER_NOMBRE',   et:'Primer nombre',    req:true},
    {id:'TxtSEGUNDO_NOMBRE',  et:'Segundo nombre'},
    {id:'TxtAPELLIDO_PATERNO',et:'Apellido paterno', req:true},
    {id:'TxtAPELLIDO_MATERNO',et:'Apellido materno'},
    {id:'CbTIPO_DOCUMENTO',   et:'Tipo documento',   req:true, opc:'CbTIPO_DOCUMENTO'},
    {id:'TxtNUMERO_DOCUMENTO',et:'N.º documento',    req:true},
    {id:'CbSexo',             et:'Sexo',             req:true, opc:'CbSexo'},
    {id:'dtFECHA_NACIMIENTO', et:'Fecha nacimiento', tipo:'date'},
    {id:'CbESTADO_CIVIL',     et:'Estado civil',     opc:'CbESTADO_CIVIL'},
    {id:'CbSituacionLaboral', et:'Situación laboral',opc:'CbSituacionLaboral'},
    {id:'CbNivelEducacion',   et:'Nivel educación',  opc:'CbNivelEducacion'}]},
  {sec:'Contacto y domicilio', campos:[
    {id:'TxtCORREO_01', et:'Correo', ancho:2},
    {id:'TxtMOVIL_01',  et:'Teléfono móvil'},
    {id:'CbDEPARTAMENTO',et:'Departamento', opc:'CbDEPARTAMENTO'},
    {id:'CbPROVINCIA',  et:'Provincia', opc:'CbPROVINCIA'},
    {id:'CbDISTRITO',   et:'Distrito',  opc:'CbDISTRITO'},
    {id:'TxtDIRECCION', et:'Dirección', ancho:2}]},
  {sec:'Producto', campos:[
    {id:'TxtPoliza',        et:'Póliza'},
    {id:'CbTipoSeguro',     et:'Tipo de seguro',   req:true, opc:'CbTipoSeguro'},
    {id:'CbPROTECCION',     et:'Monto cobertura',  req:true, opc:'CbPROTECCION'},
    {id:'CbTIEMPO',         et:'Vigencia',         req:true, opc:'CbTIEMPO'},
    {id:'CmbMoneda',        et:'Moneda',           opc:'CmbMoneda'},
    {id:'TxtIMPORTE_PACTADO',et:'Prima pactada',   req:true, dinero:true, positivo:true},
    {id:'CbFRECUENCIA_PAGO',et:'Periodicidad pago',req:true, opc:'CbFRECUENCIA_PAGO'},
    {id:'CbCanalVentas',    et:'Canal de venta',   opc:'CbCanalVentas'},
    {id:'CbMedioLLamada',   et:'Medio de llamada', req:true, opc:'CbMedioLLamada'},
    {id:'TxtTrea',          et:'TREA',             dinero:true},
    {id:'TxtPorcentajeRetorno',et:'% de retorno',  dinero:true, tope:100},
    {id:'TxtMontoRetorno',  et:'Monto retorno',    dinero:true}]},
  {sec:'Forma de pago', campos:[
    {id:'CbMarcaTarjeta',et:'Marca de tarjeta', req:true, opc:'CbMarcaTarjeta'},
    {id:'CbTipoTarjeta', et:'Tipo de tarjeta',  opc:'CbTipoTarjeta'},
    {id:'CmbBanco',      et:'Banco',            opc:'CmbBanco'},
    {id:'TxtNroCuenta',  et:'N.º de cuenta'},
    {id:'dtFECHA_PAGO',  et:'Fecha de pago',    tipo:'date'}]},
  {sec:'Estado y consentimiento', campos:[
    {id:'CbTipoEstado', et:'Tipo de estado', opc:'CbTipoEstado'},
    {id:'CbEstado',     et:'Estado',         opc:'CbEstado'},
    {id:'CbSubEstado',  et:'Subestado',      opc:'CbSubEstado'},
    {id:'CheckBoxLDPD', et:'Autoriza tratamiento de datos (LDPD)', tipo:'check'},
    {id:'CheckBoxConsentimiento', et:'Consentimiento de grabación', tipo:'check'},
    {id:'TxtNotaAgente',et:'Nota del agente', tipo:'area'}]}
];

/* ----------------------------------------------------------------- estado */

const hoy = () => new Date().toLocaleDateString('en-CA');

const S = {
  operador: document.body.dataset.crmUser || 'OPERADOR',
  salida: document.body.dataset.crmExit || 'index.php',
  modo: 'contactos',
  filtro: '',
  criterio: 'TODO',
  desde: hoy(),
  hasta: hoy(),
  actual: 0,
  telEdit: null,
  tip: [null, null, null],   // índices elegidos en cada nivel
  nota: '',
  propuesta: null,           // borrador de venta de la gestión en curso
  agenda: null,              // borrador de rellamada de la gestión en curso
  contactos: [
    {id:1001, documento:'00000001', nombre:'ANA DEMO PÉREZ',   correo:'ana@example.invalid',   direccion:'DIRECCIÓN DE DEMOSTRACIÓN 123', poliza:'DEMO-001',
     campania:'CRM VENTAS', base:'BASE DEMO', moneda:'SOLES', producto:'VIDA', prima:'100.00', vigencia:'10 AÑOS',
     telefonos:[{n:'900000001', t:'MÓVIL'}, {n:'900000004', t:'TRABAJO'}]},
    {id:1002, documento:'00000002', nombre:'LUIS DEMO TORRES', correo:'luis@example.invalid',  direccion:'DIRECCIÓN DE DEMOSTRACIÓN 456', poliza:'DEMO-002',
     campania:'CRM VENTAS', base:'BASE DEMO', moneda:'SOLES', producto:'VIDA', prima:'150.00', vigencia:'20 AÑOS',
     telefonos:[{n:'900000002', t:'MÓVIL'}]},
    {id:1003, documento:'00000003', nombre:'MARÍA DEMO ROJAS', correo:'maria@example.invalid', direccion:'DIRECCIÓN DE DEMOSTRACIÓN 789', poliza:'DEMO-003',
     campania:'CRM VENTAS', base:'BASE DEMO', moneda:'DOLARES', producto:'VIDA', prima:'90.00', vigencia:'5 AÑOS',
     telefonos:[{n:'900000003', t:'MÓVIL'}]}
  ],
  gestiones: [
    {id:1, contacto:1001, fecha:new Date().toLocaleString('es-PE'), asesor:'OPERADOR DEMO',
     n1:'CONTACTADO', n2:'SEGUIMIENTO', n3:'INTERESADO', nota:'Registro ficticio para la demostración visual.'}
  ],
  ventas: [
    {id:1, contacto:1002, fecha:hoy(), asesor:'OPERADOR DEMO', estado:'PENDIENTE', subestado:'POR REVISAR', prima:'150.00'}
  ],
  agendas: [
    {id:1, contacto:1003, fecha:hoy(), hora:'10:00', nota:'RELLAMADA DEMO'}
  ]
};
let seqGestion = 2, seqVenta = 2, seqAgenda = 2;

const contacto = () => S.contactos[S.actual] || S.contactos[0];

/* -------------------------------------------------------------- utilidades */

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

function tabla(cols, filas, opc) {
  const o = opc || {};
  if (!filas.length) return el('div', {cls:'empty', txt:o.vacio || 'Sin registros.'});
  const thead = el('thead', null, el('tr', null, cols.map(c => el('th', {txt:c.t, style:c.w ? 'width:' + c.w : null}))));
  const tbody = el('tbody', null, filas.map((f, i) => {
    const tr = el('tr', {'data-pick': o.pick ? true : null}, cols.map(c => {
      const v = f[c.k];
      return el('td', {cls:c.num ? 'num' : null, title:typeof v === 'string' ? v : null}, c.render ? c.render(f, i) : String(v ?? ''));
    }));
    if (o.activo && o.activo(f)) tr.classList.add('is-active');
    if (o.pick) tr.addEventListener('click', () => o.pick(f, i));
    return tr;
  }));
  return el('table', null, thead, tbody);
}

function aviso(titulo, texto, acciones) {
  const btns = acciones || [['Aceptar', () => {}]];
  const hoja = el('div', {cls:'sheet'},
    el('header', null, el('span', {txt:titulo})),
    el('div', {cls:'sheet-body'}, el('div', {cls:'sheet-msg', txt:texto})),
    el('div', {cls:'bar bar--end'}, btns.map(([t, fn], i) =>
      el('button', {cls:'btn-sm' + (i === 0 ? ' btn-sm--primary' : ''), onclick:() => { cerrarCapa(); fn(); }, txt:t})))
  );
  abrirCapa(hoja);
}

function abrirCapa(hoja) {
  const capa = $('layer');
  capa.replaceChildren(hoja);
  capa.hidden = false;
  capa.querySelector('input,select,textarea,button')?.focus();
}
function cerrarCapa() {
  const capa = $('layer');
  capa.hidden = true;
  capa.replaceChildren();
}

function estado(texto) { $('msg').textContent = texto; }

/* ------------------------------------------------------- columna 1: cola */

const MODOS = [
  {id:'contactos', et:'Contactos'},
  {id:'asignados', et:'Asignados'},
  {id:'agenda',    et:'Agenda'},
  {id:'ventas',    et:'Ventas'}
];

function pintarCola() {
  $('queueModes').replaceChildren(...MODOS.map(m =>
    el('button', {type:'button', 'aria-pressed':String(S.modo === m.id), txt:m.et,
      onclick:() => { S.modo = m.id; S.filtro = ''; pintarCola(); }})));

  // El filtro cambia con el modo: texto+criterio para listas de contactos,
  // rango de fechas para agenda y ventas.
  const f = $('queueFilter');
  if (S.modo === 'agenda' || S.modo === 'ventas') {
    f.replaceChildren(
      el('input', {type:'date', value:S.desde, title:'Desde', oninput:e => { S.desde = e.target.value; pintarLista(); }}),
      el('input', {type:'date', value:S.hasta, title:'Hasta', oninput:e => { S.hasta = e.target.value; pintarLista(); }})
    );
  } else {
    f.replaceChildren(
      el('select', {title:'Criterio', onchange:e => { S.criterio = e.target.value; pintarLista(); }},
        ['TODO','DNI','NOMBRE','TELÉFONO','ID'].map(v => el('option', {value:v, txt:v, selected:S.criterio === v}))),
      el('input', {type:'search', placeholder:'Filtrar…', value:S.filtro, oninput:e => { S.filtro = e.target.value; pintarLista(); }}),
      el('button', {type:'button', title:'Limpiar', txt:'×', onclick:() => { S.filtro = ''; pintarCola(); }})
    );
  }
  pintarLista();
}

function coincide(c) {
  const q = S.filtro.trim().toLocaleLowerCase('es');
  if (!q) return true;
  const campo = {DNI:c.documento, NOMBRE:c.nombre, 'TELÉFONO':(c.telefonos[0] || {}).n, ID:String(c.id)}[S.criterio];
  const donde = campo !== undefined ? String(campo) : [c.id, c.documento, c.nombre, (c.telefonos[0] || {}).n].join(' ');
  return donde.toLocaleLowerCase('es').includes(q);
}

function enRango(f) { return (!S.desde || f >= S.desde) && (!S.hasta || f <= S.hasta); }
const nombreDe = id => (S.contactos.find(c => c.id === id) || {}).nombre || '—';

function pintarLista() {
  const cuerpo = $('queueBody');
  let vista;
  if (S.modo === 'agenda') {
    const filas = S.agendas.filter(a => enRango(a.fecha));
    vista = tabla([
      {t:'Fecha', k:'fecha', w:'74px'},
      {t:'Hora', k:'hora', w:'44px'},
      {t:'Contacto', k:'quien'}
    ], filas.map(a => ({...a, quien:nombreDe(a.contacto)})),
      {vacio:'Sin rellamadas en el rango.', pick:a => irA(a.contacto, 'Rellamada agendada abierta.')});
  } else if (S.modo === 'ventas') {
    const filas = S.ventas.filter(v => enRango(v.fecha));
    vista = tabla([
      {t:'N.º', k:'id', w:'38px', num:true},
      {t:'Contacto', k:'quien'},
      {t:'Estado', k:'estado', w:'78px'}
    ], filas.map(v => ({...v, quien:nombreDe(v.contacto)})),
      {vacio:'Sin ventas en el rango.', pick:v => irA(v.contacto, 'Contacto de la venta abierto.')});
  } else {
    const filas = S.contactos.filter(coincide);
    vista = tabla([
      {t:'ID', k:'id', w:'42px', num:true},
      {t:'Nombre', k:'nombre'},
      {t:'Tel.', k:'tel', w:'76px'}
    ], filas.map(c => ({...c, tel:(c.telefonos[0] || {}).n || '—'})),
      {vacio:'Sin resultados.', activo:c => c.id === contacto().id,
       pick:c => irA(c.id, 'Contacto ' + c.id + ' cargado.')});
  }
  cuerpo.replaceChildren(vista);
  $('queueCount').textContent = (vista.tagName === 'TABLE' ? vista.querySelectorAll('tbody tr').length : 0) + ' reg.';
}

function irA(id, msg) {
  const i = S.contactos.findIndex(c => c.id === id);
  if (i < 0) { aviso('Contacto', 'El contacto ' + id + ' no está en la cola cargada.'); return; }
  S.actual = i;
  limpiarGestion();
  pintarTodo();
  estado(msg || 'Contacto ' + id + ' cargado.');
}

/* --------------------------------------------- columna 2: ficha y tablas */

function pintarFicha() {
  const c = contacto();
  $('cHeadId').textContent = c.id;
  $('cHeadName').textContent = c.nombre;
  $('cHeadDoc').innerHTML = 'Doc. <b>' + c.documento + '</b> · Campaña <b>' + c.campania + '</b> · Base <b>' + c.base + '</b>';

  const datos = [
    ['Documento', c.documento], ['Teléfono', (c.telefonos[0] || {}).n || '—'],
    ['Correo', c.correo],       ['Dirección', c.direccion],
    ['Campaña', c.campania],    ['Base', c.base],
    ['Póliza', c.poliza],       ['Moneda', c.moneda],
    ['Producto', c.producto],   ['Prima informada', c.prima],
    ['Vigencia', c.vigencia],   ['Gestiones', String(S.gestiones.filter(g => g.contacto === c.id).length)]
  ];
  $('cData').replaceChildren(...datos.flatMap(([k, v]) =>
    [el('dt', {txt:k}), el('dd', {txt:String(v || '—'), title:String(v || '')})]));
}

function pintarTelefonos() {
  const c = contacto();
  $('telCount').textContent = c.telefonos.length + ' reg.';
  $('telBody').replaceChildren(tabla([
    {t:'#', k:'i', w:'28px', num:true},
    {t:'Tipo', k:'t', w:'90px'},
    {t:'Número', k:'n'},
    {t:'', k:'acc', w:'62px', render:(f, i) => el('span', null,
      el('button', {cls:'btn-sm', style:'height:17px;padding:0 5px;margin-right:3px', txt:'Editar',
        onclick:ev => { ev.stopPropagation(); editarTelefono(i); }}),
      el('button', {cls:'btn-sm btn-sm--danger', style:'height:17px;padding:0 5px', txt:'×', title:'Eliminar',
        onclick:ev => { ev.stopPropagation(); borrarTelefono(i); }}))}
  ], c.telefonos.map((t, i) => ({...t, i:i + 1})), {vacio:'El contacto no tiene teléfonos registrados.'}));
}

function editarTelefono(i) {
  const c = contacto();
  S.telEdit = i;
  $('telNum').value = c.telefonos[i].n;
  $('telTipo').value = c.telefonos[i].t;
  $('telSave').textContent = 'Actualizar';
  $('telNum').focus();
}

function borrarTelefono(i) {
  const c = contacto();
  if (c.telefonos.length === 1) { aviso('Teléfonos', 'El contacto debe conservar al menos un teléfono.'); return; }
  aviso('Eliminar teléfono', '¿Quitar el número ' + c.telefonos[i].n + ' de este contacto?', [
    ['Eliminar', () => { c.telefonos.splice(i, 1); S.telEdit = null; limpiarFormTelefono(); pintarTelefonos(); pintarFicha(); estado('Teléfono eliminado.'); }],
    ['Cancelar', () => {}]
  ]);
}

function limpiarFormTelefono() {
  $('telNum').value = '';
  $('telSave').textContent = 'Agregar';
  S.telEdit = null;
}

function guardarTelefono() {
  const c = contacto();
  const n = $('telNum').value.trim();
  const t = $('telTipo').value;
  if (!/^\+?[\d ()-]{6,30}$/.test(n)) { aviso('Teléfonos', 'Ingresa un número válido de al menos 6 dígitos.'); return; }
  const dup = c.telefonos.findIndex(x => x.n === n);
  if (dup >= 0 && dup !== S.telEdit) { aviso('Teléfonos', 'Ese número ya está registrado en el contacto.'); return; }
  if (S.telEdit === null) c.telefonos.push({n, t});
  else c.telefonos[S.telEdit] = {n, t};
  estado(S.telEdit === null ? 'Teléfono agregado.' : 'Teléfono actualizado.');
  limpiarFormTelefono();
  pintarTelefonos();
  pintarFicha();
}

function pintarHistorial() {
  const c = contacto();
  const filas = S.gestiones.filter(g => g.contacto === c.id).slice().reverse();
  $('histCount').textContent = filas.length + ' reg.';
  $('histBody').replaceChildren(tabla([
    {t:'N.º', k:'id', w:'40px', num:true},
    {t:'Fecha', k:'fecha', w:'135px'},
    {t:'Asesor', k:'asesor', w:'130px'},
    {t:'Nivel 1', k:'n1', w:'110px'},
    {t:'Nivel 2', k:'n2', w:'115px'},
    {t:'Nivel 3', k:'n3', w:'135px'},
    {t:'Nota', k:'nota'}
  ], filas, {vacio:'Este contacto todavía no tiene gestiones registradas.'}));
}

/* ------------------------------------------ columna 3: panel de gestión */

function nivel(i) {
  if (i === 0) return TIPIFICACION;
  const padre = nivel(i - 1)[S.tip[i - 1]];
  return padre ? (padre.hijos || []) : [];
}
const hoja = () => (S.tip[2] !== null ? nivel(2)[S.tip[2]] : null);

function pintarGestion() {
  for (let i = 0; i < 3; i++) {
    const sel = $('tip' + i);
    const ops = nivel(i);
    sel.replaceChildren(
      el('option', {value:'', txt:i === 0 ? '— Seleccionar —' : (ops.length ? '— Seleccionar —' : 'Depende del nivel anterior')}),
      ...ops.map((o, k) => el('option', {value:String(k), txt:o.label, selected:S.tip[i] === k}))
    );
    sel.disabled = i > 0 && !ops.length;
    sel.closest('.field').classList.toggle('field--on', S.tip[i] !== null);
  }
  const h = hoja();
  const out = $('tipOut');
  out.textContent = h ? DESENLACE[h.kind] : 'Elige los tres niveles para habilitar el registro.';
  if (h) out.dataset.kind = h.kind; else delete out.dataset.kind;

  // Avisos de trámite pendiente según el desenlace elegido.
  const pend = $('tipPend');
  if (h && h.kind === 'sale') {
    pend.hidden = false;
    pend.innerHTML = S.propuesta ? 'Propuesta <b>preparada</b>. Se adjuntará al grabar.' : 'Falta la <b>propuesta de venta</b>.';
    $('tipPendBtn').textContent = S.propuesta ? 'Revisar propuesta' : 'Registrar propuesta';
    $('tipPendBtn').onclick = abrirVenta;
    $('tipPendBtn').hidden = false;
  } else if (h && h.kind === 'recall') {
    pend.hidden = false;
    pend.innerHTML = S.agenda ? ('Rellamada <b>' + S.agenda.fecha + ' ' + S.agenda.hora + '</b> lista.') : 'Falta <b>agendar</b> la rellamada.';
    $('tipPendBtn').textContent = S.agenda ? 'Cambiar agenda' : 'Agendar rellamada';
    $('tipPendBtn').onclick = abrirAgenda;
    $('tipPendBtn').hidden = false;
  } else {
    pend.hidden = true;
    $('tipPendBtn').hidden = true;
  }
  $('btnGrabar').disabled = !h;
}

function elegirNivel(i, valor) {
  S.tip[i] = valor === '' ? null : Number(valor);
  for (let k = i + 1; k < 3; k++) S.tip[k] = null;   // la cascada invalida lo de abajo
  // Cambiar de rama descarta trámites que ya no aplican.
  const h = hoja();
  if (!h || h.kind !== 'sale') S.propuesta = null;
  if (!h || h.kind !== 'recall') S.agenda = null;
  pintarGestion();
  if (h && h.kind === 'sale' && !S.propuesta) abrirVenta();
  if (h && h.kind === 'recall' && !S.agenda) abrirAgenda();
}

function limpiarGestion() {
  S.tip = [null, null, null];
  S.nota = '';
  S.propuesta = null;
  S.agenda = null;
  const n = $('tipNota');
  if (n) n.value = '';
  limpiarFormTelefono();
}

function grabarGestion() {
  const h = hoja();
  if (!h) { aviso('Registro de gestión', 'Selecciona los tres niveles de tipificación.'); return; }
  if (h.kind === 'sale' && !S.propuesta) { aviso('Registro de gestión', 'Esta tipificación exige registrar la propuesta de venta.', [['Registrar', abrirVenta], ['Cancelar', () => {}]]); return; }
  if (h.kind === 'recall' && !S.agenda) { aviso('Registro de gestión', 'Esta tipificación exige agendar la rellamada.', [['Agendar', abrirAgenda], ['Cancelar', () => {}]]); return; }

  const c = contacto();
  S.gestiones.push({
    id:seqGestion++, contacto:c.id, fecha:new Date().toLocaleString('es-PE'), asesor:S.operador,
    n1:nivel(0)[S.tip[0]].label, n2:nivel(1)[S.tip[1]].label, n3:h.label, nota:$('tipNota').value.trim()
  });
  if (S.propuesta) {
    S.ventas.push({id:seqVenta++, contacto:c.id, fecha:hoy(), asesor:S.operador,
      estado:S.propuesta.CbTipoEstado || 'PENDIENTE', subestado:S.propuesta.CbEstado || 'POR REVISAR',
      prima:S.propuesta.TxtIMPORTE_PACTADO || '0.00'});
  }
  if (S.agenda) S.agendas.push({id:seqAgenda++, contacto:c.id, fecha:S.agenda.fecha, hora:S.agenda.hora, nota:S.agenda.nota});

  const extra = S.propuesta ? ' Propuesta adjuntada.' : (S.agenda ? ' Rellamada agendada.' : '');
  limpiarGestion();
  pintarTodo();
  estado('Gestión registrada.' + extra);
}

function terminarAtencion() {
  const c = contacto();
  S.actual = (S.actual + 1) % S.contactos.length;
  limpiarGestion();
  pintarTodo();
  estado('Atención de ' + c.id + ' finalizada. Siguiente contacto en pantalla.');
}

/* ------------------------------------------------- propuesta de venta */

function campoVenta(cp, valores) {
  const v = valores[cp.id];
  let control;
  if (cp.opc) {
    control = el('select', {id:'v_' + cp.id},
      el('option', {value:'', txt:''}),
      ...OPC[cp.opc].map(o => el('option', {value:o, txt:o, selected:v === o})));
  } else if (cp.tipo === 'check') {
    control = el('input', {type:'checkbox', id:'v_' + cp.id, checked:!!v});
  } else if (cp.tipo === 'area') {
    control = el('textarea', {id:'v_' + cp.id, maxlength:'2000'}, v || '');
  } else {
    control = el('input', {type:cp.tipo === 'date' ? 'date' : 'text', id:'v_' + cp.id,
      value:v || '', inputmode:cp.dinero ? 'decimal' : null, maxlength:'200'});
  }
  if (cp.tipo === 'check') {
    return el('div', {cls:'f f--check', 'data-f':cp.id}, control, el('label', {for:'v_' + cp.id, txt:cp.et}));
  }
  const et = el('label', {for:'v_' + cp.id}, cp.et, cp.req ? el('span', {cls:'req', txt:' *'}) : null);
  const ancho = cp.tipo === 'area' ? ' f--full' : (cp.ancho === 2 ? ' f--wide' : '');
  return el('div', {cls:'f' + ancho, 'data-f':cp.id}, et, control);
}

function abrirVenta() {
  const c = contacto();
  // Precarga con lo que ya se sabe del contacto, como hacía el escritorio.
  const partes = c.nombre.split(' ');
  const base = {
    TxtPRIMER_NOMBRE:partes[0] || '', TxtSEGUNDO_NOMBRE:'',
    TxtAPELLIDO_PATERNO:partes[1] || '', TxtAPELLIDO_MATERNO:partes.slice(2).join(' '),
    TxtNUMERO_DOCUMENTO:c.documento, TxtCORREO_01:c.correo, TxtMOVIL_01:(c.telefonos[0] || {}).n || '',
    TxtDIRECCION:c.direccion, TxtPoliza:c.poliza, CmbMoneda:c.moneda, CbTipoSeguro:c.producto,
    TxtIMPORTE_PACTADO:c.prima, CbTIEMPO:c.vigencia, TxtTrea:'0.00', TxtPorcentajeRetorno:'0',
    TxtMontoRetorno:'0.00', CbCanalVentas:'TELEVENTAS', CbTipoEstado:'PENDIENTE', CbEstado:'POR REVISAR', CbSubEstado:'EN PROCESO',
    // Valores de arranque de una campaña de televenta; el agente los cambia
    // si corresponde. Evitan errores de validación por campos obvios.
    CbMedioLLamada:'SALIENTE', CbMarcaTarjeta:'NO APLICA', CbTipoTarjeta:'NO APLICA'
  };
  const val = Object.assign(base, S.propuesta || {});

  const cuerpo = el('div', {cls:'grid4'},
    el('div', {cls:'form-error', id:'vErr', hidden:true}),
    VENTA.flatMap(g => [el('div', {cls:'sec', txt:g.sec}), ...g.campos.map(cp => campoVenta(cp, val))])
  );
  abrirCapa(el('div', {cls:'sheet', style:'width:min(1060px,calc(100vw - 20px))'},
    el('header', null,
      el('span', {txt:'Propuesta de venta'}),
      el('span', {cls:'spacer'}),
      el('span', {style:'font-weight:400;text-transform:none;letter-spacing:0', txt:c.id + ' · ' + c.nombre}),
      el('button', {type:'button', title:'Cerrar', txt:'×', onclick:cerrarCapa})),
    el('div', {cls:'sheet-body'}, cuerpo),
    el('div', {cls:'bar bar--end'},
      el('span', {style:'margin-right:auto;font-size:11px;color:var(--text-muted)', txt:'Los campos con * son obligatorios.'}),
      el('button', {cls:'btn-sm', txt:'Cancelar', onclick:cerrarCapa}),
      el('button', {cls:'btn-sm btn-sm--primary', txt:'Guardar propuesta', onclick:guardarVenta}))
  ));
}

// Reglas que aplicaba el servidor original antes de aceptar una venta.
function validarVenta(v) {
  const errores = [];
  const malos = [];
  for (const g of VENTA) for (const cp of g.campos) {
    const valor = String(v[cp.id] ?? '').trim();
    if (cp.req && !valor) { errores.push(cp.et); malos.push(cp.id); continue; }
    if (cp.dinero && valor) {
      const n = Number(valor.replace(',', '.'));
      if (!isFinite(n) || n < 0) { errores.push(cp.et + ': importe inválido'); malos.push(cp.id); continue; }
      if (cp.tope && n > cp.tope) { errores.push(cp.et + ': no puede superar ' + cp.tope); malos.push(cp.id); continue; }
      if (cp.positivo && n <= 0) { errores.push(cp.et + ': debe ser mayor que cero'); malos.push(cp.id); }
    }
  }
  // TREA se exige solo en inversión; la prima pactada siempre.
  if (v.CbTipoSeguro === 'INVERSION_SEGURA' && !(Number(v.TxtTrea) > 0)) {
    errores.push('TREA: obligatorio y mayor que cero para INVERSION_SEGURA'); malos.push('TxtTrea');
  }
  if (v.dtFECHA_NACIMIENTO && v.dtFECHA_NACIMIENTO > hoy()) {
    errores.push('Fecha de nacimiento: no puede ser futura'); malos.push('dtFECHA_NACIMIENTO');
  }
  return {errores, malos};
}

function guardarVenta() {
  const v = {};
  for (const g of VENTA) for (const cp of g.campos) {
    const n = $('v_' + cp.id);
    if (!n) continue;
    v[cp.id] = cp.tipo === 'check' ? n.checked : n.value.trim();
  }
  const {errores, malos} = validarVenta(v);
  document.querySelectorAll('.grid4 .f').forEach(f => f.classList.remove('is-bad'));
  const caja = $('vErr');
  if (errores.length) {
    malos.forEach(id => document.querySelector('.grid4 .f[data-f="' + id + '"]')?.classList.add('is-bad'));
    caja.hidden = false;
    caja.textContent = 'Revisa ' + errores.length + ' campo(s): ' + errores.slice(0, 4).join(' · ') + (errores.length > 4 ? ' …' : '');
    caja.scrollIntoView({block:'nearest'});
    return;
  }
  S.propuesta = v;
  cerrarCapa();
  pintarGestion();
  estado('Propuesta preparada. Queda adjunta al grabar la gestión.');
}

/* ----------------------------------------------------- agenda de rellamada */

function abrirAgenda() {
  const a = S.agenda || {fecha:hoy(), hora:'10:00', nota:''};
  abrirCapa(el('div', {cls:'sheet', style:'width:380px'},
    el('header', null, el('span', {txt:'Agendar rellamada'}), el('span', {cls:'spacer'}),
      el('button', {type:'button', title:'Cerrar', txt:'×', onclick:cerrarCapa})),
    el('div', {cls:'sheet-body'}, el('div', {cls:'grid4', style:'grid-template-columns:1fr 1fr'},
      el('div', {cls:'f'}, el('label', {for:'aFecha'}, 'Fecha', el('span', {cls:'req', txt:' *'})),
        el('input', {type:'date', id:'aFecha', value:a.fecha, min:hoy()})),
      el('div', {cls:'f'}, el('label', {for:'aHora'}, 'Hora', el('span', {cls:'req', txt:' *'})),
        el('input', {type:'time', id:'aHora', value:a.hora})),
      el('div', {cls:'f f--full'}, el('label', {for:'aNota', txt:'Nota'}),
        el('textarea', {id:'aNota', maxlength:'500'}, a.nota)))),
    el('div', {cls:'bar bar--end'},
      el('button', {cls:'btn-sm', txt:'Cancelar', onclick:cerrarCapa}),
      el('button', {cls:'btn-sm btn-sm--primary', txt:'Agendar', onclick:guardarAgenda}))
  ));
}

function guardarAgenda() {
  const fecha = $('aFecha').value, hora = $('aHora').value;
  if (!fecha || !hora) { aviso('Rellamada', 'Indica fecha y hora.'); return; }
  if (fecha < hoy()) { aviso('Rellamada', 'La fecha debe ser igual o posterior a hoy.'); return; }
  S.agenda = {fecha, hora, nota:$('aNota').value.trim()};
  cerrarCapa();
  pintarGestion();
  estado('Rellamada preparada para el ' + fecha + ' a las ' + hora + '.');
}

/* ------------------------------------------------------------- arranque */

function pintarTodo() {
  pintarFicha();
  pintarTelefonos();
  pintarHistorial();
  pintarGestion();
  pintarLista();
  $('leadCount').textContent = S.contactos.length;
}

function saltarAContacto() {
  const q = $('jump').value.trim();
  if (!q) return;
  const c = S.contactos.find(x => String(x.id) === q || x.documento === q);
  if (!c) { aviso('Buscar contacto', 'No hay ningún contacto con ID o documento «' + q + '» en la cola cargada.'); return; }
  $('jump').value = '';
  irA(c.id);
}

function init() {
  $('opName').textContent = S.operador;

  $('jumpGo').addEventListener('click', saltarAContacto);
  $('jump').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saltarAContacto(); } });
  $('exit').addEventListener('click', () => { top.location.href = S.salida; });

  for (let i = 0; i < 3; i++) $('tip' + i).addEventListener('change', e => elegirNivel(i, e.target.value));
  $('btnGrabar').addEventListener('click', grabarGestion);
  $('btnTerminar').addEventListener('click', terminarAtencion);

  $('telSave').addEventListener('click', guardarTelefono);
  $('telCancel').addEventListener('click', () => { limpiarFormTelefono(); estado('Edición de teléfono cancelada.'); });
  $('telNum').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); guardarTelefono(); } });
  $('telTipo').replaceChildren(...OPC.cbTipoTelefono.map(t => el('option', {value:t, txt:t})));

  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('layer').hidden) cerrarCapa(); });

  pintarCola();
  pintarTodo();
  estado('Listo. Maqueta de demostración: los datos son ficticios y no se guardan.');
}

init();
