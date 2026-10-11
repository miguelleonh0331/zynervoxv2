<?php
if (PHP_SAPI!=='cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '')!=='127.0.0.1') exit(1);
require __DIR__.'/users-fixture.php';
session_start();$_SESSION=[];
if (isset($_GET['role'])) {
    $db->prepare('UPDATE v2_zynervox_users SET user_level=? WHERE user=?')->execute([(int)$_GET['role'],'90000']);
    $_SESSION=['user'=>'90000','user_level'=>9,'core_users_csrf'=>'users-fixture-token'];
}
if (isset($_GET['inactive'])) $db->exec("UPDATE v2_zynervox_users SET active='N'");
if (isset($_GET['login'])) {
    Includes\CoreUsers::saveAgent(['user'=>'90001','full_name'=>'Agent','pass'=>'test-password-only','active'=>'Y']);
    require $web.'/index.php';exit;
}
if (isset($_GET['edit'])) {
    Includes\CoreUsers::saveAgent(['user'=>'90001','full_name'=>'<script>name</script>','pass'=>'test-password-only','active'=>'Y']);
    $_GET['edit']='90001';
}
$entry=isset($_GET['direct']) ? 'users_isolated.php' : 'users.php';
$_SERVER['SCRIPT_FILENAME']=$web.'/modules/admin/'.$entry;
require $web.'/includes/IsolatedGate.php';
require $_SERVER['SCRIPT_FILENAME'];
