'use strict';
/* Secciones de demostración de la consola: Tareas, Reportes y Base de
   conocimiento.

   TODO lo que hay acá son DATOS INVENTADOS. Ninguna de estas tres pantallas
   consulta el servidor: viven enteras en el navegador para poder mostrarse
   sin backend. Están separadas de crm.js a propósito, para que se vea de un
   vistazo qué parte de la consola es real —la cartera, el discador, la
   tipificación— y qué parte es escenografía.

   Cuando cada área tenga su origen de verdad, se reemplaza el bloque de
   datos de arriba por la consulta y las funciones de pintado siguen
   sirviendo tal cual.

   Depende de crm.js para `el`, `svg`, `aviso` y `estado`; se carga antes,
   pero sólo se ejecuta cuando el usuario entra a una sección.            */

/* ====================================================================== */
/*  DATOS                                                                 */
/* ====================================================================== */

// Fecha de referencia de la demo. Se calcula al arrancar para que las tareas
// digan "hace 2 horas" y no una fecha fija de hace meses.
const DEMO_HOY = new Date();
const demoDia = d => { const x = new Date(DEMO_HOY); x.setDate(x.getDate() + d); return x; };
const demoHora = (d, h, m) => { const x = demoDia(d); x.setHours(h, m || 0, 0, 0); return x; };

const DEMO_TAREAS = [
  { id:1, tipo:'rellamada', prioridad:'alta', cliente:'Betty Ramírez Nizama', telefono:'980910856',
    vence:demoHora(0, 9, 30), minutos:10,
    titulo:'Rellamada comprometida',
    detalle:'Pidió que la llamen al salir del trabajo. Quedó en S/ 8 000 a 24 meses; falta confirmar el sustento de ingresos.',
    sla:'Comprometida con el cliente', origen:'Tipificación 010102 · VOLVER A LLAMAR' },

  { id:2, tipo:'documento', prioridad:'alta', cliente:'Héctor Chávez Olaya', telefono:'987130189',
    vence:demoHora(0, 11, 0), minutos:20,
    titulo:'Falta boleta de pago',
    detalle:'Aprobado condicional por S/ 18 000. Sin la tercera boleta el expediente cae a las 18:00 y vuelve a evaluación.',
    sla:'Vence hoy 18:00', origen:'Riesgos · expediente 44120' },

  { id:3, tipo:'seguimiento', prioridad:'media', cliente:'Sabina Vásquez Peña', telefono:'961679915',
    vence:demoHora(0, 15, 0), minutos:15,
    titulo:'Enviar cotización por WhatsApp',
    detalle:'Interesada en compra de deuda por S/ 12 000. Pidió verlo con su esposo; quedó en responder hoy.',
    sla:'Mismo día', origen:'Tipificación 010101 · INTERESADO' },

  { id:4, tipo:'rellamada', prioridad:'alta', cliente:'Leopoldo Seminario Ruiz', telefono:'51957741704',
    vence:demoHora(-1, 17, 30), minutos:10,
    titulo:'Rellamada vencida',
    detalle:'No contestó en dos intentos. Tercer intento antes de devolverlo a la base: la campaña permite hasta 6.',
    sla:'Vencida ayer', origen:'Discador · intento 2 de 6' },

  { id:5, tipo:'venta', prioridad:'alta', cliente:'Tomás Calderón Castro', telefono:'902925295',
    vence:demoHora(0, 12, 30), minutos:25,
    titulo:'Cerrar desembolso',
    detalle:'Firmó ayer. Falta validar la cuenta de abono con el área de operaciones para desembolsar hoy.',
    sla:'Comisión del mes en juego', origen:'Venta 010201 · S/ 15 000' },

  { id:6, tipo:'seguimiento', prioridad:'media', cliente:'Mónica Farfán Rivas', telefono:'962878679',
    vence:demoHora(1, 10, 0), minutos:15,
    titulo:'Confirmar interés tras cotización',
    detalle:'Se le envió la propuesta el martes. No respondió el WhatsApp; llamar antes de darla por perdida.',
    sla:'48 h desde el envío', origen:'Seguimiento automático' },

  { id:7, tipo:'documento', prioridad:'media', cliente:'Alejandrina Quispe Alvarado', telefono:'990480687',
    vence:demoHora(1, 16, 0), minutos:10,
    titulo:'Actualizar recibo de servicio',
    detalle:'El recibo cargado tiene cuatro meses. Política exige máximo dos.',
    sla:'Antes del comité del jueves', origen:'Riesgos · observación 3' },

  { id:8, tipo:'rellamada', prioridad:'baja', cliente:'Guillermina Zapata Córdova', telefono:'997471614',
    vence:demoHora(2, 9, 0), minutos:10,
    titulo:'Rellamada agendada',
    detalle:'Pidió que la contacten el viernes por la mañana, después de cobrar.',
    sla:'Agendada por el cliente', origen:'Tipificación 010102' },

  { id:9, tipo:'seguimiento', prioridad:'baja', cliente:'Carlos Ipanaqué Sandoval', telefono:'955251732',
    vence:demoHora(3, 11, 0), minutos:15,
    titulo:'Ofrecer ampliación de línea',
    detalle:'Cliente al día con 14 cuotas pagadas. Califica para ampliación sin nueva evaluación.',
    sla:'Campaña de ampliación', origen:'Base preaprobados' },

  { id:10, tipo:'documento', prioridad:'media', cliente:'Flor Nizama Seminario', telefono:'940989378',
    vence:demoHora(4, 12, 0), minutos:10,
    titulo:'Firma de contrato pendiente',
    detalle:'Contrato enviado el lunes. Sin firma no corre el desembolso ni la comisión.',
    sla:'5 días desde el envío', origen:'Operaciones' }
];

