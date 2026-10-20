'use strict';
/* Datos de la maqueta de supervisión.

   Todo es inventado. Ninguna cifra sale de la base de producción: esta
   página no abre conexiones ni consulta nada. Los nombres, montos y
   conversaciones se eligieron para que la demostración se sostenga sola y
   para que encajen con la presentación del asesor (app/web/demo), donde la
   asesora es Ana Torres y la campaña es carsa0001.

   El azar está sembrado a propósito (sembrar()): la línea de tiempo del
   piso se dibuja igual en cada carga, así el recorrido guiado siempre
   señala lo mismo y nadie se lleva una sorpresa en la reunión. */

/* ----------------------------------------------- reloj de la escena --- */
/* La maqueta vive un martes a las 14:42. Fijo, no la hora real: así las
   cifras del día y la línea de tiempo siempre cuentan la misma historia. */
const HOY = {fecha:'Martes 20 de octubre, 2026', ini:8*60, fin:17*60, ahora:14*60+42};
const reloj = m => String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0');

/* Generador sembrado: mismos bloques en cada carga. */
function sembrar(s){ return function(){ s|=0; s=s+0x6D2B79F5|0;
  let t=Math.imul(s^s>>>15,1|s); t=t+Math.imul(t^t>>>7,61|t)^t;
  return ((t^t>>>14)>>>0)/4294967296; }; }

/* ----------------------------------------------------------- equipo --- */
/* Cada agente puede estar habilitado en VARIAS campañas (camps) y estar
   trabajando en UNA a la vez (activa). Son dos cosas distintas y así
   funciona de verdad: `v2_dialer_agent_campaigns` es una tabla de relación
   —un agente, muchas campañas— y `agent_status.selected_campaign_id` guarda
   en cuál entró hoy. El supervisor decide lo primero; el agente, lo segundo.

   Cuando alguien queda habilitado a la vez en una saliente y una entrante
   se le llama agente MIXTO. */
const AGENTES = [
  {id:'5000', nombre:'Ana Torres',    camps:['carsa0001','entrantes'],    activa:'carsa0001',    estado:'call',  llam:58, cont:42, vent:6, monto:29800, ocup:78, login:'07:58', pausaMin:42, punt:'ok'},
  {id:'5001', nombre:'Luis Ramírez',  camps:['carsa0001'],                activa:'carsa0001',    estado:'pausa', llam:52, cont:38, vent:5, monto:24500, ocup:71, login:'08:02', pausaMin:51, punt:'ok',    motivo:'Refrigerio', desde:14*60+18},
  {id:'5002', nombre:'María Chávez',  camps:['renovaciones','entrantes'], activa:'renovaciones', estado:'libre', llam:61, cont:40, vent:4, monto:18600, ocup:74, login:'07:55', pausaMin:38, punt:'ok'},
  {id:'5003', nombre:'Carlos Díaz',   camps:['carsa0001','renovaciones'], activa:'carsa0001',    estado:'call',  llam:47, cont:31, vent:4, monto:16800, ocup:69, login:'08:14', pausaMin:46, punt:'tarde'},
  {id:'5004', nombre:'Valeria Rojas', camps:['renovaciones'],             activa:'renovaciones', estado:'wrap',  llam:39, cont:28, vent:3, monto:14200, ocup:66, login:'08:01', pausaMin:44, punt:'ok'},
  {id:'5005', nombre:'Jorge Quispe',  camps:['carsa0001'],                activa:'carsa0001',    estado:'call',  llam:44, cont:29, vent:3, monto:12900, ocup:72, login:'07:59', pausaMin:40, punt:'ok'},
  {id:'5006', nombre:'Diana Paredes', camps:['renovaciones','postventa'], activa:'renovaciones', estado:'pausa', llam:33, cont:19, vent:1, monto:4800,  ocup:54, login:'08:06', pausaMin:87, punt:'ok',    motivo:'Baño', desde:14*60+19},
  {id:'5007', nombre:'Marco Salinas', camps:[],                           activa:null,           estado:'off',   llam:12, cont:7,  vent:0, monto:0,     ocup:31, login:'10:41', pausaMin:22, punt:'falta'},
];
const porId = id => AGENTES.find(a => a.id === id);
const ESTADOS = {
  call :{t:'En llamada',   c:'call'},
  pausa:{t:'En pausa',     c:'pausa'},
  libre:{t:'Disponible',   c:'libre'},
  wrap :{t:'Tipificando',  c:'wrap'},
  off  :{t:'Desconectado', c:'off'},
};

