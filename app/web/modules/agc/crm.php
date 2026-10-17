<?php
// Interfaz del agente: la consola CRM a pantalla completa, sin el sidebar de
// administración. Es la misma consola que modules/admin/crm_app.php embebe en
// el panel, servida aquí de forma autónoma para quien sólo atiende llamadas.
//
// Los recursos (crm.css, crm.js) y el puente de WhatsApp siguen viviendo en
// modules/admin/: se referencian, no se duplican.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;

Auth::checkAccess([1, 2, 3, 4, 5, 6, 7, 8, 9]);

$usuario = (string)($_SESSION['user'] ?? '');
if (empty($_SESSION['dialer_csrf'])) $_SESSION['dialer_csrf'] = bin2hex(random_bytes(32));

// Conexión al discador. Es la misma acción "bootstrap" de
// modules/admin/dialer_agent.php: Dialer::agentLogin valida que el agente
// tenga anexo SIP activo, lo marca conectado y lo suma a las colas de sus
// campañas activas. La consola la invoca al abrir y muestra el resultado.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'bootstrap') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $mensaje = '';
    $fallo = '';
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialer_csrf'], $_POST['csrf_token'])) {
        $fallo = 'Sesión inválida, recarga la página.';
    } else {
        try {
            $_SESSION['dialer_entered'] = true;
            Dialer::agentLogin($usuario);
            $activas = array_filter(Dialer::campaignsForAgent($usuario), static function ($c) { return (bool)$c['active']; });
            $mensaje = $activas
                ? 'Conectado · ' . count($activas) . ' campaña' . (count($activas) === 1 ? '' : 's')
                : 'Sin campañas activas asignadas';
        } catch (Throwable $e) {
            $fallo = $e->getMessage();
        }
    }
    if ($fallo !== '') http_response_code(503);
    echo json_encode(['message' => $mensaje, 'error' => $fallo], JSON_THROW_ON_ERROR);
    exit;
}

$operador = (string)($_SESSION['full_name'] ?? $_SESSION['user'] ?? 'OPERADOR');
$nivel = (int)($_SESSION['user_level'] ?? 1);
$rol = $nivel >= 8 ? 'Administrador' : ($nivel >= 6 ? 'Supervisor' : 'Asesor de ventas');
function crm_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2D2F3B">
<title>CRM · Atención Telefónica · Zynervox</title>
<link rel="stylesheet" href="../admin/crm-assets/crm.css">
<script src="../admin/crm-assets/crm.js" defer></script>
</head>
<body data-crm-user="<?php echo crm_h($operador); ?>" data-crm-rol="<?php echo crm_h($rol); ?>" data-crm-exit="../../index.php" data-crm-wa="../admin/crm_wa_session.php"
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
