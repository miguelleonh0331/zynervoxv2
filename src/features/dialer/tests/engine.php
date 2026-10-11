<?php
declare(strict_types=1);
require __DIR__ . '/reporting.php';
use Includes\Dialer;
// Existing test connection holds only private TEMPORARY tables.
foreach (['v2_dialer_lists', 'v2_dialer_dnc'] as $table) {
    $definition = $db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $definition = preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition);
    $definition = preg_replace('/^  CONSTRAINT .* FOREIGN KEY .*\n/m', '', $definition);
    $definition = preg_replace('/,\n\)/', "\n)", $definition);
    $definition = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $definition);
    $db->exec($definition);
}
$db->exec(file_get_contents(__DIR__ . '/../models/008-outbound-routing.sql'));
$campaign = ['campaign_id' => 1, 'dial_prefix' => '7399', 'outbound_prefix' => '881851', 'caller_id_number' => '1000', 'dial_timeout_seconds' => 25,
    'queue_name' => 'v2dialer_1', 'amd_enabled' => 1, 'drop_call_seconds' => 8, 'drop_action' => 'HANGUP', 'amd_action_machine' => 'HANGUP'];
$lead = ['call_id' => 123, 'phone_number' => '000000'];
$sip = ['protocol' => 'SIP', 'active' => 'Y', 'account_entry' => "[lab]\ntype=peer\nhost=127.0.0.1\n"];
$fields = Dialer::originateFields($campaign, $lead, $sip);
verify($fields['Channel'] === 'SIP/lab/881851000000' && $fields['Context'] === 'zynervoxv2' && $fields['Exten'] === '7399000000' && $fields['Priority'] === '1', 'Real trunk and postanswer callback');
verify($fields['Timeout'] === '25000' && $fields['ChannelId'] === 'zv2dialer-123' && $fields['Variable'] === '__ZV2_DIALER_CALL_ID=123', 'Timeout and correlation');
$pjsip = ['protocol' => 'PJSIP', 'active' => 'Y', 'account_entry' => "[lab]\ntype=endpoint\n[lab-auth]\ntype=auth\n[lab]\ntype=aor\n"];
verify(Dialer::originateFields($campaign, $lead, $pjsip)['Channel'] === 'PJSIP/881851000000@lab', 'PJSIP uses endpoint, not carrier id');
expectFailure(static function () use ($campaign, $lead, $sip) { Dialer::originateFields(array_replace($campaign, ['outbound_prefix' => null]), $lead, $sip); }, 'Unconfigured route accepted');
expectFailure(static function () use ($campaign, $lead, $sip) { Dialer::originateFields($campaign, $lead, array_replace($sip, ['account_entry' => "[a]\ntype=peer\n[b]\ntype=friend\n"])); }, 'Ambiguous endpoints accepted');
expectFailure(static function () use ($campaign, $lead, $sip) { Dialer::originateFields($campaign, array_replace($lead, ['phone_number' => "0\r\nAction: Hangup"]), $sip); }, 'AMI injection accepted');
$bodyMethod = new ReflectionMethod(Dialer::class, 'dialplanBody');
$bodyMethod->setAccessible(true);
$body = $bodyMethod->invoke(null, $campaign);
require $web . '/includes/DialplanOrigins.php';
verify(\Includes\DialplanOrigins::prefixes(\Includes\DialplanOrigins::body($body)) === ['7399'], 'Generated callback/handler must satisfy public dialplan contract');
verify(strpos($body, 'MixMonitor(') < strpos($body, 'AMD()') && strpos($body, 'AMD()') < strpos($body, 'Queue('), 'Recording begins postanswer before AMD/Queue');
verify(strpos($body, 'hangup_handler_push') !== false && strpos($body, 'StopMixMonitor()') !== false && strpos($body, 'Return()') !== false, 'Hangup handler must close recording and return');
expectFailure(static function () use ($bodyMethod, $campaign) { $bodyMethod->invoke(null, array_replace($campaign, ['drop_action' => 'MESSAGE', 'drop_message' => '../etc/passwd'])); }, 'Invalid MESSAGE audio accepted');

