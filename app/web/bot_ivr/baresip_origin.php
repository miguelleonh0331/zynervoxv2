<?php
declare(strict_types=1);

function bot_baresip_request(array $request): array {
    $process=proc_open(['/usr/bin/sudo','-n','/usr/local/bin/python3.11','/usr/local/libexec/zynervoxv2-baresip-origin.py'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('No se pudo contactar al origen Baresip.');
    fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($process);
    $reply=json_decode((string)$output,true);
    if($code!==0 || !is_array($reply) || empty($reply['ok']))throw new RuntimeException('El origen Baresip no pudo completar la solicitud. Revisa su instalacion y el pool.');
    return $reply;
}

function bot_baresip_enabled(string $prefix): bool {
    if($prefix!=='7307' || !is_file('/etc/zynervoxv2/baresip-origin.json'))return false;
    $config=json_decode((string)file_get_contents('/etc/zynervoxv2/baresip-origin.json'),true,512,JSON_THROW_ON_ERROR);
    return !empty($config['enabled']);
}

final class BotBaresipOrigin {
    private $repo;private $ami;private $runId;private $calls=[];
    public function __construct($repo,$ami,string $runId){$this->repo=$repo;$this->ami=$ami;$this->runId=$runId;}
    public function reserveSlot(string $callId): bool {
        $reply=bot_baresip_request(['action'=>'reserve','call_id'=>$callId,'run_id'=>$this->runId]);
        return !empty($reply['available']);
    }
    public function releaseSlot(string $callId): void {bot_baresip_request(['action'=>'release','call_id'=>$callId]);}
    public function originate(array $lead,string $prefix,string $callerId): bool {
        if($prefix!=='7307')throw new RuntimeException('Origen Baresip incompatible.');
        // A lost acknowledgement is never retried: the broker owns the ambiguous lease.
        bot_baresip_request(['action'=>'dial','call_id'=>$lead['call_id']]);
        $this->calls[$lead['call_id']]=true;return true;
    }
    public function ping(): void {
        $this->ami->ping();
        $reply=bot_baresip_request(['action'=>'poll','calls'=>array_keys($this->calls)]);
        foreach($reply['failed'] as $id){
            $this->repo->finishCall($id,'CHANUNAVAIL','','',0,false);
            $this->repo->recordCallEvent($id,'DIAL_REJECTED',hash('sha256',$id.'|BARESIP_REJECTED'),time(),['reason'=>'Baresip origin ended before SIP channel creation']);
        }
        foreach($reply['closed'] as $id)unset($this->calls[$id]);
    }
    public function close(): void {$this->ami->close();}
}
