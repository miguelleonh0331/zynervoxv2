<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_auth(true);
header('Content-Type: application/json; charset=utf-8');

// Inventario puramente administrativo de los .txt de cuentas proxy: que
// archivo viene de que cuenta Gmail y cuando se subio. No participa en el
// parseo real de proxies -- eso lo sigue haciendo orchestrator.py leyendo
// los .txt de la carpeta tal cual lo hace hoy. Este archivo solo anade un
// sidecar JSON (.inventory.json) en la misma carpeta, que orchestrator.py
// ignora porque su lector solo toma archivos con sufijo .txt.

$dir = (string)(farm_config()['proxy_dir'] ?? '');
if ($dir === '' || !is_dir($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Carpeta de proxies no disponible en el servidor']);
    exit;
}
$dir = rtrim($dir, '/');
$inventoryPath = $dir.'/.inventory.json';

function proxy_inventory_read(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function proxy_inventory_write(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $inventory = proxy_inventory_read($inventoryPath);
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'txt') continue;
        $full = $dir.'/'.$name;
        if (!is_file($full)) continue;
        $meta = $inventory[$name] ?? null;
        $files[] = [
            'file' => $name,
            'account' => $meta['account'] ?? null,
            'original_name' => $meta['original_name'] ?? null,
            'uploaded_at' => $meta['uploaded_at'] ?? null,
            'size' => filesize($full) ?: 0,
            'registered' => $meta !== null,
        ];
    }
    usort($files, fn($a, $b) => strcmp($b['file'], $a['file']));
    echo json_encode(['ok' => true, 'files' => $files]);
    exit;
}

if ($method === 'POST') {
    require_csrf();
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $action = (string)($payload['action'] ?? '');

    if ($action === 'delete') {
        $name = basename((string)($payload['file'] ?? ''));
        if ($name === '' || strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'txt') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Nombre de archivo invalido']);
            exit;
        }
        $target = $dir.'/'.$name;
        if (!is_file($target)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El archivo ya no existe']);
            exit;
        }
        $inventory = proxy_inventory_read($inventoryPath);
        $account = $inventory[$name]['account'] ?? null;
        unlink($target);
        if (isset($inventory[$name])) {
            unset($inventory[$name]);
            proxy_inventory_write($inventoryPath, $inventory);
        }
        audit_event('proxy_file_delete', ['file' => $name, 'account' => $account]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Accion no reconocida']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