/* Bloques de la línea de tiempo. Se arman sembrados: llamada, tipificación,
   espera y pausas, en proporción distinta según qué tan productivo fue el
   agente. Es exactamente la forma que tiene el dato real en la base
   (agent_intervals + agent_pauses: estado, inicio y fin). */
function construirPista(ag, i) {
  const r = sembrar(7919 + i*131);
  const arr = [];
  const entra = parseInt(ag.login.slice(0,2),10)*60 + parseInt(ag.login.slice(3),10);
  if (entra > HOY.ini) arr.push({t:'off', a:HOY.ini, d:entra-HOY.ini, q:'Sin conectar'});
  let m = entra;
  const finAg = ag.estado === 'off' ? 12*60+20 : HOY.ahora;
  // Refrigerio: todos tienen uno, escalonado para no vaciar el piso.
  const refri = 12*60 + (i % 4) * 30;
  const flojo = ag.ocup < 60;
  while (m < finAg) {
    if (m >= refri && m < refri + 45) { arr.push({t:'pausa', a:m, d:45, q:'Refrigerio'}); m += 45; continue; }
    const dado = r();
    let t, d, q;
    if (dado < (flojo ? .40 : .60))      { t='call';   d=3+Math.floor(r()*9);  q='En llamada'; }
    else if (dado < (flojo ? .55 : .78)) { t='wrap';   d=1+Math.floor(r()*3);  q='Tipificando'; }
    else if (dado < (flojo ? .80 : .93)) { t='espera'; d=1+Math.floor(r()*4);  q='Esperando llamada'; }
    else                                 { t='pausa';  d=5+Math.floor(r()*12);
                                           q=['Baño','Capacitación','Refrigerio'][Math.floor(r()*3)]; }
    d = Math.min(d, finAg - m);
    if (d > 0) arr.push({t, a:m, d, q});
    m += d;
  }
  if (finAg < HOY.ahora) arr.push({t:'off', a:finAg, d:HOY.ahora-finAg, q:'Desconectado'});
  return arr;
}
const PISTAS = {};
AGENTES.forEach((a, i) => { PISTAS[a.id] = construirPista(a, i); });

/* ------------------------------------------------- llamadas en curso --- */
const EN_CURSO = [
  {ag:'5000', cliente:'Betty Ramos Quiroz',   tel:'51957741704', seg:268, camp:'carsa0001',    etapa:'Negociando'},
  {ag:'5003', cliente:'Héctor Chávez Olaya',  tel:'987130189',   seg:94,  camp:'carsa0001',    etapa:'Presentando'},
  {ag:'5005', cliente:'Rosa Jiménez Arce',    tel:'961447208',   seg:412, camp:'carsa0001',    etapa:'Cerrando'},
  {ag:'—',    cliente:'Pedro Luna Ascencio',  tel:'943220871',   seg:37,  camp:'entrantes',    etapa:'En cola', cola:true},
];

/* ------------------------------------------------------- alertas ------ */
const ALERTAS = [
  {n:1, sev:'alta',  tit:'Diana Paredes lleva 23 min en pausa',
   txt:'El motivo es “Baño”, que está configurado para 10 minutos.', hora:'14:42',
   acc:[{t:'Sacar de pausa', a:'despausar:5006'}, {t:'Abrir chat', a:'chat:5006'}]},
  {n:2, sev:'alta',  tit:'Abandono en 4,2 %, sobre el umbral',
   txt:'El umbral de la campaña carsa0001 está en 3 %. Hace 6 minutos que lo supera.', hora:'14:36',
   acc:[{t:'Bajar el marcado', a:'bajarNivel'}, {t:'Ver campaña', a:'ir:campanas'}]},
  {n:3, sev:'media', tit:'Carlos Díaz cerró 3 llamadas sin tipificar',
   txt:'Las llamadas quedan sin resultado y no entran al reporte.', hora:'14:21',
   acc:[{t:'Susurrarle', a:'escuchar:5003'}, {t:'Abrir chat', a:'chat:5003'}]},
  {n:4, sev:'media', tit:'La cola de entrantes superó los 40 segundos',
   txt:'Hay 1 persona esperando y solo María está disponible.', hora:'14:39',
   acc:[{t:'Ver la cola', a:'ir:campanas'}]},
  {n:5, sev:'baja',  tit:'La lista carsa_chiclayo está por agotarse',
   txt:'Quedan 214 leads sin contactar de 1 000. Rinde cada vez menos.', hora:'13:50',
   acc:[{t:'Segmentar y reciclar', a:'ir:segmentos'}]},
];

