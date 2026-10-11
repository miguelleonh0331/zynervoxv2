<?php
declare(strict_types=1);
// Run against a private staging copy. All fixtures are connection-local TEMPORARY
// tables; the persistent tables are never modified and no call is originated.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) exit(1);
$web = getenv('DIALER_TEST_WEB_ROOT');
if (!$web || !is_file($web . '/includes/Dialer.php')) throw new RuntimeException('DIALER_TEST_WEB_ROOT requerido.');
require $web . '/includes/Dialer.php';
use Includes\Dialer;
use Includes\Database;

function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function expectFailure(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException $e) { return; } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$parse = new ReflectionMethod(Dialer::class, 'parseQueueConnection');
$parse->setAccessible(true);
$log = "100|other|q|SIP/9|CONNECT|2\n101|lab|q|SIP/10000|CONNECT|3\n180|lab|q|SIP/10000|COMPLETEAGENT|3|79|1\n";
verify($parse->invoke(null, $log, 'lab') === ['interface' => 'SIP/10000', 'epoch' => 101], 'CONNECT must use connection epoch, not completion');
verify($parse->invoke(null, $log, 'missing') === null, 'Unrelated calls must not match');
verify($parse->invoke(null, "bad|lab|q|SIP/10000|CONNECT\n", 'lab') === null, 'Invalid epoch must not be accepted');
verify($parse->invoke(null, $log, '') === null, 'Empty uniqueid must not match');

