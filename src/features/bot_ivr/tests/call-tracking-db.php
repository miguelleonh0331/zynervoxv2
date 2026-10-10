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
    echo "PASS: lead creation, list isolation, idempotence, AMD and late callbacks\n";
} finally { $db->rollBack(); }
