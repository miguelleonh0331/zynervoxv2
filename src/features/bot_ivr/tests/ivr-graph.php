<?php
require $argv[1].'/ivr_graph_service.php';
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/carsa3.json'),true,512,JSON_THROW_ON_ERROR);
function graphCheck($ok,$message) {if (!$ok) throw new RuntimeException($message);}
function runGraphCase(array $flow,array $replies,bool $sqlOk=true,bool $urlOk=true,?string $interrupt=null): array {
    $lead=['lead_id'=>49,'list_id'=>1,'campaign_id'=>1,'phone'=>'13119898','customer_name'=>'Prueba','extra_json'=>'{"monto":"500","local":"Carsa Lima","direccion":"Av. Lima 123"}'];
    $events=[];$results=[];$commands=[];$generated=[];
    $io=function($action,$data) use(&$replies,&$generated,$urlOk) {
        if ($action==='transcribe') { if (!$replies) throw new RuntimeException('Unexpected STT request');$text=array_shift($replies);return ['ok'=>$text!=='','text'=>$text,'provider'=>$data['provider']]; }
        if ($action==='capture_value') return ['ok'=>$data['text']==='mañana','value'=>'2026-10-11','spoken'=>'domingo 11 de octubre'];
        if ($action==='execute') return ['ok'=>$urlOk];
        if ($action==='audio') {$hash=bot_ivr_audio_hash($data['text']);$generated[$hash]=true;return ['ok'=>true,'hash'=>$hash];}
        throw new RuntimeException('Unexpected IO action');
    };
    $responseText=bot_list_audio_render($flow['nodes']['Mensajefinal']['audio_text'],['fecha_spoken'=>'domingo 11 de octubre','local'=>'Carsa Lima','direccion'=>'Av. Lima 123']);
    $dynamicHash=bot_ivr_audio_hash($responseText);
    $published=function($hash) use(&$generated,$dynamicHash) {return $hash!==$dynamicHash || isset($generated[$hash]);};
    $command=function($command) use(&$commands,$interrupt) {$commands[]=$command;if ($interrupt!==null && strpos($command,$interrupt)!==false) return '200 result=-1';if(strpos($command,'GET VARIABLE')===0)return '200 result=1 (capture-1)';return '200 result=0 endpos=8000';};
    $event=function($type,$data) use(&$events) {$events[]=$data;};
    $save=function($node,$fields) use(&$results,$sqlOk) {$results[$node]=$fields;return $sqlOk;};
    $runner=new BotIvrGraphRunner($flow,$lead,'fixture-call','/runtime',$published,$command,$event,$io,$save,['execute_allowlist'=>['http://172.16.10.18/send_sms/send.php'],'variables'=>['sms_key'=>'fixture']]);
    return ['result'=>$runner->run(),'events'=>$events,'results'=>$results,'commands'=>$commands,'generated'=>$generated];
}
bot_ivr_validate_graph($fixture);
graphCheck(count($fixture['nodes'])===27,'CARSA reference node count');
$out=runGraphCase($fixture,['','sí','sí','mañana']);
graphCheck($out['result']==='COMPLETED' && $out['results']['injectando1']['RESULTADO']==='AGENDADO','appointment path');
graphCheck($out['results']['injectando1']['FECHA_AGENDADA']==='2026-10-11' && $out['results']['injectando1']['RESPUESTA_REGISTRADA']==='mañana','capture variables and raw speech');
graphCheck(count($out['generated'])===1,'captured-date audio publication');
graphCheck(count(array_filter($out['events'],fn($e)=>($e['action'] ?? '')==='execute' && $e['ok']))===1,'execute after SQL');
foreach ([['','no'],['','sí','no']] as $answers) {$out=runGraphCase($fixture,$answers);graphCheck($out['result']==='COMPLETED' && !$out['results'],'negative path');}
$out=runGraphCase($fixture,['deje su mensaje después del tono']);
graphCheck($out['result']==='COMPLETED' && count(array_filter($out['commands'],fn($c)=>strpos($c,'STREAM FILE')===0))===0,'Vosk machine terminates without greeting');
$out=runGraphCase($fixture,['','','']);graphCheck($out['results']['node_1']['RESULTADO']==='SIN_AUDIO','first menu timeout and one retry');
$out=runGraphCase($fixture,['','sí','','']);graphCheck($out['results']['node_2']['RESULTADO']==='NO_RESPONDE_F2','second menu fallback');
$out=runGraphCase($fixture,['','sí','sí','no sé','no sé']);graphCheck($out['results']['injectando2']['RESULTADO']==='AGENDADO_VALIDAR' && $out['results']['injectando2']['FECHA_AGENDADA']===null,'date failure uses validation path');
$out=runGraphCase($fixture,['','sí','sí','mañana'],false,false);graphCheck($out['result']==='COMPLETED','SQL and URL fallback preserve configured path');
$out=runGraphCase($fixture,[],true,true,'EXEC Wait');graphCheck($out['result']==='INTERRUPTED' && !$out['results'],'hungup does not reach SQL or URL');
graphCheck(bot_ivr_intent($fixture['nodes']['PRIMERSTT'],'[silencio]')===null && bot_ivr_intent($fixture['nodes']['PRIMERSTT'],'siempre')===null,'nonverbal tags and word boundaries');
$bad=$fixture;$bad['nodes']['AMD_HANGUP']['type']='execute';$bad['nodes']['AMD_HANGUP']['next']='inicio';
try {bot_ivr_validate_graph($bad);throw new RuntimeException('cycle accepted');}catch(RuntimeException $e){graphCheck($e->getMessage()==='IVR cycle or step limit','cycle rejection');}
try {bot_ivr_url('http://evil.test/send?x={numero}',[],['http://172.16.10.18/send_sms/send.php']);throw new RuntimeException('unallowed URL accepted');}catch(RuntimeException $e){graphCheck($e->getMessage()==='IVR URL service not allowed','URL allowlist');}
graphCheck(bot_ivr_url('http://example.test/send?x={value}',['value'=>'a&key=evil'],['http://example.test/send'])==='http://example.test/send?x=a%26key%3Devil','query escaping');
graphCheck(bot_ivr_url('http://example.test/send?n={numero}',['numero'=>'511999000111'],['http://example.test/send'])==='http://example.test/send?n=999000111','SMS number convention');
$templates=bot_list_audio_templates($fixture,true);graphCheck(!isset($templates['Mensajefinal.audio']) && isset($templates['PRIMERSTT.retry']) && isset($templates['bridge_1.bridge']),'static prebuild excludes captured variables');
echo "PASS: CARSA 27 nodes, AMD, both menus, retries, date capture, bridges, SQL/URL fallbacks, dynamic audio, hangup and validation\n";
