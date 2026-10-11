<?php
declare(strict_types=1);
require __DIR__.'/reporting.php';
use Includes\Dialer;
$ddl=$db->query('SHOW CREATE TABLE v2_dialer_lists')->fetch(PDO::FETCH_NUM)[1];
$ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl);
$ddl=preg_replace('/^  CONSTRAINT .* FOREIGN KEY .*\n/m','',$ddl);
$db->exec(preg_replace('/,\n\)/',"\n)",$ddl));
$db->exec('DELETE FROM v2_dialer_call_log'); $db->exec('DELETE FROM v2_dialer_leads');
$db->exec("INSERT INTO v2_dialer_lists (list_id,campaign_id,name,active) VALUES (1,1,'First',1),(2,1,'Inactive',0),(3,2,'Foreign',1),(4,1,'Empty',1)");
$db->exec("INSERT INTO v2_dialer_leads (lead_id,list_id,phone_number) VALUES (1,1,'111111'),(2,1,'222222'),(3,2,'333333'),(4,3,'444444')");
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,answered_at,bridged_at,ended_at,termination_reason,disposition_code,dial_level_at_origin,duration_talk,duration_wrapup) VALUES
 (1,1,'111111','2026-10-01 12:00:00',NULL,NULL,'2026-10-01 12:00:10','NOANSWER','NO',1,NULL,NULL),
 (1,1,'111111','2026-10-01 13:00:00','2026-10-01 13:00:02','2026-10-01 13:00:03','2026-10-01 13:00:13','COMPLETED','SALE',1,10,2),
 (1,2,'222222','2026-10-01 14:00:00',NULL,NULL,NULL,NULL,NULL,1,NULL,NULL),
 (1,3,'333333','2026-10-01 15:00:00',NULL,NULL,'2026-10-01 15:00:10','HANGUP',NULL,2,NULL,NULL),
 (1,3,'333333','2026-10-01 16:00:00','2026-10-01 16:00:02',NULL,'2026-10-01 16:00:10','MACHINE',NULL,2,NULL,NULL),
 (1,3,'333333','2026-10-01 17:00:00','2026-10-01 17:00:02',NULL,'2026-10-01 17:00:10','DROPPED',NULL,2,NULL,NULL),
 (2,4,'444444','2026-10-01 12:00:00',NULL,NULL,'2026-10-01 12:00:10','NOANSWER','SALE',1,NULL,NULL),
 (1,1,'111111','2026-10-02 00:00:00',NULL,NULL,'2026-10-02 00:00:10','HANGUP',NULL,1,NULL,NULL)");
$db->exec("UPDATE v2_dialer_call_log SET amd_result='MACHINE' WHERE termination_reason='MACHINE'");
$all=Dialer::campaignReport(1,'2026-10-01','2026-10-01');
$first=Dialer::campaignReport(1,'2026-10-01','2026-10-01',1);
$inactive=Dialer::campaignReport(1,'2026-10-01','2026-10-01',2);
$empty=Dialer::campaignReport(1,'2026-10-01','2026-10-01',4);
foreach ([$all,$first,$inactive,$empty] as $report) {
    $k=$report['kpis'];
    verify($k['placed']===$k['closed']+$k['in_progress'],'Attempts do not reconcile');
    verify($k['closed']===$k['disposed']+$k['closed_unclassified'],'Dispositions do not reconcile');
    verify($k['placed']===array_sum(array_column($report['breakdown']['results'],'calls')),'PBX results do not reconcile');
    verify($k['disposed']===array_sum(array_column($report['breakdown']['dispositions'],'calls')),'Disposition codes do not reconcile');
    verify($k['placed']===array_sum(array_column($report['levels'],'llamadas')),'Level scope diverged');
}
verify($all['kpis']['placed']===6 && $first['kpis']['placed']===3 && $inactive['kpis']['placed']===3,'Campaign/list/date scope mixed');
verify($first['kpis']['unique_phones']===2 && $first['kpis']['disposed']===2 && $first['kpis']['contacted']===1,'Retry or failed disposition miscounted');
verify($first['kpis']['answered_total']===1 && $first['kpis']['asr_pct']===33.33 && $first['kpis']['aht_seconds']===12.0,'Filtered timing/denominators diverged');
verify($inactive['kpis']['answered_total']===2 && $inactive['kpis']['answered_human']===1 && $inactive['kpis']['answered_machine']===1 && $inactive['kpis']['abandon_pct']===100.0,'Inactive list or MACHINE denominator wrong');
verify(count($first['levels'])===1 && count($inactive['levels'])===1 && (float)$inactive['levels'][0]['abandono_pct']===100.0,'Filtered level grouping wrong');
verify($inactive['kpis']['occupancy_pct']===null && $inactive['kpis']['logged_seconds']===null,'Invented list occupancy');
verify($empty['kpis']['placed']===0 && $empty['kpis']['asr_pct']===null && $empty['breakdown']['results']===[],'Empty list mixed campaign totals');
foreach ([0,-1,3,999] as $bad) {
    expectFailure(static function() use($bad){Dialer::campaignReport(1,'2026-10-01','2026-10-01',$bad);},'Invalid/foreign list accepted');
    verify(!$db->inTransaction(),'Rejected filter leaked transaction');
}
verify(Dialer::kpis(1,'2026-10-01','2026-10-01')['placed']===6,'Legacy all-list API changed');
echo "PASS: list/campaign/date isolation, retries, unconnected dispositions vs contacts, closed/open reconciliation, PBX/catalog totals, inactive/empty/foreign lists, percentages, levels, timing and honest list occupancy.\n";