<?php
// Consola del agente: entorno autónomo. Todo lo que necesita vive en
// modules/agc/ (login, salida, recursos y puente de WhatsApp); nunca envía al
// agente al panel de administración.
//
// La misma URL resuelve las dos situaciones: sin sesión pide credenciales,
// con sesión abre la consola y conecta al discador.
declare(strict_types=1);
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;

if (session_status() === PHP_SESSION_NONE) session_start();

function crm_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$errorLogin = '';

// --- Acceso -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $usuarioEnviado = (string)($_POST['username'] ?? '');
    $claveEnviada = (string)($_POST['password'] ?? '');
    if ($usuarioEnviado === '' || $claveEnviada === '') {
        $errorLogin = 'Escribe usuario y contraseña.';
    } elseif (Auth::login($usuarioEnviado, $claveEnviada)) {
        // Identificador nuevo tras autenticar, y se vuelve por GET para que
        // recargar no reenvíe las credenciales.
        session_regenerate_id(true);
        header('Location: crm.php', true, 303);
        exit;
    } else {
        $errorLogin = 'Usuario o contraseña incorrectos.';
        usleep(300000);
    }
}

// En despliegue aislado la cuenta se revalida contra la base en cada carga,
// igual que hace Auth::checkAccess, para que una cuenta desactivada pierda el
// acceso sin esperar a que caduque la sesión.
$autenticado = isset($_SESSION['user']);
if ($autenticado && \Config\Config::deployment('isolated', false)) {
    try {
        $stmt = \Includes\Database::getCoreInstance()->prepare(
            'SELECT full_name,user_level FROM ' . \Config\Config::coreTable('users') . ' WHERE user=? AND active=\'Y\''
        );
        $stmt->execute([$_SESSION['user']]);
        $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cuenta) {
            $_SESSION = [];
            session_destroy();
            $autenticado = false;
            $errorLogin = 'Tu cuenta ya no está activa.';
        } else {
            $_SESSION['user_level'] = (int)$cuenta['user_level'];
            $_SESSION['full_name'] = $cuenta['full_name'];
        }
    } catch (Throwable $e) {
        http_response_code(503);
        exit('No se pudo verificar la cuenta.');
    }
}

if (!$autenticado) {
    http_response_code($errorLogin !== '' ? 401 : 200);
    include __DIR__ . '/login_view.php';
    exit;
}

$usuario = (string)($_SESSION['user'] ?? '');
if (empty($_SESSION['dialer_csrf'])) $_SESSION['dialer_csrf'] = bin2hex(random_bytes(32));

// Conexión al discador. Es la misma acción "bootstrap" de
// modules/admin/dialer_agent.php: Dialer::agentLogin valida que el agente
// tenga anexo SIP activo, lo marca conectado y lo suma a las colas de sus
// campañas activas. La consola la invoca al abrir y muestra el resultado.
//
// Las demás acciones son las mismas de modules/admin/dialer_agent.php
// (marcar, pausar, reanudar, colgar), expuestas como JSON porque esta consola
// se repinta sola en vez de recargar la página.

/**
 * Lo que cambia solo y hay que releer: llamada en curso, pausa, tipificación
 * pendiente y autodial. Es lo único que viaja en cada sondeo.
 */
