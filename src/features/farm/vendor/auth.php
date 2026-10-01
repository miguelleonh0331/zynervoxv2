<?php
declare(strict_types=1);

$farmConfigFile = __DIR__ . '/config.local.php';
if (!is_file($farmConfigFile)) {
    http_response_code(503);
    exit('Farm no configurado');
}
$farmConfig = require $farmConfigFile;

require_once dirname(__DIR__, 3) . '/includes/Auth.php';

function farm_config(): array {
    global $farmConfig;
    return $farmConfig;
}

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function audit_event(string $event, array $details = []): void {
    $config = farm_config();
    $row = [
        'ts' => gmdate('c'),
        'ip' => client_ip(),
        'user' => (string)($_SESSION['user'] ?? 'unknown'),
        'event' => $event,
        'details' => $details,
    ];
    @file_put_contents((string)$config['audit_log'], json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
}

function csrf_token(): string {
    if (empty($_SESSION['farm_csrf'])) $_SESSION['farm_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['farm_csrf'];
}

function require_csrf(): void {
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['farm_csrf'] ?? ''), $provided)) {
        audit_event('csrf_rejected');
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Solicitud rechazada']);
        exit;
    }
}

function require_auth(bool $json = false): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user']) || (int)($_SESSION['user_level'] ?? 0) !== 9) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sesión administrativa requerida']);
        } else {
            header('Location: ../../../index.php');
        }
        exit;
    }
    csrf_token();
}
