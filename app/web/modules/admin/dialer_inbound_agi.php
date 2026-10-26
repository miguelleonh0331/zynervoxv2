#!/usr/bin/php
<?php
declare(strict_types=1);
// AGI de la llamada entrante. Mismo patrón que dialer_call_tracking_agi.php:
// AGI mínimo, sin librería. El contexto de entrada lo escribe el operador; este
// script solo registra, resuelve la cola y cierra.
//
// Orden esperado en el dialplan, equivalente al Answer→AGI→Queue de la
// saliente: Answer() y después "start", para que la ficha del cliente esté en
// base mientras el teléfono del agente timbra y la consola la encuentre al
// sondear. Variables que deja "start":
//   ZV2_INBOUND_READY  1 si el DID tiene ruta; 0 si no (el dialplan decide)
//   ZV2_INBOUND_CALL_ID  id para los pasos siguientes
//   ZV2_INBOUND_QUEUE    cola a la que pasar el canal
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
require __DIR__ . '/../../includes/Dialer.php';
use Includes\Dialer;

// Protocolo AGI: Asterisk manda cabeceras "clave: valor" por stdin hasta una
// línea vacía. Hay que consumirlas antes de leer argv o Asterisk se queda
// esperando.
while (($line = fgets(STDIN)) !== false && trim($line) !== '') { /* descartar */ }

function inboundSet(string $key, string $value): void {
    fwrite(STDOUT, 'SET VARIABLE ' . $key . ' "' . $value . '"' . "\n");
    fgets(STDIN); // respuesta AGI al SET VARIABLE
}

try {
    $action = $argv[1] ?? '';
    if ($action === 'start') {
        $did = (string) ($argv[2] ?? '');
        $phone = (string) ($argv[3] ?? '');
        $call = Dialer::registerInboundCall($did, $phone, (string) ($argv[4] ?? ''));
        if ($call === null) { inboundSet('ZV2_INBOUND_READY', '0'); exit(0); }
        inboundSet('ZV2_INBOUND_CALL_ID', (string) $call['call_id']);
        inboundSet('ZV2_INBOUND_QUEUE', $call['queue_name']);
        inboundSet('ZV2_INBOUND_READY', '1');
    } else {
        $callId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$callId) throw new RuntimeException('call_id invalido');
        if ($action === 'bridged') {
            // ${MEMBERINTERFACE} dentro del macro de puente de Queue().
            Dialer::bindInboundInterface($callId, (string) ($argv[3] ?? ''));
        } elseif ($action === 'closed') {
            Dialer::closeInboundCall($callId, (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''));
        } else {
            throw new RuntimeException('accion invalida: ' . $action);
        }
    }
} catch (Throwable $e) {
    // Fail-open: un error aquí no debe tumbar la llamada real en curso. En
    // "start" se avisa al dialplan para que no mande el canal a una cola que
    // no se resolvió.
    if (($argv[1] ?? '') === 'start') { try { inboundSet('ZV2_INBOUND_READY', '0'); } catch (Throwable $ignored) { } }
    fwrite(STDERR, 'dialer_inbound_agi: ' . $e->getMessage() . "\n");
    exit(1);
}