function agc_volatil(string $u): array {
    $pausa = Dialer::agentPause($u);
    $lead = Dialer::agentCurrentLead($u);
    $autodial = Dialer::agentAutodialState($u);
    $pendiente = Dialer::pendingDispositionForAgent($u);

    // Los códigos de cierre sólo hacen falta cuando hay algo que tipificar.
    $codigos = [];
    if ($lead) {
        foreach (Dialer::dispositions((int)$lead['campaign_id']) as $d) {
            $codigos[] = ['code' => (string)$d['code'], 'label' => (string)$d['label']];
        }
    }

    return [
        'pause' => $pausa ? ['label' => (string)$pausa['label'], 'seconds' => (int)$pausa['duration_seconds']] : null,
        'lead' => $lead ? [
            'call_id' => (int)$lead['call_id'],
            'phone' => (string)($lead['phone_number'] ?? ''),
            'campaign' => (string)($lead['campaign_name'] ?? ''),
            'campaign_id' => (int)$lead['campaign_id'],
            'name' => trim((string)($lead['first_name'] ?? '') . ' ' . (string)($lead['last_name'] ?? '')),
            'at' => (string)($lead['originated_at'] ?? ''),
            'ended' => $lead['ended_at'] !== null,
            'bridged' => $lead['bridged_at'] !== null,
            'disposition' => $lead['disposition_code'],
            'status' => (string)($lead['status'] ?? ''),
            'variables' => is_array($lead['variables'] ?? null) ? $lead['variables'] : [],
        ] : null,
        'dispositions' => $codigos,
        'pending' => $pendiente ? [
            'call_id' => (int)$pendiente['call_id'],
            'phone' => (string)($pendiente['phone_number'] ?? ''),
            'campaign' => (string)($pendiente['campaign_name'] ?? ''),
        ] : null,
        'autodial' => [
            'enabled' => (bool)($autodial['enabled'] ?? false),
            'waiting' => (bool)($autodial['pending'] ?? false),
            'seconds' => $autodial['seconds_until'] === null ? null : (int)$autodial['seconds_until'],
            'running' => (bool)($autodial['running'] ?? false),
        ],
        'selectedCampaign' => (int)($autodial['selected_campaign_id'] ?? 0),
    ];
}

/**
 * Lo que no cambia durante el turno: campañas del agente y motivos de pausa.
 * Viaja sólo al conectar, para no repetirlo en cada sondeo.
 */
function agc_fijo(string $u): array {
    $manuales = [];
    $arboles = [];
    foreach (Dialer::campaignsForAgent($u) as $c) {
        if (!$c['active'] || $c['dial_method'] !== 'MANUAL') continue;
        $id = (int)$c['campaign_id'];
        $manuales[] = ['id' => $id, 'name' => (string)$c['name']];

        // Árbol de tipificación de la campaña. Las hojas y los nodos
        // intermedios llegan planos; el nivel y el padre salen del código,
        // así que el navegador reconstruye la cascada sin más consultas.
        $nodos = [];
        foreach (Dialer::dispositions($id) as $d) {
            $code = (string)$d['code'];
            $nivel = Dialer::dispositionLevel($code);
            if ($nivel === 0) continue;   // códigos antiguos, no jerárquicos
            $nodos[] = [
                'code' => $code,
                'label' => (string)$d['label'],
                'level' => $nivel,
                'parent' => Dialer::dispositionParent($code),
                'sale' => (bool)$d['is_sale'],
                'retry' => (bool)$d['retryable'],
            ];
        }
        $arboles[(string)$id] = $nodos;
    }
    $motivos = [];
    foreach (Dialer::pauseTypes() as $t) {
        if ($t['active']) $motivos[] = ['id' => (int)$t['pause_type_id'], 'label' => (string)$t['label']];
    }
    return ['campaigns' => $manuales, 'pauseTypes' => $motivos, 'trees' => $arboles];
}

/**
 * Llamadas que el agente ya trabajó, para poblar la cartera al abrir. Una
 * sola consulta por carga de página: después la lista se mantiene sola con el
 * lead que ya viaja en cada sondeo.
 *
 * En llamadas MANUAL `agent_user` queda NULL y el agente real está en
 * `reserved_agent_user` / `disposition_agent_user`, de ahí el OR.
 */
function agc_historial(string $u, int $limite = 50): array {
    $sql = 'SELECT l.call_id,l.campaign_id,l.phone_number,l.originated_at,l.ended_at,l.disposition_code,
                   c.name campaign_name,d.first_name,d.last_name,d.status
            FROM v2_dialer_call_log l
            JOIN v2_dialer_campaigns c ON c.campaign_id=l.campaign_id
            LEFT JOIN v2_dialer_leads d ON d.lead_id=l.lead_id
            WHERE (l.reserved_agent_user=:u1 OR l.disposition_agent_user=:u2 OR l.agent_user=:u3)
              AND l.originated_at >= CURDATE()
            ORDER BY l.call_id DESC LIMIT ' . max(1, min(200, $limite));
    $q = \Includes\Database::getCoreInstance()->prepare($sql);
    $q->execute(['u1' => $u, 'u2' => $u, 'u3' => $u]);
    $filas = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $filas[] = [
            'call_id' => (int)$r['call_id'],
            'campaign_id' => (int)$r['campaign_id'],
            'campaign' => (string)$r['campaign_name'],
            'phone' => (string)$r['phone_number'],
            'name' => trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')),
            'at' => (string)$r['originated_at'],
            'ended' => $r['ended_at'] !== null,
            'disposition' => $r['disposition_code'],
            'status' => (string)($r['status'] ?? ''),
        ];
    }
    return $filas;
}

