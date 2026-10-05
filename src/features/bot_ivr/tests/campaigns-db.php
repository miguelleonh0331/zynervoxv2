<?php
declare(strict_types=1);
// php campaigns-db.php /path/to/web/bot_ivr (test data always rolled back).
require $argv[1] . '/db.php';
require $argv[1] . '/campaigns_service.php';
function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$db = carsa_db();
$db->beginTransaction();
try {
    $id = bot_campaign_create($db, '__test_campaign__', false);
    verify($id > 0, 'ID must be generated');
    $campaign = bot_campaign_get($db, $id);
    verify((int)$campaign['active'] === 0, 'Inactive creation');
    $stmt = $db->prepare('SELECT COUNT(*) FROM zynervox_bot_lists WHERE campaign_id=?');
    $stmt->execute([$id]);
    verify((int)$stmt->fetchColumn() === 0, 'Creation must not add lists');
    bot_campaign_update($db, $id, ['name'=>'Horario nocturno','active'=>'1','scheduled'=>'1','start_time'=>'22:00','end_time'=>'06:00']);
    $campaign = bot_campaign_get($db, $id);
    verify($campaign['start_time'] === '22:00:00' && $campaign['end_time'] === '06:00:00', 'Schedule persistence');
    try { bot_campaign_time('25:00'); throw new LogicException('Invalid time accepted'); }
    catch (RuntimeException $e) {}
    try { bot_campaign_name(' '); throw new LogicException('Empty name accepted'); }
    catch (RuntimeException $e) {}
    bot_campaign_update($db, $id, ['name'=>'Desactivada','active'=>'0','scheduled'=>'0','start_time'=>'08:00','end_time'=>'17:45']);
    $disabled = bot_campaign_get($db, $id);
    verify((int)$disabled['active'] === 0 && (int)$disabled['scheduled'] === 0, 'Select No must disable flags');
    $list = bot_campaign_list_create($db, $id, 'Lista de prueba', true);
    $other = bot_campaign_create($db, '__other_campaign__', true);
    try { bot_campaign_list_update($db, $other, $list, 'Incorrecto', false); throw new LogicException('Cross-campaign edit accepted'); }
    catch (RuntimeException $e) {}
    bot_campaign_list_update($db, $id, $list, 'Actualizada', false);
    $stmt = $db->prepare('SELECT name,active FROM zynervox_bot_lists WHERE list_id=?');
    $stmt->execute([$list]);
    $row = $stmt->fetch();
    verify($row['name'] === 'Actualizada' && (int)$row['active'] === 0, 'List update');
    try {
        $db->prepare('DELETE FROM zynervox_bot_campaigns WHERE campaign_id=?')->execute([$id]);
        throw new LogicException('Campaign with lists deleted');
    } catch (PDOException $e) { verify($e->getCode() === '23000', 'Unexpected delete error'); }
    $stmt = $db->prepare('INSERT INTO zynervox_bot_list(list_id,phone,extra_json) VALUES (?,?,?)');
    $stmt->execute([$list,'999000001','{"nombre":"Prueba"}']);
    try { $stmt->execute([PHP_INT_MAX,'999000002','{}']); throw new LogicException('Orphan lead accepted'); }
    catch (PDOException $e) { verify($e->getCode() === '23000', 'Unexpected FK error'); }
    echo "PASS: campaign ID/active, empty creation, schedule, validation, list ownership/update, foreign keys\n";
} finally {
    $db->rollBack();
}
