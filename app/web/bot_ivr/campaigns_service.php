<?php
declare(strict_types=1);

function bot_campaign_name(string $value): string {
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > 120 || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
        throw new RuntimeException('El nombre debe tener entre 1 y 120 caracteres, sin caracteres de control.');
    }
    return $value;
}

function bot_campaign_time(string $value): string {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
        throw new RuntimeException('El horario debe tener formato HH:MM.');
    }
    return $value . ':00';
}

function bot_campaign_create(PDO $db, string $name, bool $active): int {
    $stmt = $db->prepare('INSERT INTO zynervox_bot_campaigns (name,active) VALUES (:name,:active)');
    $stmt->execute([':name'=>bot_campaign_name($name), ':active'=>(int)$active]);
    return (int)$db->lastInsertId();
}

function bot_campaign_get(PDO $db, int $id): array {
    $stmt = $db->prepare('SELECT * FROM zynervox_bot_campaigns WHERE campaign_id=:id');
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Campaña inexistente.');
    return $row;
}

function bot_campaign_update(PDO $db, int $id, array $input): void {
    bot_campaign_get($db, $id);
    $name = bot_campaign_name((string)($input['name'] ?? ''));
    $start = bot_campaign_time((string)($input['start_time'] ?? ''));
    $end = bot_campaign_time((string)($input['end_time'] ?? ''));
    $scheduled = (string)($input['scheduled'] ?? '') === '1' ? 1 : 0;
    if ($scheduled && $start === $end) throw new RuntimeException('Activación y bloqueo deben tener horas distintas.');
    $stmt = $db->prepare('UPDATE zynervox_bot_campaigns SET name=:name,active=:active,scheduled=:scheduled,start_time=:start,end_time=:end WHERE campaign_id=:id');
    $stmt->execute([':name'=>$name, ':active'=>(string)($input['active'] ?? '') === '1' ? 1 : 0,
        ':scheduled'=>$scheduled, ':start'=>$start, ':end'=>$end, ':id'=>$id]);
}

function bot_campaign_list_create(PDO $db, int $campaignId, string $name, bool $active): int {
    bot_campaign_get($db, $campaignId);
    $stmt = $db->prepare('INSERT INTO zynervox_bot_lists (campaign_id,name,active) VALUES (:campaign,:name,:active)');
    $stmt->execute([':campaign'=>$campaignId, ':name'=>bot_campaign_name($name), ':active'=>(int)$active]);
    return (int)$db->lastInsertId();
}

function bot_campaign_list_update(PDO $db, int $campaignId, int $listId, string $name, bool $active): void {
    $owned = $db->prepare('SELECT list_id FROM zynervox_bot_lists WHERE list_id=:list AND campaign_id=:campaign');
    $owned->execute([':list'=>$listId, ':campaign'=>$campaignId]);
    if (!$owned->fetch()) throw new RuntimeException('La lista no pertenece a esta campaña.');
    $stmt = $db->prepare('UPDATE zynervox_bot_lists SET name=:name,active=:active WHERE list_id=:list AND campaign_id=:campaign');
    $stmt->execute([':name'=>bot_campaign_name($name), ':active'=>(int)$active, ':list'=>$listId, ':campaign'=>$campaignId]);
}
