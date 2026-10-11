<?php
require_once __DIR__.'/../../includes/Auth.php';
require_once __DIR__.'/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;
Auth::checkAccess([7,8,9]);
if (isset($_GET['audio'])) {
    $id=filter_var($_GET['audio'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) { http_response_code(400); exit('ID inválido.'); }
    $recording=Dialer::recording((int)$id);
    if (!$recording) { http_response_code(404); exit('Grabación no disponible.'); }
    session_write_close();
    $size=(int)$recording['size']; $start=0; $end=$size-1;
    header('Content-Type: audio/wav'); header('Cache-Control: private, no-store');
    header('Accept-Ranges: bytes'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="call-'.$id.'.wav"');
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range=$_SERVER['HTTP_RANGE'];
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$parts) || ($parts[1]==='' && $parts[2]==='')) { http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
        if ($parts[1]==='') { $start=max(0,$size-(int)$parts[2]); }
        else { $start=(int)$parts[1]; if ($parts[2]!=='') $end=min($end,(int)$parts[2]); }
        if ($start>$end || $start>=$size) { http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
        http_response_code(206); header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    header('Content-Length: '.($end-$start+1));
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='HEAD') exit;
    $handle=fopen($recording['file'],'rb'); fseek($handle,$start); $left=$end-$start+1;
    while ($left>0 && !feof($handle) && !connection_aborted()) { $chunk=fread($handle,min(65536,$left)); if ($chunk===false || $chunk==='') break; echo $chunk; $left-=strlen($chunk); }
    fclose($handle); exit;
}
function recordingEsc($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$campaignId=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$since=is_string($_GET['since'] ?? null) ? $_GET['since'] : date('Y-m-d',strtotime('-30 days'));
$until=is_string($_GET['until'] ?? null) ? $_GET['until'] : date('Y-m-d');
$phone=is_string($_GET['phone'] ?? null) ? $_GET['phone'] : '';
$agent=is_string($_GET['agent'] ?? null) ? $_GET['agent'] : '';
$cursor=filter_var($_GET['before'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
$rows=[]; $campaign=null; $error='';
try {
    if (!$campaignId || $cursor===false) throw new InvalidArgumentException('ID de campaña o página inválido.');
    $campaign=Dialer::campaign((int)$campaignId);
    $rows=Dialer::recordings((int)$campaignId,$since,$until,$phone,$agent,(int)$cursor);
} catch (InvalidArgumentException $e) { http_response_code(400); $error=$e->getMessage(); }
catch (Throwable $e) { http_response_code($campaign===null ? 404 : 500); $error='No se pudo consultar las grabaciones.'; error_log('dialer recordings: '.$e->getMessage()); }
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Grabaciones del marcador</title><link rel="stylesheet" href="layout.css"></head><body>
<?php if (($_SESSION['user_level'] ?? 0)==9) { require_once __DIR__.'/sidebar.php'; renderSidebar('campaigns'); } else { require_once __DIR__.'/../sup/sidebar.php'; renderSupSidebar('campaigns'); } ?>
<main class="main-content"><h1>Grabaciones — <?php echo recordingEsc($campaign['name'] ?? 'Marcador'); ?></h1>
<p><a href="dialer_report.php?id=<?php echo (int)$campaignId; ?>">Volver al reporte</a></p>
<?php if ($error): ?><p role="alert"><?php echo recordingEsc($error); ?></p><?php endif; ?>
<form method="GET" action="dialer_report.php" class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
<input type="hidden" name="view" value="recordings"><input type="hidden" name="id" value="<?php echo (int)$campaignId; ?>">
<label>Desde <input type="date" name="since" value="<?php echo recordingEsc($since); ?>" required></label>
<label>Hasta <input type="date" name="until" value="<?php echo recordingEsc($until); ?>" required></label>
<label>Teléfono <input name="phone" value="<?php echo recordingEsc($phone); ?>" maxlength="20"></label>
<label>Agente <input name="agent" value="<?php echo recordingEsc($agent); ?>" maxlength="60"></label>
<button type="submit" class="btn-primary">Buscar</button></form>
<p><?php echo count($rows); ?> grabaciones en esta página. Los archivos ausentes se indican sin ocultar el registro.</p>
<div style="overflow-x:auto"><table><thead><tr><th>Llamada</th><th>Fecha</th><th>Teléfono</th><th>Agente</th><th>Resultado</th><th>Tipificación</th><th>Conversación</th><th>Audio</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<?php foreach (['call_id','originated_at','phone_number','agent_user','termination_reason','disposition_code','duration_talk'] as $key): ?><td style="padding:8px"><?php echo recordingEsc($row[$key] ?? 'N/D'); ?></td><?php endforeach; ?>
<td><?php if ($row['available']): ?><audio controls preload="none" src="dialer_report.php?view=recordings&amp;audio=<?php echo (int)$row['call_id']; ?>"></audio><?php else: ?>Archivo no disponible<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if (count($rows)===100): $query=['view'=>'recordings','id'=>$campaignId,'since'=>$since,'until'=>$until,'phone'=>$phone,'agent'=>$agent,'before'=>end($rows)['call_id']]; ?><a href="dialer_report.php?<?php echo recordingEsc(http_build_query($query)); ?>">Ver anteriores</a><?php endif; ?>
</main></body></html>
