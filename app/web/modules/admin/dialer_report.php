<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;

Auth::checkAccess([7, 8, 9]);
if (($_GET['view'] ?? '') === 'recordings') { require __DIR__.'/dialer_recordings.php'; exit; }
function h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function metric($value, string $unit = ''): string {
    return $value === null ? 'N/D' : h(number_format((float) $value, $unit === '' ? 0 : 2, ',', '.')) . h($unit);
}

$campaignId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$since = is_string($_GET['since'] ?? null) ? $_GET['since'] : date('Y-m-d', strtotime('-30 days'));
$until = is_string($_GET['until'] ?? null) ? $_GET['until'] : date('Y-m-d');
$error = '';
$campaign = null;
$kpis = null;
try {
    if (!$campaignId) throw new InvalidArgumentException('ID de campaña inválido.');
    $campaign = Dialer::campaign($campaignId);
    $kpis = Dialer::kpis($campaignId, $since, $until);
    $levels = Dialer::abandonByLevel($campaignId, $since, $until);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    $error = $e->getMessage();
} catch (Throwable $e) {
    http_response_code($campaign === null ? 404 : 500);
    $error = 'No se pudo obtener el reporte. Verifica la campaña y vuelve a intentar.';
    error_log('dialer_report: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte de campaña - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0}
        .report-grid .card{padding:16px}.report-grid dt{color:var(--text-muted);font-size:13px}.report-grid dd{margin:8px 0 0;font-size:24px}
        .report-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end}.report-note{font-size:13px;color:var(--text-muted);margin:12px 0}
        .report-table{width:100%;font-size:13px}.report-table th,.report-table td{text-align:left;padding:8px;border-bottom:1px solid var(--border)}
    </style>
</head>
<body>
<?php
if (($_SESSION['user_level'] ?? 0) == 9) {
    require_once __DIR__ . '/sidebar.php'; renderSidebar('campaigns');
} else {
    require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('campaigns');
}
?>
<main class="main-content">
    <header class="top-bar" style="margin-bottom:16px">
        <div>
            <h1>Reporte<?php echo $campaign ? ' — ' . h($campaign['name']) : ' de campaña'; ?></h1>
            <a href="campaigns.php">Campañas</a>
            <?php if ($campaign): ?> · <a href="dialer_campaign_edit.php?id=<?php echo (int) $campaignId; ?>">Configuración</a> · <a href="dialer_report.php?view=recordings&amp;id=<?php echo (int)$campaignId; ?>">Buscar grabaciones</a><?php endif; ?>
        </div>
    </header>
    <?php if ($error !== ''): ?><p role="alert"><?php echo h($error); ?></p><?php endif; ?>
    <?php if ($campaign): ?>
    <form method="GET" class="card report-filters">
        <input type="hidden" name="id" value="<?php echo (int) $campaignId; ?>">
        <div class="field"><label for="since">Desde</label><input id="since" type="date" name="since" value="<?php echo h($since); ?>" required></div>
        <div class="field"><label for="until">Hasta (inclusive)</label><input id="until" type="date" name="until" value="<?php echo h($until); ?>" required></div>
        <button type="submit" class="btn-primary" style="padding:10px">Ver reporte</button>
    </form>
    <?php endif; ?>
    <?php if ($kpis !== null): ?>
    <p class="report-note">Periodo por fecha de inicio de llamada, en la zona horaria del servidor. Las llamadas abiertas hacen provisionales los porcentajes. N/D indica que no hay datos suficientes.</p>
    <dl class="report-grid">
        <?php foreach ([
            'Intentos' => [$kpis['placed'], ''], 'Contestadas' => [$kpis['answered_total'], ''],
            'Respuestas humanas' => [$kpis['answered_human'], ''], 'Contestadores' => [$kpis['answered_machine'], ''],
            'Abandonos' => [$kpis['dropped'], ''], 'Llamadas abiertas' => [$kpis['in_progress'], ''],
            'Contactos tipificados' => [$kpis['contacted'], ''], 'Ventas' => [$kpis['sales'], ''],
            'Abandono' => [$kpis['abandon_pct'], ' %'], 'Tasa de contacto' => [$kpis['contact_pct'], ' %'],
            'ASR' => [$kpis['asr_pct'], ' %'], 'Tasa de contestador' => [$kpis['machine_pct'], ' %'],
            'Conversión' => [$kpis['conversion_pct'], ' %'], 'Espera promedio' => [$kpis['avg_wait_seconds'], ' s'],
            'AHT' => [$kpis['aht_seconds'], ' s'], 'Ocupación de agente' => [$kpis['occupancy_pct'], ' %'],
        ] as $label => [$value, $unit]): ?>
            <div class="card"><dt><?php echo h($label); ?></dt><dd><?php echo metric($value, $unit); ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <p class="report-note">Objetivo de abandono: <?php echo metric($campaign['adaptive_target_abandon_pct'], ' %'); ?>.
        Espera: <?php echo (int) $kpis['wait_samples']; ?> llamadas con duración medida.
        AHT: <?php echo (int) $kpis['aht_samples']; ?> llamadas con conversación y tipificación medidas.</p>
    <section class="card">
        <h2>Abandono por nivel de marcado</h2>
        <div style="overflow-x:auto">
        <table class="report-table">
            <thead><tr><th scope="col">Nivel al originar</th><th scope="col">Intentos</th><th scope="col">Humanos</th><th scope="col">Abandonos</th><th scope="col">Abandono %</th></tr></thead>
            <tbody>
            <?php foreach ($levels as $level): ?>
                <tr><td><?php echo h(number_format((float) $level['dial_level_at_origin'], 2, ',', '.')); ?></td><td><?php echo (int) $level['llamadas']; ?></td><td><?php echo (int) $level['humanos']; ?></td><td><?php echo (int) $level['abandonos']; ?></td><td><?php echo metric($level['abandono_pct'], ' %'); ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$levels): ?><tr><td colspan="5">Sin llamadas con nivel registrado en este periodo.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <p class="report-note">Las llamadas históricas sin nivel guardado se incluyen en los totales y no en este desglose.</p>
    </section>
    <section class="card" style="margin-top:16px">
        <h2>Cómo se calculan</h2>
        <ul>
            <li>Abandono: humanos abandonados / respuestas humanas. Los contestadores quedan excluidos, también por nivel.</li>
            <li>Tasa de contacto: respuestas humanas / intentos. AMD NOTSURE y AMD sin resultado se cuentan como humanos, según la política actual.</li>
            <li>ASR: contestadas / intentos. Tasa de contestador: contestadores / contestadas.</li>
            <li>Contactos tipificados: llamadas completadas con una disposición marcada como contacto. Conversión: ventas marcadas como contacto / contactos tipificados. Se usa el catálogo actual de disposiciones.</li>
            <li>Espera: promedio desde respuesta hasta conexión con agente, solo para llamadas con duración medida.</li>
            <li>AHT: promedio de conversación + tiempo observado hasta la primera tipificación tras el cierre. Los registros incompletos o históricos sin duraciones se excluyen.</li>
            <li>Ocupación: conversación y wrapup observado, divididos por tiempo logueado sin pausa manual. Intervalos recortados al periodo. Cobertura: <?php echo h($kpis['occupancy_coverage']); ?>; <?php echo h($kpis['logged_seconds']); ?> segundos observados. Históricos o huecos sin sesiones: N/D.</li>
        </ul>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