/**
 * Deja la tipificación en el propio lead, dentro de `custom_fields`.
 *
 * Ese JSON ya trae los datos de origen de la base cargada (id_contacto,
 * nombre, monto, local, direccion), así que se escriben sólo claves propias
 * con prefijo `tip_`. Se usa JSON_SET en una única sentencia: evita el
 * leer-modificar-escribir y, con él, el riesgo de pisar lo que otro proceso
 * haya guardado entretanto.
 *
 * Es deliberadamente la última tipificación: el historial por llamada vive en
 * v2_dialer_call_log, y las tablas de tipificaciones y gestiones llegarán con
 * el software de gestión que se conectará más adelante.
 */
function agc_tipificar_lead(int $callId, string $agente, string $codigo, string $n1, string $n2, string $n3, string $nota): bool {
    $db = \Includes\Database::getCoreInstance();

    $q = $db->prepare('SELECT lead_id FROM v2_dialer_call_log WHERE call_id=?');
    $q->execute([$callId]);
    $leadId = $q->fetchColumn();
    if (!$leadId) return false;

    // Recortes defensivos: el campo es compartido y no debe crecer sin límite.
    $corta = static function (string $v, int $max) {
        $v = preg_replace('/[\x00-\x1f]/u', ' ', trim($v));
        return mb_substr((string)$v, 0, $max, 'UTF-8');
    };

    $sql = 'UPDATE v2_dialer_leads
            SET custom_fields = JSON_SET(
                  CASE WHEN custom_fields IS NULL OR custom_fields = \'\' OR JSON_VALID(custom_fields) = 0
                       THEN \'{}\' ELSE custom_fields END,
                  \'$.tip_nivel1\', :n1,
                  \'$.tip_nivel2\', :n2,
                  \'$.tip_nivel3\', :n3,
                  \'$.tip_nota\',   :nota,
                  \'$.tip_codigo\', :codigo,
                  \'$.tip_agente\', :agente,
                  \'$.tip_llamada\', :llamada,
                  \'$.tip_fecha\',  :fecha)
            WHERE lead_id = :lead';
    $u = $db->prepare($sql);
    $u->execute([
        'n1' => $corta($n1, 80), 'n2' => $corta($n2, 80), 'n3' => $corta($n3, 80),
        'nota' => $corta($nota, 1000), 'codigo' => $corta($codigo, 20),
        'agente' => $corta($agente, 60), 'llamada' => $callId,
        'fecha' => date('Y-m-d H:i:s'), 'lead' => (int)$leadId,
    ]);
    return $u->rowCount() > 0;
}

$accionesJson = ['bootstrap', 'state', 'manual', 'pause', 'unpause', 'hangup', 'dispose'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['action'] ?? '', $accionesJson, true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $accion = (string)$_POST['action'];
    $mensaje = '';
    $fallo = '';

    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialer_csrf'], $_POST['csrf_token'])) {
        $fallo = 'Sesión inválida, recarga la página.';
    } else {
        try {
            switch ($accion) {
                case 'bootstrap':
                    $_SESSION['dialer_entered'] = true;
                    Dialer::agentLogin($usuario);
                    $activas = array_filter(Dialer::campaignsForAgent($usuario), static function ($c) { return (bool)$c['active']; });
                    $mensaje = $activas
                        ? 'Conectado · ' . count($activas) . ' campaña' . (count($activas) === 1 ? '' : 's')
                        : 'Sin campañas activas asignadas';
                    break;

                case 'state':
                    break;   // sólo devuelve el estado de abajo

                case 'manual':
                    $campana = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (!$campana) throw new RuntimeException('Selecciona una campaña.');
                    // Recordar la elección, igual que el panel de agente.
                    Dialer::selectManualCampaign($usuario, $campana);
                    $callId = Dialer::originateNext($campana, $usuario);
                    $mensaje = $callId === null
                        ? 'Sin lead elegible, capacidad o agente disponible; no se marcó.'
                        : 'Intento #' . $callId . ' enviado. Contesta en tu softphone para marcar al cliente.';
                    break;

                case 'pause':
                    $motivo = filter_var($_POST['pause_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (!$motivo) throw new RuntimeException('Selecciona un motivo de pausa.');
                    Dialer::agentPauseAll($usuario, $motivo);
                    $mensaje = 'Pausa aplicada a todas tus campañas.';
                    break;

                case 'unpause':
                    Dialer::agentPauseAll($usuario, null);
                    $mensaje = 'Agente reanudado.';
                    break;

                case 'hangup':
                    Dialer::agentHangup($usuario, (int)($_POST['call_id'] ?? 0));
                    $mensaje = 'Solicitud de colgar enviada.';
                    break;

                case 'dispose':
                    // Cierra el intento en el discador. Es lo que libera el
                    // encadenado: setDisposition() programa la llamada
                    // siguiente si la campaña tiene autodial manual.
                    $llamada = filter_var($_POST['call_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $codigo = (string)($_POST['code'] ?? '');
                    if (!$llamada) throw new RuntimeException('No hay llamada que tipificar.');
                    if ($codigo === '') throw new RuntimeException('Falta el código de tipificación.');
                    Dialer::setDisposition($llamada, $usuario, $codigo);
                    // El discador ya aceptó el cierre; ahora la cascada queda
                    // en el lead. Si esto fallara, la llamada igual está
                    // tipificada: se avisa sin deshacer lo anterior.
                    $guardado = agc_tipificar_lead(
                        $llamada, $usuario, $codigo,
                        (string)($_POST['n1'] ?? ''), (string)($_POST['n2'] ?? ''),
                        (string)($_POST['n3'] ?? ''), (string)($_POST['nota'] ?? '')
                    );
                    $mensaje = $guardado
                        ? 'Llamada tipificada y guardada en el lead.'
                        : 'Llamada tipificada (no se pudo escribir en el lead).';
                    break;
            }
        } catch (Throwable $e) {
            $fallo = $e->getMessage();
        }
    }

    // El estado viaja siempre que se pueda: tras un fallo el navegador debe
    // repintar con la situación real, no con la que suponía. Lo fijo y el
    // historial sólo al conectar: repetirlos en cada sondeo sería gasto puro.
    $respuesta = ['message' => $mensaje, 'error' => $fallo, 'state' => null];
    try {
        $respuesta['state'] = agc_volatil($usuario);
        if ($accion === 'bootstrap') {
            $respuesta['fixed'] = agc_fijo($usuario);
            $respuesta['history'] = agc_historial($usuario);
        }
    } catch (Throwable $e) { /* se informa sin estado */ }

    if ($fallo !== '') http_response_code(503);
    echo json_encode($respuesta, JSON_THROW_ON_ERROR);
    exit;
}

$operador = (string)($_SESSION['full_name'] ?? $_SESSION['user'] ?? 'OPERADOR');
$nivel = (int)($_SESSION['user_level'] ?? 1);
$rol = $nivel >= 8 ? 'Administrador' : ($nivel >= 6 ? 'Supervisor' : 'Asesor de ventas');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2D2F3B">
<title>CRM · Atención Telefónica · Zynervox</title>
<link rel="stylesheet" href="crm.css">
<script src="crm.js" defer></script>
</head>
<body data-crm-user="<?php echo crm_h($operador); ?>" data-crm-rol="<?php echo crm_h($rol); ?>" data-crm-exit="logout.php" data-crm-wa="crm_wa_session.php"
      data-crm-dialer="crm.php" data-crm-csrf="<?php echo crm_h($_SESSION['dialer_csrf']); ?>">

<div id="app">

  <!-- Barra superior: búsqueda global, estado de la llamada e identidad. -->
  <header class="topbar">
    <div class="brand"><i></i> ZYNERVOX <b>CRM</b></div>

    <div class="search">
      <svg viewBox="0 0 24 24" class="ico"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input id="search" type="search" autocomplete="off"
             placeholder="Buscar cliente por nombre, documento o teléfono…">
      <kbd>/</kbd>
    </div>

    <div class="call-state" id="dialerState" data-on="wait" title="Conexión con el discador">
      <span class="dot"></span>
      <span id="dialerLabel">Discador…</span>
    </div>

    <div class="call-state" id="callState" data-on="false">
      <span class="dot"></span>
      <span id="callLabel">Sin llamada</span>
      <b id="callTimer">00:00</b>
    </div>

    <div class="who">
      <span class="avatar avatar--sm" id="whoAv">—</span>
      <span class="who-txt"><b id="opName">—</b><em id="opRole">—</em></span>
    </div>
    <button id="exit" class="topbar-exit" type="button" title="Volver al panel">Salir</button>
  </header>

  <!-- Columna 1: cartera del asesor. -->
  <section class="col col--list">
    <nav class="list-tabs" id="listTabs"></nav>
    <div class="list-body" id="listBody"></div>
    <div class="list-foot"><span id="listCount">—</span></div>
  </section>

  <!-- Columna 2: expediente del cliente. -->
  <section class="col col--detail">
    <header class="det-head" id="detHead"></header>
    <nav class="det-tabs" id="detTabs"></nav>
    <div class="det-body" id="detBody"></div>
  </section>

  <!-- Columna 3: trabajo del asesor sobre la llamada en curso. -->
  <aside class="col col--work">

    <!-- Control de llamada: las funciones del panel de agente (campaña,
         marcar, pausa y colgar), que el JS mantiene al día con el discador. -->
    <div class="block" id="callCtl">
      <h2>Control de llamada <span class="h-count" id="agentState">—</span></h2>
      <div class="block-body block-body--pad">
        <div class="field">
          <label for="ctlCampaign">Campaña para marcar</label>
          <select id="ctlCampaign"><option value="">Sin campañas manuales</option></select>
        </div>
        <button class="btn btn--primary" id="ctlDial" type="button" style="width:100%;height:28px">Marcar siguiente lead</button>

        <div class="field" id="ctlPauseWrap" style="margin-top:8px">
          <label for="ctlPauseType">Motivo de pausa</label>
          <select id="ctlPauseType"><option value="">Selecciona un motivo…</option></select>
        </div>
        <div class="ctl-row">
          <button class="btn-sm" id="ctlPause" type="button">Pausar</button>
          <button class="btn-sm btn-sm--danger" id="ctlHangup" type="button" hidden>Colgar</button>
        </div>

        <div class="note" id="ctlLead" hidden></div>
      </div>
    </div>

    <div class="block block--grow">
      <h2>Tipificar gestión</h2>
      <div class="block-body block-body--pad">

        <div class="field field--step">
          <label for="tip0" data-step="1">Nivel 1 · Resultado</label>
          <select id="tip0"></select>
        </div>
        <div class="field field--step">
          <label for="tip1" data-step="2">Nivel 2 · Motivo</label>
          <select id="tip1"></select>
        </div>
        <div class="field field--step">
          <label for="tip2" data-step="3">Nivel 3 · Desenlace</label>
          <select id="tip2"></select>
        </div>

        <div class="outcome" id="tipOut">Elige los tres niveles para habilitar el registro.</div>
        <div class="note" id="tipPend" hidden></div>
        <button class="btn-sm btn-sm--primary" id="tipPendBtn" type="button" style="width:100%;margin-bottom:8px" hidden></button>

        <div class="field">
          <label for="tipNota">Nota de la gestión</label>
          <textarea id="tipNota" maxlength="2000" placeholder="Observaciones de la llamada…"></textarea>
        </div>
      </div>
      <div class="actions">
        <button class="btn btn--primary btn--wide" id="btnGrabar" type="button" disabled>Grabar gestión</button>
      </div>
    </div>

    <!-- Rendimiento del día, traído de la columna de navegación del modelo. -->
    <div class="perf">
      <h2>Tu performance hoy</h2>
      <div class="perf-body">
        <div class="donut" id="perfDonut"><span id="perfRatio">0/0</span><em>Ventas</em></div>
        <dl class="perf-kpis" id="perfKpis"></dl>
      </div>
    </div>
  </aside>

  <footer class="statusbar">
    <span id="msg">Cargando…</span>
    <span style="margin-left:auto">Maqueta · datos ficticios, sin base de datos</span>
  </footer>

</div>

<div id="layer" hidden></div>

</body>
</html>
