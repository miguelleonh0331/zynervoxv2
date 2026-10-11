<?php
require __DIR__.'/users-fixture.php';
session_id(bin2hex(random_bytes(20)));session_start();$_SESSION=['user'=>'90000','user_level'=>9];
function usersVerify($value,$message) { if (!$value) throw new RuntimeException($message); }
function usersReject($call) { try {$call();} catch (InvalidArgumentException $expected) {return;} throw new RuntimeException('Invalid input accepted'); }
$input=['user'=>'90001','full_name'=>'<script>name</script>','pass'=>'test-password-only','active'=>'Y','user_level'=>9];
Includes\CoreUsers::saveAgent($input);
$stored=$db->query("SELECT * FROM v2_zynervox_users WHERE user='90001'")->fetch(PDO::FETCH_ASSOC);
usersVerify((int)$stored['user_level']===1 && password_verify($input['pass'],$stored['pass']),'Agent role/hash');
usersVerify(!array_key_exists('pass',Includes\CoreUsers::getByUser('90001')) && !array_key_exists('pass',Includes\CoreUsers::getAll()[0]),'Secret exposed');
usersReject(static function() use($input) { Includes\CoreUsers::saveAgent($input); });
foreach ([['user'=>'abc'],['user'=>str_repeat('1',21)],['full_name'=>''],['full_name'=>str_repeat('x',101)],['pass'=>'short'],['pass'=>str_repeat('x',73)],['active'=>'bad']] as $invalid) usersReject(static function() use($input,$invalid) { Includes\CoreUsers::saveAgent(array_replace($input,$invalid)); });
usersReject(static function() use($input) { Includes\CoreUsers::saveAgent($input,'90000'); });
usersReject(static function() { Includes\CoreUsers::saveAgent(['user'=>'90000','full_name'=>'Admin','pass'=>'','active'=>'N'],'90000'); });
Includes\CoreUsers::saveAgent(array_replace($input,['pass'=>'','full_name'=>'Changed']),'90001');
usersVerify($db->query("SELECT pass FROM v2_zynervox_users WHERE user='90001'")->fetchColumn()===$stored['pass'],'Empty password changed hash');
$old=session_id();usersVerify(Includes\Auth::login('90001',$input['pass']) && session_id()!==$old,'Login/session rotation');
usersVerify(!Includes\Auth::login('90001',$stored['pass']) && !Includes\Auth::login('90001','wrong-password'),'Hash accepted as password');
usersVerify(Includes\Auth::getRedirectPath(1)==='modules/admin/dialer_agent.php','Agent destination');
Includes\CoreUsers::saveAgent(array_replace($input,['pass'=>'','active'=>'N']),'90001');
usersVerify(!Includes\Auth::login('90001',$input['pass']),'Inactive login');
usersVerify(Includes\Auth::login('90000','legacy-test-password'),'Legacy admin login');
session_destroy();
echo "PASS: agent CRUD, immutable identity/admin protection, validation, hashes/plaintext, empty password, inactive login and session rotation; TEMPORARY tables only.\n";
