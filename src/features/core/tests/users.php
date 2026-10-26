<?php
require __DIR__.'/users-fixture.php';
session_id(bin2hex(random_bytes(20)));session_start();$_SESSION=['user'=>'90000','user_level'=>9];
function usersVerify($value,$message) { if (!$value) throw new RuntimeException($message); }
function usersReject($call) { try {$call();} catch (InvalidArgumentException $expected) {return;} throw new RuntimeException('Invalid input accepted'); }
$input=['user'=>'90001','full_name'=>'<script>name</script>','pass'=>'test-password-only','active'=>'Y','user_level'=>1];
Includes\CoreUsers::saveUser($input);
$stored=$db->query("SELECT * FROM v2_zynervox_users WHERE user='90001'")->fetch(PDO::FETCH_ASSOC);
usersVerify((int)$stored['user_level']===1 && password_verify($input['pass'],$stored['pass']),'Agent role/hash');
// La web administra 1/7/8; el 9 se da de alta por SQL.
foreach ([9,0,2,'','x'] as $forbidden) usersReject(static function() use($input,$forbidden) { Includes\CoreUsers::saveUser(array_replace($input,['user'=>'90002','user_level'=>$forbidden])); });
// El agente se loguea con su anexo SIP; GTR y supervisor con letras.
foreach ([['user'=>'90002','user_level'=>8],['user'=>'Supervisor','user_level'=>8],['user'=>'9sup','user_level'=>7],['user'=>'su','user_level'=>8],['user'=>'sup visor','user_level'=>8]] as $invalid) usersReject(static function() use($input,$invalid) { Includes\CoreUsers::saveUser(array_replace($input,$invalid)); });
Includes\CoreUsers::saveUser(array_replace($input,['user'=>'rvaldez','full_name'=>'Rodrigo Valdez','user_level'=>8]));
usersVerify((int)$db->query("SELECT user_level FROM v2_zynervox_users WHERE user='rvaldez'")->fetchColumn()===8,'Supervisor level stored');
usersReject(static function() use($input) { Includes\CoreUsers::saveUser(array_replace($input,['user'=>'rvaldez','user_level'=>1,'pass'=>'']),'rvaldez'); });
usersVerify(Includes\Auth::getRedirectPath(8)==='modules/admin/campaign_monitor.php' && Includes\Auth::getRedirectPath(7)===Includes\Auth::getRedirectPath(8),'Supervisor destination');
// Alcance de campanas: cierra por defecto y solo aplica a 7 y 8.
usersVerify(Includes\CoreUsers::campaignsFor('rvaldez')===[],'Scope closed by default');
foreach ([['x:1'],['agents:0'],['agents:abc'],['agents'],['agents:1:2'],'agents:1',[['agents:1']]] as $bad) usersReject(static function() use($input,$bad) { Includes\CoreUsers::saveUser(array_replace($input,['user'=>'rvaldez2','user_level'=>8,'campaigns'=>$bad])); });
Includes\CoreUsers::saveUser(array_replace($input,['user'=>'rvaldez','full_name'=>'Rodrigo Valdez','user_level'=>8,'pass'=>'','campaigns'=>['agents:55','bot_ivr:3','agents:55']]),'rvaldez');
$scope=Includes\CoreUsers::campaignsFor('rvaldez');sort($scope);
usersVerify($scope===['agents:55','bot_ivr:3'],'Scope stored and deduplicated');
Includes\CoreUsers::saveUser(array_replace($input,['user'=>'rvaldez','full_name'=>'Rodrigo Valdez','user_level'=>8,'pass'=>'','campaigns'=>[]]),'rvaldez');
usersVerify(Includes\CoreUsers::campaignsFor('rvaldez')===[],'Scope cleared');
Includes\CoreUsers::saveUser(array_replace($input,['user'=>'90003','user_level'=>1,'campaigns'=>['agents:55']]));
usersVerify(Includes\CoreUsers::campaignsFor('90003')===[],'Agent keeps no scope');
usersVerify(!array_key_exists('pass',Includes\CoreUsers::getByUser('90001')) && !array_key_exists('pass',Includes\CoreUsers::getAll()[0]),'Secret exposed');
usersReject(static function() use($input) { Includes\CoreUsers::saveUser($input); });
foreach ([['user'=>'abc'],['user'=>str_repeat('1',21)],['full_name'=>''],['full_name'=>str_repeat('x',101)],['pass'=>'short'],['pass'=>str_repeat('x',73)],['active'=>'bad']] as $invalid) usersReject(static function() use($input,$invalid) { Includes\CoreUsers::saveUser(array_replace($input,$invalid)); });
usersReject(static function() use($input) { Includes\CoreUsers::saveUser($input,'90000'); });
usersReject(static function() { Includes\CoreUsers::saveUser(['user'=>'90000','full_name'=>'Admin','pass'=>'','active'=>'N'],'90000'); });
Includes\CoreUsers::saveUser(array_replace($input,['pass'=>'','full_name'=>'Changed']),'90001');
usersVerify($db->query("SELECT pass FROM v2_zynervox_users WHERE user='90001'")->fetchColumn()===$stored['pass'],'Empty password changed hash');
$old=session_id();usersVerify(Includes\Auth::login('90001',$input['pass']) && session_id()!==$old,'Login/session rotation');
usersVerify(!Includes\Auth::login('90001',$stored['pass']) && !Includes\Auth::login('90001','wrong-password'),'Hash accepted as password');
usersVerify(Includes\Auth::getRedirectPath(1)==='modules/admin/dialer_agent.php','Agent destination');
Includes\CoreUsers::saveUser(array_replace($input,['pass'=>'','active'=>'N']),'90001');
usersVerify(!Includes\Auth::login('90001',$input['pass']),'Inactive login');
usersVerify(Includes\Auth::login('90000','legacy-test-password'),'Legacy admin login');
session_destroy();
echo "PASS: agent/GTR/supervisor CRUD, level whitelist and per-level login rules, immutable identity/level/admin protection, validation, hashes/plaintext, empty password, inactive login, redirects and session rotation; TEMPORARY tables only.\n";
