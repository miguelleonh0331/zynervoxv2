<?php
// Consola de Atención Telefónica (maqueta). Se sirve dentro del iframe de
// crm.php para que su hoja de estilos de pantalla completa no choque con el
// layout de Zynervox. Mismo nivel de acceso que crm.php y "Panel de agente".
require_once __DIR__ . '/../../includes/Auth.php';
use Includes\Auth;

Auth::checkAccess([1, 2, 3, 4, 5, 6, 7, 8, 9]);

$operador = (string)($_SESSION['full_name'] ?? $_SESSION['user'] ?? 'OPERADOR');
function crm_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2D2F3B">
<title>Atención Telefónica · Zynervox CRM</title>
<link rel="stylesheet" href="crm-assets/crm.css">
<script src="crm-assets/crm.js" defer></script>
</head>
<body data-crm-user="<?php echo crm_h($operador); ?>" data-crm-exit="index.php">

<div id="app">

  <header class="topbar">
    <div class="brand"><i></i> ZYNERVOX CRM</div>
    <div class="topbar-jump">
      <label for="jump">Ir a contacto</label>
      <input id="jump" type="search" placeholder="ID o documento" autocomplete="off">
      <button id="jumpGo" type="button">Abrir</button>
    </div>
    <div class="topbar-spacer"></div>
    <div class="topbar-meta">
      <span>Campaña <b>CRM VENTAS</b></span>
      <span>Operador <b id="opName">—</b></span>
      <span class="pill">Asignados <b id="leadCount">0</b></span>
    </div>
    <button id="exit" class="topbar-exit" type="button">Salir</button>
  </header>

  <!-- Columna 1: cola de trabajo. Reemplaza las pestañas de búsqueda y el
       cuadro de leads asignados del escritorio original. -->
  <section class="col col--queue">
    <div class="block block--grow">
      <h2>Cola de trabajo <span class="h-count" id="queueCount">—</span></h2>
      <div class="queue-modes" id="queueModes"></div>
      <div class="queue-filter" id="queueFilter"></div>
      <div class="block-body" id="queueBody"></div>
    </div>
  </section>

  <!-- Columna 2: ficha del contacto, sus teléfonos y su historial. -->
  <section class="col col--file">
    <div class="contact-head">
      <span class="cid" id="cHeadId">—</span>
      <span class="cname" id="cHeadName">—</span>
      <span class="spacer"></span>
      <span class="cdoc" id="cHeadDoc"></span>
    </div>

    <div class="block">
      <h2>Datos del contacto</h2>
      <dl class="kv" id="cData"></dl>
    </div>

    <div class="block">
      <h2>Teléfonos <span class="h-count" id="telCount">—</span></h2>
      <div class="bar">
        <input id="telNum" type="text" placeholder="Número" style="width:140px" autocomplete="off">
        <select id="telTipo" style="width:110px"></select>
        <button id="telSave" class="btn-sm btn-sm--primary" type="button">Agregar</button>
        <button id="telCancel" class="btn-sm" type="button">Limpiar</button>
      </div>
      <div class="block-body" id="telBody" style="max-height:132px"></div>
    </div>

    <div class="block block--grow">
      <h2>Historial de gestiones <span class="h-count" id="histCount">—</span></h2>
      <div class="block-body" id="histBody"></div>
    </div>
  </section>

  <!-- Columna 3: tipificación. Es la acción principal del agente, por eso
       ocupa una columna fija en vez de ir embebida al pie del formulario. -->
  <aside class="col col--action">
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
  </aside>

  <footer class="statusbar">
    <span id="msg">Cargando…</span>
    <span style="margin-left:auto">Maqueta · datos ficticios, sin base de datos</span>
  </footer>

</div>

<div id="layer" hidden></div>

</body>
</html>
