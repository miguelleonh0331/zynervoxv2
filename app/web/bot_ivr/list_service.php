<?php
declare(strict_types=1);

const BOT_LIST_UPLOAD_BYTES = 10485760;
const BOT_LIST_UPLOAD_ROWS = 50000;

function bot_list_get(PDO $db, int $listId, int $campaignId): array {
    $stmt = $db->prepare('SELECT l.*,c.name campaign_name FROM zynervox_bot_lists l JOIN zynervox_bot_campaigns c ON c.campaign_id=l.campaign_id WHERE l.list_id=:list AND l.campaign_id=:campaign');
    $stmt->execute([':list'=>$listId, ':campaign'=>$campaignId]);
    $list = $stmt->fetch();
    if (!$list) throw new RuntimeException('La lista no existe o no pertenece a esta campaña.');
    return $list;
}

function bot_list_header(string $value): string {
    $value = mb_strtolower(trim($value, " \t\r\n\xEF\xBB\xBF"), 'UTF-8');
    $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return trim(preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '', '_');
}

function bot_list_parse_txt(string $contents): array {
    if (strlen($contents) > BOT_LIST_UPLOAD_BYTES) throw new RuntimeException('El archivo supera 10 MB.');
    if (!mb_check_encoding($contents, 'UTF-8')) throw new RuntimeException('Guarda el archivo en UTF-8 antes de cargarlo.');
    $stream = fopen('php://memory', 'r+');
    if ($stream === false) throw new RuntimeException('No se pudo leer el archivo.');
    try {
        fwrite($stream, $contents);
        rewind($stream);
        $raw = fgetcsv($stream, 0, ',', '"', '');
        $header = array_map('bot_list_header', $raw ?: []);
        if (count($header) < 2 || count($header) > 10) throw new RuntimeException('El TXT debe tener de 2 a 10 columnas separadas por comas.');
        if (in_array('', $header, true) || count(array_unique($header)) !== count($header)) throw new RuntimeException('Las cabeceras no pueden estar vacías ni repetidas.');
        foreach ($header as $key) if (strlen($key) > 60) throw new RuntimeException('Las cabeceras no pueden superar 60 caracteres.');
        $phoneIndex = array_search('numero', $header, true);
        if ($phoneIndex === false) throw new RuntimeException('Falta la columna obligatoria numero.');
        $nameIndex = false;
        foreach (['nombre','name','cliente','nombres'] as $alias) {
            $index = array_search($alias, $header, true);
            if ($index !== false) { $nameIndex = $index; break; }
        }
        $result = ['rows'=>[], 'duplicates'=>0, 'rejected'=>0, 'errors'=>[]];
        $seen = [];
        $record = 1;
        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $record++;
            if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
            if ($record > BOT_LIST_UPLOAD_ROWS + 1) throw new RuntimeException('El archivo supera 50000 registros. Divídelo en archivos más pequeños.');
            $reason = '';
            if (count($cells) > count($header)) $reason = 'cantidad de columnas incorrecta';
            $cells = array_pad($cells, count($header), '');
            $phone = preg_replace('/\D+/', '', (string)$cells[$phoneIndex]) ?? '';
            if ($phone === '' || strlen($phone) > 20) $reason = 'teléfono vacío o mayor de 20 dígitos';
            $name = $nameIndex === false ? '' : trim((string)$cells[$nameIndex]);
            if (mb_strlen($name, 'UTF-8') > 160) $reason = 'nombre mayor de 160 caracteres';
            $extra = [];
            foreach ($header as $index=>$key) {
                if ($index === $phoneIndex) continue;
                $value = trim((string)$cells[$index]);
                if (mb_strlen($value, 'UTF-8') > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', $value)) $reason = 'valor inválido o mayor de 1000 caracteres';
                $extra[$key] = $value;
            }
            if ($reason !== '') {
                $result['rejected']++;
                if (count($result['errors']) < 5) $result['errors'][] = 'Registro '.$record.': '.$reason;
                continue;
            }
            if (isset($seen[$phone])) { $result['duplicates']++; continue; }
            $seen[$phone] = true;
            $result['rows'][] = ['phone'=>$phone, 'customer_name'=>$name, 'extra'=>$extra];
        }
        return $result;
    } finally { fclose($stream); }
}

function bot_list_import(PDO $db, int $listId, int $campaignId, array $parsed): array {
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        // Serialize imports for the same list, preserving existing contacts.
        $lock = $db->prepare('SELECT list_id FROM zynervox_bot_lists WHERE list_id=:list AND campaign_id=:campaign FOR UPDATE');
        $lock->execute([':list'=>$listId, ':campaign'=>$campaignId]);
        if (!$lock->fetch()) throw new RuntimeException('La lista no pertenece a esta campaña.');
        $existing = [];
        foreach (array_chunk(array_column($parsed['rows'], 'phone'), 500) as $phones) {
            $stmt = $db->prepare('SELECT phone FROM zynervox_bot_list WHERE list_id=? AND phone IN ('.implode(',', array_fill(0, count($phones), '?')).')');
            $stmt->execute(array_merge([$listId], $phones));
            while (($phone = $stmt->fetchColumn()) !== false) $existing[(string)$phone] = true;
        }
        $saved = 0;
        $duplicates = (int)$parsed['duplicates'];
        foreach (array_chunk($parsed['rows'], 100) as $batch) {
            $values = [];
            $bindings = [];
            foreach ($batch as $row) {
                if (isset($existing[$row['phone']])) { $duplicates++; continue; }
                $existing[$row['phone']] = true;
                $values[] = '(?,?,?,?)';
                array_push($bindings, $listId, $row['phone'], $row['customer_name'], json_encode($row['extra'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
            if ($values) {
                $db->prepare('INSERT INTO zynervox_bot_list (list_id,phone,customer_name,extra_json) VALUES '.implode(',', $values))->execute($bindings);
                $saved += count($values);
            }
        }
        if ($ownsTransaction) $db->commit();
        return ['saved'=>$saved, 'duplicates'=>$duplicates, 'rejected'=>(int)$parsed['rejected'], 'errors'=>$parsed['errors']];
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
