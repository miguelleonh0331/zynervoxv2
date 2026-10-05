<?php
declare(strict_types=1);
require $argv[1].'/db.php';
require $argv[1].'/campaigns_service.php';
require $argv[1].'/list_service.php';
function list_verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$parsed = bot_list_parse_txt("\xEF\xBB\xBFnumero,Nombre,Monto,Dirección\r\n+51 999000001,María,125.50,Av. Lima 100\r\n51999000001,Duplicada,20,Otra\r\n,Sin teléfono,0,No\r\n999000002,Pedro,90,Jr. Uno 10\r\n");
list_verify(count($parsed['rows']) === 2 && $parsed['duplicates'] === 1 && $parsed['rejected'] === 1, 'Parse counters');
list_verify($parsed['rows'][0]['extra']['direccion'] === 'Av. Lima 100', 'Raw variables preserved');
foreach (["nombre,monto\nA,10", "numero,nombre,Nombre\n1,A,A", "numero,,nombre\n1,A,A", "numero,nombre\n1,\xFF"] as $bad) {
    try { bot_list_parse_txt($bad); throw new LogicException('Invalid file accepted'); }
    catch (RuntimeException $e) {}
}
$db = carsa_db();
$db->beginTransaction();
try {
    $campaign = bot_campaign_create($db, '__list_import_test__', false);
    $list = bot_campaign_list_create($db, $campaign, '__test_list__', false);
    $other = bot_campaign_create($db, '__other_import_test__', false);
    $result = bot_list_import($db, $list, $campaign, $parsed);
    list_verify($result['saved'] === 2 && $result['duplicates'] === 1 && $result['rejected'] === 1, 'Import counters');
    $again = bot_list_import($db, $list, $campaign, $parsed);
    list_verify($again['saved'] === 0 && $again['duplicates'] === 3, 'Reupload must not duplicate leads');
    $stmt = $db->prepare('SELECT customer_name,extra_json FROM zynervox_bot_list WHERE list_id=? AND phone=?');
    $stmt->execute([$list,'51999000001']);
    $row = $stmt->fetch();
    list_verify($row['customer_name'] === 'María' && json_decode($row['extra_json'], true)['monto'] === '125.50', 'Saved raw values');
    try { bot_list_import($db, $list, $other, $parsed); throw new LogicException('Cross-campaign upload accepted'); }
    catch (RuntimeException $e) {}
    try { bot_list_get($db, $list, $other); throw new LogicException('Cross-campaign list opened'); }
    catch (RuntimeException $e) {}
    $separate = bot_campaign_list_create($db, $other, '__separate_list__', false);
    $independent = bot_list_import($db, $separate, $other, $parsed);
    list_verify($independent['saved'] === 2, 'Phone deduplication must be scoped to list');
    echo "PASS: UTF8/BOM, headers, CSV parsing, counters, raw variables, reupload dedupe and list ownership\n";
} finally { $db->rollBack(); }
