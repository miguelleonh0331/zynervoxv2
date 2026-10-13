<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
initial_survey_require_login();
require_once __DIR__.'/db.php';
require_once __DIR__.'/call_recordings.php';
$ids=[];
foreach (['id','campaign_id','lead_id','attempt_id'] as $key) {
    $ids[$key]=filter_var($_GET[$key] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$ids[$key]) { http_response_code(400); exit('Solicitud inválida.'); }
}
try { $rows=bot_ivr_repository()->latestLeadCalls($ids['id'],$ids['campaign_id'],1,0,$ids['lead_id']); }
catch (Throwable $e) { http_response_code(404); exit('Audio no disponible.'); }
$lead=$rows[0] ?? null;
$path=$lead!==null && (int)$lead['attempt_id']===$ids['attempt_id'] ? bot_lead_recording_path($lead) : null;
if ($path===null) { http_response_code(404); exit('Audio no disponible para la última llamada.'); }
$handle=fopen($path,'rb');
if ($handle===false) { http_response_code(404); exit('Audio no disponible.'); }
session_write_close();
$size=(int)fstat($handle)['size']; $start=0; $end=$size-1;
header('Content-Type: audio/wav');
header('Cache-Control: private, no-store');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="lead-'.$ids['lead_id'].'-call-'.$ids['attempt_id'].'.wav"');
if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$parts) || ($parts[1]==='' && $parts[2]==='')) { fclose($handle); http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
    if ($parts[1]==='') $start=max(0,$size-(int)$parts[2]);
    else { $start=(int)$parts[1]; if ($parts[2]!=='') $end=min($end,(int)$parts[2]); }
    if ($start>$end || $start>=$size) { fclose($handle); http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
    http_response_code(206);
    header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
}
header('Content-Length: '.($end-$start+1));
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='HEAD') {
    fseek($handle,$start); $remaining=$end-$start+1;
    while ($remaining>0 && !feof($handle) && !connection_aborted()) {
        $chunk=fread($handle,min(65536,$remaining));
        if ($chunk===false || $chunk==='') break;
        echo $chunk; $remaining-=strlen($chunk);
    }
}
fclose($handle);
