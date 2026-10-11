<?php
declare(strict_types=1);
// Fase 3: originator de prueba. SOLO CLI, invocacion manual (sin cron, sin
// servicio). Respeta max_concurrent_calls, reserva leads con
// FOR UPDATE SKIP LOCKED (ver Dialer::claimLead) y origina por AMI. El
// conteo "en vuelo" es una aproximacion por ventana de tiempo (ver
// Dialer::inFlightCount) hasta que exista el closer real (Fase 5).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../includes/Dialer.php';
require_once __DIR__ . '/../../includes/PbxAmi.php';
use Includes\Dialer;
use Includes\PbxAmi;

$options = getopt('', ['campaign:', 'ticks::', 'wait::', 'window::']);
$campaignId = filter_var($options['campaign'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ticks = filter_var($options['ticks'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
$wait = filter_var($options['wait'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
$window = filter_var($options['window'] ?? 5, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
if (!$campaignId) { fwrite(STDERR, "Uso: php dialer_originator_worker.php --campaign=ID [--ticks=20] [--wait=1] [--window=5]\n"); exit(1); }

$campaign = Dialer::campaign($campaignId);
if (!$campaign['active']) { fwrite(STDERR, "Campana inactiva.\n"); exit(1); }
if ($campaign['dial_method'] === 'MANUAL') { fwrite(STDERR, "Campana en modo MANUAL: el originator no aplica.\n"); exit(1); }

$config = PbxAmi::load();
$hardCap = (int) $campaign['max_concurrent_calls'];
$dialPrefix = (string) $campaign['dial_prefix'];
$adaptive = $campaign['dial_method'] === 'ADAPTIVE';

echo "Campana #$campaignId ({$campaign['name']}) prefijo=$dialPrefix metodo={$campaign['dial_method']} max_concurrent(tope duro)=$hardCap\n";

for ($tick = 1; $tick <= $ticks; $tick++) {
    // Fase 6: en ADAPTIVE el tope efectivo es agentes_disponibles x nivel
    // (recalculado cada tick, manual §8.2/8.3), siempre limitado por el
    // tope duro de la campaña. RATIO/MANUAL no cambian (Fase 3 ya probada).
    $maxConcurrent = $hardCap;
    if ($adaptive) {
        $level = Dialer::recalculateDialLevel($campaignId);
        $agents = Dialer::availableAgentsCount($campaignId, (string) $campaign['level_count_mode']);
        $maxConcurrent = min($hardCap, (int) round($agents * $level));
        echo "[tick $tick] nivel=$level agentes=$agents tope_efectivo=$maxConcurrent\n";
    }
    $inFlight = Dialer::inFlightCount($campaignId, $window);
    $claimed = null;
    if ($inFlight < $maxConcurrent) {
        $claimed = Dialer::claimLead($campaignId, $dialPrefix);
        if ($claimed) {
            try {
                $msg = PbxAmi::originate($config, $claimed['channel'], ['ZV2_DIALER_CALL_ID' => $claimed['call_id']]);
                echo "[tick $tick] en_vuelo=$inFlight claim lead={$claimed['lead_id']} phone={$claimed['phone_number']} call_id={$claimed['call_id']} -> $msg\n";
            } catch (Throwable $e) {
                Dialer::releaseFailedClaim($claimed['call_id'], $claimed['lead_id']);
                echo "[tick $tick] en_vuelo=$inFlight claim lead={$claimed['lead_id']} RECHAZADO por AMI: {$e->getMessage()} (liberado)\n";
            }
        }
    }
    if (!$claimed) {
        echo "[tick $tick] en_vuelo=$inFlight sin_claim\n";
    }
    sleep($wait);
}

echo "Fin de la prueba ($ticks ticks).\n";
