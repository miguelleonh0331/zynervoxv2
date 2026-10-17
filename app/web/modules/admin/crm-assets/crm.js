'use strict';
/* Consola CRM de Atención Telefónica — Zynervox (maqueta sin backend).
 *
 * Tres zonas: cartera del asesor (izquierda), expediente del cliente (centro)
 * y panel de trabajo sobre la llamada en curso (derecha).
 *
 * Reglas del CRM original que se conservan:
 *   - tipificación en cascada de tres niveles; el nodo hoja fija el desenlace
 *     (venta / rellamada / simple), igual que el árbol `gestion` del backend;
 *   - "venta" exige propuesta y "rellamada" exige agenda antes de grabar;
 *   - validaciones de la propuesta heredadas del servidor (campos exigidos,
 *     importes, TREA según producto, % de retorno, fecha de nacimiento);
 *   - terminar la atención pasa al siguiente cliente de la cartera.
 *
 * Nada se persiste: el estado vive en memoria mientras dura la visita.
 */

/* --------------------------------------------------------------- catálogos */

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
  sale:'Requiere registrar la propuesta de venta.',
  recall:'Requiere agendar fecha y hora de rellamada.',
  normal:'Se registra sin trámite adicional.'
};

const OPC = {
  CbTIPO_DOCUMENTO:['DNI','CE','PASAPORTE'],
  CbSexo:['FEMENINO','MASCULINO'],
  CbESTADO_CIVIL:['SOLTERO/A','CASADO/A','CONVIVIENTE','DIVORCIADO/A','VIUDO/A'],
  CbSituacionLaboral:['DEPENDIENTE','INDEPENDIENTE','OTRO'],
  CbNivelEducacion:['SECUNDARIA','TÉCNICA','UNIVERSITARIA'],
  CbDEPARTAMENTO:['LIMA','AREQUIPA'], CbPROVINCIA:['LIMA','AREQUIPA'],
  CbDISTRITO:['LIMA','MIRAFLORES','SAN ISIDRO'],
  CbTipoSeguro:['VIDA','INVERSION_SEGURA','SOAT','RUMBO'],
  CbPROTECCION:['10,000','20,000','50,000'], CbTIEMPO:['5 AÑOS','10 AÑOS','20 AÑOS'],
  CmbMoneda:['SOLES','DOLARES'], CbFRECUENCIA_PAGO:['MENSUAL','TRIMESTRAL','SEMESTRAL','ANUAL'],
  CbCanalVentas:['TELEVENTAS','REFERIDO'], CbMedioLLamada:['ENTRANTE','SALIENTE'],
  CbMarcaTarjeta:['NO APLICA','VISA','MASTERCARD','AMERICAN EXPRESS'],
  CbTipoTarjeta:['NO APLICA','CRÉDITO','DÉBITO'], CmbBanco:['BANCO DEMO'],
  CbTipoEstado:['PENDIENTE','VALIDADO','OBSERVADO'],
  CbEstado:['POR REVISAR','APROBADO','RECHAZADO'],
  CbSubEstado:['EN PROCESO','COMPLETO','SUBSANAR'],
  cbTipoTelefono:['MÓVIL','FIJO','TRABAJO','REFERENCIA']
};

const VENTA = [
  {sec:'Titular', campos:[
    {id:'TxtPRIMER_NOMBRE',et:'Primer nombre',req:true},
    {id:'TxtSEGUNDO_NOMBRE',et:'Segundo nombre'},
    {id:'TxtAPELLIDO_PATERNO',et:'Apellido paterno',req:true},
    {id:'TxtAPELLIDO_MATERNO',et:'Apellido materno'},
    {id:'CbTIPO_DOCUMENTO',et:'Tipo documento',req:true,opc:'CbTIPO_DOCUMENTO'},
    {id:'TxtNUMERO_DOCUMENTO',et:'N.º documento',req:true},
    {id:'CbSexo',et:'Sexo',req:true,opc:'CbSexo'},
    {id:'dtFECHA_NACIMIENTO',et:'Fecha nacimiento',tipo:'date'},
    {id:'CbESTADO_CIVIL',et:'Estado civil',opc:'CbESTADO_CIVIL'},
    {id:'CbSituacionLaboral',et:'Situación laboral',opc:'CbSituacionLaboral'},
    {id:'CbNivelEducacion',et:'Nivel educación',opc:'CbNivelEducacion'}]},
  {sec:'Contacto y domicilio', campos:[
    {id:'TxtCORREO_01',et:'Correo',ancho:2},
    {id:'TxtMOVIL_01',et:'Teléfono móvil'},
    {id:'CbDEPARTAMENTO',et:'Departamento',opc:'CbDEPARTAMENTO'},
    {id:'CbPROVINCIA',et:'Provincia',opc:'CbPROVINCIA'},
    {id:'CbDISTRITO',et:'Distrito',opc:'CbDISTRITO'},
    {id:'TxtDIRECCION',et:'Dirección',ancho:2}]},
  {sec:'Producto', campos:[
    {id:'TxtPoliza',et:'Póliza'},
    {id:'CbTipoSeguro',et:'Tipo de seguro',req:true,opc:'CbTipoSeguro'},
    {id:'CbPROTECCION',et:'Monto cobertura',req:true,opc:'CbPROTECCION'},
    {id:'CbTIEMPO',et:'Vigencia',req:true,opc:'CbTIEMPO'},
    {id:'CmbMoneda',et:'Moneda',opc:'CmbMoneda'},
    {id:'TxtIMPORTE_PACTADO',et:'Prima pactada',req:true,dinero:true,positivo:true},
    {id:'CbFRECUENCIA_PAGO',et:'Periodicidad pago',req:true,opc:'CbFRECUENCIA_PAGO'},
    {id:'CbCanalVentas',et:'Canal de venta',opc:'CbCanalVentas'},
    {id:'CbMedioLLamada',et:'Medio de llamada',req:true,opc:'CbMedioLLamada'},
    {id:'TxtTrea',et:'TREA',dinero:true},
    {id:'TxtPorcentajeRetorno',et:'% de retorno',dinero:true,tope:100},
    {id:'TxtMontoRetorno',et:'Monto retorno',dinero:true}]},
  {sec:'Forma de pago', campos:[
    {id:'CbMarcaTarjeta',et:'Marca de tarjeta',req:true,opc:'CbMarcaTarjeta'},
    {id:'CbTipoTarjeta',et:'Tipo de tarjeta',opc:'CbTipoTarjeta'},
    {id:'CmbBanco',et:'Banco',opc:'CmbBanco'},
    {id:'TxtNroCuenta',et:'N.º de cuenta'},
    {id:'dtFECHA_PAGO',et:'Fecha de pago',tipo:'date'}]},
  {sec:'Estado y consentimiento', campos:[
    {id:'CbTipoEstado',et:'Tipo de estado',opc:'CbTipoEstado'},
    {id:'CbEstado',et:'Estado',opc:'CbEstado'},
    {id:'CbSubEstado',et:'Subestado',opc:'CbSubEstado'},
    {id:'CheckBoxLDPD',et:'Autoriza tratamiento de datos (LDPD)',tipo:'check'},
    {id:'CheckBoxConsentimiento',et:'Consentimiento de grabación',tipo:'check'},
    {id:'TxtNotaAgente',et:'Nota del agente',tipo:'area'}]}
];

