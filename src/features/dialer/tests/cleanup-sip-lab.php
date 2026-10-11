<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('DIALER_LAB_CONFIRM')!=='LOOPBACK_SIP') exit(1);
require '/srv/www/htdocs/zynervoxv2/includes/Dialer.php';
require '/srv/www/htdocs/zynervoxv2/includes/Carriers.php';
$db=Includes\Database::getCoreInstance();
$ami=Includes\PbxAmi::load();
if (strpos(Includes\PbxAmi::command($ami,'core show channels count'),'0 active channels')===false) throw new RuntimeException('Active calls; fixture recovery must wait.');
$q=$db->query("SELECT campaign_id FROM v2_dialer_campaigns WHERE carrier_id='DIALER_LAB_SIP' AND name IN ('LAB SIP MANUAL','LAB SIP PROGRESSIVE','LAB SIP ADAPTIVE')");
foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $db->prepare('DELETE FROM v2_dialer_call_log WHERE campaign_id=:c')->execute(['c'=>$id]);
    $db->prepare('DELETE d FROM v2_dialer_leads d JOIN v2_dialer_lists l ON l.list_id=d.list_id WHERE l.campaign_id=:c')->execute(['c'=>$id]);
    Includes\Dialer::deleteCampaign((int)$id);
}
$phone=Includes\Phones::getById('79996');
if ($phone && $phone['fullname']==='Dialer SIP lab') Includes\Phones::delete('79996');
$carrier=Includes\Carriers::getById('DIALER_LAB_SIP');
if ($carrier && $carrier['carrier_name']==='Loopback dialer laboratory') Includes\Carriers::delete('DIALER_LAB_SIP');
Includes\PbxAmi::command($ami,'sip reload');
Includes\PbxAmi::command($ami,'dialplan reload');
echo "CLEAN: orphaned lab-owned fixtures only.\n";
