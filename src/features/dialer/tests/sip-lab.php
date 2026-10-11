<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('DIALER_LAB_CONFIRM') !== 'LOOPBACK_SIP') exit(1);
$web='/srv/www/htdocs/zynervoxv2';
require $web.'/includes/Dialer.php';
require $web.'/includes/Carriers.php';
use Includes\Dialer;
use Includes\Carriers;
use Includes\Phones;
use Includes\Database;
use Includes\PbxAmi;
function check_sip($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$db=Database::getCoreInstance(); $ami=PbxAmi::load();
$agent='79996'; $carrierId='DIALER_LAB_SIP'; $campaigns=[]; $recordings=[]; $process=null;
$directory=sys_get_temp_dir().'/dialer-sip-'.bin2hex(random_bytes(6));
mkdir($directory,0700);
check_sip(!Phones::getById($agent) && !Carriers::getById($carrierId),'Fixture identifiers already exist; do not reuse them.');
check_sip(strpos(PbxAmi::command($ami,'core show channels count'),'0 active channels')!==false,'PBX must have no active calls before laboratory.');
function call_row(int $id): array {
    global $db;
    $q=$db->prepare('SELECT * FROM v2_dialer_call_log WHERE call_id=:id'); $q->execute(['id'=>$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
function wait_closed(int $id): array {
    global $campaigns;
    for ($i=0;$i<45;$i++) {
        usleep(500000);
        foreach ($campaigns as $c) { Dialer::reconcileUnanswered($c); Dialer::observeAgentSessions($c); }
        $row=call_row($id);
        if ($row['ended_at']!==null) return $row;
    }
    throw new RuntimeException('Call did not close: '.$id);
}
function prepare_number(int $campaignId,string $number): void {
    global $db;
    $db->prepare("UPDATE v2_dialer_leads d JOIN v2_dialer_lists l ON l.list_id=d.list_id SET d.status=IF(d.phone_number=:p,'NEW','CONTACTED'),d.next_eligible_at=NOW(),d.called_count=0 WHERE l.campaign_id=:c")->execute(['p'=>$number,'c'=>$campaignId]);
}
function change_settings(int $campaignId,array $changes): void {
    $campaign=Dialer::campaign($campaignId); $input=['name'=>$campaign['name'],'active'=>'1'];
    foreach (Dialer::CAMPAIGN_FIELDS as $key=>$field) $input[$key]=$campaign[$key] ?? '';
    Dialer::updateCampaign($campaignId,array_replace($input,$changes));
}
try {
    $secret=bin2hex(random_bytes(20));
    check_sip(Phones::create(['extension'=>$agent,'login'=>$agent,'pass'=>$secret,'fullname'=>'Dialer SIP lab','outbound_cid'=>$agent,'active'=>'Y','protocol'=>'SIP']),'Create lab phone failed.');
    Carriers::create(['carrier_id'=>$carrierId,'carrier_name'=>'Loopback dialer laboratory','protocol'=>'SIP','server_ip'=>'127.0.0.1','active'=>'Y',
        'account_entry'=>"[dialer_lab_client]\ntype=peer\nhost=127.0.0.1\nport=5097\ncontext=zynervoxv2\nqualify=no\ndisallow=all\nallow=ulaw\ninsecure=port,invite\nnat=no\ndirectmedia=no\n",'dialplan_entry'=>'']);
    PbxAmi::command($ami,'sip reload');
    file_put_contents($directory.'/credentials.json',json_encode(['extension'=>$agent,'secret'=>$secret])); chmod($directory.'/credentials.json',0600);
    $process=proc_open(['python3',__DIR__.'/sip-lab-peer.py',$directory.'/credentials.json'],[0=>['pipe','r'],1=>['file',$directory.'/peer.log','a'],2=>['file',$directory.'/peer.log','a']],$pipes);
    check_sip(is_resource($process),'SIP fixture did not start.');
    for ($i=0;$i<50;$i++) { usleep(100000); if (strpos((string)file_get_contents($directory.'/peer.log'),'READY')!==false) break; }
    check_sip(strpos((string)file_get_contents($directory.'/peer.log'),'READY')!==false,'SIP agent registration failed: '.file_get_contents($directory.'/peer.log'));
    foreach (['MANUAL','PROGRESSIVE','ADAPTIVE'] as $index=>$mode) {
        $c=Dialer::createCampaign('LAB SIP '.$mode,$carrierId,false); $campaigns[]=$c;
        $campaign=Dialer::campaign($c); $input=['name'=>$campaign['name'],'active'=>'1'];
        foreach (Dialer::CAMPAIGN_FIELDS as $key=>$field) $input[$key]=$campaign[$key] ?? '';
        $input=array_replace($input,['dial_method'=>$mode,'dial_prefix'=>'7989'.($index+1),'outbound_prefix'=>'','amd_enabled'=>'1','dial_timeout_seconds'=>5,'drop_call_seconds'=>3,'max_concurrent_calls'=>2,'wrapup_seconds'=>0,'local_call_time_start'=>'00:00:00','local_call_time_end'=>'23:59:59']);
        Dialer::updateCampaign($c,$input);
        Dialer::setCampaignAgents($c,[$agent]); Dialer::createDisposition($c,'SALE','Lab contact',true,true,false,0);
        $list=Dialer::createList($c,'Loopback only');
        Dialer::importLeads($list,$c,Dialer::parseLeadsTxt("numero,nombre\n000001,Human\n000002,Machine\n000003,Noanswer\n000004,Drop\n000005,Earlyhangup\n000006,Earlymedia\n000007,Busy\n"));
        Dialer::agentJoinQueue($agent,$c); Dialer::agentJoinQueue($agent,$c); // Idempotent login.
    }
    foreach ($campaigns as $index=>$c) {
        $mode=['MANUAL','PROGRESSIVE','ADAPTIVE'][$index];
        prepare_number($c,'000001'); Dialer::setEngineState($c,'RUNNING');
        $id=null;
        if ($mode!=='MANUAL' && getenv('DIALER_LAB_USE_SERVICE')==='1') {
            for ($attempt=0;$attempt<50;$attempt++) {
                $q=$db->prepare('SELECT MAX(call_id) FROM v2_dialer_call_log WHERE campaign_id=:c'); $q->execute(['c'=>$c]);
                $value=$q->fetchColumn(); if ($value) { $id=(int)$value; break; } usleep(100000);
            }
        } else $id=Dialer::originateNext($c,$mode==='MANUAL' ? $agent : null);
        check_sip($id!==null,$mode.' did not originate.');
        if ($mode==='MANUAL') {
            Dialer::agentLogin($agent); // Reload during ringing must keep personal queue available.
            $pauseType=(int)Dialer::pauseTypes()[0]['pause_type_id'];
            Dialer::agentPauseAll($agent,$pauseType); Dialer::agentLogin($agent);
            check_sip(Dialer::agentPause($agent)!==null,'Auto login removed requested pause.');
        }
        if ($mode==='PROGRESSIVE') {
            $pauseType=(int)Dialer::pauseTypes()[0]['pause_type_id'];
            Dialer::agentPauseAll($agent,$pauseType);
        }
        if ($mode==='MANUAL') {
            Dialer::setEngineState($campaigns[1],'RUNNING');
            check_sip(Dialer::originateNext($campaigns[1])===null,'Shared agent reserved twice across campaigns.');
            Dialer::setEngineState($campaigns[1],'STOPPED');
        }
        Dialer::setEngineState($c,'STOPPED');
        check_sip(Dialer::originateNext($c,$mode==='MANUAL' ? $agent : null)===null,'STOPPED allowed new origin.');
        $row=wait_closed($id);
        check_sip($row['termination_reason']==='COMPLETED' && $row['agent_user']===$agent && $row['bridged_at']!==null,$mode.' did not bridge/attribute: '.json_encode($row));
        check_sip($row['amd_result']===($mode==='MANUAL' ? null : 'HUMAN'),$mode.' AMD classification/bypass failed.');
        if ($mode==='MANUAL') {
            check_sip(strpos(file_get_contents($directory.'/peer.log'),'CLIENT_INVITE: agent_connected=True')!==false,'Manual called destination before agent connected.');
            check_sip(strpos(file_get_contents($directory.'/peer.log'),'RINGING_AUDIO_RECEIVED')!==false,'Manual agent did not receive ringing audio before customer answer.');
        }
        check_sip(strtotime($row['answered_at'])-strtotime($row['originated_at'])>=1,'Answer recorded before SIP 200.');
        check_sip(is_file($row['recording_path']) && filesize($row['recording_path'])>44,'Missing real RTP recording.'); $recordings[]=$row['recording_path'];
        check_sip(Dialer::pendingDispositionForAgent($agent)['call_id']==$id,'No pending disposition.');
        Dialer::setDisposition($id,$agent,'SALE');
        if ($mode==='MANUAL' || $mode==='PROGRESSIVE') {
            check_sip(Dialer::agentPause($agent)!==null,'Closer/disposition removed requested pause.');
            Dialer::agentPauseAll($agent,null);
        }
        $k=Dialer::kpis($c); check_sip($k['sales']===1 && $k['occupancy_pct']!==null,'Sale/occupancy not observed.');
        echo json_encode(['mode'=>$mode,'call_id'=>$id,'amd'=>$row['amd_result'],'reason'=>$row['termination_reason'],'agent'=>$row['agent_user'],'talk_seconds'=>$row['duration_talk'],'recording_bytes'=>filesize($row['recording_path']),'occupancy_pct'=>$k['occupancy_pct']]),"\n";
    }
    $c=$campaigns[0];
    change_settings($c,['amd_action_machine'=>'MESSAGE','amd_message'=>'dialer-lab/message']);
    foreach (['000002'=>'COMPLETED','000003'=>'NOANSWER','000005'=>'COMPLETED','000006'=>'COMPLETED','000007'=>'BUSY'] as $number=>$reason) {
        prepare_number($c,$number); Dialer::setEngineState($c,'RUNNING'); $id=Dialer::originateNext($c,$agent); check_sip($id!==null,'Scenario could not originate.'); Dialer::setEngineState($c,'STOPPED');
        if ($number==='000006') {
            usleep(800000); $progress=call_row($id);
            check_sip($progress['answered_at']===null && $progress['bridged_at']===null && $progress['recording_path']===null,'Agent answer/early media counted as customer answer or started recording.');
        }
        $row=wait_closed($id); check_sip($row['termination_reason']===$reason,'Wrong scenario result: '.json_encode($row));
        check_sip($row['amd_result']===null,'Manual must never run AMD.');
        if ($number==='000006') check_sip(strpos(file_get_contents($directory.'/peer.log'),'EARLY_MEDIA_RECEIVED')!==false,'Provider early media did not reach agent before answer.');
        if ($row['agent_user']) Dialer::setDisposition($id,$agent,'SALE');
        else check_sip($row['answered_at']===null && $row['bridged_at']===null && $row['recording_path']===null && $row['agent_slot']===null,'Unanswered manual attempt invented conversation or retained reservation.');
        if (!$row['agent_user']) { check_sip((int)Dialer::pendingDispositionForAgent($agent)['call_id']===$id,'Manual failure missing disposition.'); Dialer::setDisposition($id,$agent,'SALE'); }
        if ($row['recording_path'] && is_file($row['recording_path'])) $recordings[]=$row['recording_path'];
        echo json_encode(['scenario'=>$number,'call_id'=>$id,'reason'=>$row['termination_reason'],'amd'=>$row['amd_result']]),"\n";
    }
    foreach (['noanswer'=>'NOANSWER','hangup'=>'HANGUP'] as $action=>$reason) {
        $before=substr_count(file_get_contents($directory.'/peer.log'),'CLIENT_INVITE:');
        file_put_contents($directory.'/agent-control',$action);
        prepare_number($c,'000001'); Dialer::setEngineState($c,'RUNNING'); $id=Dialer::originateNext($c,$agent); check_sip($id!==null,'Agent failure fixture did not originate.'); Dialer::setEngineState($c,'STOPPED');
        $row=wait_closed($id); unlink($directory.'/agent-control');
        check_sip($row['termination_reason']===$reason && $row['answered_at']===null && $row['recording_path']===null && $row['agent_slot']===null,'Agent failure invented customer answer or retained slot: '.json_encode($row));
        if ($action==='noanswer') check_sip(substr_count(file_get_contents($directory.'/peer.log'),'CLIENT_INVITE:')===$before,'Destination called when agent did not answer.');
        check_sip((int)Dialer::pendingDispositionForAgent($agent)['call_id']===$id,'Agent failure missing manual disposition.'); Dialer::setDisposition($id,$agent,'SALE');
        echo 'PASS: agent '.$action.', no customer answer/recording, reservation released.',"\n";
    }
    foreach (['ringing','talking'] as $phase) {
        prepare_number($c,'000001'); Dialer::setEngineState($c,'RUNNING'); $id=Dialer::originateNext($c,$agent); check_sip($id!==null,'Hangup fixture did not originate.'); Dialer::setEngineState($c,'STOPPED');
        if ($phase==='talking') {
            for ($i=0;$i<30;$i++) { if (call_row($id)['bridged_at']!==null) break; usleep(100000); }
            check_sip(call_row($id)['bridged_at']!==null,'Hangup fixture never bridged.');
        } else usleep(400000);
        Dialer::agentHangup($agent,$id); $row=wait_closed($id);
        check_sip($row['ended_at']!==null && $row['agent_slot']===null,'AMI Hangup did not close/release call.');
        if ($phase==='ringing') check_sip($row['answered_at']===null,'Hangup during ringing invented customer answer.');
        if ($row['recording_path']) $recordings[]=$row['recording_path'];
        check_sip((int)Dialer::pendingDispositionForAgent($agent)['call_id']===$id,'Hangup missing disposition.');
        Dialer::setDisposition($id,$agent,'SALE');
        echo 'PASS: own-call AMI Hangup '.$phase.', real PBX handler closure.',"\n";
    }
    Dialer::agentLogout($agent);
    check_sip(Dialer::availableAgentsCount($c,'LOGGED_IN')===0,'Logout retained agent availability.');
    Dialer::agentLogin($agent);
    check_sip(Dialer::availableAgentsCount($c,'WAITING')===1,'Automatic login did not restore assigned membership.');
    // Preserve the actual AMD/MESSAGE regression in the destination-first mode.
    change_settings($c,['dial_method'=>'PROGRESSIVE']); prepare_number($c,'000002');
    $lead=Dialer::claimLead($c,Dialer::campaign($c)['dial_prefix'],1,$agent);
    PbxAmi::rawAction($ami,'Originate',Dialer::originateFields(Dialer::campaign($c),$lead,Carriers::getById($carrierId)));
    $row=wait_closed($lead['call_id']);
    check_sip($row['termination_reason']==='MACHINE' && $row['amd_result']==='MACHINE','Progressive AMD/MESSAGE changed.');
    $recordings[]=$row['recording_path'];
    echo 'PASS: progressive MACHINE and configured MESSAGE preserved.',"\n";
    // Real SIP destination with all agents removed: explicit lower-level probe of overflow.
    foreach ($campaigns as $c) Dialer::agentLeaveQueue($agent,$c);
    $c=$campaigns[0]; prepare_number($c,'000004');
    change_settings($c,['dial_method'=>'PROGRESSIVE','drop_action'=>'MESSAGE','drop_message'=>'dialer-lab/message']);
    $lead=Dialer::claimLead($c,Dialer::campaign($c)['dial_prefix'],1);
    PbxAmi::rawAction($ami,'Originate',Dialer::originateFields(Dialer::campaign($c),$lead,Carriers::getById($carrierId)));
    $row=wait_closed($lead['call_id']); check_sip($row['termination_reason']==='DROPPED','Real SIP queue timeout failed.'); $recordings[]=$row['recording_path'];
    check_sip(strtotime($row['ended_at'])-strtotime($row['answered_at'])>=4,'MESSAGE playback closed capacity too early.');
    if (getenv('DIALER_LAB_TEST_PJSIP')==='1') {
    // Optional future protocol test; the current deployment operates with SIP.
    Carriers::update($carrierId,['carrier_name'=>'Loopback dialer laboratory','protocol'=>'PJSIP','server_ip'=>'127.0.0.1','active'=>'Y',
        'account_entry'=>"[dialer_lab_client]\ntype=endpoint\ncontext=zynervoxv2\ndisallow=all\nallow=ulaw\naors=dialer_lab_client\ndirect_media=no\nrtp_symmetric=yes\n\n[dialer_lab_client]\ntype=aor\ncontact=sip:127.0.0.1:5097\n",'dialplan_entry'=>'']);
    PbxAmi::command($ami,'pjsip reload');
    change_settings($c,['dial_method'=>'MANUAL','amd_action_machine'=>'HANGUP','drop_action'=>'HANGUP']);
    Dialer::agentJoinQueue($agent,$c); prepare_number($c,'000001'); Dialer::setEngineState($c,'RUNNING');
    $id=Dialer::originateNext($c,$agent); check_sip($id!==null,'PJSIP did not originate.'); Dialer::setEngineState($c,'STOPPED');
    $row=wait_closed($id); check_sip($row['termination_reason']==='COMPLETED' && $row['agent_user']===$agent,'PJSIP actual bridge failed: '.json_encode($row)); $recordings[]=$row['recording_path'];
    Dialer::setDisposition($id,$agent,'SALE');
    echo json_encode(['protocol'=>'PJSIP','call_id'=>$id,'reason'=>$row['termination_reason'],'amd'=>$row['amd_result'],'recording_bytes'=>filesize($row['recording_path'])]),"\n";
    }
    // Simulate an answer whose channel disappeared before its hangup handler.
    prepare_number($c,'000003'); $orphan=Dialer::claimLead($c,Dialer::campaign($c)['dial_prefix'],1);
    Dialer::markAnswered($orphan['call_id'],'zv2dialer-'.$orphan['call_id']);
    $db->prepare('UPDATE v2_dialer_call_log SET answered_at=NOW()-INTERVAL 20 SECOND WHERE call_id=:id')->execute(['id'=>$orphan['call_id']]);
    $row=wait_closed($orphan['call_id']);
    check_sip($row['termination_reason']==='PBX_LOST' && $row['duration_talk']===null && Dialer::inFlightCount($c)===0,'Lost answered channel was not recovered without invented talk time.');
    echo "PASS: loopback SIP/RTP manual, progressive, predictive, shared agent, stop/drain, HUMAN/MACHINE, bridge, recording, disposition, occupancy, noanswer, early hangup, overflow.\n";
} finally {
    foreach ($campaigns as $c) {
        try { Dialer::setEngineState($c,'STOPPED'); } catch (Throwable $error) {}
    }
    foreach (explode("\n",PbxAmi::command($ami,'core show channels concise')) as $line) {
        $cols=explode('!',preg_replace('/^Output: /','',$line));
        if (count($cols)>=12 && (strpos($cols[0],'SIP/dialer_lab_client-')===0 || strpos($cols[0],'SIP/'.$agent.'-')===0)) PbxAmi::rawAction($ami,'Hangup',['Channel'=>$cols[0]]);
    }
    sleep(1);
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach ($campaigns as $c) {
        try { Dialer::agentLeaveQueue($agent,$c); } catch (Throwable $error) {}
        $db->prepare('DELETE FROM v2_dialer_call_log WHERE campaign_id=:c')->execute(['c'=>$c]);
        $db->prepare('DELETE d FROM v2_dialer_leads d JOIN v2_dialer_lists l ON l.list_id=d.list_id WHERE l.campaign_id=:c')->execute(['c'=>$c]);
        $db->prepare('DELETE FROM v2_dialer_lists WHERE campaign_id=:c')->execute(['c'=>$c]);
        Dialer::deleteCampaign($c);
    }
    Phones::delete($agent); Carriers::delete($carrierId); PbxAmi::command($ami,'sip reload');
    $db->prepare('DELETE FROM v2_dialer_agent_pauses WHERE agent_user=?')->execute([$agent]);
    $db->prepare('DELETE FROM v2_dialer_agent_status WHERE agent_user=?')->execute([$agent]);
    if (getenv('DIALER_LAB_TEST_PJSIP')==='1') PbxAmi::command($ami,'pjsip reload');
    PbxAmi::command($ami,'dialplan reload');
    $evidence=getenv('DIALER_LAB_EVIDENCE');
    foreach (array_unique($recordings) as $file) if (is_file($file) && strpos($file,Dialer::RECORDING_DIRECTORY.'/')===0) {
        if ($evidence && is_dir($evidence) && strpos(realpath($evidence),'/var/backups/zynervoxv2/')===0) copy($file,$evidence.'/'.basename($file));
        unlink($file);
    }
    if ($evidence && is_dir($evidence) && strpos(realpath($evidence),'/var/backups/zynervoxv2/')===0 && is_file($directory.'/peer.log')) copy($directory.'/peer.log',$evidence.'/peer.log');
    foreach (glob($directory.'/*') as $file) unlink($file); rmdir($directory);
    echo "CLEAN: own SIP fixtures removed; audit retained.\n";
}
