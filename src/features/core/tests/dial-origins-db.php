<?php
require $argv[1].'/includes/Carriers.php';
use Includes\Carriers;
use Includes\Database;
function checkOriginDb($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$db = Database::getCoreInstance();
$ids = ['ORIGIN_TEST_'.bin2hex(random_bytes(4)), 'ORIGIN_TEST_'.bin2hex(random_bytes(4))];
$prefix = '009'.random_int(100000000,999999999);
try {
    $stmt=$db->prepare("INSERT INTO v2_carriers (carrier_id,carrier_name,account_entry,dialplan_entry,server_ip,carrier_description) VALUES (?, 'Origin test', '', ?, '127.0.0.1', '')");
    foreach($ids as $id) $stmt->execute([$id,"exten => _{$prefix}X.,1,Hangup()"]);
    Carriers::extractDialOrigin($ids[0],$prefix,'Fixture');
    Carriers::extractDialOrigin($ids[0],$prefix,'Renamed');
    $stmt=$db->prepare('SELECT COUNT(*) FROM v2_dial_origins WHERE dial_prefix=?'); $stmt->execute([$prefix]);
    checkOriginDb((int)$stmt->fetchColumn()===1,'no duplicate origin');
    checkOriginDb(in_array($prefix,array_column(Carriers::dialOrigins(),'dial_prefix'),true),'active origin exposed');
    try { Carriers::extractDialOrigin($ids[1],$prefix,'Other'); throw new RuntimeException('conflict accepted'); } catch(InvalidArgumentException $expected) {}
    try { Carriers::extractDialOrigin($ids[0],'123','Invalid'); throw new RuntimeException('absent prefix accepted'); } catch(InvalidArgumentException $expected) {}
    $db->prepare("UPDATE v2_carriers SET active='N' WHERE carrier_id=?")->execute([$ids[0]]);
    checkOriginDb(!in_array($prefix,array_column(Carriers::dialOrigins(),'dial_prefix'),true),'inactive origin unavailable');
    $db->prepare("UPDATE v2_carriers SET active='Y',dialplan_entry='' WHERE carrier_id=?")->execute([$ids[0]]);
    checkOriginDb(!in_array($prefix,array_column(Carriers::dialOrigins(),'dial_prefix'),true),'removed route unavailable');
    echo "PASS: origin extraction, rename, conflict, active and current-route validation\n";
} finally {
    foreach($ids as $id) $db->prepare('DELETE FROM v2_carriers WHERE carrier_id=?')->execute([$id]);
}
