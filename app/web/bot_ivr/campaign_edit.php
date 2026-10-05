<?php
declare(strict_types=1);
// Migrado desde modules/initial_survey/campaign_edit.php.
// NOTA rutas: bot_ivr/ vive UN nivel bajo la raiz de Zynervox -- ver
// comentario equivalente en index.php.
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/page.php';
require __DIR__ . '/campaign_runtime.php';
initial_survey_require_login();

$baseDir = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
$campaignId = (int) ($_GET['id'] ?? $_POST['campaign_id'] ?? 0);
if ($campaignId <= 0) {
    http_response_code(400);
    exit('Campaña inválida.');
}

function ce_name(string $value): string {
    $value = preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', trim($value)) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_substr(trim($value), 0, 80, 'UTF-8');
}
function ce_phone(string $value): string {
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) === 11 && substr($digits, 0, 2) === '51') $digits = substr($digits, 2);
    if (strlen($digits) > 9) $digits = substr($digits, -9);
    if (strlen($digits) !== 9) throw new RuntimeException('El número debe tener 9 dígitos.');
    return $digits;
}
function ce_text(string $value, int $limit = 200): string {
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value)) ?? '';
    return mb_substr($value, 0, $limit, 'UTF-8');
}
function ce_campaign($db, int $id, bool $lock = false): array {
    $sql = 'SELECT id,name,flow_code,status,scheduled,start_time,end_time,created_at FROM synervox_campaigns WHERE id=:id';
    if ($lock) $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->execute([':id'=>$id]);
    $campaign = $stmt->fetch();
    if (!$campaign) throw new RuntimeException('Campaña inexistente.');
    return $campaign;
}
function ce_require_editable(array $campaign): void {
    if ((string) $campaign['status'] === 'running') {
        throw new RuntimeException('No se puede editar mientras la campaña está ejecutándose.');
    }
}
function ce_extra(array $input): array {
    $clean = [];
    foreach ($input as $key => $value) {
        $key = strtolower(trim((string) $key));
        if (!preg_match('/^[a-z0-9_]{1,60}$/', $key)) continue;
        $clean[$key] = ce_text((string) $value, 200);
    }
    return $clean;
}

