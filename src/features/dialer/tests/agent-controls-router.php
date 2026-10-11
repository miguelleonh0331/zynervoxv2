<?php
if (PHP_SAPI!=='cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '')!=='127.0.0.1') { http_response_code(404); exit; }
ob_start(); require __DIR__.'/agent-controls.php'; ob_end_clean();
$db->exec('DELETE FROM v2_dialer_call_log');
$db->exec("UPDATE v2_dialer_campaigns SET manual_autodial_enabled=1 WHERE campaign_id=2");
if (($_GET['pending'] ?? '')==='1') $db->exec("INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,ended_at,reserved_agent_user,disposition_agent_user,termination_reason) VALUES (1,1,'000000',NOW(),NOW(),'lab','lab','HANGUP')");
$ddl=$db->query('SHOW CREATE TABLE v2_zynervox_users')->fetch(PDO::FETCH_NUM)[1];
$db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
session_start(); $_SESSION=[];
if (isset($_GET['role'])) {
    $_SESSION=['user'=>'lab','user_level'=>(int)$_GET['role'],'dialer_entered'=>true,'dialer_csrf'=>'controls_fixture_token'];
    $db->prepare("INSERT INTO v2_zynervox_users (user,pass,full_name,user_level,active) VALUES ('lab','fixture-only','Lab',?,'Y')")->execute([(int)$_GET['role']]);
}
if (($_GET['page'] ?? '')==='logout') require getenv('DIALER_TEST_WEB_ROOT').'/logout.php';
else require getenv('DIALER_TEST_WEB_ROOT').'/modules/admin/dialer_agent.php';