// Iconos en línea (trazo, heredan color). Evitan cualquier archivo de imagen.
const ICO = {
  tel:'<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.1 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7A2 2 0 0 1 22 16.9z"/>',
  wa:'<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/>',
  mail:'<rect x="2" y="4" width="20" height="16" rx="1"/><polyline points="2,6 12,13 22,6"/>',
  sms:'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  nota:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
  gestion:'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
  prod:'<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
  money:'<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
  origen:'<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
  pin:'<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
  doc:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/>',
  mas:'<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>'
};
const CANALES = {
  llamada:{et:'Llamada', ico:'tel'}, whatsapp:{et:'WhatsApp', ico:'wa'},
  email:{et:'Correo', ico:'mail'}, sms:{et:'SMS', ico:'sms'},
  nota:{et:'Nota', ico:'nota'}, gestion:{et:'Gestión', ico:'gestion'}
};

/* ----------------------------------------------------------------- estado */

const hoyISO = () => new Date().toLocaleDateString('en-CA');
const haceMin = m => new Date(Date.now() - m * 60000).toISOString();
const META_VENTAS = 10;   // meta diaria del asesor, para el anillo de avance

const S = {
  operador: document.body.dataset.crmUser || 'OPERADOR',
  rol: document.body.dataset.crmRol || 'Asesor de ventas',
  salida: document.body.dataset.crmExit || 'index.php',
  tabLista: 'mis',
  tabDetalle: 'resumen',
  filtroProducto: '',
  orden: 'prioridad',
  busqueda: '',
  chip: 'todas',
  actual: 0,
  tip: [null, null, null],
  propuesta: null,
  agenda: null,
  llamada: null,            // {contacto, desde:Date}
  contactos: [
    {id:1001, documento:'44012345', nombre:'Víctor Andrés Arbulú Rocca', correo:'victor.arbulu@correo.com',
     prioridad:'Alta', producto:'Inversión Segura', monto:25000, moneda:'S/', origen:'Referido (Jorge Odar)',
     profesion:'Ejecutivo en empleabilidad', empresa:'—', ubicacion:'Lima, Perú',
     direccion:'Av. Demostración 123, Miraflores', poliza:'DEMO-001', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'10 AÑOS', prima:'250.00',
     telefonos:[{n:'+51 987 654 321', t:'MÓVIL'}, {n:'+51 1 555 0101', t:'TRABAJO'}],
     notas:[{t:'Interés: inversiones a mediano plazo (3–5 años)', f:haceMin(180)},
            {t:'Prefiere asesoría virtual', f:haceMin(170)},
            {t:'Busca seguridad y buena rentabilidad en USD', f:haceMin(160)},
            {t:'Referido por Jorge Odar', f:haceMin(2880)}]},
    {id:1002, documento:'44022221', nombre:'María López García', correo:'maria.lopez@correo.com',
     prioridad:'Media', producto:'Vida', monto:8400, moneda:'S/', origen:'Campaña digital',
     profesion:'Contadora', empresa:'Estudio Contable Lima', ubicacion:'Lima, Perú',
     direccion:'Jr. Demostración 456, Lince', poliza:'DEMO-002', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'20 AÑOS', prima:'150.00',
     telefonos:[{n:'+51 912 345 678', t:'MÓVIL'}],
     notas:[{t:'Solicita cotización para cónyuge', f:haceMin(300)}]},
    {id:1003, documento:'44033330', nombre:'Carlos Ramírez Díaz', correo:'carlos.ramirez@correo.com',
     prioridad:'Alta', producto:'Rumbo', monto:15200, moneda:'S/', origen:'Base propia',
     profesion:'Ingeniero civil', empresa:'Constructora Demo', ubicacion:'Arequipa, Perú',
     direccion:'Av. Demostración 789, Cayma', poliza:'DEMO-003', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'5 AÑOS', prima:'320.00',
     telefonos:[{n:'+51 934 567 890', t:'MÓVIL'}],
     notas:[{t:'Pidió llamar después de las 6 pm', f:haceMin(1500)}]},
    {id:1004, documento:'44044440', nombre:'Patricia Fernández Luna', correo:'patricia.fernandez@correo.com',
     prioridad:'Media', producto:'SOAT', monto:380, moneda:'S/', origen:'Renovación',
     profesion:'Comerciante', empresa:'—', ubicacion:'Lima, Perú',
     direccion:'Calle Demostración 321, Surco', poliza:'DEMO-004', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'1 AÑO', prima:'380.00',
     telefonos:[{n:'+51 987 222 333', t:'MÓVIL'}],
     notas:[{t:'Vence su SOAT el próximo mes', f:haceMin(1600)}]},
    {id:1005, documento:'44055550', nombre:'Luis González Paredes', correo:'luis.gonzalez@correo.com',
     prioridad:'Alta', producto:'Vida', monto:19800, moneda:'S/', origen:'Referido (Ana Torres)',
     profesion:'Médico', empresa:'Clínica Demo', ubicacion:'Lima, Perú',
     direccion:'Av. Demostración 654, San Isidro', poliza:'DEMO-005', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'20 AÑOS', prima:'410.00',
     telefonos:[{n:'+51 998 111 222', t:'MÓVIL'}],
     notas:[]},
    {id:1006, documento:'44066660', nombre:'Sofía Martínez Vega', correo:'sofia.martinez@correo.com',
     prioridad:'Media', producto:'Inversión Segura', monto:31000, moneda:'S/', origen:'Campaña digital',
     profesion:'Arquitecta', empresa:'Estudio Demo', ubicacion:'Lima, Perú',
     direccion:'Av. Demostración 987, Barranco', poliza:'DEMO-006', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'10 AÑOS', prima:'520.00',
     telefonos:[{n:'+51 966 333 444', t:'MÓVIL'}],
     notas:[{t:'Comparará con otra aseguradora', f:haceMin(4300)}]},
    {id:1007, documento:'44077770', nombre:'Jorge Ruiz Campos', correo:'jorge.ruiz@correo.com',
     prioridad:'Baja', producto:'Rumbo', monto:5600, moneda:'S/', origen:'Base propia',
     profesion:'Docente', empresa:'Colegio Demo', ubicacion:'Trujillo, Perú',
     direccion:'Jr. Demostración 147, Centro', poliza:'DEMO-007', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'5 AÑOS', prima:'120.00',
     telefonos:[{n:'+51 944 555 666', t:'MÓVIL'}],
     notas:[]},
    {id:1008, documento:'44088880', nombre:'Camila Torres Ríos', correo:'camila.torres@correo.com',
     prioridad:'Alta', producto:'Vida', monto:22400, moneda:'S/', origen:'Referido (Jorge Odar)',
     profesion:'Abogada', empresa:'Estudio Jurídico Demo', ubicacion:'Lima, Perú',
     direccion:'Av. Demostración 258, La Molina', poliza:'DEMO-008', campania:'CRM VENTAS', base:'BASE DEMO',
     vigencia:'20 AÑOS', prima:'460.00',
     telefonos:[{n:'+51 922 888 777', t:'MÓVIL'}],
     notas:[{t:'Pide propuesta por correo antes de decidir', f:haceMin(5800)}]}
  ],
  interacciones: [
    {id:1, contacto:1001, tipo:'llamada',  titulo:'Llamada realizada', detalle:'Interesado en Inversión Segura. Se envió cotización.', f:haceMin(200), autor:'Ana Torres', dur:'04:28'},
    {id:2, contacto:1001, tipo:'whatsapp', titulo:'WhatsApp enviado',  detalle:'Hola Víctor, como conversamos te comparto la cotización de Inversión Segura.', f:haceMin(220), autor:'Ana Torres'},
    {id:3, contacto:1001, tipo:'email',    titulo:'Correo enviado',    detalle:'Propuesta de inversión + brochure.', f:haceMin(1300), autor:'Ana Torres'},
    {id:4, contacto:1001, tipo:'llamada',  titulo:'Llamada entrante',  detalle:'Consulta sobre tasas en USD.', f:haceMin(1400), autor:'Ana Torres', dur:'02:11'},
    {id:5, contacto:1001, tipo:'nota',     titulo:'Nota',              detalle:'Cliente menciona que evaluará con su equipo.', f:haceMin(1500), autor:'Ana Torres'},
    {id:6, contacto:1002, tipo:'llamada',  titulo:'Llamada realizada', detalle:'Solicita incluir a su cónyuge en la cotización.', f:haceMin(320), autor:'Ana Torres', dur:'03:02'},
    {id:7, contacto:1003, tipo:'gestion',  titulo:'Gestión registrada', detalle:'CONTACTADO › SEGUIMIENTO › VOLVER A LLAMAR — Pidió llamar después de las 6 pm.', f:haceMin(1500), autor:'Ana Torres'},
    {id:8, contacto:1006, tipo:'email',    titulo:'Correo enviado',    detalle:'Comparativo de rentabilidad a 3 y 5 años.', f:haceMin(4300), autor:'Ana Torres'}
  ],
  gestiones: [
    {id:1, contacto:1003, f:haceMin(1500), asesor:'Ana Torres', n1:'CONTACTADO', n2:'SEGUIMIENTO', n3:'VOLVER A LLAMAR', nota:'Pidió llamar después de las 6 pm.'}
  ],
  ventas: [
    {id:1, contacto:1002, f:hoyISO(), asesor:'Ana Torres', producto:'Vida', estado:'PENDIENTE', subestado:'POR REVISAR', prima:'150.00'}
  ],
  agendas: [
    {id:1, contacto:1003, fecha:hoyISO(), hora:'18:30', nota:'Rellamada solicitada por el cliente.'},
    {id:2, contacto:1006, fecha:hoyISO(), hora:'11:00', nota:'Confirmar comparativo enviado.'}
  ],
  documentos: [
    {id:1, contacto:1001, nombre:'Cotización Inversión Segura.pdf', tipo:'Cotización', f:haceMin(220)},
    {id:2, contacto:1001, nombre:'Brochure producto.pdf', tipo:'Material', f:haceMin(1300)},
    {id:3, contacto:1001, nombre:'DNI escaneado.jpg', tipo:'Identidad', f:haceMin(2880)}
  ]
};
let seq = {gestion:2, venta:2, agenda:3, interaccion:9, doc:4};

