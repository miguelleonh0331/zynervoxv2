<?php
require_once __DIR__ . '/../../includes/Auth.php';
\Includes\Auth::checkAccess(9);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stt Providers - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="integration-shell.css">
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; renderSidebar('stt_providers'); ?>
<main class="integration-shell">
    <header class="integration-head"><h1>Stt Providers</h1><p>Cuentas, API keys y pruebas de transcripción</p></header>
    <iframe class="integration-frame" src="stt_providers_app/stt_providers_admin.php" title="Stt Providers"></iframe>
</main>
</body>
</html>
