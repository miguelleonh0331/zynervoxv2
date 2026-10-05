<?php
declare(strict_types=1);
require __DIR__ . '/campaigns_page.php';
$campaignId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$campaign = null;
$lists = [];
try {
    if ($campaignId === false || $campaignId === null) throw new RuntimeException('ID de campaña inválido.');
    $db = carsa_db();
    $campaign = bot_campaign_get($db, $campaignId);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== 'save_db_config') {
        try {
            bot_campaign_csrf();
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'update_campaign') {
                bot_campaign_update($db, $campaignId, $_POST);
            } elseif ($action === 'create_list') {
                bot_campaign_list_create($db, $campaignId, (string)($_POST['list_name'] ?? ''), isset($_POST['active']));
            } elseif ($action === 'update_list') {
                bot_campaign_list_update($db, $campaignId, (int)($_POST['list_id'] ?? 0), (string)($_POST['list_name'] ?? ''), isset($_POST['active']));
            } else {
                throw new RuntimeException('Acción inválida.');
            }
            bot_campaign_redirect('campaign_edit.php?id='.$campaignId, $action==='create_list' ? 'Lista creada y asignada a la campaña.' : 'Cambios guardados.');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $campaign = bot_campaign_get($db, $campaignId);
    $stmt = $db->prepare('SELECT l.*, (SELECT COUNT(*) FROM zynervox_bot_list d WHERE d.list_id=l.list_id) leads_count FROM zynervox_bot_lists l WHERE l.campaign_id=:id ORDER BY l.list_id');
    $stmt->execute([':id'=>$campaignId]);
    $lists = $stmt->fetchAll();
} catch (Throwable $e) {
    http_response_code($campaignId ? 404 : 400);
    $error = $e->getMessage();
}
function bot_campaign_field_row(string $key, array $campaign): void {
    $field = BOT_CAMPAIGN_FIELDS[$key];
    $value = (string)$campaign[$key];
    echo '<tr><th scope="row"><label for="field_' . h($key) . '">' . h($field['label']) . ':</label></th><td>';
    if ($field['type'] === 'select') {
        echo '<select id="field_' . h($key) . '" name="' . h($key) . '">';
        foreach ($field['options'] as $option) {
            echo '<option value="' . h($option) . '"' . ($value === $option ? ' selected' : '') . '>' . h($option) . '</option>';
        }
        echo '</select>';
    } else {
        $numeric = in_array($field['type'], ['number','decimal'], true);
        echo '<input id="field_' . h($key) . '" name="' . h($key) . '" type="' . ($numeric ? 'number' : 'text') . '" value="' . h($value) . '"';
        if ($numeric) echo ' min="' . $field['min'] . '" max="' . $field['max'] . '" step="' . ($field['type'] === 'decimal' ? '0.01' : '1') . '" required';
        else echo ' maxlength="' . $field['length'] . '"' . (!empty($field['required']) ? ' required' : '');
        echo '>';
    }
    echo '</td></tr>';
}
bot_campaign_header($campaign ? 'Campaña #'.$campaignId.' — '.$campaign['name'] : 'Campaña');
if ($campaign): ?>
<style>
.main-content{min-width:0}.campaign-detail{width:100%;max-width:none}
.campaign-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;align-items:start}
.campaign-column{min-width:0}.campaign-column h3{font-size:13px;text-align:center;margin:4px 0 8px}
.campaign-save{text-align:center;background:#cbdff9;padding:8px;margin-top:10px}
.campaign-detail .carsa-card{padding:10px;margin-bottom:16px}
.campaign-detail h2{text-align:center;margin:0 0 10px;font-size:13px}
.campaign-fields{table-layout:fixed;margin:0;width:100%;border-collapse:collapse;font-size:13px}
.campaign-fields tr:nth-child(odd){background:#dce8fa}
.campaign-fields tr:nth-child(even){background:#cbdff9}
.campaign-fields th{width:42%;padding:5px 9px;text-align:right;font-weight:normal;color:#172b45;background:transparent;border:none;text-transform:none;white-space:normal}
.campaign-fields td{padding:4px 9px;text-align:left}
.campaign-fields label{text-transform:none;font-size:13px;letter-spacing:0;font-weight:normal;color:inherit;white-space:normal}
.campaign-fields input,.campaign-fields select{display:inline-block;width:auto;max-width:100%;margin:0;padding:3px 6px;min-height:26px;background:#fff;color:#172b45;border:1px solid #aab8c9;border-radius:2px;font-size:13px}
.campaign-fields input[type=text]{width:100%;box-sizing:border-box}
.campaign-fields input[type=time]{width:135px}
.campaign-fields select{min-width:70px;max-width:100%;box-sizing:border-box}
.campaign-fields input[type=number]{width:100px;box-sizing:border-box}
.campaign-fields td{overflow-wrap:anywhere}
.campaign-detail .compact-submit{display:inline-block;width:auto;padding:5px 16px;font-size:12px}
.campaign-fields .submit-row td{text-align:center;padding:8px}
.campaign-detail .list-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
.campaign-detail .list-head h2{text-align:left;margin:0}
.campaign-detail .carsa-table{margin-top:0}
.campaign-detail .carsa-table th,.campaign-detail .carsa-table td{padding:5px 8px}
.campaign-detail details summary{cursor:pointer;color:var(--primary)}
.campaign-detail details .carsa-form{min-width:190px;max-width:260px;padding-top:8px}
.campaign-detail details input[type=checkbox]{width:auto;display:inline-block;margin:0 5px 0 0}
@media(max-width:1050px){.campaign-columns{grid-template-columns:minmax(0,1fr)}}
@media(max-width:600px){.campaign-fields th{width:125px;padding:5px}.campaign-fields td{padding:4px}.campaign-fields input[type=text]{width:100%}.campaign-detail .list-head{align-items:flex-start}}
</style>
<div class="campaign-detail">
<section class="carsa-card">
<h2>Configuración de campaña</h2>
<form method="post">
<input type="hidden" name="action" value="update_campaign"><?php bot_campaign_token(); ?>
<div class="campaign-columns">
<div class="campaign-column"><h3>Datos generales y horarios</h3>
<table class="campaign-fields"><tbody>
<tr><th scope="row">ID de campaña:</th><td><strong><?php echo (int)$campaignId; ?></strong></td></tr>
<tr><th scope="row"><label for="campaign_name">Nombre:</label></th><td><input type="text" id="campaign_name" name="name" maxlength="120" value="<?php echo h($campaign['name']); ?>" required></td></tr>
<?php bot_campaign_field_row('campaign_description', $campaign); ?>
<tr><th scope="row"><label for="campaign_active">Activo:</label></th><td><select id="campaign_active" name="active"><option value="1" <?php echo $campaign['active'] ? 'selected' : ''; ?>>Sí</option><option value="0" <?php echo !$campaign['active'] ? 'selected' : ''; ?>>No</option></select></td></tr>
<?php bot_campaign_field_row('user_group', $campaign); ?>
<tr><th scope="row">Fecha de creación:</th><td><?php echo h($campaign['created_at']); ?></td></tr>
<tr><th scope="row">Última modificación:</th><td><?php echo h($campaign['updated_at']); ?></td></tr>
<tr><th scope="row"><label for="campaign_scheduled">Habilitar horario diario:</label></th><td><select id="campaign_scheduled" name="scheduled"><option value="1" <?php echo $campaign['scheduled'] ? 'selected' : ''; ?>>Sí</option><option value="0" <?php echo !$campaign['scheduled'] ? 'selected' : ''; ?>>No</option></select></td></tr>
<tr><th scope="row"><label for="start_time">Hora de activación:</label></th><td><input id="start_time" type="time" name="start_time" value="<?php echo h(substr($campaign['start_time'],0,5)); ?>" required></td></tr>
<tr><th scope="row"><label for="end_time">Hora de bloqueo:</label></th><td><input id="end_time" type="time" name="end_time" value="<?php echo h(substr($campaign['end_time'],0,5)); ?>" required></td></tr>
<tr><th scope="row">Listas asignadas:</th><td><?php echo count($lists); ?></td></tr>
<tr><th scope="row">Total de leads:</th><td><?php echo (int)array_sum(array_column($lists,'leads_count')); ?></td></tr>
</tbody></table></div>
<div class="campaign-column"><h3>Parámetros de marcación</h3>
<table class="campaign-fields"><tbody>
<?php foreach (['dial_method','auto_dial_level','max_channels','lead_order','dial_statuses','hopper_level','dial_timeout','dial_prefix','campaign_cid','campaign_recording'] as $key) bot_campaign_field_row($key, $campaign); ?>
</tbody></table></div>
</div>
<div class="campaign-save"><button class="carsa-btn compact-submit">Guardar campaña</button></div>
</form>
</section>
<section class="carsa-card">
<div class="list-head"><h2>Listas de la campaña</h2><button type="button" class="carsa-btn compact-submit" onclick="document.getElementById('createListModal').showModal()">Crear lista</button></div>
<div class="bot-table-wrap"><table class="carsa-table"><thead><tr><th>List ID</th><th>Campaign ID</th><th>Nombre</th><th>Leads</th><th>Activo</th><th>Modificar</th></tr></thead><tbody>
<?php foreach ($lists as $list): ?>
<tr><td><?php echo (int)$list['list_id']; ?></td><td><?php echo (int)$list['campaign_id']; ?></td><td><?php echo h($list['name']); ?></td><td><?php echo (int)$list['leads_count']; ?></td><td><?php echo $list['active'] ? 'Sí' : 'No'; ?></td><td>
<details><summary>Modificar</summary><form method="post" class="carsa-form">
<input type="hidden" name="action" value="update_list"><input type="hidden" name="list_id" value="<?php echo (int)$list['list_id']; ?>"><?php bot_campaign_token(); ?>
<input aria-label="Nombre de lista <?php echo (int)$list['list_id']; ?>" name="list_name" maxlength="120" value="<?php echo h($list['name']); ?>" required>
<label><input type="checkbox" name="active" value="1" <?php echo $list['active'] ? 'checked' : ''; ?>> Activo</label>
<button class="carsa-btn secondary compact-submit">Guardar lista</button></form></details></td></tr>
<?php endforeach; ?>
<?php if (!$lists): ?><tr><td colspan="6">Esta campaña todavía no tiene listas.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
</div>
<dialog id="createListModal" aria-labelledby="createListTitle">
<h2 id="createListTitle">Crear y asignar lista</h2>
<form method="post" class="carsa-form"><input type="hidden" name="action" value="create_list"><?php bot_campaign_token(); ?>
<div class="carsa-field"><label for="list_name">Nombre de lista</label><input id="list_name" name="list_name" maxlength="120" required></div>
<label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="active" value="1" checked style="width:auto;margin:0"> Activo</label>
<div class="carsa-actions"><button class="carsa-btn">Crear lista</button><button type="button" class="carsa-btn secondary" onclick="document.getElementById('createListModal').close()">Cancelar</button></div>
</form>
</dialog>
<?php endif;
bot_campaign_footer();
