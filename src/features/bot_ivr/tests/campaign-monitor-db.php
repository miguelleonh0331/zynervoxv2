<?php
require $argv[1].'/db.php';$db=carsa_db();$repo=bot_ivr_repository();$db->beginTransaction();
function monitorCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
try{
 $campaign=$repo->createCampaign('Monitor fixture',true);$list=$repo->createList($campaign,'Monitor list',true,2);$repo->updateListDialPrefix($list,$campaign,'7307');
 $db->prepare("INSERT INTO zynervox_bot_list(list_id,phone,status) VALUES (?,?,'NEW')")->execute([$list,'999000001']);
 $id='monitor-'.bin2hex(random_bytes(6));$repo->startCall($list,'999000002',$id);$repo->finishCall($id,'ANSWER','','',16,true);
 $retry='monitor-retry-'.bin2hex(random_bytes(6));$repo->startCall($list,'999000002',$retry);
 $run=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$run,3,['statuses'=>['NEW'],'order'=>'UP','prefix'=>'7307','flow_id'=>2],'fixture');$repo->transitionDialRun($run,'running');
 $inactive=$repo->createCampaign('Inactive monitor',false);$empty=$repo->createCampaign('Empty monitor',true);
 $rows=array_column($repo->campaignMonitor(),null,'campaign_id');$c=$rows[$campaign];
 monitorCheck($c['state']==='running' && $c['total']===2 && $c['calling']===1 && $c['finished']===0,'retry/counts/run state');
 monitorCheck(count($c['lists'])===1 && $c['lists'][0]['channels']===3,'list channels');
 monitorCheck($rows[$empty]['total']===0 && $rows[$empty]['state']==='idle' && $rows[$empty]['active'],'empty campaign');
 monitorCheck(!isset($rows[$inactive]),'inactive campaign visible');
 $states=array_column($c['states'],'count','status');monitorCheck(($states['NEW']??0)===1 && ($states['CALL']??0)===1 && array_sum($states)===2,'lead status summary');
 $repo->recordCallEvent($retry,'IVR_NODE',hash('sha256',$retry.'state'),time(),['action'=>'status','status'=>'AGENDADO_VALIDAR']);
 $repo->finishCall($retry,'NOANSWER','','',16,false);$repo->transitionDialRun($run,'completed');
 $rows=array_column($repo->campaignMonitor(),null,'campaign_id');monitorCheck($rows[$campaign]['finished']===1 && $rows[$campaign]['calling']===0 && $rows[$campaign]['state']==='completed','completed');
 $states=array_column($rows[$campaign]['states'],'count','status');monitorCheck(($states['AGENDADO_VALIDAR']??0)===1 && !isset($states['ANSWER']),'summary used dial status');
 echo "CAMPAIGN_MONITOR_OK: run state, unique contacts, retries, channels, empty campaigns and completion\n";
}finally{$db->rollBack();}
