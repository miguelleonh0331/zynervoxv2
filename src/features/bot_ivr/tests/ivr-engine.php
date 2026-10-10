<?php
require $argv[1].'/ivr_engine_service.php';
function checkIvr($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$lead=['lead_id'=>1,'list_id'=>2,'campaign_id'=>3,'phone'=>'999','customer_name'=>'mundo','extra_json'=>'{}'];
$flow=['start'=>'start','nodes'=>[
    'start'=>['type'=>'noop','next'=>'voice'],
    'voice'=>['type'=>'create_audio','audio_text'=>'Hola {nombre}','next'=>'end'],
    'end'=>['type'=>'hangup'],
]];
$steps=bot_ivr_steps($flow,$lead,'/runtime',fn($hash)=>true);
checkIvr(count($steps)===3 && $steps[1]['hash']===bot_ivr_audio_hash('Hola mundo'),'lead variables and hash');
checkIvr($steps[1]['hash']==='7b2ad86080953389f71a98d021b0a7928533d9799b2433643d85b30d36c3f42e','Python generator canonical hash compatibility');
$commands=[]; $events=[];
$result=bot_ivr_execute($steps,function($command) use (&$commands) {$commands[]=$command;return '200 result=0 endpos=8000';},function($type,$data) use(&$events){$events[]=$data['node'];});
checkIvr($result==='COMPLETED' && count($commands)===1 && $events===['start','voice','end'],'sequence and terminal');
checkIvr(bot_ivr_execute($steps,fn($command)=>'200 result=-1',fn($type,$data)=>null)==='INTERRUPTED','hangup interrupts playback');
try { bot_ivr_steps($flow,$lead,'/runtime',fn($hash)=>false);throw new RuntimeException('unpublished audio accepted'); } catch(RuntimeException $e) {checkIvr($e->getMessage()==='IVR audio not published','registry rejection');}
$bad=$flow;$bad['nodes']['voice']['type']='capture_stt';
try {bot_ivr_steps($bad,$lead,'/runtime',fn($hash)=>true);throw new RuntimeException('unsupported node accepted');}catch(RuntimeException $e){checkIvr(strpos($e->getMessage(),'not supported')!==false,'unsupported node rejected');}
$bad=$flow;$bad['nodes']['voice']['next']='start';
try {bot_ivr_steps($bad,$lead,'/runtime',fn($hash)=>true);throw new RuntimeException('cycle accepted');}catch(RuntimeException $e){checkIvr($e->getMessage()==='IVR cycle or step limit','cycle rejected');}
echo "PASS: IVR variables, prepared audio, node sequence, hangup, unpublished hashes and unsupported/cyclic flows\n";
