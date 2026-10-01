<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');

// Endpoint interno de SOLO LECTURA (sin login/CSRF) para que un consumidor
// autorizado verifique cuantas cuentas SIP del pool Zypad
// estan REALMENTE registradas contra Asterisk antes de permitir lanzar una
// campana con origen Zypad. Protegido por IP de origen + token compartido
// (nunca por sesion, es llamada servidor-a-servidor). 2026-09-06.

// No se confia en una IP de origen potencialmente inestable. La proteccion
// real de este endpoint es el token compartido (secreto de 256
// bits, comparacion constante con hash_equals) mas HTTPS; suficiente para un
// endpoint de solo lectura sin datos sensibles (solo cuenta de registros SIP).

function deny(int $code, string $error): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

$config = farm_config();
$expected = trim((string) @file_get_contents((string)$config['internal_token']));
$provided = (string) ($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '');
if ($expected === '' || !hash_equals($expected, $provided)) {
    deny(403, 'Token invalido');
}

function call_daemon(array $payload): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents((string)farm_config()['annex_url'], false, $ctx);
    if ($raw === false) return ['ok' => false, 'error' => 'daemon zypad-annex inaccesible'];
    $result = json_decode($raw, true);
    return is_array($result) ? $result : ['ok' => false, 'error' => 'respuesta invalida del daemon'];
}

$result = call_daemon(['action' => 'status_detail']);
if (empty($result['ok'])) {
    deny(502, (string) ($result['error'] ?? 'error consultando el pool Zypad'));
}

$agents = is_array($result['agents'] ?? null) ? $result['agents'] : [];
$active = 0;
$registered = 0;
foreach ($agents as $a) {
    if (($a['service_active'] ?? '') === 'active') $active++;
    if (($a['reg_state'] ?? '') === 'registrado') $registered++;
}

echo json_encode([
    'ok' => true,
    'total_pool' => count($agents),
    'active' => $active,
    'registered' => $registered,
    'agents' => $agents,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
