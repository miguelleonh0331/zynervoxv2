<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../../../../app/web/modules/vpn/service.php';
session_start();
$_SESSION = ['user'=>'vpn-role-test','user_level'=>8];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
register_shutdown_function(function (): void {
    $denied = http_response_code() === 403;
    $_SESSION = [];
    session_destroy();
    echo $denied ? "\nROLE_DENIED_OK\n" : "\nROLE_DENIED_FAILED\n";
    if (!$denied) exit(1);
});
vpn_authorize();
exit(1);