// Las primeras ya están hechas: una lista que arranca vacía no se entiende.
const DEMO_TAREAS_HECHAS = [
  { id:101, tipo:'rellamada', cliente:'Percy Olaya Ruiz', titulo:'Rellamada atendida',
    cuando:demoHora(0, 8, 40), resultado:'Contactado · agendó visita' },
  { id:102, tipo:'documento', cliente:'Dailita Rivas Peña', titulo:'DNI validado',
    cuando:demoHora(0, 9, 5), resultado:'Expediente completo' },
  { id:103, tipo:'seguimiento', cliente:'José Castro Farfán', titulo:'Cotización enviada',
    cuando:demoHora(0, 9, 50), resultado:'Leída, sin respuesta' },
  { id:104, tipo:'venta', cliente:'María Alvarado Quispe', titulo:'Venta confirmada',
    cuando:demoHora(0, 10, 15), resultado:'S/ 9 500 · 24 cuotas' }
];

const TAREA_TIPO = {
  rellamada:  { et:'Rellamada',  ico:'tel',     color:'var(--ok)' },
  documento:  { et:'Documento',  ico:'doc',     color:'var(--info)' },
  seguimiento:{ et:'Seguimiento',ico:'wa',      color:'var(--wa)' },
  venta:      { et:'Venta',      ico:'gestion', color:'var(--primary)' }
};

/* ---------------------------------------------------------- reportes --- */

// Ventas del mes, día por día. De acá sale todo lo demás: el total, la
// comisión, el ritmo y el gráfico. Nada está escrito dos veces.
const DEMO_VENTAS_MES = [
  {dia:1, n:1, monto:7500}, {dia:2, n:0, monto:0}, {dia:3, n:2, monto:21000},
  {dia:6, n:1, monto:9000}, {dia:7, n:1, monto:12000}, {dia:8, n:0, monto:0},
  {dia:9, n:2, monto:17500}, {dia:10, n:1, monto:8000}, {dia:13, n:0, monto:0},
  {dia:14, n:1, monto:15000}, {dia:15, n:2, monto:24000}, {dia:16, n:1, monto:6500},
  {dia:17, n:0, monto:0}, {dia:18, n:1, monto:11000}, {dia:19, n:1, monto:9500}
];

// Escalera de comisiones. El tramo se paga sobre el total, no por partes:
// así es como el asesor lo entiende, y es lo que hace que "me faltan 3"
// tenga un número de plata al lado.
const DEMO_TRAMOS = [
  {nombre:'Base',    desde:0,  pct:2.0},
  {nombre:'Plata',   desde:9,  pct:3.5},
  {nombre:'Oro',     desde:15, pct:5.0},
  {nombre:'Platino', desde:22, pct:6.5}
];

const DEMO_META_MES = 20;        // ventas que pide la campaña
const DEMO_DIAS_HABILES = 22;    // del mes
const DEMO_DIA_ACTUAL = 19;

const DEMO_KPIS = [
  {k:'llamadas',  et:'Llamadas marcadas',  v:486,   ayer:462,  fmt:'n'},
  {k:'contacto',  et:'Contactos efectivos',v:197,   ayer:188,  fmt:'n'},
  {k:'tasa',      et:'Tasa de contacto',   v:40.5,  ayer:40.7, fmt:'%'},
  {k:'conv',      et:'Conversión',         v:7.1,   ayer:6.4,  fmt:'%'},
  {k:'ticket',    et:'Ticket promedio',    v:11364, ayer:10890,fmt:'s/'},
  {k:'tmo',       et:'Tiempo medio',       v:243,   ayer:268,  fmt:'t', menosEsMejor:true}
];

const DEMO_RANKING = [
  {pos:1, nombre:'Rosa Medina',    ventas:17, monto:198000, yo:false},
  {pos:2, nombre:'Julio Panta',    ventas:15, monto:171500, yo:false},
  {pos:3, nombre:'Ana Torres',     ventas:14, monto:159000, yo:true},
  {pos:4, nombre:'Milagros Cruz',  ventas:13, monto:148000, yo:false},
  {pos:5, nombre:'Edwin Saavedra', ventas:11, monto:126500, yo:false},
  {pos:6, nombre:'Carla Ramos',    ventas:9,  monto:97000,  yo:false}
];

const DEMO_LOGROS = [
  {et:'Racha de 4 días', desc:'Al menos una venta por día', hecho:true},
  {et:'Cierre relámpago', desc:'Venta en menos de 3 minutos', hecho:true},
  {et:'Sin abandonos', desc:'Ninguna llamada sin tipificar', hecho:true},
  {et:'Club de los 20', desc:'Llegar a la meta del mes', hecho:false}
];

/* ------------------------------------------------------ conocimiento --- */

