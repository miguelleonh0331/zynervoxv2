<?php
declare(strict_types=1);
// Migrado desde modules/initial_survey/campaigns.php (mirmidon/CARSA).
// Este es ahora el "index" real de Bot IVR: crear y activar campañas
// (el viejo index.php de prueba manual unitaria quedó descartado).
// NOTA rutas: bot_ivr/ vive UN nivel bajo la raiz de Zynervox (el original
// vivia DOS niveles bajo la raiz de CARSA) -- todas las referencias
// relativas se ajustaron a esa profundidad. Nada de rutas absolutas nuevas.
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/page.php';
require __DIR__ . '/campaign_runtime.php';
initial_survey_require_login();

// Raiz web. Los datos operativos viven en MariaDB y el código del motor futuro
// vivirá bajo /etc/asterisk/synervox/modules/bot_ivr/.
$baseDir = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

/* ---------- helpers de limpieza / normalización (alineados con index.php) ---------- */
function camp_phone9(string $v): string {
    $d = preg_replace('/\D+/', '', $v) ?? '';
    if (strlen($d) === 11 && substr($d, 0, 2) === '51') $d = substr($d, 2);
    if (strlen($d) > 9) $d = substr($d, -9);
    return strlen($d) === 9 ? $d : '';
}
function camp_test_phone(string $v): string {
    $digits = preg_replace('/\D+/', '', trim($v)) ?? '';
    if (strlen($digits) < 6 || strlen($digits) > 15) {
        throw new RuntimeException('El número debe contener entre 6 y 15 dígitos.');
    }
    return $digits;
}
function camp_require_test01_editable($db, int $campaignId): array {
    $stmt = $db->prepare('SELECT id, name, status FROM synervox_campaigns WHERE id=:id');
    $stmt->execute([':id'=>$campaignId]);
    $campaign = $stmt->fetch();
    if (!$campaign || (string) $campaign['name'] !== 'test01') {
        throw new RuntimeException('El CRUD está habilitado únicamente para test01.');
    }
    if ((string) $campaign['status'] === 'running') {
        throw new RuntimeException('No se pueden editar clientes mientras la campaña está ejecutándose.');
    }
    return $campaign;
}
function camp_name(string $v): string {
    $v = preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', trim($v)) ?? '';
    $v = preg_replace('/\s+/', ' ', $v) ?? $v;
    return mb_substr(trim($v), 0, 80, 'UTF-8');
}
function camp_amount(string $v): string {
    $v = preg_replace('/[^\d\.\,]/', '', trim($v)) ?? '';
    return mb_substr($v, 0, 20, 'UTF-8');
}
function camp_free(string $v, int $limit = 120): string {
    $v = preg_replace('/[^\p{L}\p{N}\s\.\,\-\/#]/u', '', trim($v)) ?? '';
    $v = preg_replace('/\s+/', ' ', $v) ?? $v;
    return mb_substr(trim($v), 0, $limit, 'UTF-8');
}
/** Valida "HH:MM" y devuelve "HH:MM:00"; si no calza, devuelve el default. */
function camp_time(string $v, string $default): string {
    $v = trim($v);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? ($v . ':00') : $default;
}
/* ---------- cabecera flexible (2026-08-05, ver UPDATE.MD) ----------
   Acepta archivos con 2 a 10 columnas y nombres de cabecera libres.
   La UNICA columna obligatoria es 'numero' (telefono a marcar).        */
const CAMP_MIN_COLS = 2;
const CAMP_MAX_COLS = 10;

/** Alias de cabecera -> columna legacy de carsa_initial_survey. */
const CAMP_ALIAS_NAME    = ['nombre', 'name', 'cliente', 'nombres'];
const CAMP_ALIAS_AMOUNT  = ['monto', 'amount', 'importe', 'deuda', 'saldo', 'precio', 'total', 'cuota', 'valor'];
const CAMP_ALIAS_ADDRESS = ['direccion', 'address', 'tienda', 'domicilio', 'local', 'sucursal', 'agencia'];

/** Devuelve el indice de la primera columna respetando la prioridad de alias. */
function camp_find_col(array $header, array $aliases) {
    foreach ($aliases as $alias) {
        $i = array_search($alias, $header, true);
        if ($i !== false) return $i;
    }
    return false;
}

/** Convierte una cabecera libre en identificador seguro para usar como {variable}. */
function camp_slug(string $v): string {
    $v = trim($v, " \t\n\r\0\x0B\xEF\xBB\xBF");
    $v = mb_strtolower($v, 'UTF-8');
    $from = ['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'];
    $to   = ['a','e','i','o','u','u','n','a','e','i','o','u','u','n'];
    $v = str_replace($from, $to, $v);
    $v = preg_replace('/[^a-z0-9_]+/', '_', $v) ?? '';
    $v = preg_replace('/_+/', '_', $v) ?? $v;
    return trim($v, '_');
}

