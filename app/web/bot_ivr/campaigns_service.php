<?php
declare(strict_types=1);

const BOT_CAMPAIGN_FIELDS = [
    'campaign_description'=>['label'=>'Descripción', 'type'=>'text', 'length'=>255],
    'user_group'=>['label'=>'Grupo administrativo', 'type'=>'text', 'length'=>20, 'required'=>true],
    'dial_method'=>['label'=>'Método de marcación', 'type'=>'select', 'options'=>['MANUAL','RATIO','ADAPT_HARD_LIMIT','ADAPT_TAPERED','ADAPT_AVERAGE']],
    'lead_order'=>['label'=>'Orden de leads', 'type'=>'select', 'options'=>['DOWN','UP','RANDOM']],
    'dial_statuses'=>['label'=>'Estados a marcar', 'type'=>'text', 'length'=>255, 'required'=>true],
    'hopper_level'=>['label'=>'Nivel mínimo de hopper', 'type'=>'number', 'min'=>0, 'max'=>1000000],
    'auto_dial_level'=>['label'=>'Nivel de marcación automática', 'type'=>'decimal', 'min'=>0, 'max'=>20],
    'dial_timeout'=>['label'=>'Tiempo de llamada (segundos)', 'type'=>'number', 'min'=>1, 'max'=>255],
    'dial_prefix'=>['label'=>'Prefijo de marcación', 'type'=>'text', 'length'=>20, 'digits'=>true],
    'campaign_cid'=>['label'=>'Caller ID de campaña', 'type'=>'text', 'length'=>20, 'digits'=>true],
    'campaign_recording'=>['label'=>'Grabación de llamadas', 'type'=>'select', 'options'=>['NEVER','ONDEMAND','ALLCALLS','ALLFORCE']],
    'max_channels'=>['label'=>'Canales máximos', 'type'=>'number', 'min'=>1, 'max'=>1000],
];

function bot_campaign_field_value(string $key, $value) {
    $field = BOT_CAMPAIGN_FIELDS[$key];
    if (!is_scalar($value)) throw new RuntimeException($field['label'] . ': valor inválido.');
    $value = trim((string)$value);
    $valid = true;
    if ($field['type'] === 'select') {
        $valid = in_array($value, $field['options'], true);
    } elseif ($field['type'] === 'number') {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$field['min'], 'max_range'=>$field['max']]]);
        $valid = $parsed !== false;
        if ($valid) $value = $parsed;
    } elseif ($field['type'] === 'decimal') {
        $valid = (bool)preg_match('/^\d+(?:\.\d{1,2})?$/', $value)
            && (float)$value >= $field['min'] && (float)$value <= $field['max'];
    } else {
        $valid = mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $field['length']
            && !preg_match('/[\x00-\x1f\x7f]/u', $value)
            && (empty($field['required']) || $value !== '');
        if (!empty($field['digits'])) $valid = $valid && (bool)preg_match('/^[+0-9*#]*$/', $value);
        if ($key === 'dial_statuses') {
            $value = strtoupper($value);
            $valid = $valid && (bool)preg_match('/^[A-Z0-9_]{1,6}(?:\s+[A-Z0-9_]{1,6})*$/', $value);
            if ($valid) $value = implode(' ', array_unique(preg_split('/\s+/', $value)));
        }
    }
    if (!$valid) throw new RuntimeException($field['label'] . ': valor inválido o fuera del rango permitido.');
    return $value;
}

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
    $existing = bot_campaign_get($db, $id);
    $name = bot_campaign_name((string)($input['name'] ?? ''));
    $start = bot_campaign_time((string)($input['start_time'] ?? ''));
    $end = bot_campaign_time((string)($input['end_time'] ?? ''));
    $scheduled = (string)($input['scheduled'] ?? '') === '1' ? 1 : 0;
    if ($scheduled && $start === $end) throw new RuntimeException('Activación y bloqueo deben tener horas distintas.');
    $values = [':name'=>$name, ':active'=>(string)($input['active'] ?? '') === '1' ? 1 : 0,
        ':scheduled'=>$scheduled, ':start'=>$start, ':end'=>$end, ':id'=>$id];
    $assignments = [];
    foreach (BOT_CAMPAIGN_FIELDS as $key=>$field) {
        // Older open forms must preserve metadata that they do not submit.
        $values[':'.$key] = bot_campaign_field_value($key, $input[$key] ?? $existing[$key]);
        $assignments[] = $key . '=:' . $key;
    }
    $stmt = $db->prepare('UPDATE zynervox_bot_campaigns SET name=:name,active=:active,scheduled=:scheduled,start_time=:start,end_time=:end,' . implode(',', $assignments) . ' WHERE campaign_id=:id');
    $stmt->execute($values);
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
