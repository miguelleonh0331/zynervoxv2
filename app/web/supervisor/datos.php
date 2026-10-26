<?php
// Reemplaza al datos.js inventado de la maqueta. Emite exactamente los mismos
// nombres globales, para que sup.js y sup.css queden iguales y la pantalla se
// vea igual. Lo que el motor ya calcula llega con cifras; lo que todavia no
// existe llega vacio y su cuadro queda en blanco.
//
// Se conservan tal cual los catalogos de la maqueta (ESTADOS, CAMPOS_SEG,
// PAUSAS_TIPO, CRITERIOS, ejes del mapa de calor, MON_*): son configuracion
// de la pantalla, no datos del piso.
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/CoreUsers.php';
require_once __DIR__ . '/../includes/Dialer.php';

use Includes\Auth;
use Includes\CoreUsers;
use Includes\Dialer;

Auth::checkAccess([7, 8, 9]);
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store');

$level = (int) ($_SESSION['user_level'] ?? 0);
$user = (string) ($_SESSION['user'] ?? '');
$scoped = in_array($level, CoreUsers::scopedLevels(), true);

// --- alcance: el 9 ve todo; 7 y 8, solo sus campanas -----------------------
$allowed = null;
if ($scoped) {
    $allowed = [];
    foreach (CoreUsers::campaignsFor($user) as $key) {
        if (strpos($key, 'agents:') === 0) $allowed[] = (int) substr($key, 7);
    }
}

$campaigns = [];
try {
    foreach (Dialer::campaigns() as $row) {
        $id = (int) $row['campaign_id'];
        if ($allowed !== null && !in_array($id, $allowed, true)) continue;
        $campaigns[] = $row;
    }
} catch (\Throwable $exception) { $campaigns = []; }

// --- campanas: forma 'out' de la maqueta ----------------------------------
$CAMPANAS = [];
$kpis = null;
$breakdown = null;
foreach ($campaigns as $row) {
    $id = (int) $row['campaign_id'];
    $k = null;
    try { $k = Dialer::kpis($id); } catch (\Throwable $exception) { $k = null; }
    if ($kpis === null && $k !== null) {
        $kpis = $k;
        try { $breakdown = Dialer::reportBreakdown($id); } catch (\Throwable $exception) { $breakdown = null; }
    }
    $CAMPANAS[] = [
        'id' => (string) $id,
        'nombre' => (string) $row['name'],
        'tipo' => 'out',
        'estado' => strtoupper((string) ($row['engine_state'] ?? '')) === 'RUNNING' ? 'on' : 'off',
        'nivel' => (float) ($row['dial_level'] ?? 1),
        'leads' => (int) ($k['placed'] ?? 0),
        'pend' => 0,
        'hechas' => (int) ($k['closed'] ?? 0),
        'cont' => (int) ($k['contacted'] ?? 0),
        'vent' => (int) ($k['sales'] ?? 0),
        'abandono' => (float) ($k['abandon_pct'] ?? 0),
        'canales' => (int) ($row['max_concurrent_calls'] ?? 0),
    ];
}

// --- los veinte indicadores del motor -------------------------------------
$numero = function ($value) { return number_format((float) $value, 0, ',', ','); };
$porcentaje = function ($value) {
    return $value === null ? 'N/D' : number_format((float) $value, 1, ',', '') . ' %';
};
$duracion = function ($seconds) {
    $seconds = (int) round((float) $seconds);
    return $seconds >= 60 ? intdiv($seconds, 60) . ' m ' . ($seconds % 60) . ' s' : $seconds . ' s';
};
$KPIS_MOTOR = [];
if ($kpis !== null) {
    $cobertura = ['observed' => 'Observada', 'partial' => 'Parcial', 'none' => 'Sin datos', 'list_unavailable' => 'No aplica'];
    $KPIS_MOTOR = [
        ['t' => 'Marcadas', 'v' => $numero($kpis['placed'] ?? 0)],
        ['t' => 'Teléfonos únicos', 'v' => $numero($kpis['unique_phones'] ?? 0)],
        ['t' => 'Contestadas', 'v' => $numero($kpis['answered_total'] ?? 0)],
        ['t' => 'Contestó una persona', 'v' => $numero($kpis['answered_human'] ?? 0)],
        ['t' => 'Contestador', 'v' => $numero($kpis['answered_machine'] ?? 0)],
        ['t' => 'Colgadas por el motor', 'v' => $numero($kpis['dropped'] ?? 0)],
        ['t' => 'Contactados', 'v' => $numero($kpis['contacted'] ?? 0)],
        ['t' => 'Ventas', 'v' => $numero($kpis['sales'] ?? 0)],
        ['t' => 'En curso', 'v' => $numero($kpis['in_progress'] ?? 0)],
        ['t' => 'Sin tipificar', 'v' => $numero($kpis['closed_unclassified'] ?? 0)],
        ['t' => 'Contactabilidad', 'v' => $porcentaje($kpis['contact_pct'] ?? null)],
        ['t' => 'Conversión', 'v' => $porcentaje($kpis['conversion_pct'] ?? null)],
        ['t' => 'ASR', 'v' => $porcentaje($kpis['asr_pct'] ?? null)],
        ['t' => 'Abandono', 'v' => $porcentaje($kpis['abandon_pct'] ?? null)],
        ['t' => '% contestador', 'v' => $porcentaje($kpis['machine_pct'] ?? null)],
        ['t' => 'Espera promedio', 'v' => number_format((float) ($kpis['avg_wait_seconds'] ?? 0), 1, ',', '') . ' s'],
        ['t' => 'AHT', 'v' => $duracion($kpis['aht_seconds'] ?? 0)],
        ['t' => 'Ocupación', 'v' => $porcentaje($kpis['occupancy_pct'] ?? null)],
        ['t' => 'Nivel de marcado', 'v' => number_format((float) ($CAMPANAS[0]['nivel'] ?? 0), 1, ',', '')],
        ['t' => 'Cobertura del dato', 'v' => $cobertura[(string) ($kpis['occupancy_coverage'] ?? '')] ?? '—'],
    ];
}

