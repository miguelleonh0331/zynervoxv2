<?php
declare(strict_types=1);
// Temporary tables isolate all fixture writes from real campaigns and calls.
require $argv[1].'/../zynervox_queries/factory.php';
require ($argv[2] ?? $argv[1]).'/../includes/Database.php';
checkDeactivate(\Config\Config::deployment('isolated',false), 'Isolated v2 deployment required');
$cfg = \Includes\Database::getBotIvrConfig();
checkDeactivate($cfg['database'] === 'zynervox_core', 'Isolated schema required');
// Root local socket permits connection-local fixtures without granting DDL to the app account.
$db = new PDO('mysql:host=localhost;dbname=zynervox_core','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach (['zynervox_bot_campaigns','zynervox_bot_lists','zynervox_bot_list','zynervox_bot_dial_runs','zynervox_bot_call_attempts','zynervox_bot_call_events'] as $table) {
    $ddl = $db->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n/m','',$ddl);
    $ddl = preg_replace('/,\n\)/',"\n)",$ddl);
    $db->exec($ddl);
}
$repo = \ZynervoxQueries\botIvrRepository($cfg,$db);
function checkDeactivate(bool $ok, string $why): void {
    if (!$ok) throw new LogicException($why);
}
function rejectDeactivate(callable $operation): void {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new LogicException('Expected rejection');
}
$id = $repo->createCampaign('Active fixture', true);
$other = $repo->createCampaign('Other fixture', true);
$list = $repo->createList($id, 'Preserved list', true, 2);
$repo->updateListDialPrefix($list, $id, '7307');
$db->prepare("INSERT INTO zynervox_bot_list(list_id,phone,status) VALUES (?,?,'NEW')")->execute([$list,'999000001']);
$run = str_repeat('a',32);
$cfg = ['statuses'=>['NEW'],'order'=>'UP','prefix'=>'7307','flow_id'=>2];
$repo->createDialRun($list,$id,$run,1,$cfg,'fixture');
foreach (['starting','running','stopping'] as $state) {
    if ($state !== 'starting') $repo->transitionDialRun($run,$state);
    rejectDeactivate(function() use ($repo,$id) { $repo->deactivateCampaign($id); });
    checkDeactivate((int)$repo->campaign($id)['active'] === 1, 'Active run changed campaign');
}
$repo->transitionDialRun($run,'stopped');
$repo->startCall($list,'999000001','deactivate-fixture');
rejectDeactivate(function() use ($repo,$id) { $repo->deactivateCampaign($id); });
checkDeactivate((int)$repo->campaign($id)['active'] === 1, 'Open call changed campaign');
$repo->finishCall('deactivate-fixture','NOANSWER','','',16,false);
$before = $repo->campaign($id);
$repo->deactivateCampaign($id);
$after = $repo->campaign($id);
checkDeactivate((int)$after['active'] === 0, 'Not deactivated');
foreach ($before as $key=>$value) {
    if (!in_array($key,['active','updated_at'],true)) checkDeactivate($after[$key] === $value, 'Metadata changed: '.$key);
}
checkDeactivate((int)$repo->campaign($other)['active'] === 1, 'Other campaign changed');
checkDeactivate(count($repo->lists($id)) === 1 && $repo->leadCount($list) === 1, 'List/leads lost');
checkDeactivate((int)$db->query('SELECT COUNT(*) FROM zynervox_bot_call_attempts')->fetchColumn() === 1, 'History lost');
$repo->deactivateCampaign($id);
rejectDeactivate(function() use ($repo,$list,$id,$cfg) { $repo->createDialRun($list,$id,str_repeat('b',32),1,$cfg,'fixture'); });
rejectDeactivate(function() use ($repo) { $repo->deactivateCampaign(0); });
rejectDeactivate(function() use ($repo) { $repo->deactivateCampaign(PHP_INT_MAX); });
$db->beginTransaction();
try { rejectDeactivate(function() use ($repo,$other) { $repo->deactivateCampaign($other); }); }
finally { $db->rollBack(); }
$db->prepare('UPDATE zynervox_bot_campaigns SET active=1 WHERE campaign_id=?')->execute([$id]);
$repo->createDialRun($list,$id,str_repeat('c',32),1,$cfg,'fixture');
echo "PASS: deactivate, active run/open call guards, history preservation, scope, inactive dialing rejection and reactivation\n";