$db->exec('DELETE FROM v2_dialer_call_log');
$db->exec("UPDATE v2_dialer_campaigns SET dial_method='RATIO',dial_prefix='7399',outbound_prefix='881851',max_concurrent_calls=2,dial_level=1.5,local_call_time_start='00:00:00',local_call_time_end='23:59:59' WHERE campaign_id=1");
$db->exec("INSERT INTO v2_dialer_lists (list_id,campaign_id,name) VALUES (1,1,'Lab')");
$db->exec("UPDATE v2_dialer_leads SET status='NEW',called_count=0,next_eligible_at=NOW()-INTERVAL 1 MINUTE WHERE lead_id=1");
$db->exec("INSERT INTO v2_dialer_leads (lead_id,list_id,phone_number) VALUES (2,1,'000002'),(3,1,'000003'),(4,1,'000004')");
$one = Dialer::claimLead(1, '7399', 2);
$two = Dialer::claimLead(1, '7399', 2);
verify($one !== null && $two !== null && $one['lead_id'] !== $two['lead_id'], 'Each claim reserves a different lead');
verify(Dialer::claimLead(1, '7399', 2) === null, 'Hard capacity must block third call');
$db->exec('UPDATE v2_dialer_call_log SET originated_at=NOW()-INTERVAL 2 HOUR');
verify(Dialer::inFlightCount(1, 1) === 2, 'Long-lived call vanished from capacity');
verify((float) $db->query('SELECT dial_level_at_origin FROM v2_dialer_call_log LIMIT 1')->fetchColumn() === 1.5, 'Level at origin missing');
Dialer::releaseFailedClaim($one['call_id'], $one['lead_id']);
verify(Dialer::inFlightCount(1) === 1, 'Rejected attempt did not release capacity');
verify($db->query('SELECT termination_reason FROM v2_dialer_call_log WHERE call_id=' . $one['call_id'])->fetchColumn() === 'ORIGINATE_ERROR', 'Rejected attempt was deleted');
Dialer::markAnswered($two['call_id'], 'zv2dialer-' . $two['call_id']);
Dialer::releaseFailedClaim($two['call_id'], $two['lead_id']);
verify(Dialer::inFlightCount(1) === 1, 'Preanswer fallback closed an answered call');
Dialer::markAmd($two['call_id'], 'MACHINE');
Dialer::closeMachine($two['call_id']);
$closed = $db->query('SELECT * FROM v2_dialer_call_log WHERE call_id=' . $two['call_id'])->fetch(PDO::FETCH_ASSOC);
Dialer::closeCall($two['call_id'], 'TIMEOUT', '');
verify($db->query('SELECT termination_reason FROM v2_dialer_call_log WHERE call_id=' . $two['call_id'])->fetchColumn() === 'MACHINE', 'Repeated handler overwrote machine closure');
verify($closed['recording_path'] === Dialer::RECORDING_DIRECTORY . '/call-' . $two['call_id'] . '.wav', 'Recorded path must match MixMonitor filename');
$db->exec("INSERT INTO v2_dialer_dnc (phone_number,campaign_id) VALUES ('000003',NULL)");
$db->exec("UPDATE v2_dialer_leads SET called_count=99 WHERE lead_id=4");
verify(Dialer::claimLead(1, '7399', 2) === null, 'DNC or maximum-attempt lead was claimed');
$db->exec("UPDATE v2_dialer_leads SET called_count=0 WHERE lead_id=4");
$db->exec('UPDATE v2_dialer_campaigns SET active=0 WHERE campaign_id=1');
verify(Dialer::claimLead(1, '7399', 2) === null, 'Inactive campaign claimed a lead');
expectFailure(static function () { Dialer::agentJoinQueue('unassigned', 1); }, 'Unassigned agent can join campaign');
$db->exec("INSERT INTO v2_dialer_agent_campaigns (agent_user,campaign_id) VALUES ('lab',1)");
$db->exec("UPDATE v2_dialer_campaigns SET dial_method='MANUAL' WHERE campaign_id=1");
verify(Dialer::campaignsForAgent('lab')[0]['dial_method'] === 'MANUAL', 'Agent campaign query must expose manual mode to UI');
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at) VALUES (1,1,'000000',NOW())");
$failedRecording = (int) $db->lastInsertId();
Dialer::markAnswered($failedRecording);
expectFailure(static function () use ($failedRecording) { Dialer::verifyRecording($failedRecording, '/invalid'); }, 'Failed recording accepted');
Dialer::closeCall($failedRecording, 'TIMEOUT', '');
verify($db->query('SELECT termination_reason FROM v2_dialer_call_log WHERE call_id=' . $failedRecording)->fetchColumn() === 'RECORDING_ERROR', 'Handler overwrote mandatory-recording failure');
echo "PASS: SIP/PJSIP fields, safe routing, recording/handler, capacity, unique reservation, long calls, failure retention, idempotence, MACHINE, DNC, attempts and assignment.\n";