function camp_normalize_rows(string $baseDir, array $rows): array {
    if (!$rows) return [];
    $script = '/etc/asterisk/synervox/modules/bot_ivr/normalize_tts.py';
    $cmd = '/usr/bin/python3 ' . escapeshellarg($script);
    $pipes = [];
    $proc = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('No se pudo iniciar normalizador TTS.');
    fwrite($pipes[0], json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = trim((string) stream_get_contents($pipes[2])); fclose($pipes[2]);
    $code = proc_close($proc);
    $norm = json_decode((string) $out, true);
    if ($code !== 0 || !is_array($norm)) throw new RuntimeException($err !== '' ? $err : 'Normalización TTS fallida.');
    return $norm;
}

/**
 * Procesa un TXT de clientes y lo inserta en carsa_initial_survey para
 * $campaignId. Reutilizado por "Crear campaña" (carga opcional en el mismo
 * paso) y por "Cargar base" (agregar clientes a una campaña YA creada, sin
 * tener que eliminarla y rehacerla) (2026-09-06).
 * Devuelve ['saved'=>int,'rejected'=>int,'duplicates'=>int].
 */
function camp_upload_clients_txt($db, string $baseDir, int $campaignId, array $upl): array {
    if (strtolower((string) pathinfo((string) $upl['name'], PATHINFO_EXTENSION)) !== 'txt') {
        throw new RuntimeException('El archivo debe ser .txt');
    }
    $contents = file_get_contents((string) $upl['tmp_name']);
    if ($contents === false) throw new RuntimeException('No se pudo leer el archivo.');
    if (!mb_check_encoding($contents, 'UTF-8')) {
        throw new RuntimeException(
            'El archivo no está codificado en UTF-8. Vuelve a guardarlo como UTF-8 ' .
            '(en Excel: "Guardar como" → "CSV UTF-8", y renombra la extensión a .txt) ' .
            'y súbelo de nuevo. Descarga la plantilla para partir de un archivo correcto.'
        );
    }
    unset($contents);
    $h = fopen((string) $upl['tmp_name'], 'rb');
    if ($h === false) throw new RuntimeException('No se pudo leer el archivo.');

    /* --- Cabecera flexible: 2 a 10 columnas, nombres libres, 'numero' obligatoria --- */
    $rawHeader = fgetcsv($h);
    $header = array_map(static fn($x) => camp_slug((string) $x), $rawHeader ?: []);
    $header = array_values(array_filter($header, static fn($x) => $x !== ''));

    $colCount = count($header);
    if ($colCount < CAMP_MIN_COLS || $colCount > CAMP_MAX_COLS) {
        fclose($h);
        throw new RuntimeException(
            'El archivo debe tener entre ' . CAMP_MIN_COLS . ' y ' . CAMP_MAX_COLS .
            ' columnas. Se detectaron: ' . $colCount . '.'
        );
    }
    if (count(array_unique($header)) !== $colCount) {
        fclose($h);
        throw new RuntimeException('Hay cabeceras duplicadas. Cada columna debe tener un nombre distinto.');
    }
    $phoneIdx = array_search('numero', $header, true);
    if ($phoneIdx === false) {
        fclose($h);
        throw new RuntimeException(
            'Falta la columna obligatoria "numero". Cabeceras detectadas: ' . implode(', ', $header)
        );
    }

    // Indices de las columnas que ademas alimentan las columnas legacy.
    $idxName    = camp_find_col($header, CAMP_ALIAS_NAME);
    $idxAmount  = camp_find_col($header, CAMP_ALIAS_AMOUNT);
    $idxAddress = camp_find_col($header, CAMP_ALIAS_ADDRESS);

    $rows = [];
    $seenPhones = [];
    $rejected = 0;
    $duplicates = 0;
    $lineNo = 1;
    while (($r = fgetcsv($h)) !== false) {
        $lineNo++;
        if (count($r) === 1 && trim((string) $r[0]) === '') continue;   // linea vacia
        if (count($r) > $colCount) { $rejected++; continue; }           // fila corrupta / desalineada
        $r = array_pad($r, $colCount, '');                             // faltantes -> vacio

        $phone = camp_phone9((string) $r[$phoneIdx]);
        if ($phone === '') { $rejected++; continue; }                   // sin telefono no se puede marcar

        // Duplicados: se conserva la PRIMERA aparicion dentro del archivo.
        if (isset($seenPhones[$phone])) {
            $duplicates++;
            continue;
        }
        $seenPhones[$phone] = $lineNo;

        // Todas las columnas menos 'numero' quedan disponibles como {variable}.
        $extraRaw = [];
        foreach ($header as $i => $slug) {
            if ($i === $phoneIdx) continue;
            $extraRaw[$slug] = camp_free((string) $r[$i], 200);
        }

        $rows[] = [
            'phone'             => $phone,
            'customer_name'     => $idxName    !== false ? camp_name((string) $r[$idxName])       : '',
            'amount_raw'        => $idxAmount  !== false ? camp_amount((string) $r[$idxAmount])   : '',
            'store_address_raw' => $idxAddress !== false ? camp_free((string) $r[$idxAddress])    : '',
            'extra_raw'         => $extraRaw,
        ];
    }
    fclose($h);
    $rows = camp_normalize_rows($baseDir, $rows);

    $batchId = 'camp_' . $campaignId . '_' . date('Ymd_His');
    $ins = $db->prepare(
        'INSERT INTO carsa_initial_survey
            (batch_id, campaign_id, phone, customer_name, amount_raw, amount, store_address_raw, store_address, extra_json, status)
         VALUES (:b, :c, :p, :n, :ar, :a, :sr, :s, :ej, "pending")'
    );
    $saved = 0;
    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            // extra_json guarda los valores YA normalizados para TTS.
            $extra = $row['extra'] ?? $row['extra_raw'] ?? [];
            $ins->execute([
                ':b'=>$batchId, ':c'=>$campaignId,
                ':p'=>$row['phone'], ':n'=>$row['customer_name'],
                ':ar'=>$row['amount_raw'], ':a'=>$row['amount'] ?? $row['amount_raw'],
                ':sr'=>$row['store_address_raw'], ':s'=>$row['store_address'] ?? $row['store_address_raw'],
                ':ej'=>json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $saved++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    if ($saved > 0) {
        $db->prepare('UPDATE synervox_campaigns SET status="loaded" WHERE id=:id')->execute([':id'=>$campaignId]);
    }
    return ['saved' => $saved, 'rejected' => $rejected, 'duplicates' => $duplicates];
}

/* ---------- acciones POST ---------- */
$message = '';
$error = '';
if (isset($_SESSION['bot_ivr_flash_message'])) {
    $message = (string) $_SESSION['bot_ivr_flash_message'];
    $error = (string) ($_SESSION['bot_ivr_flash_error'] ?? '');
    unset($_SESSION['bot_ivr_flash_message'], $_SESSION['bot_ivr_flash_error']);
}
$action = (string) ($_POST['action'] ?? '');

if (empty($_SESSION['bot_ivr_db_csrf'])) $_SESSION['bot_ivr_db_csrf'] = bin2hex(random_bytes(32));
$dbConfig = ['database' => 'zynervox', 'port' => '3306'];
try { $dbConfig = bot_ivr_db_config(); }
catch (Throwable $e) { $error = $e->getMessage(); }
$dbConfig['database'] = 'zynervox';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_db_config') {
    try {
        if (!hash_equals($_SESSION['bot_ivr_db_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sesión inválida. Recarga la página.');
        }
        $candidate = [];
        foreach (['server', 'port', 'database', 'user'] as $key) $candidate[$key] = trim((string) ($_POST['db_' . $key] ?? ''));
        $candidate['password'] = (string) ($_POST['db_password'] ?? '');
        if ($candidate['password'] === '') $candidate['password'] = (string) ($dbConfig['password'] ?? '');
        bot_ivr_db_save($candidate);
        $_SESSION['bot_ivr_flash_message'] = 'Conexión verificada y configuración guardada.';
        header('Location: index.php');
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_test01_client') {
    try {
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $phone = camp_test_phone((string) ($_POST['phone'] ?? ''));
        $db = carsa_db();
        camp_require_test01_editable($db, $campaignId);
        $dup = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE campaign_id=:cid AND phone=:phone');
        $dup->execute([':cid'=>$campaignId, ':phone'=>$phone]);
        if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('Ese número ya existe en test01.');
        $stmt = $db->prepare(
             'INSERT INTO carsa_initial_survey
             (batch_id,campaign_id,phone,customer_name,amount_raw,amount,store_address_raw,store_address,status,audio_status,extra_json)
             VALUES (:batch,:cid,:phone,:name,:amount_raw,:amount_value,:address_raw,:address_value,"pending","ready","{}")'
        );
        $amount = camp_amount((string) ($_POST['amount'] ?? ''));
        $address = camp_free((string) ($_POST['store_address'] ?? ''), 255);
        $stmt->execute([
            ':batch'=>'camp_'.$campaignId.'_manual', ':cid'=>$campaignId, ':phone'=>$phone,
            ':name'=>camp_name((string) ($_POST['customer_name'] ?? '')),
            ':amount_raw'=>$amount, ':amount_value'=>$amount,
            ':address_raw'=>$address, ':address_value'=>$address,
        ]);
        $message = 'Cliente agregado a test01.';
    } catch (Throwable $e) { $error = 'No se pudo agregar: ' . $e->getMessage(); }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_test01_client') {
    try {
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $phone = camp_test_phone((string) ($_POST['phone'] ?? ''));
        $db = carsa_db();
        camp_require_test01_editable($db, $campaignId);
        $owned = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE campaign_id=:cid AND id=:id');
        $owned->execute([':cid'=>$campaignId, ':id'=>$queueId]);
        if ((int) $owned->fetchColumn() !== 1) throw new RuntimeException('Cliente inexistente.');
        $dup = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE campaign_id=:cid AND phone=:phone AND id<>:id');
        $dup->execute([':cid'=>$campaignId, ':phone'=>$phone, ':id'=>$queueId]);
        if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('Ese número ya existe en test01.');
        $stmt = $db->prepare(
            'UPDATE carsa_initial_survey
             SET phone=:phone,customer_name=:name,amount_raw=:amount_raw,amount=:amount_value,
                 store_address_raw=:address_raw,store_address=:address_value
             WHERE id=:id AND campaign_id=:cid'
        );
        $amount = camp_amount((string) ($_POST['amount'] ?? ''));
        $address = camp_free((string) ($_POST['store_address'] ?? ''), 255);
        $stmt->execute([
            ':phone'=>$phone, ':name'=>camp_name((string) ($_POST['customer_name'] ?? '')),
            ':amount_raw'=>$amount, ':amount_value'=>$amount,
            ':address_raw'=>$address, ':address_value'=>$address,
            ':id'=>$queueId, ':cid'=>$campaignId,
        ]);
        $message = 'Cliente actualizado en test01.';
    } catch (Throwable $e) { $error = 'No se pudo actualizar: ' . $e->getMessage(); }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_test01_client') {
    try {
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $db = carsa_db();
        camp_require_test01_editable($db, $campaignId);
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM ivr_call_results WHERE campaign_id=:cid AND queue_id=:qid')->execute([':cid'=>$campaignId, ':qid'=>$queueId]);
            $stmt = $db->prepare('DELETE FROM carsa_initial_survey WHERE id=:qid AND campaign_id=:cid');
            $stmt->execute([':qid'=>$queueId, ':cid'=>$campaignId]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Cliente inexistente.');
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        $message = 'Cliente eliminado de test01.';
    } catch (Throwable $e) { $error = 'No se pudo eliminar el cliente: ' . $e->getMessage(); }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_campaign') {
    try {
        $name = camp_name((string) ($_POST['name'] ?? ''));
        $flowCode = (string) ($_POST['flow_code'] ?? '');
        if ($name === '') throw new RuntimeException('Nombre de campaña requerido.');
        if (!preg_match('/^\d{2}$/', $flowCode)) throw new RuntimeException('Selecciona un flujo válido.');
        $scheduled = isset($_POST['scheduled']) ? 1 : 0;
        $startTime = camp_time((string) ($_POST['start_time'] ?? ''), '08:00:00');
        $endTime = camp_time((string) ($_POST['end_time'] ?? ''), '17:45:00');

        $db = carsa_db();
        $stmt = $db->prepare('INSERT INTO synervox_campaigns (name, flow_code, status, scheduled, start_time, end_time) VALUES (:n, :f, :s, :sch, :st, :et)');
        $stmt->execute([':n' => $name, ':f' => $flowCode, ':s' => 'draft', ':sch' => $scheduled, ':st' => $startTime, ':et' => $endTime]);
        $campaignId = (int) $db->lastInsertId();

        // carga opcional del TXT en el mismo paso
        $saved = 0; $rejected = 0; $duplicates = 0;
        if (($_FILES['clients_txt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $result = camp_upload_clients_txt($db, $baseDir, $campaignId, $_FILES['clients_txt']);
            $saved = $result['saved']; $rejected = $result['rejected']; $duplicates = $result['duplicates'];
        }
        $message = "Campaña #{$campaignId} creada (flujo {$flowCode}). Clientes cargados: {$saved}. Rechazados: {$rejected}."
            . ($duplicates > 0 ? " Duplicados descartados (se conservó la primera aparición): {$duplicates}." : "");
    } catch (Throwable $e) {
        $error = 'No se pudo crear la campaña: ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_campaign_clients') {
    // Cargar base a una campaña YA creada (2026-09-06): evita tener que
    // eliminarla y volver a crearla solo para subirle el TXT de clientes.
    try {
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) throw new RuntimeException('Campaña inválida.');
        $db = carsa_db();
        $check = $db->prepare('SELECT id, status FROM synervox_campaigns WHERE id=:id');
        $check->execute([':id' => $campaignId]);
        $campaign = $check->fetch();
        if (!$campaign) throw new RuntimeException('Campaña inexistente.');
        if ((string) $campaign['status'] === 'running') {
            throw new RuntimeException('No se puede cargar una base mientras la campaña está ejecutándose.');
        }
        if (($_FILES['clients_txt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Selecciona un archivo .txt.');
        }
        $result = camp_upload_clients_txt($db, $baseDir, $campaignId, $_FILES['clients_txt']);
        $message = "Base cargada en la campaña #{$campaignId}. Clientes cargados: {$result['saved']}. Rechazados: {$result['rejected']}."
            . ($result['duplicates'] > 0 ? " Duplicados descartados (se conservó la primera aparición): {$result['duplicates']}." : "");
    } catch (Throwable $e) {
        $error = 'No se pudo cargar la base: ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reactivate_test01') {
    try {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Campaña inválida.');

        $db = carsa_db();
        if(campaign_process_active($db,$id,'dialer')) throw new RuntimeException('La campaña ya se está ejecutando.');
        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT id, name FROM synervox_campaigns WHERE id=:id FOR UPDATE');
            $check->execute([':id'=>$id]);
            $campaign = $check->fetch();
            if (!$campaign || (string) $campaign['name'] !== 'test01') {
                throw new RuntimeException('La reactivación está habilitada únicamente para test01.');
            }
            $active = $db->prepare(
                'SELECT COUNT(*) FROM carsa_initial_survey
                 WHERE campaign_id=:id AND status IN ("pending","calling","reserved")'
            );
            $active->execute([':id'=>$id]);
            if ((int) $active->fetchColumn() > 0) {
                throw new RuntimeException('La campaña ya tiene clientes pendientes o activos.');
            }
            $clone = $db->prepare(
                'INSERT INTO carsa_initial_survey
                    (batch_id,phone,customer_name,amount_raw,amount,store_address_raw,store_address,
                     status,last_error,audio_status,audio_name,tts_provider,audio_error,dial_number,
                     assigned_agent,reserved_at,started_at,completed_at,campaign_id,extra_json)
                 SELECT
                    CONCAT(LEFT(q.batch_id,50),"_retry_",DATE_FORMAT(NOW(),"%Y%m%d%H%i%s")),
                    q.phone,q.customer_name,q.amount_raw,q.amount,q.store_address_raw,q.store_address,
                    "pending",NULL,q.audio_status,q.audio_name,q.tts_provider,q.audio_error,NULL,
                    NULL,NULL,NULL,NULL,q.campaign_id,q.extra_json
                 FROM carsa_initial_survey q
                 INNER JOIN (
                    SELECT phone,MAX(id) AS latest_id
                    FROM carsa_initial_survey
                    WHERE campaign_id=:latest_campaign
                    GROUP BY phone
                 ) latest ON latest.latest_id=q.id
                 WHERE q.campaign_id=:source_campaign'
            );
            $clone->execute([':latest_campaign'=>$id, ':source_campaign'=>$id]);
            $cloned = $clone->rowCount();
            if ($cloned <= 0) throw new RuntimeException('La campaña no tiene clientes para reactivar.');
            $db->prepare('UPDATE synervox_campaigns SET status="loaded" WHERE id=:id')->execute([':id'=>$id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $message = "Campaña test01 #{$id} reactivada: {$cloned} cliente(s) quedaron pendientes. Pulsa Play para iniciar las llamadas.";
    } catch (Throwable $e) {
        $error = 'No se pudo reactivar test01: ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'purge_campaign') {
    try {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Campaña inválida.');
        $db = carsa_db();
        if (campaign_process_active($db, $id, 'dialer')) {
            throw new RuntimeException('No se puede purgar una campaña mientras está ejecutándose.');
        }
        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT id FROM synervox_campaigns WHERE id=:id FOR UPDATE');
            $check->execute([':id'=>$id]);
            if (!$check->fetchColumn()) throw new RuntimeException('Campaña inexistente.');
            $deleted = $db->prepare('DELETE FROM carsa_initial_survey WHERE campaign_id=:id');
            $deleted->execute([':id'=>$id]);
            $db->prepare('UPDATE synervox_campaigns SET status="draft" WHERE id=:id')->execute([':id'=>$id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $message = "Carga purgada de la campaña #{$id}: {$deleted->rowCount()} cliente(s) eliminados. La campaña se conservó.";
    } catch (Throwable $e) {
        $error = 'No se pudo purgar la carga: ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_campaign') {
    try {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Campaña inválida.');
        $db = carsa_db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM ivr_call_results WHERE campaign_id=:id')->execute([':id'=>$id]);
            $db->prepare('DELETE FROM carsa_initial_survey WHERE campaign_id=:id')->execute([':id'=>$id]);
            $db->prepare('DELETE FROM carsa_campaign_state_summary WHERE campaign_id=:id')->execute([':id'=>$id]);
            $db->prepare('DELETE FROM synervox_campaigns WHERE id=:id')->execute([':id'=>$id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $message = "Campaña #{$id} eliminada junto con sus clientes y resultados.";
    } catch (Throwable $e) {
        $error = 'No se pudo eliminar: ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_schedule') {
    try {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Campaña inválida.');
        $scheduled = isset($_POST['scheduled']) ? 1 : 0;
        $startTime = camp_time((string) ($_POST['start_time'] ?? ''), '08:00:00');
        $endTime = camp_time((string) ($_POST['end_time'] ?? ''), '17:45:00');
        $db = carsa_db();
        $stmt = $db->prepare('UPDATE synervox_campaigns SET scheduled=:sch, start_time=:st, end_time=:et WHERE id=:id');
        $stmt->execute([':sch' => $scheduled, ':st' => $startTime, ':et' => $endTime, ':id' => $id]);
        $message = "Horario de la campaña #{$id} actualizado.";
    } catch (Throwable $e) {
        $error = 'No se pudo actualizar el horario: ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'retry_failed_global') {
    try {
        $db = carsa_db();
        $stmt = $db->prepare(
            "UPDATE carsa_initial_survey
             SET status='pending', started_at=NULL, completed_at=NULL, assigned_agent=NULL
             WHERE status='failed'"
        );
        $stmt->execute();
        $count = $stmt->rowCount();
        $message = $count > 0
            ? "Reintentar fallidos: {$count} contacto(s) puestos de nuevo en pending (en todas las campañas)."
            : "No había contactos en failed para reintentar.";
    } catch (Throwable $e) {
        $error = 'No se pudo reintentar los fallidos: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['bot_ivr_flash_message'] = $message;
    $_SESSION['bot_ivr_flash_error'] = $error;
    header('Location: index.php');
    exit;
}

/* ---------- helpers de estado visual para la Consola (campaign_console.php) ---------- */
function campaign_visual_state(array $campaign): string {
    $run = (string) ($campaign['campaign_status'] ?? 'idle');
    $audio = (string) ($campaign['audio_status'] ?? 'pending');
    if ($run === 'completed') return 'completed';
    if ($run === 'running' || $run === 'stopping') return 'running';
    if ($audio === 'building') return 'building';
    if ($audio === 'ready') return 'ready';
    if ($audio === 'error') return 'error';
    return 'pending';
}
function campaign_state_marker(string $state): string {
    return ['building'=>'🔵','ready'=>'🟡','running'=>'🟠','completed'=>'🟢','error'=>'🔴'][$state] ?? '⚪';
}

/* ---------- lectura: lista de campañas con conteo ---------- */
$campaigns = [];
$test01Clients = [];
$deepgramPoolWorkers = 0;
try {
    $db = carsa_db();
    $campaigns = $db->query(
        'SELECT c.id, c.name, c.flow_code, c.status, c.created_at,
                c.scheduled, c.start_time, c.end_time,
                (SELECT COUNT(*) FROM carsa_initial_survey q WHERE q.campaign_id = c.id) AS clientes,
                COALESCE(s.audio_status,"pending") AS audio_status,
                COALESCE(s.campaign_status,"idle") AS campaign_status
         FROM synervox_campaigns c
         LEFT JOIN carsa_campaign_state_summary s ON s.campaign_id = c.id
         ORDER BY c.id DESC'
    )->fetchAll();
    try {
        $deepgramPoolWorkers = (int) $db->query(
            "SELECT COUNT(DISTINCT SHA2(TRIM(api_key), 256))
             FROM deepgram_profiles
             WHERE active=1 AND TRIM(api_key)<>''"
        )->fetchColumn();
    } catch (Throwable $e) { $deepgramPoolWorkers = 0; }
    $test01Clients = $db->query(
        'SELECT q.id,q.campaign_id,q.phone,q.customer_name,q.amount_raw,q.store_address_raw,q.status
         FROM carsa_initial_survey q
         INNER JOIN synervox_campaigns c ON c.id=q.campaign_id
         INNER JOIN (
            SELECT campaign_id,phone,MAX(id) AS latest_id
            FROM carsa_initial_survey
            GROUP BY campaign_id,phone
         ) latest ON latest.latest_id=q.id
         WHERE c.name="test01"
         ORDER BY q.id'
    )->fetchAll();
} catch (Throwable $e) {
    $error = $error ?: ('No se pudo leer campañas: ' . $e->getMessage());
}

initial_survey_page_start('Bot IVR', 'Crear y administrar campañas de discado');
?>
<style>
.cmp-grid{display:grid;gap:12px}
.cmp-table-wrap{overflow-x:auto}
.cmp-table{width:100%;border-collapse:collapse;font-size:.75rem;margin-top:.5rem}
.cmp-table th{background:var(--dark);color:#fff;text-align:left;padding:5px 8px;border:1px solid var(--dark);text-transform:uppercase;font-size:.7rem}
.cmp-table td{padding:4px 8px;border:1px solid var(--border);color:var(--text)}
.cmp-table tbody tr:nth-child(even){background:var(--glass)}
.cmp-schedule-form{display:inline-flex;gap:8px;align-items:center;flex-wrap:nowrap;white-space:nowrap;margin:0}
.cmp-schedule-times{display:inline-flex;align-items:center;gap:5px}
.cmp-schedule-times span{color:var(--text-muted);font-size:.7rem}
.cmp-badge{display:inline-block;padding:2px 8px;border-radius:2px;font-size:.66rem;font-weight:700}
.cmp-draft{background:var(--glass);color:var(--text-muted)}
.cmp-loaded{background:var(--glass);color:#047857}
.cmp-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.cmp-btn-danger{background:#FEF2F2!important;border-color:#ef4444!important;color:#b91c1c!important}
.cmp-btn-danger:hover{background:#FEE2E2!important}
.cmp-modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(45,47,59,.5);padding:28px;overflow:auto}
.cmp-modal.open{display:flex;align-items:flex-start;justify-content:center}
.cmp-modal-box{width:min(1100px,100%);background:var(--bg-card);border:1px solid var(--border);border-radius:2px;padding:18px}
.cmp-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.cmp-client-grid{display:grid;grid-template-columns:1.1fr 1.2fr .8fr 1.5fr auto auto auto;gap:7px;align-items:center;margin-bottom:7px}
.cmp-client-grid input{width:100%;min-width:0}
.cmp-client-head{color:var(--text-muted);font-size:.66rem;font-weight:700;text-transform:uppercase}
@media(max-width:900px){.cmp-client-head{display:none}.cmp-client-grid{grid-template-columns:1fr}.cmp-client-grid form{display:contents}}

/* ---------- pestañas (Campañas / Consola) ---------- */
.cc-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border)}
.cc-tab-btn{background:transparent;border:none;border-bottom:2px solid transparent;padding:10px 18px;font-size:.78rem;font-weight:700;color:var(--text-muted);cursor:pointer;text-transform:uppercase;letter-spacing:.4px;font-family:inherit}
.cc-tab-btn.active{color:var(--primary);border-bottom-color:var(--primary)}
.tab-pane{display:none}
.tab-pane.active{display:block}

/* ---------- Consola de campaña (migrado de campaign_console.php) ---------- */
.cc-controls{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:6px}
.cc-field label{display:block;font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.cc-field select{background:var(--bg-card);border:1px solid var(--border);border-radius:2px;color:var(--text);padding:8px 10px;font-size:.8rem;min-width:180px}
.cc-pool-workers{min-width:180px;padding:8px 10px;border:1px solid var(--border);border-radius:2px;background:var(--glass);color:var(--text-muted);font-size:.78rem}
.cc-legend{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:7px;color:var(--text-muted);font-size:.65rem;line-height:1}
.cc-legend span{white-space:nowrap}
.cc-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:2px;padding:9px 16px;font-size:.76rem;font-weight:700;cursor:pointer;background:var(--bg-card);color:var(--text);font-family:inherit}
.cc-btn:disabled{opacity:.4;cursor:not-allowed}
.cc-play{background:var(--primary);border-color:var(--primary);color:#fff}
.cc-stop{background:#B91C1C;border-color:#B91C1C;color:#fff}
.cc-generate{background:var(--dark);border-color:var(--dark);color:#fff}
.cc-running{display:inline-flex;align-items:center;gap:6px;font-size:.74rem;font-weight:700;padding:5px 10px;border-radius:2px}
.cc-on{background:#DCFCE7;color:#047857}
.cc-off{background:var(--glass);color:var(--text-muted)}
.cc-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
.cc-on .cc-dot{animation:cc-pulse 1.1s infinite}
@keyframes cc-pulse{0%,100%{opacity:1}50%{opacity:.25}}
.cc-audio{margin-top:12px;padding:10px 12px;border:1px solid var(--border);border-radius:2px;background:var(--glass)}
.cc-audio-line{display:flex;justify-content:space-between;gap:12px;color:var(--text-muted);font-size:.74rem;margin-bottom:6px}
.cc-progress{height:8px;background:var(--border);border-radius:2px;overflow:hidden}
.cc-progress>span{display:block;height:100%;width:0;background:var(--primary);transition:width .25s}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-top:14px}
.stat-tile{background:var(--glass);border:1px solid var(--border);border-radius:2px;padding:14px;text-align:center}
.stat-num{font-size:1.8rem;font-weight:800;line-height:1;margin-bottom:6px;color:var(--dark)}
.stat-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)}
.live-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px}
.live-title{font-size:1rem;font-weight:800;color:var(--dark)}
.live-meta{font-size:.74rem;color:var(--text-muted)}
.live-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:12px}
.live-box{padding:10px 12px;border-radius:2px;background:var(--glass);border:1px solid var(--border)}
.live-box strong{display:block;color:var(--dark);font-size:.95rem;margin-top:3px}
.live-label{font-size:.62rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)}
.result-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.result-chip{padding:4px 9px;border-radius:2px;background:var(--glass);color:var(--dark);font-size:.7rem;border:1px solid var(--border)}
.current-call{margin-top:10px;font-size:.76rem;color:var(--primary);min-height:1.2em}
</style>

<?php if ($message !== ''): ?><div class="carsa-msg"><?php echo h($message); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="carsa-err"><?php echo h($error); ?></div><?php endif; ?>

<div class="carsa-actions" style="margin-bottom:12px">
  <button type="button" class="carsa-btn secondary" onclick="document.getElementById('dbConfigModal').classList.add('open')">Configurar conexión a base de datos</button>
</div>
<div class="cc-tabs">
  <button type="button" class="cc-tab-btn active" data-tab="campanas" onclick="showBotIvrTab('campanas')">Campañas</button>
  <button type="button" class="cc-tab-btn" data-tab="consola" onclick="showBotIvrTab('consola')">Consola</button>
</div>

<div id="tab-campanas" class="tab-pane active">

<div class="carsa-card" style="margin-bottom:18px">
  <h2>Crear campaña</h2>
  <form method="post" enctype="multipart/form-data" class="carsa-form" autocomplete="off">
    <input type="hidden" name="action" value="create_campaign">
    <div class="carsa-field">
      <label>Nombre de la campaña</label>
      <input name="name" placeholder="Préstamos junio - Lima" required>
    </div>
    <div class="carsa-field">
      <label>Flujo IVR asignado</label>
      <select name="flow_code" id="flow_code" required>
        <option value="">Cargando flujos…</option>
      </select>
    </div>
    <div class="carsa-field">
      <label>Base de clientes (TXT) — opcional ahora</label>
      <input type="file" name="clients_txt" accept=".txt,text/plain">
    </div>
    <div class="carsa-field">
      <label><input type="checkbox" name="scheduled" value="1" style="width:auto;margin-right:6px"> Programado (inicia y termina solo, todos los días)</label>
    </div>
    <div class="carsa-field">
      <label>Hora de inicio</label>
      <input type="time" name="start_time" value="08:00">
    </div>
    <div class="carsa-field">
      <label>Hora de fin</label>
      <input type="time" name="end_time" value="17:45">
    </div>
    <div class="timeline-item">
      <strong>Cabecera del TXT — formato libre</strong>
      <span>
        De 2 a 10 columnas con los nombres que traiga el cliente.
        La única obligatoria es <code>numero</code> (teléfono a marcar).
        Cada cabecera queda disponible en el flujo como <code>{cabecera}</code>.<br>
        Ej: <code>numero,nombre,monto,direccion</code> · <code>numero,cliente,deuda</code> · <code>numero,nombres,saldo,asesor,codigo</code><br>
        Se leen como monto: <code>monto, amount, importe, deuda, saldo, precio, total, cuota, valor</code>.
        Como dirección: <code>direccion, address, tienda, domicilio, local, sucursal, agencia</code>.
        El resto se envía tal cual.<br>
        El archivo debe estar guardado en <strong>UTF-8</strong>.
        Cada teléfono debe aparecer una sola vez; si hay duplicados se conserva la primera aparición y el resto se descarta automáticamente.
      </span>
    </div>
    <div class="carsa-actions">
      <button class="carsa-btn">Crear campaña</button>
      <a class="carsa-btn secondary" href="plantilla_carga.php" download>Descargar plantilla</a>
      <a class="carsa-btn secondary" href="../ivr_builder/index.php">Abrir IVR Builder</a>
    </div>
  </form>
</div>

<div class="carsa-card">
  <h2>Mantenimiento</h2>
  <p class="muted" style="margin:0 0 10px">
    Pone en <code>pending</code> a todos los contactos marcados <code>failed</code>, en <strong>todas las campañas</strong> (no solo la que estés viendo). No borra ni clona nada: el historial de intentos anteriores queda igual. Úsalo para que vuelvan a discarse solos en la próxima corrida de su campaña.
  </p>
  <form method="post" onsubmit="return confirm('¿Reintentar TODOS los contactos fallidos, en TODAS las campañas? Volverán a quedar pendientes para discarse de nuevo.')" style="margin:0">
    <input type="hidden" name="action" value="retry_failed_global">
    <button class="carsa-btn">Reintentar fallidos</button>
  </form>
</div>

<div class="carsa-card">
  <h2>Campañas</h2>
  <?php if ($campaigns): ?>
    <div class="cmp-table-wrap">
    <table class="cmp-table">
      <thead>
        <tr><th>ID</th><th>Nombre</th><th>Flujo</th><th>Clientes</th><th>Estado</th><th>Creada</th><th>Horario</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($campaigns as $c): ?>
          <tr>
            <td><?php echo (int) $c['id']; ?></td>
            <td><?php echo h($c['name']); ?></td>
            <td><code><?php echo h($c['flow_code']); ?></code></td>
            <td><?php echo (int) $c['clientes']; ?></td>
            <td><span class="cmp-badge <?php echo $c['status'] === 'loaded' ? 'cmp-loaded' : 'cmp-draft'; ?>"><?php echo h($c['status']); ?></span></td>
            <td><?php echo h($c['created_at']); ?></td>
            <td>
              <form method="post" class="cmp-schedule-form">
                <input type="hidden" name="action" value="update_schedule">
                <input type="hidden" name="campaign_id" value="<?php echo (int) $c['id']; ?>">
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:.72rem;color:var(--text-muted)">
                  <input type="checkbox" name="scheduled" value="1" style="width:auto" <?php echo ((int) $c['scheduled'] === 1) ? 'checked' : ''; ?>>
                </label>
                <span class="cmp-schedule-times">
                  <input type="time" name="start_time" value="<?php echo h(substr((string) $c['start_time'], 0, 5)); ?>" style="width:88px;padding:4px 6px;font-size:.72rem">
                  <span>–</span>
                  <input type="time" name="end_time" value="<?php echo h(substr((string) $c['end_time'], 0, 5)); ?>" style="width:88px;padding:4px 6px;font-size:.72rem">
                </span>
                <button class="carsa-btn secondary" style="font-size:.68rem;padding:4px 8px">Guardar</button>
              </form>
            </td>
            <td><div class="cmp-actions">
              <a class="carsa-btn" href="campaign_edit.php?id=<?php echo (int) $c['id']; ?>" style="font-size:.72rem;padding:5px 10px">Abrir</a>
              <?php if ((string) $c['name'] === 'test01'): ?>
              <form method="post" onsubmit="return confirm('¿Reactivar test01? Se crearán nuevos intentos pendientes; las llamadas iniciarán solo al pulsar Play.')" style="margin:0">
                <input type="hidden" name="action" value="reactivate_test01">
                <input type="hidden" name="campaign_id" value="<?php echo (int) $c['id']; ?>">
                <button class="carsa-btn" style="font-size:.72rem;padding:5px 10px">Reactivar</button>
              </form>
              <button type="button" class="carsa-btn secondary" onclick="openClients(<?php echo (int) $c['id']; ?>)" style="font-size:.72rem;padding:5px 10px">Clientes</button>
              <?php endif; ?>
               <button type="button" class="carsa-btn secondary" onclick="openUpload(<?php echo (int) $c['id']; ?>)" style="font-size:.72rem;padding:5px 10px">Cargar base</button>
              <form method="post" onsubmit="return confirm('¿Purgar toda la carga de base de la campaña #<?php echo (int)$c['id']; ?>? La campaña se conservará, pero todos sus clientes cargados serán eliminados.')" style="margin:0">
                <input type="hidden" name="action" value="purge_campaign">
                <input type="hidden" name="campaign_id" value="<?php echo (int) $c['id']; ?>">
                <button class="carsa-btn secondary" style="font-size:.72rem;padding:5px 10px">Purgar</button>
              </form>
              <form method="post" onsubmit="return confirm('¿Eliminar campaña #<?php echo (int)$c['id']; ?>? Los clientes se conservan.')" style="margin:0">
                <input type="hidden" name="action" value="delete_campaign">
                <input type="hidden" name="campaign_id" value="<?php echo (int) $c['id']; ?>">
                <button class="carsa-btn cmp-btn-danger" style="font-size:.72rem;padding:5px 10px">Eliminar</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php else: ?>
    <p class="muted">Aún no hay campañas. Crea la primera arriba.</p>
  <?php endif; ?>
</div>

</div>

<div id="tab-consola" class="tab-pane">

<div class="carsa-card" style="margin-bottom:18px">
  <h2>Consola de campaña</h2>
  <div class="cc-controls">
    <div class="cc-field">
      <label>Campaña</label>
      <select id="cc-campaign" onchange="selectCampaign()">
        <option value="">Selecciona…</option>
        <?php foreach ($campaigns as $c): ?>
          <?php $visualState = campaign_visual_state($c); $baseLabel = $c['id'] . ' · ' . $c['name'] . ' (flujo ' . $c['flow_code'] . ')'; ?>
          <option value="<?php echo (int) $c['id']; ?>"
                  data-base-label="<?php echo h($baseLabel); ?>"
                  data-audio-status="<?php echo h((string) ($c['audio_status'] ?? 'pending')); ?>"
                  data-campaign-status="<?php echo h((string) ($c['campaign_status'] ?? 'idle')); ?>"><?php echo h(campaign_state_marker($visualState) . ' ' . $baseLabel); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="cc-legend"><span>🔵 audios</span><span>🟡 lista</span><span>🟠 en Play</span><span>🟢 finalizada</span><span>🔴 error</span></div>
    </div>
    <div class="cc-field">
      <label>Llamadas simultáneas</label>
      <select id="cc-max" onchange="poll()">
        <option value="" selected>Selecciona…</option>
        <option value="0">Todos los anexos libres</option>
        <option value="1">1</option><option value="2">2</option><option value="3">3</option>
        <option value="5">5</option><option value="8">8</option><option value="12">12</option><option value="20">20</option>
        <option value="30">30</option><option value="40">40</option><option value="50">50</option><option value="60">60</option>
        <option value="80">80</option>
      </select>
    </div>
    <div class="cc-field">
      <label>Proveedor TTS</label>
      <select id="tts_provider" onchange="saveProvider()">
        <option value="macelioai">gTTS (gratis, voz básica)</option>
        <option value="macelioai_remote" selected>gTTS remoto · zynerdesk (evita bloqueo 429)</option>
        <option value="deepgram">Deepgram · Celeste</option>
        <option value="deepgram_pool">Deepgram Pool · Celeste (<?php echo (int) $deepgramPoolWorkers; ?> API keys)</option>
        <option value="voicescloning">voicescloning · Nelly (peruana)</option>
        <option value="deepgram_pod">Deepgram · Celeste (RunPod, más barato)</option>
      </select>
    </div>
    <div class="cc-field">
      <label>Origen de llamada</label>
      <select id="cc-origin" onchange="saveOrigin()">
        <option value="sipp_2006">Estándar · 2006</option>
        <option value="zypad_3006">Zypad · 3006</option>
        <option value="zypad_whisper_4006">Zypad Whisper · 4006</option>
      </select>
    </div>
    <div class="cc-field">
      <label>Velocidad generación</label>
      <select id="cc-audio-workers">
        <option value="1">1x</option>
        <option value="3">3x</option>
        <option value="10">10x</option>
        <option value="25" selected>25x</option>
        <option value="60">60x</option>
        <option value="100">100x</option>
      </select>
      <div class="cc-pool-workers" id="cc-pool-workers" style="display:none"><?php echo (int) $deepgramPoolWorkers; ?> workers automáticos</div>
    </div>
    <button class="cc-btn cc-generate" id="cc-generate" onclick="generateAudios()" disabled>⚙ Generar audios</button>
    <button class="cc-btn cc-play" id="cc-play" onclick="playCampaign()" disabled>▶ Play</button>
    <button class="cc-btn cc-stop" id="cc-stop" onclick="stopCampaign()" disabled>⏹ Stop</button>
    <button class="cc-btn" id="cc-apply-channels" onclick="applyChannels()" disabled title="Cambia los canales de la campaña YA corriendo (sin relanzar, sin cortar llamadas en curso)">🔧 Aplicar canales</button>
    <span class="cc-running cc-off" id="cc-state"><span class="cc-dot"></span><span id="cc-state-txt">Detenido</span></span>
  </div>
  <div id="cc-msg" style="font-size:.78rem;color:var(--primary);margin-top:8px;min-height:1.2em"></div>
  <div class="cc-audio" id="cc-audio" style="display:none">
    <div class="cc-audio-line"><span id="cc-audio-text">Audios pendientes</span><strong id="cc-audio-pct">0%</strong></div>
    <div class="cc-progress"><span id="cc-audio-bar"></span></div>
  </div>
</div>

<div class="carsa-card" style="margin-bottom:18px">
  <h2 style="margin:0">Estadísticas en tiempo real</h2>
  <div class="stat-grid">
    <div class="stat-tile"><div class="stat-num" id="st-cargados">0</div><div class="stat-lbl">Cargados</div></div>
    <div class="stat-tile"><div class="stat-num" id="st-pendiente">0</div><div class="stat-lbl">Pendiente</div></div>
    <div class="stat-tile"><div class="stat-num" id="st-llamando">0</div><div class="stat-lbl">Llamando</div></div>
    <div class="stat-tile"><div class="stat-num" id="st-contestado">0</div><div class="stat-lbl">Procesado</div></div>
    <div class="stat-tile"><div class="stat-num" id="st-terminado">0</div><div class="stat-lbl">Terminado</div></div>
  </div>
</div>

<div class="carsa-card">
  <div class="live-head">
    <div><div class="live-title">Avance de la campaña</div><div class="live-meta" id="live-summary">Selecciona una campaña.</div></div>
    <strong id="live-percent" style="font-size:1.3rem;color:var(--primary)">0%</strong>
  </div>
  <div class="cc-progress"><span id="live-bar"></span></div>
  <div class="live-grid">
    <div class="live-box"><span class="live-label">Procesados</span><strong id="live-processed">0 / 0</strong></div>
    <div class="live-box"><span class="live-label">Promedio por llamada</span><strong id="live-average">—</strong></div>
    <div class="live-box"><span class="live-label">Tiempo estimado</span><strong id="live-eta">—</strong></div>
    <div class="live-box"><span class="live-label">Resultados recibidos</span><strong id="live-results-count">0</strong></div>
  </div>
  <div class="current-call" id="live-current"></div>
  <div class="result-chips" id="live-result-chips"></div>
  <h3 style="font-size:.8rem;color:var(--text-muted);margin:14px 0 4px">Últimos resultados</h3>
  <table class="cmp-table">
    <thead><tr><th>Hora</th><th>Teléfono</th><th>Cliente</th><th>Resultado</th></tr></thead>
    <tbody id="live-recent"><tr><td colspan="4" class="muted">Sin resultados.</td></tr></tbody>
  </table>
</div>

</div>

<div id="dbConfigModal" class="cmp-modal" onclick="if(event.target===this)closeDbConfig()">
  <div class="cmp-modal-box" style="width:min(480px,100%)" role="dialog" aria-modal="true" aria-labelledby="dbConfigTitle">
    <div class="cmp-modal-head">
      <h2 id="dbConfigTitle">Conexión a base de datos</h2>
      <button type="button" class="carsa-btn secondary" onclick="closeDbConfig()">Cerrar</button>
    </div>
    <form method="post" class="carsa-form" autocomplete="off">
      <input type="hidden" name="action" value="save_db_config">
      <input type="hidden" name="csrf" value="<?php echo h($_SESSION['bot_ivr_db_csrf']); ?>">
      <?php foreach (['server'=>'Servidor', 'port'=>'Puerto', 'database'=>'Base de datos', 'user'=>'Usuario'] as $key=>$label): ?>
      <div class="carsa-field">
        <label for="db_<?php echo h($key); ?>"><?php echo h($label); ?></label>
        <input id="db_<?php echo h($key); ?>" name="db_<?php echo h($key); ?>" value="<?php echo h($dbConfig[$key] ?? ''); ?>" required <?php echo $key === 'database' ? 'readonly' : ''; ?> <?php echo $key === 'port' ? 'type="number" min="1" max="65535"' : 'type="text"'; ?>>
      </div>
      <?php endforeach; ?>
      <div class="carsa-field">
        <label for="db_password">Contraseña</label>
        <input id="db_password" name="db_password" type="password" autocomplete="new-password" placeholder="Vacío: conservar contraseña actual">
      </div>
      <button class="carsa-btn">Probar y guardar</button>
    </form>
  </div>
</div>

<div id="clientsModal" class="cmp-modal" onclick="if(event.target===this)closeClients()">
  <div class="cmp-modal-box">
    <div class="cmp-modal-head">
      <div><h2 style="margin:0">Clientes de test01</h2><span class="muted" id="clientsCampaignLabel"></span></div>
      <button type="button" class="carsa-btn secondary" onclick="closeClients()">Cerrar</button>
    </div>

    <div class="cmp-client-grid cmp-client-head">
      <span>Número</span><span>Nombre</span><span>Monto</span><span>Dirección</span><span>Estado</span><span>Acciones</span>
    </div>
    <?php foreach ($test01Clients as $client): ?>
    <div class="cmp-client-grid cmp-client-row" data-campaign-id="<?php echo (int) $client['campaign_id']; ?>">
      <form method="post" style="display:contents">
        <input type="hidden" name="action" value="update_test01_client">
        <input type="hidden" name="campaign_id" value="<?php echo (int) $client['campaign_id']; ?>">
        <input type="hidden" name="queue_id" value="<?php echo (int) $client['id']; ?>">
        <input name="phone" inputmode="numeric" value="<?php echo h($client['phone']); ?>" required>
        <input name="customer_name" value="<?php echo h($client['customer_name']); ?>">
        <input name="amount" value="<?php echo h((string) $client['amount_raw']); ?>">
        <input name="store_address" value="<?php echo h((string) $client['store_address_raw']); ?>">
        <span class="cmp-badge cmp-draft"><?php echo h($client['status']); ?></span>
        <button class="carsa-btn" style="font-size:.72rem;padding:6px 10px">Guardar</button>
      </form>
      <form method="post" onsubmit="return confirm('¿Eliminar este cliente de test01?')" style="margin:0">
        <input type="hidden" name="action" value="delete_test01_client">
        <input type="hidden" name="campaign_id" value="<?php echo (int) $client['campaign_id']; ?>">
        <input type="hidden" name="queue_id" value="<?php echo (int) $client['id']; ?>">
        <button class="carsa-btn secondary" style="font-size:.72rem;padding:6px 10px">Eliminar</button>
      </form>
    </div>
    <?php endforeach; ?>

    <h3 style="margin:18px 0 8px">Agregar cliente</h3>
    <form method="post" class="cmp-client-grid">
      <input type="hidden" name="action" value="add_test01_client">
      <input type="hidden" name="campaign_id" id="addClientCampaignId" value="">
      <input name="phone" inputmode="numeric" placeholder="Número fijo o móvil" required>
      <input name="customer_name" placeholder="Nombre">
      <input name="amount" placeholder="Monto">
      <input name="store_address" placeholder="Dirección">
      <span></span>
      <button class="carsa-btn" style="font-size:.72rem;padding:6px 10px">Agregar</button>
    </form>
  </div>
</div>

<div id="uploadModal" class="cmp-modal" onclick="if(event.target===this)closeUpload()">
  <div class="cmp-modal-box" style="width:min(480px,100%)">
    <div class="cmp-modal-head">
      <div><h2 style="margin:0">Cargar base</h2><span class="muted" id="uploadCampaignLabel"></span></div>
      <button type="button" class="carsa-btn secondary" onclick="closeUpload()">Cerrar</button>
    </div>
    <form method="post" enctype="multipart/form-data" class="carsa-form">
      <input type="hidden" name="action" value="upload_campaign_clients">
      <input type="hidden" name="campaign_id" id="uploadCampaignId" value="">
      <div class="carsa-field">
        <label>Archivo de clientes (TXT)</label>
        <input type="file" name="clients_txt" accept=".txt,text/plain" required>
      </div>
      <span class="muted" style="font-size:.72rem">Se agrega a los clientes que ya tenga la campaña. Duplicados dentro del archivo: se conserva la primera aparición.</span>
      <div class="carsa-actions">
        <button class="carsa-btn">Cargar</button>
        <a class="carsa-btn secondary" href="plantilla_carga.php" download>Descargar plantilla</a>
      </div>
    </form>
  </div>
</div>

<script>
function closeDbConfig(){ document.getElementById('dbConfigModal').classList.remove('open'); document.getElementById('db_password').value = ''; }
function openUpload(campaignId){
  document.getElementById('uploadCampaignId').value = campaignId;
  document.getElementById('uploadCampaignLabel').textContent = 'Campaña #' + campaignId;
  document.getElementById('uploadModal').classList.add('open');
}
function closeUpload(){
  document.getElementById('uploadModal').classList.remove('open');
}
function openClients(campaignId){
  document.getElementById('addClientCampaignId').value = campaignId;
  document.getElementById('clientsCampaignLabel').textContent = 'Campaña #' + campaignId;
  document.querySelectorAll('.cmp-client-row').forEach(row => {
    row.style.display = Number(row.dataset.campaignId) === Number(campaignId) ? 'grid' : 'none';
  });
  document.getElementById('clientsModal').classList.add('open');
}
function closeClients(){
  document.getElementById('clientsModal').classList.remove('open');
}
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') { closeClients(); closeUpload(); closeDbConfig(); }
});
// Poblar el selector de flujos desde ivr_builder (mismos credenciales de sesión)
(async function(){
  const sel = document.getElementById('flow_code');
  try {
    const r = await fetch('../ivr_builder/api_flows.php', {cache:'no-store'});
    const d = await r.json();
    const flows = (d && d.flows) ? d.flows : [];
    if (!flows.length) { sel.innerHTML = '<option value="">(no hay flujos creados)</option>'; return; }
    sel.innerHTML = '<option value="">Selecciona un flujo…</option>' +
      flows.map(f => `<option value="${f.code}">${f.code} - ${(f.name||'').replace(/</g,'&lt;')}</option>`).join('');
  } catch(e) {
    sel.innerHTML = '<option value="">(error al cargar flujos)</option>';
  }
})();

function showBotIvrTab(name){
  document.querySelectorAll('.cc-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.getElementById('tab-campanas').classList.toggle('active', name === 'campanas');
  document.getElementById('tab-consola').classList.toggle('active', name === 'consola');
}

/* ---------- Consola de campaña (migrado de campaign_console.php) ----------
   Se omiten a propósito "Estado del servidor" y "Monitoreo avanzado":
   pidieron dejar solo el control de campaña + progreso en vivo. */
(function(){
let _cid = '', _timer = null, _audioReady = false, _currentProvider = '', _hasManifest = false, _providerLoaded = false, _originLoaded = false;
const $ = id => document.getElementById(id);
const DEEPGRAM_POOL_WORKERS = <?php echo (int) $deepgramPoolWorkers; ?>;
const esc = s => String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const durationText = value => {
  const seconds=Math.max(0,Number(value||0));
  if(!seconds) return '—';
  const hours=Math.floor(seconds/3600), minutes=Math.floor((seconds%3600)/60), secs=Math.floor(seconds%60);
  return hours ? `${hours} h ${minutes} min` : (minutes ? `${minutes} min ${secs} s` : `${secs} s`);
};

function syncProviderControls(){
  const isPool = $('tts_provider').value === 'deepgram_pool';
  $('cc-audio-workers').style.display = isPool ? 'none' : '';
  $('cc-pool-workers').style.display = isPool ? 'block' : 'none';
  if(isPool && DEEPGRAM_POOL_WORKERS < 1){
    setMsg('Deepgram Pool no tiene API keys activas.','#b91c1c');
  }
}

window.saveProvider = async function saveProvider(){
  syncProviderControls();
  if(!_cid) return;
  const select = $('tts_provider');
  const provider = select.value;
  select.disabled = true;
  try{
    const fd = new URLSearchParams({campaign_id:_cid, provider});
    const d = await (await fetch('campaign_set_provider.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd})).json();
    setMsg(d.ok ? d.message : ('Error: '+d.error), d.ok?'#047857':'#b91c1c');
    if(!d.ok) _providerLoaded=false;
    poll();
  }catch(e){ setMsg('Error de red: '+e.message,'#b91c1c'); _providerLoaded=false; }
  finally{ select.disabled=false; }
};

window.saveOrigin = async function saveOrigin(){
  if(!_cid) return;
  const select = $('cc-origin');
  const origin = select.value;
  select.disabled = true;
  try{
    const fd = new URLSearchParams({campaign_id:_cid, origin});
    const d = await (await fetch('campaign_set_origin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd})).json();
    setMsg(d.ok ? d.message : ('Error: '+d.error), d.ok?'#047857':'#b91c1c');
    if(!d.ok) _originLoaded=false;
    poll();
  }catch(e){ setMsg('Error de red: '+e.message,'#b91c1c'); _originLoaded=false; }
  finally{ select.disabled=false; }
};

function campaignVisualState(audioStatus,campaignStatus){
  if(campaignStatus==='completed') return 'completed';
  if(campaignStatus==='running'||campaignStatus==='stopping') return 'running';
  if(audioStatus==='building') return 'building';
  if(audioStatus==='ready') return 'ready';
  if(audioStatus==='error') return 'error';
  return 'pending';
}

function updateSelectedCampaignOption(audioStatus,campaignStatus){
  const option=$('cc-campaign').selectedOptions[0];
  if(!option||!option.value) return;
  const state=campaignVisualState(audioStatus,campaignStatus);
  const markers={building:'🔵',ready:'🟡',running:'🟠',completed:'🟢',error:'🔴',pending:'⚪'};
  option.dataset.audioStatus=audioStatus;
  option.dataset.campaignStatus=campaignStatus;
  option.textContent=markers[state]+' '+option.dataset.baseLabel;
}

window.selectCampaign = function selectCampaign(){
  _cid = $('cc-campaign').value;
  if(_timer){ clearInterval(_timer); _timer=null; }
  _audioReady=false;
  _currentProvider=''; _hasManifest=false; _providerLoaded=false; _originLoaded=false;
  $('cc-max').value = '';
  if(!_cid){ $('cc-play').disabled=true; $('cc-stop').disabled=true; $('cc-generate').disabled=true; $('cc-audio').style.display='none'; return; }
  $('cc-generate').disabled=false;
  poll();
  _timer = setInterval(poll, 3000);
};

window.generateAudios = async function generateAudios(){
  if(!_cid) return;
  const prov = $('tts_provider').value;
  const sameFamily = (a,b) => {
    const macelio = (a==='macelioai'||a==='macelioai_remote') && (b==='macelioai'||b==='macelioai_remote');
    const deepgram = (a==='deepgram'||a==='deepgram_pool') && (b==='deepgram'||b==='deepgram_pool');
    return macelio || deepgram;
  };
  if(_hasManifest && _currentProvider && prov !== _currentProvider && !sameFamily(prov,_currentProvider) &&
     !confirm('Cambiar de proveedor regenera TODOS los audios de la campaña. Con voicescloning esto consume créditos nuevamente (~1.200 por cliente). ¿Continuar?')) return;
  $('cc-generate').disabled=true; $('cc-play').disabled=true;
  setMsg('Iniciando generación de audios…');
  try{
    const workers = $('cc-audio-workers').value;
    const fd = new URLSearchParams({campaign_id:_cid,workers,provider:prov});
    const r = await fetch('launch_campaign_prebuild.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd});
    const d = await r.json();
    setMsg(d.ok ? 'Generación iniciada. El progreso se actualizará automáticamente.' : ('Error: '+d.error), d.ok?'#2D2F3B':'#b91c1c');
    poll();
  }catch(e){ setMsg('Error de red: '+e.message,'#b91c1c'); $('cc-generate').disabled=false; }
};

window.playCampaign = async function playCampaign(){
  if(!_cid) return;
  const max = $('cc-max').value;
  if(max === ''){ setMsg('Selecciona la cantidad de llamadas simultáneas antes de reproducir.','#b91c1c'); return; }
  const origin = $('cc-origin').value;
  $('cc-play').disabled=true; setMsg('Lanzando discador…');
  try{
    const fd = new URLSearchParams({campaign_id:_cid, max, origin});
    const d = await (await fetch('launch_campaign.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd})).json();
    setMsg(d.ok ? ('Discador lanzado (PID '+d.pid+').') : ('Error: '+d.error), d.ok?'#047857':'#b91c1c');
    poll();
  }catch(e){ setMsg('Error de red: '+e.message,'#b91c1c'); $('cc-play').disabled=false; }
};

window.stopCampaign = async function stopCampaign(){
  if(!_cid) return;
  if(!confirm('¿Detener la campaña? Las llamadas en curso terminan y no se lanzan nuevas.')) return;
  $('cc-stop').disabled=true; setMsg('Enviando señal de detención…');
  try{
    const fd = new URLSearchParams({campaign_id:_cid});
    const d = await (await fetch('stop_campaign.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd})).json();
    setMsg(d.ok ? d.message : ('Error: '+d.error), d.ok?'#E0731A':'#b91c1c');
    poll();
  }catch(e){ setMsg('Error de red: '+e.message,'#b91c1c'); }
};

window.applyChannels = async function applyChannels(){
  if(!_cid) return;
  const channels = $('cc-max').value;
  $('cc-apply-channels').disabled = true;
  setMsg('Aplicando canales…');
  try{
    const fd = new URLSearchParams({campaign_id:_cid, channels});
    const d = await (await fetch('campaign_set_channels.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd})).json();
    setMsg(d.ok ? d.message : ('Error: '+d.error), d.ok?'#047857':'#b91c1c');
  }catch(e){ setMsg('Error de red: '+e.message,'#b91c1c'); }
  finally{ $('cc-apply-channels').disabled = false; }
};

async function poll(){
  if(!_cid) return;
  try{
    const d = await (await fetch('campaign_status.php?campaign_id='+encodeURIComponent(_cid)+'&_='+Date.now())).json();
    if(!d.ok){ setMsg(d.error||'error','#b91c1c'); return; }
    const st = d.stats;
    $('st-cargados').textContent = st.cargados;
    $('st-pendiente').textContent = st.pendiente;
    $('st-llamando').textContent = st.llamando;
    $('st-contestado').textContent = st.contestado;
    $('st-terminado').textContent = st.terminado;

    const stEl = $('cc-state'), txt = $('cc-state-txt');
    if(d.running){ stEl.className='cc-running cc-on'; txt.textContent = d.stop_pending?'Deteniéndose…':'En ejecución'; }
    else { stEl.className='cc-running cc-off'; txt.textContent='Detenido'; }
    $('cc-apply-channels').disabled = !!d.stop_pending;
    const desiredCh = d.campaign && d.campaign.desired_channels;
    if(d.running && desiredCh && document.activeElement !== $('cc-max')) $('cc-max').value = String(desiredCh);
    const au = d.audio || {};
    _hasManifest = !!au.manifest_status && au.manifest_status !== 'missing';
    _currentProvider = _hasManifest ? (au.provider || 'macelioai_remote') : '';
    if(!_providerLoaded){
      const assignedProvider = d.campaign && d.campaign.tts_provider;
      if(assignedProvider) $('tts_provider').value=assignedProvider;
      else if(_hasManifest) $('tts_provider').value=_currentProvider;
      _providerLoaded=true;
      syncProviderControls();
    }
    $('tts_provider').disabled = !!(d.running || d.prebuild_running);
    if(!_originLoaded){
      $('cc-origin').value = (d.campaign && d.campaign.call_origin) || 'sipp_2006';
      _originLoaded=true;
    }
    $('cc-origin').disabled = !!d.running;
    _audioReady = !!d.audio_ready_to_launch;
    const total = Number(au.total||0), ready = Number(au.ready_count||0);
    const pct = total ? Math.round(ready/total*100) : 0;
    $('cc-audio').style.display='block';
    $('cc-audio-pct').textContent=pct+'%';
    $('cc-audio-bar').style.width=pct+'%';
    $('cc-audio-text').textContent = d.prebuild_running
      ? `Generando: ${ready}/${total} listos · ${au.building||0} en proceso · ${au.failed||0} fallidos`
      : (_audioReady
          ? `Preparados: ${ready}/${total} · ${au.generated||0} nuevos · ${au.reused||0} reutilizados · ${au.runtime_only||0} durante llamada`
          : `${au.reason||'Audios pendientes'} · ${ready}/${total} listos · ${au.failed||0} fallidos`);
    $('cc-generate').disabled = d.running || d.prebuild_running;
    $('cc-play').disabled = d.running || d.prebuild_running || !_audioReady || $('cc-max').value === '';
    $('cc-stop').disabled = !d.running;

    const pg=d.progress||{}, summaries=d.result_summary||[], active=d.active_calls||[];
    const campaignState=d.running ? (d.stop_pending?'stopping':'running')
      : (Number(pg.total||0)>0 && Number(pg.remaining||0)===0 ? 'completed' : (d.summary_campaign_status||'idle'));
    const audioState=d.prebuild_running ? 'building'
      : (_audioReady ? 'ready' : ((Number(au.failed||0)>0||au.manifest_status==='failed')?'error':'pending'));
    updateSelectedCampaignOption(audioState,campaignState);
    $('live-percent').textContent=(pg.percent||0)+'%';
    $('live-bar').style.width=(pg.percent||0)+'%';
    $('live-processed').textContent=`${pg.processed||0} / ${pg.total||0}`;
    $('live-average').textContent=durationText(pg.average_seconds);
    $('live-eta').textContent=(pg.remaining||0) ? durationText(pg.eta_seconds) : 'Completada';
    $('live-summary').textContent=`${pg.remaining||0} por procesar · actualización cada 3 segundos`;
    $('live-results-count').textContent=summaries.reduce((sum,item)=>sum+Number(item.cantidad||0),0);
    $('live-current').textContent=active.length
      ? active.map(call=>`Llamando: ${call.customer_name||'Sin nombre'} · ${call.phone} · anexo ${call.assigned_agent||'—'} · ${durationText(call.elapsed_seconds)}`).join(' | ')
      : (d.running?'Esperando anexo libre…':'Sin llamada activa.');
    $('live-result-chips').innerHTML=summaries.map(item=>`<span class="result-chip">${esc(item.resultado)}: <strong>${Number(item.cantidad||0)}</strong></span>`).join('') || '<span style="font-size:.72rem;color:var(--text-muted)">Aún no hay resultados.</span>';
    $('live-recent').innerHTML=(d.recent_results||[]).map(item=>`<tr>
      <td>${esc(item.created_at||'—')}</td><td style="font-family:monospace">${esc(item.phone||'—')}</td>
      <td>${esc(item.customer_name||'—')}</td><td><code>${esc(item.resultado||'Sin etiqueta')}</code></td>
    </tr>`).join('') || '<tr><td colspan="4" class="muted">Sin resultados.</td></tr>';

  }catch(e){ /* silencioso */ }
}

function setMsg(m,c){ const e=$('cc-msg'); e.textContent=m; e.style.color=c||'var(--primary)'; }

syncProviderControls();
})();
</script>

<?php initial_survey_page_end(); ?>

