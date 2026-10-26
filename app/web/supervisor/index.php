<?php
// Caparazon del puesto de supervision. Lo que no tiene respaldo en el motor va
// tachado y sin enlace, con el motivo a la vista: un tablero vale por lo que
// de el es cierto.
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/CoreUsers.php';

use Includes\Auth;

Auth::checkAccess([7, 8, 9]);

$level = (int) ($_SESSION['user_level'] ?? 0);
$user = (string) ($_SESSION['user'] ?? '');
$fullName = (string) ($_SESSION['full_name'] ?? $user);
$role = $level === 7 ? 'GTR' : ($level === 8 ? 'Supervisor' : 'Administrador');
$scoped = in_array($level, \Includes\CoreUsers::scopedLevels(), true);

// El snapshot ya viene recortado al alcance de la sesion.
$campaigns = [];
$campaignsError = '';
try {
    require_once __DIR__ . '/../modules/admin/campaign_monitor_cache.php';
    foreach (campaign_monitor_cache_read()['campaigns'] as $campaign) {
        if (is_array($campaign)) $campaigns[] = (string) ($campaign['name'] ?? '');
    }
} catch (\Throwable $exception) {
    $campaignsError = 'El monitor está esperando una actualización del servicio.';
}

$sections = [
    'Operación' => [
        ['t' => 'Monitor en vivo', 'd' => 'Estado de cada campaña, actualizado cada cinco segundos', 'h' => '../modules/admin/campaign_monitor.php'],
        ['t' => 'Campañas', 'd' => 'Crear, arrancar y detener', 'h' => '../modules/admin/campaigns.php'],
        ['t' => 'Piso en vivo', 'd' => 'Agente por agente, en qué anda cada uno', 'p' => 'Los datos existen en v2_dialer_agent_intervals, pero ningún método los expone: el panel del agente es sólo para sí mismo'],
    ],
    'Base' => [
        ['t' => 'Listas y leads', 'd' => 'Cargar y revisar la base de la campaña', 'h' => '../modules/admin/dialer_list_leads.php'],
        ['t' => 'Segmentación', 'd' => 'Volver a llamar sólo a quien conviene', 'p' => 'No existe el constructor de segmentos ni las reglas de reintento por tipificación'],
    ],
    'Contacto' => [
        ['t' => 'WhatsApp', 'd' => 'Conversaciones del piso', 'h' => '../modules/admin/whatsapp.php'],
        ['t' => 'Clientes', 'd' => 'Ficha única con historial de todos los canales', 'p' => 'El expediente unificado no existe como entidad en la base'],
    ],
    'Gestión' => [
        ['t' => 'Reportes', 'd' => 'Los veinte indicadores del motor, embudo y tipificaciones', 'h' => '../modules/admin/dialer_report.php'],
        ['t' => 'Grabaciones', 'd' => 'Escuchar lo ya grabado, por campaña, fecha o agente', 'h' => '../modules/admin/dialer_report.php?view=recordings'],
        ['t' => 'Equipo', 'd' => 'Asistencia, pausas contra el tope, ranking', 'p' => 'El motor agrega la ocupación por campaña y descarta la identidad: hoy no hay indicador por agente'],
        ['t' => 'Calidad', 'd' => 'Puntaje por criterio sobre las grabaciones', 'p' => 'No hay tabla de puntaje ni criterios de evaluación'],
        ['t' => 'Entrar a la llamada', 'd' => 'Escuchar, susurrar al asesor, intervenir', 'p' => 'ChanSpy no existe en el dialplan; es trabajo de telefonía, no de pantalla'],
        ['t' => 'Comunicación', 'd' => 'Mensaje al piso, chat interno, manos levantadas', 'p' => 'No hay ningún canal entre el supervisor y el agente'],
        ['t' => 'Monitoreo remoto', 'd' => 'Las computadoras del equipo', 'p' => 'El backend de Zynerdesk está, pero falta el agente Windows y entra con identidad propia'],
    ],
];

function supHtml($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Supervisión - Zynervox</title><link rel="stylesheet" href="../modules/admin/layout.css">
<style>
body{font-family:Arial,sans-serif}
.sup-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:.6rem}
.sup-quien{color:var(--text-muted);font-size:.85rem;margin:2px 0 0}
.sup-alcance{margin:.6rem 0 1.2rem}
.sup-alcance .chip{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:3px 10px;margin:4px 6px 0 0;font-size:.8rem}
.sup-vacio{color:#b45309}
.sup-sec{margin-bottom:1.4rem}
.sup-sec h2{font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin:0 0 .5rem}
.sup-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.sup-card{display:block;border:1px solid var(--border);border-radius:8px;padding:14px 16px;text-decoration:none;color:inherit;background:var(--bg-card)}
a.sup-card:hover{border-color:var(--text-muted)}
.sup-card h3{margin:0;font-size:.95rem}
.sup-card p{margin:5px 0 0;font-size:.8rem;color:var(--text-muted)}
.sup-card--no{opacity:.55}
.sup-card--no h3{text-decoration:line-through}
.sup-pend{display:inline-block;margin-top:7px;font-size:.7rem;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);border:1px solid var(--border);border-radius:4px;padding:1px 6px}
</style></head>
<body>
<main class="main-content" style="margin-left:0">
<header class="top-bar sup-top">
    <div>
        <h1 style="font-size:1.15rem;font-weight:700">Supervisión</h1>
        <p class="sup-quien"><?php echo supHtml($fullName); ?> · <?php echo supHtml($role); ?></p>
    </div>
    <a href="../logout.php" class="link-action">Cerrar sesión</a>
</header>

<div class="sup-alcance">
<?php if ($campaignsError): ?>
    <p class="sup-vacio"><?php echo supHtml($campaignsError); ?></p>
<?php elseif ($campaigns): ?>
    <strong style="font-size:.85rem">Campañas a su cargo:</strong>
    <?php foreach ($campaigns as $campaign): ?><span class="chip"><?php echo supHtml($campaign); ?></span><?php endforeach; ?>
<?php elseif ($scoped): ?>
    <p class="sup-vacio">No tiene campañas asignadas todavía: pídale a un administrador que se las marque en Usuarios.</p>
<?php else: ?>
    <p class="sup-quien">No hay campañas en el monitor todavía.</p>
<?php endif; ?>
</div>

<?php foreach ($sections as $title => $items): ?>
<section class="sup-sec">
    <h2><?php echo supHtml($title); ?></h2>
    <div class="sup-grid">
    <?php foreach ($items as $item): ?>
        <?php if (isset($item['h'])): ?>
        <a class="sup-card" href="<?php echo supHtml($item['h']); ?>">
            <h3><?php echo supHtml($item['t']); ?></h3>
            <p><?php echo supHtml($item['d']); ?></p>
        </a>
        <?php else: ?>
        <div class="sup-card sup-card--no">
            <h3><?php echo supHtml($item['t']); ?></h3>
            <p><?php echo supHtml($item['d']); ?></p>
            <span class="sup-pend">No construido</span>
            <p><?php echo supHtml($item['p']); ?></p>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
</main>
</body></html>
