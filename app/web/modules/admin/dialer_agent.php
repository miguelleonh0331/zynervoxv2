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
if (\Config\Config::deployment('isolated',false)) $_SESSION['dialer_entered']=true;

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$user = (string) ($_SESSION['user'] ?? '');
$msg = '';
$error = '';

if (($_GET['poll'] ?? '')==='1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(Dialer::agentPanelState($user),JSON_THROW_ON_ERROR); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialer_csrf'], $_POST['csrf_token'])) {
        $error = 'Sesión inválida, recarga la página.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            if ($action === 'bootstrap') {
                $_SESSION['dialer_entered']=true;
                Dialer::agentLogin($user);
                $msg=count(array_filter(Dialer::campaignsForAgent($user),static function($c) { return (bool)$c['active']; })) ? 'Conectado a tus campañas asignadas.' : 'No tienes campañas activas asignadas.';
            } elseif ($action === 'pause') {
                $typeId=filter_var($_POST['pause_type_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if (!$typeId) throw new RuntimeException('Selecciona un tipo de pausa.');
                Dialer::agentPauseAll($user,$typeId); $msg='Pausa aplicada a todas tus campañas.';
            } elseif ($action === 'unpause') {
                Dialer::agentPauseAll($user,null); $msg='Agente reanudado.';
            } elseif ($action === 'hangup') {
                Dialer::agentHangup($user,(int)($_POST['call_id'] ?? 0)); $msg='Solicitud de colgar enviada.';
            } elseif ($action === 'save_pause_type') {
                if ((int)$_SESSION['user_level']!==9) throw new RuntimeException('Solo el administrador gestiona tipos de pausa.');
                Dialer::savePauseType((int)($_POST['pause_type_id'] ?? 0),(string)($_POST['code'] ?? ''),(string)($_POST['label'] ?? ''),isset($_POST['active'])); $msg='Tipo de pausa guardado.';
            } elseif ($action === 'dispose') {
                Dialer::setDisposition((int) ($_POST['call_id'] ?? 0), $user, (string) ($_POST['code'] ?? ''));
                $msg = 'Llamada tipificada.';
            } elseif ($action === 'manual') {
                $callId = Dialer::originateNext($campaignId, $user);
                $msg = $callId === null ? 'Sin lead elegible, capacidad o agente disponible; no se marcó.' : 'Intento #' . $callId . ' enviado. Contesta en tu softphone para marcar al cliente y escuchar el timbrado. MANUAL no usa AMD. La aceptación no confirma que el cliente contestó.';
            } else {
                throw new RuntimeException('Acción inválida.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if (($_POST['action'] ?? '')==='bootstrap' && ($_POST['ajax'] ?? '')==='1') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if ($error!=='') http_response_code(503);
    echo json_encode(['message'=>$msg,'error'=>$error],JSON_THROW_ON_ERROR); exit;
}

try {
    $campaigns = Dialer::campaignsForAgent($user);
    $pending = Dialer::pendingDispositionForAgent($user);
    $dispositions = $pending ? Dialer::dispositions((int) $pending['campaign_id']) : [];
    $pauseTypes=Dialer::pauseTypes((int)$_SESSION['user_level']===9);
    $pause=Dialer::agentPause($user);
    $lead=Dialer::agentCurrentLead($user);
} catch (Throwable $e) {
    $campaigns = [];
    $pending = null;
    $dispositions = [];
    $pauseTypes=[]; $pause=null; $lead=null;
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
        <p id="connection-status" role="status">Conectando a tus campañas…</p>
        <div class="card" style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <span id="pause-status"><?php echo $pause ? h('Pausado: '.$pause['label']) : 'Disponible'; ?></span>
            <form method="POST" id="pause-form">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="<?php echo $pause ? 'unpause' : 'pause'; ?>">
                <select name="pause_type_id" <?php echo $pause ? 'hidden' : 'required'; ?>><option value="">Motivo de pausa…</option><?php foreach ($pauseTypes as $type): if (!$type['active']) continue; ?><option value="<?php echo (int)$type['pause_type_id']; ?>"><?php echo h($type['label']); ?></option><?php endforeach; ?></select>
                <button class="carsa-btn" type="submit"><?php echo $pause ? 'Reanudar' : 'Pausar'; ?></button>
            </form>
            <form method="POST" id="hangup-form" <?php echo !$lead || $lead['ended_at'] ? 'hidden' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="action" value="hangup"><input type="hidden" name="call_id" value="<?php echo (int)($lead['call_id'] ?? 0); ?>"><button type="submit" class="carsa-btn danger">Colgar</button>
            </form>
            <form method="POST" action="../../logout.php"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><button type="submit" class="carsa-btn">Cerrar sesión</button></form>
        </div>
        <?php if ((int)$_SESSION['user_level']===9): ?>
        <section class="card" style="margin-bottom:16px;"><h2>Tipos de pausa</h2>
        <?php foreach (array_merge($pauseTypes,[['pause_type_id'=>0,'code'=>'','label'=>'','active'=>1]]) as $type): ?>
        <form method="POST" style="display:flex;gap:8px;margin:8px 0;flex-wrap:wrap;"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="action" value="save_pause_type"><input type="hidden" name="pause_type_id" value="<?php echo (int)$type['pause_type_id']; ?>"><input name="code" value="<?php echo h($type['code']); ?>" placeholder="Código" maxlength="30" required><input name="label" value="<?php echo h($type['label']); ?>" placeholder="Nombre de pausa" maxlength="80" required><label><input type="checkbox" name="active" <?php echo $type['active'] ? 'checked' : ''; ?>> Activa</label><button type="submit" class="carsa-btn"><?php echo $type['pause_type_id'] ? 'Guardar' : 'Crear pausa'; ?></button></form>
        <?php endforeach; ?></section>
        <?php endif; ?>

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
                    <span style="color:var(--text-muted);font-size:12px;"> · cola <?php echo h($c['queue_name']); ?> · <?php echo $c['active'] ? 'activa' : 'inactiva'; ?> · <span id="agent-state-<?php echo (int)$c['campaign_id']; ?>">consultando estado</span></span>
                </div>
                <div class="actions">
                    <?php if ($c['dial_method'] === 'MANUAL' && $c['active']): ?>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="campaign_id" value="<?php echo (int) $c['campaign_id']; ?>"><input type="hidden" name="action" value="manual"><button type="submit" class="btn-primary">Marcar siguiente lead</button></form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$campaigns): ?><p>No tienes campañas asignadas. Pídele al administrador que te asigne una en "Configurar" → Agentes asignados.</p><?php endif; ?>
        </div>
    <section id="lead-card" class="card" style="padding:16px;margin-top:16px" hidden><h2>Cliente de la llamada</h2><p id="lead-summary"></p><dl id="lead-variables"></dl></section>
    </main>
    <script>
    const pendingAtRender = <?php echo json_encode($pending['call_id'] ?? null); ?>;
    const connectionStatus=document.getElementById('connection-status');
    fetch('dialer_agent.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'bootstrap',ajax:'1',csrf_token:<?php echo json_encode($_SESSION['dialer_csrf']); ?>})}).then(async response=>{const result=await response.json();connectionStatus.textContent=result.error || result.message;}).catch(()=>{connectionStatus.textContent='No se pudo conectar a las campañas. Recarga para reintentar.';});
    setInterval(async () => {
        try {
            const response = await fetch('dialer_agent.php?poll=1',{cache:'no-store'});
            if (!response.ok) return;
            const state = await response.json();
            const lead=state.lead;
            const hangup=document.getElementById('hangup-form'); hangup.hidden=!lead || !!lead.ended_at;
            hangup.elements.call_id.value=lead?.call_id ?? 0;
            const pauseForm=document.getElementById('pause-form');
            pauseForm.elements.action.value=state.pause ? 'unpause' : 'pause';
            pauseForm.elements.pause_type_id.hidden=!!state.pause; pauseForm.elements.pause_type_id.required=!state.pause;
            pauseForm.querySelector('button').textContent=state.pause ? 'Reanudar' : 'Pausar';
            document.getElementById('pause-status').textContent=state.pause ? (lead && !lead.ended_at ? 'Pausa solicitada: ' : 'Pausado: ')+state.pause.label+' · '+state.pause.duration_seconds+' s' : state.pending ? 'Tipificación pendiente' : 'Disponible';
            document.getElementById('lead-card').hidden=!lead;
            if (lead) {
                document.getElementById('lead-summary').textContent=[lead.campaign_name,lead.phone_number,lead.first_name,lead.status,lead.ended_at ? 'tipificación pendiente' : lead.bridged_at ? 'en conversación' : 'llamando'].join(' · ');
                const variables=document.getElementById('lead-variables'); variables.replaceChildren();
                for (const [key,value] of Object.entries(lead.variables)) { const label=document.createElement('dt'), content=document.createElement('dd'); label.textContent=key; content.textContent=typeof value==='object' ? JSON.stringify(value) : String(value); variables.append(label,content); }
            }
            for (const element of document.querySelectorAll('[id^="agent-state-"]')) element.textContent='fuera de cola';
            for (const session of state.sessions) {
                const element=document.getElementById('agent-state-'+session.campaign_id);
                if (element) element.textContent=session.state==='ACTIVE' ? 'en cola' : session.state==='WRAPUP' ? 'tipificación pendiente' : 'pausado';
            }
            if ((state.pending?.call_id ?? null) !== pendingAtRender && !document.querySelector('select[name="code"]')?.value && !document.querySelector('input:focus,select:focus,textarea:focus')) location.reload();
        } catch (_) { /* Next poll retries a temporary connection failure. */ }
    },3000);
    </script>
</body>
</html>