$db->exec("UPDATE v2_dialer_campaigns SET active=1,dial_method='PROGRESSIVE',engine_state='RUNNING',local_call_time_start='00:00:00',local_call_time_end='23:59:59' WHERE campaign_id IN (1,2)");
$db->exec("UPDATE v2_dialer_leads SET status='NEW',called_count=0,next_eligible_at=NOW() WHERE lead_id=4");
$db->exec("INSERT INTO v2_dialer_lists (list_id,campaign_id,name) VALUES (2,2,'Shared agent test')");
$db->exec("INSERT INTO v2_dialer_leads (lead_id,list_id,phone_number) VALUES (5,2,'000005')");
$reserved = Dialer::claimLead(1,'7399',2,'shared_agent',true);
verify($reserved !== null, 'Progressive agent was not reserved');
verify(Dialer::claimLead(2,'7399',2,'shared_agent',true) === null, 'Same agent reserved across two campaigns');
verify((int)$db->query('SELECT called_count FROM v2_dialer_leads WHERE lead_id=5')->fetchColumn() === 0, 'Blocked reservation spent an attempt');
verify(strpos(Dialer::targetQueue($reserved['call_id']), 'v2dialer_1_a_') === 0, 'Manual/progressive call does not target its reserved agent queue');
Dialer::closeMachine($reserved['call_id']);
$db->exec("UPDATE v2_dialer_campaigns SET engine_state='STOPPED' WHERE campaign_id=2");
verify(Dialer::claimLead(2,'7399',2,'shared_agent',true) === null, 'STOPPED reservation admitted');
$db->exec("UPDATE v2_dialer_campaigns SET engine_state='RUNNING' WHERE campaign_id=2");
verify(Dialer::claimLead(2,'7399',2,'shared_agent',true) !== null, 'Closed call did not release global agent slot');
echo "PASS: progressive global agent reservation, targeted queue, stop/start and release without spending blocked attempts.\n";

// Adaptive decisions and their cadence use the same private tables.
$db->exec('DELETE FROM v2_dialer_call_log');
$db->exec("UPDATE v2_dialer_campaigns SET dial_method='ADAPTIVE',dial_level=1.5,adaptive_target_abandon_pct=25,adaptive_step=0.1,adaptive_min_level=1,adaptive_max_level=2,adaptive_min_sample=4,adaptive_recalculated_at=NULL WHERE campaign_id=3");
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,answered_at,ended_at,amd_result,termination_reason) VALUES
 (3,1,'000000',NOW(),NOW(),NOW(),'HUMAN','COMPLETED'),
 (3,1,'000000',NOW(),NOW(),NOW(),'HUMAN','COMPLETED'),
 (3,1,'000000',NOW(),NOW(),NOW(),'HUMAN','COMPLETED')");
