<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/Auth.php';
require_once __DIR__.'/../../bot_ivr/db.php';
require_once __DIR__.'/../../includes/Dialer.php';
use Includes\Dialer;
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(empty($_SESSION['user']) || !in_array((int)($_SESSION['user_level']??0),[7,8,9],true)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sesion requerida.']);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    if((int)($_SESSION['user_level']??0)!==9){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sesion de administrador requerida.']);exit;}
    if(!is_string($_POST['csrf']??null) || empty($_SESSION['campaign_monitor_csrf']) || !hash_equals($_SESSION['campaign_monitor_csrf'],$_POST['csrf'])){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sesion invalida. Recarga la pagina.']);exit;}
    try{
        $id=filter_var($_POST['campaign_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $action=$_POST['action']??null;
        if(!$id || !in_array($action,['play','stop'],true) || !in_array($_POST['origin']??null,['bot_ivr','agents'],true))throw new RuntimeException('Campana o accion invalida.');
        $actor=(string)$_SESSION['user'];session_write_close();
        if($_POST['origin']==='agents'){
            Dialer::setEngineState($id,$action==='stop'?'STOPPED':'RUNNING');
            $message=$action==='stop'?'Nuevas llamadas detenidas; las conversaciones actuales continuan.':'Motor de la campana iniciado.';
        }else{
            require_once __DIR__.'/../../bot_ivr/list_call_service.php';
            $repo=bot_ivr_repository();$campaign=$repo->campaign($id);
            if($action==='play' && !$campaign['active'])throw new RuntimeException('La campana esta inactiva.');
            $started=0;$running=0;$stopped=0;$errors=[];
            foreach($repo->lists($id) as $list){
                if($action==='play' && !$list['active'])continue;
                $listId=(int)$list['list_id'];
                try{
                    $run=bot_list_call_status($listId,$id);
                    if($action==='stop'){
                        if($run && in_array($run['state'],['starting','running','stopping'],true)){bot_list_call_stop($listId,$id,$run['run_id']);$stopped++;}
                        continue;
                    }
                    if($run && in_array($run['state'],['starting','running','stopping'],true)){$running++;continue;}
                    bot_list_call_start($listId,$id,(int)$list['call_channels'],$actor);$started++;
                }catch(Throwable $e){$errors[]='Lista #'.$listId.': '.($e instanceof RuntimeException?$e->getMessage():'No se pudo completar la accion.');}
            }
            $message=$action==='stop'?'Detencion solicitada en '.$stopped.' listas; las llamadas actuales continuan.':'Listas iniciadas: '.$started.'. Ya en marcha: '.$running.'.';
            if($errors)$message.=' '.implode(' ',$errors);
            if($action==='play' && !$started && !$running)throw new RuntimeException($errors?$message:'La campana no tiene listas activas.');
            if($action==='stop' && !$stopped && $errors)throw new RuntimeException($message);
        }
        echo json_encode(['ok'=>true,'message'=>$message],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'No se pudo controlar la campana.']);}
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
try{
    $campaigns=bot_ivr_repository()->campaignMonitor();
    try{$campaigns=array_merge($campaigns,Dialer::campaignMonitor());}catch(Throwable $e){}
    echo json_encode(['ok'=>true,'campaigns'=>$campaigns],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'No se pudo consultar las campanas.']);}
