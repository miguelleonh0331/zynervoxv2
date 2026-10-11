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
$_SESSION['dialer_csrf']='private_http_fixture_token';
if (getenv('DIALER_HTTP_AUDIO_FIXTURE')==='1') {
    $db->exec("INSERT INTO v2_dialer_call_log (call_id,campaign_id,lead_id,agent_user,phone_number,originated_at,ended_at,recording_path) VALUES
      (900000001,1,1,'lab','000000','2026-10-01 12:00:00','2026-10-01 12:01:00','/var/spool/asterisk/monitor/zynervoxv2/dialer/call-900000001.wav'),
      (900000002,1,1,'lab','000001','2026-10-01 12:00:00','2026-10-01 12:01:00','/etc/passwd')");
}
$pages=['report'=>'dialer_report.php','campaign'=>'dialer_campaign_edit.php','agent'=>'dialer_agent.php'];
$page=$pages[$_GET['page'] ?? 'report'] ?? 'dialer_report.php';
require getenv('DIALER_TEST_WEB_ROOT') . '/modules/admin/'.$page;
