<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;

// Mismo nivel de acceso que "Panel de agente": cualquier usuario logueado.
Auth::checkAccess([1, 2, 3, 4, 5, 6, 7, 8, 9]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CRM - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
    /* El escritorio CRM va en un iframe: trae su propia hoja de estilos de
       pantalla completa (html,body a 100% y modales fijos) que chocaría con
       este layout si se insertara en la misma página. Aquí main-content pasa
       a columna de alto completo para que el marco ocupe el resto exacto. */
    .main-content { display: flex; flex-direction: column; height: 100vh; }
    .crm-frame { flex: 1; min-height: 0; width: 100%; display: block; border: 1px solid var(--border); background: var(--bg-main); }
    </style>
</head>
<body>

    <?php renderSidebar('crm'); ?>

    <main class="main-content">
        <header class="top-bar">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">CRM</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Maqueta del escritorio de ventas &mdash; datos ficticios, sin base de datos</p>
            </div>
            <a class="btn-primary" href="crm_app.php" target="_blank" rel="noopener">Abrir en pestaña completa</a>
        </header>

        <iframe class="crm-frame" src="crm_app.php" title="Escritorio CRM"></iframe>
    </main>

</body>
</html>