/* ------------------------------------------------------ campañas ------ */
/* Las campañas ya no guardan su lista de agentes: la fuente única es
   AGENTES[].camps y de ahí se deriva. Con dos listas había que mantenerlas
   sincronizadas a mano, y en cuanto alguien esté en dos campañas se
   desincronizan. */
const CAMPANAS = [
  {id:'carsa0001', nombre:'Crédito personal — Carsa', tipo:'out', estado:'on', nivel:2.4,
   leads:1000, pend:214, hechas:786, cont:512, vent:23, abandono:4.2, canales:12},
  {id:'renovaciones', nombre:'Renovación de póliza', tipo:'out', estado:'on', nivel:1.8,
   leads:640, pend:388, hechas:252, cont:161, vent:8, abandono:1.9, canales:8},
  {id:'cobranza', nombre:'Cobranza temprana', tipo:'out', estado:'off', nivel:1.0,
   leads:1420, pend:1420, hechas:0, cont:0, vent:0, abandono:0, canales:6},
  {id:'entrantes', nombre:'Ventas — línea entrante', tipo:'in', estado:'on',
   recibidas:148, atendidas:139, abandonadas:9, nivelServicio:91, esperaProm:18, canales:20},
  {id:'postventa', nombre:'Soporte posventa', tipo:'in', estado:'on',
   recibidas:63, atendidas:61, abandonadas:2, nivelServicio:96, esperaProm:11, canales:10},
];
const campPorId  = id => CAMPANAS.find(c => c.id === id);
const nombreCamp = id => (campPorId(id) || {}).nombre || '—';
const agentesDe  = id => AGENTES.filter(a => a.camps.includes(id));
/* Mixto: habilitado a la vez en una saliente y una entrante. */
const esMixto = a => a.camps.some(c => (campPorId(c) || {}).tipo === 'out')
                  && a.camps.some(c => (campPorId(c) || {}).tipo === 'in');
const COLA_IN = [
  {quien:'Pedro Luna Ascencio', tel:'943220871', esp:37, motivo:'Consulta de tasa'},
  {quien:'Número no registrado', tel:'929887340', esp:12, motivo:'Sin clasificar'},
];

/* ------------------------------------------------------- clientes ----- */
const CLIENTES = [
  {id:4820, nombre:'Héctor Chávez Olaya', tel:'987130189', doc:'43887120',
   correo:'hector.chavez@gmail.com', prof:'Transportista', empresa:'Transportes Olmos EIRL',
   ciudad:'Chiclayo', dir:'Av. Balta 1280', prod:'Crédito personal', monto:18000,
   origen:'Referido', camp:'carsa0001', lista:'carsa_chiclayo', tip:'INTERESADO',
   intentos:3, ultima:'Hoy 14:28', agente:'5003'},
  {id:4911, nombre:'Betty Ramos Quiroz', tel:'51957741704', doc:'41229087',
   correo:'betty.ramos@hotmail.com', prof:'Comerciante', empresa:'Bodega Santa Rosa',
   ciudad:'Chiclayo', dir:'Calle Elías Aguirre 455', prod:'Crédito personal', monto:24500,
   origen:'Base propia', camp:'carsa0001', lista:'carsa_chiclayo', tip:'SEGUIMIENTO',
   intentos:2, ultima:'Hoy 14:38', agente:'5000'},
  {id:5102, nombre:'Rosa Jiménez Arce', tel:'961447208', doc:'45120933',
   correo:'rosa.jimenez@gmail.com', prof:'Docente', empresa:'I.E. San José',
   ciudad:'Trujillo', dir:'Jr. Pizarro 740', prod:'Renovación de póliza', monto:9800,
   origen:'Campaña web', camp:'renovaciones', lista:'renov_norte', tip:'VENTA',
   intentos:1, ultima:'Hoy 14:35', agente:'5005'},
  {id:5240, nombre:'Pedro Luna Ascencio', tel:'943220871', doc:'09887412',
   correo:'pluna@outlook.com', prof:'Contador', empresa:'Estudio Luna & Asoc.',
   ciudad:'Lima', dir:'Av. Arequipa 2140', prod:'Crédito personal', monto:32000,
   origen:'Entrante', camp:'entrantes', lista:'—', tip:'NUEVO',
   intentos:0, ultima:'—', agente:'—'},
];