const cli = () => S.contactos[S.actual] || S.contactos[0];

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
function svg(name, cls) {
  const s = el('svg', {cls:cls || 'ico', viewBox:'0 0 24 24'});
  s.innerHTML = ICO[name] || '';
  return s;
}
const iniciales = s => String(s || '?').trim().split(/\s+/).slice(0, 2).map(x => x[0]).join('').toUpperCase();

// Color estable del avatar a partir del nombre (sin imágenes ni aleatoriedad).
const PAL = ['#2D2F3B', '#1D4ED8', '#15803D', '#B45309', '#6D28D9', '#B91C1C', '#0F766E', '#9D174D'];
function avatar(nombre, cls) {
  let h = 0;
  for (const c of String(nombre)) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return el('span', {cls:'avatar ' + (cls || ''), style:'background:' + PAL[h % PAL.length], txt:iniciales(nombre)});
}

const money = (n, m) => (m || 'S/') + ' ' + Number(n || 0).toLocaleString('es-PE');

function cuando(iso) {
  const d = new Date(iso), ahora = new Date();
  const hora = d.toLocaleTimeString('es-PE', {hour:'2-digit', minute:'2-digit'});
  const dia = x => x.toLocaleDateString('en-CA');
  if (dia(d) === dia(ahora)) return 'Hoy, ' + hora;
  const ayer = new Date(ahora); ayer.setDate(ayer.getDate() - 1);
  if (dia(d) === dia(ayer)) return 'Ayer, ' + hora;
  return d.toLocaleDateString('es-PE', {day:'2-digit', month:'short'}) + ', ' + hora;
}
const corto = iso => {
  const d = new Date(iso), ahora = new Date();
  const dia = x => x.toLocaleDateString('en-CA');
  if (dia(d) === dia(ahora)) return 'Hoy ' + d.toLocaleTimeString('es-PE', {hour:'2-digit', minute:'2-digit'});
  return d.toLocaleDateString('es-PE', {day:'2-digit', month:'short'});
};
const esHoy = iso => new Date(iso).toLocaleDateString('en-CA') === hoyISO();

function tabla(cols, filas, vacio) {
  if (!filas.length) return el('div', {cls:'empty', txt:vacio || 'Sin registros.'});
  return el('table', null,
    el('thead', null, el('tr', null, cols.map(c => el('th', {txt:c.t, style:c.w ? 'width:' + c.w : null})))),
    el('tbody', null, filas.map(f => el('tr', null, cols.map(c =>
      el('td', {cls:c.num ? 'num' : null, title:typeof f[c.k] === 'string' ? f[c.k] : null},
        c.render ? c.render(f) : String(f[c.k] ?? '')))))));
}

function abrirCapa(hoja) {
  const capa = $('layer');
  capa.replaceChildren(hoja); capa.hidden = false;
  capa.querySelector('input,select,textarea,button')?.focus();
}
function cerrarCapa() { const c = $('layer'); c.hidden = true; c.replaceChildren(); }

function aviso(titulo, texto, acciones) {
  const btns = acciones || [['Aceptar', () => {}]];
  const capa = $('layer');
  // Un aviso puede saltar encima de una hoja abierta (p. ej. al validar un
  // teléfono): se guarda lo que había para devolverlo al cerrarlo, en vez de
  // tirar abajo la hoja en la que el usuario estaba trabajando.
  const previo = capa.hidden ? null : Array.from(capa.childNodes);
  const volver = () => {
    if (previo && previo.length) { capa.replaceChildren(...previo); capa.hidden = false; }
    else cerrarCapa();
  };
  capa.replaceChildren(el('div', {cls:'sheet'},
    el('header', null, el('span', {txt:titulo})),
    el('div', {cls:'sheet-body'}, el('div', {cls:'sheet-msg', txt:texto})),
    el('div', {cls:'bar bar--end'}, btns.map(([t, fn], i) =>
      el('button', {cls:'btn-sm' + (i === 0 ? ' btn-sm--primary' : ''), txt:t,
        onclick:() => { volver(); fn(); }})))));
  capa.hidden = false;
  capa.querySelector('button')?.focus();
}
const estado = t => { $('msg').textContent = t; };

/* --------------------------------------------- columna 1: cartera */

