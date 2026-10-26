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

// --- el piso: agentes, su manana y lo que hablan ahora --------------------
// Se leen tablas del discador de solo lectura, sin tocar Dialer.php, porque
// el motor no publica el estado del piso: agentPanelState solo se sirve a si
// mismo. Autorizado para esta pantalla; ver ADR-0028.
$AGENTES = [];
$PISTAS = [];
$EN_CURSO = [];
$PAUSAS_HOY = [];
$PAUSAS_TIPO = [];
$idsCampana = array_map(function ($c) { return (int) $c['id']; }, $CAMPANAS);
if ($idsCampana) {
    try {
        $db = \Includes\Database::getCoreInstance();
        $marcas = implode(',', array_fill(0, count($idsCampana), '?'));
        $hoy = date('Y-m-d');
        $minutos = function ($fecha) {
            $t = strtotime((string) $fecha);
            return $t ? ((int) date('G', $t)) * 60 + (int) date('i', $t) : null;
        };

        // Quien puede trabajar en estas campanas, y en cual esta ahora.
        // Las tablas del discador guardan el login en utf8mb4_general_ci y
        // v2_zynervox_users en utf8mb4_unicode_ci. Sin COLLATE explicito el
        // JOIN muere con "1267 Illegal mix of collations".
        $stmt = $db->prepare("SELECT a.agent_user, a.campaign_id, u.full_name
            FROM v2_dialer_agent_campaigns a
            JOIN v2_zynervox_users u
              ON u.user = a.agent_user COLLATE utf8mb4_unicode_ci AND u.active = 'Y'
            WHERE a.campaign_id IN ($marcas) ORDER BY u.full_name, a.campaign_id");
        $stmt->execute($idsCampana);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fila) {
            $id = (string) $fila['agent_user'];
            if (!isset($AGENTES[$id])) {
                $AGENTES[$id] = ['id' => $id, 'nombre' => (string) $fila['full_name'], 'camps' => [],
                    'activa' => null, 'estado' => 'off', 'llam' => 0, 'cont' => 0, 'vent' => 0,
                    'monto' => 0, 'ocup' => 0, 'login' => '', 'pausaMin' => 0, 'punt' => 'ok'];
            }
            $AGENTES[$id]['camps'][] = (string) ((int) $fila['campaign_id']);
        }

        if ($AGENTES) {
            $logins = array_keys($AGENTES);
            $marcasAg = implode(',', array_fill(0, count($logins), '?'));

            // La manana: un bloque por intervalo observado.
            $stmt = $db->prepare("SELECT agent_user, campaign_id, state, started_at,
                    COALESCE(ended_at, last_seen_at) AS hasta, ended_at
                FROM v2_dialer_agent_intervals
                WHERE agent_user IN ($marcasAg) AND DATE(started_at) = ? ORDER BY started_at");
            $stmt->execute(array_merge($logins, [$hoy]));
            $mapaEstado = ['ACTIVE' => 'call', 'WRAPUP' => 'wrap', 'PAUSED' => 'pausa'];
            $segundosActivo = [];
            $segundosTotal = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fila) {
                $id = (string) $fila['agent_user'];
                if (!isset($AGENTES[$id])) continue;
                $desde = $minutos($fila['started_at']);
                $hasta = $minutos($fila['hasta']);
                if ($desde === null || $hasta === null) continue;
                $tipo = $mapaEstado[(string) $fila['state']] ?? 'espera';
                $PISTAS[$id][] = ['t' => $tipo, 'a' => $desde, 'd' => max(1, $hasta - $desde),
                    'q' => (string) $fila['state']];
                $largo = max(0, $hasta - $desde) * 60;
                $segundosTotal[$id] = ($segundosTotal[$id] ?? 0) + $largo;
                if ($fila['state'] !== 'PAUSED') $segundosActivo[$id] = ($segundosActivo[$id] ?? 0) + $largo;
                if ($AGENTES[$id]['login'] === '') $AGENTES[$id]['login'] = substr((string) $fila['started_at'], 11, 5);
                // El intervalo sin cerrar es el estado de ahora mismo.
                if ($fila['ended_at'] === null) {
                    $AGENTES[$id]['estado'] = $tipo;
                    $AGENTES[$id]['activa'] = (string) ((int) $fila['campaign_id']);
                    if ($tipo === 'pausa') $AGENTES[$id]['desde'] = $desde;
                }
            }
            foreach ($AGENTES as $id => $_) {
                $AGENTES[$id]['ocup'] = !empty($segundosTotal[$id])
                    ? (int) round(($segundosActivo[$id] ?? 0) / $segundosTotal[$id] * 100) : 0;
            }

            // Produccion del dia, por agente.
            $stmt = $db->prepare("SELECT l.agent_user, COUNT(*) llam,
                    SUM(d.is_contact) cont, SUM(d.is_sale) vent
                FROM v2_dialer_call_log l
                LEFT JOIN v2_dialer_dispositions d
                       ON d.campaign_id = l.campaign_id AND d.code = l.disposition_code
                WHERE l.agent_user IN ($marcasAg) AND DATE(l.originated_at) = ?
                GROUP BY l.agent_user");
            $stmt->execute(array_merge($logins, [$hoy]));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fila) {
                $id = (string) $fila['agent_user'];
                if (!isset($AGENTES[$id])) continue;
                $AGENTES[$id]['llam'] = (int) $fila['llam'];
                $AGENTES[$id]['cont'] = (int) $fila['cont'];
                $AGENTES[$id]['vent'] = (int) $fila['vent'];
            }

            // Pausas de hoy, repartidas por motivo y alineadas al catalogo.
            $tiposPausa = $db->query('SELECT pause_type_id, label FROM v2_dialer_pause_types WHERE active=1 ORDER BY pause_type_id')->fetchAll(\PDO::FETCH_ASSOC);
            // Por etiqueta exacta: strtoupper no normaliza la ñ ni los acentos.
            $topes = ['Refrigerio' => 45, 'Baño' => 10, 'Capacitación' => 60];
            $colores = ['#2563EB', '#7C3AED', '#0891B2', '#D97706', '#16A34A'];
            $indice = [];
            foreach ($tiposPausa as $i => $tp) {
                $indice[(int) $tp['pause_type_id']] = $i;
                $etiqueta = (string) $tp['label'];
                $PAUSAS_TIPO[] = ['t' => $etiqueta, 'min' => 0,
                    'tope' => $topes[$etiqueta] ?? 30,
                    'color' => $colores[$i % count($colores)]];
            }
            if ($tiposPausa) {
                $stmt = $db->prepare("SELECT agent_user, pause_type_id,
                        SUM(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW()))) minutos
                    FROM v2_dialer_agent_pauses
                    WHERE agent_user IN ($marcasAg) AND DATE(started_at) = ?
                    GROUP BY agent_user, pause_type_id");
                $stmt->execute(array_merge($logins, [$hoy]));
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fila) {
                    $id = (string) $fila['agent_user'];
                    $pos = $indice[(int) $fila['pause_type_id']] ?? null;
                    if (!isset($AGENTES[$id]) || $pos === null) continue;
                    if (!isset($PAUSAS_HOY[$id])) $PAUSAS_HOY[$id] = array_fill(0, count($tiposPausa), 0);
                    $PAUSAS_HOY[$id][$pos] = (int) $fila['minutos'];
                }
            }
            foreach ($AGENTES as $id => $agente) {
                if ($agente['estado'] === 'pausa') {
                    $AGENTES[$id]['pausaMin'] = array_sum($PAUSAS_HOY[$id] ?? []);
                    $AGENTES[$id]['motivo'] = 'En pausa';
                }
            }
        }

        // Lo que se esta hablando ahora: el motor deja ended_at en nulo.
        $stmt = $db->prepare("SELECT l.agent_user, l.campaign_id, l.phone_number, l.bridged_at,
                   l.originated_at, d.first_name, d.last_name
            FROM v2_dialer_call_log l
            LEFT JOIN v2_dialer_leads d ON d.lead_id = l.lead_id
            WHERE l.campaign_id IN ($marcas) AND l.ended_at IS NULL
            ORDER BY l.originated_at");
        $stmt->execute($idsCampana);
        $ahora = time();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fila) {
            $arranque = strtotime((string) ($fila['bridged_at'] ?: $fila['originated_at']));
            $nombre = trim(((string) $fila['first_name']) . ' ' . ((string) $fila['last_name']));
            $EN_CURSO[] = [
                'ag' => (string) ($fila['agent_user'] ?? ''),
                'cliente' => $nombre !== '' ? $nombre : (string) $fila['phone_number'],
                'tel' => (string) $fila['phone_number'],
                'seg' => $arranque ? max(0, $ahora - $arranque) : 0,
                'camp' => (string) ((int) $fila['campaign_id']),
                'etapa' => $fila['bridged_at'] ? 'En conversación' : 'Timbrando',
                'cola' => $fila['agent_user'] === null,
            ];
            if (isset($AGENTES[(string) $fila['agent_user']]) && $fila['bridged_at']) {
                $AGENTES[(string) $fila['agent_user']]['estado'] = 'call';
            }
        }
        $AGENTES = array_values($AGENTES);
    } catch (\Throwable $exception) {
        $AGENTES = []; $PISTAS = []; $EN_CURSO = []; $PAUSAS_HOY = []; $PAUSAS_TIPO = [];
    }
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
/* Salen de v2_dialer_agent_campaigns, v2_dialer_agent_intervals y del
   call_log del dia. El estado de ahora es el intervalo sin cerrar. */
const AGENTES = <?php echo supJson($AGENTES); ?>;
const PISTAS = <?php echo supJson((object) $PISTAS); ?>;
const EN_CURSO = <?php echo supJson($EN_CURSO); ?>;
/* Las alertas se deducirian de umbrales; todavia no se calculan. */
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
/* Catalogo real de v2_dialer_pause_types; los topes todavia no viven en la
   base, se declaran aca hasta que exista la columna. */
const PAUSAS_TIPO = <?php echo supJson($PAUSAS_TIPO); ?>;
const PAUSAS_HOY = <?php echo supJson((object) $PAUSAS_HOY); ?>;
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
