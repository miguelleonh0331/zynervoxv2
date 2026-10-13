<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');set_time_limit(0);umask(0077);
require __DIR__.'/preanswer_service.php';
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))!==__FILE__)return;
try {
    if(carsa_db()->query('SELECT DATABASE()')->fetchColumn()!=='zynervox_core')throw new RuntimeException('Invalid preanswer database');
    $options=getopt('',['call:']);
    if(isset($options['call'])){bot_preanswer_process((string)$options['call']);exit;}
    $root=bot_preanswer_root();$lease=fopen($root.'/bot_ivr/call_jobs/.preanswer-worker.lock','c');if(!$lease || !flock($lease,LOCK_EX|LOCK_NB))throw new RuntimeException('Preanswer worker already running');
    $stopping=false;$jobs=[];$next=[];
    if(function_exists('pcntl_signal')){pcntl_async_signals(true);pcntl_signal(SIGTERM,function()use(&$stopping){$stopping=true;});pcntl_signal(SIGINT,function()use(&$stopping){$stopping=true;});}
    while(!$stopping || $jobs){
        foreach($jobs as $id=>$job){$state=proc_get_status($job['process']);
            if(!$state['running']){proc_close($job['process']);unset($jobs[$id]);$next[$id]=microtime(true)+2;}
            elseif(microtime(true)-$job['started']>40){proc_terminate($job['process'],9);}
        }
        if(!$stopping && count($jobs)<12){
            try{
                foreach(bot_ivr_repository()->pendingPreanswerCalls() as $call){
                    $id=$call['call_id'];if(isset($jobs[$id]) || ($next[$id]??0)>microtime(true))continue;
                    $process=proc_open([PHP_BINARY,__FILE__,'--call',$id],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','php://stderr','w']],$pipes);
                    if(!is_resource($process))throw new RuntimeException('Preanswer child unavailable');$jobs[$id]=['process'=>$process,'started'=>microtime(true)];
                    if(count($jobs)>=12)break;
                }
            }catch(Throwable $e){fwrite(STDERR,'ZV2 preanswer scan failed: '.get_class($e)."\n");sleep(2);}
        }
        foreach($next as $id=>$at)if($at<microtime(true)-180)unset($next[$id]);
        usleep(250000);
    }
    fclose($lease);
}catch(Throwable $e){fwrite(STDERR,'ZV2 preanswer worker failed: '.get_class($e)."\n");exit(1);}
