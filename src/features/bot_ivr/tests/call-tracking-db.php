<?php
declare(strict_types=1);
require $argv[1].'/db.php';
$db=carsa_db(); $repo=bot_ivr_repository();
function call_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db->beginTransaction();
try {
    $campaign=$repo->createCampaign('Tracking fixture',true);
    $list=$repo->createList($campaign,'Tracking fixture',true);
    $id='test-'.bin2hex(random_bytes(12)); $phone='999000123';
    $lead=$repo->startCall($list,$phone,$id);
    call_check($repo->startCall($list,$phone,$id)===$lead,'Repeated start changed lead');
    call_check($repo->leadCount($list)===1,'Duplicated lead');
    $read=$db->prepare('SELECT status,called_count FROM zynervox_bot_list WHERE lead_id=?');
    $read->execute([$lead]); call_check((int)$read->fetch()['called_count']===1,'Count duplicated');
    $repo->finishCall($id,'ANSWER','MACHINE','MAXWORDS-3-2',16,true);
    $repo->finishCall($id,'BUSY','','',17,false);
    $read->execute([$lead]); call_check($read->fetch()['status']==='AA','Repeated finish or AMD lost');
    $new=$id.'-new'; $repo->startCall($list,$phone,$new);
    $repo->finishCall($new,'NOANSWER','','',19,false);
    $read->execute([$lead]); $row=$read->fetch();
    call_check($row['status']==='NA' && (int)$row['called_count']===2,'Second attempt wrong');
    $old=$id.'-late'; $repo->startCall($list,$phone,$old);
    $latest=$id.'-latest'; $repo->startCall($list,$phone,$latest);
    $repo->finishCall($latest,'BUSY','','',17,false);
    $repo->finishCall($old,'ANSWER','HUMAN','',16,true);
    $read->execute([$lead]); call_check($read->fetch()['status']==='B','Late callback overwrote latest');
    call_check($repo->leadCount($list)===1,'Same phone duplicated');
    $other=$repo->createList($campaign,'Other',true);
    call_check($repo->startCall($other,$phone,$id.'-other')!==$lead,'List isolation broken');
    $rejected=false;
    try { $repo->startCall($other,$phone,$id); } catch (RuntimeException $e) { $rejected=true; }
    call_check($rejected,'Call ID mismatch accepted');
    $audit=$id.'-audit'; $repo->startCall($list,$phone,$audit);
    $start=['linked_id'=>$audit,'channel'=>'SIP/test-caller','prefix'=>'7306','carrier'=>'carrier1'];
    $repo->recordCallEvent($audit,'START',hash('sha256',$audit.'start'),1000,$start);
    $repo->recordCallEvent($audit,'OUTBOUND',hash('sha256',$audit.'out'),1002,['channel'=>'SIP/test-callee','unique_id'=>'callee-1']);
    $repo->recordCallEvent($audit,'ANSWER',hash('sha256',$audit.'answer'),1010,[]);
    $repo->recordCallEvent($audit,'AMD',hash('sha256',$audit.'amd'),1012,['amd_status'=>'MACHINE','amd_cause'=>'MAXWORDS-3-2']);
    $repo->finishCall($audit,'ANSWER','','',16,true);
    $repo->recordCallEvent($audit,'FINISH',hash('sha256',$audit.'finish'),1030,[]);
    $repo->recordCallEvent($audit,'FINISH',hash('sha256',$audit.'finish'),1050,[]);
    $s=$db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=?'); $s->execute([$audit]); $cdr=$s->fetch();
    call_check((int)$cdr['duration_seconds']===30 && (int)$cdr['billsec']===20,'CDR timing wrong');
    call_check($cdr['status']==='AA' && $cdr['callee_unique_id']==='callee-1','AMD or channel correlation lost');
    $s=$db->prepare('SELECT COUNT(*) FROM zynervox_bot_call_events WHERE call_id=?'); $s->execute([$audit]);
    call_check((int)$s->fetchColumn()===5,'Event audit duplicated');
    $lost=$id.'-lost';$repo->startCall($list,$phone,$lost);
    call_check(!$repo->observeCallPresence($lost,false,2000),'No recovery grace');
    call_check(!$repo->observeCallPresence($lost,false,2050),'Recovered too early');
    call_check(!$repo->observeCallPresence($lost,true,2100),'Active channel recovered');
    call_check(!$repo->observeCallPresence($lost,false,2200),'Missing observation not reset');
    call_check($repo->observeCallPresence($lost,false,2321),'Missing closure not flagged');
    $s=$db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=?');$s->execute([$lost]);$row=$s->fetch();
    call_check($row['status']==='LOST' && $row['finished_at']===null && $row['billsec']===null,'Recovery fabricated CDR');
    $repo->finishCall($lost,'NOANSWER','','',19,false);
    $s->execute([$lost]);call_check($s->fetch()['status']==='NA','Real result cannot replace recovery');
    echo "PASS: lead creation, list isolation, idempotence, AMD and late callbacks\n";
} finally { $db->rollBack(); }
