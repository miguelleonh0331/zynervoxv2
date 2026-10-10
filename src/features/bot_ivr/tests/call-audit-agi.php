<?php
declare(strict_types=1);
require $argv[1].'/db.php';
require $argv[1].'/call_audit_service.php';
$repo=bot_ivr_repository();$db=carsa_db();
$campaign=$repo->createCampaign('AGI audit fixture',true);
$list=$repo->createList($campaign,'AGI audit fixture',true);
$id='audit-test-'.bin2hex(random_bytes(10));
function audit_run(array $args,string $channel,string $unique): void {
    global $argv;
    $p=proc_open(array_merge(['/usr/bin/php',$argv[1].'/call_tracking_agi.php'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('AGI launch failed');
    fwrite($pipes[0],"agi_channel: $channel\nagi_uniqueid: $unique\n\n".str_repeat("200 result=1\n",10));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0)throw new RuntimeException('AGI failed: '.$err);
}
try {
    audit_run(['start',(string)$list,'999000123',$id,'7306','carrier1',$id],'SIP/test-caller',$id);
    audit_run(['event',$id,'OUTBOUND'],'SIP/test-callee',$id.'-leg');
    audit_run(['event',$id,'ANSWER'],'SIP/test-callee',$id.'-leg');
    audit_run(['event',$id,'AMD','HUMAN','HUMAN-800'],'SIP/test-callee',$id.'-leg');
    audit_run(['finish',$id,'ANSWER','HUMAN','HUMAN-800','16','1'],'SIP/test-caller',$id);
    audit_run(['finish',$id,'ANSWER','HUMAN','HUMAN-800','16','1'],'SIP/test-caller',$id);
    $s=$db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=?');$s->execute([$id]);$row=$s->fetch();
    if($row['status']!=='ANSWER' || $row['answered_at']===null || $row['finished_at']===null || $row['linked_id']!==$id || $row['callee_unique_id']!==$id.'-leg')throw new RuntimeException('CDR incomplete');
    $s=$db->prepare('SELECT COUNT(*) FROM zynervox_bot_call_events WHERE call_id=?');$s->execute([$id]);
    if((int)$s->fetchColumn()!==5)throw new RuntimeException('Events duplicated');
    $entry=['call_id'=>$id.'-missing','type'=>'FINISH','event_id'=>hash('sha256',$id.'pending'),'epoch'=>time(),'data'=>['dial_status'=>'NOANSWER','amd_status'=>'','amd_cause'=>'','hangup_cause'=>19,'answered'=>false]];
    $journal=call_audit_write($entry);$failed=false;
    try {call_audit_apply($entry);}catch(Throwable $e){$failed=true;}
    if(!$failed || !is_file($journal))throw new RuntimeException('Failed event not retained');
    unlink($journal);
    echo "PASS: AGI protocol, five events, CDR, duplicate finish and failed-event journal\n";
} finally {
    foreach(glob(call_audit_directory().'/*.json') as $file) {
        $entry=json_decode((string)file_get_contents($file),true);
        if(strpos($entry['call_id']??'',$id)===0)unlink($file);
    }
    $db->prepare('DELETE FROM zynervox_bot_call_events WHERE call_id=?')->execute([$id]);
    $db->prepare('DELETE FROM zynervox_bot_call_attempts WHERE list_id=?')->execute([$list]);
    $db->prepare('DELETE FROM zynervox_bot_list WHERE list_id=?')->execute([$list]);
    $db->prepare('DELETE FROM zynervox_bot_lists WHERE list_id=?')->execute([$list]);
    $db->prepare('DELETE FROM zynervox_bot_campaigns WHERE campaign_id=?')->execute([$campaign]);
}
