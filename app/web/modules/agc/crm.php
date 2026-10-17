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

/** Estado del puesto de trabajo que el navegador necesita para repintar. */
function agc_estado(string $u): array {
    $manuales = [];
    foreach (Dialer::campaignsForAgent($u) as $c) {
        if ($c['active'] && $c['dial_method'] === 'MANUAL') {
            $manuales[] = ['id' => (int)$c['campaign_id'], 'name' => (string)$c['name']];
        }
    }
    $motivos = [];
    foreach (Dialer::pauseTypes() as $t) {
        if ($t['active']) $motivos[] = ['id' => (int)$t['pause_type_id'], 'label' => (string)$t['label']];
    }
    $pausa = Dialer::agentPause($u);
    $lead = Dialer::agentCurrentLead($u);
    $autodial = Dialer::agentAutodialState($u);
    return [
        'campaigns' => $manuales,
        'pauseTypes' => $motivos,
        'pause' => $pausa ? ['label' => (string)$pausa['label'], 'seconds' => (int)$pausa['duration_seconds']] : null,
        'lead' => $lead ? [
            'call_id' => (int)$lead['call_id'],
            'phone' => (string)($lead['phone_number'] ?? ''),
            'campaign' => (string)($lead['campaign_name'] ?? ''),
            'started' => (string)($lead['originated_at'] ?? ''),
            'ended' => $lead['ended_at'] !== null,
            'disposed' => $lead['disposition_code'] !== null,
        ] : null,
        'selectedCampaign' => (int)($autodial['selected_campaign_id'] ?? 0),
    ];
}

$accionesJson = ['bootstrap', 'state', 'manual', 'pause', 'unpause', 'hangup'];
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
            }
        } catch (Throwable $e) {
            $fallo = $e->getMessage();
        }
    }

    // El estado viaja siempre que se pueda: tras un fallo el navegador debe
    // repintar con la situación real, no con la que suponía.
    $estado = null;
    try { $estado = agc_estado($usuario); } catch (Throwable $e) { /* se informa sin estado */ }

    if ($fallo !== '') http_response_code(503);
    echo json_encode(['message' => $mensaje, 'error' => $fallo, 'state' => $estado], JSON_THROW_ON_ERROR);
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
    <div class="list-filters">
      <select id="fProducto" title="Producto"></select>
      <select id="fOrden" title="Orden">
        <option value="prioridad">Orden: Prioridad</option>
        <option value="reciente">Orden: Más reciente</option>
        <option value="nombre">Orden: Nombre</option>
        <option value="monto">Orden: Monto</option>
      </select>
    </div>
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
        <button class="btn btn--wide" id="btnTerminar" type="button">Terminar atención</button>
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
