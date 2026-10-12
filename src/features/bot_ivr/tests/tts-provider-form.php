<?php
// Render and submit the real page against private collaborators; no SQL or calls.
$root=sys_get_temp_dir().'/zynervox-tts-form-'.bin2hex(random_bytes(6));
foreach (['bot_ivr','config','includes','ivr_builder'] as $dir) mkdir($root.'/'.$dir,0700,true);
foreach (['list_edit.php','list_audio_jobs.php','list_audio_service.php','tts_service.php'] as $file) copy($argv[1].'/'.$file,$root.'/bot_ivr/'.$file);
file_put_contents($root.'/config/Config.php','<?php namespace Config; class Config { static function deployment($key,$default=null) { return $key==="runtime" ? '.var_export($root.'/runtime',true).' : $default; }}');
file_put_contents($root.'/includes/Carriers.php','<?php namespace Includes; class Carriers { static function dialOrigins() {return [];} }');
file_put_contents($root.'/ivr_builder/published_flow.php','<?php function ivr_builder_published_flow($id) {return ["start"=>"a","nodes"=>["a"=>["type"=>"hangup","audio_text"=>"Hola"]]];}');
file_put_contents($root.'/bot_ivr/list_call_service.php','<?php function bot_list_call_status($id,$campaign) {return null;}');
file_put_contents($root.'/bot_ivr/list_service.php','<?php function bot_list_get($db,$id,$campaign) {if($id!==56 || $campaign!==50) throw new RuntimeException("Scope rejected");return ["id_flujo"=>2,"name"=>"Fixture","campaign_name"=>"Fixture","active"=>1,"created_at"=>"2026-10-12","dial_prefix"=>""]; }');
file_put_contents($root.'/bot_ivr/campaigns_page.php', <<<'PHP'
<?php
$message='';$error='';$_SESSION=['bot_campaign_csrf'=>'valid'];
function h($text) {return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function bot_campaign_csrf() {if(!hash_equals('valid',(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('CSRF rejected');}
function bot_campaign_token() {echo '<input name="csrf" value="valid">';}
function bot_campaign_header($title) {global $error; echo h($error);}
function bot_campaign_footer() {}
function bot_campaign_redirect($target,$message) {echo json_encode(['created'=>$GLOBALS['created'],'target'=>$target]);exit;}
function bot_ivr_repository() {return new class {
 function ttsProviders() {return [['provider_id'=>7,'name'=>'Fixture <TTS>','endpoint'=>'ws://127.0.0.1:8900']];}
 function createTtsProvider($name,$endpoint) {$GLOBALS['created']=[$name,$endpoint];return 8;}
 function leadCount($id) {return 0;}
 function audioLeads($id,$campaign) {return [];}
 function listStatistics($id,$campaign) {return ['total'=>0,'calling'=>0,'finished'=>0,'states'=>[],'errors'=>[]];}
};}
PHP);
file_put_contents($root.'/driver.php','<?php $request=json_decode(file_get_contents($argv[1]),true);$_GET=$request["get"] ?? ["id"=>56,"campaign_id"=>50];$_POST=$request["post"] ?? [];$_SERVER["REQUEST_METHOD"]=$_POST?"POST":"GET";require __DIR__."/bot_ivr/list_edit.php";');
function formRequest($root,array $request): string {
 file_put_contents($root.'/request.json',json_encode($request));
 $p=proc_open([PHP_BINARY,$root.'/driver.php',$root.'/request.json'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
 if($code!==0 || $error!=='') throw new RuntimeException($error);return $body;
}
function formCheck($ok,$why) {if(!$ok)throw new RuntimeException($why);}
try {
 $html=formRequest($root,[]);
 formCheck(strpos($html,'Fixture &lt;TTS&gt;')!==false && strpos($html,'value="ws:7"')!==false,'Provider rendering/escaping');
 formCheck(strpos($html,'id="tts-provider-dialog"')!==false && strpos($html,'name="tts_endpoint"')!==false,'Registration modal missing');
 $post=['action'=>'add_tts_provider','csrf'=>'valid','tts_name'=>'Internal server','tts_endpoint'=>'ws://172.16.10.26:8900'];
 $saved=json_decode(formRequest($root,['post'=>$post]),true);
 formCheck($saved['created']===['Internal server','ws://172.16.10.26:8900'] && strpos($saved['target'],'tts=ws:8')!==false,'Registration/selection failed');
 foreach ([['csrf'=>'wrong'],['tts_endpoint'=>'http://host'],['tts_name'=>'']] as $bad) {
  $html=formRequest($root,['post'=>array_merge($post,$bad)]);
  formCheck(strpos($html,'"created":')===false,'Invalid input persisted');
  formCheck(strpos($html,isset($bad['csrf'])?'CSRF rejected':(isset($bad['tts_endpoint'])?'URL WebSocket válida':'nombre debe tener'))!==false,'Invalid input not explained');
 }
 $html=formRequest($root,['get'=>['id'=>99,'campaign_id'=>50],'post'=>$post]);
 formCheck(strpos($html,'Scope rejected')!==false && strpos($html,'"created":')===false,'Scope bypass');
 echo "PASS: real TTS form rendering, escaping, registration, selected redirect, CSRF/input/scope guards\n";
} finally {
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($iterator as $path) {$path->isDir()?rmdir($path->getPathname()):unlink($path->getPathname());}rmdir($root);
}
