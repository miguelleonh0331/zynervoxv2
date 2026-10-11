<?php
declare(strict_types=1);
require __DIR__.'/reporting.php';
use Includes\Dialer;
use Includes\PbxAmi;
$ddl=$db->query('SHOW CREATE TABLE v2_phones')->fetch(PDO::FETCH_NUM)[1];
$db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
$db->exec("INSERT INTO v2_phones (extension,login,pass,fullname,active,protocol) VALUES ('79990','lab','fixture-only','Lab','Y','SIP')");
$db->exec("DELETE FROM v2_dialer_call_log");
$db->exec("INSERT INTO v2_dialer_agent_campaigns (agent_user,campaign_id) VALUES ('lab',1),('lab',2)");
Dialer::agentLogin('lab');
verify(array_key_exists('SIP/79990',PbxAmi::$queues['lab1']) && array_key_exists('SIP/79990',PbxAmi::$queues['lab2']),'Auto login did not join both assigned campaigns');
Dialer::savePauseType(0,'CONTROL_TEST','Prueba de pausa',true);
$type=(int)$db->query("SELECT pause_type_id FROM v2_dialer_pause_types WHERE code='CONTROL_TEST'")->fetchColumn();
Dialer::agentPauseAll('lab',$type);
$pause=Dialer::agentPause('lab'); Dialer::agentLogin('lab');
verify(Dialer::agentPause('lab')['pause_id']===$pause['pause_id'],'Reload restarted or removed pause');
verify(PbxAmi::$queues['lab1']['SIP/79990']==='DIALER_PAUSE_'.$type && PbxAmi::$queues['lab2']['SIP/79990']==='DIALER_PAUSE_'.$type,'Pause did not apply across campaigns');
Dialer::savePauseType($type,'CONTROL_TEST','Etiqueta nueva',false);
verify(Dialer::agentPause('lab')['label']==='Prueba de pausa','Catalog edit changed historical pause label');
$db->exec("UPDATE v2_dialer_campaigns SET engine_state='RUNNING' WHERE campaign_id=1");
PbxAmi::$queues['lab1']['SIP/79990']=null; // Stale WAITING snapshot after failed QueuePause.
verify(Dialer::claimLead(1,'7399',1,'lab',true)===null,'Durable pause admitted a new reserved attempt');
verify(Dialer::availableAgentsCount(1,'WAITING')===0,'Stale PBX state overrode durable pause');
verify((int)$db->query('SELECT COUNT(*) FROM v2_dialer_call_log')->fetchColumn()===0,'Rejected pause claim spent an attempt');
expectFailure(static function() use($type) { Dialer::agentPauseAll('lab',$type); },'Inactive pause accepted');
Dialer::agentPauseAll('lab',null);
verify(Dialer::agentPause('lab')===null && PbxAmi::$queues['lab1']['SIP/79990']===null,'Resume did not close pause and unpause');
verify($db->query('SELECT ended_at FROM v2_dialer_agent_pauses WHERE pause_id='.$pause['pause_id'])->fetchColumn()!==null,'Pause duration history not closed');
Dialer::savePauseType($type,'CONTROL_TEST','Prueba',true);
// An already reserved progressive attempt keeps its personal queue while main queues pause.
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,reserved_agent_user,agent_slot,channel_uniqueid) VALUES (1,1,'000000',NOW(),'lab','lab','zv2dialer-901')");
$call=(int)$db->lastInsertId(); $db->exec("UPDATE v2_dialer_call_log SET channel_uniqueid='zv2dialer-$call' WHERE call_id=$call");
Dialer::agentPauseAll('lab',$type);
$queue=Dialer::targetQueue($call);
verify(PbxAmi::$queues[$queue]['SIP/79990']===null,'Pause blocked existing reserved progressive attempt');
Dialer::markAnswered($call,'zv2dialer-'.$call); Dialer::markBridged($call,'SIP/79990-0000abcd');
Dialer::closeCall($call,'CONTINUE','');
verify(Dialer::agentPause('lab')!==null && PbxAmi::$queues[$queue]['SIP/79990']==='DIALER_PAUSE_'.$type,'Closer overwrote manual pause');
Dialer::setDisposition($call,'lab','SALE');
verify(Dialer::agentPause('lab')!==null,'Disposition removed manual pause');
// Hangup ownership and exact channel correlation; PBX closure remains authoritative.
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,reserved_agent_user) VALUES (1,1,'000000',NOW(),'lab')");
$call=(int)$db->lastInsertId(); $db->exec("UPDATE v2_dialer_call_log SET channel_uniqueid='zv2dialer-$call' WHERE call_id=$call");
PbxAmi::$channels='SIP/79990-0000abcd!x!x!x!x!x!x!x!x!x!x!zv2dialer-'.$call;
expectFailure(static function() use($call) { Dialer::agentHangup('other',$call); },'Hangup accepted another agent');
Dialer::agentHangup('lab',$call);
verify(end(PbxAmi::$events)[0]==='Hangup' && $db->query('SELECT ended_at FROM v2_dialer_call_log WHERE call_id='.$call)->fetchColumn()===null,'Hangup closed database before PBX handler');
PbxAmi::$channels=''; expectFailure(static function() use($call) { Dialer::agentHangup('lab',$call); },'Hangup accepted absent channel');
// Partial failure stores intent; reconciliation can finish it without reporting success.
PbxAmi::$fail=true;
expectFailure(static function() { Dialer::agentLogout('lab'); },'Logout reported success with PBX unavailable');
verify((int)$db->query("SELECT logged_in FROM v2_dialer_agent_status WHERE agent_user='lab'")->fetchColumn()===0,'Logout did not preserve offline intent');
verify(Dialer::claimLead(1,'7399',1,'lab',true)===null,'Offline intent admitted a new reserved attempt');
PbxAmi::$fail=false; Dialer::observeAgentSessions(1); Dialer::observeAgentSessions(2); Dialer::agentLogout('lab');
verify(empty(PbxAmi::$queues['lab1']) && empty(PbxAmi::$queues['lab2']) && Dialer::agentPause('lab')===null,'Logout retained queue membership or open pause');
echo "PASS: automatic multicampaign login, pause/reload/catalog/history, progressive reservation, wrapup preservation, own-channel Hangup and partial logout recovery.\n";
