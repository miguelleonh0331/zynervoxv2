<?php
require $argv[1].'/db.php';
require $argv[1].'/ivr_engine_service.php';
require $argv[1].'/call_audit_service.php';
$repo=bot_ivr_repository();$db=carsa_db();
$runtime=rtrim((string)\Config\Config::deployment('runtime'),'/');
$sample=$repo->audioLeads(1,1)[0];
$code=null;$path=null;$campaign=null;$list=null;$ids=[];
function testIvrAgi(array $args,string $reply): array {
    global $argv;
    $p=proc_open(array_merge(['/usr/bin/php',$argv[1].'/ivr_engine_agi.php'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],"agi_channel: SIP/ivr-fixture\nagi_uniqueid: fixture-leg\n\n".$reply);fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),$out,$err];
}
try {
    for($n=90;$n<=99;$n++) {
        $candidate=$runtime.'/modules/flows/published/'.$n.'.json';
        $file=@fopen($candidate,'x');
        if (!$file) continue;
        $code=$n;$path=$candidate;
        $flow=['flow_code'=>(string)$n,'start'=>'a','nodes'=>['a'=>['type'=>'noop','next'=>'b'],'b'=>['type'=>'create_audio','audio_text'=>'hola como estas {nombre}','next'=>'c'],'c'=>['type'=>'hangup']]];
        fwrite($file,json_encode($flow,JSON_THROW_ON_ERROR));fclose($file);chmod($path,0640);break;
    }
    if ($code===null) throw new RuntimeException('No free fixture flow code');
    $campaign=$repo->createCampaign('IVR engine fixture',true);
    $list=$repo->createList($campaign,'IVR engine fixture',true,$code);
    foreach (['COMPLETED','INTERRUPTED'] as $result) {
        $id='ivr-fixture-'.bin2hex(random_bytes(8));$ids[]=$id;
        $lead=$repo->startCall($list,'999000123',$id);
        $db->prepare('UPDATE zynervox_bot_list SET customer_name=?,extra_json=? WHERE lead_id=?')->execute([$sample['customer_name'],$sample['extra_json'],$lead]);
        try {$repo->ivrCallContext($list+1,$lead,$id);throw new RuntimeException('mismatched list accepted');}catch(RuntimeException $e){if($e->getMessage()!=='Invalid IVR call context')throw $e;}
        $reply=$result==='COMPLETED'?"200 result=0 endpos=8000\n200 result=1\n":"200 result=-1\n";
        [$exit,$out,$err]=testIvrAgi([(string)$list,(string)$lead,$id],$reply);
        if ($exit!==0 || substr_count($out,'STREAM FILE')!==1) throw new RuntimeException('AGI playback sequence failed');
        $s=$db->prepare("SELECT payload_json FROM zynervox_bot_call_events WHERE call_id=? AND event_type='IVR_END'");$s->execute([$id]);
        if ((json_decode($s->fetchColumn(),true)['result'] ?? '')!==$result) throw new RuntimeException('IVR end result missing');
        [$exit]=testIvrAgi([(string)$list,(string)$lead,$id],"200 result=1\n");
        if ($exit===0) throw new RuntimeException('completed execution repeated');
    }
    echo "PASS: CLI AGI playback, completed/interrupted audit, scoped identity and repeated-execution rejection\n";
} finally {
    foreach ($ids as $id) {
        foreach(glob(call_audit_directory().'/*.json') as $journal) { $data=json_decode((string)file_get_contents($journal),true);if(($data['call_id'] ?? '')===$id)unlink($journal); }
        $db->prepare('DELETE FROM zynervox_bot_call_events WHERE call_id=?')->execute([$id]);
        $db->prepare('DELETE FROM zynervox_bot_call_attempts WHERE call_id=?')->execute([$id]);
        $lock=$runtime.'/bot_ivr/ivr_calls/'.$id.'.lock';if(is_file($lock))unlink($lock);
    }
    if($list){$db->prepare('DELETE FROM zynervox_bot_list WHERE list_id=?')->execute([$list]);$db->prepare('DELETE FROM zynervox_bot_lists WHERE list_id=?')->execute([$list]);}
    if($campaign)$db->prepare('DELETE FROM zynervox_bot_campaigns WHERE campaign_id=?')->execute([$campaign]);
    if($path)unlink($path);
}
