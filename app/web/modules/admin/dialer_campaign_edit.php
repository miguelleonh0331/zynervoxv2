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
        if ($action === 'start_engine' || $action === 'stop_engine') {
            Dialer::setEngineState($campaignId,$action === 'start_engine' ? 'RUNNING' : 'STOPPED');
            $msg = $action === 'start_engine' ? 'Motor iniciado.' : 'Nuevas llamadas detenidas; las conversaciones actuales continúan.';
        } elseif ($action === 'update_campaign') {
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaña #<?php echo h($campaignId); ?> - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .campaign-page{font-size:14px;line-height:1.5}
        .campaign-page .main-content{min-width:0;padding:28px 32px;max-width:1660px}
        .campaign-page .top-bar{margin-bottom:24px;gap:16px;align-items:flex-start}
        .campaign-page .top-bar h1{font-size:24px!important;line-height:1.3;margin-bottom:8px}
        .campaign-page .top-bar a{font-size:13px!important}
        .campaign-page .dialer-card{padding:24px;margin-bottom:20px;border-radius:10px;min-width:0}
        .campaign-page .dialer-card>h2{font-size:18px;margin-bottom:16px}
        .engine-bar{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;padding-bottom:20px;border-bottom:1px solid var(--border);margin-bottom:20px}
        .engine-bar h2{font-size:18px;margin:0 0 8px}
        .engine-status{display:inline-flex;align-items:center;gap:8px;border-radius:20px;padding:4px 10px;background:#f1f3f5;color:#505969;font-size:12px;font-weight:700}
        .engine-status::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
        .engine-status.running{background:#e7f6ed;color:#176b40}
        .engine-meta{display:flex;align-items:center;flex-wrap:wrap;gap:10px;color:var(--text-muted);font-size:12px}
        .engine-actions{display:flex;flex-wrap:wrap;gap:10px}
        .campaign-page button,.campaign-page .btn-primary,.campaign-page .carsa-btn{font:600 13px Arial,sans-serif;border-radius:6px;padding:10px 16px;min-height:40px;cursor:pointer}
        .campaign-page .carsa-btn{border:1px solid var(--border);background:#fff;color:var(--text)}
        .campaign-page .carsa-btn:hover{border-color:var(--primary);background:#fff8f0}
        .campaign-page button:focus-visible,.campaign-page a:focus-visible{outline:2px solid var(--primary);outline-offset:3px}
        .campaign-page .link-action{padding:4px;min-height:auto;background:none;border:0}
        .campaign-context{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;color:var(--text-muted);font-size:13px}
        .campaign-context span{padding:6px 10px;background:#f7f7f8;border-radius:5px}
        .campaign-page .field{margin:0;min-width:0}
        .campaign-page label{display:block;text-transform:none;letter-spacing:0;font-size:13px;color:var(--text);font-weight:600;margin-bottom:6px}
        .campaign-page input:not([type=checkbox]),.campaign-page select{min-height:42px;margin-top:0;border-radius:6px;padding:10px 12px;font-size:14px}
        .campaign-page input:focus,.campaign-page select:focus{border-color:var(--primary);outline:2px solid #f5821f26}
        .campaign-page input[type=checkbox]{width:16px;height:16px;flex-shrink:0;margin:0;accent-color:var(--primary)}
        .campaign-identity{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:24px;margin-bottom:24px}
        .campaign-page .check-label{display:flex;align-items:center;gap:8px;margin:0;min-height:42px}
        .config-section{border:1px solid var(--border);border-radius:8px;margin:0 0 20px;padding:18px 20px 20px;min-width:0}
        .config-section legend{font-size:15px;font-weight:700;padding:0 8px;margin-left:-8px}
        .section-help{font-size:13px;color:var(--text-muted);margin:0 0 18px;max-width:80ch}
        .dialer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 24px}
        .config-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;border-top:1px solid var(--border);padding-top:20px;color:var(--text-muted);font-size:13px}
        .campaign-page [role=alert]{background:#fff4e5;border:1px solid #f4d8ad;border-radius:6px;color:#87520e;padding:12px 16px;margin-bottom:18px}
        .dialer-table{width:100%;font-size:13px}
        .dialer-table th,.dialer-table td{padding:10px 12px;text-align:left}
        .table-scroll{overflow-x:auto;margin-bottom:18px}
        .campaign-page .table-scroll table{margin-top:0}
        .create-list-form{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
        .create-list-form input{flex:1;min-width:180px}
        .disposition-form{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}
        .disposition-form .field{flex:1;min-width:160px}
        .disposition-form>label{display:flex;align-items:center;gap:6px;min-height:42px;margin:0}
        .agent-list{max-height:300px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:12px;margin:18px 0}
        .agent-list label{display:flex;align-items:center;gap:10px;padding:10px 8px;margin:0;font-weight:normal;border-radius:4px}
        .agent-list label:hover{background:#fff8f0}
        @media(min-width:1450px){.dialer-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:1000px){.campaign-page .main-content{padding:20px}.dialer-grid{grid-template-columns:1fr}.campaign-page .dialer-card{padding:20px}}
        @media(max-width:700px){.campaign-page{display:block}.campaign-page .sidebar{position:relative;width:100%;height:auto;max-height:190px}.campaign-page .main-content{margin-left:0;padding:16px;width:100%}.campaign-page .top-bar h1{font-size:20px!important}.campaign-page .dialer-card{padding:16px}.campaign-identity{grid-template-columns:1fr;gap:12px}.config-section{padding:14px}.engine-actions{width:100%}.engine-actions button{flex:1}.config-footer .btn-primary{width:100%}}
    </style>
</head>
<body class="campaign-page">
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
            <div class="engine-bar">
                <div><h2>Configuración de campaña</h2>
                    <div class="engine-meta"><span class="engine-status <?php echo $campaign['engine_state']==='RUNNING' ? 'running' : ''; ?>"><?php echo $campaign['engine_state']==='RUNNING' ? 'En marcha' : 'Detenido'; ?></span><span>Último ciclo: <?php echo h($campaign['worker_seen_at'] ?? 'sin observar'); ?></span></div>
                </div>
            <form method="POST" class="engine-actions">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <button class="btn-primary" name="action" value="start_engine">Iniciar motor</button>
                <button class="carsa-btn" name="action" value="stop_engine">Detener nuevas llamadas</button>
            </form>
            </div>
            <?php if ($campaign['worker_error']): ?><p role="alert"><?php echo h($campaign['worker_error']); ?></p><?php endif; ?>
            <?php if ($campaign['outbound_prefix'] === null): ?><p role="alert">Marcación pendiente: guarda el prefijo del número requerido por la troncal (vacío si no requiere prefijo).</p><?php endif; ?>
            <div class="campaign-context"><span>Troncal <strong><?php echo h($campaign['carrier_id']); ?></strong></span><span>Cola <code><?php echo h($campaign['queue_name']); ?></code></span></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="update_campaign">
                <div class="campaign-identity"><div class="field">
                    <label for="campaign-name">Nombre de la campaña</label>
                    <input type="text" id="campaign-name" name="name" value="<?php echo h($campaign['name']); ?>" maxlength="120" required>
                </div>
                <label class="check-label"><input type="checkbox" name="active" value="1" <?php echo $campaign['active'] ? 'checked' : ''; ?>> Campaña activa</label></div>
                <?php $sections = [
                    ['Conexión y salida', 'Configura la identificación y los prefijos de la llamada.', ['dial_prefix','outbound_prefix','caller_id_number']],
                    ['Estrategia de marcación', 'Manual: el agente solicita el siguiente lead. Progresivo: una llamada por agente libre. Predictivo: ajusta el nivel según el abandono. Ratio: nivel fijo.', ['dial_method','dial_level','level_count_mode','max_concurrent_calls','dial_timeout_seconds']],
                    ['Ajuste predictivo', 'Límites y sensibilidad del ajuste automático para el modo predictivo.', ['adaptive_target_abandon_pct','adaptive_min_level','adaptive_max_level','adaptive_step','adaptive_min_sample']],
                    ['Contestadores y abandono', 'Define qué sucede al detectar un contestador o cuando no hay un agente disponible.', ['amd_enabled','amd_action_machine','amd_message','drop_call_seconds','drop_action','drop_message']],
                    ['Leads y atención', 'Orden de contacto, horarios locales, límite de intentos y tiempo después de la llamada.', ['lead_order','max_attempts_per_lead','wrapup_seconds','local_call_time_start','local_call_time_end']],
                ];
                $optionLabels = ['MANUAL'=>'Manual','PROGRESSIVE'=>'Progresivo','ADAPTIVE'=>'Predictivo (adaptativo)','RATIO'=>'Ratio (nivel fijo)','WAITING'=>'Agentes disponibles','NON_PAUSED'=>'Agentes sin pausa','LOGGED_IN'=>'Agentes conectados','HANGUP'=>'Colgar','MESSAGE'=>'Reproducir audio','1'=>'Sí','0'=>'No','RANK_ASC'=>'Prioridad del lead','OLDEST_FIRST'=>'Más antiguos primero','RANDOM'=>'Aleatorio','LAST_CALL_ASC'=>'Última llamada más antigua'];
                foreach ($sections as [$title,$description,$keys]): ?>
                <fieldset class="config-section"><legend><?php echo h($title); ?></legend><p class="section-help"><?php echo h($description); ?></p>
                <div class="dialer-grid">
                    <?php foreach ($keys as $key): $field=Dialer::CAMPAIGN_FIELDS[$key]; $value = (string) $campaign[$key]; ?>
                    <div class="field">
                        <label for="field-<?php echo h($key); ?>"><?php echo h($field['label']); ?></label>
                        <?php if ($field['type'] === 'select'): ?>
                            <select id="field-<?php echo h($key); ?>" name="<?php echo h($key); ?>">
                                <?php foreach ($field['options'] as $option): ?>
                                    <option value="<?php echo h($option); ?>" <?php echo $value === (string) $option ? 'selected' : ''; ?>><?php echo h($optionLabels[$option] ?? $option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: $numeric = in_array($field['type'], ['number', 'decimal'], true); ?>
                            <input type="<?php echo $numeric ? 'number' : 'text'; ?>" id="field-<?php echo h($key); ?>" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>"
                                <?php if ($numeric): ?>min="<?php echo h($field['min']); ?>" max="<?php echo h($field['max']); ?>" step="<?php echo $field['type'] === 'decimal' ? '0.01' : '1'; ?>" required<?php else: ?>maxlength="<?php echo h($field['length']); ?>"<?php endif; ?>>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                </fieldset><?php endforeach; ?>
                <div class="config-footer"><span>Guarda los cambios para aplicar la configuración.</span><button type="submit" class="btn-primary">Guardar configuración</button></div>
            </form>
        </div>

        <div class="card dialer-card">
            <h2>Listas</h2>
            <div class="table-scroll"><table class="dialer-table">
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
            </table></div>
            <form method="POST" class="create-list-form">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="create_list">
                <input type="text" name="list_name" placeholder="Nombre de la lista" maxlength="120" required style="flex:1">
                <button type="submit" class="carsa-btn">Crear lista</button>
            </form>
        </div>

        <div class="card dialer-card">
            <h2>Disposiciones</h2>
            <div class="table-scroll"><table class="dialer-table">
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
            </table></div>
            <form method="POST" class="disposition-form">
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
            <p class="section-help">Un agente puede estar asignado a varias campañas a la vez.</p>
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
