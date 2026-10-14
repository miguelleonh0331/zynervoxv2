<?php
// Isolated route fixtures: no database, AMI, worker or real calls.
declare(strict_types=1);
$root=sys_get_temp_dir().'/campaign-play-'.bin2hex(random_bytes(6));
mkdir($root.'/modules/admin',0777,true);mkdir($root.'/includes',0777,true);mkdir($root.'/bot_ivr',0777,true);
copy(__DIR__.'/../../../../app/web/modules/admin/campaign_monitor_api.php',$root.'/modules/admin/campaign_monitor_api.php');
file_put_contents($root.'/includes/Auth.php','<?php');
file_put_contents($root.'/modules/admin/campaign_monitor_cache.php','<?php function campaign_monitor_cache_read(){return ["campaigns"=>[],"updated_at"=>gmdate("c"),"age_seconds"=>0,"stale"=>false];}');
file_put_contents($root.'/includes/Dialer.php', <<<'PHP'
<?php
namespace Includes;
class Dialer {
 public static function setEngineState($id,$state){file_put_contents($GLOBALS['log'],json_encode(['agent',$id,$state])."\n",FILE_APPEND);}
 public static function campaignMonitor(){throw new \RuntimeException('Web must not query agent statistics');}
}
PHP
);
file_put_contents($root.'/bot_ivr/db.php', <<<'PHP'
<?php
function bot_ivr_repository(){return new class {
 public function campaign($id){return ['active'=>$GLOBALS['scenario']!=='inactive'];}
 public function lists($id){return $GLOBALS['scenario']==='empty'?[]:[['list_id'=>56,'active'=>1,'call_channels'=>25],['list_id'=>57,'active'=>0,'call_channels'=>3],['list_id'=>58,'active'=>1,'call_channels'=>10],['list_id'=>59,'active'=>1,'call_channels'=>5]];}
 public function campaignMonitor(){throw new RuntimeException('Web must not query Bot IVR statistics');}
};}
PHP
);
file_put_contents($root.'/bot_ivr/list_call_service.php', <<<'PHP'
<?php
function bot_list_call_status($list,$campaign){
 if(in_array($GLOBALS['scenario'],['stop','stop-error'],true))return $list===59?['state'=>'completed','run_id'=>'finished']:['state'=>$list===58?'stopping':($list===57?'starting':'running'),'run_id'=>str_repeat((string)($list-50),32)];
 return $list===58?['state'=>'running','run_id'=>str_repeat('a',32)]:null;
}
function bot_list_call_stop($list,$campaign,$run){
 if($GLOBALS['scenario']==='stop-error' && $list===58)throw new RuntimeException('La marcacion cambio.');
 file_put_contents($GLOBALS['log'],json_encode(['stop',$list,$campaign,$run])."\n",FILE_APPEND);
}
function bot_list_call_start($list,$campaign,$channels,$actor){
 if($list===59 || $GLOBALS['scenario']==='failed')throw new RuntimeException('Los audios de la lista deben estar listos.');
 file_put_contents($GLOBALS['log'],json_encode(['bot',$list,$campaign,$channels,$actor])."\n",FILE_APPEND);
}
PHP
);
function requestFixture(array $post,array $session,string $method='POST',string $scenario='normal'): array {
 global $root;
 $log=$root.'/calls.jsonl';file_put_contents($log,'');
 $code='session_start();$_SESSION=json_decode(base64_decode($argv[1]),true);$_POST=json_decode(base64_decode($argv[2]),true);$_SERVER["REQUEST_METHOD"]=$argv[3];$GLOBALS["scenario"]=$argv[4];$GLOBALS["log"]=$argv[5];require $argv[6];';
 $p=proc_open([PHP_BINARY,'-r',$code,base64_encode(json_encode($session)),base64_encode(json_encode($post)),$method,$scenario,$log,$root.'/modules/admin/campaign_monitor_api.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);
 if($status!==0 || $err!=='')throw new RuntimeException('Route error: '.$err);
 return [json_decode($out,true,512,JSON_THROW_ON_ERROR),file_get_contents($log)];
}
function checkFixture(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
try{
 $session=['user'=>'fixture-admin','user_level'=>9,'campaign_monitor_csrf'=>'valid'];
 $post=['action'=>'play','csrf'=>'valid','origin'=>'bot_ivr','campaign_id'=>'50'];
 foreach([[],array_replace($session,['user_level'=>8]),array_replace($session,['user_level'=>7])] as $denied){
  [$data,$calls]=requestFixture($post,$denied);checkFixture(!$data['ok'] && $calls==='','Authorization must prevent calls');
 }
 foreach([['csrf'=>'wrong'],['origin'=>'legacy'],['campaign_id'=>'0'],['campaign_id'=>['50']],['action'=>'delete']] as $invalid){
  [$data,$calls]=requestFixture(array_replace($post,$invalid),$session);checkFixture(!$data['ok'] && $calls==='','Invalid input must prevent calls');
 }
 [$data,$calls]=requestFixture($post,$session,'GET');checkFixture($data['ok'] && $calls==='','GET must remain read only');
 [$data,$calls]=requestFixture($post,$session,'PUT');checkFixture(!$data['ok'] && $calls==='','Unsupported method must prevent calls');
 [$data,$calls]=requestFixture($post,$session);
 checkFixture($data['ok'] && strpos($data['message'],'Lista #59: Los audios')!==false,'Partial failures must be reported');
 checkFixture($calls===json_encode(['bot',56,50,25,'fixture-admin'])."\n",'Only idle active lists may start with stored channels and correct scope');
 foreach(['inactive','empty'] as $scenario){[$data,$calls]=requestFixture($post,$session,'POST',$scenario);checkFixture(!$data['ok'] && $calls==='','Inactive/empty campaigns must not start');}
 [$data,$calls]=requestFixture(array_replace($post,['origin'=>'agents']),$session);
 checkFixture($data['ok'] && $calls===json_encode(['agent',50,'RUNNING'])."\n",'Agents must use existing engine control, never Bot IVR');
 $stop=array_replace($post,['action'=>'stop']);
 foreach([[],array_replace($session,['user_level'=>8]),array_replace($session,['user_level'=>7])] as $denied){[$data,$calls]=requestFixture($stop,$denied);checkFixture(!$data['ok'] && $calls==='','Stop authorization must prevent mutations');}
 [$data,$calls]=requestFixture(array_replace($stop,['csrf'=>'bad']),$session);checkFixture(!$data['ok'] && $calls==='','Stop CSRF must prevent mutations');
 [$data,$calls]=requestFixture($stop,$session,'POST','stop');
 $expected='';foreach([56,57,58] as $list)$expected.=json_encode(['stop',$list,50,str_repeat((string)($list-50),32)])."\n";
 checkFixture($data['ok'] && $calls===$expected,'Stop must target exact current run of all busy lists, including inactive lists, and skip finished runs');
 [$data,$calls]=requestFixture($stop,$session,'POST','stop-error');checkFixture($data['ok'] && strpos($data['message'],'Lista #58: La marcacion cambio.')!==false,'Stop partial failures must be visible');
 [$data,$calls]=requestFixture($stop,$session,'POST','empty');checkFixture($data['ok'] && $calls==='','Stop with no runs must be idempotent');
 [$data,$calls]=requestFixture(array_replace($stop,['origin'=>'agents']),$session);checkFixture($data['ok'] && $calls===json_encode(['agent',50,'STOPPED'])."\n",'Agent Stop must use existing engine drain');
 echo "CAMPAIGN_CONTROLS_OK: play/stop auth, CSRF, scope, channels, current runs, inactive lists, partial errors, agents, idempotent stop\n";
}finally{
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
