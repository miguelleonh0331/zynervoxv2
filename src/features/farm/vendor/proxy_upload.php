<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_auth(true);
require_csrf();
header('Content-Type: application/json; charset=utf-8');

// Sube archivos de cuentas proxy directo a la carpeta vigilada por
// orchestrator.py (proxy_dir) -- reemplaza el flujo manual por FTP/SSH.
// El orquestador recarga solo, no hace falta avisarle por otra via.

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$dir = (string)(farm_config()['proxy_dir'] ?? '');
if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Carpeta de proxies no disponible en el servidor']);
    exit;
}

if (!isset($_FILES['proxy_file']) || $_FILES['proxy_file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['proxy_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['ok' => false, 'error' => "Error de subida (codigo $err)"]);
    exit;
}

$maxBytes = 5 * 1024 * 1024; // 5 MB: listas de proxy son texto plano, de sobra
if ($_FILES['proxy_file']['size'] > $maxBytes) {
    echo json_encode(['ok' => false, 'error' => 'Archivo demasiado grande (max 5 MB)']);
    exit;
}

$originalName = (string)$_FILES['proxy_file']['name'];
$ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, ['txt', 'csv', 'json'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Extension no permitida: use .txt, .csv o .json']);
    exit;
}

// Nombre seguro: solo alfanumerico/guiones del original, prefijado con
// timestamp para no pisar archivos existentes con el mismo nombre.
$base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$safeName = gmdate('Ymd-His') . '_' . substr((string)$base, 0, 80) . '.' . $ext;
$dest = rtrim($dir, '/') . '/' . $safeName;

if (!move_uploaded_file($_FILES['proxy_file']['tmp_name'], $dest)) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo']);
    exit;
}
// 0664 y no 0660: el archivo queda con dueno/grupo www-data (lo crea PHP),
// pero quien lo LEE es orchestrator.py, que corre como el usuario de la
// instancia Farm -- no esta en el grupo www-data, asi que necesita el bit de
// lectura de "otros". No expone nada: el directorio contenedor sigue 0770,
// solo el usuario Farm y www-data pueden siquiera entrar en el.
chmod($dest, 0664);

// Inventario puramente administrativo (que cuenta Gmail genero este archivo).
// Vive en un sidecar .inventory.json dentro de la misma carpeta; orchestrator.py
// lo ignora porque su lector de proxies solo toma archivos con sufijo .txt.
$account = trim((string)($_POST['cuenta'] ?? ''));
$account = mb_substr($account, 0, 120);
$inventoryPath = rtrim($dir, '/').'/.inventory.json';
$inventory = [];
if (is_file($inventoryPath)) {
    $raw = @file_get_contents($inventoryPath);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($decoded)) $inventory = $decoded;
}
$inventory[$safeName] = [
    'account' => $account !== '' ? $account : null,
    'original_name' => $originalName,
    'uploaded_at' => gmdate('c'),
];
file_put_contents($inventoryPath, json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

audit_event('proxy_file_upload', ['file' => $safeName, 'original' => $originalName, 'size' => $_FILES['proxy_file']['size'], 'account' => $account !== '' ? $account : null]);
echo json_encode(['ok' => true, 'file' => $safeName, 'account' => $account !== '' ? $account : null]);
