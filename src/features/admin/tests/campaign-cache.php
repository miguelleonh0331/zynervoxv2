<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/campaign-cache-'.bin2hex(random_bytes(6));
mkdir($root.'/modules/admin',0777,true);mkdir($root.'/config');mkdir($root.'/runtime/campaign_monitor',0777,true);
copy(__DIR__.'/../../../../app/web/modules/admin/campaign_monitor_cache.php',$root.'/modules/admin/campaign_monitor_cache.php');
file_put_contents($root.'/config/Config.php','<?php namespace Config; class Config {static function deployment($key){return '.var_export($root.'/runtime',true).';}}');
require $root.'/modules/admin/campaign_monitor_cache.php';
function cacheCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
try{
 try{campaign_monitor_cache_read();throw new LogicException('Missing cache accepted');}catch(RuntimeException $expected){}
 $rows=[['campaign_id'=>50,'origin'=>'bot_ivr','total'=>3000,'states'=>[['status'=>'NEW','count'=>1000]]]];
 campaign_monitor_cache_publish($rows);$first=campaign_monitor_cache_read();
 cacheCheck($first['campaigns']===$rows && !$first['stale'],'Fresh snapshot changed its counters');
 for($i=0;$i<100;$i++){$read=campaign_monitor_cache_read();cacheCheck($read['campaigns']===$first['campaigns'] && $read['updated_at']===$first['updated_at'],'Readers must share the same published snapshot');}
 $path=campaign_monitor_cache_directory().'/snapshot.json';$hash=hash_file('sha256',$path);
 try{campaign_monitor_cache_publish([INF]);throw new LogicException('Invalid JSON accepted');}catch(JsonException $expected){}
 cacheCheck(hash_file('sha256',$path)===$hash,'Failed generation must preserve last good snapshot');
 $data=json_decode(file_get_contents($path),true);$data['generated_at']=time()-30;file_put_contents($path,json_encode($data));
 $stale=campaign_monitor_cache_read();cacheCheck($stale['stale'] && $stale['campaigns']===$rows && $stale['age_seconds']>=30,'Old data must remain visible and marked stale');
 file_put_contents($path,'{"version":2}');
 try{campaign_monitor_cache_read();throw new LogicException('Corrupt schema accepted');}catch(RuntimeException $expected){}
 cacheCheck(count(glob(campaign_monitor_cache_directory().'/.snapshot-*'))===0,'Publication left temporary files');
 echo "CAMPAIGN_CACHE_OK: missing/fresh/shared/stale snapshots, failed publication preservation, schema, cleanup\n";
}finally{
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