$message = '';
$error = '';
try {
    $db = carsa_db();
    $campaign = ce_campaign($db, $campaignId);
} catch (Throwable $e) {
    http_response_code(404);
    exit(h($e->getMessage()));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        $campaign = ce_campaign($db, $campaignId);
        ce_require_editable($campaign);

        if ($action === 'update_campaign') {
            $name = ce_name((string) ($_POST['name'] ?? ''));
            $flowCode = trim((string) ($_POST['flow_code'] ?? ''));
            if ($name === '') throw new RuntimeException('El nombre es obligatorio.');
            if (!preg_match('/^\d{2}$/', $flowCode)) throw new RuntimeException('Selecciona un flujo válido.');
            $stmt = $db->prepare('UPDATE synervox_campaigns SET name=:name,flow_code=:flow WHERE id=:id');
            $stmt->execute([':name'=>$name, ':flow'=>$flowCode, ':id'=>$campaignId]);
            $message = 'Campaña actualizada.';
        } elseif ($action === 'add_client') {
            $phone = ce_phone((string) ($_POST['phone'] ?? ''));
            $dup = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE campaign_id=:cid AND phone=:phone');
            $dup->execute([':cid'=>$campaignId, ':phone'=>$phone]);
            if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('Ese número ya existe en la campaña.');
            $name = ce_name((string) ($_POST['customer_name'] ?? ''));
            $amount = ce_text((string) ($_POST['amount'] ?? ''), 20);
            $address = ce_text((string) ($_POST['store_address'] ?? ''), 255);
            // NOTA: placeholders nombrados repetidos (:amount/:address dos veces
            // cada uno) fallan con "Invalid parameter number" cuando
            // ATTR_EMULATE_PREPARES esta en false (bug latente heredado del
            // original, ver mismo patron ya corregido en Leads::search()).
            $stmt = $db->prepare(
                'INSERT INTO carsa_initial_survey
                 (batch_id,campaign_id,phone,customer_name,amount_raw,amount,store_address_raw,store_address,status,audio_status,extra_json)
                 VALUES (:batch,:cid,:phone,:name,:amount1,:amount2,:address1,:address2,"pending","ready","{}")'
            );
            $stmt->execute([':batch'=>'camp_'.$campaignId.'_manual', ':cid'=>$campaignId, ':phone'=>$phone,
                ':name'=>$name, ':amount1'=>$amount, ':amount2'=>$amount, ':address1'=>$address, ':address2'=>$address]);
            $db->prepare('UPDATE synervox_campaigns SET status="loaded" WHERE id=:id')->execute([':id'=>$campaignId]);
            $message = 'Cliente agregado.';
        } elseif ($action === 'update_client') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            $phone = ce_phone((string) ($_POST['phone'] ?? ''));
            $dup = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE campaign_id=:cid AND phone=:phone AND id<>:id');
            $dup->execute([':cid'=>$campaignId, ':phone'=>$phone, ':id'=>$queueId]);
            if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('Ese número ya existe en la campaña.');
            $amount = ce_text((string) ($_POST['amount'] ?? ''), 20);
            $address = ce_text((string) ($_POST['store_address'] ?? ''), 255);
            $extra = ce_extra(is_array($_POST['extra'] ?? null) ? $_POST['extra'] : []);
            // Mismo fix de placeholders unicos que en add_client (arriba).
            $stmt = $db->prepare(
                'UPDATE carsa_initial_survey SET phone=:phone,customer_name=:name,
                 amount_raw=:amount1,amount=:amount2,store_address_raw=:address1,store_address=:address2,extra_json=:extra
                 WHERE id=:id AND campaign_id=:cid'
            );
            $stmt->execute([':phone'=>$phone, ':name'=>ce_name((string) ($_POST['customer_name'] ?? '')),
                ':amount1'=>$amount, ':amount2'=>$amount, ':address1'=>$address, ':address2'=>$address,
                ':extra'=>json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id'=>$queueId, ':cid'=>$campaignId]);
            if ($stmt->rowCount() === 0) {
                $owned = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE id=:id AND campaign_id=:cid');
                $owned->execute([':id'=>$queueId, ':cid'=>$campaignId]);
                if ((int) $owned->fetchColumn() !== 1) throw new RuntimeException('Cliente inexistente.');
            }
            $message = 'Cliente actualizado.';
        } elseif ($action === 'delete_client') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            $find = $db->prepare('SELECT phone FROM carsa_initial_survey WHERE campaign_id=:cid AND id=:qid');
            $find->execute([':cid'=>$campaignId, ':qid'=>$queueId]);
            $deletePhone = (string) $find->fetchColumn();
            if ($deletePhone === '') throw new RuntimeException('Cliente inexistente.');
            $db->beginTransaction();
            try {
                $results = $db->prepare(
                    'DELETE FROM ivr_call_results WHERE campaign_id=:cid AND queue_id IN
                     (SELECT id FROM carsa_initial_survey WHERE campaign_id=:cid2 AND phone=:phone)'
                );
                $results->execute([':cid'=>$campaignId, ':cid2'=>$campaignId, ':phone'=>$deletePhone]);
                $stmt = $db->prepare('DELETE FROM carsa_initial_survey WHERE campaign_id=:cid AND phone=:phone');
                $stmt->execute([':cid'=>$campaignId, ':phone'=>$deletePhone]);
                if ($stmt->rowCount() < 1) throw new RuntimeException('Cliente inexistente.');
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            $message = 'Cliente eliminado completamente de la campaña.';
        } elseif ($action === 'reactivate_campaign') {
            if(campaign_process_active($db,$campaignId,'dialer')) throw new RuntimeException('La campaña ya se está ejecutando.');
            $active = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE campaign_id=:id AND status IN ("pending","calling","reserved")');
            $active->execute([':id'=>$campaignId]);
            if ((int) $active->fetchColumn() > 0) throw new RuntimeException('La campaña ya tiene clientes pendientes o activos.');
            $stmt = $db->prepare(
                'INSERT INTO carsa_initial_survey
                 (batch_id,phone,customer_name,amount_raw,amount,store_address_raw,store_address,status,audio_status,last_error,
                  assigned_agent,reserved_at,started_at,completed_at,campaign_id,extra_json)
                 SELECT :batch,q.phone,q.customer_name,q.amount_raw,q.amount,q.store_address_raw,q.store_address,
                        "pending","ready",NULL,NULL,NULL,NULL,NULL,q.campaign_id,q.extra_json
                 FROM carsa_initial_survey q
                 INNER JOIN (SELECT phone,MAX(id) latest_id FROM carsa_initial_survey WHERE campaign_id=:latest GROUP BY phone) x ON x.latest_id=q.id
                 WHERE q.campaign_id=:source'
            );
            $stmt->execute([':batch'=>'react_'.$campaignId.'_'.date('Ymd_His'), ':latest'=>$campaignId, ':source'=>$campaignId]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('No hay clientes para reactivar.');
            $db->prepare('UPDATE synervox_campaigns SET status="loaded" WHERE id=:id')->execute([':id'=>$campaignId]);
            $message = 'Campaña reactivada con '.$stmt->rowCount().' clientes pendientes.';
        }
        $campaign = ce_campaign($db, $campaignId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$clients = [];
try {
    $stmt = $db->prepare(
        'SELECT q.id,q.phone,q.customer_name,q.amount_raw,q.store_address_raw,q.status,q.extra_json
         FROM carsa_initial_survey q
         INNER JOIN (SELECT phone,MAX(id) latest_id FROM carsa_initial_survey WHERE campaign_id=:latest GROUP BY phone) x ON x.latest_id=q.id
         WHERE q.campaign_id=:cid ORDER BY q.id DESC'
    );
    $stmt->execute([':latest'=>$campaignId, ':cid'=>$campaignId]);
    $clients = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}

initial_survey_page_start('Bot IVR', 'Campaña #' . $campaignId);
?>
<style>
.ce-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
.ce-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.ce-client{margin-bottom:10px;padding:10px;border:1px solid var(--border);border-radius:2px;background:var(--glass)}
.ce-client-grid{display:grid;grid-template-columns:1fr 1.2fr .8fr 1.5fr;gap:8px}
.ce-extra{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:8px}
.ce-actions{display:flex;align-items:center;gap:8px;margin-top:10px}
.ce-state{margin-left:auto;color:var(--text-muted);font-size:.72rem}
@media(max-width:850px){.ce-grid,.ce-client-grid,.ce-extra{grid-template-columns:1fr}}
</style>
<div class="ce-head"><div><a class="carsa-btn secondary" href="index.php">← Campañas</a><h1 style="margin:12px 0 0">Campaña #<?php echo $campaignId; ?></h1></div><span class="muted"><?php echo h($campaign['status']); ?></span></div>
<?php if ($message !== ''): ?><div class="carsa-msg"><?php echo h($message); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="carsa-err"><?php echo h($error); ?></div><?php endif; ?>

<div class="carsa-card" style="margin-bottom:16px">
  <h2>Configuración</h2>
  <form method="post" class="carsa-form">
    <input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>"><input type="hidden" name="action" value="update_campaign">
    <div class="ce-grid"><div class="carsa-field"><label>Nombre</label><input name="name" value="<?php echo h($campaign['name']); ?>" required></div><div class="carsa-field"><label>Flujo IVR</label><select name="flow_code" id="flowCode"><option value="<?php echo h($campaign['flow_code']); ?>"><?php echo h($campaign['flow_code']); ?></option></select></div></div>
    <div class="carsa-actions"><button class="carsa-btn">Guardar campaña</button></div>
  </form>
  <form method="post" onsubmit="return confirm('¿Reactivar esta campaña y crear nuevos intentos para todos sus clientes?')" style="margin-top:10px">
    <input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>"><input type="hidden" name="action" value="reactivate_campaign"><button class="carsa-btn secondary">Reactivar campaña</button>
  </form>
</div>

<div class="carsa-card" style="margin-bottom:16px"><h2>Agregar cliente</h2><form method="post" class="ce-client-grid"><input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>"><input type="hidden" name="action" value="add_client"><input name="phone" inputmode="numeric" placeholder="Número" required><input name="customer_name" placeholder="Nombre"><input name="amount" placeholder="Monto"><input name="store_address" placeholder="Dirección"><div class="carsa-actions"><button class="carsa-btn">Agregar</button></div></form></div>

<div class="carsa-card"><h2>Clientes (<?php echo count($clients); ?>)</h2>
<?php if (!$clients): ?><p class="muted">La campaña no tiene clientes.</p><?php endif; ?>
<?php foreach ($clients as $client): $extra=json_decode((string)($client['extra_json'] ?? ''),true); if(!is_array($extra))$extra=[]; ?>
  <div class="ce-client"><form method="post"><input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>"><input type="hidden" name="queue_id" value="<?php echo (int)$client['id']; ?>"><input type="hidden" name="action" value="update_client">
    <div class="ce-client-grid"><div class="carsa-field"><label>Número</label><input name="phone" inputmode="numeric" value="<?php echo h($client['phone']); ?>" required></div><div class="carsa-field"><label>Nombre</label><input name="customer_name" value="<?php echo h($client['customer_name']); ?>"></div><div class="carsa-field"><label>Monto</label><input name="amount" value="<?php echo h((string)$client['amount_raw']); ?>"></div><div class="carsa-field"><label>Dirección</label><input name="store_address" value="<?php echo h((string)$client['store_address_raw']); ?>"></div></div>
    <?php if($extra): ?><div class="ce-extra"><?php foreach($extra as $key=>$value): ?><div class="carsa-field"><label><?php echo h((string)$key); ?></label><input name="extra[<?php echo h((string)$key); ?>]" value="<?php echo h((string)$value); ?>"></div><?php endforeach; ?></div><?php endif; ?>
    <div class="ce-actions"><button class="carsa-btn">Guardar cliente</button><span class="ce-state"><?php echo h($client['status']); ?></span></div>
  </form><form method="post" onsubmit="return confirm('¿Eliminar este cliente y sus resultados de esta campaña?')" style="margin-top:8px"><input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>"><input type="hidden" name="queue_id" value="<?php echo (int)$client['id']; ?>"><input type="hidden" name="action" value="delete_client"><button class="carsa-btn secondary">Eliminar cliente</button></form></div>
<?php endforeach; ?></div>
<script>
(async function(){const s=document.getElementById('flowCode'),current=<?php echo json_encode((string)$campaign['flow_code']); ?>;try{const r=await fetch('../ivr_builder/api_flows.php',{cache:'no-store'}),d=await r.json(),flows=d&&d.flows?d.flows:[];s.innerHTML=flows.map(f=>{const o=document.createElement('option');o.value=f.code;o.textContent=f.code+' - '+(f.name||'');o.selected=String(f.code)===String(current);return o.outerHTML}).join('')||'<option value="'+current+'">'+current+'</option>'}catch(e){}})();
</script>
<?php initial_survey_page_end(); ?>