// --- embudo: lo que el motor sabe. Conversaciones y cotizaciones no existen.
$EMBUDO = [];
if ($kpis !== null) {
    $EMBUDO = [
        ['t' => 'Leads asignados', 'n' => (int) ($kpis['unique_phones'] ?? 0), 'c' => '#1D4ED8'],
        ['t' => 'Contactados', 'n' => (int) ($kpis['contacted'] ?? 0), 'c' => '#2563EB'],
        ['t' => 'Ventas', 'n' => (int) ($kpis['sales'] ?? 0), 'c' => '#16A34A'],
    ];
}

// --- tipificaciones: catalogo real con su conteo del periodo ---------------
$TIPIFICACIONES = [];
if ($CAMPANAS) {
    try {
        $counts = [];
        foreach ((array) ($breakdown['dispositions'] ?? []) as $item) {
            $counts[(string) ($item['disposition_code'] ?? '')] = (int) ($item['total'] ?? 0);
        }
        foreach (Dialer::dispositions((int) $CAMPANAS[0]['id']) as $item) {
            $TIPIFICACIONES[] = [
                'c' => (string) $item['code'],
                't' => (string) $item['label'],
                'cont' => (bool) $item['is_contact'],
                'venta' => (bool) $item['is_sale'],
                'reint' => (bool) $item['retryable'],
                'horas' => (int) round(((int) $item['retry_delay_minutes']) / 60),
                'n' => $counts[(string) $item['code']] ?? 0,
            ];
        }
    } catch (\Throwable $exception) { $TIPIFICACIONES = []; }
}

// --- grabaciones: existen de verdad; el puntaje de calidad no --------------
$GRABACIONES = [];
if ($CAMPANAS) {
    try {
        foreach (Dialer::recordings((int) $CAMPANAS[0]['id'], date('Y-m-d'), date('Y-m-d')) as $item) {
            if (empty($item['available'])) continue;
            $GRABACIONES[] = [
                'id' => (string) ($item['call_id'] ?? ''),
                'ag' => (string) ($item['agent_user'] ?? ''),
                'cli' => (string) ($item['phone_number'] ?? ''),
                'dur' => intdiv((int) ($item['duration_talk'] ?? 0), 60) . ':' . str_pad((string) (((int) ($item['duration_talk'] ?? 0)) % 60), 2, '0', STR_PAD_LEFT),
                'h' => substr((string) ($item['originated_at'] ?? ''), 11, 5),
                'score' => null,
                'tip' => (string) ($item['disposition_code'] ?? ''),
                'rev' => false,
            ];
        }
    } catch (\Throwable $exception) { $GRABACIONES = []; }
}

// --- turno del dia --------------------------------------------------------
$dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$HOY = [
    'fecha' => $dias[(int) date('w')] . ' ' . (int) date('j') . ' de ' . $meses[(int) date('n')] . ', ' . date('Y'),
    'ini' => 8 * 60,
    'fin' => 17 * 60,
    'ahora' => ((int) date('G')) * 60 + (int) date('i'),
];

function supJson($value) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
?>
/* Generado por supervisor/datos.php. No editar a mano. */

/* ------------------------------------------------- turno y utilidades --- */
const HOY = <?php echo supJson($HOY); ?>;
const reloj = m => String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0');

