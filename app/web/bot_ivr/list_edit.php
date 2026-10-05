<?php
declare(strict_types=1);
require __DIR__ . '/campaigns_page.php';
require __DIR__ . '/list_service.php';
$listId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$page = max(1, (int)($_GET['page'] ?? 1));
$list = null;
$leads = [];
$count = 0;
$pages = 1;
try {
    if (!$listId || !$campaignId) throw new RuntimeException('ID de lista o campaña inválido.');
    $db = carsa_db();
    $list = bot_list_get($db, $listId, $campaignId);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== 'save_db_config') {
        try {
            if (empty($_POST) && empty($_FILES)) throw new RuntimeException('La carga supera el límite de esta instalación. Usa un archivo más pequeño.');
            bot_campaign_csrf();
            if (($_POST['action'] ?? '') !== 'upload_list') throw new RuntimeException('Acción inválida o archivo mayor del límite de esta instalación.');
            $upload = $_FILES['clients_txt'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona un archivo TXT dentro del límite de subida de esta instalación.');
            if (strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)) !== 'txt') throw new RuntimeException('El archivo debe tener extensión .txt.');
            if (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) throw new RuntimeException('Archivo de carga inválido.');
            if ((int)$upload['size'] > BOT_LIST_UPLOAD_BYTES) throw new RuntimeException('El archivo supera 10 MB.');
            $contents = file_get_contents((string)$upload['tmp_name']);
            if ($contents === false) throw new RuntimeException('No se pudo leer el archivo.');
            $parsed = bot_list_parse_txt($contents);
            $result = bot_list_import($db, $listId, $campaignId, $parsed);
            $summary = 'Carga finalizada. Cargados: '.$result['saved'].'. Duplicados: '.$result['duplicates'].'. Rechazados: '.$result['rejected'].'.';
            if ($result['errors']) $summary .= ' '.implode(' · ', $result['errors']);
            bot_campaign_redirect('list_edit.php?id='.$listId.'&campaign_id='.$campaignId, $summary);
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=:id');
    $stmt->execute([':id'=>$listId]);
    $count = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($count / 50));
    $page = min($page, $pages);
    $offset = ($page - 1) * 50;
    $stmt = $db->prepare('SELECT lead_id,phone,customer_name,extra_json,created_at FROM zynervox_bot_list WHERE list_id=:id ORDER BY lead_id LIMIT 50 OFFSET '.$offset);
    $stmt->execute([':id'=>$listId]);
    $leads = $stmt->fetchAll();
} catch (Throwable $e) {
    http_response_code($listId && $campaignId ? 404 : 400);
    $error = $e->getMessage();
}
bot_campaign_header($list ? 'Lista #'.$listId.' — '.$list['name'] : 'Lista');
if ($list): ?>
<style>
.main-content{min-width:0}.list-layout{display:grid;grid-template-columns:minmax(240px,1fr) minmax(0,2fr);gap:16px;margin-top:12px}
.list-card h2{font-size:13px;margin:0 0 12px}.list-meta{margin:0}.list-meta th{width:42%;background:var(--glass);color:var(--text-muted);text-transform:none;font-weight:normal;border:1px solid var(--border)}
.list-meta td{border:1px solid var(--border)}.list-guide{padding:12px;background:var(--glass);border:1px solid var(--border);font-size:12px;line-height:1.6}
.list-guide code{color:var(--text)}.list-form-actions{display:flex;gap:10px;flex-wrap:wrap}.list-pages{display:flex;align-items:center;gap:12px;margin-top:12px;font-size:12px}
.lead-extra{max-width:400px;overflow-wrap:anywhere;white-space:pre-wrap}.list-back{color:var(--primary-hover);font-size:13px;text-decoration:none}
@media(max-width:1000px){.list-layout{grid-template-columns:minmax(0,1fr)}}
</style>
<a class="list-back" href="campaign_edit.php?id=<?php echo (int)$campaignId; ?>">← Volver a campaña #<?php echo (int)$campaignId; ?></a>
<div class="list-layout">
<section class="carsa-card list-card"><h2>Datos de la lista</h2>
<table class="carsa-table list-meta"><tbody>
<tr><th scope="row">List ID</th><td><?php echo (int)$listId; ?></td></tr>
<tr><th scope="row">Nombre</th><td><?php echo h($list['name']); ?></td></tr>
<tr><th scope="row">Campaña</th><td>#<?php echo (int)$campaignId; ?> — <?php echo h($list['campaign_name']); ?></td></tr>
<tr><th scope="row">Activo</th><td><?php echo $list['active'] ? 'Sí' : 'No'; ?></td></tr>
<tr><th scope="row">Fecha de creación</th><td><?php echo h($list['created_at']); ?></td></tr>
<tr><th scope="row">Leads</th><td><?php echo $count; ?></td></tr>
</tbody></table></section>
<section class="carsa-card list-card"><h2>Cargar base de clientes</h2>
<form method="post" enctype="multipart/form-data" class="carsa-form">
<input type="hidden" name="action" value="upload_list"><?php bot_campaign_token(); ?>
<div class="carsa-field"><label for="clients_txt">Base de clientes (TXT)</label><input id="clients_txt" type="file" name="clients_txt" accept=".txt,text/plain" required></div>
<div class="list-guide"><strong>Cabecera del TXT — formato libre</strong>
<p>De 2 a 10 columnas separadas por comas. La columna obligatoria es <code>numero</code>; las demás se conservan como variables del contacto.</p>
<p>Ejemplo: <code>numero,nombre,monto,direccion</code></p>
<p>Archivo en <strong>UTF-8</strong>. Se conservan los contactos existentes y se descartan teléfonos repetidos dentro del archivo o ya presentes en esta lista. Se informa cuántos registros se cargaron, duplicaron o rechazaron.</p>
<p>Máximo: 10 MB o el límite de esta instalación (<?php echo h(ini_get('upload_max_filesize')); ?> por archivo; <?php echo h(ini_get('post_max_size')); ?> por solicitud). Hasta 50000 registros.</p></div>
<div class="list-form-actions"><button class="carsa-btn">Cargar base</button><a class="carsa-btn secondary" href="plantilla_carga.php" download>Descargar plantilla</a></div>
</form></section>
</div>
<section class="carsa-card list-card"><h2>Leads de la lista — <?php echo $count; ?></h2>
<div class="bot-table-wrap"><table class="carsa-table"><thead><tr><th>Lead ID</th><th>Teléfono</th><th>Nombre</th><th>Variables</th><th>Fecha de carga</th></tr></thead><tbody>
<?php foreach ($leads as $lead): ?>
<tr><td><?php echo (int)$lead['lead_id']; ?></td><td><?php echo h($lead['phone']); ?></td><td><?php echo h($lead['customer_name']); ?></td><td class="lead-extra"><?php echo h($lead['extra_json'] ?? '{}'); ?></td><td><?php echo h($lead['created_at']); ?></td></tr>
<?php endforeach; ?>
<?php if (!$leads): ?><tr><td colspan="5">Esta lista todavía no tiene leads. Carga su base desde el formulario.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="list-pages"><span>Página <?php echo $page; ?> de <?php echo $pages; ?></span>
<?php if ($page > 1): ?><a href="list_edit.php?id=<?php echo (int)$listId; ?>&amp;campaign_id=<?php echo (int)$campaignId; ?>&amp;page=<?php echo $page-1; ?>">Anterior</a><?php endif; ?>
<?php if ($page < $pages): ?><a href="list_edit.php?id=<?php echo (int)$listId; ?>&amp;campaign_id=<?php echo (int)$campaignId; ?>&amp;page=<?php echo $page+1; ?>">Siguiente</a><?php endif; ?>
</div></section>
<?php endif;
bot_campaign_footer();