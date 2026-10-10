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
$repository = \ZynervoxQueries\botIvrRepository(bot_ivr_db_config(), $db);
$db->beginTransaction();
try {
    $campaign = bot_campaign_create($repository, '__list_import_test__', false);
    $list = bot_campaign_list_create($repository, $campaign, '__test_list__', false);
    $other = bot_campaign_create($repository, '__other_import_test__', false);
    $result = bot_list_import($repository, $list, $campaign, $parsed);
    list_verify($result['saved'] === 2 && $result['duplicates'] === 1 && $result['rejected'] === 1, 'Import counters');
    $dial = $db->prepare('SELECT status,called_count,last_call_at,next_call_at FROM zynervox_bot_list WHERE list_id=?');
    $dial->execute([$list]);
    foreach ($dial->fetchAll() as $lead) {
        list_verify($lead['status'] === 'NEW' && (int)$lead['called_count'] === 0 && $lead['last_call_at'] === null && $lead['next_call_at'] === null, 'New import must initialize dialer fields');
    }
    $db->prepare("UPDATE zynervox_bot_list SET status='NA',called_count=2,last_call_at='2026-10-10 08:00:00',next_call_at='2026-10-11 08:00:00' WHERE list_id=?")->execute([$list]);
    $again = bot_list_import($repository, $list, $campaign, $parsed);
    list_verify($again['saved'] === 2 && $again['duplicates'] === 1 && $repository->leadCount($list) === 2, 'Reupload replaces without accumulating leads');
    $dial->execute([$list]);
    foreach ($dial->fetchAll() as $lead) {
        list_verify($lead['status'] === 'NEW' && (int)$lead['called_count'] === 0 && $lead['last_call_at'] === null && $lead['next_call_at'] === null, 'Replacement must reset dialer fields for new leads');
    }
    $stmt = $db->prepare('SELECT customer_name,extra_json FROM zynervox_bot_list WHERE list_id=? AND phone=?');
    $stmt->execute([$list,'51999000001']);
    $row = $stmt->fetch();
    list_verify($row['customer_name'] === 'María' && json_decode($row['extra_json'], true)['monto'] === '125.50', 'Saved raw values');
    try { bot_list_import($repository, $list, $other, $parsed); throw new LogicException('Cross-campaign upload accepted'); }
    catch (RuntimeException $e) {}
    try { bot_list_get($repository, $list, $other); throw new LogicException('Cross-campaign list opened'); }
    catch (RuntimeException $e) {}
    $separate = bot_campaign_list_create($repository, $other, '__separate_list__', false);
    $independent = bot_list_import($repository, $separate, $other, $parsed);
    list_verify($independent['saved'] === 2, 'Phone deduplication must be scoped to list');
    $replacement = bot_list_parse_txt("numero,nombre\n999000002,Actualizado\n999000003,Nuevo\n");
    bot_list_import($repository, $list, $campaign, $replacement);
    $stmt = $db->prepare('SELECT phone,customer_name FROM zynervox_bot_list WHERE list_id=? ORDER BY phone');
    $stmt->execute([$list]);
    $expected = $stmt->fetchAll();
    list_verify($expected === [['phone'=>'999000002','customer_name'=>'Actualizado'],['phone'=>'999000003','customer_name'=>'Nuevo']], 'Old contacts removed, retained phone updated, new contact inserted');
    list_verify($repository->leadCount($separate) === 2, 'Other list changed');
    foreach ([bot_list_parse_txt("numero,nombre\n"), bot_list_parse_txt("numero,nombre\n,Inválido\n")] as $empty) {
        try { bot_list_import($repository, $list, $campaign, $empty); throw new LogicException('Empty replacement accepted'); }
        catch (RuntimeException $e) {}
    }
    $bad = $replacement;
    $bad['rows'][0]['extra'] = ['invalid'=>"\xFF"];
    try { bot_list_import($repository, $list, $campaign, $bad); throw new LogicException('Invalid JSON accepted'); }
    catch (JsonException $e) {}
    $stmt->execute([$list]);
    list_verify($stmt->fetchAll() === $expected && $db->inTransaction(), 'Failed replacement must restore contacts and preserve caller transaction');
    echo "PASS: parsing, replacement, reupload, empty file preservation, failed replacement savepoint rollback, ownership and other-list isolation\n";
} finally { $db->rollBack(); }