/* --------------------------------------------------------------- piso --- */
/* El motor guarda los intervalos de agente en v2_dialer_agent_intervals,
   pero no hay forma publica de leerlos: el panel del agente solo se sirve a
   si mismo. Hasta que exista, el piso llega vacio. */
const AGENTES = [];
const PISTAS = {};
const EN_CURSO = [];
const ALERTAS = [];

const porId = id => AGENTES.find(a => a.id === id);
const ESTADOS = {
  call:  {t:'En llamada',  c:'ok'},
  pausa: {t:'En pausa',    c:'warn'},
  libre: {t:'Esperando',   c:'info'},
  wrap:  {t:'Tipificando', c:'info'},
  off:   {t:'Sin conectar',c:'mute'},
};

/* ----------------------------------------------------------- campanas --- */
const CAMPANAS = <?php echo supJson($CAMPANAS); ?>;
const campPorId = id => CAMPANAS.find(c => c.id === id);
const nombreCamp = id => (campPorId(id) || {}).nombre || id;
const agentesDe = id => AGENTES.filter(a => (a.camps || []).includes(id));
const esMixto = a => {
  const cs = (a && a.camps) || [];
  return cs.some(c => (campPorId(c) || {}).tipo === 'out')
      && cs.some(c => (campPorId(c) || {}).tipo === 'in');
};
/* El discador no tiene direccion de campana, solo queue_name: no hay
   entrantes ni cola de entrada que mostrar. */
const COLA_IN = [];

/* ------------------------------------------------------------ clientes -- */
/* El expediente unificado no existe como entidad en la base. */
const CLIENTES = [];
const HISTORIAS = {};
const WA = {};
const BANDEJA = [];

/* ---------------------------------------------------- carga de base ----- */
const ARCHIVO = {nombre:'—', peso:'—', filas:0, columnas:[], validacion:[], normalizados:0};
const CARGAS = [];

/* ------------------------------------------------------ segmentacion ---- */
const CAMPOS_SEG = [
  {k:'tip',      t:'Tipificación',   tipo:'lista'},
  {k:'intentos', t:'Intentos',       tipo:'num'},
  {k:'ultima',   t:'Última llamada', tipo:'horas'},
  {k:'monto',    t:'Monto',          tipo:'num'},
  {k:'ciudad',   t:'Ciudad',         tipo:'lista'},
  {k:'producto', t:'Producto',       tipo:'lista'},
  {k:'origen',   t:'Origen',         tipo:'lista'},
];
const TIPIFICACIONES = <?php echo supJson($TIPIFICACIONES); ?>;
const SEG_GUARDADOS = [];
const CALOR_H = ['8','9','10','11','12','13','14','15','16','17','18'];
const CALOR_D = ['Lun','Mar','Mié','Jue','Vie','Sáb'];
/* La contactabilidad por dia y hora no se calcula todavia. */
const CALOR = [];
const SALUD_LISTAS = [];

/* ---------------------------------------------------------- calidad ----- */
/* Las grabaciones son reales. El puntaje no existe: no hay tabla de scoring,
   por eso score llega nulo y la columna queda en blanco. */
const GRABACIONES = <?php echo supJson($GRABACIONES); ?>;
const CRITERIOS = [];

/* ------------------------------------------------------------ equipo ---- */
const SEMANA = [];
const ASISTENCIA = {};
const PAUSAS_TIPO = [
  {t:'Refrigerio',   min:0, tope:45, color:'#2563EB'},
  {t:'Baño',         min:0, tope:10, color:'#7C3AED'},
  {t:'Capacitación', min:0, tope:60, color:'#0891B2'},
];
const PAUSAS_HOY = {};
const COACHING = {};

/* ----------------------------------------------------- comunicacion ----- */
/* No hay canal entre el supervisor y el agente. */
const BROADCASTS = [];
const CHAT = {};
const MANOS = [];

/* ---------------------------------------------------------- reportes ---- */
const META_DIA = 0;
const CURVA = [];
const REAL_AHORA = 0;
const EMBUDO = <?php echo supJson($EMBUDO); ?>;
/* El producto y el monto viven en leads.custom_fields, sin consumidor. */
const PRODUCTOS = [];
const KPIS_MOTOR = <?php echo supJson($KPIS_MOTOR); ?>;

/* -------------------------------------------------- monitoreo remoto ---- */
/* Zynerdesk tiene backend, pero falta el agente Windows. */
const MON_CAMPANAS = [];
const MON_PROCESOS = [];
const MON_CAJA = {latMin:-12.20, latMax:-11.90, lonMin:-77.15, lonMax:-76.90};
const EQUIPOS = [];
const MON_TOTAL = 0;
const MON_EN_LINEA = 0;
const MON_SELLOS = {lista:null, buscador:null, mapa:null, pantalla:null, mosaico:null,
  control:null, procesos:null, clip:null, inactivo:null};
