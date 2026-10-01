<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_auth(true);
require_csrf();
header('Content-Type: application/json; charset=utf-8');

// Pool de anexos SIP (baresip) para pruebas de pre-answer.
// separado del PBX. Sin login -- servidor de pruebas, mismo criterio usado
// para otras herramientas internas del proyecto. 2026-08-18.
//
// No usa sudo: apache2.service tiene RestrictSUIDSGID=yes (hardening de
// systemd), bloquea que www-data ejecute binarios setuid como sudo. En vez
// de eso, le pega por HTTP a zypad_annex_daemon.py, que corre como root
// (systemd, sin setuid) escuchando solo en 127.0.0.1:8811.

function call_daemon(array $payload): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents((string)farm_config()['annex_url'], false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'No se pudo contactar el daemon local (zypad-annex-daemon.service caido?)'];
    }
    $result = json_decode($raw, true);
    return is_array($result) ? $result : ['ok' => false, 'error' => 'Respuesta invalida del daemon: ' . $raw];
}

$data = json_decode((string) file_get_contents('php://input'), true);
$action = (string) ($data['action'] ?? '');
$agent = preg_replace('/\D+/', '', (string) ($data['agent'] ?? '')) ?? '';
$password = (string) ($data['password'] ?? $agent);

$validActions = ['create', 'create_range', 'delete', 'start', 'stop', 'stop_all', 'start_all', 'status', 'status_detail'];
if (!in_array($action, $validActions, true)) {
    echo json_encode(['ok' => false, 'error' => 'Accion invalida']);
    exit;
}

if ($action === 'create_range') {
    $from = preg_replace('/\D+/', '', (string) ($data['from'] ?? ''));
    $to = preg_replace('/\D+/', '', (string) ($data['to'] ?? ''));
    if (!preg_match('/^4\d{3}$/', $from) || !preg_match('/^4\d{3}$/', $to)) {
        echo json_encode(['ok' => false, 'error' => 'Rango invalido: use formato 4XXX en ambos extremos']);
        exit;
    }
    echo json_encode(
        call_daemon(['action' => 'create_range', 'from' => $from, 'to' => $to, 'password' => $password]),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

if (!in_array($action, ['status', 'status_detail', 'stop_all', 'start_all'], true) && !preg_match('/^4\d{3}$/', $agent)) {
    echo json_encode(['ok' => false, 'error' => 'Anexo invalido: use formato 4XXX']);
    exit;
}

audit_event('annex_action', ['action' => $action, 'agent' => $agent]);
echo json_encode(
    call_daemon(['action' => $action, 'agent' => $agent, 'password' => $password]),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
