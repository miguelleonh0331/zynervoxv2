<?php
declare(strict_types=1);
require __DIR__ . '/campaigns_page.php';
$creating = ($_GET['view'] ?? '') === 'create';
$inactive = ($_GET['tab'] ?? '') === 'inactive';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'deactivate_campaign') {
    try {
        bot_campaign_csrf();
        $id = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || $id === null) throw new RuntimeException('ID de campaña inválido.');
        bot_ivr_repository()->deactivateCampaign($id);
        bot_campaign_redirect('index.php?tab=inactive', 'Campaña desactivada. Sus listas y su historial se conservan.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$name = '';
$active = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create_campaign') {
    $creating = true;
    $name = (string)($_POST['name'] ?? '');
    $active = isset($_POST['active']);
    try {
        bot_campaign_csrf();
        $id = bot_campaign_create(bot_ivr_repository(), $name, $active);
        bot_campaign_redirect('campaign_edit.php?id='.$id, 'Campaña creada. Ahora puedes asignarle listas.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$campaigns = [];
if (!$creating) {
    try {
        $campaigns = array_values(array_filter(bot_ivr_repository()->campaigns(), static function(array $campaign) use ($inactive): bool {
            return (bool)$campaign['active'] === !$inactive;
        }));
    } catch (Throwable $e) { if ($error === '') $error = $e->getMessage(); }
}
bot_campaign_header($creating ? 'Crear campaña' : 'Campañas');
if ($creating): ?>
<div class="carsa-card bot-form">
<form method="post" class="carsa-form">
  <input type="hidden" name="action" value="create_campaign"><?php bot_campaign_token(); ?>
  <div class="carsa-field"><label>ID de campaña</label><input value="Automático al guardar" readonly></div>
  <div class="carsa-field"><label for="campaign_name">Nombre</label><input id="campaign_name" name="name" maxlength="120" value="<?php echo h($name); ?>" required></div>
  <div><label><input type="checkbox" name="active" value="1" <?php echo $active ? 'checked' : ''; ?>> Activo</label></div>
  <div class="carsa-actions"><button class="carsa-btn">Crear campaña</button><a class="carsa-btn secondary" href="index.php">Cancelar</a></div>
</form>
</div>
<?php else: ?>
<div class="carsa-card">
<style>
.bot-campaign-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.bot-campaign-tabs a{padding:10px 16px;border:1px solid var(--border);text-decoration:none;color:var(--text);border-radius:3px;font-weight:600}
.bot-campaign-tabs a[aria-current="page"]{background:var(--primary);color:#fff;border-color:var(--primary)}
.bot-campaign-actions{display:flex;align-items:center;gap:12px;white-space:nowrap}.bot-campaign-actions form{margin:0}
.bot-campaign-actions .bot-deactivate{background:#b45309;color:#fff}.bot-campaign-actions .bot-deactivate:hover{background:#92400e}
</style>
<nav class="bot-campaign-tabs" aria-label="Estado de campañas">
<a href="index.php" <?php echo !$inactive ? 'aria-current="page"' : ''; ?>>Campañas activas</a>
<a href="index.php?tab=inactive" <?php echo $inactive ? 'aria-current="page"' : ''; ?>>Campañas inactivas</a>
</nav>
<h2><?php echo $inactive ? 'Campañas inactivas' : 'Campañas activas'; ?></h2>
<div class="bot-table-wrap"><table class="carsa-table">
<thead><tr><th>ID campaña</th><th>Nombre</th><th>Activo</th><th>Listas</th><th>Activación</th><th>Bloqueo</th><th>Horario habilitado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach ($campaigns as $c): ?>
<tr><td><?php echo (int)$c['campaign_id']; ?></td><td><?php echo h($c['name']); ?></td><td><?php echo $c['active'] ? 'Sí' : 'No'; ?></td><td><?php echo (int)$c['lists_count']; ?></td><td><?php echo h(substr($c['start_time'],0,5)); ?></td><td><?php echo h(substr($c['end_time'],0,5)); ?></td><td><?php echo $c['scheduled'] ? 'Sí' : 'No'; ?></td><td><div class="bot-campaign-actions"><a href="campaign_edit.php?id=<?php echo (int)$c['campaign_id']; ?>">Modificar</a>
<?php if ($c['active']): ?>
<form method="post" onsubmit="return confirm('¿Desactivar esta campaña? Sus listas, contactos e historial se conservarán.');">
<input type="hidden" name="action" value="deactivate_campaign">
<input type="hidden" name="campaign_id" value="<?php echo (int)$c['campaign_id']; ?>"><?php bot_campaign_token(); ?>
<button type="submit" class="carsa-btn bot-deactivate" aria-label="<?php echo h('Desactivar campaña '.$c['name']); ?>">Desactivar</button>
</form>
<?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
<?php if (!$campaigns): ?><tr><td colspan="8">No hay campañas <?php echo $inactive ? 'inactivas' : 'activas'; ?>.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
<?php endif;
bot_campaign_footer();
