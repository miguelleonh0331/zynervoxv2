<?php
declare(strict_types=1);
require __DIR__.'/reporting.php';
require $web.'/includes/Dialplans.php';
use Includes\Dialer;
use Includes\Dialplans;
$route=['dialplan_id'=>1,'name'=>'SIP route','dial_prefix'=>'0073','active'=>'Y','dialplan_entry'=>"exten => _0073X.,1,NoOp(Test)\n same => n,Dial(SIP/peer_one/881851\${EXTEN:4},60)\n same => n,Hangup()\n"];
verify(Dialplans::sipRoute($route)['outbound_prefix']==='881851','Lost carrier prefix');
foreach ([
    str_replace('${EXTEN:4}','${EXTEN:3}',$route['dialplan_entry']),
    str_replace('SIP/peer_one/','SIP/${DYNAMIC}/',$route['dialplan_entry']),
    $route['dialplan_entry']." same => n,Dial(SIP/another/\${EXTEN:4},60)\n",
    str_replace('NoOp(Test)','GotoIf(1?other)',$route['dialplan_entry']),
    str_replace('NoOp(Test)','Answer()',$route['dialplan_entry']),
    str_replace('${EXTEN:4}','${ZV2_PHONE}',$route['dialplan_entry']),
    str_replace('60)','60,U(custom-handler))',$route['dialplan_entry']),
] as $invalid) expectFailure(static function() use($route,$invalid) { Dialplans::sipRoute(array_replace($route,['dialplan_entry'=>$invalid])); },'Ambiguous/custom route accepted');
foreach (['v2_dialplans','v2_carriers','v2_dialer_lists','v2_dialer_dnc'] as $table) {
    $ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl);
    $ddl=preg_replace('/^  CONSTRAINT .* FOREIGN KEY .*\n/m','',$ddl);
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $db->exec($ddl);
}
$insert=$db->prepare('INSERT INTO v2_dialplans (dialplan_id,name,dial_prefix,dialplan_entry,active) VALUES (?,?,?,?,?)');
$insert->execute([1,$route['name'],$route['dial_prefix'],$route['dialplan_entry'],'Y']);
$two=array_replace($route,['dialplan_id'=>2,'name'=>'Second route','dial_prefix'=>'0074','dialplan_entry'=>str_replace(['0073','peer_one','881851'],['0074','peer_two','9911'],$route['dialplan_entry'])]);
$insert->execute([2,$two['name'],$two['dial_prefix'],$two['dialplan_entry'],'Y']);
$insert->execute([3,'Inactive','0075',str_replace('0073','0075',$route['dialplan_entry']),'N']);
$original=$db->query('SELECT * FROM v2_dialplans ORDER BY dialplan_id')->fetchAll(PDO::FETCH_ASSOC);
$id=Dialer::createCampaignFromDialplan('Selected route',1,true);
$row=Dialer::campaign($id);
verify($row['carrier_id']===null && (int)$row['selected_dialplan_id']===1 && (int)$row['dialplan_id']!==1,'Shared and owned IDs conflated');
verify(count(Dialer::availableDialplans())===2,'Callback/inactive leaked into dropdown');
$fields=new ReflectionMethod(Dialer::class,'routedFields'); $fields->setAccessible(true);
verify($fields->invoke(null,$row,['call_id'=>123,'phone_number'=>'000001'])['Channel']==='SIP/peer_one/881851000001','Selected dialplan route not used');
$input=['name'=>$row['name'],'active'=>'1','selected_dialplan_id'=>2];
foreach (Dialer::CAMPAIGN_FIELDS as $key=>$field) if (!in_array($key,['dial_prefix','outbound_prefix'],true)) $input[$key]=$row[$key];
Dialer::setEngineState($id,'RUNNING');
$stale=array_replace(Dialer::campaign($id),['selected_dialplan_id'=>2]);
verify(Dialer::claimLead($id,$row['dial_prefix'],1,null,true,$stale)===null,'Stale route snapshot admitted');
expectFailure(static function() use($id,$input) { Dialer::updateCampaign($id,$input); },'Running route changed');
verify((int)Dialer::campaign($id)['selected_dialplan_id']===1,'Rejected update persisted');
Dialer::setEngineState($id,'STOPPED');
$list=Dialer::createList($id,'Synthetic');
$db->prepare('INSERT INTO v2_dialer_leads (list_id,phone_number) VALUES (?,?)')->execute([$list,'000001']); $leadId=(int)$db->lastInsertId();
$db->prepare('INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at) VALUES (?,?,?,NOW())')->execute([$id,$leadId,'000001']);
expectFailure(static function() use($id,$input) { Dialer::updateCampaign($id,$input); },'Open call route changed');
$db->exec('UPDATE v2_dialer_call_log SET ended_at=NOW()');
Dialer::updateCampaign($id,$input);
Dialer::setEngineState($id,'RUNNING');
verify(Dialer::claimLead($id,$row['dial_prefix'],1,null,true,$row)===null,'Old route reserved after stop/change/start');
verify((int)$db->query('SELECT called_count FROM v2_dialer_leads WHERE lead_id='.$leadId)->fetchColumn()===0,'Stale route spent a lead attempt');
Dialer::setEngineState($id,'STOPPED');
verify($fields->invoke(null,Dialer::campaign($id),['call_id'=>123,'phone_number'=>'000001'])['Channel']==='SIP/peer_two/9911000001','Changed dialplan did not change carrier');
$db->exec("UPDATE v2_dialplans SET active='N' WHERE dialplan_id=2");
expectFailure(static function() use($id) { Dialer::setEngineState($id,'RUNNING'); },'Inactive route started');
$db->exec("UPDATE v2_dialplans SET active='Y' WHERE dialplan_id=2");
Dialer::deleteCampaign($id);
verify($db->query('SELECT * FROM v2_dialplans ORDER BY dialplan_id')->fetchAll(PDO::FETCH_ASSOC)===$original,'Shared dialplans changed or deleted');
echo "PASS: selected SIP route, number substitution, inactive/custom rejection, automatic private callback, shared ownership, stop/drain guard and carrier change.\n";