const TABS_LISTA = [
  {id:'mis',   et:'Mis clientes', filtro:() => S.contactos},
  {id:'prio',  et:'Prioritarios', filtro:() => S.contactos.filter(c => c.prioridad === 'Alta')},
  {id:'segui', et:'Seguimientos', filtro:() => S.contactos.filter(c => S.agendas.some(a => a.contacto === c.id))}
];
const PESO = {Alta:0, Media:1, Baja:2};

// Última señal de actividad del cliente, para ordenar y mostrar "cuándo".
function ultima(c) {
  const fechas = S.interacciones.filter(i => i.contacto === c.id).map(i => +new Date(i.f));
  return fechas.length ? Math.max(...fechas) : 0;
}

function carteraVisible() {
  const tab = TABS_LISTA.find(t => t.id === S.tabLista) || TABS_LISTA[0];
  let lista = tab.filtro();
  if (S.filtroProducto) lista = lista.filter(c => c.producto === S.filtroProducto);
  const q = S.busqueda.trim().toLocaleLowerCase('es');
  if (q) {
    lista = lista.filter(c => [c.nombre, c.documento, c.producto, ...c.telefonos.map(t => t.n)]
      .join(' ').toLocaleLowerCase('es').includes(q));
  }
  const orden = {
    prioridad:(a, b) => PESO[a.prioridad] - PESO[b.prioridad] || ultima(b) - ultima(a),
    reciente:(a, b) => ultima(b) - ultima(a),
    nombre:(a, b) => a.nombre.localeCompare(b.nombre, 'es'),
    monto:(a, b) => b.monto - a.monto
  }[S.orden];
  return lista.slice().sort(orden);
}

function pintarTabsLista() {
  $('listTabs').replaceChildren(...TABS_LISTA.map(t =>
    el('button', {type:'button', 'aria-pressed':String(S.tabLista === t.id),
      txt:t.et + ' (' + t.filtro().length + ')',
      onclick:() => { S.tabLista = t.id; pintarTabsLista(); pintarCartera(); }})));
}

function pintarFiltroProducto() {
  const prods = [...new Set(S.contactos.map(c => c.producto))].sort();
  $('fProducto').replaceChildren(
    el('option', {value:'', txt:'Todos los productos'}),
    ...prods.map(p => el('option', {value:p, txt:p, selected:S.filtroProducto === p})));
}

function pintarCartera() {
  const lista = carteraVisible();
  $('listBody').replaceChildren(...(lista.length ? lista.map(c => {
    const u = ultima(c);
    return el('div', {cls:'cli', 'aria-current':String(c.id === cli().id), role:'button', tabindex:'0',
        onclick:() => abrirCliente(c.id),
        onkeydown:e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); abrirCliente(c.id); } }},
      avatar(c.nombre),
      el('div', {cls:'cli-name', txt:c.nombre, title:c.nombre}),
      el('span', {cls:'tag tag--' + c.prioridad.toLowerCase(), txt:c.prioridad}),
      el('div', {cls:'cli-tel', txt:c.telefonos[0] ? c.telefonos[0].n : '—'}),
      el('div', {cls:'cli-meta'},
        svg(c.producto === 'SOAT' ? 'doc' : 'prod'),
        el('span', {cls:'prod', txt:c.producto, title:c.producto}),
        el('span', {cls:'when', txt:u ? corto(new Date(u).toISOString()) : '—'})));
  }) : [el('div', {cls:'empty', txt:'Ningún cliente coincide con el filtro.'})]));
  $('listCount').textContent = lista.length + ' de ' + S.contactos.length + ' clientes';
}

function abrirCliente(id) {
  const i = S.contactos.findIndex(c => c.id === id);
  if (i < 0) return;
  if (S.llamada && S.llamada.contacto !== id) {
    aviso('Llamada en curso', 'Hay una llamada activa con ' + nombreDe(S.llamada.contacto) + '. Finalízala antes de abrir otro cliente.');
    return;
  }
  S.actual = i;
  limpiarGestion();
  S.tabDetalle = 'resumen';
  pintarTodo();
  estado('Expediente de ' + cli().nombre + ' abierto.');
}
const nombreDe = id => (S.contactos.find(c => c.id === id) || {}).nombre || '—';

/* ------------------------------------------- columna 2: expediente */

function pintarCabecera() {
  const c = cli();
  $('detHead').replaceChildren(
    avatar(c.nombre, 'avatar--lg'),
    el('div', {cls:'det-title'},
      el('h1', {txt:c.nombre, title:c.nombre}),
      el('span', {cls:'tag tag--' + c.prioridad.toLowerCase(), txt:c.prioridad + ' prioridad'})),
    el('div', {cls:'det-acts'},
      el('button', {cls:'btn-sm', type:'button', txt:'Editar', onclick:() => aviso('Editar cliente', 'La edición de la ficha se habilitará cuando el módulo tenga base de datos.')}),
      el('button', {cls:'btn-sm', type:'button', title:'Más', onclick:menuCliente}, svg('mas'))),
    el('div', {cls:'det-contact'},
      el('span', null, svg('tel'), el('b', {txt:c.telefonos[0] ? c.telefonos[0].n : '—'})),
      el('span', null, svg('mail'), el('b', {txt:c.correo})),
      el('span', null, svg('pin'), el('b', {txt:c.ubicacion}))));

  $('kpis').replaceChildren(
    kpi('prod', 'Producto de interés', c.producto),
    kpi('money', 'Monto estimado', money(c.monto, c.moneda), true),
    kpi('origen', 'Origen', c.origen));
}
function kpi(ico, et, valor, esMoneda) {
  return el('dl', {cls:'kpi'}, svg(ico), el('dt', {txt:et}), el('dd', {cls:esMoneda ? 'money' : null, txt:valor, title:valor}));
}

function menuCliente() {
  const c = cli();
  aviso('Más acciones', 'Acciones disponibles sobre ' + c.nombre + ' en esta maqueta: registrar nota, gestionar teléfonos y agendar rellamada.', [
    ['Registrar nota', nuevaNota],
    ['Gestionar teléfonos', hojaTelefonos],
    ['Agendar rellamada', abrirAgenda],
    ['Cerrar', () => {}]
  ]);
}

const TABS_DET = [
  {id:'resumen',       et:'Resumen',       n:() => null},
  {id:'interacciones', et:'Interacciones', n:c => S.interacciones.filter(i => i.contacto === c.id).length},
  {id:'transacciones', et:'Transacciones', n:c => S.ventas.filter(v => v.contacto === c.id).length},
  {id:'documentos',    et:'Documentos',    n:c => S.documentos.filter(d => d.contacto === c.id).length},
  {id:'notas',         et:'Notas',         n:c => c.notas.length}
];

function pintarTabsDetalle() {
  const c = cli();
  $('detTabs').replaceChildren(...TABS_DET.map(t => {
    const n = t.n(c);
    return el('button', {type:'button', 'aria-pressed':String(S.tabDetalle === t.id),
      onclick:() => { S.tabDetalle = t.id; pintarTabsDetalle(); pintarDetalle(); }},
      t.et, n === null ? null : el('span', {cls:'n', txt:' (' + n + ')'}));
  }));
}

function panel(titulo, cuerpo, acciones) {
  return el('section', {cls:'panel'},
    el('h3', null, el('span', {txt:titulo}), el('span', {cls:'spacer'}), ...(acciones || [])),
    el('div', {cls:'panel-body'}, cuerpo));
}

