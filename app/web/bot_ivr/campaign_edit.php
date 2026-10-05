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
bot_campaign_header($campaign ? 'Campaña #'.$campaignId.' — '.$campaign['name'] : 'Campaña');
if ($campaign): ?>
<div class="carsa-card bot-form">
<h2>Configuración de campaña</h2>
<form method="post" class="carsa-form">
<input type="hidden" name="action" value="update_campaign"><?php bot_campaign_token(); ?>
<div class="carsa-field"><label>ID de campaña</label><input value="<?php echo (int)$campaignId; ?>" readonly></div>
<div class="carsa-field"><label for="campaign_name">Nombre</label><input id="campaign_name" name="name" maxlength="120" value="<?php echo h($campaign['name']); ?>" required></div>
<div><label><input type="checkbox" name="active" value="1" <?php echo $campaign['active'] ? 'checked' : ''; ?>> Activo</label></div>
<div><label><input type="checkbox" name="scheduled" value="1" <?php echo $campaign['scheduled'] ? 'checked' : ''; ?>> Habilitar horario diario</label></div>
<div class="carsa-field"><label for="start_time">Hora de activación</label><input id="start_time" type="time" name="start_time" value="<?php echo h(substr($campaign['start_time'],0,5)); ?>" required></div>
<div class="carsa-field"><label for="end_time">Hora de bloqueo</label><input id="end_time" type="time" name="end_time" value="<?php echo h(substr($campaign['end_time'],0,5)); ?>" required></div>
<button class="carsa-btn">Guardar campaña</button>
</form>
</div>
<div class="carsa-card">
<h2>Listas de la campaña</h2>
<div class="bot-table-wrap"><table class="carsa-table"><thead><tr><th>List ID</th><th>Campaign ID</th><th>Nombre</th><th>Activo</th><th>Leads</th><th>Modificar</th></tr></thead><tbody>
<?php foreach ($lists as $list): ?>
<tr><td><?php echo (int)$list['list_id']; ?></td><td><?php echo (int)$list['campaign_id']; ?></td><td><?php echo h($list['name']); ?></td><td><?php echo $list['active'] ? 'Sí' : 'No'; ?></td><td><?php echo (int)$list['leads_count']; ?></td><td>
<details><summary>Modificar</summary><form method="post" class="carsa-form">
<input type="hidden" name="action" value="update_list"><input type="hidden" name="list_id" value="<?php echo (int)$list['list_id']; ?>"><?php bot_campaign_token(); ?>
<input aria-label="Nombre de lista <?php echo (int)$list['list_id']; ?>" name="list_name" maxlength="120" value="<?php echo h($list['name']); ?>" required>
<label><input type="checkbox" name="active" value="1" <?php echo $list['active'] ? 'checked' : ''; ?>> Activo</label>
<button class="carsa-btn secondary">Guardar lista</button></form></details></td></tr>
<?php endforeach; ?>
<?php if (!$lists): ?><tr><td colspan="6">Esta campaña todavía no tiene listas.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
<div class="carsa-card bot-form"><h2>Crear y asignar lista</h2>
<form method="post" class="carsa-form"><input type="hidden" name="action" value="create_list"><?php bot_campaign_token(); ?>
<div class="carsa-field"><label for="list_name">Nombre de lista</label><input id="list_name" name="list_name" maxlength="120" required></div>
<div><label><input type="checkbox" name="active" value="1" checked> Activo</label></div>
<button class="carsa-btn">Crear lista</button></form>
</div>
<?php endif;
bot_campaign_footer();