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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        .agent-workspace{min-width:0;max-width:1600px;width:calc(100% - 260px);padding:32px;line-height:1.5}
        .agent-workspace [hidden]{display:none!important}
        .agent-workspace .top-bar{gap:16px;margin-bottom:24px}
        .agent-workspace h1{font-size:26px;letter-spacing:-.6px;line-height:1.2}
        .agent-workspace .subtitle{color:var(--text-muted);font-size:14px;margin-top:6px}
        .agent-workspace .user-badge{background:white;border:1px solid var(--border);padding:8px 14px;border-radius:24px;white-space:nowrap;font-size:13px;font-weight:600}
        .agent-workspace .card{padding:24px;border-radius:12px;margin-bottom:20px}
        .agent-workspace .card h2{font-size:17px;margin-bottom:8px}
        .agent-workspace .section-description{color:var(--text-muted);font-size:13px;margin-bottom:20px}
        .agent-workspace .controls-card{display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap}
        .agent-workspace .status-caption{font-size:12px;color:var(--text-muted);margin-bottom:8px}
        .agent-workspace .status-badge{display:inline-flex;align-items:center;gap:9px;background:#ecfdf5;color:#166534;border-radius:24px;padding:8px 14px;font-size:14px;font-weight:600}
        .agent-workspace .status-badge:before{content:'';width:8px;height:8px;flex-shrink:0;border-radius:50%;background:currentColor}
        .agent-workspace .status-badge[data-state="paused"],.agent-workspace .status-badge[data-state="pending"]{background:#fff7ed;color:#9a3412}
        .agent-workspace .status-badge[data-state="calling"]{background:#eff6ff;color:#1d4ed8}
        .agent-workspace .control-actions,.agent-workspace #pause-form{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        .agent-workspace select,.agent-workspace input:not([type="hidden"]):not([type="checkbox"]){min-height:44px;margin:0;border-radius:8px;padding:10px 12px;font-size:14px}
        .agent-workspace #pause-form select{width:220px;max-width:100%}
        .agent-workspace button{min-height:44px;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:1px solid transparent;line-height:1.4}
        .agent-workspace .btn-primary{color:#2D2F3B}
        .agent-workspace .btn-secondary{background:#fff;border-color:#cbd5e1;color:var(--text)}
        .agent-workspace .btn-secondary:hover{background:#f8fafc;border-color:#94a3b8}
        .agent-workspace .btn-danger{background:#b91c1c;color:#fff}
        .agent-workspace .btn-danger:hover{background:#991b1b}
        .agent-workspace button:focus-visible,.agent-workspace select:focus-visible,.agent-workspace input:focus-visible{outline:3px solid #2563eb;outline-offset:3px}
        .agent-workspace .notice{border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
        .agent-workspace .notice-error{background:#fef2f2;color:#991b1b;border-color:#fecaca}
        .agent-workspace #connection-status{color:var(--text-muted);font-size:13px;margin-bottom:16px}
        .agent-workspace #connection-status[data-error="true"]{color:#991b1b}
        .agent-workspace .agent-camp{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:20px;border:1px solid var(--border);border-radius:10px;margin-top:12px}
        .agent-workspace .campaign-name{display:block;font-size:17px;margin-bottom:8px;overflow-wrap:anywhere}
        .agent-workspace .campaign-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:var(--text-muted);font-size:12px}
        .agent-workspace .campaign-tag{border-radius:5px;background:#f1f5f9;color:#475569;padding:3px 8px;font-weight:600}
        .agent-workspace .agent-camp .actions{flex-shrink:0}
        .agent-workspace .pause-type-row{display:flex;gap:12px;align-items:center;margin-top:12px;flex-wrap:wrap}
        .agent-workspace .pause-type-row input:not([type="hidden"]):not([type="checkbox"]){flex:1;min-width:160px;width:auto}
        .agent-workspace .pause-type-row input[type="checkbox"]{width:auto;margin:0;accent-color:var(--primary)}
        .agent-workspace .pause-type-row label{display:flex;align-items:center;gap:6px;text-transform:none;font-size:13px}
        .agent-workspace #lead-summary{margin-bottom:16px;overflow-wrap:anywhere}
        .agent-workspace #lead-variables{display:grid;grid-template-columns:minmax(100px,1fr) 2fr;gap:8px 16px;font-size:14px}
        .agent-workspace #lead-variables dt{font-weight:600;color:var(--text-muted)}
        .agent-workspace #lead-variables dd{overflow-wrap:anywhere}
        .agent-workspace .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        @media(max-width:1050px){.agent-workspace{padding:24px}.agent-workspace .agent-camp{align-items:flex-start;flex-direction:column}}
        @media(max-width:700px){.agent-page{flex-direction:column}.agent-page .sidebar{position:static;width:100%;height:auto}.agent-workspace{margin-left:0;width:100%;padding:20px}.agent-workspace .card{padding:18px}.agent-workspace .top-bar{align-items:flex-start;flex-wrap:wrap}.agent-workspace .control-actions,.agent-workspace #pause-form{width:100%}.agent-workspace #pause-form select{flex:1;min-width:160px;width:auto}.agent-workspace .agent-camp .actions,.agent-workspace .agent-camp .actions button{width:100%}}
    </style>
</head>
<body class="agent-page">
    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('dialer_agent');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('dialer_agent');
    }
    ?>
    <main class="main-content agent-workspace">
        <header class="top-bar">
            <div>
                <h1>Panel de agente</h1>
                <p class="subtitle">Tus campañas y llamadas en un solo lugar.</p>
            </div>
            <span class="user-badge">Agente <?php echo h($user); ?></span>
        </header>

        <?php if ($msg !== ''): ?><p class="notice" role="status"> <?php echo h($msg); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="notice notice-error" role="alert"> <?php echo h($error); ?></p><?php endif; ?>
        <p id="connection-status" role="status">Conectando a tus campañas…</p>
        <section class="card controls-card" aria-label="Estado y controles del agente">
            <div><p class="status-caption">Estado del agente</p><span id="pause-status" class="status-badge" role="status" data-state="<?php echo $pause ? 'paused' : 'available'; ?>"><?php echo $pause ? h('Pausado: '.$pause['label']) : 'Disponible'; ?></span></div>
            <div class="control-actions">
            <form method="POST" id="pause-form">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="<?php echo $pause ? 'unpause' : 'pause'; ?>">
                <label for="pause-type" class="sr-only">Motivo de pausa</label><select id="pause-type" name="pause_type_id" <?php echo $pause ? 'hidden' : 'required'; ?>><option value="">Motivo de pausa…</option><?php foreach ($pauseTypes as $type): if (!$type['active']) continue; ?><option value="<?php echo (int)$type['pause_type_id']; ?>"><?php echo h($type['label']); ?></option><?php endforeach; ?></select>
                <button class="btn-secondary" type="submit"><?php echo $pause ? 'Reanudar' : 'Pausar'; ?></button>
            </form>
            <form method="POST" id="hangup-form" <?php echo !$lead || $lead['ended_at'] ? 'hidden' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="action" value="hangup"><input type="hidden" name="call_id" value="<?php echo (int)($lead['call_id'] ?? 0); ?>"><button type="submit" class="btn-danger">Colgar</button>
            </form>
            </div>
        </section>
        <?php if ((int)$_SESSION['user_level']===9): ?>
        <section class="card"><h2>Tipos de pausa</h2><p class="section-description">Gestiona los motivos disponibles para tus agentes.</p>
        <?php foreach (array_merge($pauseTypes,[['pause_type_id'=>0,'code'=>'','label'=>'','active'=>1]]) as $type): ?>
        <form method="POST" class="pause-type-row"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="action" value="save_pause_type"><input type="hidden" name="pause_type_id" value="<?php echo (int)$type['pause_type_id']; ?>"><input aria-label="Código de pausa" name="code" value="<?php echo h($type['code']); ?>" placeholder="Código" maxlength="30" required><input aria-label="Nombre de pausa" name="label" value="<?php echo h($type['label']); ?>" placeholder="Nombre de pausa" maxlength="80" required><label><input type="checkbox" name="active" <?php echo $type['active'] ? 'checked' : ''; ?>> Activa</label><button type="submit" class="btn-secondary"><?php echo $type['pause_type_id'] ? 'Guardar' : 'Crear pausa'; ?></button></form>
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
            <p class="section-description">Las campañas asignadas se conectan automáticamente. En modo manual, inicia cada llamada desde aquí.</p>
            <?php foreach ($campaigns as $c): ?>
            <div class="agent-camp">
                <div>
                    <strong class="campaign-name"><?php echo h($c['name']); ?></strong>
                    <div class="campaign-meta"><span class="campaign-tag"><?php echo h($c['dial_method']); ?></span><span><?php echo $c['active'] ? 'Activa' : 'Inactiva'; ?></span><span aria-hidden="true">·</span><span id="agent-state-<?php echo (int)$c['campaign_id']; ?>">Consultando estado</span></div>
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
    fetch('dialer_agent.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'bootstrap',ajax:'1',csrf_token:<?php echo json_encode($_SESSION['dialer_csrf']); ?>})}).then(async response=>{const result=await response.json();connectionStatus.textContent=result.error || result.message;connectionStatus.dataset.error=String(!!result.error);}).catch(()=>{connectionStatus.dataset.error='true';connectionStatus.textContent='No se pudo conectar a las campañas. Recarga para reintentar.';});
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
            const pauseStatus=document.getElementById('pause-status');
            pauseStatus.dataset.state=state.pause ? 'paused' : state.pending ? 'pending' : lead && !lead.ended_at ? 'calling' : 'available';
            pauseStatus.textContent=state.pause ? (lead && !lead.ended_at ? 'Pausa solicitada: ' : 'Pausado: ')+state.pause.label+' · '+state.pause.duration_seconds+' s' : state.pending ? 'Tipificación pendiente' : lead && !lead.ended_at ? (lead.bridged_at ? 'En conversación' : 'Llamando') : 'Disponible';
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