function pintarDetalle() {
  const c = cli();
  const body = $('detBody');
  if (S.tabDetalle === 'resumen') {
    body.replaceChildren(
      el('div', {cls:'duo'}, panelInfo(c), panelNotas(c)),
      botoneraCanales(c),
      panel('Historial de interacciones',
        listaTimeline(interaccionesDe(c).slice(0, 6), 'Sin interacciones registradas.'),
        [el('button', {cls:'btn-sm btn-sm--ghost', type:'button', txt:'Ver todas',
          onclick:() => { S.tabDetalle = 'interacciones'; pintarTabsDetalle(); pintarDetalle(); }})]));
  } else if (S.tabDetalle === 'interacciones') {
    const chips = ['todas', 'llamada', 'whatsapp', 'email', 'sms', 'nota', 'gestion'];
    body.replaceChildren(
      botoneraCanales(c),
      panel('Historial de interacciones',
        listaTimeline(interaccionesDe(c).filter(i => S.chip === 'todas' || i.tipo === S.chip), 'Sin interacciones de este tipo.'),
        [el('div', {cls:'chips'}, chips.map(k =>
          el('button', {cls:'chip', type:'button', 'aria-pressed':String(S.chip === k),
            txt:k === 'todas' ? 'Todas' : CANALES[k].et + 's',
            onclick:() => { S.chip = k; pintarDetalle(); }})))]));
  } else if (S.tabDetalle === 'transacciones') {
    const filas = S.ventas.filter(v => v.contacto === c.id);
    body.replaceChildren(panel('Transacciones del cliente', tabla([
      {t:'N.º', k:'id', w:'46px', num:true},
      {t:'Fecha', k:'f', w:'92px'},
      {t:'Producto', k:'producto'},
      {t:'Prima', k:'prima', w:'80px', num:true},
      {t:'Estado', k:'estado', w:'96px'},
      {t:'Subestado', k:'subestado', w:'104px'},
      {t:'Asesor', k:'asesor'}
    ], filas, 'Este cliente todavía no tiene transacciones.')));
  } else if (S.tabDetalle === 'documentos') {
    const filas = S.documentos.filter(d => d.contacto === c.id).map(d => ({...d, cuando:cuando(d.f)}));
    body.replaceChildren(panel('Documentos', tabla([
      {t:'Documento', k:'nombre'},
      {t:'Tipo', k:'tipo', w:'110px'},
      {t:'Fecha', k:'cuando', w:'130px'}
    ], filas, 'Sin documentos adjuntos.')));
  } else {
    body.replaceChildren(panel('Notas del cliente', listaNotas(c), [
      el('button', {cls:'btn-sm btn-sm--ghost', type:'button', txt:'Agregar nota', onclick:nuevaNota})]));
  }
}

function panelInfo(c) {
  const datos = [
    ['Nombre completo', c.nombre], ['Documento', c.documento],
    ['Teléfono', null], ['Correo', c.correo],
    ['Profesión', c.profesion], ['Empresa', c.empresa],
    ['Ubicación', c.ubicacion], ['Dirección', c.direccion],
    ['Campaña', c.campania], ['Base', c.base],
    ['Póliza', c.poliza], ['Vigencia', c.vigencia]
  ];
  const dl = el('dl', {cls:'kv'});
  for (const [k, v] of datos) {
    dl.append(el('dt', {txt:k}));
    if (k === 'Teléfono') {
      dl.append(el('dd', null,
        el('span', {txt:c.telefonos[0] ? c.telefonos[0].n : '—'}),
        c.telefonos.length > 1 ? el('span', {style:'color:var(--text-muted)', txt:'+' + (c.telefonos.length - 1)}) : null,
        el('button', {cls:'btn-sm', style:'height:17px;padding:0 6px;margin-left:auto', type:'button',
          txt:'Gestionar', onclick:hojaTelefonos})));
    } else {
      dl.append(el('dd', {txt:String(v || '—'), title:String(v || '')}));
    }
  }
  return panel('Información del cliente', dl);
}

function panelNotas(c) {
  return panel('Notas clave', listaNotas(c),
    [el('button', {cls:'btn-sm btn-sm--ghost', type:'button', txt:'Agregar nota', onclick:nuevaNota})]);
}
function listaNotas(c) {
  if (!c.notas.length) return el('div', {cls:'empty', txt:'Sin notas registradas para este cliente.'});
  return el('ul', {cls:'notes'}, c.notas.slice().sort((a, b) => new Date(b.f) - new Date(a.f)).map(n =>
    el('li', null, el('span', {txt:n.t}), el('span', {cls:'when', txt:corto(n.f)}))));
}

const interaccionesDe = c => S.interacciones.filter(i => i.contacto === c.id).slice().sort((a, b) => new Date(b.f) - new Date(a.f));

function listaTimeline(items, vacio) {
  if (!items.length) return el('div', {cls:'empty', txt:vacio});
  return el('ul', {cls:'tl'}, items.map(i => {
    const canal = CANALES[i.tipo] || CANALES.nota;
    return el('li', null,
      el('span', {cls:'mark m-' + i.tipo}, svg(canal.ico)),
      el('span', {cls:'t', txt:i.titulo}),
      el('span', {cls:'w', txt:cuando(i.f) + (i.dur ? ' · ' + i.dur : '')}),
      el('span', {cls:'d', txt:i.detalle}),
      el('span', {cls:'who2', txt:i.autor}));
  }));
}

/* ------------------------------------------------- canales de contacto */

function botoneraCanales(c) {
  const enLlamada = !!S.llamada;
  const propio = enLlamada && S.llamada.contacto === c.id;
  return el('div', {cls:'channels'},
    el('button', {cls:'ch ch--call', type:'button', disabled:enLlamada && !propio,
      onclick:() => propio ? colgar() : llamar()}, svg('tel'), propio ? 'Colgar' : 'Llamar'),
    el('button', {cls:'ch ch--wa', type:'button', onclick:() => enviarPor('whatsapp')}, svg('wa'), 'WhatsApp'),
    el('button', {cls:'ch ch--mail', type:'button', onclick:() => enviarPor('email')}, svg('mail'), 'Email'),
    el('button', {cls:'ch ch--sms', type:'button', onclick:() => enviarPor('sms')}, svg('sms'), 'SMS'),
    el('button', {cls:'ch ch--more', type:'button', onclick:menuCliente}, svg('mas'), 'Más acciones'));
}

function registrar(tipo, titulo, detalle, extra) {
  S.interacciones.push(Object.assign({
    id:seq.interaccion++, contacto:cli().id, tipo, titulo, detalle,
    f:new Date().toISOString(), autor:S.operador
  }, extra || {}));
}

function llamar() {
  const c = cli();
  if (!c.telefonos.length) { aviso('Llamar', 'El cliente no tiene teléfonos registrados.'); return; }
  S.llamada = {contacto:c.id, desde:Date.now()};
  pintarLlamada();
  pintarDetalle();
  estado('Llamando a ' + c.telefonos[0].n + '…');
}

function colgar() {
  if (!S.llamada) return;
  const seg = Math.round((Date.now() - S.llamada.desde) / 1000);
  const dur = String(Math.floor(seg / 60)).padStart(2, '0') + ':' + String(seg % 60).padStart(2, '0');
  const c = S.contactos.find(x => x.id === S.llamada.contacto) || cli();
  S.llamada = null;
  registrar('llamada', 'Llamada realizada', 'Llamada saliente a ' + (c.telefonos[0] || {}).n + '.', {dur});
  pintarLlamada();
  pintarTodo();
  estado('Llamada finalizada (' + dur + '). Registrada en el historial.');
}

