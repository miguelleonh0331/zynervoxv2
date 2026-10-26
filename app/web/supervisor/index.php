<?php
// Puesto de supervision. Misma pantalla que la maqueta app/web/demo-sup-v2:
// sup.css y sup.js se copian tal cual para que la distribucion, los colores y
// los botones sean los mismos. Lo unico que cambia es de donde salen los
// datos: datos.php reemplaza al datos.js inventado de la maqueta. Lo que el
// motor todavia no sabe calcular llega vacio y el cuadro queda en blanco.
// Fuera de la maqueta quedan la portada, el recorrido guiado y su voz.
require_once __DIR__ . '/../includes/Auth.php';

use Includes\Auth;

Auth::checkAccess([7, 8, 9]);

$level = (int) ($_SESSION['user_level'] ?? 0);
$fullName = (string) ($_SESSION['full_name'] ?? ($_SESSION['user'] ?? ''));
$role = $level === 7 ? 'GTR' : ($level === 8 ? 'Supervisor de piso' : 'Administrador');

function supHtml($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0F172A">
<title>Zynervox · Supervisión</title>
<link rel="stylesheet" href="sup.css">
</head>
<body class="sup-body">

<div id="app">

  <!-- --------------------------- menú lateral --------------------------- -->
  <aside class="side" id="side">
    <div class="side-brand"><i></i><span>ZYNERVOX <b>SUPERVISIÓN</b></span></div>
    <nav class="side-nav" id="sideNav"><!-- sup.js --></nav>
    <div class="side-foot">
      <div class="side-yo">
        <span class="side-yo-cara" id="yoCara"></span>
        <span class="side-yo-txt"><b><?php echo supHtml($fullName); ?></b><em><?php echo supHtml($role); ?></em></span>
      </div>
    </div>
  </aside>

  <!-- --------------------------- barra superior ------------------------- -->
  <header class="topbar">
    <button class="icon-btn" id="btnPlegar" type="button" title="Plegar el menú" aria-label="Plegar el menú"></button>

    <div class="search" id="buscador">
      <input id="qGlobal" type="text" placeholder="Buscar cliente, teléfono, agente o campaña…" autocomplete="off">
      <kbd>/</kbd>
    </div>
    <div class="sug" id="sugerencias" hidden></div>

    <div class="top-reloj" id="topReloj">—</div>

    <div class="top-chip" id="chipMotor" data-estado="on">
      <i class="pulso"></i><span>Marcador <b>activo</b></span>
    </div>

    <button class="icon-btn icon-btn--badge" id="btnAlertas" type="button" title="Alertas">
      <b class="badge" id="badgeAlertas">0</b>
    </button>

    <button class="top-btn" id="btnTV" type="button">Modo TV</button>
    <a class="top-btn" href="../logout.php">Salir</a>
    <span class="top-yo" id="topYo"></span>
  </header>

  <!-- --------------------------- lienzo --------------------------------- -->
  <main class="canvas" id="canvas"><!-- sup.js dibuja la vista activa --></main>

  <!-- --------------------------- barra de estado ------------------------ -->
  <footer class="statusbar">
    <span id="stVista">Piso en vivo</span>
    <span class="st-sep"></span>
    <span id="stAgentes">— agentes</span>
    <span class="st-sep"></span>
    <span id="stLlamadas">— en curso</span>
  </footer>
</div>

<!-- ======================= CAPAS SUPERPUESTAS ======================= -->

<!-- Fichas, confirmaciones y hojas laterales. -->
<div class="capa" id="capa" hidden></div>

<!-- Escucha en vivo: ocupa la esquina y sigue abierta mientras se navega. -->
<div class="escucha" id="escucha" hidden></div>

<!-- Wallboard para proyectar. -->
<div class="tv" id="tv" hidden></div>

<!-- Avisos breves. -->
<div class="toast" id="toast" hidden></div>

<script src="datos.php"></script>
<script src="sup.js"></script>
</body>
</html>
