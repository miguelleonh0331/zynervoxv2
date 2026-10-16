<?php
declare(strict_types=1);
require __DIR__.'/campaigns_page.php';
$listId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$phone = $_GET['phone'] ?? '';
$list = null;
$rows = [];
$searched = false;
try {
    if (!$listId || !$campaignId) throw new InvalidArgumentException('ID de lista o campaña inválido.');
    $repo = bot_ivr_repository();
    $list = $repo->list($listId, $campaignId);
    if (!is_string($phone) || strlen($phone) > 64 || ($phone !== '' && !preg_match('/^[0-9+() .-]+$/D', $phone))) throw new InvalidArgumentException('Introduce un teléfono válido de hasta 20 dígitos.');
    if ($phone !== '') {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (!preg_match('/^[0-9]{1,20}$/D', $phone)) throw new InvalidArgumentException('Introduce un teléfono válido de hasta 20 dígitos.');
        $rows = $repo->searchLeadsByPhone($listId, $campaignId, $phone);
        $searched = true;
    }
} catch (InvalidArgumentException $e) { http_response_code(400); $error = $e->getMessage(); }
catch (Throwable $e) { http_response_code($list === null ? 404 : 500); $error = 'No se pudieron consultar los datos de esta lista.'; }
header('Cache-Control: private, no-store');
bot_campaign_header('Buscar por teléfono', $list ? (int)$listId : null, $list ? (int)$campaignId : null);
if ($list): ?>
<p style="margin-bottom:16px"><a href="list_edit.php?id=<?= (int)$listId ?>&amp;campaign_id=<?= (int)$campaignId ?>">← Volver a la lista</a></p>
<section class="carsa-card">
<h2>Datos cargados — <?= h($list['name']) ?></h2>
<form method="get" class="carsa-form" style="max-width:520px;margin:16px 0">
<input type="hidden" name="id" value="<?= (int)$listId ?>"><input type="hidden" name="campaign_id" value="<?= (int)$campaignId ?>">
<div class="carsa-field"><label for="search-phone">Teléfono</label><input id="search-phone" name="phone" type="tel" maxlength="64" value="<?= h(is_string($phone) ? $phone : '') ?>" placeholder="Introduce el teléfono completo" required aria-describedby="search-phone-note"></div>
<p id="search-phone-note" style="margin-bottom:12px">Coincidencia exacta del número completo, incluido el prefijo si se cargó. Solo busca en esta lista.</p>
<button class="carsa-btn" type="submit">Buscar</button>
</form>
<?php if ($searched && !$rows): ?><p role="status">No se encontraron datos para este teléfono en la lista.</p><?php endif; ?>
<?php foreach ($rows as $row): ?>
<div class="bot-table-wrap" style="margin-top:16px"><table class="carsa-table"><caption style="text-align:left;padding:8px 0">Datos del contacto #<?= (int)$row['lead_id'] ?></caption><thead><tr><th scope="col">Campo</th><th scope="col">Valor</th></tr></thead><tbody>
<tr><th scope="row">Teléfono</th><td><?= h($row['phone']) ?></td></tr>
<tr><th scope="row">Nombre</th><td><?= h($row['customer_name']) ?></td></tr>
<tr><th scope="row">Estado</th><td><?= h($row['status']) ?></td></tr>
<?php $extra = json_decode((string)$row['extra_json'], true); foreach (is_array($extra) ? $extra : [] as $key=>$value): ?>
<tr><th scope="row"><?= h((string)$key) ?></th><td><?= h(is_scalar($value) || $value === null ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE)) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endforeach; ?>
</section>
<?php endif; bot_campaign_footer();
