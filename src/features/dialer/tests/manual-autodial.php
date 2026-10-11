<?php
declare(strict_types=1);
require __DIR__.'/agent-controls.php';
use Includes\Dialer;
use Includes\PbxAmi;
foreach (['v2_dialer_lists','v2_dialer_dnc','v2_carriers','v2_dialplans'] as $table) {
    $ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl);
    $ddl=preg_replace('/^  CONSTRAINT .* FOREIGN KEY .*\n/m','',$ddl);
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl); $db->exec($ddl);
}
$db->exec("INSERT INTO v2_carriers (carrier_id,carrier_name,account_entry,dialplan_entry,server_ip,active,protocol) VALUES ('TEST','Synthetic','[lab]\ntype=peer\nhost=127.0.0.1\n','','127.0.0.1','Y','SIP')");
$db->exec("DELETE FROM v2_dialer_call_log");
$db->exec("UPDATE v2_dialer_campaigns SET dial_method='MANUAL',engine_state='RUNNING',dial_prefix='7399',outbound_prefix='',caller_id_number='79990',local_call_time_start='00:00:00',local_call_time_end='23:59:59',queue_name=CONCAT('v2dialer_',campaign_id),manual_autodial_enabled=IF(campaign_id=1,1,0)");
$db->exec("INSERT INTO v2_dialer_lists (list_id,campaign_id,name) VALUES (1,1,'Autodial fixture'),(2,2,'Other fixture')");
$db->exec("UPDATE v2_dialer_leads SET status='NEW',called_count=0,next_eligible_at=NOW()-INTERVAL 1 MINUTE WHERE lead_id=1");
for($id=2;$id<=20;$id++) $db->exec("INSERT INTO v2_dialer_leads (lead_id,list_id,phone_number,next_eligible_at) VALUES ($id,1,'0000$id',NOW()-INTERVAL 1 MINUTE)");
function originated(): int { return count(array_filter(PbxAmi::$events,static function($e) {return $e[0]==='Originate';})); }
function due(): void { global $db; $db->exec("UPDATE v2_dialer_agent_status SET autodial_due_at=NOW()-INTERVAL 1 SECOND WHERE agent_user='lab' AND autodial_source_call_id IS NOT NULL"); }
function finish(int $call): void { Dialer::closeManual($call,'NOANSWER','zv2dialer-'.$call); Dialer::setDisposition($call,'lab','NO'); }
function arm(): int { $call=Dialer::originateNext(1,'lab'); verify($call!==null,'Manual fixture did not originate'); finish($call); verify(Dialer::agentAutodialState('lab')['pending'],'Disposition did not schedule continuation'); return $call; }
Dialer::agentLogin('lab');
$before=originated(); Dialer::selectManualCampaign('lab',1); Dialer::processManualAutodial(1);
verify(originated()===$before && !Dialer::agentAutodialState('lab')['pending'],'Login/selection triggered a call');
expectFailure(static function(){Dialer::selectManualCampaign('other',1);},'Unassigned campaign accepted');
expectFailure(static function(){Dialer::selectManualCampaign('lab',3);},'Unassigned third campaign accepted');
$source=arm(); $scheduled=$db->query("SELECT autodial_due_at FROM v2_dialer_agent_status WHERE agent_user='lab'")->fetchColumn();
Dialer::setDisposition($source,'lab','NO');
verify($scheduled===$db->query("SELECT autodial_due_at FROM v2_dialer_agent_status WHERE agent_user='lab'")->fetchColumn(),'Duplicate disposition rearmed timer');
$before=originated(); Dialer::processManualAutodial(1); verify(originated()===$before,'Autodial fired before delay');
due(); Dialer::processManualAutodial(1); $next=(int)$db->query('SELECT MAX(call_id) FROM v2_dialer_call_log')->fetchColumn();
verify(originated()===$before+1 && $next>$source && !Dialer::agentAutodialState('lab')['pending'],'Worker did not consume exactly one continuation');
$event=end(PbxAmi::$events); $orig=array_values(array_filter(PbxAmi::$events,static function($e){return $e[0]==='Originate';})); $fields=end($orig)[1];
verify($fields['Channel']==='SIP/79990' && $fields['Context']==='zynervoxv2-dialer-manual-1','Autodial bypassed agent-first MANUAL');
Dialer::processManualAutodial(1); verify(originated()===$before+1,'Consumed continuation originated twice');
verify(Dialer::originateNext(1,'lab',$source)===null,'Stale continuation reserved a second call');
Dialer::selectManualCampaign('lab',2); finish($next); verify(!Dialer::agentAutodialState('lab')['pending'],'Old campaign disposition overrode selected campaign');
$source=arm(); Dialer::selectManualCampaign('lab',2); due(); $before=originated(); Dialer::processManualAutodial(1);
verify(originated()===$before && !Dialer::agentAutodialState('lab')['pending'],'Selection switch retained old continuation');
$source=arm(); Dialer::agentPauseAll('lab',$type); verify(!Dialer::agentAutodialState('lab')['pending'],'Pause retained continuation');
Dialer::agentPauseAll('lab',null); verify(!Dialer::agentAutodialState('lab')['pending'],'Resume rearmed continuation');
$source=arm(); Dialer::agentLogout('lab'); verify(!Dialer::agentAutodialState('lab')['pending'],'Logout retained continuation'); Dialer::agentLogin('lab');
$source=arm(); Dialer::setEngineState(1,'STOPPED'); verify(!Dialer::agentAutodialState('lab')['pending'],'Stop retained continuation'); Dialer::setEngineState(1,'RUNNING');
$source=arm(); $input=['name'=>'Laboratorio','active'=>'1']; foreach(Dialer::CAMPAIGN_FIELDS as $key=>$field) $input[$key]=Dialer::campaign(1)[$key]; $input['manual_autodial_enabled']='0';
Dialer::updateCampaign(1,$input); verify(!Dialer::agentAutodialState('lab')['pending'],'Disable flag retained continuation');
$db->exec("UPDATE v2_dialer_campaigns SET manual_autodial_enabled=1 WHERE campaign_id=1");
$source=arm(); due(); $db->exec("UPDATE v2_dialer_leads SET status='CONTACTED'"); $before=originated(); Dialer::processManualAutodial(1);
verify(originated()===$before && !Dialer::agentAutodialState('lab')['pending'],'No eligible lead retained delayed request');
$db->exec("UPDATE v2_dialer_leads SET status='NEW',next_eligible_at=NOW()-INTERVAL 1 MINUTE WHERE lead_id>=10");
$source=arm(); $db->exec("UPDATE v2_dialer_agent_status SET autodial_due_at=NOW()-INTERVAL 31 SECOND WHERE agent_user='lab'"); $before=originated(); Dialer::processManualAutodial(1); verify(originated()===$before && !Dialer::agentAutodialState('lab')['pending'],'Stale restart request unexpectedly dialed');
$source=arm(); due(); unset(PbxAmi::$queues['v2dialer_1']['SIP/79990']); $before=originated(); Dialer::processManualAutodial(1); verify(originated()===$before && !Dialer::agentAutodialState('lab')['pending'],'Missing device retained request'); Dialer::agentLogin('lab');
$source=arm(); Dialer::setCampaignAgents(1,[]); verify(!Dialer::agentAutodialState('lab')['pending'] && Dialer::agentAutodialState('lab')['selected_campaign_id']===null,'Unassignment retained selection/continuation');
Dialer::setCampaignAgents(1,['lab']); Dialer::agentLogin('lab'); Dialer::selectManualCampaign('lab',1);
$db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,ended_at,agent_user) VALUES (1,1,'000000',NOW(),NOW(),'lab')"); $old=(int)$db->lastInsertId(); Dialer::setDisposition($old,'lab','NO'); verify(!Dialer::agentAutodialState('lab')['pending'],'Historical automatic call rearmed MANUAL');
Dialer::agentLogout('lab');
echo "PASS: selected campaign, first-disposition idempotence, delay, one-shot worker, stale tokens, MANUAL agent-first, switch/pause/logout/stop/disable/unassign cancellation, no lead/device, historical origin.\n";