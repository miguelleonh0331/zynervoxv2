<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('DIALER_LAB_CONFIRM')!=='TEN_LOOPBACK_AGENTS') exit(1);
require '/srv/www/htdocs/zynervoxv2/includes/Dialer.php';
require '/srv/www/htdocs/zynervoxv2/includes/Carriers.php';
use Includes\Dialer;
use Includes\Phones;
use Includes\Carriers;
use Includes\Database;
use Includes\PbxAmi;
function labAssert($ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=Database::getCoreInstance(); $ami=PbxAmi::load(); $agents=[]; $campaigns=[]; $process=null;
$carrier='DIALER_TEN_SIP'; $directory=getenv('DIALER_LAB_EVIDENCE');
labAssert($directory && is_dir($directory) && strpos(realpath($directory),'/var/backups/zynervoxv2/')===0,'Private evidence directory required.');
labAssert(preg_match('/(?:^|\n)(?:Output: )?0 active channels(?:\r?\n|$)/',PbxAmi::command($ami,'core show channels count'))===1,'PBX must be idle.');
$existing=Carriers::getById($carrier);
labAssert(!$existing || ($existing['active']==='N' && $existing['carrier_name']==='Ten-agent loopback laboratory' && $existing['server_ip']==='127.0.0.1'),'Exclusive carrier is not an inactive laboratory fixture.');
labAssert((int)$db->query("SELECT COUNT(*) FROM v2_dialer_campaigns WHERE active=1 AND engine_state='RUNNING'")->fetchColumn()===0,'Other engines must be stopped before the laboratory.');
for ($i=0;$i<10;$i++) labAssert(!Phones::getById((string)(79970+$i)),'Exclusive agent exists.');
$results=[]; $success=false;
try {
    $credentials=[];
    for ($i=0;$i<10;$i++) {
        $agent=(string)(79970+$i); $secret=bin2hex(random_bytes(20));
        labAssert(Phones::create(['extension'=>$agent,'login'=>$agent,'pass'=>$secret,'fullname'=>'Ten-agent SIP lab','outbound_cid'=>$agent,'active'=>'Y','protocol'=>'SIP']),'Phone creation failed.');
        $agents[]=$agent; $credentials[]=['extension'=>$agent,'secret'=>$secret];
    }
    $carrierData=['carrier_id'=>$carrier,'carrier_name'=>'Ten-agent loopback laboratory','protocol'=>'SIP','server_ip'=>'127.0.0.1','active'=>'Y','account_entry'=>"[dialer_ten_client]\ntype=peer\nhost=127.0.0.1\nport=5097\ncontext=zynervoxv2\nqualify=no\ndisallow=all\nallow=ulaw\ninsecure=port,invite\nnat=no\ndirectmedia=no\n",'dialplan_entry'=>''];
    if ($existing) Carriers::update($carrier,$carrierData); else Carriers::create($carrierData);
    PbxAmi::command($ami,'sip reload');
    file_put_contents($directory.'/credentials.json',json_encode($credentials)); chmod($directory.'/credentials.json',0600);
    $process=proc_open(['python3',__DIR__.'/multi-sip-peer.py',$directory],[0=>['pipe','r'],1=>['file',$directory.'/peer.log','a'],2=>['file',$directory.'/peer.log','a']],$pipes);
    labAssert(is_resource($process),'Peer process did not start.');
    for ($i=0;$i<100;$i++) { usleep(100000); if (substr_count((string)file_get_contents($directory.'/peer.log'),'READY ')>=10) break; }
    labAssert(substr_count((string)file_get_contents($directory.'/peer.log'),'READY ')===10,'Ten SIP registrations not observed.');
    foreach (['MANUAL','PROGRESSIVE','ADAPTIVE'] as $index=>$mode) {
        $c=Dialer::createCampaign('LAB TEN '.$mode,$carrier,false); $campaigns[]=$c;
        $row=Dialer::campaign($c); $input=['name'=>$row['name'],'active'=>'1'];
        foreach (Dialer::CAMPAIGN_FIELDS as $key=>$field) $input[$key]=$row[$key] ?? '';
        Dialer::updateCampaign($c,array_replace($input,['dial_method'=>$mode,'dial_prefix'=>'797800'.$c,'outbound_prefix'=>'','amd_enabled'=>1,'dial_timeout_seconds'=>5,'drop_call_seconds'=>20,'max_concurrent_calls'=>10,'dial_level'=>1,'adaptive_min_level'=>1,'adaptive_max_level'=>1.5,'adaptive_min_sample'=>100,'wrapup_seconds'=>0,'local_call_time_start'=>'00:00:00','local_call_time_end'=>'23:59:59']));
        Dialer::setCampaignAgents($c,$agents);
        Dialer::createDisposition($c,'SALE','Lab sale',true,true,false,0);
        Dialer::createDisposition($c,'RETRY','Lab retry',false,false,true,30);
        $list=Dialer::createList($c,'Synthetic variables only');
        $txt="id_contacto,nombre,numero,monto,local,direccion\n";
        for ($i=0;$i<10;$i++) $txt.="LAB$i,Cliente laboratorio $i,".(99000001+$i*100).",100.00,Laboratorio,Calle de prueba $i\n";
        labAssert(Dialer::importLeads($list,$c,Dialer::parseLeadsTxt($txt))['saved']===10,'Synthetic load failed.');
        foreach ($agents as $agent) Dialer::agentJoinQueue($agent,$c);
        labAssert(Dialer::availableAgentsCount($c,'WAITING')===10,'Ten agents not available.');
        Dialer::setEngineState($c,'RUNNING');
        if ($mode==='MANUAL') foreach ($agents as $agent) labAssert(Dialer::originateNext($c,$agent)!==null,'Manual call missing.');
        $peak=0; $live=[]; $stopped=false; $deadline=time()+110;
        do {
            usleep(250000);
            $q=$db->prepare('SELECT * FROM v2_dialer_call_log WHERE campaign_id=:c ORDER BY call_id'); $q->execute(['c'=>$c]); $calls=$q->fetchAll(PDO::FETCH_ASSOC);
            $peak=max($peak,count(array_filter($calls,static function($r) { return $r['ended_at']===null; })));
            if (count($calls)===10 && !$stopped) { Dialer::setEngineState($c,'STOPPED'); $stopped=true; }
            foreach ($agents as $agent) {
                $lead=Dialer::agentPanelState($agent)['lead'];
                if ($lead && $lead['bridged_at'] && !$lead['ended_at']) {
                    labAssert($lead['variables']['local']==='Laboratorio' && isset($lead['variables']['monto'],$lead['variables']['direccion'],$lead['variables']['id_contacto']),'Live screen pop lost variables.');
                    $live[$agent]=true;
                }
            }
            $done=count($calls)===10 && count(array_filter($calls,static function($r) { return $r['ended_at']===null; }))===0;
        } while (!$done && time()<$deadline);
        labAssert($done && count($live)===10,'Ten completed calls and live lead screens required.');
        labAssert($peak===10,'Ten simultaneous calls were not observed.');
        $seen=[];
        foreach ($calls as $i=>$call) {
            labAssert($call['termination_reason']==='COMPLETED' && $call['amd_result']==='HUMAN' && $call['duration_talk']>=15,'Bridge, AMD or duration failed: '.json_encode($call));
            labAssert(!isset($seen[$call['agent_user']]),'Same agent received simultaneous calls.'); $seen[$call['agent_user']]=true;
            $q=$db->prepare('SELECT status,called_count FROM v2_dialer_leads WHERE lead_id=:id'); $q->execute(['id'=>$call['lead_id']]); $lead=$q->fetch(PDO::FETCH_ASSOC);
            labAssert($lead['status']==='CONTACTED' && (int)$lead['called_count']===1,'Completed lead state incorrect.');
            labAssert(Dialer::recording((int)$call['call_id'])['size']>44,'Recording unreadable.');
            copy($call['recording_path'],$directory.'/'.basename($call['recording_path']));
            Dialer::setDisposition((int)$call['call_id'],$call['agent_user'],$i===0 ? 'RETRY' : 'SALE');
        }
        $q=$db->prepare("SELECT COUNT(*) FROM v2_dialer_leads WHERE list_id=:l AND status='NEW' AND next_eligible_at>NOW()+INTERVAL 29 MINUTE"); $q->execute(['l'=>$list]);
        labAssert((int)$q->fetchColumn()===1,'Retry lead state/delay incorrect.');
        $k=Dialer::kpis($c);
        labAssert($k['placed']===10 && $k['answered_human']===10 && $k['dropped']===0 && $k['sales']===9 && $k['in_progress']===0 && $k['occupancy_pct']!==null,'Reporter KPIs failed: '.json_encode($k));
        labAssert(count(Dialer::recordings($c,date('Y-m-d'),date('Y-m-d')))==10,'Recording search missing calls.');
        $result=['mode'=>$mode,'campaign_id'=>$c,'peak_in_flight'=>$peak,'connected_agents'=>count($seen),'live_variable_screens'=>count($live),'kpis'=>$k,'calls'=>array_map(static function($r) { return ['call_id'=>$r['call_id'],'agent'=>$r['agent_user'],'phone'=>$r['phone_number'],'recording'=>$r['recording_path']]; },$calls)];
        $results[]=$result; file_put_contents($directory.'/results.json',json_encode($results,JSON_PRETTY_PRINT));
        echo json_encode(['mode'=>$mode,'peak_in_flight'=>$peak,'agents'=>count($seen),'recordings'=>10,'sales'=>9,'retry'=>1,'occupancy'=>$k['occupancy_pct']]),"\n";
        foreach ($agents as $agent) Dialer::agentLeaveQueue($agent,$c);
    }
    $rtp=json_decode((string)file_get_contents($directory.'/rtp.json'),true);
    $agentStreams=array_filter($rtp,static function($r) { return $r['agent']!==null && $r['answered']; });
    labAssert(count($agentStreams)===30,'Expected thirty answered agent media streams.');
    foreach ($agentStreams as $r) labAssert($r['tx_packets']>500 && $r['rx_voice_packets']>50,'Missing bidirectional agent RTP.');
    foreach (array_filter($rtp,static function($r) { return $r['agent']===null && $r['answered']; }) as $r) labAssert($r['rx_voice_packets']>50,'Client did not receive agent audio.');
    $success=true; echo "PASS: three modes, ten agents, live variables, bidirectional RTP, states, retries, recordings and reports.\n";
} finally {
    foreach ($campaigns as $c) try { Dialer::setEngineState($c,'STOPPED'); } catch (Throwable $e) {}
    foreach (explode("\n",PbxAmi::command($ami,'core show channels concise')) as $line) {
        $channel=explode('!',preg_replace('/^Output: /','',$line))[0];
        if (strpos($channel,'SIP/dialer_ten_client-')===0) PbxAmi::rawAction($ami,'Hangup',['Channel'=>$channel]);
    }
    sleep(2);
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_file($directory.'/credentials.json')) unlink($directory.'/credentials.json');
    foreach ($campaigns as $c) try { Dialer::setCampaignAgents($c,[]); } catch (Throwable $e) {}
    foreach ($agents as $agent) Phones::delete($agent);
    if (Carriers::getById($carrier)) {
        $row=Carriers::getById($carrier);
        Carriers::update($carrier,array_replace($row,['active'=>'N']));
    }
    PbxAmi::command($ami,'sip reload');
    // Synthetic campaigns remain STOPPED for web verification, with no agents and an inactive loopback carrier.
    // Failure evidence is also retained; cleanup never targets a real campaign.
    file_put_contents($directory.'/status.json',json_encode(['success'=>$success,'campaign_ids'=>$campaigns]));
    echo "CLEAN: simulated SIP peers removed; synthetic STOPPED campaign history retained for web tests.\n";
}