const DEMO_WIKI = [
  { id:'cred-personal', cat:'Productos', titulo:'Crédito personal',
    resumen:'Libre disponibilidad, sin garantía. El caballito de batalla de la campaña.',
    tags:['tasas', 'requisitos', 'plazos'], actualizado:'hace 3 días', autor:'Producto',
    battle:{
      gana:['Desembolso en 24 h si el expediente está completo',
            'No pide garantía ni aval hasta S/ 20 000',
            'Se puede adelantar cuotas sin penalidad'],
      objeciones:[['«Está caro»', 'Cambiá el eje a la cuota mensual, no a la tasa.'],
                  ['«Lo voy a pensar»', 'Acotá: cotización por WhatsApp y rellamada con hora.'],
                  ['«El banco me da menos tasa»', 'Preguntá el plazo: casi siempre comparan distinto.']],
      limites:['No aplica a independientes sin RUC vigente',
               'Monto máximo S/ 20 000 sin sustento ampliado'],
      siguiente:'Cotización firmada + recibo de servicio en la misma llamada.'
    },
    secciones:[
      ['Para quién es', 'Dependientes con 6 meses en planilla, o independientes con RUC activo y 12 meses de facturación. Edad 21 a 70 al vencimiento de la última cuota.'],
      ['Montos y plazos', 'De S/ 1 000 a S/ 20 000. Plazo de 6 a 48 meses. Hasta 36 meses sin requisitos adicionales; de 37 a 48 necesita visto bueno del supervisor.'],
      ['Tasas vigentes', 'TEA de 45 % a 59 % según evaluación. Con domiciliación de haberes baja 4 puntos. La TCEA incluye el desgravamen y es la que hay que mencionar antes de cerrar.'],
      ['La cuota manda', 'La cuota no puede superar el 30 % del ingreso neto declarado. Si no entra, bajá el monto antes de estirar el plazo: el cliente percibe mejor "menos plata" que "más meses".']
    ]},

  { id:'compra-deuda', cat:'Productos', titulo:'Compra de deuda',
    resumen:'Consolida deudas de otras entidades en una sola cuota. El de mejor conversión.',
    tags:['tasas', 'requisitos'], actualizado:'hace 1 semana', autor:'Producto',
    battle:{
      gana:['Una sola cuota en lugar de tres o cuatro',
            'Tasa menor que la del crédito personal',
            'Se puede incluir dinero extra en el mismo desembolso'],
      objeciones:[['«Ya tengo deudas»', 'Ese es el argumento, no la objeción: menos cuota total.'],
                  ['«Me van a reportar»', 'Al contrario: se cancelan las deudas y mejora el historial.']],
      limites:['No compra deuda en cobranza judicial',
               'Máximo 4 entidades en una operación'],
      siguiente:'Pedir los estados de cuenta de las deudas a comprar.'
    },
    secciones:[
      ['Cómo funciona', 'Se cancelan las deudas directamente con las entidades y el cliente queda con una sola cuota. El excedente, si lo hay, se abona a su cuenta.'],
      ['Tasas vigentes', 'TEA desde 38,9 %. Baja a 32,5 % con domiciliación de haberes.'],
      ['Lo que más convierte', 'Mostrá la resta: suma de cuotas actuales contra la cuota nueva. Es el único producto donde el cliente ve el ahorro en el acto.']
    ]},

  { id:'ampliacion', cat:'Productos', titulo:'Ampliación de línea',
    resumen:'Para clientes al día. No requiere nueva evaluación.',
    tags:['requisitos'], actualizado:'hace 2 semanas', autor:'Producto',
    battle:{
      gana:['Sin papeleo: ya está evaluado', 'Desembolso el mismo día'],
      objeciones:[['«No necesito más»', 'Posicionalo como línea disponible, no como deuda.']],
      limites:['Mínimo 12 cuotas pagadas sin atraso'],
      siguiente:'Confirmar el monto preaprobado antes de ofrecerlo.'
    },
    secciones:[
      ['Quién califica', 'Clientes con 12 cuotas pagadas y sin atrasos mayores a 8 días en los últimos 6 meses.'],
      ['Monto', 'Hasta el 60 % de la línea original, con un tope de S/ 12 000.']
    ]},

  { id:'tarifario', cat:'Tarifas', titulo:'Tarifario vigente',
    resumen:'Tasas, seguros y comisiones de todos los productos de la campaña.',
    tags:['tasas'], actualizado:'ayer', autor:'Finanzas',
    secciones:[
      ['Crédito personal', 'TEA 45 % – 59 %. Desgravamen 0,09 % mensual sobre el saldo.'],
      ['Compra de deuda', 'TEA 38,9 % – 44 %. Desgravamen igual.'],
      ['Ampliación de línea', 'TEA 41 % – 52 %.'],
      ['Comisiones', 'Sin comisión de desembolso ni de evaluación. Portes S/ 9 mensuales sólo si pide estado de cuenta físico.'],
      ['Penalidad', 'Mora de 0,12 % diario sobre la cuota vencida. No hay penalidad por pago adelantado.']
    ]},

  { id:'requisitos', cat:'Requisitos', titulo:'Documentos por monto',
    resumen:'Qué pedir y cuándo. La causa número uno de expedientes caídos.',
    tags:['requisitos', 'documentos'], actualizado:'hace 4 días', autor:'Riesgos',
    secciones:[
      ['Hasta S/ 15 000', 'DNI vigente y recibo de servicio del domicilio con antigüedad máxima de 2 meses.'],
      ['Más de S/ 15 000', 'Lo anterior más sustento de ingresos: tres últimas boletas, o los seis últimos recibos por honorarios.'],
      ['Independientes', 'Ficha RUC activa y declaración mensual de los últimos 3 periodos.'],
      ['Error frecuente', 'El recibo de servicio vencido tumba más expedientes que cualquier otra cosa. Pedí la foto en la misma llamada, no después.']
    ]},

  { id:'obj-precio', cat:'Objeciones', titulo:'«Está muy caro»',
    resumen:'La más frecuente. No se discute el precio: se cambia el eje.',
    tags:['objeciones'], actualizado:'hace 2 días', autor:'Comercial',
    battle:{
      gana:['La cuota, no la tasa', 'El plazo como palanca', 'La cotización escrita'],
      objeciones:[['Variante: «el banco me da menos»', 'Casi siempre comparan plazos distintos. Preguntá cuántos meses.'],
                  ['Variante: «es mucho interés»', 'Mostrá el costo mensual, no el anual.']],
      limites:['No prometas tasas fuera del tarifario para salvar la venta.'],
      siguiente:'Si no cierra, dejá cotización escrita y rellamada con hora.'
    },
    secciones:[
      ['El guion', '«¿Con cuánto estaba contando mensualmente? Veamos si entra.» — Reposiciona de tasa a cuota en una frase.'],
      ['Por qué funciona', 'El cliente no compara tasas: compara lo que le queda a fin de mes. La tasa es una abstracción; la cuota, no.'],
      ['Lo que no hay que hacer', 'Defender la tasa. Cada segundo discutiéndola confirma que el precio es el tema.']
    ]},

  { id:'obj-postergar', cat:'Objeciones', titulo:'«Lo voy a pensar»',
    resumen:'No es un no. Es una falta de urgencia o una decisión compartida.',
    tags:['objeciones'], actualizado:'hace 2 días', autor:'Comercial',
    battle:{
      gana:['Reconocer antes de insistir', 'Acotar el tiempo', 'Dejar algo escrito'],
      objeciones:[['«Tengo que consultarlo»', 'Preguntá con quién y ofrecé estar en esa conversación.']],
      limites:['Nunca presionar con vencimientos falsos: se nota y quema la base.'],
      siguiente:'Rellamada con fecha y hora concretas, cargada como tarea.'
    },
    secciones:[
      ['El guion', '«Me parece prudente. ¿Le dejo la cotización por WhatsApp y lo llamo mañana a esta misma hora?»'],
      ['La regla', 'Una rellamada sin fecha no es una rellamada: es una pérdida con otro nombre.']
    ]},

  { id:'desgravamen', cat:'Legal', titulo:'Seguro de desgravamen',
    resumen:'Obligatorio, ya incluido en la cuota. Qué cubre y qué no.',
    tags:['seguros', 'exclusiones'], actualizado:'hace 1 mes', autor:'Legal',
    secciones:[
      ['Qué es', 'Cancela el saldo de la deuda ante fallecimiento o invalidez total y permanente del titular.'],
      ['Costo', '0,09 % mensual sobre el saldo. Ya está dentro de la cuota: no es un cobro aparte.'],
      ['Exclusiones', 'Preexistencias declaradas y no declaradas detectables, y siniestros ocurridos en actividades de riesgo no informadas.'],
      ['Obligatorio decirlo', 'La exclusión por preexistencia debe mencionarse antes de la firma. No es opcional y su omisión anula la venta.']
    ]},

  { id:'horarios', cat:'Procesos', titulo:'Horarios y reglas de contacto',
    resumen:'Cuándo se puede llamar y cuántas veces. De cumplimiento obligatorio.',
    tags:['proceso', 'legal'], actualizado:'hace 3 semanas', autor:'Cumplimiento',
    secciones:[
      ['Ventana horaria', 'De 08:00 a 20:00 de lunes a sábado. Domingos y feriados, prohibido.'],
      ['Intentos', 'Máximo 6 intentos por lead. Un número que pidió no ser contactado va a la lista de exclusión y no se marca nunca más.'],
      ['Identificación', 'Hay que identificarse con nombre y empresa en los primeros 10 segundos.'],
      ['Grabación', 'Toda llamada se graba. Hay que avisarlo si el cliente lo pregunta; nunca negarlo.']
    ]},

  { id:'desembolso', cat:'Procesos', titulo:'Del sí al desembolso',
    resumen:'Los cinco pasos entre la venta y la plata en la cuenta.',
    tags:['proceso'], actualizado:'hace 1 semana', autor:'Operaciones',
    secciones:[
      ['1 · Tipificar', 'La venta se registra como 010201 con la propuesta adjunta. Sin eso, no existe.'],
      ['2 · Expediente', 'DNI, recibo y sustento cargados el mismo día. Riesgos revisa en 2 horas hábiles.'],
      ['3 · Contrato', 'Se envía por WhatsApp para firma digital. Vence a los 5 días.'],
      ['4 · Validación de cuenta', 'Operaciones confirma la cuenta de abono. Es el paso donde más se traba.'],
      ['5 · Desembolso', 'Mismo día si todo entró antes de las 16:00. La comisión se cuenta recién acá.']
    ]}
];

