<?php
// Escritorio CRM (maqueta). Se sirve dentro del iframe de crm.php para que su
// hoja de estilos de pantalla completa no choque con el layout de Zynervox.
// Mismo nivel de acceso que crm.php y que "Panel de agente".
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
 <meta name="theme-color" content="#556b2f">
 <title>CRM VENTAS · Escritorio</title>
 <link rel="stylesheet" href="crm-assets/demo.css">
 <script src="crm-assets/demo.js" defer></script>
 <script src="crm-assets/crm-local.js" defer></script>
</head>
<body data-crm-user="<?php echo crm_h($operador); ?>" data-crm-base="crm-assets/" data-crm-exit="index.php">
 <div id="desktop">
  <header class="app-title"><span>▣</span> <strong id="appCaption">CRM VENTAS</strong><span class="window-chrome" aria-hidden="true">─　□　×</span></header>
  <nav id="menu" aria-label="Menú principal"></nav>
  <main id="workspace"><p class="loading">Cargando interfaz…</p></main>
  <footer class="statusbar"><span id="status">Maqueta de demostración · datos ficticios, sin base de datos</span><label>Vista <select id="zoom" aria-label="Escala de la interfaz"><option value="fit">Ajustar</option><option value="1">100%</option><option value="1.25">125%</option><option value=".75">75%</option></select></label><button id="resetDemo">Actualizar</button></footer>
 </div>
 <div id="modalLayer"></div>
 <input id="demoFile" type="file" accept=".csv,.xlsx,.xls,.txt" hidden>
</body>
</html>