const HISTORIAS = {
  4820:[
    {v:'llamada',  t:'Llamada realizada', d:'Aprobado condicional. Falta la tercera boleta.', q:'Carlos Díaz', h:'Hoy 14:28'},
    {v:'whatsapp', t:'WhatsApp enviado',  d:'Lista de documentos pendientes.',                q:'Carlos Díaz', h:'Hoy 14:31'},
    {v:'email',    t:'Correo enviado',    d:'Documentos para completar su solicitud.',        q:'Sistema',     h:'Ayer 17:10'},
    {v:'llamada',  t:'No contesta',       d:'Segundo intento del día.',                        q:'Carlos Díaz', h:'Ayer 11:04'},
    {v:'sms',      t:'SMS enviado',       d:'Su solicitud sigue activa. Responda SÍ.',        q:'Sistema',     h:'15/10 09:00'},
    {v:'nota',     t:'Nota interna',      d:'Prefiere que lo llamen después de las 2 p.m.',   q:'Carlos Díaz', h:'14/10 16:22'},
  ],
  4911:[
    {v:'llamada',  t:'En llamada ahora',  d:'Negociando la cuota mensual.',                    q:'Ana Torres',  h:'Hoy 14:38'},
    {v:'whatsapp', t:'WhatsApp recibido', d:'“¿Me pueden mandar la simulación?”',              q:'Cliente',     h:'Hoy 11:52'},
    {v:'llamada',  t:'Llamada realizada', d:'Interesada. Pidió simulación a 24 meses.',        q:'Ana Torres',  h:'Hoy 10:15'},
    {v:'nota',     t:'Nota interna',      d:'Tiene bodega. Facturación estable.',              q:'Ana Torres',  h:'Hoy 10:18'},
  ],
  5102:[
    {v:'llamada',  t:'Venta confirmada',  d:'Renueva por 12 meses. Prima S/ 9 800.',           q:'Jorge Quispe',h:'Hoy 14:35'},
    {v:'email',    t:'Correo enviado',    d:'Contrato de renovación para firma.',              q:'Sistema',     h:'Hoy 14:36'},
  ],
  5240:[
    {v:'llamada',  t:'Llamada entrante',  d:'En cola. Consulta por tasa.',                     q:'—',           h:'Hoy 14:41'},
  ],
};

/* Conversaciones de WhatsApp que el supervisor puede abrir y contestar. */
const WA = {
  4820:[
    {de:'ag', t:'Buenas tardes señor Héctor, le escribo de Carsa. Ya tenemos su evaluación.', h:'14:31'},
    {de:'cl', t:'Buenas, ¿qué me falta?', h:'14:33'},
    {de:'ag', t:'Solo la boleta del tercer mes. Con eso cerramos hoy mismo.', h:'14:34'},
    {de:'cl', t:'¿Y la cuota en cuánto queda? Porque si es más de 700 ya no me sirve', h:'14:37'},
  ],
  4911:[
    {de:'cl', t:'Hola, ¿me pueden mandar la simulación a 24 meses?', h:'11:52'},
    {de:'ag', t:'Claro señora Betty, se la envío ahora mismo.', h:'11:55'},
    {de:'ag', t:'📎 simulacion_24meses.pdf', h:'11:56', file:true},
    {de:'cl', t:'Gracias, lo reviso con mi esposo y le aviso', h:'12:10'},
  ],
};