const DEMO_WIKI_CATS = ['Productos', 'Tarifas', 'Requisitos', 'Objeciones', 'Procesos', 'Legal'];

/* ====================================================================== */
/*  PANTALLAS                                                             */
/* ====================================================================== */

const dosDig = n => String(n).padStart(2, '0');
const demoHoraTxt = d => dosDig(d.getHours()) + ':' + dosDig(d.getMinutes());
const demoPlata = n => 'S/ ' + Number(n || 0).toLocaleString('es-PE');

/* Cuánto falta —o hace cuánto venció— en palabras. Un reloj absoluto
   obliga a hacer la cuenta; esto se lee de un vistazo. */
function demoCuando(fecha) {
  const min = Math.round((fecha - new Date()) / 60000);
  const abs = Math.abs(min);
  const txt = abs < 60 ? abs + ' min'
            : abs < 1440 ? Math.round(abs / 60) + ' h'
            : Math.round(abs / 1440) + ' d';
  if (min < -5) return {et:'vencida hace ' + txt, estado:'vencida'};
  if (min <= 30) return {et:'en ' + txt, estado:'urgente'};
  if (min <= 480) return {et:'hoy ' + demoHoraTxt(fecha), estado:'hoy'};
  return {et:demoHoraTxt(fecha) + ' · ' + ['dom','lun','mar','mié','jue','vie','sáb'][fecha.getDay()], estado:'futuro'};
}

function demoCabecera(titulo, bajada, acciones) {
  return el('header', {cls:'v-head'},
    el('div', null, el('h1', {txt:titulo}), el('p', {txt:bajada})),
    el('div', {cls:'v-head-acts'}, acciones || []));
}

/* ------------------------------------------------------------ TAREAS --- */

const demoEstadoTareas = {filtro:'todas', hechas:DEMO_TAREAS_HECHAS.map(t => t.id), cuando:{}};

/* Lo completado hoy: lo que ya venía hecho más lo que el asesor marcó en
   esta sesión, por hora. Una tarea que desaparece de la lista sin aparecer
   en ningún otro lado se siente perdida. */
function demoHechasHoy() {
  const propias = DEMO_TAREAS
    .filter(t => demoEstadoTareas.cuando[t.id])
    .map(t => ({titulo:t.titulo, cliente:t.cliente, cuando:demoEstadoTareas.cuando[t.id],
                resultado:'Completada por vos'}));
  return DEMO_TAREAS_HECHAS.concat(propias).sort((a, b) => a.cuando - b.cuando);
}

