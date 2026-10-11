<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || !getenv('DIALER_TEST_WEB_ROOT')) exit(1);
require getenv('DIALER_TEST_WEB_ROOT').'/includes/Dialer.php';
use Includes\Dialer;
use Includes\Database;
use Includes\PbxAmi;
function deletionAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function blockedDeletion(int $id,string $expected): void {
    try { Dialer::deleteCampaign($id); } catch (RuntimeException $error) {
        deletionAssert(strpos($error->getMessage(),$expected)!==false,$error->getMessage()); return;
    }
    throw new RuntimeException('Deletion unexpectedly succeeded');
}
$admin=new PDO('mysql:host=localhost;dbname=zynervox_core','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='zynervox_dialer_delete_test_'.bin2hex(random_bytes(6));
deletionAssert(preg_match('/^zynervox_dialer_delete_test_[a-f0-9]{12}$/D',$schema)===1,'Unsafe test schema');
$admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');
try {
    $db=new PDO('mysql:host=localhost;dbname='.$schema,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $copied=[];
    $clone=function(string $table) use (&$clone,&$copied,$admin,$db): void {
        if (isset($copied[$table])) return;
        deletionAssert(preg_match('/^v2_[a-z0-9_]+$/D',$table)===1,'Unexpected FK dependency');
        $ddl=$admin->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        preg_match_all('/REFERENCES `([^`]+)`/',$ddl,$references);
        foreach ($references[1] as $dependency) $clone($dependency);
        $db->exec($ddl); $copied[$table]=true;
    };
    foreach (['v2_dialer_campaigns','v2_dialer_lists','v2_dialer_leads','v2_dialer_call_log','v2_dialer_dispositions','v2_dialer_dnc','v2_dialer_agent_campaigns','v2_dialer_agent_intervals','v2_phones','v2_access_log'] as $table) $clone($table);
    // Bind only this disposable schema. No persistent operational data is copied.
    $instance=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
    $conn=new ReflectionProperty(Database::class,'conn'); $conn->setAccessible(true); $conn->setValue($instance,$db);
    $core=new ReflectionProperty(Database::class,'coreInstance'); $core->setAccessible(true); $core->setValue(null,$instance);
    $db->exec("INSERT INTO v2_carriers (carrier_id,carrier_name,account_entry,dialplan_entry,server_ip,active) VALUES ('DELETE_TEST','Synthetic','', '', '127.0.0.1','N')");
    for ($id=1;$id<=6;$id++) {
        $db->exec("INSERT INTO v2_dialer_campaigns (campaign_id,name,carrier_id,queue_name,engine_state) VALUES ($id,'Delete test $id','DELETE_TEST','v2dialer_$id','".($id===3 ? 'RUNNING' : 'STOPPED')."')");
        $db->exec("INSERT INTO v2_dialer_lists (list_id,campaign_id,name) VALUES ($id,$id,'Synthetic')");
        $db->exec("INSERT INTO v2_dialer_leads (lead_id,list_id,phone_number) VALUES ($id,$id,'00000$id')");
        $db->exec("INSERT INTO v2_dialer_call_log (call_id,campaign_id,lead_id,phone_number,originated_at,ended_at) VALUES ($id,$id,$id,'00000$id',NOW(),".($id===4 ? 'NULL' : 'NOW()').")");
        $db->exec("INSERT INTO v2_dialer_dispositions (campaign_id,code,label) VALUES ($id,'TEST','Synthetic')");
    }
    $db->exec("INSERT INTO v2_phones (extension,login,pass,protocol) VALUES ('79990','delete_agent','synthetic-not-used','SIP')");
    $db->exec("INSERT INTO v2_dialer_agent_campaigns (agent_user,campaign_id) VALUES ('delete_agent',1),('delete_agent',2)");
    $db->exec("INSERT INTO v2_dialer_agent_intervals (campaign_id,agent_user,state,open_slot) VALUES (1,'delete_agent','ACTIVE','1:delete_agent'),(2,'delete_agent','ACTIVE','2:delete_agent')");
    $db->exec("INSERT INTO v2_dialer_dnc (phone_number,campaign_id) VALUES ('111111',NULL),('222222',1),('333333',2)");
    $wav=getenv('DIALER_TEST_WEB_ROOT').'/retained.wav'; file_put_contents($wav,'synthetic retained recording');
    $db->prepare('UPDATE v2_dialer_call_log SET recording_path=? WHERE call_id=1')->execute([$wav]);
    $db->exec('UPDATE v2_dialer_campaigns SET dialplan_id=901 WHERE campaign_id=1');
    blockedDeletion(3,'Detén el motor'); blockedDeletion(4,'llamadas abiertas');
    deletionAssert(count(PbxAmi::$events)===0,'Blocked deletion touched PBX');
    $db->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON v2_dialer_dispositions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected SQL failure'");
    blockedDeletion(5,'Injected SQL failure');
    foreach (['v2_dialer_campaigns','v2_dialer_lists','v2_dialer_call_log','v2_dialer_dispositions'] as $table) deletionAssert((int)$db->query('SELECT COUNT(*) FROM '.$table.' WHERE campaign_id=5')->fetchColumn()===1,'Transaction lost '.$table);
    deletionAssert((int)$db->query('SELECT COUNT(*) FROM v2_dialer_leads WHERE lead_id=5')->fetchColumn()===1,'Rollback lost lead');
    $db->exec('DROP TRIGGER fail_delete');
    Dialer::deleteCampaign(1);
    foreach (['v2_dialer_campaigns','v2_dialer_lists','v2_dialer_call_log','v2_dialer_dispositions','v2_dialer_agent_campaigns','v2_dialer_agent_intervals','v2_dialer_dnc'] as $table) {
        deletionAssert((int)$db->query('SELECT COUNT(*) FROM '.$table.' WHERE campaign_id=1')->fetchColumn()===0,'Own rows retained in '.$table);
        deletionAssert((int)$db->query('SELECT COUNT(*) FROM '.$table.' WHERE campaign_id=2')->fetchColumn()===1,'Foreign campaign changed in '.$table);
    }
    deletionAssert((int)$db->query('SELECT COUNT(*) FROM v2_dialer_leads WHERE lead_id=1')->fetchColumn()===0,'Own lead retained');
    deletionAssert((int)$db->query('SELECT COUNT(*) FROM v2_dialer_leads WHERE lead_id=2')->fetchColumn()===1,'Foreign lead removed');
    deletionAssert((int)$db->query('SELECT COUNT(*) FROM v2_dialer_dnc WHERE campaign_id IS NULL')->fetchColumn()===1,'DNC scope widened or global exclusion lost');
    deletionAssert(is_file($wav),'Physical recording deleted');
    deletionAssert(Includes\Dialplans::$deleted===[901] && in_array('dialplan reload',PbxAmi::$events,true),'Dialplan cleanup missing');
    $removals=array_filter(PbxAmi::$events,static function($event) { return is_array($event); });
    deletionAssert(count($removals)===2,'Main and personal memberships not removed');
    foreach ($removals as $event) deletionAssert($event[0]==='QueueRemove' && strpos($event[1]['Queue'],'v2dialer_1')===0,'Other queue touched');
    PbxAmi::$fail=true; blockedDeletion(6,'Campaña eliminada; limpieza PBX pendiente');
    deletionAssert((int)$db->query('SELECT COUNT(*) FROM v2_dialer_campaigns WHERE campaign_id=6')->fetchColumn()===0,'PBX error falsely rolled back deletion');
    echo "PASS: real foreign keys, complete deletion, running/open guards, SQL rollback, foreign/global DNC isolation, WAV retention and scoped PBX cleanup/failure.\n";
} finally {
    $admin->exec('DROP DATABASE `'.$schema.'`');
    echo "CLEAN: disposable deletion test schema removed.\n";
}