// Root's local socket is only used for TEMPORARY fixtures in the test process.
$db = new PDO('mysql:host=localhost;dbname=zynervox_core', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$property = new ReflectionProperty(Database::class, 'coreInstance');
$property->setAccessible(true);
$instance = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$connection = new ReflectionProperty(Database::class, 'conn');
$connection->setAccessible(true);
$connection->setValue($instance, $db);
$property->setValue(null, $instance);
foreach (['v2_dialer_call_log', 'v2_dialer_campaigns', 'v2_dialer_dispositions', 'v2_dialer_leads', 'v2_access_log', 'v2_dialer_agent_campaigns', 'v2_dialer_agent_intervals'] as $table) {
    $definition = $db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $definition = preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition);
    $definition = preg_replace('/^  CONSTRAINT .* FOREIGN KEY .*\n/m', '', $definition);
    $definition = preg_replace('/,\n\)/', "\n)", $definition);
    $definition = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $definition);
    $db->exec($definition);
}
$db->exec(file_get_contents(__DIR__.'/../models/012-selected-dialplan.sql'));
$db->exec(file_get_contents(__DIR__.'/../models/015-manual-disposition-owner.sql'));
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',file_get_contents(__DIR__.'/../models/013-agent-controls.sql')));
$db->exec(file_get_contents(__DIR__.'/../models/016-manual-autodial.sql'));
$db->exec("INSERT INTO v2_dialer_campaigns (campaign_id,name,carrier_id,queue_name) VALUES (1,'Laboratorio','TEST','lab1'),(2,'Otra','TEST','lab2'),(3,'Vacía','TEST','lab3')");
$db->exec("INSERT INTO v2_dialer_leads (lead_id,list_id,phone_number) VALUES (1,1,'000000')");
$db->exec("INSERT INTO v2_dialer_dispositions (campaign_id,code,label,is_contact,is_sale) VALUES (1,'SALE','Venta',1,1),(1,'NO','No contacto',0,0),(2,'SALE','Otro catálogo',0,0)");
$insert = $db->prepare('INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,answered_at,bridged_at,ended_at,amd_result,termination_reason,disposition_code,dial_level_at_origin,duration_wait,duration_talk,duration_wrapup) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute([1,1,'000000','2026-10-01 00:00:00','2026-10-01 00:00:02','2026-10-01 00:00:12','2026-10-01 00:01:12','HUMAN','COMPLETED','SALE',1.5,10,60,20]);
$insert->execute([1,1,'000000','2026-10-01 23:59:59','2026-10-02 00:00:00',null,'2026-10-02 00:00:05','NOTSURE','DROPPED',null,1.5,null,null,null]);
$insert->execute([1,1,'000000','2026-10-01 01:00:00','2026-10-01 01:00:02',null,'2026-10-01 01:00:05','MACHINE','MACHINE',null,1.5,null,null,null]);
$insert->execute([1,1,'000000','2026-10-01 02:00:00',null,null,'2026-10-01 02:00:05',null,'NOANSWER',null,1.5,null,null,null]);
$insert->execute([1,1,'000000','2026-10-01 03:00:00',null,null,null,null,null,null,2.25,null,null,null]);
$insert->execute([1,1,'000000','2026-10-02 00:00:00',null,null,null,null,null,null,1.5,null,null,null]);
$insert->execute([2,1,'000000','2026-10-01 00:00:00','2026-10-01 00:00:01',null,'2026-10-01 00:00:05','HUMAN','DROPPED',null,1.5,null,null,null]);

$k = Dialer::kpis(1, '2026-10-01', '2026-10-01');
verify($k['placed'] === 5 && $k['answered_total'] === 3 && $k['answered_human'] === 2 && $k['answered_machine'] === 1, 'Counts, date inclusivity and campaign isolation');
verify($k['dropped'] === 1 && $k['abandon_pct'] === 50.0, 'Abandonment uses answered humans');
verify($k['contact_pct'] === 40.0 && $k['asr_pct'] === 60.0 && $k['machine_pct'] === 33.33, 'Contact, ASR and machine rate');
verify($k['contacted'] === 1 && $k['sales'] === 1 && $k['conversion_pct'] === 100.0, 'Disposition flags and catalogue campaign isolation');
verify($k['avg_wait_seconds'] === 10.0 && $k['aht_seconds'] === 80.0 && $k['wait_samples'] === 1 && $k['aht_samples'] === 1, 'Timing excludes machines, abandonment and incomplete rows');
verify($k['in_progress'] === 1 && $k['occupancy_pct'] === null, 'Open calls and unknown occupancy');
$levels = Dialer::abandonByLevel(1, '2026-10-01', '2026-10-01');
verify(count($levels) === 2 && (float) $levels[0]['abandono_pct'] === 50.0 && (int) $levels[0]['humanos'] === 2, 'Level denominator equals global denominator');
verify((float) $levels[1]['dial_level_at_origin'] === 2.25 && $levels[1]['abandono_pct'] === null, 'Decimal levels and zero-human denominator');
$empty = Dialer::kpis(3, '2026-10-01', '2026-10-01');
verify($empty['placed'] === 0 && $empty['asr_pct'] === null && $empty['aht_seconds'] === null, 'Empty campaign has no percentages or artificial zero timing');
expectFailure(static function () { Dialer::kpis(999, '2026-10-01', '2026-10-01'); }, 'Missing campaign accepted');
foreach ([['2026-02-30','2026-10-01'], ['2026-10-02','2026-10-01'], ['2026-1-1','2026-10-01'], ['x','2026-10-01']] as $range) {
    expectFailure(static function () use ($range) { Dialer::kpis(1, $range[0], $range[1]); }, 'Invalid dates accepted');
}

// A disposition that is not a contact must not inflate contact conversion.
$db->exec("UPDATE v2_dialer_call_log SET disposition_code='NO' WHERE call_id=1");
verify(Dialer::kpis(1, '2026-10-01', '2026-10-01')['contacted'] === 0, 'Non-contact disposition counted as contact');
$db->exec("UPDATE v2_dialer_call_log SET agent_user='lab',ended_at=NOW()-INTERVAL 15 SECOND,duration_wrapup=NULL WHERE call_id=1");
$db->exec("INSERT INTO v2_dialer_agent_intervals (campaign_id,agent_user,state,started_at,ended_at,last_seen_at) VALUES (1,'lab','WRAPUP',NOW()-INTERVAL 15 SECOND,NOW(),NOW())");
expectFailure(static function () { Dialer::setDisposition(1, 'other', 'SALE'); }, 'Agent can disposition another agent call');
expectFailure(static function () { Dialer::setDisposition(1, 'lab', 'INVALID'); }, 'Unknown disposition accepted');
Dialer::setDisposition(1, 'lab', 'SALE');
$wrap = (int) $db->query('SELECT duration_wrapup FROM v2_dialer_call_log WHERE call_id=1')->fetchColumn();
verify($wrap >= 15 && $wrap <= 17, 'Wrapup must be observed from close to first disposition');
Dialer::setDisposition(1, 'lab', 'NO');
verify((int) $db->query('SELECT duration_wrapup FROM v2_dialer_call_log WHERE call_id=1')->fetchColumn() === $wrap, 'Repeated disposition changed original wrapup');
// No queue_log lookup or real call: timeout closes safely without bridge metrics.
$db->exec("UPDATE v2_dialer_call_log SET ended_at=NULL,answered_at=NOW(),bridged_at=NULL,duration_wait=NULL,duration_talk=NULL WHERE call_id=2");
Dialer::closeCall(2, 'TIMEOUT', '');
$closed = $db->query('SELECT * FROM v2_dialer_call_log WHERE call_id=2')->fetch(PDO::FETCH_ASSOC);
verify($closed['ended_at'] !== null && $closed['termination_reason'] === 'DROPPED' && $closed['duration_talk'] === null, 'Unbridged timeout must not get artificial talk duration');
$db->exec("INSERT INTO v2_dialer_agent_intervals (campaign_id,agent_user,state,started_at,ended_at,last_seen_at) VALUES
 (3,'occupancy_lab','ACTIVE','2026-10-01 00:00:00','2026-10-01 00:02:00','2026-10-01 00:02:00'),
 (3,'occupancy_lab','PAUSED','2026-10-01 00:02:00','2026-10-01 00:05:00','2026-10-01 00:05:00')");
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,agent_user,phone_number,originated_at,answered_at,bridged_at,ended_at,duration_talk,duration_wrapup) VALUES
 (3,1,'occupancy_lab','000000','2026-10-01 00:00:00','2026-10-01 00:00:02','2026-10-01 00:00:10','2026-10-01 00:01:10',60,20)");
$occupied=Dialer::kpis(3,'2026-10-01','2026-10-01');
verify($occupied['logged_seconds']===120 && $occupied['occupancy_pct']===66.67,'Occupancy denominator must exclude manual pauses, with observed talk + wrapup');
$db->exec("UPDATE v2_dialer_agent_intervals SET started_at='2026-10-01 00:00:30' WHERE campaign_id=3 AND state='ACTIVE'");
verify(Dialer::kpis(3,'2026-10-01','2026-10-01')['occupancy_pct']===null,'Historical call before session coverage must remain N/D');
echo "PASS: CONNECT, KPIs, ranges, campaign isolation, nulls, level denominators, dispositions, wrapup and occupancy coverage; temporary tables only.\n";

// Preserve the live callback when a rotated/truncated queue log has no CONNECT.
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,agent_user,phone_number,originated_at,answered_at,bridged_at) VALUES (1,1,'live_lab','000000',NOW()-INTERVAL 30 SECOND,NOW()-INTERVAL 25 SECOND,NOW()-INTERVAL 20 SECOND)");
$liveId=(int)$db->lastInsertId();
$db->exec("UPDATE v2_dialer_leads SET custom_fields='{" . '"monto":"100.00","local":"Laboratorio","direccion":"<script>synthetic</script>"' . "}' WHERE lead_id=1");
verify(Dialer::agentCurrentLead('live_lab')['variables']['monto']==='100.00','Live lead variables lost');
verify(Dialer::agentCurrentLead('unrelated_agent')===null,'Agent saw another agent lead');
Dialer::closeCall($liveId,'','');
$liveClosed=$db->query('SELECT * FROM v2_dialer_call_log WHERE call_id='.$liveId)->fetch(PDO::FETCH_ASSOC);
verify($liveClosed['termination_reason']==='COMPLETED' && $liveClosed['agent_user']==='live_lab' && (int)$liveClosed['duration_talk']>=20,'Missing queue log erased live callback');
expectFailure(static function() { Dialer::markBridged(1,'SIP/invalid\r\nAction: Hangup'); },'Invalid bridged channel accepted');
echo "PASS: live variables, per-agent isolation and callback preservation without queue log.\n";