function demoTareasPendientes() {
  return DEMO_TAREAS.filter(t => demoEstadoTareas.hechas.indexOf(t.id) < 0);
}

function demoTareaFicha(t) {
  const info = TAREA_TIPO[t.tipo];
  const cuando = demoCuando(t.vence);
  const hecha = demoEstadoTareas.hechas.indexOf(t.id) >= 0;

  return el('article', {cls:'tarea tarea--' + cuando.estado + (hecha ? ' is-hecha' : '')},
    el('div', {cls:'tarea-ico', style:'background:' + info.color}, svg(info.ico)),

    el('div', {cls:'tarea-cuerpo'},
      el('div', {cls:'tarea-linea1'},
        el('b', {cls:'tarea-titulo', txt:t.titulo}),
        el('span', {cls:'tag tag--' + t.prioridad, txt:t.prioridad}),
        el('span', {cls:'tarea-tipo', txt:info.et})),

      el('div', {cls:'tarea-cliente'},
        avatar(t.cliente, 'avatar--sm'),
        el('b', {txt:t.cliente}),
        el('span', {cls:'tarea-tel', txt:t.telefono})),

      el('p', {cls:'tarea-detalle', txt:t.detalle}),

      el('div', {cls:'tarea-pie'},
        el('span', {cls:'tarea-sla'}, svg('reloj'), t.sla),
        el('span', {cls:'tarea-origen', txt:t.origen}),
        el('span', {cls:'tarea-dura', txt:'~' + t.minutos + ' min'}))),

    el('div', {cls:'tarea-lado'},
      el('span', {cls:'tarea-vence tarea-vence--' + cuando.estado, txt:cuando.et}),
      el('div', {cls:'tarea-acts'},
        el('button', {cls:'btn-sm btn-sm--primary', type:'button', txt:'Llamar',
          onclick:() => estado('Demostración: desde acá se marcaría a ' + t.cliente + '.')}),
        el('button', {cls:'btn-sm', type:'button', txt:'Reprogramar',
          onclick:() => aviso('Reprogramar', 'En la versión conectada esto abre la agenda y mueve el compromiso con el cliente.')}),
        el('button', {cls:'btn-sm', type:'button', txt:hecha ? 'Reabrir' : 'Completar',
          onclick:() => {
            const i = demoEstadoTareas.hechas.indexOf(t.id);
            if (i >= 0) {
              demoEstadoTareas.hechas.splice(i, 1);
              delete demoEstadoTareas.cuando[t.id];
            } else {
              demoEstadoTareas.hechas.push(t.id);
              // Se guarda la hora para que la tarea aparezca en "Completadas
              // hoy": sacarla de la lista y que no reaparezca en ningún lado
              // da la sensación de que se perdió.
              demoEstadoTareas.cuando[t.id] = new Date();
            }
            pintarTareas();
            if (typeof marcarTareasPendientes === 'function') marcarTareasPendientes();
            estado(i >= 0 ? 'Tarea reabierta.' : 'Tarea completada · ' + t.titulo);
          }}))));
}

function pintarTareas() {
  const caja = $('vista');
  const pend = demoTareasPendientes();
  const vencidas = pend.filter(t => t.vence < new Date());
  const hoy = pend.filter(t => t.vence >= new Date() && t.vence < demoHora(1, 0));
  const total = DEMO_TAREAS.length;
  const hechas = total - pend.length;
  const pct = Math.round(hechas / total * 100);
  const minutos = pend.reduce((a, t) => a + t.minutos, 0);

  const FILTROS = [
    ['todas', 'Todas', pend.length],
    ['vencida', 'Vencidas', vencidas.length],
    ['hoy', 'Para hoy', hoy.length],
    ['rellamada', 'Rellamadas', pend.filter(t => t.tipo === 'rellamada').length],
    ['documento', 'Documentos', pend.filter(t => t.tipo === 'documento').length],
    ['seguimiento', 'Seguimiento', pend.filter(t => t.tipo === 'seguimiento').length]
  ];

  const visibles = pend.filter(t => {
    const f = demoEstadoTareas.filtro;
    if (f === 'todas') return true;
    if (f === 'vencida') return t.vence < new Date();
    if (f === 'hoy') return t.vence >= new Date() && t.vence < demoHora(1, 0);
    return t.tipo === f;
  }).sort((a, b) => a.vence - b.vence);

  caja.replaceChildren(
    demoCabecera('Tareas', 'Compromisos con el cliente y pendientes del expediente.', [
      el('button', {cls:'btn-sm', type:'button', txt:'Ordenar por prioridad',
        onclick:() => estado('Demostración: el orden por vencimiento es el que menos tareas pierde.')}),
      el('button', {cls:'btn-sm btn-sm--primary', type:'button', txt:'Nueva tarea',
        onclick:() => aviso('Nueva tarea', 'En la versión conectada esto crea un compromiso ligado a la ficha del cliente.')})
    ]),

    // Resumen del día.
    el('div', {cls:'v-kpis'},
      demoTarjetaKpi('Pendientes', String(pend.length), pend.length + ' de ' + total, pend.length ? '' : 'ok'),
      demoTarjetaKpi('Vencidas', String(vencidas.length), vencidas.length ? 'requieren acción hoy' : 'ninguna', vencidas.length ? 'mal' : 'ok'),
      demoTarjetaKpi('Para hoy', String(hoy.length), 'antes de cerrar el turno', ''),
      demoTarjetaKpi('Carga estimada', Math.round(minutos / 60 * 10) / 10 + ' h', minutos + ' min de trabajo', '')),

    // Avance.
    el('div', {cls:'v-barra'},
      el('div', {cls:'v-barra-txt'},
        el('b', {txt:hechas + ' de ' + total + ' completadas'}),
        el('span', {txt:pct + ' %'})),
      el('div', {cls:'barra'}, el('i', {style:'width:' + pct + '%'}))),

    el('div', {cls:'v-chips'}, FILTROS.map(([id, et, n]) =>
      el('button', {cls:'chip chip--claro', type:'button', 'aria-pressed':String(demoEstadoTareas.filtro === id),
        onclick:() => { demoEstadoTareas.filtro = id; pintarTareas(); }},
        et, el('b', {txt:String(n)})))),

    el('div', {cls:'tareas'}, visibles.length
      ? visibles.map(demoTareaFicha)
      : [el('div', {cls:'empty', txt:'Nada pendiente en este filtro.'})]),

    // Lo hecho hoy, para que la lista no se sienta vacía al avanzar.
    el('section', {cls:'v-bloque'},
      el('h2', {txt:'Completadas hoy'}),
      el('ul', {cls:'hechas'}, demoHechasHoy().map(t =>
        el('li', null,
          svg('gestion'),
          el('b', {txt:t.titulo}),
          el('span', {txt:' · ' + t.cliente}),
          el('span', {cls:'when', txt:demoHoraTxt(t.cuando) + ' · ' + t.resultado}))))));
}

