'use strict';
/* Monitoreo remoto — datos ficticios.

   Veinticinco equipos, veintitrés en línea. Todo inventado:

   · Los nombres son de fantasía. Los ocho primeros son los asesores que ya
     aparecen en el resto de la maqueta (Ana Torres, Luis Ramírez…), para que
     el gerente reconozca al mismo piso que vio en las otras pantallas; los
     diecisiete restantes son nuevos.
   · Las IP públicas salen de 203.0.113.0/24, el rango que la RFC 5737
     reserva para documentación. Nunca una IP real.
   · Las IP locales, de 192.168.40.0/24.
   · Las campañas son las de la maqueta más dos inventadas.
   · El contenido del portapapeles es texto operativo inocuo: números de
     ticket inventados y nombres de archivo. Nada que se parezca a una
     credencial, una tarjeta o un documento de identidad.

   El azar está sembrado: los mismos equipos, las mismas cifras y las mismas
   posiciones en cada carga. En una presentación nada puede moverse solo. */

/* Mismo generador sembrado que usa el resto de la maqueta. */
function sembrarMon(s) {
  return function () {
    s |= 0; s = s + 0x6D2B79F5 | 0;
    let t = Math.imul(s ^ s >>> 15, 1 | s);
    t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
    return ((t ^ t >>> 14) >>> 0) / 4294967296;
  };
}

/* Los ocho del piso que ya se conoce, y diecisiete más. */
const MON_PERSONAS = [
  ['TORRES', 'ANA'], ['RAMIREZ', 'LUIS'], ['CHAVEZ', 'MARIA'], ['DIAZ', 'CARLOS'],
  ['ROJAS', 'VALERIA'], ['QUISPE', 'JORGE'], ['PAREDES', 'DIANA'], ['SALINAS', 'MARCO'],
  ['MONTOYA', 'CLAUDIA'], ['ESPINOZA', 'RENZO'], ['CARRION', 'LUCIA'], ['VILCA', 'ABEL'],
  ['HUAMAN', 'PATRICIA'], ['BENITES', 'GUSTAVO'], ['ZEGARRA', 'MILAGROS'], ['LOAYZA', 'IVAN'],
  ['CABRERA', 'NOELIA'], ['MEJIA', 'FRANCO'], ['ASTO', 'KARINA'], ['PANDO', 'HUGO'],
  ['VERASTEGUI', 'SOFIA'], ['NINAHUANCA', 'ELMER'], ['BRAVO', 'TATIANA'],
  ['OCHOA', 'RODRIGO'], ['SANTILLAN', 'GABY'],
];

const MON_CAMPANAS = ['CARSA_0001', 'RENOV_NORTE', 'SOAT_LIMA', 'COBRANZA_A'];

const MON_PROCESOS = [
  ['navegador.exe',    'CRM y correo'],
  ['microsip.exe',     'Teléfono del asesor'],
  ['agente-remoto.exe','Conexión con supervisión'],
  ['hoja-calculo.exe', 'Planilla de seguimiento'],
  ['mensajeria.exe',   'Chat interno'],
  ['visor-pdf.exe',    'Contratos'],
  ['reproductor.exe',  'Grabaciones'],
  ['antivirus.exe',    'Seguridad'],
  ['explorador.exe',   'Archivos'],
  ['notas.exe',        'Apuntes'],
];

const MON_PORTAPAPELES = [
  'Ticket SOAT-44821 — renovación pendiente',
  'contrato_renovacion_octubre.pdf',
  'Caso CRM-10294 derivado a backoffice',
  'Cuota mensual propuesta: 680',
  'Lista de documentos: boleta, recibo, copia simple',
  'Ticket CRED-77310 — falta tercera boleta',
  'simulacion_24meses.pdf',
  'Agendar devolución de llamada 4:30 p.m.',
  'Observación: prefiere contacto por la tarde',
  'reporte_cierre_turno.xlsx',
];

/* Lima Metropolitana, en la caja que pide la especificación. */
const MON_CAJA = {latMin:-12.20, latMax:-11.90, lonMin:-77.15, lonMax:-76.90};

