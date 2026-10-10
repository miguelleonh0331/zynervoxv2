<?php
require $argv[1].'/db.php';
$db=carsa_db();$repo=bot_ivr_repository();$db->beginTransaction();
function statusCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
try {
 $campaign=$repo->createCampaign('Status fixture',true);$list=$repo->createList($campaign,'Status fixture',true,2);$id='status-'.bin2hex(random_bytes(10));$lead=$repo->startCall($list,'999000123',$id);
 $read=$db->prepare('SELECT status FROM zynervox_bot_list WHERE lead_id=?');
 foreach(['RESPONDE_F1','RESPONDE_F2'] as $state){$repo->recordCallEvent($id,'IVR_NODE',hash('sha256',$id.$state),time(),['action'=>'status','status'=>$state]);$read->execute([$lead]);statusCheck($read->fetchColumn()===$state,'stage not persisted');}
 $repo->saveIvrResult($id,'sql',['RESULTADO'=>'AGENDADO_VALIDAR']);$repo->finishCall($id,'ANSWER','','',16,true);
 $read->execute([$lead]);statusCheck($read->fetchColumn()==='AGENDADO_VALIDAR','finish overwrote business state');
 $s=$db->prepare('SELECT status,dial_status FROM zynervox_bot_call_attempts WHERE call_id=?');$s->execute([$id]);$a=$s->fetch();statusCheck($a['status']==='AGENDADO_VALIDAR' && $a['dial_status']==='ANSWER','audit status lost');
 $old=$id.'-old';$new=$id.'-new';$repo->startCall($list,'999000123',$old);$repo->startCall($list,'999000123',$new);
 $repo->recordCallEvent($new,'IVR_NODE',hash('sha256',$new),time(),['action'=>'status','status'=>'RESPONDE_F1']);
 $repo->saveIvrResult($old,'sql',['RESULTADO'=>'AGENDADO']);$repo->finishCall($old,'ANSWER','','',16,true);
 $read->execute([$lead]);statusCheck($read->fetchColumn()==='RESPONDE_F1','old attempt overwrote newer status');
 $repo->finishCall($new,'CANCEL','','',16,true);$read->execute([$lead]);statusCheck($read->fetchColumn()==='RESPONDE_F1','interruption lost attained stage');
 $recovery=$id.'-recovery';$repo->startCall($list,'999000123',$recovery);$repo->recordCallEvent($recovery,'IVR_NODE',hash('sha256',$recovery),time(),['action'=>'status','status'=>'RESPONDE_F2']);$repo->observeCallPresence($recovery,false,time());$repo->observeCallPresence($recovery,false,time()+121);$read->execute([$lead]);statusCheck($read->fetchColumn()==='RESPONDE_F2','recovery overwrote stage');
 statusCheck(!$repo->observeCallPresence($recovery,false,time()+242),'recovery duplicated');
 echo "IVR_STATUS_OK\n";
} finally{$db->rollBack();}