function demoTarjetaKpi(et, valor, pie, tono) {
  return el('div', {cls:'kpi' + (tono ? ' kpi--' + tono : '')},
    el('span', {cls:'kpi-et', txt:et}),
    el('b', {cls:'kpi-v', txt:valor}),
    el('span', {cls:'kpi-pie', txt:pie}));
}

/* ---------------------------------------------------------- REPORTES --- */

// Todo sale de DEMO_VENTAS_MES: si se cambia un día, cambian el total, la
// comisión, el ritmo y el gráfico a la vez. Nada se escribe dos veces.
function demoResumenMes() {
  const ventas = DEMO_VENTAS_MES.reduce((a, d) => a + d.n, 0);
  const monto = DEMO_VENTAS_MES.reduce((a, d) => a + d.monto, 0);
  const tramo = DEMO_TRAMOS.filter(t => ventas >= t.desde).pop();
  const siguiente = DEMO_TRAMOS.find(t => t.desde > ventas) || null;
  const comision = monto * tramo.pct / 100;

  // Cuánto más cobraría si llegara al tramo siguiente, manteniendo el ticket.
  let salto = 0, faltan = 0;
  if (siguiente) {
    faltan = siguiente.desde - ventas;
    const ticket = monto / ventas;
    const montoProy = monto + ticket * faltan;
    salto = montoProy * siguiente.pct / 100 - comision;
  }
  const diasRestantes = DEMO_DIAS_HABILES - DEMO_DIA_ACTUAL;
  const faltanMeta = Math.max(0, DEMO_META_MES - ventas);
  return {ventas, monto, tramo, siguiente, comision, faltan, salto,
          diasRestantes, faltanMeta,
          ritmo: diasRestantes > 0 ? faltanMeta / diasRestantes : faltanMeta,
          pctMeta: Math.min(100, Math.round(ventas / DEMO_META_MES * 100))};
}

// Anillo de progreso con dos arcos SVG. Sin librerías ni imágenes.
function demoAnillo(pct, centro, pie) {
  const r = 52, c = 2 * Math.PI * r;
  const ns = 'http://www.w3.org/2000/svg';
  const arco = (color, ancho, dash) => {
    const n = document.createElementNS(ns, 'circle');
    n.setAttribute('cx', '60'); n.setAttribute('cy', '60'); n.setAttribute('r', String(r));
    n.setAttribute('fill', 'none'); n.setAttribute('stroke', color);
    n.setAttribute('stroke-width', String(ancho)); n.setAttribute('stroke-linecap', 'round');
    if (dash) { n.setAttribute('stroke-dasharray', dash); n.setAttribute('transform', 'rotate(-90 60 60)'); }
    return n;
  };
  const s = document.createElementNS(ns, 'svg');
  s.setAttribute('viewBox', '0 0 120 120');
  s.setAttribute('class', 'anillo-svg');
  s.appendChild(arco('var(--rule)', 11));
  s.appendChild(arco('var(--ok)', 11, (c * pct / 100).toFixed(1) + ' ' + c.toFixed(1)));
  return el('div', {cls:'anillo'}, s,
    el('div', {cls:'anillo-txt'}, el('b', {txt:centro}), el('span', {txt:pie})));
}

function demoFmt(kpi) {
  if (kpi.fmt === '%') return kpi.v.toFixed(1) + ' %';
  if (kpi.fmt === 's/') return demoPlata(kpi.v);
  if (kpi.fmt === 't') return Math.floor(kpi.v / 60) + ':' + dosDig(kpi.v % 60);
  return String(kpi.v);
}

function demoDelta(kpi) {
  const d = kpi.v - kpi.ayer;
  if (!d) return el('span', {cls:'delta', txt:'sin cambio'});
  const sube = d > 0;
  const bueno = kpi.menosEsMejor ? !sube : sube;
  const pct = Math.abs(d / kpi.ayer * 100).toFixed(1);
  return el('span', {cls:'delta ' + (bueno ? 'delta--sube' : 'delta--baja'),
    txt:(sube ? '▲ ' : '▼ ') + pct + ' % vs ayer'});
}