function enviarPor(canal) {
  const c = cli();
  const et = CANALES[canal].et;
  const esMail = canal === 'email';

  const campos = el('div', {cls:'grid4', style:'grid-template-columns:1fr'});
  campos.append(el('div', {cls:'f'},
    el('label', {txt:esMail ? 'Para' : 'Número'}),
    el('input', {id:'dest', value:esMail ? c.correo : ((c.telefonos[0] || {}).n || '')})));
  if (esMail) {
    campos.append(el('div', {cls:'f'},
      el('label', {txt:'Asunto'}),
      el('input', {id:'asunto', value:'Propuesta ' + c.producto})));
  }
  campos.append(el('div', {cls:'f'},
    el('label', {txt:'Mensaje'}),
    el('textarea', {id:'cuerpo', maxlength:'1000'},
      'Hola ' + c.nombre.split(' ')[0] + ', te comparto la información de ' + c.producto + '.')));

  const registrarEnvio = () => {
    const cuerpo = $('cuerpo').value.trim();
    if (!cuerpo) { aviso(et, 'Escribe el mensaje antes de registrarlo.'); return; }
    const asunto = $('asunto') ? $('asunto').value.trim() : '';
    cerrarCapa();
    registrar(canal, et + ' enviado', (asunto ? asunto + ' — ' : '') + cuerpo);
    pintarTodo();
    estado(et + ' registrado en el historial del cliente.');
  };

  abrirCapa(el('div', {cls:'sheet', style:'width:460px'},
    cabeceraHoja(et + ' a ' + c.nombre),
    el('div', {cls:'sheet-body'}, campos),
    pieHoja('Registrar envío', registrarEnvio, 'Maqueta: no se envía nada real.')));
}

// Cabecera y pie estándar de las hojas modales, para no anidar de más.
function cabeceraHoja(titulo, extra) {
  return el('header', null,
    el('span', {txt:titulo}),
    el('span', {cls:'spacer'}),
    extra || null,
    el('button', {type:'button', txt:'×', title:'Cerrar', onclick:cerrarCapa}));
}
function pieHoja(etiqueta, accion, aviso2) {
  return el('div', {cls:'bar bar--end'},
    aviso2 ? el('span', {style:'margin-right:auto;font-size:11px;color:var(--text-muted)', txt:aviso2}) : null,
    el('button', {cls:'btn-sm', txt:'Cancelar', onclick:cerrarCapa}),
    el('button', {cls:'btn-sm btn-sm--primary', txt:etiqueta, onclick:accion}));
}

function nuevaNota() {
  const c = cli();
  const campo = el('div', {cls:'grid4', style:'grid-template-columns:1fr'},
    el('div', {cls:'f'},
      el('label', {txt:'Nota sobre ' + c.nombre}),
      el('textarea', {id:'nNota', maxlength:'500', style:'height:70px'})));
  const guardar = () => {
    const t = $('nNota').value.trim();
    if (!t) { aviso('Nota', 'Escribe el contenido de la nota.'); return; }
    cerrarCapa();
    c.notas.unshift({t, f:new Date().toISOString()});
    registrar('nota', 'Nota', t);
    pintarTodo();
    estado('Nota registrada.');
  };
  abrirCapa(el('div', {cls:'sheet', style:'width:440px'},
    cabeceraHoja('Nueva nota'),
    el('div', {cls:'sheet-body'}, campo),
    pieHoja('Guardar nota', guardar)));
}

/* ------------------------------------------------------------ teléfonos */

let telEdit = null;

function hojaTelefonos() {
  const c = cli();
  const cuerpo = el('div', {cls:'panel-body', id:'telWrap'});
  const refresca = () => cuerpo.replaceChildren(tabla([
    {t:'#', k:'i', w:'28px', num:true},
    {t:'Tipo', k:'t', w:'100px'},
    {t:'Número', k:'n'},
    {t:'', k:'acc', w:'110px', render:f => el('span', null,
      el('button', {cls:'btn-sm', style:'height:17px;padding:0 6px;margin-right:3px', txt:'Editar',
        onclick:() => { telEdit = f.i - 1; $('telNum').value = f.n; $('telTipo').value = f.t; $('telSave').textContent = 'Actualizar'; }}),
      el('button', {cls:'btn-sm btn-sm--danger', style:'height:17px;padding:0 6px', txt:'×', title:'Eliminar',
        onclick:() => {
          if (c.telefonos.length === 1) { aviso('Teléfonos', 'El cliente debe conservar al menos un teléfono.'); return; }
          c.telefonos.splice(f.i - 1, 1); telEdit = null; refresca(); pintarTodo(); estado('Teléfono eliminado.');
        }}))}
  ], c.telefonos.map((t, i) => ({...t, i:i + 1})), 'Sin teléfonos.'));
  refresca();

  const guardar = () => {
    const n = $('telNum').value.trim(), t = $('telTipo').value;
    if (!/^\+?[\d ()-]{6,30}$/.test(n)) { aviso('Teléfonos', 'Ingresa un número válido de al menos 6 dígitos.'); return; }
    const dup = c.telefonos.findIndex(x => x.n === n);
    if (dup >= 0 && dup !== telEdit) { aviso('Teléfonos', 'Ese número ya está registrado en el cliente.'); return; }
    if (telEdit === null) c.telefonos.push({n, t}); else c.telefonos[telEdit] = {n, t};
    estado(telEdit === null ? 'Teléfono agregado.' : 'Teléfono actualizado.');
    telEdit = null; $('telNum').value = ''; $('telSave').textContent = 'Agregar';
    refresca(); pintarTodo();
  };

  abrirCapa(el('div', {cls:'sheet', style:'width:560px'},
    el('header', null, el('span', {txt:'Teléfonos de ' + c.nombre}), el('span', {cls:'spacer'}),
      el('button', {type:'button', txt:'×', title:'Cerrar', onclick:() => { telEdit = null; cerrarCapa(); }})),
    el('div', {cls:'bar'},
      el('input', {id:'telNum', type:'text', placeholder:'Número', style:'width:170px', autocomplete:'off',
        onkeydown:e => { if (e.key === 'Enter') { e.preventDefault(); guardar(); } }}),
      el('select', {id:'telTipo', style:'width:120px'}, OPC.cbTipoTelefono.map(t => el('option', {value:t, txt:t}))),
      el('button', {cls:'btn-sm btn-sm--primary', id:'telSave', type:'button', txt:'Agregar', onclick:guardar})),
    el('div', {cls:'sheet-body'}, cuerpo),
    el('div', {cls:'bar bar--end'}, el('button', {cls:'btn-sm', txt:'Cerrar', onclick:() => { telEdit = null; cerrarCapa(); }}))));
}

/* --------------------------------------- columna 3: tipificar gestión */

function nivel(i) {
  if (i === 0) return TIPIFICACION;
  const padre = nivel(i - 1)[S.tip[i - 1]];
  return padre ? (padre.hijos || []) : [];
}
const hoja = () => (S.tip[2] !== null ? nivel(2)[S.tip[2]] : null);

