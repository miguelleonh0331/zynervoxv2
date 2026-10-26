<?php
declare(strict_types=1);
// Comprueba el registro de la llamada entrante: ruta del DID, reutilización del
// lead, aislamiento del cupo saliente y el bloqueo del marcador por agent_slot.
// No toca MariaDB operativa: clona el esquema en una base desechable.
if (PHP_SAPI!=='cli' || !getenv('DIALER_TEST_WEB_ROOT')) exit(1);
require getenv('DIALER_TEST_WEB_ROOT').'/includes/Dialer.php';
use Includes\Dialer;
use Includes\Database;

function inboundAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
// Exception y no RuntimeException: la validación del panel lanza
// InvalidArgumentException, que no hereda de RuntimeException.
function inboundRejects(callable $run,string $expected): void {
    try { $run(); } catch (Exception $error) {
        inboundAssert(strpos($error->getMessage(),$expected)!==false,$error->getMessage()); return;
    }
    throw new RuntimeException('Se aceptó una entrada inválida: '.$expected);
}

$admin=new PDO('mysql:host=localhost;dbname=zynervox_core','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='zynervox_dialer_inbound_test_'.bin2hex(random_bytes(6));
inboundAssert(preg_match('/^zynervox_dialer_inbound_test_[a-f0-9]{12}$/D',$schema)===1,'Nombre de esquema de prueba inseguro');
$admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');
try {
    $db=new PDO('mysql:host=localhost;dbname='.$schema,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $copied=[];
    $clone=function(string $table) use (&$clone,&$copied,$admin,$db): void {
        if (isset($copied[$table])) return;
        inboundAssert(preg_match('/^v2_[a-z0-9_]+$/D',$table)===1,'Dependencia FK inesperada');
        $ddl=$admin->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        preg_match_all('/REFERENCES `([^`]+)`/',$ddl,$references);
        foreach ($references[1] as $dependency) $clone($dependency);
        $db->exec($ddl); $copied[$table]=true;
    };
    foreach (['v2_dialer_campaigns','v2_dialer_lists','v2_dialer_leads','v2_dialer_call_log','v2_dialer_agent_status','v2_dialer_agent_pauses','v2_dialer_pause_types','v2_dialer_agent_intervals','v2_dialer_agent_campaigns','v2_phones','v2_access_log'] as $table) $clone($table);

    // La migración 017 se aplica aquí mismo: así la prueba también la valida, y
    // no depende de que producción ya la tenga.
    $migration=file_get_contents(__DIR__.'/../models/017-inbound.sql');
    inboundAssert($migration!==false,'No se pudo leer 017-inbound.sql');
    foreach (explode(';',preg_replace('/^\s*--.*$/m','',$migration)) as $statement) {
        $statement=trim($statement);
        if ($statement!=='') $db->exec($statement);
    }
    inboundAssert((int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='".$schema."' AND table_name='v2_dialer_call_log' AND column_name IN ('direction','inbound_did')")->fetchColumn()===2,'La migración no agregó direction/inbound_did');

    // Solo este esquema desechable queda enlazado. No se copia dato operativo.
    $instance=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
    $conn=new ReflectionProperty(Database::class,'conn'); $conn->setAccessible(true); $conn->setValue($instance,$db);
    $core=new ReflectionProperty(Database::class,'coreInstance'); $core->setAccessible(true); $core->setValue(null,$instance);

    $db->exec("INSERT INTO v2_carriers (carrier_id,carrier_name,account_entry,dialplan_entry,server_ip,active) VALUES ('INBOUND_TEST','Synthetic','','','127.0.0.1','N')");
    // Campaña 1 entrante (destino del DID) y campaña 2 saliente, para probar
    // que una entrante no consume el cupo de la saliente.
    foreach ([1,2] as $id) {
        $db->exec("INSERT INTO v2_dialer_campaigns (campaign_id,name,carrier_id,queue_name,engine_state) VALUES ($id,'Inbound test $id','INBOUND_TEST','v2dialer_$id','STOPPED')");
        $db->exec("INSERT INTO v2_dialer_lists (list_id,campaign_id,name) VALUES ($id,$id,'Synthetic $id')");
    }
    $db->exec("INSERT INTO v2_dialer_inbound_dids (did,list_id,name) VALUES ('5551000',1,'Mesa central')");
    $db->exec("INSERT INTO v2_dialer_inbound_dids (did,list_id,name,active) VALUES ('5551999',1,'Apagado',0)");
    // Cliente que ya existe en la lista SALIENTE 2: la entrante debe reutilizarlo.
    $db->exec("INSERT INTO v2_dialer_leads (lead_id,list_id,phone_number,status) VALUES (900,2,'900900900','NEW')");

    // 1. DID sin ruta y DID inactivo: no se inventa destino.
    inboundAssert(Dialer::registerInboundCall('5550000','777777777')===null,'Un DID sin ruta devolvió destino');
    inboundAssert(Dialer::registerInboundCall('5551999','777777777')===null,'Un DID inactivo entregó destino');
    inboundAssert((int)$db->query('SELECT COUNT(*) FROM v2_dialer_call_log')->fetchColumn()===0,'Un DID sin ruta registró la llamada');

    // 2. Teléfono nuevo: crea el lead en la lista del DID, con estado INBOUND.
    $first=Dialer::registerInboundCall('5551000','111222333','1761500000.1');
    inboundAssert($first!==null && $first['campaign_id']===1,'La entrante no resolvió la campaña del DID');
    inboundAssert($first['queue_name']==='v2dialer_1','La entrante no resolvió la cola de la campaña de la lista');
    $lead=$db->query('SELECT list_id,status FROM v2_dialer_leads WHERE lead_id='.(int)$first['lead_id'])->fetch(PDO::FETCH_ASSOC);
    inboundAssert((int)$lead['list_id']===1,'El lead nuevo no quedó en la lista del DID');
    inboundAssert($lead['status']==='INBOUND','El lead entrante quedó dialable: claimLead() toma status NEW');
    $row=$db->query('SELECT direction,inbound_did,channel_uniqueid,agent_slot,dial_level_at_origin,answered_at,bridged_at FROM v2_dialer_call_log WHERE call_id='.(int)$first['call_id'])->fetch(PDO::FETCH_ASSOC);
    inboundAssert($row['direction']==='IN','La llamada no quedó marcada como entrante');
    inboundAssert($row['inbound_did']==='5551000','No se conservó el DID de entrada');
    inboundAssert($row['channel_uniqueid']==='1761500000.1','No se conservó el canal real de Asterisk');
    inboundAssert($row['agent_slot']===null && $row['dial_level_at_origin']===null,'La entrante nació con reserva o nivel de marcado');
    // answered_at al registrar y bridged_at recién al tomar: así duration_wait
    // mide la espera en cola y no sale en cero.
    inboundAssert($row['answered_at']!==null && $row['bridged_at']===null,'answered_at/bridged_at no distinguen atender al cliente de que el agente tome');

    // 3. El mismo teléfono vuelve a llamar: reutiliza su lead, no crea otro.
    $again=Dialer::registerInboundCall('5551000','111222333');
    inboundAssert($again!==null && $again['lead_id']===$first['lead_id'],'La segunda entrante duplicó el lead');

    // 4. Cliente que ya vivía en una lista saliente: se reutiliza esa ficha.
    $known=Dialer::registerInboundCall('5551000','900900900');
    inboundAssert($known!==null && $known['lead_id']===900,'No se reutilizó el lead existente del cliente');
    inboundAssert((int)$db->query("SELECT COUNT(*) FROM v2_dialer_leads WHERE phone_number='900900900'")->fetchColumn()===1,'Se creó un lead duplicado para un cliente conocido');

    // 5. El cupo saliente ignora las entrantes abiertas.
    $db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,direction) VALUES (1,900,'900900900',NOW(),'OUT')");
    inboundAssert(Dialer::inFlightCount(1)===1,'inFlightCount contó entrantes como cupo de marcado');

    // 6. bindInboundAgent estampa al agente y ocupa el mutex que frena al marcador.
    $hasWork=new ReflectionMethod(Dialer::class,'agentHasWork'); $hasWork->setAccessible(true);
    inboundAssert($hasWork->invoke(null,'inbound_agent')===false,'El agente arrancó con trabajo abierto');
    inboundAssert(Dialer::bindInboundAgent((int)$first['call_id'],'inbound_agent')===true,'No se pudo estampar al agente de la entrante');
    $bound=$db->query('SELECT agent_user,agent_slot,answered_at,bridged_at FROM v2_dialer_call_log WHERE call_id='.(int)$first['call_id'])->fetch(PDO::FETCH_ASSOC);
    inboundAssert($bound['agent_user']==='inbound_agent' && $bound['agent_slot']==='inbound_agent','La entrante no ocupó agent_slot');
    inboundAssert($bound['answered_at']!==null && $bound['bridged_at']!==null,'La entrante no quedó contestada ni puenteada');
    inboundAssert($hasWork->invoke(null,'inbound_agent')===true,'El marcador no ve la entrante: el blending no frena las salientes');

    // 7. Segundo intento del mismo agente: se registra sin slot, no se pierde.
    inboundAssert(Dialer::bindInboundAgent((int)$again['call_id'],'inbound_agent')===false,'El slot global aceptó dos llamadas del mismo agente');
    $second=$db->query('SELECT agent_user,agent_slot FROM v2_dialer_call_log WHERE call_id='.(int)$again['call_id'])->fetch(PDO::FETCH_ASSOC);
    inboundAssert($second['agent_user']==='inbound_agent' && $second['agent_slot']===null,'La entrante en conflicto se perdió o robó el slot');

    // 8. El dialplan solo conoce la interfaz: v2_phones traduce a usuario.
    $db->exec("INSERT INTO v2_phones (extension,login,pass,protocol) VALUES ('5099','inbound_member','synthetic-not-used','SIP')");
    $byMember=Dialer::registerInboundCall('5551000','444555666');
    inboundAssert(Dialer::bindInboundInterface((int)$byMember['call_id'],'SIP/5099')===true,'No se resolvió el agente desde la interfaz de cola');
    inboundAssert($db->query('SELECT agent_user FROM v2_dialer_call_log WHERE call_id='.(int)$byMember['call_id'])->fetchColumn()==='inbound_member','La interfaz no se tradujo al usuario Zynervox');
    inboundAssert(Dialer::bindInboundInterface((int)$byMember['call_id'],'SIP/9998')===false,'Un anexo sin usuario se aceptó como agente');
    inboundRejects(static function() { Dialer::bindInboundInterface(1,'Local/x@y'); },'Interfaz inválida');

    // 9. Cierre atendido: COMPLETED y el lead avanza a CONTACTED.
    Dialer::closeInboundCall((int)$byMember['call_id'],'','');
    $closed=$db->query('SELECT termination_reason,agent_slot FROM v2_dialer_call_log WHERE call_id='.(int)$byMember['call_id'])->fetch(PDO::FETCH_ASSOC);
    inboundAssert($closed['termination_reason']==='COMPLETED','La entrante atendida no se cerró como COMPLETED');
    inboundAssert($closed['agent_slot']===null,'El cierre no liberó agent_slot: el agente queda bloqueado para siempre');
    inboundAssert($db->query("SELECT status FROM v2_dialer_leads WHERE phone_number='444555666'")->fetchColumn()==='CONTACTED','El lead atendido no quedó CONTACTED');

    // 10. Colgó esperando en cola: ABANDONED, y el lead NO vuelve a NEW. Es la
    // razón de no reutilizar closeCall(): ahí quedaría dialable.
    $abandoned=Dialer::registerInboundCall('5551000','777888999');
    Dialer::closeInboundCall((int)$abandoned['call_id'],'TIMEOUT','');
    inboundAssert($db->query('SELECT termination_reason FROM v2_dialer_call_log WHERE call_id='.(int)$abandoned['call_id'])->fetchColumn()==='ABANDONED','El abandono en cola no se registró como ABANDONED');
    inboundAssert($db->query("SELECT status FROM v2_dialer_leads WHERE phone_number='777888999'")->fetchColumn()==='INBOUND','El abandono dejó el lead dialable por salientes');

    // 11. Frontera de confianza: el AGI no puede inyectar basura.
    inboundRejects(static function() { Dialer::registerInboundCall('55 OR 1=1','111222333'); },'DID inválido');
    inboundRejects(static function() { Dialer::registerInboundCall('5551000','abc'); },'Teléfono inválido');

    // 12. Cola con gente esperando: se lee del encabezado de `queue show`, no
    // del parseo de miembros del que dependen reconciliación y nivel.
    Includes\PbxAmi::$waiting="v2dialer_1 has 3 calls (max unlimited) in 'leastrecent' strategy (0s holdtime, 0s talktime), W:0, C:1, A:0\n"
        ."v2dialer_2 has 0 calls (max unlimited) in 'leastrecent' strategy (0s holdtime, 0s talktime), W:0, C:0, A:0\n"
        ."v2dialer_1_a_deadbeefdeadbeef has 0 calls (max unlimited) in 'leastrecent' strategy\n";
    $esperando=Dialer::queuesWithCallersWaiting();
    inboundAssert($esperando===['v2dialer_1'=>3],'No se leyó bien quién tiene gente esperando: '.json_encode($esperando));

    // 13. El agente de la cola entrante con gente esperando queda fuera del
    // reparto de salientes; el que no tiene entrantes sigue disponible.
    $db->exec("INSERT INTO v2_dialer_agent_campaigns (agent_user,campaign_id) VALUES ('mixto',1),('mixto',2),('solo_saliente',2)");
    $porAgente=new ReflectionMethod(Dialer::class,'inboundQueuesByAgent'); $porAgente->setAccessible(true);
    $bloqueado=new ReflectionMethod(Dialer::class,'blockedByInbound'); $bloqueado->setAccessible(true);
    $mapa=$porAgente->invoke(null,2);   // la campaña saliente es la 2
    inboundAssert(($mapa['mixto'] ?? [])===['v2dialer_1'],'El agente mixto no resolvió su cola entrante: '.json_encode($mapa));
    inboundAssert(!isset($mapa['solo_saliente']),'Un agente sin entrantes apareció con cola entrante');
    inboundAssert($bloqueado->invoke(null,'mixto',$mapa,$esperando)===true,'La entrante en espera no frena las salientes del agente mixto');
    inboundAssert($bloqueado->invoke(null,'solo_saliente',$mapa,$esperando)===false,'Se frenó a un agente que no atiende entrantes');
    inboundAssert($bloqueado->invoke(null,'mixto',$mapa,[])===false,'Se frenó al mixto con la cola entrante vacía');

    // 14. DIDs desde el panel: validación, renombrado y borrado protegido.
    inboundRejects(static function() { Dialer::saveInboundDid('55-A',1,'Malo',true); },'solo dígitos');
    inboundRejects(static function() { Dialer::saveInboundDid('5552000',99999,'Lista fantasma',true); },'no existe');
    Dialer::saveInboundDid('5552000',1,'Ventas',true);
    inboundAssert(Dialer::inboundDid('5552000')!==null,'El DID nuevo no se guardó');
    Dialer::saveInboundDid('5552001',1,'Ventas',true,'5552000');
    inboundAssert(Dialer::inboundDid('5552000')===null && Dialer::inboundDid('5552001')!==null,'Cambiar el número dejó dos filas o perdió el DID');
    inboundRejects(static function() { Dialer::saveInboundDid('5552001',1,'Choque',true,'5551000'); },'ya está registrado');
    $abierta=Dialer::registerInboundCall('5551000','555000111');
    inboundRejects(static function() { Dialer::deleteInboundDid('5551000'); },'entrantes abiertas');
    // Quedan abiertas las de los pasos anteriores: se cierran todas, que es la
    // condición real para poder borrar el DID.
    foreach ($db->query("SELECT call_id FROM v2_dialer_call_log WHERE inbound_did='5551000' AND direction='IN' AND ended_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $pendiente) {
        Dialer::closeInboundCall((int)$pendiente,'TIMEOUT','');
    }
    Dialer::deleteInboundDid('5551000');
    inboundAssert(Dialer::inboundDid('5551000')===null,'El DID no se eliminó tras cerrar la entrante');

    echo "inbound: OK\n";
} finally {
    $admin->exec('DROP DATABASE `'.$schema.'`');
}