const EQUIPOS = (() => {
  const r = sembrarMon(20261022);
  const out = [];

  /* Las excepciones se reparten a mano para que los conteos cierren exactos:
     2 fuera de línea, 3 sin speedtest, 2 con WebRTC caído, 4 inactivos por
     más de 15 minutos y 1 con latencia sobre 200 ms. */
  const FUERA     = [11, 19];                 // índices sin conexión
  const SIN_SPEED = [2, 7, 14];
  const WEBRTC    = [5, 16];
  const INACTIVOS = [3, 8, 12, 21];
  const LENTO     = 17;

  MON_PERSONAS.forEach(([ape, nom], i) => {
    const enLinea = !FUERA.includes(i);
    const lat = MON_CAJA.latMin + r() * (MON_CAJA.latMax - MON_CAJA.latMin);
    const lon = MON_CAJA.lonMin + r() * (MON_CAJA.lonMax - MON_CAJA.lonMin);

    const nProc = 5 + Math.floor(r() * 6);          // entre 5 y 10 procesos
    const procesos = MON_PROCESOS.slice(0, nProc).map(([nombre, que], j) => ({
      nombre, que,
      pid: 1200 + Math.floor(r() * 7800),
      cpu: +(r() * (j === 0 ? 14 : 5)).toFixed(1),
      ram_mb: 60 + Math.floor(r() * (j === 0 ? 700 : 260)),
      abierto: (1 + Math.floor(r() * 7)) + 'h ' + Math.floor(r() * 59) + 'm',
    }));

    const nClip = 3 + Math.floor(r() * 3);
    const portapapeles = Array.from({length:nClip}, (_, j) => ({
      hora: String(13 + Math.floor(r() * 2)).padStart(2, '0') + ':' +
            String(Math.floor(r() * 60)).padStart(2, '0'),
      texto: MON_PORTAPAPELES[(i * 3 + j) % MON_PORTAPAPELES.length],
    })).sort((a, b) => b.hora.localeCompare(a.hora));

    out.push({
      id: 'eq-' + String(i + 1).padStart(3, '0'),
      nombre: ape + '_' + nom,
      persona: nom.charAt(0) + nom.slice(1).toLowerCase() + ' ' +
               ape.charAt(0) + ape.slice(1).toLowerCase(),
      host: 'DESKTOP-' + (
        'ABCDEFGHJKMNPQRSTUVWXYZ'[Math.floor(r() * 23)] +
        String(Math.floor(r() * 9) + 1) +
        'ABCDEFGHJKMNPQRSTUVWXYZ'[Math.floor(r() * 23)] +
        String(Math.floor(r() * 9) + 1) +
        'ABCDEFGHJKMNPQRSTUVWXYZ'[Math.floor(r() * 23)] +
        String(Math.floor(r() * 9) + 1) +
        'ABCDEFGHJKMNPQRSTUVWXYZ'[Math.floor(r() * 23)]),
      usuario: 'operador' + String(i + 1).padStart(2, '0'),
      estado: enLinea ? 'online' : 'offline',
      latencia_ms: !enLinea ? null : (i === LENTO ? 214 : 18 + Math.floor(r() * 70)),
      campana: MON_CAMPANAS[i % MON_CAMPANAS.length],
      tags: [MON_CAMPANAS[i % MON_CAMPANAS.length]]
        .concat(SIN_SPEED.includes(i) ? ['SIN DATOS'] : [])
        .concat(i === LENTO ? ['RED LENTA'] : []),
      visto_hace_s: enLinea ? 2 + Math.floor(r() * 25) : 1800 + Math.floor(r() * 9000),
      ip_local: '192.168.40.' + (11 + i),
      ip_publica: '203.0.113.' + (20 + i * 3),
      gps: {lat:+lat.toFixed(4), lon:+lon.toFixed(4)},
      speedtest: SIN_SPEED.includes(i) ? null
        : {bajada:+(9 + r() * 42).toFixed(1), subida:+(3 + r() * 14).toFixed(1)},
      sesion: !enLinea ? 'Desconectado'
        : WEBRTC.includes(i) ? 'WebRTC desconectado' : 'Conectado',
      pantalla: i % 6,                      // cuál de los seis escritorios dibuja
      inactividad_s: INACTIVOS.includes(i) ? 900 + Math.floor(r() * 2400)
        : Math.floor(r() * 420),
      version: '2.4.' + (7 + (i % 3)),
      procesos,
      portapapeles,
    });
  });
  return out;
})();

const MON_TOTAL   = EQUIPOS.length;
const MON_EN_LINEA = EQUIPOS.filter(e => e.estado === 'online').length;

/* Qué lleva sello y qué no, en un solo sitio, para que no haya dos
   versiones de la verdad en la pantalla. Lo que no lleva sello se muestra
   funcionando, sin explicar con qué se resuelve por dentro: al gerente le
   interesa qué hace el sistema, no cómo está armado. */
const MON_SELLOS = {
  lista:     null,
  buscador:  null,
  mapa:      'PRÓXIMAMENTE',
  pantalla:  null,
  mosaico:   null,
  control:   null,
  procesos:  'PRÓXIMAMENTE',
  clip:      'PRÓXIMAMENTE',
  inactivo:  'PRÓXIMAMENTE',
};