/* Bandeja: lo que está pasando en WhatsApp en todo el piso. */
const BANDEJA = [
  {cli:4820, ag:'5003', ult:'¿Y la cuota en cuánto queda? Porque si es más de 700…', h:'14:37', sin:1, alerta:'Sin responder hace 5 min'},
  {cli:4911, ag:'5000', ult:'Gracias, lo reviso con mi esposo y le aviso',            h:'12:10', sin:0},
  {cli:5102, ag:'5005', ult:'Perfecto, quedo atenta al contrato',                      h:'14:36', sin:0},
  {cli:5240, ag:'—',    ult:'Buenas, vi su anuncio. ¿Qué tasa manejan?',              h:'14:40', sin:1, alerta:'Sin asignar'},
];

/* ------------------------------------------------- carga de base ------ */
const ARCHIVO = {
  nombre:'carsa_noviembre_2026.xlsx', peso:'1,4 MB', filas:4820,
  columnas:[
    {col:'CEL',         ej:'939178487',             campo:'telefono',  auto:true},
    {col:'NOMBRES',     ej:'JUAN CARLOS',           campo:'nombre',    auto:true},
    {col:'APELLIDOS',   ej:'MENDOZA RIVAS',         campo:'apellidos', auto:true},
    {col:'DOC_IDENT',   ej:'44102388',              campo:'documento', auto:true},
    {col:'MAIL',        ej:'jc.mendoza@gmail.com',  campo:'correo',    auto:true},
    {col:'PROD_INT',    ej:'Crédito personal',      campo:'producto',  auto:true},
    {col:'MONTO_SOL',   ej:'18500',                 campo:'monto',     auto:true},
    {col:'DPTO',        ej:'LAMBAYEQUE',            campo:'ciudad',    auto:false},
    {col:'CAMPO_X',     ej:'A-2291',                campo:'',          auto:false},
  ],
  validacion:[
    {k:'ok',   n:4593, t:'Listos para cargar',   d:'Pasan todas las validaciones'},
    {k:'warn', n:112,  t:'Repetidos en el archivo', d:'El mismo teléfono aparece más de una vez'},
    {k:'warn', n:73,   t:'Ya existen en otra lista', d:'Están en carsa_chiclayo y renov_norte'},
    {k:'mal',  n:24,   t:'Teléfono inválido',    d:'Menos de 9 dígitos o con letras'},
    {k:'mal',  n:18,   t:'En lista negra',       d:'Pidieron no ser contactados'},
  ],
  normalizados:1204,
};
const CARGAS = [
  {f:'18/10 09:12', arch:'renovaciones_oct.xlsx', filas:640,  ok:618,  quien:'Rodrigo Valdez', lista:'renov_norte'},
  {f:'14/10 16:40', arch:'carsa_chiclayo.csv',    filas:1000, ok:1000, quien:'Rodrigo Valdez', lista:'carsa_chiclayo'},
  {f:'09/10 11:05', arch:'cobranza_q4.xlsx',      filas:1420, ok:1388, quien:'Ivana Cueva',    lista:'cobranza_q4'},
];