function pintarReportes() {
  const r = demoResumenMes();
  const maxDia = Math.max(...DEMO_VENTAS_MES.map(d => d.monto)) || 1;

  $('vista').replaceChildren(
    demoCabecera('Reportes', 'Tu mes, tu comisión y qué falta para el próximo tramo.', [
      el('span', {cls:'v-periodo', txt:'Octubre 2026 · día ' + DEMO_DIA_ACTUAL + ' de ' + DEMO_DIAS_HABILES}),
      el('button', {cls:'btn-sm', type:'button', txt:'Exportar',
        onclick:() => aviso('Exportar', 'En la versión conectada esto descarga el detalle del mes en Excel.')})
    ]),

    /* Lo primero que el asesor quiere saber: cuánto lleva ganado y cuánto
       le falta para el salto. El resto del tablero explica ese número. */
    el('section', {cls:'hero'},
      el('div', {cls:'hero-plata'},
        el('span', {cls:'hero-et', txt:'Comisión acumulada'}),
        el('b', {cls:'hero-v', txt:demoPlata(Math.round(r.comision))}),
        el('span', {cls:'hero-pie',
          txt:'Tramo ' + r.tramo.nombre + ' · ' + r.tramo.pct.toFixed(1) + ' % sobre ' + demoPlata(r.monto)}),
        r.siguiente
          ? el('div', {cls:'hero-salto'},
              el('b', {txt:'Te faltan ' + r.faltan + (r.faltan === 1 ? ' venta' : ' ventas')
                         + ' para ' + r.siguiente.nombre}),
              el('span', {txt:'Eso suma unos ' + demoPlata(Math.round(r.salto)) + ' más a tu comisión.'}))
          : el('div', {cls:'hero-salto'}, el('b', {txt:'Estás en el tramo más alto.'}))),

      demoAnillo(r.pctMeta, r.ventas + '/' + DEMO_META_MES, 'ventas del mes'),

      el('div', {cls:'hero-ritmo'},
        el('span', {cls:'hero-et', txt:'Para llegar a la meta'}),
        el('b', {cls:'hero-v2', txt:r.faltanMeta + ' ventas'}),
        el('span', {cls:'hero-pie', txt:'en ' + r.diasRestantes + ' días hábiles'}),
        el('div', {cls:'hero-ritmo-n'},
          el('b', {txt:r.ritmo.toFixed(1)}), ' por día'),
        el('span', {cls:'hero-pie', txt:'Vas a ' + (r.ventas / DEMO_DIA_ACTUAL).toFixed(1) + ' por día'}))),

    // Escalera de tramos: dónde está parado y qué viene.
    el('section', {cls:'v-bloque'},
      el('h2', {txt:'Escalera de comisiones'}),
      el('div', {cls:'escalera'}, DEMO_TRAMOS.map(t => {
        const activo = t.nombre === r.tramo.nombre;
        const logrado = r.ventas >= t.desde;
        return el('div', {cls:'peldano' + (activo ? ' is-actual' : '') + (logrado ? ' is-logrado' : '')},
          el('b', {txt:t.nombre}),
          el('span', {cls:'peldano-pct', txt:t.pct.toFixed(1) + ' %'}),
          el('span', {cls:'peldano-req', txt:'desde ' + t.desde + ' ventas'}),
          activo ? el('span', {cls:'peldano-aqui', txt:'estás acá'}) : null);
      }))),

    // Indicadores con comparación contra ayer.
    el('section', {cls:'v-bloque'},
      el('h2', {txt:'Tu operación'}),
      el('div', {cls:'v-kpis v-kpis--4'}, DEMO_KPIS.map(k =>
        el('div', {cls:'kpi'},
          el('span', {cls:'kpi-et', txt:k.et}),
          el('b', {cls:'kpi-v', txt:demoFmt(k)}),
          demoDelta(k))))),

    // Gráfico de barras del mes, en CSS.
    el('section', {cls:'v-bloque'},
      el('h2', {txt:'Ventas por día'}),
      el('div', {cls:'grafico'}, DEMO_VENTAS_MES.map(d =>
        el('div', {cls:'gcol' + (d.dia === DEMO_DIA_ACTUAL ? ' is-hoy' : '')},
          el('span', {cls:'gval', txt:d.n ? String(d.n) : ''}),
          el('i', {style:'height:' + Math.round(d.monto / maxDia * 100) + '%',
            title:demoPlata(d.monto)}),
          el('span', {cls:'gdia', txt:String(d.dia)}))))),

    // Tabla de posiciones. La competencia sana es lo que más mueve la aguja
    // en un piso de ventas, y el asesor tiene que verse a sí mismo.
    el('section', {cls:'v-bloque'},
      el('h2', {txt:'Tabla del equipo'}),
      el('ul', {cls:'ranking'}, DEMO_RANKING.map(p =>
        el('li', {cls:p.yo ? 'is-yo' : ''},
          el('span', {cls:'rank-pos', txt:String(p.pos)}),
          avatar(p.nombre, 'avatar--sm'),
          el('b', {txt:p.nombre}),
          p.yo ? el('span', {cls:'rank-tu', txt:'vos'}) : null,
          el('span', {cls:'rank-n', txt:p.ventas + ' ventas'}),
          el('span', {cls:'rank-m', txt:demoPlata(p.monto)}))))),

    // Logros: pequeños, concretos y verificables.
    el('section', {cls:'v-bloque'},
      el('h2', {txt:'Logros del mes'}),
      el('div', {cls:'logros'}, DEMO_LOGROS.map(l =>
        el('div', {cls:'logro' + (l.hecho ? ' is-hecho' : '')},
          svg(l.hecho ? 'gestion' : 'escudo'),
          el('div', null, el('b', {txt:l.et}), el('span', {txt:l.desc})))))),

    el('p', {cls:'v-nota', txt:'Datos de demostración. Las cifras no provienen de la operación real.'}));
}

/* ------------------------------------------------------ CONOCIMIENTO --- */

const demoWikiEstado = {cat:'Todas', busqueda:'', abierto:DEMO_WIKI[0].id};

function demoWikiFiltrados() {
  const q = demoWikiEstado.busqueda.trim().toLowerCase();
  return DEMO_WIKI.filter(a => {
    if (demoWikiEstado.cat !== 'Todas' && a.cat !== demoWikiEstado.cat) return false;
    if (!q) return true;
    const heno = [a.titulo, a.resumen, a.cat, a.tags.join(' '),
                  (a.secciones || []).map(s => s.join(' ')).join(' ')].join(' ').toLowerCase();
    return heno.includes(q);
  });
}

