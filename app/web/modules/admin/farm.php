<?php
require_once __DIR__ . '/../../includes/Auth.php';
\Includes\Auth::checkAccess(9);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Farm - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="integration-shell.css">
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; renderSidebar('farm'); ?>
<main class="integration-shell">
    <header class="integration-head"><h1>Farm</h1><p>Anexos SIP, workers y proxies</p></header>
    <iframe class="integration-frame" src="farm_app/index.php" title="Farm"></iframe>
</main>
</body>
</html>
