<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;

Auth::checkAccess([7, 8, 9]);
if (empty($_SESSION['dialer_csrf'])) $_SESSION['dialer_csrf'] = bin2hex(random_bytes(32));

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$campaignId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$msg = '';
$error = '';

try {
    if (!$campaignId) throw new RuntimeException('ID de campaña inválido.');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialer_csrf'], $_POST['csrf_token'])) {
            throw new RuntimeException('Sesión inválida, recarga la página.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_campaign') {
            Dialer::updateCampaign($campaignId, $_POST);
            $msg = 'Configuración guardada.';
        } elseif ($action === 'create_list') {
            Dialer::createList($campaignId, (string) ($_POST['list_name'] ?? ''));
            $msg = 'Lista creada.';
        } elseif ($action === 'create_disposition') {
            Dialer::createDisposition(
                $campaignId,
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                isset($_POST['is_contact']),
                isset($_POST['is_sale']),
                isset($_POST['retryable']),
                (int) ($_POST['retry_delay_minutes'] ?? 60)
            );
            $msg = 'Disposición agregada.';
        } elseif ($action === 'delete_disposition') {
            Dialer::deleteDisposition((int) ($_POST['disposition_id'] ?? 0), $campaignId);
            $msg = 'Disposición eliminada.';
        } elseif ($action === 'set_agents') {
            Dialer::setCampaignAgents($campaignId, (array) ($_POST['agents'] ?? []));
            $msg = 'Agentes asignados.';
        } else {
            throw new RuntimeException('Acción inválida.');
        }
    }

    $campaign = Dialer::campaign($campaignId);
    $lists = Dialer::lists($campaignId);
    $dispositions = Dialer::dispositions($campaignId);
    $availableAgents = Dialer::availableAgents();
    $assignedAgents = Dialer::agentsForCampaign($campaignId);
} catch (Throwable $e) {
    http_response_code($campaignId ? 404 : 400);
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Campaña #<?php echo h($campaignId); ?> - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .dialer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .dialer-grid .field{margin-bottom:10px}
        .dialer-card{margin-bottom:16px}
        .dialer-table{width:100%;font-size:12px}
        .dialer-table th,.dialer-table td{padding:5px 8px;border-bottom:1px solid var(--border);text-align:left}
        .agent-list{max-height:260px;overflow:auto;border:1px solid var(--border);padding:8px}
        .agent-list label{display:flex;align-items:center;gap:6px;padding:3px 0;font-weight:normal}
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
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;"><?php echo $campaign ?? null ? h('Campaña #' . $campaignId . ' — ' . $campaign['name']) : 'Campaña'; ?></h1>
                <a href="campaigns.php" style="font-size:12px;color:var(--text-muted)">&larr; Volver al listado</a>
                <?php if (!empty($campaign)): ?> · <a href="dialer_report.php?id=<?php echo (int) $campaignId; ?>">Ver reporte</a><?php endif; ?>
            </div>
        </header>

        <?php if ($msg !== ''): ?><p style="color:#10b981;margin-bottom:1rem;">✅ <?php echo h($msg); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p style="color:#fca5a5;margin-bottom:1rem;">❌ <?php echo h($error); ?></p><?php endif; ?>

        <?php if (!empty($campaign)): ?>
        <div class="card dialer-card">
            <h2>Configuración</h2>
            <p style="font-size:12px;color:var(--text-muted);margin-top:-6px">
                Troncal: <strong><?php echo h($campaign['carrier_id']); ?></strong> (fija al crear) ·
                Cola Asterisk: <code><?php echo h($campaign['queue_name']); ?></code>
            </p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="update_campaign">
                <div class="field">
                    <label>Nombre</label>
                    <input type="text" name="name" value="<?php echo h($campaign['name']); ?>" maxlength="120" required>
                </div>
                <label style="display:flex;align-items:center;gap:6px;margin:8px 0;"><input type="checkbox" name="active" value="1" <?php echo $campaign['active'] ? 'checked' : ''; ?> style="width:auto;margin:0"> Activa</label>
                <div class="dialer-grid">
                    <?php foreach (Dialer::CAMPAIGN_FIELDS as $key => $field): $value = (string) $campaign[$key]; ?>
                    <div class="field">
                        <label><?php echo h($field['label']); ?></label>
                        <?php if ($field['type'] === 'select'): ?>
                            <select name="<?php echo h($key); ?>">
                                <?php foreach ($field['options'] as $option): ?>
                                    <option value="<?php echo h($option); ?>" <?php echo $value === (string) $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: $numeric = in_array($field['type'], ['number', 'decimal'], true); ?>
                            <input type="<?php echo $numeric ? 'number' : 'text'; ?>" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>"
                                <?php if ($numeric): ?>min="<?php echo h($field['min']); ?>" max="<?php echo h($field['max']); ?>" step="<?php echo $field['type'] === 'decimal' ? '0.01' : '1'; ?>"<?php else: ?>maxlength="<?php echo h($field['length']); ?>"<?php endif; ?> required>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn-primary" style="margin-top:10px;padding:0.5rem 1.2rem;">Guardar configuración</button>
            </form>
        </div>

        <div class="card dialer-card">
            <h2>Listas</h2>
            <table class="dialer-table">
                <thead><tr><th>ID</th><th>Nombre</th><th>Leads</th><th>Activa</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lists as $list): ?>
                    <tr>
                        <td><?php echo (int) $list['list_id']; ?></td>
                        <td><?php echo h($list['name']); ?></td>
                        <td><?php echo (int) $list['leads_count']; ?></td>
                        <td><?php echo $list['active'] ? 'Sí' : 'No'; ?></td>
                        <td><a class="link-action" href="dialer_list_leads.php?id=<?php echo (int) $list['list_id']; ?>&amp;campaign_id=<?php echo (int) $campaignId; ?>">Cargar leads</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lists): ?><tr><td colspan="5">Sin listas todavía.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <form method="POST" style="margin-top:10px;display:flex;gap:8px;">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="create_list">
                <input type="text" name="list_name" placeholder="Nombre de la lista" maxlength="120" required style="flex:1">
                <button type="submit" class="carsa-btn">Crear lista</button>
            </form>
        </div>

        <div class="card dialer-card">
            <h2>Disposiciones</h2>
            <table class="dialer-table">
                <thead><tr><th>Código</th><th>Etiqueta</th><th>Contacto</th><th>Venta</th><th>Reintenta</th><th>Espera (min)</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($dispositions as $d): ?>
                    <tr>
                        <td><?php echo h($d['code']); ?></td>
                        <td><?php echo h($d['label']); ?></td>
                        <td><?php echo $d['is_contact'] ? 'Sí' : 'No'; ?></td>
                        <td><?php echo $d['is_sale'] ? 'Sí' : 'No'; ?></td>
                        <td><?php echo $d['retryable'] ? 'Sí' : 'No'; ?></td>
                        <td><?php echo (int) $d['retry_delay_minutes']; ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('¿Eliminar disposición?');">
                                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                                <input type="hidden" name="action" value="delete_disposition">
                                <input type="hidden" name="disposition_id" value="<?php echo (int) $d['disposition_id']; ?>">
                                <button type="submit" class="link-action danger">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dispositions): ?><tr><td colspan="7">Sin disposiciones todavía.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <form method="POST" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="create_disposition">
                <div class="field"><label>Código</label><input type="text" name="code" maxlength="20" required style="text-transform:uppercase"></div>
                <div class="field"><label>Etiqueta</label><input type="text" name="label" maxlength="80" required></div>
                <div class="field"><label>Espera reintento (min)</label><input type="number" name="retry_delay_minutes" value="60" min="0" max="100000"></div>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" name="is_contact" style="width:auto"> Contacto</label>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" name="is_sale" style="width:auto"> Venta</label>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" name="retryable" checked style="width:auto"> Reintenta</label>
                <button type="submit" class="carsa-btn">Agregar</button>
            </form>
        </div>

        <div class="card dialer-card">
            <h2>Agentes asignados</h2>
            <p style="font-size:12px;color:var(--text-muted);margin-top:-6px">Un agente puede estar asignado a varias campañas a la vez.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="set_agents">
                <div class="agent-list">
                    <?php foreach ($availableAgents as $agent): ?>
                        <label>
                            <input type="checkbox" name="agents[]" value="<?php echo h($agent['login']); ?>" <?php echo in_array($agent['login'], $assignedAgents, true) ? 'checked' : ''; ?>>
                            <?php echo h($agent['extension'] . ' — ' . ($agent['fullname'] ?: $agent['login'])); ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$availableAgents): ?><p>No hay anexos creados todavía (Anexos/Teléfonos).</p><?php endif; ?>
                </div>
                <button type="submit" class="btn-primary" style="margin-top:10px;padding:0.5rem 1.2rem;">Guardar asignación</button>
            </form>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
