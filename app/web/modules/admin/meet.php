<?php
require_once __DIR__ . '/../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

$recordings = [
    ['title' => 'Reunión de coordinación comercial', 'date' => '15 oct 2026 · 09:00', 'duration' => '32 min', 'people' => '6 participantes', 'team' => 'Equipo comercial', 'summary' => 'Revisión de objetivos semanales, seguimiento de oportunidades y asignación de próximos contactos.'],
    ['title' => 'Capacitación de atención al cliente', 'date' => '14 oct 2026 · 11:30', 'duration' => '48 min', 'people' => '12 participantes', 'team' => 'Atención al cliente', 'summary' => 'Repaso del protocolo de atención, resolución de consultas frecuentes y ejercicios de seguimiento.'],
    ['title' => 'Seguimiento de implementación', 'date' => '13 oct 2026 · 16:00', 'duration' => '25 min', 'people' => '4 participantes', 'team' => 'Operaciones', 'summary' => 'Revisión de avances, identificación de pendientes y coordinación de la siguiente entrega.'],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Meet - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="integration-shell.css">
    <style>
        .meet-content { padding: 24px; }
        .meet-notice { padding: 14px 16px; background: #fff4e5; border: 1px solid #e4c9a2; line-height: 1.6; margin-bottom: 24px; }
        .meet-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-top: 16px; }
        .meet-card { background: #fff; border: 1px solid var(--border); min-width: 0; }
        .meet-preview { background: var(--dark); color: #fff; min-height: 150px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; }
        .meet-preview svg { width: 36px; height: 36px; color: #f5821f; }
        .meet-card-body { padding: 18px; }
        .meet-card h3 { font-size: 17px; margin-bottom: 12px; }
        .meet-meta { color: var(--text-muted); font-size: 14px; line-height: 1.8; }
        .meet-card details { margin-top: 16px; border-top: 1px solid var(--border); padding-top: 12px; }
        .meet-card summary { cursor: pointer; color: #9c4700; padding: 4px 0; }
        .meet-card details p { margin-top: 12px; line-height: 1.6; }
        .meet-demo { font-size: 12px; border: 1px solid #848793; padding: 3px 8px; }
        .meet-unavailable { margin-top: 16px; font-size: 13px; color: var(--text-muted); }
        @media (max-width: 420px) { .meet-content { padding: 14px; } .meet-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; renderSidebar('meet'); ?>
<main class="integration-shell">
    <header class="integration-head">
        <div><h1>Meet</h1><p>Biblioteca de grabaciones de Google Meet</p></div>
        <span>3 grabaciones de demostración</span>
    </header>
    <section class="meet-content" aria-labelledby="meet-recordings">
        <p class="meet-notice"><strong>Modo demostración.</strong> Las reuniones, fechas y participantes son ficticios. No hay una cuenta de Google conectada ni videos reales disponibles para reproducir.</p>
        <h2 id="meet-recordings">Grabaciones recientes</h2>
        <div class="meet-grid">
            <?php foreach ($recordings as $recording): ?>
            <article class="meet-card">
                <div class="meet-preview" aria-hidden="true"><?= _svgIcon('meet') ?><span>Google Meet</span><span class="meet-demo">DEMO · SIN VIDEO</span></div>
                <div class="meet-card-body">
                    <h3><?= htmlspecialchars($recording['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="meet-meta"><?= htmlspecialchars($recording['date'], ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($recording['duration'] . ' · ' . $recording['people'], ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($recording['team'], ENT_QUOTES, 'UTF-8') ?></p>
                    <details><summary>Ver resumen de ejemplo</summary><p><?= htmlspecialchars($recording['summary'], ENT_QUOTES, 'UTF-8') ?></p></details>
                    <p class="meet-unavailable">Reproducción no disponible en esta demo.</p>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
