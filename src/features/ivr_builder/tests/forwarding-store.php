<?php
// Root CLI integration: connection-local temporary tables, private publication dir.
if (PHP_SAPI!=='cli') exit(1);
$private=sys_get_temp_dir().'/zv2-forward-test-'.bin2hex(random_bytes(8));
mkdir($private,0700);
define('IVR_PUBLISHED_DIR',$private);
require $argv[1].'/ivr_builder/flow_store.php';
$db=new PDO('mysql:host=localhost;dbname=zynervox_core;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=['bot_ivr_flows','bot_ivr_flow_nodes','bot_ivr_flow_edges','bot_ivr_flow_intents','bot_ivr_flow_intent_phrases','bot_ivr_flow_ranges','bot_ivr_flow_segments','bot_ivr_flow_sql_fields'];
try {
    foreach ($tables as $table) {
        $db->exec("CREATE TEMPORARY TABLE zv2_copy_$table LIKE $table");
        $db->exec("CREATE TEMPORARY TABLE $table LIKE zv2_copy_$table");
        $db->exec("DROP TEMPORARY TABLE zv2_copy_$table");
    }
    $node=['type'=>'reenviar','message'=>'','audio'=>'','audio_text'=>'','audio_hash'=>'','audio_status'=>'pending','capture_mode'=>'date','variable'=>'','bridge_id'=>'','bridge_category'=>'','bridge_text'=>'','execute_url'=>'','execute_method'=>'GET','anexo'=>'','contexto'=>'','prioridad'=>1,'next'=>'','fallback'=>'','timeout_ms'=>6000,'retry_count'=>0,'retry_text'=>'','retry_audio'=>'','retry_audio_hash'=>'','position'=>['x'=>1,'y'=>1],'compose_provider'=>'deepgram','composite_hash'=>'','composite_status'=>'pending','edges'=>[],'stt_intents'=>[],'ranges'=>[],'segments'=>[],'inject_sql_fields'=>[],
    'forwarding'=>['mode'=>'trunk','protocol'=>'SIP','endpoint'=>'carrier1','number'=>'{numero}','headers'=>[['name'=>'X-Cliente','value'=>'{nombre}']]]];
    $flow=['flow_code'=>'99','name'=>'Temporary test','start'=>'forward','list_id'=>null,'nodes'=>['forward'=>$node]];
    flow_store($db,$flow);
    $db->exec("UPDATE bot_ivr_flows SET data_json=JSON_SET(data_json,'$.unrelated','keep') WHERE flow_code='99'");
    flow_store($db,$flow);
    if (json_decode($db->query("SELECT data_json FROM bot_ivr_flows WHERE flow_code='99'")->fetchColumn(),true)['unrelated']!=='keep') throw new RuntimeException('Metadata was lost');
    $loaded=flow_load($db,'99');
    if ($loaded['nodes']['forward']['forwarding']!==$node['forwarding']) throw new RuntimeException('Forwarding round trip failed');
    $json=json_encode($loaded,JSON_THROW_ON_ERROR);
    $publication=publish_flow_to_local_disk('99',$json);
    if ($publication['sha256']!==hash('sha256',file_get_contents($private.'/99.json')) || json_decode(file_get_contents($private.'/99.json'),true)['nodes']['forward']['forwarding']!==$node['forwarding']) throw new RuntimeException('Publication failed');
    unset($flow['nodes']['forward']['forwarding']);$flow['nodes']['forward']['anexo']='700';
    flow_store($db,$flow);
    if (flow_load($db,'99')['nodes']['forward']['forwarding']['mode']!=='dialplan') throw new RuntimeException('Legacy persistence failed');
    echo "PASS: temporary-table persistence, reload, atomic publication hash and old extension\n";
} finally {
    foreach (glob($private.'/*') as $file) unlink($file);
    rmdir($private);
}
