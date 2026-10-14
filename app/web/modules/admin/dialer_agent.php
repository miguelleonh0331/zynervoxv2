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
$agentOnly = (int)$_SESSION['user_level'] < 7;
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
            } elseif ($action === 'select_campaign') {
                Dialer::selectManualCampaign($user,$campaignId); $msg='Campaña seleccionada; continuación anterior cancelada.';
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

if (in_array($_POST['action'] ?? '',['bootstrap','select_campaign'],true) && ($_POST['ajax'] ?? '')==='1') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if ($error!=='') http_response_code(503);
    echo json_encode(['message'=>$msg,'error'=>$error,'autodial'=>Dialer::agentAutodialState($user)],JSON_THROW_ON_ERROR); exit;
}

try {
    $campaigns = Dialer::campaignsForAgent($user);
    $lead = Dialer::agentCurrentLead($user);
    $dispositions = $lead ? Dialer::dispositions((int) $lead['campaign_id']) : [];
    $pauseTypes=Dialer::pauseTypes((int)$_SESSION['user_level']===9);
    $pause=Dialer::agentPause($user);
    $autodial=Dialer::agentAutodialState($user);
} catch (Throwable $e) {
    $campaigns = [];
    $lead=null;
    $dispositions = [];
    $pauseTypes=[]; $pause=null; $autodial=[];
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
        .agent-workspace{min-width:0;width:calc(100% - 260px);padding:20px;line-height:1.4;background:#e8eaed}
        .agent-only .agent-workspace{margin-left:0;width:100%}
        .agent-workspace .header-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        .agent-workspace .agent-tabs{display:flex;gap:6px;border-bottom:2px solid #465569;margin-bottom:14px;align-items:stretch}
        .agent-workspace .agent-tabs a,.agent-workspace .agent-tabs button{display:flex;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:10px 22px;font-size:14px;font-weight:700;border:1px solid #9ba7b6;border-bottom:0;border-radius:4px 4px 0 0;text-decoration:none}
        .agent-workspace .agent-tabs a{background:#465569;color:#fff;border-color:#465569}
        .agent-workspace .agent-tabs a:focus-visible{outline:3px solid #2563eb;outline-offset:2px}
        .agent-workspace .agent-tabs button:disabled{background:#dce1e6;color:#4b5563;cursor:not-allowed;opacity:1}
        .agent-workspace .agent-tabs .tab-note{font-size:11px;font-weight:400}
        .agent-workspace [hidden]{display:none!important}
        .agent-workspace .top-bar{background:#303b4b;color:#fff;padding:16px 20px;border:1px solid #1c2736;margin-bottom:12px;gap:16px;flex-wrap:wrap}
        .agent-workspace h1{font-size:22px;line-height:1.2}
        .agent-workspace .subtitle{font-size:13px;color:#e2e8f0;margin-top:4px}
        .agent-workspace .user-badge{background:#fff;color:#243247;border:1px solid #cbd5e1;padding:8px 12px;font-size:13px;font-weight:700}
        .agent-workspace .console-grid{display:grid;grid-template-columns:230px minmax(0,1fr);gap:14px;align-items:start;margin-bottom:14px}
        .agent-workspace .card{padding:16px;border:1px solid #9ba7b6;border-radius:3px;margin-bottom:14px;background:#fff}
        .agent-workspace .console-grid>.card{margin:0}
        .agent-workspace .card h2{font-size:14px;letter-spacing:.3px;text-transform:uppercase;padding:10px 12px;margin:-16px -16px 16px;background:#d8e7f5;border-bottom:1px solid #93aac2;color:#183b59}
        .agent-workspace .controls-card{background:#dde3e9}
        .agent-workspace .controls-card h2{background:#465569;color:#fff;border-color:#303b4b}
        .agent-workspace .status-caption{font-size:12px;font-weight:700;margin-bottom:6px;color:#334155}
        .agent-workspace .status-badge{display:flex;align-items:center;gap:8px;border:1px solid #6ca883;background:#bcebc9;color:#14532d;padding:10px;font-size:14px;font-weight:700;margin-bottom:16px}
        .agent-workspace .status-badge:before{content:'';width:9px;height:9px;flex-shrink:0;border-radius:50%;background:currentColor}
        .agent-workspace .status-badge[data-state="paused"],.agent-workspace .status-badge[data-state="pending"]{background:#ffe39b;border-color:#c29838;color:#713f12}
        .agent-workspace .status-badge[data-state="calling"]{background:#bfdbfe;border-color:#6c9ad0;color:#1e3a8a}
        .agent-workspace .control-actions{display:grid;gap:18px}
        .agent-workspace .control-actions form{display:grid;gap:8px}
        .agent-workspace label{font-size:12px;color:#334155;text-transform:none;letter-spacing:0}
        .agent-workspace select,.agent-workspace input:not([type="hidden"]):not([type="checkbox"]){min-height:42px;margin:0;border:1px solid #8494a7;border-radius:3px;padding:9px;font-size:14px;background:#fff}
        .agent-workspace button{min-height:44px;padding:10px 14px;border-radius:3px;font-size:14px;font-weight:700;cursor:pointer;border:1px solid #8494a7;line-height:1.3}
        .agent-workspace .btn-primary{background:#ffc06b;color:#542c00;border-color:#be791e}
        .agent-workspace .btn-primary:hover{background:#ffac3f}
        .agent-workspace .btn-secondary{background:#ffe39b;color:#713f12;border-color:#bd9231}
        .agent-workspace .btn-secondary:hover{background:#ffd56c}
        .agent-workspace #pause-form[data-paused="true"] button{background:#bcebc9;color:#14532d;border-color:#6ca883}
        .agent-workspace .btn-danger{background:#ee9f9f;color:#711515;border-color:#bf6464}
        .agent-workspace .btn-danger:hover{background:#ef8282}
        .agent-workspace button:focus-visible,.agent-workspace select:focus-visible,.agent-workspace input:focus-visible{outline:3px solid #2563eb;outline-offset:2px}
        .agent-workspace .notice{padding:12px;margin-bottom:12px;font-size:14px;background:#bcebc9;color:#14532d;border:1px solid #6ca883}
        .agent-workspace .notice-error{background:#ffd1d1;color:#711515;border-color:#bf6464}
        .agent-workspace #connection-status{color:#334155;font-size:13px;margin-bottom:12px}
        .agent-workspace #connection-status[data-error="true"]{color:#991b1b}
        .agent-workspace .customer-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:16px}
        .agent-workspace .customer-fields dt{font-size:12px;font-weight:700;color:#334155;margin-bottom:5px}
        .agent-workspace .customer-fields dd{background:#f4f7fa;border:1px solid #a7b5c4;min-height:42px;padding:10px;font-size:14px;overflow-wrap:anywhere}
        .agent-workspace .empty-customer{background:#edf3f8;border:1px dashed #93aac2;padding:24px;color:#475569;text-align:center}
        .agent-workspace #lead-summary{font-size:13px;color:#334155;margin-bottom:16px;overflow-wrap:anywhere}
        .agent-workspace #lead-variables{display:grid;grid-template-columns:minmax(100px,1fr) 2fr;gap:8px;font-size:14px}
        .agent-workspace #lead-variables dt{font-weight:700;background:#e2e8f0;padding:8px;color:#334155;overflow-wrap:anywhere}
        .agent-workspace #lead-variables dd{border:1px solid #cbd5e1;padding:8px;overflow-wrap:anywhere}
        .agent-workspace .section-description{color:#475569;font-size:13px;margin-bottom:12px}
        .agent-workspace .campaign-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}
        .agent-workspace .agent-camp{padding:14px;background:#edf3f8;border:1px solid #a7b5c4;border-left:5px solid #4685b5}
        .agent-workspace .campaign-name{display:block;font-size:16px;margin-bottom:8px;overflow-wrap:anywhere}
        .agent-workspace .campaign-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:#334155}
        .agent-workspace .campaign-tag{background:#c7e1f7;border:1px solid #93aac2;padding:3px 6px;font-weight:700}
        .agent-workspace .disposition-card{background:#fff7df;border-color:#c29838}
        .agent-workspace .disposition-card h2{background:#ffe39b;color:#713f12;border-color:#c29838}
        .agent-workspace .disposition-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}
        .agent-workspace .disposition-form select{flex:1;min-width:180px}
        .agent-workspace .pause-type-row{display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap}
        .agent-workspace .pause-type-row input:not([type="hidden"]):not([type="checkbox"]){flex:1;min-width:160px;width:auto}
        .agent-workspace .pause-type-row input[type="checkbox"]{width:auto;margin:0;accent-color:#166534}
        .agent-workspace .pause-type-row label{display:flex;align-items:center;gap:6px}
        @media(max-width:1000px){.agent-workspace .console-grid{grid-template-columns:200px minmax(0,1fr)}.agent-workspace .customer-fields{grid-template-columns:1fr}}
        @media(max-width:750px){.agent-page{flex-direction:column}.agent-page .sidebar{position:static;width:100%;height:auto}.agent-workspace{margin-left:0;width:100%;padding:12px}.agent-workspace .console-grid{grid-template-columns:1fr}.agent-workspace .customer-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:420px){.agent-workspace .customer-fields{grid-template-columns:1fr}}
    </style>
</head>
<body class="agent-page<?php echo $agentOnly ? ' agent-only' : ''; ?>">
    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('dialer_agent');
    } elseif (!$agentOnly) {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('dialer_agent');
    }
    ?>
    <main class="main-content agent-workspace">
        <header class="top-bar">
            <div>
                <h1>Panel de agente</h1>
                <p class="subtitle">Tus campañas y llamadas en un solo lugar.</p>
            </div>
            <div class="header-actions">
                <span class="user-badge">Agente <?php echo h($user); ?></span>
                <?php if ($agentOnly): ?>
                <form method="POST" action="../../logout.php"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><button type="submit" class="btn-danger">Cerrar sesión</button></form>
                <?php endif; ?>
            </div>
        </header>
        <?php if ($agentOnly): ?>
        <nav class="agent-tabs" aria-label="Áreas del agente">
            <a href="dialer_agent.php" aria-current="page">CRM</a>
            <button type="button" disabled aria-disabled="true" title="El acceso a WhatsApp se habilitará próximamente">WhatsApp <span class="tab-note">Próximamente</span></button>
        </nav>
        <?php endif; ?>

        <?php if ($msg !== ''): ?><p class="notice" role="status"> <?php echo h($msg); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="notice notice-error" role="alert"> <?php echo h($error); ?></p><?php endif; ?>
        <p id="connection-status" role="status">Conectando a tus campañas…</p>
        <div class="console-grid">
        <section class="card controls-card" aria-label="Estado y controles del agente">
            <h2>Control de llamada</h2>
            <div><p class="status-caption">Estado del agente</p><span id="pause-status" class="status-badge" role="status" data-state="<?php echo $pause ? 'paused' : 'available'; ?>"><?php echo $pause ? h('Pausado: '.$pause['label']) : 'Disponible'; ?></span></div>
            <div class="control-actions">
            <?php $manualCampaigns=array_filter($campaigns,static function($c) { return $c['active'] && $c['dial_method']==='MANUAL'; }); if ($manualCampaigns): ?>
            <form method="POST" id="manual-form">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="action" value="manual">
                <label for="manual-campaign">Campaña para marcar</label>
                <select id="manual-campaign" name="campaign_id" required><option value="">Selecciona una campaña</option><?php foreach ($manualCampaigns as $c): ?><option value="<?php echo (int)$c['campaign_id']; ?>" <?php echo (int)($autodial['selected_campaign_id'] ?? 0)===(int)$c['campaign_id'] ? 'selected' : ''; ?>><?php echo h($c['name']); ?></option><?php endforeach; ?></select>
                <p id="autodial-status" class="section-description" role="status"></p>
                <button type="submit" class="btn-primary">Marcar siguiente lead</button>
            </form>
            <?php endif; ?>
            <form method="POST" id="pause-form" data-paused="<?php echo $pause ? 'true' : 'false'; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="<?php echo $pause ? 'unpause' : 'pause'; ?>">
                <label for="pause-type">Motivo de pausa</label><select id="pause-type" name="pause_type_id" <?php echo $pause ? 'hidden' : 'required'; ?>><option value="">Motivo de pausa…</option><?php foreach ($pauseTypes as $type): if (!$type['active']) continue; ?><option value="<?php echo (int)$type['pause_type_id']; ?>"><?php echo h($type['label']); ?></option><?php endforeach; ?></select>
                <button class="btn-secondary" type="submit"><?php echo $pause ? 'Reanudar' : 'Pausar'; ?></button>
            </form>
            <form method="POST" id="hangup-form" <?php echo !$lead || $lead['ended_at'] ? 'hidden' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="action" value="hangup"><input type="hidden" name="call_id" value="<?php echo (int)($lead['call_id'] ?? 0); ?>"><button type="submit" class="btn-danger">Colgar</button>
            </form>
            </div>
        </section>
        <section id="lead-card" class="card customer-card" aria-label="Información del cliente">
            <h2>Información del cliente</h2>
            <div id="empty-customer" class="empty-customer">Sin llamada seleccionada. Los datos del cliente aparecerán aquí al iniciar una llamada.</div>
            <div id="customer-content" hidden>
                <dl class="customer-fields">
                    <div><dt>Teléfono</dt><dd id="customer-phone">—</dd></div>
                    <div><dt>Nombre</dt><dd id="customer-name">—</dd></div>
                    <div><dt>Campaña</dt><dd id="customer-campaign">—</dd></div>
                    <div><dt>Estado de llamada</dt><dd id="customer-state">—</dd></div>
                </dl>
                <p id="lead-summary"></p><dl id="lead-variables"></dl>
            </div>
        </section>
        </div>


        <div class="card disposition-card" id="disposition-card" <?php echo $lead ? '' : 'hidden'; ?>>
            <h2>Tipificar llamada</h2>
            <p id="disposition-summary"><?php if ($lead): ?>Intento #<?php echo (int)$lead['call_id']; ?> · Campaña <strong><?php echo h($lead['campaign_name']); ?></strong> · Teléfono <?php echo h($lead['phone_number']); ?> · <?php echo h($lead['originated_at']); ?><?php endif; ?></p>
            <p id="disposition-done-note" <?php echo ($lead && $lead['disposition_code'] !== null) ? '' : 'hidden'; ?>>Tipificación guardada. Cuelga para continuar con la siguiente llamada.</p>
            <form method="POST" class="disposition-form" id="disposition-form" data-call-id="<?php echo (int) ($lead['call_id'] ?? 0); ?>" <?php echo ($lead && $lead['disposition_code'] !== null) ? 'hidden' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="hidden" name="action" value="dispose">
                <input type="hidden" name="call_id" value="<?php echo (int) ($lead['call_id'] ?? 0); ?>">
                <select name="code" aria-label="Resultado de la llamada" required>
                    <option value="">Selecciona disposición...</option>
                    <?php foreach ($dispositions as $d): ?>
                        <option value="<?php echo h($d['code']); ?>"><?php echo h($d['code'] . ' — ' . $d['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary">Guardar</button>
            </form>
        </div>

        <div class="card">
            <h2>Mis campañas</h2>
            <p class="section-description">Las campañas asignadas se conectan automáticamente. Selecciona la campaña manual en Control de llamada para marcar.</p>
            <div class="campaign-list">
            <?php foreach ($campaigns as $c): ?>
            <div class="agent-camp">
                <div>
                    <strong class="campaign-name"><?php echo h($c['name']); ?></strong>
                    <div class="campaign-meta"><span class="campaign-tag"><?php echo h($c['dial_method']); ?></span><span><?php echo $c['active'] ? 'Activa' : 'Inactiva'; ?></span><span aria-hidden="true">·</span><span id="agent-state-<?php echo (int)$c['campaign_id']; ?>">Consultando estado</span></div>
                </div>

            </div>
            <?php endforeach; ?>
            </div>
            <?php if (!$campaigns): ?><p>No tienes campañas asignadas. Pídele al administrador que te asigne una en "Configurar" → Agentes asignados.</p><?php endif; ?>
        </div>
        <?php if ((int)$_SESSION['user_level']===9): ?>
        <section class="card"><h2>Tipos de pausa</h2><p class="section-description">Gestiona los motivos disponibles para tus agentes.</p>
        <?php foreach (array_merge($pauseTypes,[['pause_type_id'=>0,'code'=>'','label'=>'','active'=>1]]) as $type): ?>
        <form method="POST" class="pause-type-row"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>"><input type="hidden" name="action" value="save_pause_type"><input type="hidden" name="pause_type_id" value="<?php echo (int)$type['pause_type_id']; ?>"><input aria-label="Código de pausa" name="code" value="<?php echo h($type['code']); ?>" placeholder="Código" maxlength="30" required><input aria-label="Nombre de pausa" name="label" value="<?php echo h($type['label']); ?>" placeholder="Nombre de pausa" maxlength="80" required><label><input type="checkbox" name="active" <?php echo $type['active'] ? 'checked' : ''; ?>> Activa</label><button type="submit" class="btn-secondary"><?php echo $type['pause_type_id'] ? 'Guardar' : 'Crear pausa'; ?></button></form>
        <?php endforeach; ?></section>
        <?php endif; ?>
    </main>
    <script>
    const connectionStatus=document.getElementById('connection-status');
    fetch('dialer_agent.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'bootstrap',ajax:'1',csrf_token:<?php echo json_encode($_SESSION['dialer_csrf']); ?>})}).then(async response=>{const result=await response.json();connectionStatus.textContent=result.error || result.message;connectionStatus.dataset.error=String(!!result.error);}).catch(()=>{connectionStatus.dataset.error='true';connectionStatus.textContent='No se pudo conectar a las campañas. Recarga para reintentar.';});
    const manualForm=document.getElementById('manual-form');
    let selectingCampaign=false;
    let autodialState=<?php echo json_encode($autodial); ?>;
    let continuationDeadline=null;
    function renderAutodial(state) {
        autodialState=state;
        if (!manualForm) return;
        const select=manualForm.elements.campaign_id;
        if (!selectingCampaign && document.activeElement!==select) select.value=state.selected_campaign_id ?? '';
        continuationDeadline=state.pending ? Date.now()+Math.max(0,state.seconds_until ?? 0)*1000 : null;
        updateAutodialText();
    }
    function updateAutodialText() {
        if (!manualForm) return;
        document.getElementById('autodial-status').textContent=selectingCampaign ? 'Guardando selección…' : continuationDeadline!==null ? 'Siguiente llamada en '+Math.max(0,Math.ceil((continuationDeadline-Date.now())/1000))+' s…' : !autodialState.selected_campaign_id ? 'Selecciona la campaña para trabajar.' : autodialState.enabled ? 'Autodial activado: marca la primera llamada; después continuará al tipificar.'+(autodialState.running ? '' : ' Motor detenido.') : 'Autodial desactivado: marca cada llamada con el botón.';
    }
    renderAutodial(autodialState);
    setInterval(updateAutodialText,250);
    manualForm?.elements.campaign_id.addEventListener('change',async () => {
        const select=manualForm.elements.campaign_id;
        const previous=autodialState.selected_campaign_id ?? '';
        if (!select.value) { select.value=previous; return; }
        selectingCampaign=true; select.disabled=true; manualForm.querySelector('button').disabled=true; updateAutodialText();
        try {
            const response=await fetch('dialer_agent.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'select_campaign',ajax:'1',csrf_token:manualForm.elements.csrf_token.value,campaign_id:select.value})});
            const result=await response.json();
            if (!response.ok || result.error) throw new Error(result.error || 'No se guardó la campaña.');
            selectingCampaign=false; renderAutodial(result.autodial);
            connectionStatus.textContent=result.message; connectionStatus.dataset.error='false';
        } catch (error) {
            select.value=previous; connectionStatus.textContent=error.message; connectionStatus.dataset.error='true';
        } finally { selectingCampaign=false; select.disabled=false; manualForm.querySelector('button').disabled=false; updateAutodialText(); }
    });
    setInterval(async () => {
        try {
            const response = await fetch('dialer_agent.php?poll=1',{cache:'no-store'});
            if (!response.ok) return;
            const state = await response.json();
            if (!selectingCampaign) renderAutodial(state.autodial);
            const lead=state.lead;
            const hangup=document.getElementById('hangup-form'); hangup.hidden=!lead || !!lead.ended_at;
            hangup.elements.call_id.value=lead?.call_id ?? 0;
            const pauseForm=document.getElementById('pause-form');
            pauseForm.dataset.paused=String(!!state.pause);
            pauseForm.elements.action.value=state.pause ? 'unpause' : 'pause';
            pauseForm.elements.pause_type_id.hidden=!!state.pause; pauseForm.elements.pause_type_id.required=!state.pause;
            pauseForm.querySelector('button').textContent=state.pause ? 'Reanudar' : 'Pausar';
            const pauseStatus=document.getElementById('pause-status');
            pauseStatus.dataset.state=state.pause ? 'paused' : state.pending ? 'pending' : lead && !lead.ended_at ? 'calling' : 'available';
            pauseStatus.textContent=state.pause ? (lead && !lead.ended_at ? 'Pausa solicitada: ' : 'Pausado: ')+state.pause.label+' · '+state.pause.duration_seconds+' s' : state.pending ? 'Tipificación pendiente' : lead && !lead.ended_at ? (lead.bridged_at ? 'En conversación' : 'Llamando') : 'Disponible';
            document.getElementById('empty-customer').hidden=!!lead;
            document.getElementById('customer-content').hidden=!lead;
            if (lead) {
                document.getElementById('customer-phone').textContent=lead.phone_number || '—';
                document.getElementById('customer-name').textContent=lead.first_name || '—';
                document.getElementById('customer-campaign').textContent=lead.campaign_name || '—';
                document.getElementById('customer-state').textContent=lead.ended_at ? 'Tipificación pendiente' : lead.bridged_at ? 'En conversación' : 'Llamando';
                document.getElementById('lead-summary').textContent=[lead.campaign_name,lead.phone_number,lead.first_name,lead.status,lead.ended_at ? 'tipificación pendiente' : lead.bridged_at ? 'en conversación' : 'llamando'].join(' · ');
                const variables=document.getElementById('lead-variables'); variables.replaceChildren();
                for (const [key,value] of Object.entries(lead.variables)) { const label=document.createElement('dt'), content=document.createElement('dd'); label.textContent=key; content.textContent=typeof value==='object' ? JSON.stringify(value) : String(value); variables.append(label,content); }
            }
            for (const element of document.querySelectorAll('[id^="agent-state-"]')) element.textContent='fuera de cola';
            for (const session of state.sessions) {
                const element=document.getElementById('agent-state-'+session.campaign_id);
                if (element) element.textContent=session.state==='ACTIVE' ? 'en cola' : session.state==='WRAPUP' ? 'tipificación pendiente' : 'pausado';
            }
            const dispositionCard=document.getElementById('disposition-card');
            const dispositionForm=document.getElementById('disposition-form');
            const dispositionFocused=dispositionForm.contains(document.activeElement);
            dispositionCard.hidden=!lead;
            if (lead && !dispositionFocused) {
                document.getElementById('disposition-summary').textContent='Intento #'+lead.call_id+' · Campaña '+(lead.campaign_name||'—')+' · Teléfono '+(lead.phone_number||'—')+' · '+(lead.originated_at||'');
                dispositionForm.elements.call_id.value=lead.call_id;
                const callIdStr=String(lead.call_id);
                if (dispositionForm.dataset.callId!==callIdStr) {
                    dispositionForm.dataset.callId=callIdStr;
                    const select=dispositionForm.elements.code;
                    select.replaceChildren(new Option('Selecciona disposición...',''));
                    for (const d of (state.dispositions||[])) select.add(new Option(d.code+' — '+d.label,d.code));
                    select.value='';
                }
                const alreadyDisposed=!!lead.disposition_code;
                dispositionForm.hidden=alreadyDisposed;
                document.getElementById('disposition-done-note').hidden=!alreadyDisposed;
            }
        } catch (_) { /* Next poll retries a temporary connection failure. */ }
    },3000);
    </script>
</body>
</html>
