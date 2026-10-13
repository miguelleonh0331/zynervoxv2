<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/ivr_graph_io.php';
require_once __DIR__.'/call_audit_service.php';
require_once __DIR__.'/../includes/AmiClient.php';
function bot_preanswer_root(): string {
    if (!\Config\Config::deployment('isolated',false)) throw new RuntimeException('Isolated preanswer required');
    return rtrim((string)\Config\Config::deployment('runtime'),'/');
}
function bot_preanswer_paths(string $callId): array {
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$callId)) throw new RuntimeException('Invalid preanswer identity');
    $root=bot_preanswer_root();$parent=$root.'/bot_ivr/ivr_calls';
    if(realpath($parent)!==$parent)throw new RuntimeException('Invalid preanswer runtime');
    $dir=$parent.'/'.$callId;
    if(!is_dir($dir) && !mkdir($dir,0700))throw new RuntimeException('Preanswer recording unavailable');
    if(is_link($dir) || realpath($dir)!==$dir)throw new RuntimeException('Invalid preanswer directory');
    return ['dir'=>$dir,'rx'=>$dir.'/preanswer_rx.wav','recording'=>$root.'/recordings/bot_ivr/'.$callId.'.wav'];
}
function bot_preanswer_normalize(string $text): string {
    $text=strtr(mb_strtolower($text,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return trim(preg_replace('/[^a-z0-9]+/',' ',$text)??'');
}
function bot_preanswer_classify(string $text): ?string {
    $text=bot_preanswer_normalize($text);$words=array_fill_keys(explode(' ',$text),true);
    // Preserve 3006's Vosk category vocabulary, with a separate service-outage category.
    foreach(['fuera de servicio','no se encuentra en servicio','temporalmente fuera'] as $phrase)if(strpos($text,$phrase)!==false)return 'fuera_servicio';
    foreach(['existe','marcado','numero'] as $word)if(isset($words[$word]))return 'numero_no_existe';
    foreach(['sentimos','tiempo','mensaje','voz','favor','deja'] as $word)if(isset($words[$word]))return 'casilla';
    return null;
}
function bot_preanswer_wav(string $pcm,int $rate=8000): string {
    return 'RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,$rate,$rate*2,2,16).'data'.pack('V',strlen($pcm)).$pcm;
}
function bot_preanswer_snapshot(string $path,int $processedBytes=0): ?array {
    clearstatcache(true,$path);
    if(is_link($path)||!is_file($path))return null;
    $h=fopen($path,'rb');if(!$h)return null;
    try {
        $header=fread($h,12);if(strlen($header)!==12 || substr($header,0,4)!=='RIFF' || substr($header,8,4)!=='WAVE')return null;
        $format=null;$start=null;
        while(ftell($h)<8192){
            $chunk=fread($h,8);if(strlen($chunk)!==8)return null;$length=unpack('V',substr($chunk,4))[1];
            if(substr($chunk,0,4)==='data'){$start=ftell($h);break;}
            if(substr($chunk,0,4)==='fmt '){if($length<16 || $length>1024)return null;$format=unpack('vcodec/vchannels/Vrate/Vbytes/vblock/vbits',fread($h,16));fseek($h,$length-16+($length%2),SEEK_CUR);}
            else {if($length>8192)return null;fseek($h,$length+($length%2),SEEK_CUR);}
        }
        if($start===null || !$format || $format['codec']!==1 || $format['channels']!==1 || $format['bits']!==16 || $format['rate']!==8000)return null;
        // Growing MixMonitor WAV headers can still declare zero data: use the actual file size.
        $bytes=max(0,(int)fstat($h)['size']-$start);$bytes-=($bytes%2);if($bytes<3200)return null;
        // Start at the first announcement even when queued; later windows overlap by two seconds.
        $offset=max(0,min($bytes,$processedBytes)-32000);$length=min(96000,$bytes-$offset);
        fseek($h,$start+$offset);$pcm=fread($h,$length);$pcm=substr($pcm,0,strlen($pcm)-strlen($pcm)%2);
        return ['wav'=>bot_preanswer_wav($pcm),'audio_bytes'=>$offset+strlen($pcm),'total_bytes'=>$bytes,'window_ms'=>(int)(strlen($pcm)/16)];
    }finally{fclose($h);}
}
function bot_preanswer_event(string $callId,string $type,array $data,string $key): void {
    $entry=['call_id'=>$callId,'type'=>$type,'event_id'=>hash('sha256',$callId.'|'.$type.'|'.$key),'epoch'=>time(),'data'=>$data+['stage'=>'preanswer']];
    $journal=call_audit_write($entry);call_audit_apply($entry);unlink($journal);
}
function bot_preanswer_process(string $callId): void {
    $repo=bot_ivr_repository();$call=$repo->preanswerCall($callId);if(!$call)return;
    $paths=bot_preanswer_paths($callId);
    $lease=fopen($paths['dir'].'/.preanswer.lock','c');if(!$lease || !flock($lease,LOCK_EX|LOCK_NB)){if(is_resource($lease))fclose($lease);return;}
    $tmp=null;
    try {
        $call=$repo->preanswerCall($callId);
        if(!$call || $call['answered_at']!==null || $call['dial_status']==='ANSWER' || $call['status']==='LOST' || $call['end_source']==='RECOVERY')return;
        $snapshot=bot_preanswer_snapshot($paths['rx'],(int)$call['preanswer_bytes']);$closed=$call['finished_at']!==null;
        if(!$snapshot || $snapshot['audio_bytes']<=(int)$call['preanswer_bytes']){
            if($closed)bot_preanswer_event($callId,'PREANSWER_DONE',['reason'=>'closed_without_new_audio'],'closed');return;
        }
        if(!$closed && $snapshot['audio_bytes']-(int)$call['preanswer_bytes']<32000)return;
        $tmp=tempnam($paths['dir'],'.preanswer-');if($tmp===false)throw new RuntimeException('Snapshot unavailable');
        file_put_contents($tmp,$snapshot['wav']);chmod($tmp,0600);
        // Adapter requires a WAV filename; private random snapshots never come from HTTP.
        $wav=$tmp.'.wav';if(!rename($tmp,$wav))throw new RuntimeException('Snapshot publication failed');$tmp=$wav;
        $result=bot_ivr_graph_io('transcribe',['path'=>$wav,'provider'=>'amd']);
        $data=['text'=>(string)($result['text']??''),'provider'=>$result['provider']??'vosk','error'=>$result['error']??'','audio_bytes'=>$snapshot['audio_bytes'],'window_ms'=>$snapshot['window_ms'],'recording'=>$paths['rx'],'stage'=>'preanswer'];
        $retry=$data['error']!=='' && $data['error']!=='no_audio';
        if($retry)$data['audio_bytes']=(int)$call['preanswer_bytes'];
        bot_preanswer_event($callId,'PREANSWER_CAPTURE',$data,$snapshot['audio_bytes'].'|'.$data['error'].'|'.time());
        if($retry)return;
        $category=!empty($result['ok'])?bot_preanswer_classify($data['text']):null;
        if($category!==null){
            $data['category']=$category;
            $mayHangup=$repo->savePreanswerResult($callId,hash('sha256',$callId.'|PREANSWER_RESULT'),time(),$data);
            if($mayHangup){
                $current=$repo->preanswerCall($callId);
                if($current && $current['answered_at']===null && $current['finished_at']===null && $current['callee_channel']!==''){
                    $ami=new \Includes\AmiClient(\Includes\PbxAmi::load());
                    try {
                        $state=$ami->request(['Action'=>'Getvar','Channel'=>$current['callee_channel'],'Variable'=>'CHANNEL(state)']);
                        $current=$repo->preanswerCall($callId);
                        if(($state['Response']??'')==='Success' && in_array($state['Value']??'',['Down','Rsrvd','OffHook','Dialing','Ring','Ringing','Pre-ring'],true) && $current && $current['answered_at']===null && $current['finished_at']===null){
                            $reply=$ami->request(['Action'=>'Hangup','Channel'=>$current['callee_channel']]);
                            bot_preanswer_event($callId,'PREANSWER_DONE',['reason'=>'detected','hangup_ok'=>($reply['Response']??'')==='Success'],'detected');
                        }
                    }finally{$ami->close();}
                }
            }
        }elseif($closed && $snapshot['audio_bytes']>=$snapshot['total_bytes'])bot_preanswer_event($callId,'PREANSWER_DONE',['reason'=>'closed_without_match'],'closed');
    }finally{if($tmp!==null && is_file($tmp))unlink($tmp);flock($lease,LOCK_UN);fclose($lease);}
}
