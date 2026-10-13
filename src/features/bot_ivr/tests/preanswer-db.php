<?php
require $argv[1].'/db.php';
$db=carsa_db();$r=bot_ivr_repository();if($db->query('SELECT DATABASE()')->fetchColumn()!=='zynervox_core')throw new RuntimeException('Wrong DB');
$db->beginTransaction();
function paDbCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function paFixture($r,$list,$phone,$prefix='7307'){
 $id='qa-pa-'.bin2hex(random_bytes(8));$r->startCall($list,$phone,$id);$r->recordCallEvent($id,'START',hash('sha256',$id.'start'),time(),['prefix'=>$prefix,'channel'=>'Local/qa;2']);$r->recordCallEvent($id,'PREANSWER_START',hash('sha256',$id.'capture'),time(),['recording'=>'fixture']);return $id;
}
try {
 $campaign=$r->createCampaign('Preanswer QA',true);$list=$r->createList($campaign,'Preanswer QA',true,2);
 $map=['casilla'=>'PREANSWER_CASILLA','numero_no_existe'=>'PREANSWER_NUMERO_NO_EXISTE','fuera_servicio'=>'PREANSWER_FUERA_SERVICIO'];$i=0;
 foreach($map as $category=>$status){
  $id=paFixture($r,$list,'990000'.sprintf('%03d',++$i));$event=hash('sha256',$id.'result');$data=['category'=>$category,'text'=>'synthetic operator message','provider'=>'vosk'];
  paDbCheck($r->savePreanswerResult($id,$event,time(),$data),'open result rejected');
  paDbCheck(!$r->savePreanswerResult($id,$event,time(),$data),'duplicate result claimed twice');
  $r->finishCall($id,'CHANUNAVAIL','','',1,false);$call=$r->preanswerCall($id);
  paDbCheck($call['status']===$status && $call['dial_status']==='CHANUNAVAIL' && $call['answered_at']===null,'closure lost category or SIP cause');
 }
 $id=paFixture($r,$list,'990000004');$r->recordCallEvent($id,'ANSWER',hash('sha256',$id.'answer'),time(),[]);
 paDbCheck(!$r->savePreanswerResult($id,hash('sha256',$id.'result'),time(),['category'=>'casilla','text'=>'late']),'late provider response claimed answered call');
 $r->finishCall($id,'ANSWER','','',16,true);paDbCheck($r->preanswerCall($id)['status']==='ANSWER','answered call overwritten');
 $id=paFixture($r,$list,'990000005');$r->finishCall($id,'CONGESTION','','',38,false);
 $new=paFixture($r,$list,'990000005');
 paDbCheck(!$r->savePreanswerResult($id,hash('sha256',$id.'result'),time(),['category'=>'numero_no_existe','text'=>'late operator result']),'closed call eligible for hangup');
 paDbCheck($r->preanswerCall($id)['status']==='PREANSWER_NUMERO_NO_EXISTE','closed result not reconciled');
 $s=$db->prepare('SELECT status FROM zynervox_bot_list WHERE list_id=? AND phone=?');$s->execute([$list,'990000005']);paDbCheck($s->fetchColumn()==='CALL','older detection overwrote newer attempt');
 $r->recordCallEvent($id,'ANSWER',hash('sha256',$id.'lateanswer'),time(),[]);paDbCheck($r->preanswerCall($id)['status']==='ANSWER','late answer did not correct preanswer status');
 $other=paFixture($r,$list,'990000006','7306');paDbCheck(!$r->savePreanswerResult($other,hash('sha256',$other.'result'),time(),['category'=>'casilla','text'=>'wrong origin']),'other prefix claimed');
 $answerOnly=paFixture($r,$list,'990000007');$r->finishCall($answerOnly,'ANSWER','','',16,false);
 paDbCheck(!$r->savePreanswerResult($answerOnly,hash('sha256',$answerOnly.'result'),time(),['category'=>'casilla','text'=>'late']),'DIALSTATUS ANSWER not respected');
 $pending=array_column($r->pendingPreanswerCalls(1000),'call_id');paDbCheck(in_array($new,$pending,true) && !in_array($id,$pending,true) && !in_array($other,$pending,true),'pending scope/done filtering');
 $db->prepare("UPDATE zynervox_bot_call_attempts SET status='LOST',end_source='RECOVERY' WHERE call_id=?")->execute([$new]);
 paDbCheck(!$r->savePreanswerResult($new,hash('sha256',$new.'result'),time(),['category'=>'casilla','text'=>'recovered']),'recovery claimed');
 echo "PREANSWER_DB_OK: core only, categories, closure, dedup, answer race, late result, latest lead, prefix, recovery, pending\n";
}finally{$db->rollBack();}
