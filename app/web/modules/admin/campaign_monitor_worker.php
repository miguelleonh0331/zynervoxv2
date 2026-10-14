<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/campaign_monitor_cache.php';
require_once __DIR__.'/../../bot_ivr/db.php';
require_once __DIR__.'/../../includes/Dialer.php';
$lock=fopen(campaign_monitor_cache_directory().'/worker.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Otro worker del monitor esta activo o no hay acceso al directorio.\n");exit(1);}
do{
    $start=microtime(true);
    try{
        $campaigns=bot_ivr_repository()->campaignMonitor();
        $campaigns=array_merge($campaigns,\Includes\Dialer::campaignMonitor());
        campaign_monitor_cache_publish($campaigns);
    }catch(Throwable $e){fwrite(STDERR,"No se pudo actualizar el monitor; se conserva la ultima copia.\n");exit(1);}
    usleep((int)(max(.1,5-(microtime(true)-$start))*1000000));
}while(true);
