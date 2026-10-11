<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;

// Cualquier usuario logueado puede ser agente (ver CONTRACT: agente = usuario
// Zynervox existente con anexo propio). No hay nivel especifico reservado
// para agentes todavia, asi que se acepta el rango completo de niveles.
Auth::checkAccess([1, 2, 3, 4, 5, 6, 7, 8, 9]);
if (empty($_SESSION['dialer_csrf'])) $_SESSION['dialer_csrf'] = bin2hex(random_bytes(32));

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$user = (string) ($_SESSION['user'] ?? '');
$msg = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialer_csrf'], $_POST['csrf_token'])) {
        $error = 'Sesión inválida, recarga la página.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            if ($action === 'join') {
                $msg = Dialer::agentJoinQueue($user, $campaignId);
            } elseif ($action === 'leave') {
                $msg = Dialer::agentLeaveQueue($user, $campaignId);
            } elseif ($action === 'pause') {
                $msg = Dialer::agentSetPause($user, $campaignId, true, (string) ($_POST['reason'] ?? ''));
            } elseif ($action === 'unpause') {
                $msg = Dialer::agentSetPause($user, $campaignId, false);
            } elseif ($action === 'dispose') {
                Dialer::setDisposition((int) ($_POST['call_id'] ?? 0), $user, (string) ($_POST['code'] ?? ''));
                $msg = 'Llamada tipificada.';
            } else {
                throw new RuntimeException('Acción inválida.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

try {
    $campaigns = Dialer::campaignsForAgent($user);
    $pending = Dialer::pendingDispositionForAgent($user);
    $dispositions = $pending ? Dialer::dispositions((int) $pending['campaign_id']) : [];
} catch (Throwable $e) {
    $campaigns = [];
    $pending = null;
    $dispositions = [];
    $error = $error ?: $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de agente - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .agent-camp{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid var(--border);margin-bottom:8px}
        .agent-camp form{display:inline}
        .agent-camp .actions{display:flex;gap:6px}
    </style>
</head>
<body>
    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('dialer_agent');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('dialer_agent');
    }
    ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Panel de agente</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Usuario: <?php echo h($user); ?></p>
            </div>
        </header>

        <?php if ($msg !== ''): ?><p style="color:#10b981;margin-bottom:1rem;">✅ <?php echo h($msg); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p style="color:#fca5a5;margin-bottom:1rem;">❌ <?php echo h($error); ?></p><?php endif; ?>

        <?php if ($pending): ?>
        <div class="card" style="margin-bottom:16px;">
            <h2>Tipificar llamada pendiente</h2>
            <p>Campaña <strong><?php echo h($pending['campaign_name']); ?></strong> · Teléfono <?php echo h($pending['phone_number']); ?> · <?php echo h($pending['originated_at']); ?></p>
            <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="dispose">
                <input type="hidden" name="call_id" value="<?php echo (int) $pending['call_id']; ?>">
                <select name="code" required>
                    <option value="">Selecciona disposición...</option>
                    <?php foreach ($dispositions as $d): ?>
                        <option value="<?php echo h($d['code']); ?>"><?php echo h($d['code'] . ' — ' . $d['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary">Guardar</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Mis campañas</h2>
            <?php foreach ($campaigns as $c): ?>
            <div class="agent-camp">
                <div>
                    <strong><?php echo h($c['name']); ?></strong>
                    <span style="color:var(--text-muted);font-size:12px;"> · cola <?php echo h($c['queue_name']); ?> · <?php echo $c['active'] ? 'activa' : 'inactiva'; ?></span>
                </div>
                <div class="actions">
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="campaign_id" value="<?php echo (int) $c['campaign_id']; ?>"><input type="hidden" name="action" value="join"><button type="submit" class="carsa-btn">Entrar a cola</button></form>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="campaign_id" value="<?php echo (int) $c['campaign_id']; ?>"><input type="hidden" name="action" value="pause"><button type="submit" class="carsa-btn secondary">Pausar</button></form>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="campaign_id" value="<?php echo (int) $c['campaign_id']; ?>"><input type="hidden" name="action" value="unpause"><button type="submit" class="carsa-btn secondary">Despausar</button></form>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="campaign_id" value="<?php echo (int) $c['campaign_id']; ?>"><input type="hidden" name="action" value="leave"><button type="submit" class="carsa-btn danger">Salir de cola</button></form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$campaigns): ?><p>No tienes campañas asignadas. Pídele al administrador que te asigne una en "Configurar" → Agentes asignados.</p><?php endif; ?>
        </div>
    </main>
</body>
</html>
