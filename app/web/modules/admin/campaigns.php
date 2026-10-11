<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;

Auth::checkAccess([7, 8, 9]);
if (empty($_SESSION['dialer_csrf'])) $_SESSION['dialer_csrf'] = bin2hex(random_bytes(32));

$msg = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialer_csrf'], $_POST['csrf_token'])) {
        $error = 'Sesión inválida, recarga la página.';
    } else {
        try {
            if (isset($_POST['create'])) {
                $selected=filter_var($_POST['selected_dialplan_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if (!$selected) throw new RuntimeException('Selecciona un dialplan de salida.');
                $id = Dialer::createCampaignFromDialplan((string) ($_POST['name'] ?? ''), $selected, isset($_POST['active']));
                $msg = "Campaña #$id creada.";
            } elseif (isset($_POST['toggle'])) {
                Dialer::toggleActive((int) ($_POST['campaign_id'] ?? 0), (string) ($_POST['current_active'] ?? '0'));
                $msg = 'Estado actualizado.';
            } elseif (isset($_POST['delete'])) {
                if (($_POST['confirm_delete'] ?? '') !== '1') throw new RuntimeException('Confirma la eliminación de la campaña.');
                Dialer::deleteCampaign((int) ($_POST['campaign_id'] ?? 0));
                $msg = 'Campaña eliminada con sus listas, leads e historial. Los archivos de audio se conservan en el servidor.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

try {
    $campaigns = Dialer::campaigns();
    $dialplans = Dialer::availableDialplans();
    $dialplanNames=array_column($dialplans,'name','dialplan_id');
} catch (Throwable $e) {
    $campaigns = [];
    $dialplans = []; $dialplanNames=[];
    $error = $error ?: $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Campañas (Dialer) - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
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
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Campañas (motor con agentes)</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Sobre zynervox_core · <code>v2_dialer_*</code></p>
            </div>
        </header>

        <?php if ($msg !== ''): ?><p style="color:#10b981;margin-bottom:1rem;">✅ <?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p style="color:#fca5a5;margin-bottom:1rem;">❌ <?php echo htmlspecialchars($error); ?></p><?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 320px; gap: 1.25rem; align-items: start;">
            <div class="card">
                <h2>Listado de campañas</h2>
                <table class="table-compact">
                    <thead>
                        <tr><th>ID</th><th>Nombre</th><th>Estado</th><th>Dialplan</th><th>Listas</th><th>Agentes</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $c): ?>
                        <tr>
                            <td><strong><?php echo (int) $c['campaign_id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($c['name']); ?></td>
                            <td><span style="color: <?php echo $c['active'] ? '#10b981' : '#ef4444'; ?>; font-weight: 600;"><?php echo $c['active'] ? 'Activa' : 'Inactiva'; ?></span></td>
                            <td><?php echo htmlspecialchars($dialplanNames[$c['selected_dialplan_id'] ?? 0] ?? (empty($c['selected_dialplan_id']) ? 'Sin asignar' : 'No disponible')); ?></td>
                            <td><?php echo (int) $c['lists_count']; ?></td>
                            <td><?php echo (int) $c['agents_count']; ?></td>
                            <td style="white-space: nowrap;">
                                <a class="link-action" href="dialer_campaign_edit.php?id=<?php echo (int) $c['campaign_id']; ?>">Configurar</a>
                                <a class="link-action" href="dialer_report.php?id=<?php echo (int) $c['campaign_id']; ?>">Reporte</a>
                                <form method="POST" class="inline-form" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['dialer_csrf']); ?>">
                                    <input type="hidden" name="campaign_id" value="<?php echo (int) $c['campaign_id']; ?>">
                                    <input type="hidden" name="current_active" value="<?php echo (int) $c['active']; ?>">
                                    <button type="submit" name="toggle" class="link-action"><?php echo $c['active'] ? 'Desactivar' : 'Activar'; ?></button>
                                </form>
                                <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('¿Eliminar definitivamente la campaña #' . $c['campaign_id'] . ' — ' . $c['name'] . '? Se borrarán sus listas, leads, historial, disposiciones, asignaciones y exclusiones DNC propias. Los audios físicos se conservan, pero ya no aparecerán en la web. Esta acción no se puede deshacer.'), ENT_QUOTES, 'UTF-8'); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['dialer_csrf']); ?>">
                                    <input type="hidden" name="campaign_id" value="<?php echo (int) $c['campaign_id']; ?>">
                                    <input type="hidden" name="confirm_delete" value="1">
                                    <button type="submit" name="delete" class="link-action danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$campaigns): ?><tr><td colspan="7">Sin campañas todavía.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Nueva campaña</h2>
                <form method="POST" style="margin-top: 0.75rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['dialer_csrf']); ?>">
                    <div class="field">
                        <label>Nombre</label>
                        <input type="text" name="name" required maxlength="120">
                    </div>
                    <div class="field">
                        <label for="new-campaign-dialplan">Dialplan de salida</label>
                        <select id="new-campaign-dialplan" name="selected_dialplan_id" required>
                            <option value="">Seleccione...</option>
                            <?php foreach ($dialplans as $dialplan): ?>
                                <option value="<?php echo (int)$dialplan['dialplan_id']; ?>"><?php echo htmlspecialchars($dialplan['name'] . ' — ' . $dialplan['dial_prefix']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p style="font-size:12px;margin-top:8px">La troncal y el prefijo de salida se toman del dialplan. <a href="dialplan.php">Administrar dialplans</a></p>
                        <?php if (!$dialplans): ?><p role="alert">No hay dialplans activos con una salida SIP compatible.</p><?php endif; ?>
                    </div>
                    <label style="display:flex;align-items:center;gap:6px;margin:10px 0;"><input type="checkbox" name="active" value="1" checked style="width:auto;margin:0"> Activa</label>
                    <button type="submit" name="create" class="btn-primary" style="width: 100%; padding: 0.6rem;">Crear campaña</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
