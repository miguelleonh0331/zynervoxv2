<?php
require $argv[1].'/db.php';
$db=carsa_db();$repo=bot_ivr_repository();$db->beginTransaction();
function statsCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
try {
 $campaign=$repo->createCampaign('Statistics fixture',true);$list=$repo->createList($campaign,'Statistics fixture',true,2);
 $db->prepare("INSERT INTO zynervox_bot_list(list_id,phone,status) VALUES (?,?,'NEW')")->execute([$list,'999000001']);
 $a='stats-a-'.bin2hex(random_bytes(6));$repo->startCall($list,'999000002',$a);$repo->finishCall($a,'ANSWER','','',16,true);
 $b='stats-b-'.bin2hex(random_bytes(6));$repo->startCall($list,'999000003',$b);$repo->finishCall($b,'CHANUNAVAIL','','',3,false);
 $stats=$repo->listStatistics($list,$campaign);statsCheck($stats['total']===3 && $stats['calling']===0 && $stats['finished']===2,'initial counters');
 statsCheck(count($stats['errors'])===1 && $stats['errors'][0]['call_id']===$b,'error identity');
 $retry='stats-retry-'.bin2hex(random_bytes(6));$repo->startCall($list,'999000003',$retry);
 $stats=$repo->listStatistics($list,$campaign);statsCheck($stats['total']===3 && $stats['calling']===1 && $stats['finished']===1,'retry inflated or classified incorrectly');
 statsCheck(count($stats['errors'])===1,'historical error lost');
 $repo->recordCallEvent($retry,'IVR_NODE',hash('sha256',$retry.'state'),time(),['action'=>'status','status'=>'RESPONDE_F1']);
 $repo->recordCallEvent($retry,'IVR_END',hash('sha256',$retry.'error'),time(),['result'=>'ERROR','error_class'=>'RuntimeException']);
 $repo->finishCall($retry,'ANSWER','','',16,true);
 $stats=$repo->listStatistics($list,$campaign);statsCheck($stats['calling']===0 && $stats['finished']===2 && count($stats['errors'])===2,'IVR error missed');
 $states=array_column($stats['states'],'count','status');statsCheck(($states['RESPONDE_F1']??0)===1 && array_sum($states)===3,'business states incorrect');
 $other=$repo->createList($campaign,'Empty statistics',true,2);$empty=$repo->listStatistics($other,$campaign);statsCheck($empty['total']===0 && !$empty['errors'] && !$empty['states'],'list isolation');
 $blocked=false;try{$repo->listStatistics($list,$campaign+100000);}catch(RuntimeException $e){$blocked=true;}statsCheck($blocked,'campaign scope bypass');
 echo "LIST_STATISTICS_OK: unique leads, latest attempt, historical/IVR errors, business states and scope\n";
}finally{$db->rollBack();}
