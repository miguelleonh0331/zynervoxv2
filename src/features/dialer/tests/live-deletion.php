<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('DIALER_LAB_CONFIRM')!=='OWN_DELETE_FIXTURE') exit(1);
require '/srv/www/htdocs/zynervoxv2/includes/Dialer.php';
require '/srv/www/htdocs/zynervoxv2/includes/Carriers.php';
use Includes\Dialer;
use Includes\Database;
use Includes\PbxAmi;
$carrier=Includes\Carriers::getById('DIALER_TEN_SIP');
if (!$carrier || $carrier['active']!=='N' || $carrier['server_ip']!=='127.0.0.1') throw new RuntimeException('Inactive laboratory carrier required');
$ami=PbxAmi::load();
if (!preg_match('/(?:^|\n)(?:Output: )?0 active channels(?:\r?\n|$)/',PbxAmi::command($ami,'core show channels count'))) throw new RuntimeException('PBX must be idle');
$db=Database::getCoreInstance(); $before=$db->query('SELECT campaign_id FROM v2_dialer_campaigns')->fetchAll(PDO::FETCH_COLUMN);
$id=Dialer::createCampaign('LAB DELETE '.bin2hex(random_bytes(6)),'DIALER_TEN_SIP',false);
try {
    $row=Dialer::campaign($id); $input=['name'=>$row['name'],'active'=>'0'];
    foreach (Dialer::CAMPAIGN_FIELDS as $key=>$field) $input[$key]=$row[$key] ?? '';
    $input['dial_prefix']='798900'.$id; $input['outbound_prefix']='';
    Dialer::updateCampaign($id,$input); $dialplanId=Dialer::campaign($id)['dialplan_id'];
    $list=Dialer::createList($id,'Deletion fixture only');
    Dialer::importLeads($list,$id,Dialer::parseLeadsTxt("numero,nombre\n00000001,Synthetic deletion fixture\n"));
    $lead=Dialer::leadsPreview($list,1)[0]['lead_id'];
    $db->prepare("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,ended_at,termination_reason) VALUES (:c,:l,'00000001',NOW(),NOW(),'COMPLETED')")->execute(['c'=>$id,'l'=>$lead]);
    Dialer::deleteCampaign($id);
    foreach (['v2_dialer_campaigns','v2_dialer_lists','v2_dialer_call_log'] as $table) {
        $q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE campaign_id=:id'); $q->execute(['id'=>$id]);
        if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Deletion fixture retained: '.$table);
    }
    $q=$db->prepare('SELECT COUNT(*) FROM v2_dialplans WHERE dialplan_id=:id'); $q->execute(['id'=>$dialplanId]);
    if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Generated dialplan retained');
    $after=$db->query('SELECT campaign_id FROM v2_dialer_campaigns')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($before,$after)) throw new RuntimeException('Existing campaigns changed');
    echo 'PASS: own STOPPED synthetic campaign #'.$id.' with closed history deleted; real PBX reload and existing campaigns preserved.',"\n";
} finally {
    $q=$db->prepare('SELECT COUNT(*) FROM v2_dialer_campaigns WHERE campaign_id=:id'); $q->execute(['id'=>$id]);
    if ((int)$q->fetchColumn()>0) Dialer::deleteCampaign($id);
}
