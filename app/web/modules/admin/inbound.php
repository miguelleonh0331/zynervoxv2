<?php
// Administración de DIDs de entrada. Un DID apunta a una lista; la campaña de
// esa lista aporta la cola donde Queue() reparte y el árbol de tipificación.
//
// Lo que esta página NO hace, a propósito: no escribe dialplan ni troncales.
// El contexto de entrada que invoca a dialer_inbound_agi.php lo escribe el
// operador en Asterisk. Aquí solo se declara a dónde va cada número.
require_once __DIR__.'/../../includes/Auth.php';
require_once __DIR__.'/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;
Auth::checkAccess(9);
if (!\Config\Config::deployment('isolated',false)) { http_response_code(409); exit('Inbound requiere despliegue v2 aislado.'); }
$_SESSION['inbound_csrf']=$_SESSION['inbound_csrf'] ?? bin2hex(random_bytes(32));
$message=''; $error=''; $edit=null; $rows=[]; $listas=[];
function inbound_h($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['inbound_csrf'],$_POST['csrf_token'])) { http_response_code(403); exit('CSRF inválido'); }
        if (isset($_POST['delete_did'])) {
            if (!is_string($_POST['did'] ?? null) || $_POST['did']==='') throw new InvalidArgumentException('DID inválido.');
            Dialer::deleteInboundDid($_POST['did']); $message='DID eliminado.';
        } elseif (isset($_POST['save_did'])) {
            foreach (['did','name','original_did'] as $field) if (!is_string($_POST[$field] ?? null)) throw new InvalidArgumentException('Campos inválidos.');
            $listId=filter_var($_POST['list_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$listId) throw new InvalidArgumentException('Elige la lista de destino.');
            Dialer::saveInboundDid($_POST['did'],$listId,$_POST['name'],($_POST['active'] ?? 'Y')==='Y',$_POST['original_did']);
            $edit=Dialer::inboundDid($_POST['did']);
            $message='DID guardado. Falta que el contexto de entrada en Asterisk lo envíe al AGI.';
        } else throw new InvalidArgumentException('Acción inválida.');
    } elseif (isset($_GET['edit_did'])) {
        if (!is_string($_GET['edit_did']) || !($edit=Dialer::inboundDid($_GET['edit_did']))) throw new InvalidArgumentException('El DID no existe.');
    }
} catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
catch (RuntimeException $e) { $error=$e->getMessage(); }
catch (Throwable $e) { $error='No se pudo guardar o cargar el DID. Revisa la conexión y permisos.'; }
try { $rows=Dialer::inboundDids(); $listas=Dialer::inboundListChoices(); }
catch (Throwable $e) { $error=$error!=='' ? $error : 'No se pudieron cargar los DIDs.'; }
$showForm=$edit!==null || ($_GET['new'] ?? '')==='1' || isset($_POST['save_did']);
$d=$edit ?? ['did'=>'','list_id'=>'','name'=>'','active'=>1];
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Inbound (Entrantes / DIDs) - Zynervox</title><link rel="stylesheet" href="layout.css">
<style>
body,.main-content{font-family:Arial,Helvetica,sans-serif}.main-content{min-width:0}.inbound-table{width:100%;border-collapse:collapse;font-size:12px}.inbound-table th,.inbound-table td{padding:7px 9px;border:1px solid var(--border);text-align:left}.inbound-table th{background:var(--dark,#2d2f3b);color:white}.inbound-table tbody tr:nth-child(even){background:var(--glass)}.inbound-fields{display:grid;grid-template-columns:1fr 1fr 2fr 1fr;gap:16px}.inbound-actions{display:flex;gap:12px;align-items:center}.inbound-actions form{margin:0}.inbound-link{border:0;background:none;color:var(--primary);text-decoration:underline;cursor:pointer;padding:0}.inbound-error{color:#b91c1c}.inbound-success{color:#047857}.inbound-note{font-size:12px;color:var(--muted,#555)}@media(max-width:900px){.inbound-fields{grid-template-columns:1fr}}
</style></head><body>
<?php require_once __DIR__.'/sidebar.php'; renderSidebar('inbound'); ?>
<main class="main-content">
<header class="top-bar"><h1>Inbound (Entrantes / DIDs)</h1></header>
<?php if ($message!==''): ?><p class="inbound-success" role="status"><?php echo inbound_h($message); ?></p><?php endif; ?>
<?php if ($error!==''): ?><p class="inbound-error" role="alert"><?php echo inbound_h($error); ?></p><?php endif; ?>
<div class="card">
<div class="inbound-actions" style="justify-content:space-between;"><h2>DIDs de entrada</h2><a href="inbound.php?new=1">+ Nuevo DID</a></div>
<table class="inbound-table"><thead><tr><th>DID</th><th>Nombre</th><th>Lista destino</th><th>Campaña</th><th>Cola</th><th>Activo</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<td><?php echo inbound_h($row['did']); ?></td>
<td><?php echo inbound_h($row['name']); ?></td>
<td><?php echo inbound_h($row['list_name']); ?><?php if (!$row['list_active']): ?> <span class="inbound-error">(lista inactiva)</span><?php endif; ?></td>
<td><?php echo inbound_h($row['campaign_name']); ?><?php if (!$row['campaign_active']): ?> <span class="inbound-error">(campaña inactiva)</span><?php endif; ?></td>
<td><?php echo inbound_h($row['queue_name']); ?></td>
<td><?php echo $row['active']?'Sí':'No'; ?></td>
<td><div class="inbound-actions"><a href="?edit_did=<?php echo urlencode($row['did']); ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este DID?');"><input type="hidden" name="csrf_token" value="<?php echo inbound_h($_SESSION['inbound_csrf']); ?>"><input type="hidden" name="did" value="<?php echo inbound_h($row['did']); ?>"><button class="inbound-link inbound-error" name="delete_did">Eliminar</button></form></div></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7">Sin DIDs declarados.</td></tr><?php endif; ?>
</tbody></table>
<p class="inbound-note">Una entrante reutiliza el lead del teléfono si ya existe, aunque viva en otra lista. Solo si es un número nuevo se crea el lead, y aterriza en la lista de este DID con estado <code>INBOUND</code>, que el marcador saliente no toma.</p>
</div>
<?php if ($showForm): ?>
<div class="card"><h2><?php echo $d['did']!==''?'Editar DID':'Nuevo DID'; ?></h2>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo inbound_h($_SESSION['inbound_csrf']); ?>"><input type="hidden" name="original_did" value="<?php echo inbound_h($d['did']); ?>">
<div class="inbound-fields">
<div class="field"><label for="inbound_did">DID</label><input id="inbound_did" name="did" maxlength="20" required pattern="\+?[0-9]{1,20}" value="<?php echo inbound_h($d['did']); ?>"></div>
<div class="field"><label for="inbound_active">Activo</label><select id="inbound_active" name="active"><option value="Y"<?php echo $d['active']?' selected':''; ?>>Sí</option><option value="N"<?php echo $d['active']?'':' selected'; ?>>No</option></select></div>
<div class="field"><label for="inbound_list">Lista destino</label><select id="inbound_list" name="list_id" required>
<option value="">— Elige la lista —</option>
<?php foreach ($listas as $l): ?><option value="<?php echo (int)$l['list_id']; ?>"<?php echo (string)$l['list_id']===(string)$d['list_id']?' selected':''; ?>><?php echo inbound_h($l['campaign_name'].' › '.$l['name'].' ('.$l['queue_name'].')'); ?></option><?php endforeach; ?>
</select></div>
<div class="field"><label for="inbound_name">Nombre</label><input id="inbound_name" name="name" maxlength="120" value="<?php echo inbound_h($d['name']); ?>"></div>
</div>
<button class="btn-primary" name="save_did">Guardar DID</button>
</form>
<p class="inbound-note">La cola sale de la campaña de la lista elegida: no se declara aparte. Los agentes entran a esa cola al conectarse, y un agente puede estar a la vez en una campaña saliente y en esta.</p>
</div>
<?php endif; ?>
<div class="card"><h2>Lo que falta fuera de esta pantalla</h2>
<p class="inbound-note">Declarar el DID aquí no hace que entren llamadas. Hace falta, en Asterisk y fuera de esta aplicación: una troncal de entrada registrada con el proveedor, y un contexto que atienda la llamada y la pase al AGI
<code>/srv/www/htdocs/zynervoxv2/modules/admin/dialer_inbound_agi.php</code> con la acción <code>start</code>, el DID y el número que llama. El detalle del contrato está en <code>docs/INBOUND.md</code>.</p></div>
</main></body></html>