/* ------------------------------------------------- segmentación ------- */
const CAMPOS_SEG = [
  {k:'tip',      t:'Tipificación',   tipo:'lista'},
  {k:'intentos', t:'Intentos',       tipo:'num'},
  {k:'ultima',   t:'Última llamada', tipo:'horas'},
  {k:'monto',    t:'Monto',          tipo:'num'},
  {k:'ciudad',   t:'Ciudad',         tipo:'lista'},
  {k:'producto', t:'Producto',       tipo:'lista'},
  {k:'origen',   t:'Origen',         tipo:'lista'},
];
const TIPIFICACIONES = [
  {c:'010101', t:'INTERESADO',      cont:true,  venta:false, reint:true,  horas:24, n:184},
  {c:'010102', t:'VOLVER A LLAMAR', cont:true,  venta:false, reint:true,  horas:4,  n:231},
  {c:'010100', t:'SEGUIMIENTO',     cont:true,  venta:false, reint:true,  horas:48, n:97},
  {c:'010201', t:'VENTA CONFIRMADA',cont:true,  venta:true,  reint:false, horas:0,  n:23},
  {c:'010301', t:'NO INTERESADO',   cont:true,  venta:false, reint:false, horas:0,  n:142},
  {c:'020100', t:'NO CONTESTA',     cont:false, venta:false, reint:true,  horas:2,  n:396},
  {c:'020101', t:'REINTENTAR',      cont:false, venta:false, reint:true,  horas:2,  n:158},
  {c:'020102', t:'AGENDAR LLAMADA', cont:false, venta:false, reint:true,  horas:12, n:44},
];
const SEG_GUARDADOS = [
  {t:'No contesta con 3+ intentos', n:412, desc:'Para reciclar de madrugada'},
  {t:'Interesados sin cierre',      n:184, desc:'Los que pidieron pensarlo'},
  {t:'Monto sobre S/ 20 000',       n:97,  desc:'Cartera alta, al mejor agente'},
  {t:'Chiclayo y Trujillo',         n:638, desc:'Operativo del norte'},
];
/* Mapa de calor: contactabilidad por día y hora. Sembrado. */
const CALOR_H = ['8','9','10','11','12','13','14','15','16','17','18'];
const CALOR_D = ['Lun','Mar','Mié','Jue','Vie','Sáb'];
const CALOR = (() => {
  const r = sembrar(4242), m = [];
  // Forma realista: pico a media mañana, caída al mediodía, repunte a las 6.
  const curva = [.52,.78,.94,.88,.55,.38,.62,.74,.81,.86,.71];
  CALOR_D.forEach((d, i) => {
    m.push(curva.map((c, j) => {
      const dia = i === 5 ? .55 : i === 4 ? .82 : 1;        // sábado flojo, viernes medio
      return Math.max(.08, Math.min(1, c * dia * (.86 + r() * .28)));
    }));
  });
  return m;
})();
const SALUD_LISTAS = [
  {t:'carsa_chiclayo', total:1000, sin:214, agot:78, estado:'mal'},
  {t:'renov_norte',    total:640,  sin:388, agot:39, estado:'ok'},
  {t:'cobranza_q4',    total:1420, sin:1420,agot:0,  estado:'ok'},
];

/* ---------------------------------------------------- calidad --------- */
const GRABACIONES = [
  {id:'c-9841', ag:'5000', cli:'Betty Ramos Quiroz',  dur:'6:28', h:'Hoy 11:04', score:94, tip:'SEGUIMIENTO', rev:true},
  {id:'c-9837', ag:'5003', cli:'Héctor Chávez Olaya', dur:'4:12', h:'Hoy 10:41', score:71, tip:'INTERESADO',  rev:true, flag:true},
  {id:'c-9830', ag:'5005', cli:'Rosa Jiménez Arce',   dur:'8:55', h:'Hoy 09:58', score:97, tip:'VENTA',       rev:true, joya:true},
  {id:'c-9822', ag:'5006', cli:'Nelly Vargas Soto',   dur:'2:04', h:'Hoy 09:12', score:58, tip:'NO INTERESADO',rev:false,flag:true},
  {id:'c-9815', ag:'5002', cli:'Iván Delgado Ríos',   dur:'5:31', h:'Ayer 16:40',score:88, tip:'SEGUIMIENTO', rev:false},
];
const CRITERIOS = [
  {t:'Uso del guion',           v:94},
  {t:'Manejo de objeciones',    v:89},
  {t:'Tono y empatía',          v:92},
  {t:'Información correcta',    v:96},
  {t:'Cierre y próximos pasos', v:88},
];

/* ---------------------------------------------------- asistencia ------ */
const SEMANA = ['Lun 14','Mar 15','Mié 16','Jue 17','Vie 18','Lun 21','Mar 22'];
const ASISTENCIA = {
  '5000':['ok','ok','ok','ok','ok','ok','ok'],
  '5001':['ok','tarde','ok','ok','ok','ok','ok'],
  '5002':['ok','ok','ok','ok','ok','ok','ok'],
  '5003':['tarde','ok','tarde','ok','ok','tarde','tarde'],
  '5004':['ok','ok','ok','libre','ok','ok','ok'],
  '5005':['ok','ok','ok','ok','ok','ok','ok'],
  '5006':['ok','ok','falta','ok','tarde','ok','ok'],
  '5007':['ok','falta','falta','ok','tarde','falta','falta'],
};
const PAUSAS_TIPO = [
  {t:'Refrigerio',   min:45, tope:45, color:'#2563EB'},
  {t:'Baño',         min:10, tope:10, color:'#0891B2'},
  {t:'Capacitación', min:30, tope:60, color:'#7C3AED'},
];
/* Minutos de pausa por agente y motivo, en el día. */
const PAUSAS_HOY = {
  '5000':[42,0,0],  '5001':[45,6,0],  '5002':[38,0,0], '5003':[45,1,0],
  '5004':[44,0,0],  '5005':[40,0,0],  '5006':[45,23,19],'5007':[22,0,0],
};
const COACHING = {
  '5003':[
    {f:'Hoy 14:21',  t:'Susurro',  d:'Le recordé tipificar antes de colgar.',           q:'Rodrigo Valdez'},
    {f:'16/10',      t:'Coaching', d:'Trabajamos el cierre. Compromiso: pedir la firma.',q:'Rodrigo Valdez'},
    {f:'09/10',      t:'Susurro',  d:'Se adelantó al precio sin presentar el beneficio.',q:'Ivana Cueva'},
  ],
};