/* Una ficha de combate: por qué ganamos, qué objeciones esperar, dónde están
   los límites y cuál es el paso siguiente. Se lee en menos de un minuto,
   que es lo que dura la atención del asesor en medio de una llamada. */
function demoBattle(b) {
  if (!b) return null;
  return el('div', {cls:'battle'},
    el('div', {cls:'battle-col battle-col--gana'},
      el('h4', null, svg('rayo', 'ico ico--fill'), 'Por qué ganamos'),
      el('ul', null, b.gana.map(t => el('li', {txt:t})))),

    el('div', {cls:'battle-col battle-col--obj'},
      el('h4', null, svg('sms-f', 'ico ico--fill'), 'Si te dicen…'),
      el('ul', null, b.objeciones.map(([q, a]) =>
        el('li', null, el('b', {txt:q}), el('span', {txt:a}))))),

    el('div', {cls:'battle-col battle-col--lim'},
      el('h4', null, svg('escudo', 'ico ico--fill'), 'Dónde NO va'),
      el('ul', null, b.limites.map(t => el('li', {txt:t})))),

    el('div', {cls:'battle-col battle-col--next'},
      el('h4', null, svg('tel-f', 'ico ico--fill'), 'Paso siguiente'),
      el('p', {txt:b.siguiente})));
}

/* Resalta el término buscado sin tocar innerHTML: el texto puede venir de
   la base de conocimiento, que mañana será contenido editable por terceros. */
function demoResaltar(texto, q) {
  if (!q) return [texto];
  const partes = String(texto).split(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'));
  return partes.map((t, i) => i % 2 ? el('mark', {txt:t}) : t).filter(t => t !== '');
}

function pintarWiki() {
  const lista = demoWikiFiltrados();
  const abierto = DEMO_WIKI.find(a => a.id === demoWikiEstado.abierto) || lista[0] || null;
  const q = demoWikiEstado.busqueda.trim();

  const indice = el('aside', {cls:'wiki-idx'},
    el('div', {cls:'wiki-busca'},
      svg('buscar'),
      el('input', {type:'search', id:'wikiQ', value:demoWikiEstado.busqueda,
        placeholder:'Buscar en la base…', autocomplete:'off',
        oninput:e => {
          demoWikiEstado.busqueda = e.target.value;
          // El repintado reemplaza el campo: se recupera el foco y el punto
          // donde estaba el cursor, o escribir se vuelve imposible.
          const pos = e.target.selectionStart;
          pintarWiki();
          const n = $('wikiQ');
          if (n) {
            n.focus();
            if (typeof n.setSelectionRange === 'function' && pos !== null) {
              try { n.setSelectionRange(pos, pos); } catch (err) { /* no es de texto */ }
            }
          }
        }})),

    el('div', {cls:'wiki-cats'}, ['Todas'].concat(DEMO_WIKI_CATS).map(c =>
      el('button', {cls:'chip chip--claro', type:'button',
        'aria-pressed':String(demoWikiEstado.cat === c), txt:c,
        onclick:() => { demoWikiEstado.cat = c; pintarWiki(); }}))),

    el('ul', {cls:'wiki-lista'}, lista.length ? lista.map(a =>
      el('li', null, el('button', {type:'button',
        'aria-current':String(abierto && a.id === abierto.id),
        onclick:() => { demoWikiEstado.abierto = a.id; pintarWiki(); }},
        el('b', null, demoResaltar(a.titulo, q)),
        el('span', {cls:'wiki-cat', txt:a.cat}),
        el('span', {cls:'wiki-res'}, demoResaltar(a.resumen, q)))))
      : [el('li', null, el('div', {cls:'empty', txt:'Nada coincide con esa búsqueda.'}))]));

  const cuerpo = abierto ? el('article', {cls:'wiki-art'},
    el('header', null,
      el('div', {cls:'wiki-art-tags'},
        el('span', {cls:'tag tag--media', txt:abierto.cat}),
        abierto.tags.map(t => el('span', {cls:'tag tag--baja', txt:t}))),
      el('h2', null, demoResaltar(abierto.titulo, q)),
      el('p', {cls:'wiki-art-res'}, demoResaltar(abierto.resumen, q)),
      el('div', {cls:'wiki-art-meta'},
        el('span', {txt:'Actualizado ' + abierto.actualizado}),
        el('span', {txt:'· ' + abierto.autor}),
        el('span', {cls:'spacer'}),
        el('button', {cls:'btn-sm', type:'button', txt:'Enviar al cliente',
          onclick:() => estado('Demostración: desde acá se envía el extracto por WhatsApp.')}),
        el('button', {cls:'btn-sm btn-sm--primary', type:'button', txt:'Preguntar al asistente',
          onclick:() => {
            irA('clientes');
            const caja = $('asisTexto');
            if (caja) { caja.value = 'Resumime ' + abierto.titulo.toLowerCase() + ' para explicárselo al cliente'; caja.focus(); }
            estado('La pregunta quedó cargada en el asistente.');
          }}))),

    demoBattle(abierto.battle),

    el('div', {cls:'wiki-secs'}, (abierto.secciones || []).map(([t, c]) =>
      el('section', null, el('h3', null, demoResaltar(t, q)), el('p', null, demoResaltar(c, q)))))
  ) : el('div', {cls:'empty', txt:'Elegí un artículo.'});

  $('vista').replaceChildren(
    demoCabecera('Base de conocimiento',
      DEMO_WIKI.length + ' artículos · producto, tarifas, objeciones y procesos', [
      el('button', {cls:'btn-sm', type:'button', txt:'Sugerir corrección',
        onclick:() => aviso('Sugerir corrección', 'En la versión conectada esto abre un ticket al dueño del artículo.')})
    ]),
    el('div', {cls:'wiki'}, indice, cuerpo));
}
