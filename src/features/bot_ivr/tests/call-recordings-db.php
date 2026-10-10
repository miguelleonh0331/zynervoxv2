<?php
declare(strict_types=1);
require $argv[1].'/db.php';
require $argv[1].'/call_audit_service.php';
$db=carsa_db();$repo=bot_ivr_repository();$db->beginTransaction();
function recording_check($ok) {if(!$ok)throw new RuntimeException('Recording metadata check failed');}
try {
 $campaign=$repo->createCampaign('Recording fixture',true);$list=$repo->createList($campaign,'Recording fixture',true,2);$id='recording-'.bin2hex(random_bytes(10));$repo->startCall($list,'999000123',$id);
 $meta=['campaign_id'=>$campaign,'flow_id'=>2,'flow_code'=>'02','flow_name'=>'carsa3','flow_sha256'=>str_repeat('a',64)];$repo->recordCallEvent($id,'IVR_START',hash('sha256',$id.'start'),time(),$meta);
 $db->prepare('UPDATE zynervox_bot_lists SET id_flujo=11 WHERE list_id=?')->execute([$list]);
 $repo->recordCallEvent($id,'IVR_END',hash('sha256',$id.'end'),time(),['result'=>'COMPLETED']);
 $finish=['recording_path'=>'/var/lib/zynervoxv2/asterisk/recordings/bot_ivr/'.$id.'.wav','recording_status'=>'ready','recording_bytes'=>12345];
 $repo->finishCall($id,'ANSWER','','',16,true);$repo->recordCallEvent($id,'FINISH',hash('sha256',$id.'finish'),time(),$finish);
 $repo->recordCallEvent($id,'FINISH',hash('sha256',$id.'finish'),time(),array_merge($finish,['recording_bytes'=>999]));
 $s=$db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
 recording_check((int)$row['campaign_id']===$campaign && (int)$row['flow_id']===2 && $row['flow_code']==='02' && $row['flow_name']==='carsa3' && $row['flow_sha256']===$meta['flow_sha256']);
 recording_check($row['ivr_result']==='COMPLETED' && $row['recording_path']===$finish['recording_path'] && $row['recording_status']==='ready' && (int)$row['recording_bytes']===12345 && $row['recording_finalized_at']!==null);
 recording_check(call_recording_metadata($id)['recording_status']==='none');
 $rejected=false;try{call_recording_metadata('../invalid');}catch(RuntimeException $e){$rejected=true;}recording_check($rejected);
 echo "CALL_RECORDINGS_OK\n";
} finally {$db->rollBack();}