verify(Dialer::recalculateDialLevel(3)===1.5,'Adaptive changed before minimum sample');
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,answered_at,ended_at,amd_result,termination_reason) VALUES (3,1,'000000',NOW(),NOW(),NOW(),'MACHINE','DROPPED')");
$db->exec('UPDATE v2_dialer_campaigns SET adaptive_recalculated_at=NULL WHERE campaign_id=3');
verify(Dialer::recalculateDialLevel(3)===1.6,'Machine incorrectly counted as human abandonment');
verify(Dialer::recalculateDialLevel(3)===1.6,'Adaptive cadence allowed a repeated immediate increase');
$db->exec("UPDATE v2_dialer_call_log SET termination_reason='DROPPED' ORDER BY call_id LIMIT 1");
$db->exec('UPDATE v2_dialer_campaigns SET adaptive_recalculated_at=NULL WHERE campaign_id=3');
verify(Dialer::recalculateDialLevel(3)===1.5,'Above-target abandonment did not lower level');
$db->exec("UPDATE v2_dialer_campaigns SET adaptive_target_abandon_pct=40,adaptive_recalculated_at=NULL WHERE campaign_id=3");
verify(Dialer::recalculateDialLevel(3)===1.5,'Hysteresis band changed the level');
$db->exec("UPDATE v2_dialer_campaigns SET adaptive_target_abandon_pct=25,dial_level=1,adaptive_recalculated_at=NULL WHERE campaign_id=3");
verify(Dialer::recalculateDialLevel(3)===1.0,'Adaptive crossed minimum level');
$db->exec("UPDATE v2_dialer_call_log SET termination_reason='COMPLETED' WHERE amd_result='HUMAN'");
$db->exec("UPDATE v2_dialer_campaigns SET dial_level=2,adaptive_recalculated_at=NULL WHERE campaign_id=3");
verify(Dialer::recalculateDialLevel(3)===2.0,'Adaptive crossed maximum level');

// Retrying a lead and correcting an old disposition must not conflict with a newer attempt.
$db->exec("DELETE FROM v2_dialer_agent_campaigns");
$db->exec("INSERT INTO v2_dialer_dispositions (campaign_id,code,label,retryable,retry_delay_minutes) VALUES (1,'RETRY','Retry',1,30)");
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,agent_user,phone_number,originated_at,ended_at) VALUES (1,1,'lab','000000',NOW(),NOW()-INTERVAL 10 MINUTE)");
$retryCall=(int)$db->lastInsertId();
$db->exec("DELETE FROM v2_dialer_agent_intervals WHERE campaign_id=1 AND agent_user='lab'");
$db->exec("INSERT INTO v2_dialer_agent_intervals (campaign_id,agent_user,state,started_at,ended_at,last_seen_at) VALUES (1,'lab','WRAPUP',NOW()-INTERVAL 10 MINUTE,NOW(),NOW())");
Dialer::setDisposition($retryCall,'lab','RETRY');
verify($db->query('SELECT status FROM v2_dialer_leads WHERE lead_id=1')->fetchColumn()==='NEW','Retry did not recycle lead');
verify((int)$db->query('SELECT TIMESTAMPDIFF(MINUTE,NOW(),next_eligible_at) FROM v2_dialer_leads WHERE lead_id=1')->fetchColumn()>=29,'Retry delay missing');
verify((int)$db->query('SELECT duration_wrapup FROM v2_dialer_call_log WHERE call_id='.$retryCall)->fetchColumn()>=600,'Observed long wrapup was capped');
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at) VALUES (1,1,'000000',NOW())");
$db->exec("UPDATE v2_dialer_leads SET status='DIALING' WHERE lead_id=1");
Dialer::setDisposition($retryCall,'lab','RETRY');
verify($db->query('SELECT status FROM v2_dialer_leads WHERE lead_id=1')->fetchColumn()==='DIALING','Old correction recycled a newer active attempt');

// A missing worker observation must remain a gap in the session history.
$session=new ReflectionMethod(Dialer::class,'sessionState'); $session->setAccessible(true);
$db->exec("INSERT INTO v2_dialer_agent_intervals (campaign_id,agent_user,state,open_slot,started_at,last_seen_at) VALUES (3,'gap_agent','ACTIVE','3:gap_agent',NOW()-INTERVAL 2 MINUTE,NOW()-INTERVAL 1 MINUTE)");
$gapId=(int)$db->lastInsertId();
$session->invoke(null,'gap_agent',3,'ACTIVE');
verify((int)$db->query('SELECT TIMESTAMPDIFF(SECOND,last_seen_at,ended_at) FROM v2_dialer_agent_intervals WHERE interval_id='.$gapId)->fetchColumn()===5,'Session gap fabricated logged time');
verify((int)$db->query("SELECT COUNT(*) FROM v2_dialer_agent_intervals WHERE open_slot='3:gap_agent'")->fetchColumn()===1,'Recovery duplicated open session');
echo "PASS: adaptive minimum sample, HUMAN denominator, cadence, hysteresis and bounds; delayed retry, old-call correction, long observed wrapup and session recovery gap.\n";
