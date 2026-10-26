<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/Config.php';

function campaign_monitor_cache_directory(): string {
    $runtime=\Config\Config::deployment('runtime');
    if(!is_string($runtime) || $runtime==='')throw new RuntimeException('Runtime del monitor no configurado.');
    return $runtime.'/campaign_monitor';
}

// Puerta unica de lectura: recorta el snapshot a las campanas asignadas al
// GTR o supervisor de la sesion. Asi el filtro cubre tanto el render inicial
// como el poll del monitor, y ningun consumidor futuro puede saltarselo.
// El nivel 9 y los procesos sin sesion (worker, CLI) leen todo.
function campaign_monitor_cache_read(): array {
    $data=campaign_monitor_cache_read_all();
    $level=(int)($_SESSION['user_level'] ?? 0);
    if(!in_array($level,[7,8],true))return $data;
    require_once __DIR__.'/../../includes/CoreUsers.php';
    $allowed=\Includes\CoreUsers::campaignsFor((string)($_SESSION['user'] ?? ''));
    $data['campaigns']=array_values(array_filter($data['campaigns'],static function($campaign)use($allowed){
        return is_array($campaign) && in_array(((string)($campaign['origin']??'')).':'.(int)($campaign['campaign_id']??0),$allowed,true);
    }));
    return $data;
}

function campaign_monitor_cache_read_all(): array {
    $path=campaign_monitor_cache_directory().'/snapshot.json';
    if(!is_file($path))throw new RuntimeException('El monitor esta preparando su primera actualizacion.');
    $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(($data['version']??null)!==1 || !is_int($data['generated_at']??null) || !is_array($data['campaigns']??null))throw new RuntimeException('La cache del monitor no es valida.');
    $age=max(0,time()-$data['generated_at']);
    return ['campaigns'=>$data['campaigns'],'updated_at'=>gmdate('c',$data['generated_at']),'age_seconds'=>$age,'stale'=>$age>15];
}

function campaign_monitor_cache_publish(array $campaigns): void {
    $dir=campaign_monitor_cache_directory();
    $json=json_encode(['version'=>1,'generated_at'=>time(),'campaigns'=>$campaigns],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $tmp=tempnam($dir,'.snapshot-');
    if($tmp===false)throw new RuntimeException('No se pudo preparar la cache.');
    try{
        if(file_put_contents($tmp,$json)!==strlen($json) || !chmod($tmp,0640) || !rename($tmp,$dir.'/snapshot.json'))throw new RuntimeException('No se pudo publicar la cache.');
    }finally{if(is_file($tmp))unlink($tmp);}
}
