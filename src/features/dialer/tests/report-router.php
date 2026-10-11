<?php
// Private HTTP harness, never deploy under the web root.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
ob_start();
require __DIR__ . '/reporting.php';
ob_end_clean();
session_start();
$_SESSION = [];
if (isset($_GET['role'])) {
    $_SESSION['user'] = 'lab';
    $_SESSION['user_level'] = (int) $_GET['role'];
}
$_GET['id'] = $_GET['id'] ?? '1';
$_GET['since'] = $_GET['since'] ?? '2026-10-01';
$_GET['until'] = $_GET['until'] ?? '2026-10-01';
require getenv('DIALER_TEST_WEB_ROOT') . '/modules/admin/dialer_report.php';
