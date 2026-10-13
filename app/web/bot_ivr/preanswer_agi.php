<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');require __DIR__.'/preanswer_service.php';
$env=[];while(($line=fgets(STDIN))!==false && trim($line)!==''){$pair=explode(':',trim($line),2);if(count($pair)===2)$env[$pair[0]]=trim($pair[1]);}
try {
    $callId=(string)($argv[1]??'');$repo=bot_ivr_repository();$call=$repo->preanswerCall($callId);
    if(!$call || $call['answered_at']!==null || $call['finished_at']!==null)exit;
    $paths=bot_preanswer_paths($callId);
    foreach(['rx','recording'] as $key)if(is_link($paths[$key])||is_file($paths[$key]))throw new RuntimeException('Recording already exists');
    $args=$paths['recording'].',r('.$paths['rx'].')i(ZV2_PREANSWER_MONITOR)';
    echo 'EXEC MixMonitor "'.$args.'"'."\n";fflush(STDOUT);$reply=fgets(STDIN);
    if($reply===false || strpos($reply,'result=-1')!==false)exit;
    bot_preanswer_event($callId,'PREANSWER_START',['recording'=>$paths['rx'],'full_recording'=>$paths['recording'],'channel'=>$env['agi_channel']??'','unique_id'=>$env['agi_uniqueid']??''],'start');
}catch(Throwable $e){fwrite(STDERR,'ZV2 preanswer setup failed: '.get_class($e)."\n");exit(1);}
