#!/usr/bin/php
<?php
declare(strict_types=1);
// Closer del motor dialer (Fase 5). Lo invoca el dialplan generado por
// Includes\Dialer::syncDialplan() en cada paso de la llamada. Mismo patron
// que bot_ivr/call_tracking_agi.php: AGI minimo, sin libreria.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
require __DIR__ . '/../../includes/Dialer.php';
use Includes\Dialer;

// Protocolo AGI: Asterisk manda cabeceras "clave: valor" por stdin hasta
// una línea vacía. No se usan aquí, pero hay que consumirlas antes de leer
// argv o Asterisk se queda esperando.
while (($line = fgets(STDIN)) !== false && trim($line) !== '') { /* descartar */ }

try {
    $action = $argv[1] ?? '';
    $callId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$callId) throw new RuntimeException('call_id invalido');
    if ($action === 'answered') {
        Dialer::markAnswered($callId);
    } elseif ($action === 'amd') {
        Dialer::markAmd($callId, (string) ($argv[3] ?? ''));
    } elseif ($action === 'amd_hangup') {
        Dialer::closeMachine($callId);
    } elseif ($action === 'closed') {
        Dialer::closeCall($callId, (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''));
    } else {
        throw new RuntimeException('accion invalida: ' . $action);
    }
} catch (Throwable $e) {
    // Fail-open: un error aqui no debe tumbar la llamada real en curso.
    fwrite(STDERR, 'dialer_call_tracking_agi: ' . $e->getMessage() . "\n");
    exit(1);
}
