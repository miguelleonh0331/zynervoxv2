<?php
require $argv[1].'/list_call_worker.php';
$db=carsa_db();$repo=bot_ivr_repository();$db->beginTransaction();
function dialCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
class FixtureAmi {
 public $sent=[];public $max=0;public $reject=false;public $drop=false;public $repo;public $db;public $run;
 public function __construct($repo,$db,$run){$this->repo=$repo;$this->db=$db;$this->run=$run;}
 public function ping(){}
 public function originate($lead,$prefix,$caller){if($this->drop)throw new RuntimeException('Acknowledgement lost');$this->sent[]=$lead;
  $n=$this->db->prepare("SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE dial_run_id=? AND finished_at IS NULL");$n->execute([$this->run]);$this->max=max($this->max,(int)$n->fetchColumn());
  if($this->reject)return false;
  $this->repo->startCall($lead['list_id'],$lead['phone'],$lead['call_id']);$this->repo->recordCallEvent($lead['call_id'],'START',hash('sha256',$lead['call_id'].'start'),time(),['channel'=>'Local/fixture;2','prefix'=>$prefix]);return true;}
}
try{
 $campaign=$repo->createCampaign('Dial worker fixture',true);$list=$repo->createList($campaign,'Dial worker fixture',true,2);$repo->updateListDialPrefix($list,$campaign,'7307');
 for($i=0;$i<7;$i++)$db->prepare("INSERT INTO zynervox_bot_list(list_id,phone,status) VALUES (?,?,'NEW')")->execute([$list,'999000'.sprintf('%03d',$i)]);
 $config=['statuses'=>['NEW'],'order'=>'UP','prefix'=>'7307','flow_id'=>2,'caller_id'=>''];$run=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$run,3,$config,'fixture');
 $dup=false;try{$repo->createDialRun($list,$campaign,bin2hex(random_bytes(16)),3,$config,'fixture');}catch(RuntimeException $e){$dup=true;}dialCheck($dup,'double Play allowed');
 $protected=false;try{$repo->updateListDialPrefix($list,$campaign,'7306');}catch(RuntimeException $e){$protected=true;}dialCheck($protected,'active list edit allowed');
 $ami=new FixtureAmi($repo,$db,$run);$ticks=0;
 $wait=function()use($repo,$db,$run,&$ticks){if(++$ticks>20)throw new RuntimeException('Worker did not complete');$s=$db->prepare('SELECT call_id FROM zynervox_bot_call_attempts WHERE dial_run_id=? AND finished_at IS NULL ORDER BY attempt_id LIMIT 1');$s->execute([$run]);if($id=$s->fetchColumn()){$repo->recordCallEvent($id,'IVR_NODE',hash('sha256',$id.'stage'),time(),['action'=>'status','status'=>'RESPONDE_F1']);$repo->finishCall($id,'ANSWER','','',16,true);}};
 bot_list_call_loop($list,$campaign,$run,$repo,$ami,$wait);$state=$repo->dialRun($list,$campaign,$run);
 dialCheck($state['state']==='completed' && $state['launched']===7 && $state['completed']===7 && $ami->max===3,'concurrency/refill/completion');
 $s=$db->prepare('SELECT COUNT(DISTINCT a.lead_id),MAX(c.called_count) FROM zynervox_bot_call_attempts a JOIN zynervox_bot_list c ON c.lead_id=a.lead_id WHERE dial_run_id=?');$s->execute([$run]);$row=$s->fetch(PDO::FETCH_NUM);dialCheck((int)$row[0]===7 && (int)$row[1]===1,'lead or audit duplicated');
 // Closed daily window must release slots without claiming leads; Stop still drains.
 $db->prepare("UPDATE zynervox_bot_campaigns SET scheduled=1,start_time='00:00:00',end_time='00:00:00' WHERE campaign_id=?")->execute([$campaign]);
 $closedConfig=$config;$closedConfig['statuses']=['RESPONDE_F1'];$closedRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$closedRun,3,$closedConfig,'fixture');
 $closedAmi=new class($repo,$db,$closedRun) extends FixtureAmi {
  public $reserved=0;public $released=0;
  public function reserveSlot($id){++$this->reserved;return true;}
  public function releaseSlot($id){++$this->released;}
 };
 $closedWait=function()use($repo,$list,$campaign,$closedRun,$closedAmi){
  dialCheck(!$closedAmi->sent && $repo->dialRun($list,$campaign,$closedRun)['launched']===0,'closed schedule consumed lead');
  dialCheck($closedAmi->reserved===0 && $closedAmi->released===0,'closed schedule reserved a slot');
  $repo->transitionDialRun($closedRun,'stopping');
 };
 bot_list_call_loop($list,$campaign,$closedRun,$repo,$closedAmi,$closedWait);
 dialCheck($repo->dialRun($list,$campaign,$closedRun)['state']==='stopped','closed schedule blocked Stop');
 $db->prepare('UPDATE zynervox_bot_campaigns SET scheduled=0 WHERE campaign_id=?')->execute([$campaign]);
 // Live limits refill upward and drain downward without interrupting calls.
 $liveConfig=$config;$liveConfig['statuses']=['RESPONDE_F1'];$liveRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$liveRun,3,$liveConfig,'fixture');
 $liveAmi=new FixtureAmi($repo,$db,$liveRun);$ticks=0;
 $liveWait=function()use($repo,$db,$list,$campaign,$liveRun,$liveAmi,&$ticks){
  ++$ticks;
  if($ticks===1){dialCheck(count($liveAmi->sent)===3,'initial live limit');$repo->updateListCallChannels($list,$campaign,5);return;}
  if($ticks===2){dialCheck(count($liveAmi->sent)===5,'raised limit not refilled');$repo->updateListCallChannels($list,$campaign,1);return;}
  if($ticks===3)dialCheck(count($liveAmi->sent)===5 && $repo->dialRun($list,$campaign,$liveRun)['active']===5,'lowering interrupted or originated calls');
  $q=$db->prepare('SELECT call_id FROM zynervox_bot_call_attempts WHERE dial_run_id=? AND finished_at IS NULL ORDER BY attempt_id LIMIT 1');$q->execute([$liveRun]);
  if($id=$q->fetchColumn()){$repo->recordCallEvent($id,'IVR_NODE',hash('sha256',$id.'stage'),time(),['action'=>'status','status'=>'RESPONDE_F1']);$repo->finishCall($id,'ANSWER','','',16,true);}
  if($ticks>20)throw new RuntimeException('Live limit run did not complete');
 };
 bot_list_call_loop($list,$campaign,$liveRun,$repo,$liveAmi,$liveWait);
 dialCheck($repo->dialRun($list,$campaign,$liveRun)['completed']===7 && $liveAmi->max===5,'live limit completion');
 $repo->updateListCallChannels($list,$campaign,3);
 // Business status is selected here, while dial_status remains ANSWER: each lead still appears at most once in this run.
 $config['statuses']=['RESPONDE_F1'];$stopRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$stopRun,3,$config,'fixture');$ami2=new FixtureAmi($repo,$db,$stopRun);$ticks=0;
 $wait=function()use($repo,$db,$stopRun,&$ticks){if(++$ticks===1)$repo->transitionDialRun($stopRun,'stopping');$s=$db->prepare('SELECT call_id FROM zynervox_bot_call_attempts WHERE dial_run_id=? AND finished_at IS NULL LIMIT 1');$s->execute([$stopRun]);if($id=$s->fetchColumn())$repo->finishCall($id,'ANSWER','','',16,true);if($ticks>10)throw new RuntimeException('Stop did not drain');};
 bot_list_call_loop($list,$campaign,$stopRun,$repo,$ami2,$wait);$state=$repo->dialRun($list,$campaign,$stopRun);dialCheck($state['state']==='stopped' && count($ami2->sent)===3 && $state['active']===0 && $state['pending']===4,'Stop launched more leads or hung up active calls');
 $repo->updateListCallChannels($list,$campaign,1);
 // No free SIP annex must not consume a lead; Stop can race with a slot reservation.
 $poolRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$poolRun,1,$config,'fixture');
 $poolAmi=new class($repo,$db,$poolRun) extends FixtureAmi {
  public $reserved=0;public $released=0;
  public function reserveSlot($id){++$this->reserved;return false;}
  public function releaseSlot($id){++$this->released;}
 };
 $poolTicks=0;
 $poolWait=function()use($repo,$list,$campaign,$poolRun,$poolAmi,&$poolTicks){
  if(++$poolTicks>2)throw new RuntimeException('Pool wait did not stop');
  dialCheck(!$poolAmi->sent && $repo->dialRun($list,$campaign,$poolRun)['launched']===0,'empty pool consumed lead');
  $repo->transitionDialRun($poolRun,'stopping');
 };
 bot_list_call_loop($list,$campaign,$poolRun,$repo,$poolAmi,$poolWait);
 $raceRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$raceRun,1,$config,'fixture');
 $raceAmi=new class($repo,$db,$raceRun) extends FixtureAmi {
  public $released=0;
  public function reserveSlot($id){$this->repo->transitionDialRun($this->run,'stopping');return true;}
  public function releaseSlot($id){++$this->released;}
 };
 bot_list_call_loop($list,$campaign,$raceRun,$repo,$raceAmi,static function(){});
 dialCheck($raceAmi->released===1 && !$raceAmi->sent && $repo->dialRun($list,$campaign,$raceRun)['launched']===0,'Stop race leaked annex or consumed lead');
 $noRun=bin2hex(random_bytes(16));$cfg=$config;$cfg['statuses']=['NEW'];$repo->createDialRun($list,$campaign,$noRun,1,$cfg,'fixture');$ami3=new FixtureAmi($repo,$db,$noRun);bot_list_call_loop($list,$campaign,$noRun,$repo,$ami3,static function(){});dialCheck(!$ami3->sent,'finalized leads redialed implicitly');
 $rejectRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$rejectRun,1,$config,'fixture');$ami4=new FixtureAmi($repo,$db,$rejectRun);$ami4->reject=true;$failed=false;try{bot_list_call_loop($list,$campaign,$rejectRun,$repo,$ami4);}catch(RuntimeException $e){$failed=true;$repo->transitionDialRun($rejectRun,'failed');}dialCheck($failed && count($ami4->sent)===1 && $repo->dialRun($list,$campaign,$rejectRun)['active']===0,'AMI rejection handling');
 $lostRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$lostRun,1,$config,'fixture');$ami5=new FixtureAmi($repo,$db,$lostRun);$ami5->drop=true;try{bot_list_call_loop($list,$campaign,$lostRun,$repo,$ami5);}catch(RuntimeException $e){$repo->transitionDialRun($lostRun,'failed');}dialCheck($repo->dialRun($list,$campaign,$lostRun)['active']===1,'lost acknowledgement released ambiguous slot');
 // A new worker must account for the unresolved call from the prior run.
 $nextRun=bin2hex(random_bytes(16));$repo->createDialRun($list,$campaign,$nextRun,1,$config,'fixture');$repo->transitionDialRun($nextRun,'running');dialCheck($repo->claimDialLead($nextRun,'fixture-next')===null,'existing calls not included in channel limit');
 echo "LIST_CALL_WORKER_OK: 3 slots, live increase/decrease, refill, one attempt per lead/run, double Play, Stop drain, eligibility, rejection and ambiguous AMI acknowledgement\n";
}finally{$db->rollBack();}
