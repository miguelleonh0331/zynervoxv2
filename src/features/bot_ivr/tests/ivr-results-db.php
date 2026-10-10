<?php
require $argv[1].'/db.php';
$db=carsa_db();$repo=bot_ivr_repository();
function resultCheck($ok,$message) {if (!$ok) throw new RuntimeException($message);}
$db->beginTransaction();
try {
    $campaign=$repo->createCampaign('IVR result fixture',true);
    $list=$repo->createList($campaign,'IVR result fixture',true,11);
    $call='ivr-result-'.bin2hex(random_bytes(12));
    $lead=$repo->startCall($list,'999000321',$call);
    $fields=['RESULTADO'=>'AGENDADO','FECHA_AGENDADA'=>'2026-10-11','RESPUESTA_REGISTRADA'=>'mañana','CALL_ID'=>$call];
    $repo->saveIvrResult($call,'sql',$fields);$repo->saveIvrResult($call,'sql',$fields);
    $s=$db->prepare('SELECT * FROM zynervox_bot_ivr_results WHERE call_id=?');$s->execute([$call]);$rows=$s->fetchAll();
    resultCheck(count($rows)===1 && (int)$rows[0]['lead_id']===$lead && (int)$rows[0]['list_id']===$list && json_decode($rows[0]['fields_json'],true)===$fields,'idempotent scoped result');
    $repo->saveIvrResult($call,'SQL',$fields);
    $s=$db->prepare('SELECT COUNT(*) FROM zynervox_bot_ivr_results WHERE call_id=?');$s->execute([$call]);resultCheck((int)$s->fetchColumn()===2,'node IDs retain case-sensitive graph identity');
    try {$repo->saveIvrResult($call,'sql',['RESULTADO'=>'OTHER']);throw new RuntimeException('mismatching result accepted');}catch(RuntimeException $e){resultCheck($e->getMessage()==='IVR result already differs','immutable result');}
    $repo->finishCall($call,'ANSWER','','',16,true);
    try {$repo->saveIvrResult($call,'sql2',$fields);throw new RuntimeException('closed attempt accepted');}catch(RuntimeException $e){resultCheck($e->getMessage()==='Invalid IVR result context','closed attempt guard');}
    echo "PASS: core IVR results scoped by attempt, no duplicates, immutable payload, closed-call rejection; fixtures rolled back\n";
} finally {if ($db->inTransaction()) $db->rollBack();}
