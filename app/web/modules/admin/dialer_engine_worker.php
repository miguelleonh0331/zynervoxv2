<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../../includes/Dialer.php';
$lock = fopen('/run/zynervox-dialer/worker.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { fwrite(STDERR,"Worker ya activo o directorio no preparado.\n"); exit(1); }
echo "Dialer supervised worker started\n";
while (true) {
    \Includes\Dialer::workerTick();
    sleep(1);
}
