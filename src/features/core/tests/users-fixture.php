<?php
if (!in_array(PHP_SAPI,['cli','cli-server'],true)) exit(1);
$web=getenv('CORE_USERS_TEST_WEB_ROOT');
if (!$web || !is_file($web.'/includes/CoreUsers.php')) throw new RuntimeException('Staging requerido');
require $web.'/includes/CoreUsers.php';
require $web.'/includes/Auth.php';
$db=new PDO('mysql:host=localhost;dbname=zynervox_core','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$instance=(new ReflectionClass(Includes\Database::class))->newInstanceWithoutConstructor();
$conn=new ReflectionProperty(Includes\Database::class,'conn');$conn->setAccessible(true);$conn->setValue($instance,$db);
$core=new ReflectionProperty(Includes\Database::class,'coreInstance');$core->setAccessible(true);$core->setValue(null,$instance);
foreach (['v2_zynervox_users','v2_access_log','v2_core_user_campaigns'] as $table) {
    $ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
}
$db->exec("INSERT INTO v2_zynervox_users (user,pass,full_name,user_level,active) VALUES ('90000','legacy-test-password','Admin',9,'Y')");
