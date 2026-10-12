<?php
require $argv[1].'/ivr_engine_service.php';
function ttsCheck($ok, $why) { if (!$ok) throw new RuntimeException($why); }
function ttsReject(callable $fn) { try {$fn();} catch (RuntimeException $e) {return;} throw new RuntimeException('Expected rejection'); }
$tts=['key'=>'ws:7','endpoint'=>'ws://127.0.0.1:8900'];
$hash=bot_ivr_audio_hash('Hola mundo',$tts);
ttsCheck(bot_ivr_audio_hash('Hola mundo')==='7b2ad86080953389f71a98d021b0a7928533d9799b2433643d85b30d36c3f42e','Legacy hash changed');
ttsCheck($hash!==bot_ivr_audio_hash('Hola mundo'),'Provider collision');
ttsCheck($hash!==bot_ivr_audio_hash('Hola mundo',array_merge($tts,['key'=>'ws:8'])),'Provider ID collision');
ttsCheck($hash!==bot_ivr_audio_hash('Hola mundo',array_merge($tts,['endpoint'=>'ws://127.0.0.1:8901'])),'Endpoint collision');
foreach (['http://127.0.0.1','ws://user:secret@host','ws://host?token=x','ws://host#x',"ws://host\n/path",'ws://host:0'] as $endpoint) ttsReject(fn()=>bot_tts_endpoint($endpoint));
ttsCheck(bot_tts_resolve('ws:7',[['provider_id'=>7,'endpoint'=>$tts['endpoint']]])===$tts,'Registry resolution');
ttsReject(fn()=>bot_tts_resolve('ws:8',[['provider_id'=>7,'endpoint'=>$tts['endpoint']]]));
$lead=['lead_id'=>1,'list_id'=>2,'campaign_id'=>3,'phone'=>'999','customer_name'=>'mundo','extra_json'=>'{}','dial_config_json'=>json_encode(['tts'=>$tts])];
$flow=['start'=>'a','nodes'=>['a'=>['type'=>'create_audio','audio_text'=>'Hola {nombre}','next'=>'end'],'end'=>['type'=>'hangup']]];
$steps=bot_ivr_steps($flow,$lead,'/runtime',fn($value)=>$value===$hash);
ttsCheck($steps[0]['hash']===$hash,'Run snapshot ignored');
$lead['dial_config_json']='{"statuses":["NEW"]}';
ttsCheck(bot_ivr_steps($flow,$lead,'/runtime',fn($value)=>true)[0]['hash']===bot_ivr_audio_hash('Hola mundo'),'Historical run changed provider');
if (isset($argv[2])) ttsCheck($hash===$argv[2],'Python/PHP hash disagreement');
echo "PASS: TTS URL validation, catalog IDs, isolated hashes and per-run snapshots\n";
