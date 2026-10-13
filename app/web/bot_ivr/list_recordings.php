<?php
declare(strict_types=1);
require __DIR__.'/campaigns_page.php';
require_once __DIR__.'/call_recordings.php';
$listId=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$campaignId=filter_var($_GET['campaign_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$page=filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$list=null; $rows=[]; $total=0; $pages=1;
try {
    if (!$listId || !$campaignId || !$page) throw new InvalidArgumentException('Lista, campaña o página inválida.');
    $repo=bot_ivr_repository();
    $list=$repo->list($listId,$campaignId);
    $total=$repo->leadCount($listId,true);
    $pages=max(1,(int)ceil($total/50));
    $page=min($page,$pages);
    $rows=$repo->latestLeadCalls($listId,$campaignId,50,($page-1)*50,null,true);
} catch (InvalidArgumentException $e) { http_response_code(400); $error=$e->getMessage(); }
catch (Throwable $e) { http_response_code($list===null ? 404 : 500); $error='No se pudieron cargar las grabaciones de esta lista.'; }
header('Cache-Control: private, no-store');
bot_campaign_header('Últimas llamadas — lista #'.($listId ?: ''));
if ($list):
$query='id='.(int)$listId.'&campaign_id='.(int)$campaignId;
?>
<style>.lead-recording-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}.lead-recording-table audio{width:260px;max-width:100%}.lead-recording-table td{vertical-align:middle}.lead-recording-note{font-size:12px;color:var(--text-muted)}.lead-recording-pages{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:16px}</style>
<div class="lead-recording-actions"><a href="list_edit.php?<?php echo h($query); ?>">← Volver a la lista</a><a class="carsa-btn secondary" href="list_recordings.php?<?php echo h($query.'&page='.$page); ?>">Actualizar</a></div>
<section class="carsa-card">
<h2><?php echo h($list['name']); ?> — última llamada por lead</h2>
<p class="lead-recording-note">Solo leads con gestión (estado distinto de NEW), una fila por lead. Si su última llamada no tiene grabación, aparece «Sin audio».</p>
<div class="bot-table-wrap"><table class="carsa-table lead-recording-table"><thead><tr><th>Lead</th><th>Nombre</th><th>Número</th><th>Tipificación</th><th>Última llamada (UTC)</th><th>Audio</th></tr></thead><tbody>
<?php foreach ($rows as $row): $audio=bot_lead_recording_path($row); ?>
<tr><td><?php echo (int)$row['lead_id']; ?></td><td><?php echo h($row['customer_name']); ?></td><td><?php echo h($row['phone']); ?></td><td><?php echo h($row['status']); ?></td><td><?php echo h($row['started_at'] ?? 'Sin llamadas'); ?></td><td>
<?php if ($audio!==null): ?>
<audio controls preload="none" aria-label="Última llamada del lead <?php echo (int)$row['lead_id']; ?>" src="list_recording.php?<?php echo h($query.'&lead_id='.(int)$row['lead_id'].'&attempt_id='.(int)$row['attempt_id']); ?>"></audio>
<?php else: ?><span class="lead-recording-note"><?php echo $row['call_id']!==null && $row['finished_at']===null ? 'Llamada en curso' : 'Sin audio'; ?></span><?php endif; ?>
</td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6">Esta lista no tiene leads con gestión.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="lead-recording-pages" aria-label="Páginas de leads">
<?php if ($page>1): ?><a href="?<?php echo h($query.'&page='.($page-1)); ?>">← Anterior</a><?php endif; ?>
<span>Página <?php echo (int)$page; ?> de <?php echo (int)$pages; ?> · <?php echo (int)$total; ?> leads con gestión</span>
<?php if ($page<$pages): ?><a href="?<?php echo h($query.'&page='.($page+1)); ?>">Siguiente →</a><?php endif; ?>
</nav></section>
<script>document.addEventListener('play',event=>{if(event.target.tagName==='AUDIO')document.querySelectorAll('audio').forEach(audio=>{if(audio!==event.target)audio.pause();});},true);</script>
<?php endif; bot_campaign_footer();