function pintarGestion() {
  for (let i = 0; i < 3; i++) {
    const sel = $('tip' + i), ops = nivel(i);
    sel.replaceChildren(
      el('option', {value:'', txt:i === 0 ? '— Seleccionar —' : (ops.length ? '— Seleccionar —' : 'Depende del nivel anterior')}),
      ...ops.map((o, k) => el('option', {value:String(k), txt:o.label, selected:S.tip[i] === k})));
    sel.disabled = i > 0 && !ops.length;
    sel.closest('.field').classList.toggle('field--on', S.tip[i] !== null);
  }
  const h = hoja(), out = $('tipOut');
  out.textContent = h ? DESENLACE[h.kind] : 'Elige los tres niveles para habilitar el registro.';
  if (h) out.dataset.kind = h.kind; else delete out.dataset.kind;

  const pend = $('tipPend'), btn = $('tipPendBtn');
  if (h && h.kind === 'sale') {
    pend.hidden = false;
    pend.innerHTML = S.propuesta ? 'Propuesta <b>preparada</b>. Se adjunta al grabar.' : 'Falta la <b>propuesta de venta</b>.';
    btn.textContent = S.propuesta ? 'Revisar propuesta' : 'Registrar propuesta';
    btn.onclick = abrirVenta; btn.hidden = false;
  } else if (h && h.kind === 'recall') {
    pend.hidden = false;
    pend.innerHTML = S.agenda ? ('Rellamada <b>' + S.agenda.fecha + ' ' + S.agenda.hora + '</b> lista.') : 'Falta <b>agendar</b> la rellamada.';
    btn.textContent = S.agenda ? 'Cambiar agenda' : 'Agendar rellamada';
    btn.onclick = abrirAgenda; btn.hidden = false;
  } else { pend.hidden = true; btn.hidden = true; }
  $('btnGrabar').disabled = !h;
}

function elegirNivel(i, valor) {
  S.tip[i] = valor === '' ? null : Number(valor);
  for (let k = i + 1; k < 3; k++) S.tip[k] = null;
  const h = hoja();
  if (!h || h.kind !== 'sale') S.propuesta = null;
  if (!h || h.kind !== 'recall') S.agenda = null;
  pintarGestion();
  if (h && h.kind === 'sale' && !S.propuesta) abrirVenta();
  if (h && h.kind === 'recall' && !S.agenda) abrirAgenda();
}

function limpiarGestion() {
  S.tip = [null, null, null]; S.propuesta = null; S.agenda = null;
  const n = $('tipNota'); if (n) n.value = '';
}

function grabarGestion() {
  const h = hoja();
  if (!h) { aviso('Registro de gestión', 'Selecciona los tres niveles de tipificación.'); return; }
  if (h.kind === 'sale' && !S.propuesta) { aviso('Registro de gestión', 'Esta tipificación exige registrar la propuesta de venta.', [['Registrar', abrirVenta], ['Cancelar', () => {}]]); return; }
  if (h.kind === 'recall' && !S.agenda) { aviso('Registro de gestión', 'Esta tipificación exige agendar la rellamada.', [['Agendar', abrirAgenda], ['Cancelar', () => {}]]); return; }

  const c = cli();
  const n1 = nivel(0)[S.tip[0]].label, n2 = nivel(1)[S.tip[1]].label;
  const nota = $('tipNota').value.trim();
  S.gestiones.push({id:seq.gestion++, contacto:c.id, f:new Date().toISOString(), asesor:S.operador, n1, n2, n3:h.label, nota});
  registrar('gestion', 'Gestión registrada', n1 + ' › ' + n2 + ' › ' + h.label + (nota ? ' — ' + nota : ''));

  if (S.propuesta) {
    S.ventas.push({id:seq.venta++, contacto:c.id, f:hoyISO(), asesor:S.operador,
      producto:S.propuesta.CbTipoSeguro || c.producto,
      estado:S.propuesta.CbTipoEstado || 'PENDIENTE', subestado:S.propuesta.CbEstado || 'POR REVISAR',
      prima:S.propuesta.TxtIMPORTE_PACTADO || '0.00'});
  }
  if (S.agenda) S.agendas.push({id:seq.agenda++, contacto:c.id, fecha:S.agenda.fecha, hora:S.agenda.hora, nota:S.agenda.nota});

  const extra = S.propuesta ? ' Propuesta adjuntada.' : (S.agenda ? ' Rellamada agendada.' : '');
  limpiarGestion();
  pintarTodo();
  estado('Gestión registrada.' + extra);
}

function terminarAtencion() {
  if (S.llamada) { aviso('Terminar atención', 'Hay una llamada en curso. Cuélgala antes de terminar la atención.'); return; }
  const lista = carteraVisible();
  const pos = lista.findIndex(x => x.id === cli().id);
  const siguiente = lista[(pos + 1) % lista.length] || cli();
  const previo = cli().nombre;
  S.actual = S.contactos.findIndex(x => x.id === siguiente.id);
  limpiarGestion();
  S.tabDetalle = 'resumen';
  pintarTodo();
  estado('Atención de ' + previo + ' finalizada. Siguiente: ' + cli().nombre + '.');
}

/* --------------------------------------------------- propuesta de venta */

function campoVenta(cp, valores) {
  const v = valores[cp.id];
  let control;
  if (cp.opc) {
    control = el('select', {id:'v_' + cp.id}, el('option', {value:'', txt:''}),
      ...OPC[cp.opc].map(o => el('option', {value:o, txt:o, selected:v === o})));
  } else if (cp.tipo === 'check') {
    control = el('input', {type:'checkbox', id:'v_' + cp.id, checked:!!v});
  } else if (cp.tipo === 'area') {
    control = el('textarea', {id:'v_' + cp.id, maxlength:'2000'}, v || '');
  } else {
    control = el('input', {type:cp.tipo === 'date' ? 'date' : 'text', id:'v_' + cp.id,
      value:v || '', inputmode:cp.dinero ? 'decimal' : null, maxlength:'200'});
  }
  if (cp.tipo === 'check') return el('div', {cls:'f f--check', 'data-f':cp.id}, control, el('label', {for:'v_' + cp.id, txt:cp.et}));
  const et = el('label', {for:'v_' + cp.id}, cp.et, cp.req ? el('span', {cls:'req', txt:' *'}) : null);
  const ancho = cp.tipo === 'area' ? ' f--full' : (cp.ancho === 2 ? ' f--wide' : '');
  return el('div', {cls:'f' + ancho, 'data-f':cp.id}, et, control);
}

