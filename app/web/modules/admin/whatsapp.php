<?php
require_once __DIR__ . '/../../includes/Auth.php';

use Includes\Auth;

Auth::checkAccess([7, 8, 9]);
$basePath = '/zynerwabav2/';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .whatsapp-frame { width: 100%; height: calc(100vh - 7rem); border: 0; border-radius: 12px; background: #fff; }
        .whatsapp-link { color: #f5821f; font-size: .82rem; text-decoration: none; }
    </style>
</head>
<body>
<?php
if (($_SESSION['user_level'] ?? 0) >= 9) {
    require_once __DIR__ . '/sidebar.php';
    renderSidebar('whatsapp');
} else {
    require_once __DIR__ . '/../sup/sidebar.php';
    renderSupSidebar('whatsapp');
}
?>
<main class="main-content">
    <header class="top-bar" style="margin-bottom: 1rem;">
        <div>
            <h1 style="font-size: 1.25rem; font-weight: 700;">WhatsApp</h1>
            <p style="color: var(--text-muted); font-size: .8125rem;">Conversaciones, usuarios, campañas y líneas</p>
        </div>
        <a class="whatsapp-link" href="<?php echo htmlspecialchars($basePath); ?>" target="_blank" rel="noopener">Abrir en una pestaña</a>
    </header>
    <iframe class="whatsapp-frame" src="<?php echo htmlspecialchars($basePath); ?>" title="Zynerwaba"></iframe>
</main>
</body>
</html>