/* -------------------------------------------------- comunicación ------ */
const BROADCASTS = [
  {f:'Hoy 09:05', t:'Promoción del día: tasa preferente 11,9 % hasta las 6 p.m.',
   img:true, destino:'Todo el piso', vistos:['5000','5001','5002','5003','5005'], total:8},
  {f:'Ayer 15:30', t:'Recordatorio: toda venta necesita la boleta del tercer mes.',
   img:false, destino:'carsa0001', vistos:['5000','5003','5005','5007'], total:4},
];
const CHAT = {
  '5003':[
    {de:'el', t:'Jefe, el cliente pide cuota menor a 700. ¿Puedo estirar a 30 meses?', h:'14:39'},
    {de:'yo', t:'Sí, hasta 30. No bajes la tasa.', h:'14:40'},
    {de:'el', t:'Perfecto, gracias', h:'14:40'},
  ],
  '5006':[
    {de:'yo', t:'Diana, ¿todo bien? Llevas 23 minutos en pausa.', h:'14:41'},
  ],
};
const MANOS = [
  {ag:'5003', motivo:'Necesita aprobar una cuota fuera de política', h:'14:39', cli:'Héctor Chávez Olaya'},
];

/* ------------------------------------------------------ reportes ------ */
const META_DIA = 320000;
const CURVA = [            // [hora, real acumulado, meta acumulada]
  [8,8400,12000],[9,31200,42000],[10,68500,86000],[11,109400,132000],
  [12,142800,168000],[13,158900,186000],[14,196300,224000],[15,null,262000],
  [16,null,296000],[17,null,320000],
];
const REAL_AHORA = 196300;
const EMBUDO = [
  {t:'Leads asignados', n:1640, c:'#1D4ED8'},
  {t:'Contactados',     n:673,  c:'#2563EB'},
  {t:'Conversaciones',  n:412,  c:'#3B82F6'},
  {t:'Cotizaciones',    n:148,  c:'#60A5FA'},
  {t:'Ventas',          n:31,   c:'#16A34A'},
];
const PRODUCTOS = [
  {t:'Crédito personal',  n:14, s:118500, c:'#2563EB'},
  {t:'Renovación póliza', n:9,  s:72800,  c:'#7C3AED'},
  {t:'Seguro vehicular',  n:5,  s:58200,  c:'#0891B2'},
  {t:'SOAT',              n:3,  s:12400,  c:'#D97706'},
];
/* Los 20 indicadores que el motor ya sabe calcular hoy. */
const KPIS_MOTOR = [
  {t:'Marcadas',            v:'1,847'},  {t:'Teléfonos únicos',   v:'1,640'},
  {t:'Contestadas',         v:'892'},    {t:'Contestó una persona',v:'673'},
  {t:'Contestador',         v:'219'},    {t:'Colgadas por el motor',v:'28'},
  {t:'Contactados',         v:'673'},    {t:'Ventas',              v:'31'},
  {t:'En curso',            v:'3'},      {t:'Sin tipificar',       v:'11'},
  {t:'Contactabilidad',     v:'36,4 %'}, {t:'Conversión',          v:'4,6 %'},
  {t:'ASR',                 v:'48,3 %'}, {t:'Abandono',            v:'4,2 %'},
  {t:'% contestador',       v:'24,6 %'}, {t:'Espera promedio',     v:'7,4 s'},
  {t:'AHT',                 v:'4 m 18 s'},{t:'Ocupación',          v:'71 %'},
  {t:'Nivel de marcado',    v:'2,4'},    {t:'Cobertura del dato',  v:'Observada'},
];