function abrirVenta() {
  const c = cli();
  const p = c.nombre.split(' ');
  const base = {
    TxtPRIMER_NOMBRE:p[0] || '', TxtSEGUNDO_NOMBRE:'',
    TxtAPELLIDO_PATERNO:p[p.length - 2] || '', TxtAPELLIDO_MATERNO:p[p.length - 1] || '',
    TxtNUMERO_DOCUMENTO:c.documento, TxtCORREO_01:c.correo, TxtMOVIL_01:(c.telefonos[0] || {}).n || '',
    TxtDIRECCION:c.direccion, TxtPoliza:c.poliza, CmbMoneda:'SOLES',
    CbTipoSeguro:OPC.CbTipoSeguro.includes(String(c.producto).toUpperCase()) ? String(c.producto).toUpperCase() : '',
    TxtIMPORTE_PACTADO:c.prima, CbTIEMPO:c.vigencia, TxtTrea:'0.00', TxtPorcentajeRetorno:'0',
    TxtMontoRetorno:'0.00', CbCanalVentas:'TELEVENTAS', CbTipoEstado:'PENDIENTE', CbEstado:'POR REVISAR', CbSubEstado:'EN PROCESO',
    // Arranque habitual de una campaña de televenta; el asesor los ajusta.
    CbMedioLLamada:'SALIENTE', CbMarcaTarjeta:'NO APLICA', CbTipoTarjeta:'NO APLICA'
  };
  const val = Object.assign(base, S.propuesta || {});
  const rejilla = el('div', {cls:'grid4'},
    el('div', {cls:'form-error', id:'vErr', hidden:true}),
    VENTA.flatMap(g => [el('div', {cls:'sec', txt:g.sec}), ...g.campos.map(cp => campoVenta(cp, val))]));
  const quien = el('span', {style:'font-weight:400;text-transform:none;letter-spacing:0', txt:c.nombre});

  abrirCapa(el('div', {cls:'sheet', style:'width:min(1060px,calc(100vw - 20px))'},
    cabeceraHoja('Propuesta de venta', quien),
    el('div', {cls:'sheet-body'}, rejilla),
    pieHoja('Guardar propuesta', guardarVenta, 'Los campos con * son obligatorios.')));
}

// Reglas que aplicaba el servidor original antes de aceptar una venta.
function validarVenta(v) {
  const errores = [], malos = [];
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
  if (v.CbTipoSeguro === 'INVERSION_SEGURA' && !(Number(v.TxtTrea) > 0)) {
    errores.push('TREA: obligatorio y mayor que cero para INVERSION_SEGURA'); malos.push('TxtTrea');
  }
  if (v.dtFECHA_NACIMIENTO && v.dtFECHA_NACIMIENTO > hoyISO()) {
    errores.push('Fecha de nacimiento: no puede ser futura'); malos.push('dtFECHA_NACIMIENTO');
  }
  return {errores, malos};
}

function guardarVenta() {
  const v = {};
  for (const g of VENTA) for (const cp of g.campos) {
    const n = $('v_' + cp.id);
    if (n) v[cp.id] = cp.tipo === 'check' ? n.checked : n.value.trim();
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

/* ------------------------------------------------ agenda de rellamada */

function abrirAgenda() {
  const a = S.agenda || {fecha:hoyISO(), hora:'10:00', nota:''};
  const campos = el('div', {cls:'grid4', style:'grid-template-columns:1fr 1fr'},
    el('div', {cls:'f'},
      el('label', {for:'aFecha'}, 'Fecha', el('span', {cls:'req', txt:' *'})),
      el('input', {type:'date', id:'aFecha', value:a.fecha, min:hoyISO()})),
    el('div', {cls:'f'},
      el('label', {for:'aHora'}, 'Hora', el('span', {cls:'req', txt:' *'})),
      el('input', {type:'time', id:'aHora', value:a.hora})),
    el('div', {cls:'f f--full'},
      el('label', {for:'aNota', txt:'Nota'}),
      el('textarea', {id:'aNota', maxlength:'500'}, a.nota)));

  abrirCapa(el('div', {cls:'sheet', style:'width:380px'},
    cabeceraHoja('Agendar rellamada'),
    el('div', {cls:'sheet-body'}, campos),
    pieHoja('Agendar', guardarAgenda)));
}

function guardarAgenda() {
  const fecha = $('aFecha').value, hora = $('aHora').value;
  if (!fecha || !hora) { aviso('Rellamada', 'Indica fecha y hora.'); return; }
  if (fecha < hoyISO()) { aviso('Rellamada', 'La fecha debe ser igual o posterior a hoy.'); return; }
  S.agenda = {fecha, hora, nota:$('aNota').value.trim()};
  cerrarCapa();
  pintarGestion();
  estado('Rellamada preparada para el ' + fecha + ' a las ' + hora + '.');
}

/* ----------------------------------------- llamada en curso y métricas */

let tick = null;
function pintarLlamada() {
  const caja = $('callState');
  if (S.llamada) {
    caja.dataset.on = 'true';
    $('callLabel').textContent = 'En llamada · ' + nombreDe(S.llamada.contacto).split(' ')[0];
    if (!tick) tick = setInterval(refrescarCrono, 1000);
    refrescarCrono();
  } else {
    caja.dataset.on = 'false';
    $('callLabel').textContent = 'Sin llamada';
    $('callTimer').textContent = '00:00';
    if (tick) { clearInterval(tick); tick = null; }
  }
}
function refrescarCrono() {
  if (!S.llamada) return;
  const s = Math.round((Date.now() - S.llamada.desde) / 1000);
  $('callTimer').textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
}

function pintarPerformance() {
  const llamadasHoy = S.interacciones.filter(i => i.tipo === 'llamada' && esHoy(i.f)).length;
  const ventasHoy = S.ventas.filter(v => v.f === hoyISO()).length;
  const reunionesHoy = S.agendas.filter(a => a.fecha === hoyISO()).length;
  const datos = [['Contactos', S.contactos.length], ['Llamadas', llamadasHoy], ['Reuniones', reunionesHoy], ['Ventas', ventasHoy]];
  $('perfRatio').textContent = ventasHoy + '/' + META_VENTAS;
  $('perfDonut').style.setProperty('--p', Math.min(100, Math.round(ventasHoy / META_VENTAS * 100)));
  $('perfKpis').replaceChildren(...datos.flatMap(([k, v]) => [el('dt', {txt:k}), el('dd', {txt:String(v)})]));
}

/* ------------------------------------------------------------- arranque */

function pintarTodo() {
  pintarTabsLista();
  pintarCartera();
  pintarCabecera();
  pintarTabsDetalle();
  pintarDetalle();
  pintarGestion();
  pintarPerformance();
}

function init() {
  $('opName').textContent = S.operador;
  $('opRole').textContent = S.rol;
  $('whoAv').textContent = iniciales(S.operador);

  $('search').addEventListener('input', e => { S.busqueda = e.target.value; pintarCartera(); });
  $('exit').addEventListener('click', () => {
    if (S.llamada) { aviso('Salir', 'Hay una llamada en curso. Cuélgala antes de salir.'); return; }
    top.location.href = S.salida;
  });
  $('fProducto').addEventListener('change', e => { S.filtroProducto = e.target.value; pintarCartera(); });
  $('fOrden').addEventListener('change', e => { S.orden = e.target.value; pintarCartera(); });

  for (let i = 0; i < 3; i++) $('tip' + i).addEventListener('change', e => elegirNivel(i, e.target.value));
  $('btnGrabar').addEventListener('click', grabarGestion);
  $('btnTerminar').addEventListener('click', terminarAtencion);

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !$('layer').hidden) { cerrarCapa(); return; }
    // Atajo "/" para saltar al buscador, como el ⌘K del modelo.
    if (e.key === '/' && $('layer').hidden && document.activeElement !== $('search')
        && !['INPUT', 'TEXTAREA', 'SELECT'].includes((document.activeElement || {}).tagName)) {
      e.preventDefault(); $('search').focus();
    }
  });

  pintarFiltroProducto();
  pintarLlamada();
  pintarTodo();
  estado('Listo. Maqueta de demostración: los datos son ficticios y no se guardan.');
}

init();
