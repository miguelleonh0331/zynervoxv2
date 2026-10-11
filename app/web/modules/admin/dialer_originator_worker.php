<?php
declare(strict_types=1);
// CLI-only bounded worker. Real outbound origination uses the carrier endpoint;
// answer/AMD/Queue/recording and closing run in the campaign's generated dialplan.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Dialer;
$options = getopt('', ['campaign:', 'ticks::', 'wait::', 'window::']);
$campaignId = filter_var($options['campaign'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ticks = filter_var($options['ticks'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
$wait = filter_var($options['wait'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
if (!$campaignId || !$ticks || !$wait) {
    fwrite(STDERR, "Uso: php dialer_originator_worker.php --campaign=ID [--ticks=20] [--wait=1]\n"); exit(1);
}
$campaign = Dialer::campaign($campaignId);
if ($campaign['dial_method'] === 'MANUAL') { fwrite(STDERR, "Modo MANUAL: usa el panel de agente.\n"); exit(1); }
for ($tick = 1; $tick <= $ticks; $tick++) {
    try {
        $closed = Dialer::reconcileUnanswered($campaignId);
        $callId = Dialer::originateNext($campaignId);
        echo "[tick $tick] cerrados=$closed en_vuelo=" . Dialer::inFlightCount($campaignId) . ' intento=' . ($callId ?? 'ninguno') . "\n";
    } catch (Throwable $error) { fwrite(STDERR, "[tick $tick] " . $error->getMessage() . "\n"); }
    if ($tick < $ticks) sleep($wait);
}
// Give any pending preanswer attempts their configured timeout before exit.
$deadline = time() + (int) Dialer::campaign($campaignId)['dial_timeout_seconds'] + 6;
do {
    try { Dialer::reconcileUnanswered($campaignId); } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); }
    if (Dialer::inFlightCount($campaignId) === 0) break;
    sleep(1);
} while (time() < $deadline);
echo "Fin: las conversaciones activas conservan su capacidad y cierran por hangup handler.\n";
