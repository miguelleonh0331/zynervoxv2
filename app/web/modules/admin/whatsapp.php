<?php
require_once __DIR__ . '/../../includes/Auth.php';

use Includes\Auth;

Auth::checkAccess([7, 8, 9]);
$config = [
    'WHATSAPP_BASE_PATH' => '/zynerwabav2',
    'ZYNERVOX_SSO_SECRET' => '',
    'ZYNERVOX_EMPRESA_ID' => '1',
];
$configFile = '/etc/zynervox/whatsapp.conf';
if (is_readable($configFile)) {
    foreach (file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) === 2 && array_key_exists($parts[0], $config)) $config[$parts[0]] = trim($parts[1]);
    }
}
$basePath = rtrim($config['WHATSAPP_BASE_PATH'], '/');
if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $basePath)) $basePath = '/zynerwabav2';
$claims = [
    'user' => (string)($_SESSION['user'] ?? ''),
    'name' => (string)($_SESSION['full_name'] ?? $_SESSION['user'] ?? ''),
    'level' => (int)($_SESSION['user_level'] ?? 0),
    'empresa_id' => (int)$config['ZYNERVOX_EMPRESA_ID'],
    'exp' => time() + 60,
];
$payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $payload, $config['ZYNERVOX_SSO_SECRET']);
$clientConfig = json_encode(['base' => $basePath, 'payload' => $payload, 'signature' => $signature], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .wa-shell{display:grid;gap:14px}.wa-head,.wa-card,.wa-toolbar{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:14px}.wa-head,.wa-toolbar,.wa-row,.wa-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.wa-head{justify-content:space-between}.wa-tabs button,.wa-btn{border:0;border-radius:8px;padding:9px 13px;cursor:pointer}.wa-tabs button.active,.wa-btn.primary{background:#f5821f;color:#fff}.wa-btn{background:#eceff3;color:#252936}.wa-grid{display:grid;grid-template-columns:minmax(230px,32%) 1fr;gap:12px}.wa-list{max-height:64vh;overflow:auto}.wa-item{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #eee;background:#fff;padding:11px;cursor:pointer}.wa-item:hover,.wa-item.active{background:#fff4e9}.wa-chat{min-height:56vh;display:flex;flex-direction:column}.wa-messages{flex:1;max-height:50vh;overflow:auto;padding:8px;background:#f6f7f9}.wa-message{max-width:78%;padding:9px 11px;margin:7px;border-radius:12px;background:#fff}.wa-message.out{margin-left:auto;background:#ffe3c7}.wa-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:9px}.wa-form input,.wa-form select,.wa-form textarea,.wa-select{width:100%;padding:9px;border:1px solid #ccd1d8;border-radius:8px}.wa-table{width:100%;border-collapse:collapse}.wa-table th,.wa-table td{text-align:left;padding:9px;border-bottom:1px solid #eee}.wa-muted{color:#667085;font-size:.82rem}.wa-error{color:#b42318}.wa-hidden{display:none!important}@media(max-width:850px){.wa-grid{grid-template-columns:1fr}.wa-table{display:block;overflow:auto}}
    </style>
</head>
<body>
<?php
if (($_SESSION['user_level'] ?? 0) >= 9) { require_once __DIR__ . '/sidebar.php'; renderSidebar('whatsapp'); }
else { require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('whatsapp'); }
?>
<main class="main-content"><div class="wa-shell">
    <header class="wa-head"><div><h1>WhatsApp</h1><p class="wa-muted">Operación omnicanal integrada en Zynervox</p></div><div style="min-width:220px"><select id="empresa" class="wa-select wa-hidden" aria-label="Empresa"></select><span id="identity" class="wa-muted"></span></div></header>
    <nav class="wa-toolbar wa-tabs" aria-label="Módulos WhatsApp">
        <button data-view="conversations" class="active">Conversaciones</button><button data-view="contacts">Contactos</button><button data-view="campaigns">Campañas</button><button data-view="users">Usuarios</button><button data-view="lines">Líneas</button>
    </nav>
    <div id="status" class="wa-card">Conectando de forma segura…</div>
    <section id="content" class="wa-hidden"></section>
</div></main>
<script>window.WA_CONFIG=<?php echo $clientConfig; ?>;</script>
<script src="<?php echo htmlspecialchars($basePath); ?>/socket.io/socket.io.js"></script>
<script>
const cfg=window.WA_CONFIG,statusBox=document.querySelector('#status'),content=document.querySelector('#content'),empresaSelect=document.querySelector('#empresa');
const state={user:null,empresaId:null,companies:[],contacts:[],active:null,lines:[],campaigns:[],lists:[]};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(path,options={}){const headers={'Content-Type':'application/json',...(state.empresaId?{'X-Empresa-Id':String(state.empresaId)}:{})};const r=await fetch(cfg.base+'/api'+path,{credentials:'same-origin',headers,...options});const d=await r.json().catch(()=>({}));if(!r.ok)throw new Error(d.error||'Error '+r.status);return d}
function message(text,error=false){statusBox.textContent=text;statusBox.classList.toggle('wa-error',error);statusBox.classList.remove('wa-hidden')}
function ready(){statusBox.classList.add('wa-hidden');content.classList.remove('wa-hidden')}
async function boot(){
  if(!cfg.signature)throw new Error('SSO de WhatsApp no configurado');
  const s=await fetch(cfg.base+'/api/sso/zynervox',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({payload:cfg.payload,signature:cfg.signature})});
  const sd=await s.json().catch(()=>({}));if(!s.ok)throw new Error(sd.error||'No se pudo iniciar sesión integrada');
  state.user=(await api('/me')).user;document.querySelector('#identity').textContent=state.user.display_name+' · '+state.user.role;
  if(state.user.role==='superadmin'){
    state.companies=await api('/empresas');empresaSelect.innerHTML=state.companies.map(e=>`<option value="${Number(e.id)}">${esc(e.nombre)}</option>`).join('');
    state.empresaId=Number(state.companies[0]?.id||0);empresaSelect.value=state.empresaId;empresaSelect.classList.remove('wa-hidden');empresaSelect.onchange=()=>{state.empresaId=Number(empresaSelect.value);render(activeView())};
  }else state.empresaId=Number(state.user.empresa_id||0);
  ready();await render('conversations');connectSocket();
}
function activeView(){return document.querySelector('.wa-tabs button.active').dataset.view}
document.querySelectorAll('.wa-tabs button').forEach(b=>b.onclick=()=>{document.querySelectorAll('.wa-tabs button').forEach(x=>x.classList.toggle('active',x===b));render(b.dataset.view)});
async function render(view){content.innerHTML='<div class="wa-card">Cargando…</div>';try{if(view==='conversations')await conversations();if(view==='contacts')await contacts();if(view==='campaigns')await campaigns();if(view==='users')await users();if(view==='lines')await lines()}catch(e){content.innerHTML=`<div class="wa-card wa-error">${esc(e.message)}</div>`}}
async function loadContacts(){state.contacts=await api('/contacts')}
async function conversations(){await loadContacts();content.innerHTML=`<div class="wa-grid"><div class="wa-card wa-list"><h3>Conversaciones</h3>${state.contacts.map(c=>`<button class="wa-item" onclick="openChat(${Number(c.id)})"><strong>${esc(c.name||c.phone)}</strong><div class="wa-muted">${esc(c.phone)} · ${esc(c.line_name||'')}</div></button>`).join('')||'<p class="wa-muted">Sin conversaciones.</p>'}</div><div id="chat" class="wa-card wa-chat"><p class="wa-muted">Selecciona una conversación.</p></div></div>`;if(state.active&&state.contacts.some(c=>c.id===state.active))openChat(state.active)}
window.openChat=async id=>{state.active=id;document.querySelectorAll('.wa-item').forEach((b,i)=>b.classList.toggle('active',state.contacts[i]?.id===id));const c=state.contacts.find(x=>x.id===id),rows=await api('/contacts/'+id+'/messages'),box=document.querySelector('#chat');box.innerHTML=`<h3>${esc(c.name||c.phone)}</h3><div class="wa-muted">${esc(c.phone)} · ${esc(c.line_name||'')}</div><div class="wa-messages">${rows.map(m=>`<div class="wa-message ${m.direction==='out'?'out':''}">${esc(m.body||'['+m.type+']')}<div class="wa-muted">${esc(m.user_name||'')} ${esc(m.status||'')}</div></div>`).join('')}</div><form class="wa-row" onsubmit="sendMessage(event,${id})"><input name="body" style="flex:1;padding:10px" placeholder="Escribe un mensaje" required><button class="wa-btn primary">Enviar</button></form>`;box.querySelector('.wa-messages').scrollTop=box.querySelector('.wa-messages').scrollHeight};
window.sendMessage=async(e,id)=>{e.preventDefault();const input=e.target.body;await api('/contacts/'+id+'/messages',{method:'POST',body:JSON.stringify({body:input.value})});input.value='';await openChat(id)};
async function contacts(){await loadContacts();content.innerHTML=`<div class="wa-card"><div class="wa-head"><div><h3>Contactos</h3><span class="wa-muted">${state.contacts.length} registrados</span></div><button class="wa-btn" onclick="render('conversations')">Abrir bandeja</button></div><table class="wa-table"><thead><tr><th>Nombre</th><th>Teléfono</th><th>Línea</th><th>Estado</th><th>Último mensaje</th></tr></thead><tbody>${state.contacts.map(c=>`<tr><td>${esc(c.name||'—')}</td><td>${esc(c.phone)}</td><td>${esc(c.line_name)}</td><td>${esc(c.status)}</td><td>${esc(c.last_message_at||'—')}</td></tr>`).join('')}</tbody></table></div>`}
async function getLines(){state.lines=state.user.role==='superadmin'?await api('/empresas/'+state.empresaId+'/lines'):await api('/my-lines');return state.lines}
async function lines(){await getLines();content.innerHTML=`<div class="wa-card"><h3>Líneas de WhatsApp</h3><table class="wa-table"><thead><tr><th>Nombre</th><th>Phone Number ID</th><th>Estado</th></tr></thead><tbody>${state.lines.map(l=>`<tr><td>${esc(l.name)}</td><td>${esc(l.phone_number_id)}</td><td>${l.active?'Activa':'Inactiva'}</td></tr>`).join('')}</tbody></table>${state.user.role==='superadmin'?`<form class="wa-form" onsubmit="createLine(event)"><input name="name" placeholder="Nombre" required><input name="phone_number_id" placeholder="Phone Number ID" pattern="\\d{5,20}" required><input name="color" type="color" value="#25d366"><button class="wa-btn primary">Crear línea</button></form>`:''}</div>`}
window.createLine=async e=>{e.preventDefault();await api('/empresas/'+state.empresaId+'/lines',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(e.target)))});await lines()};
async function users(){const rows=await api('/users'),ls=await getLines();content.innerHTML=`<div class="wa-card"><h3>Usuarios WhatsApp</h3><table class="wa-table"><thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${rows.map(u=>`<tr><td>${esc(u.username)}</td><td>${esc(u.display_name)}</td><td>${esc(u.role)}</td><td>${u.active?'Activo':'Bloqueado'}</td><td>${['agent','supervisor'].includes(u.role)?`<button class="wa-btn" onclick="toggleUser(${u.id},${u.active?0:1})">${u.active?'Bloquear':'Activar'}</button> <button class="wa-btn" onclick="deleteUser(${u.id})">Eliminar</button>`:'—'}</td></tr>`).join('')}</tbody></table><h4>Nuevo operador</h4><form class="wa-form" onsubmit="createUser(event)"><input name="username" pattern="[A-Za-z0-9._-]{3,40}" placeholder="Usuario" required><input name="display_name" placeholder="Nombre visible" required><input name="password" type="password" minlength="8" placeholder="Contraseña" required><select name="role"><option value="agent">Operador</option><option value="supervisor">Supervisor</option></select><select name="line_id"><option value="">Sin línea asignada</option>${ls.map(l=>`<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select><button class="wa-btn primary">Crear usuario</button></form></div>`}
window.createUser=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));b.line_ids=b.line_id?[Number(b.line_id)]:[];delete b.line_id;await api('/users',{method:'POST',body:JSON.stringify(b)});await users()};
window.toggleUser=async(id,active)=>{await api('/users/'+id,{method:'PATCH',body:JSON.stringify({active:Boolean(active)})});await users()};
window.deleteUser=async id=>{if(!confirm('¿Eliminar este usuario?'))return;await api('/users/'+id,{method:'DELETE'});await users()};
async function campaigns(){const [cs,lists,ls]=await Promise.all([api('/campaigns'),api('/broadcast-lists'),getLines()]);state.campaigns=cs;state.lists=lists;content.innerHTML=`<div class="wa-card"><h3>Campañas</h3><table class="wa-table"><thead><tr><th>Nombre</th><th>Plantilla</th><th>Idioma</th><th>Estado</th></tr></thead><tbody>${cs.map(c=>`<tr><td>${esc(c.name)}</td><td>${esc(c.template_name)}</td><td>${esc(c.template_language)}</td><td>${esc(c.status)}</td></tr>`).join('')}</tbody></table><h4>Nueva campaña</h4><form class="wa-form" onsubmit="createCampaign(event)"><input name="name" placeholder="Nombre" required><select name="line_id">${ls.map(l=>`<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select><input name="template_name" placeholder="Plantilla aprobada" required><input name="template_language" value="es" required><button class="wa-btn primary">Crear campaña</button></form><h4>Crear envío</h4><form class="wa-form" onsubmit="createBroadcast(event)"><input name="name" placeholder="Nombre del envío"><select name="campaign_id">${cs.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select><select name="list_id">${lists.map(l=>`<option value="${l.id}">${esc(l.name)} (${l.contactos})</option>`).join('')}</select><button class="wa-btn primary" ${!cs.length||!lists.length?'disabled':''}>Preparar envío</button></form><p class="wa-muted">Los envíos se preparan detenidos; su activación requiere una lista válida y plantilla aprobada.</p></div>`}
window.createCampaign=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));b.line_id=Number(b.line_id);await api('/campaigns',{method:'POST',body:JSON.stringify(b)});await campaigns()};
window.createBroadcast=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));b.campaign_id=Number(b.campaign_id);b.list_id=Number(b.list_id);await api('/broadcasts',{method:'POST',body:JSON.stringify(b)});alert('Envío preparado correctamente')};
function connectSocket(){if(typeof io!=='function')return;const socket=io({path:cfg.base+'/socket.io',transports:['websocket','polling']});socket.on('message:new',()=>{if(activeView()==='conversations')render('conversations')});socket.on('contact:refresh',()=>{if(['conversations','contacts'].includes(activeView()))render(activeView())})}
boot().catch(e=>message(e.message,true));
</script>
</body></html>
