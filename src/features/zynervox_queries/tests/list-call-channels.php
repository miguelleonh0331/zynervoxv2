<?php
// In-memory SQL fixture; no connections to production, no calls.
require dirname(__DIR__,4).'/app/web/zynervox_queries/mysql/bot_ivr/repository.php';
class ChannelFixturePdo extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','UTC_TIMESTAMP(6)'],['','CURRENT_TIMESTAMP'],$query),$options);
    }
}
function channelsCheck($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function channelsReject(callable $operation) {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new LogicException('Expected rejection');
}
$migrationDb=new PDO('sqlite::memory:');$migrationDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$migrationDb->exec("CREATE TABLE zynervox_bot_lists(list_id INTEGER,campaign_id INTEGER,call_channels INTEGER); CREATE TABLE zynervox_bot_dial_runs(run_id TEXT,list_id INTEGER,campaign_id INTEGER,channels INTEGER,created_at TEXT); INSERT INTO zynervox_bot_lists VALUES(56,50,NULL),(57,50,NULL),(58,50,10); INSERT INTO zynervox_bot_dial_runs VALUES('old',56,50,3,'2026-10-12'),('latest',56,50,25,'2026-10-13'),('wrong-campaign',56,51,100,'2026-10-14')");
$migration=file_get_contents(dirname(__DIR__,2).'/bot_ivr/models/014-list-call-channels.sql');
preg_match('/UPDATE zynervox_bot_lists l.*?;/s',$migration,$match);
$backfill=str_replace('UPDATE zynervox_bot_lists l','UPDATE zynervox_bot_lists AS l',$match[0]);
$migrationDb->exec($backfill);
channelsCheck($migrationDb->query('SELECT call_channels FROM zynervox_bot_lists ORDER BY list_id')->fetchAll(PDO::FETCH_COLUMN)===[25,3,10],'Migration backfill/scope/default');
$migrationDb->exec('UPDATE zynervox_bot_lists SET call_channels=5 WHERE list_id=56');$migrationDb->exec($backfill);
channelsCheck((int)$migrationDb->query('SELECT call_channels FROM zynervox_bot_lists WHERE list_id=56')->fetchColumn()===5,'Migration overwrote preference on rerun');
$db=new ChannelFixturePdo('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE zynervox_bot_campaigns(campaign_id INTEGER,name TEXT,active INTEGER); CREATE TABLE zynervox_bot_lists(list_id INTEGER,campaign_id INTEGER,active INTEGER,id_flujo INTEGER,dial_prefix TEXT,call_channels INTEGER NOT NULL DEFAULT 3); CREATE TABLE zynervox_bot_dial_runs(run_id TEXT,list_id INTEGER,campaign_id INTEGER,state TEXT,channels INTEGER,dial_prefix TEXT,flow_id INTEGER,config_json TEXT,created_by TEXT,created_at TEXT,updated_at TEXT)");
$db->exec("INSERT INTO zynervox_bot_campaigns VALUES(50,'fixture',1); INSERT INTO zynervox_bot_lists VALUES(56,50,1,2,'7307',25),(57,50,1,2,'7307',3)");
$r=new \ZynervoxQueries\Mysql\BotIvr\Repository($db);
$r->updateListCallChannels(56,50,10);
channelsCheck((int)$r->list(56,50)['call_channels']===10,'Not persisted');
channelsCheck((int)$r->list(57,50)['call_channels']===3,'Other list modified');
$r->updateListCallChannels(56,50,10); // Same value succeeds.
channelsReject(fn()=>$r->updateListCallChannels(56,51,25));
channelsReject(fn()=>$r->updateListCallChannels(56,50,2));
channelsCheck(!$db->inTransaction(),'Failed save left transaction open');
$db->beginTransaction();$r->updateListCallChannels(56,50,5);$db->rollBack();
channelsCheck((int)$r->list(56,50)['call_channels']===10,'Outer rollback ignored');
$cfg=['statuses'=>['NEW'],'order'=>'UP','prefix'=>'7307','flow_id'=>2];
channelsReject(fn()=>$r->createDialRun(56,50,str_repeat('a',32),25,$cfg,'fixture'));
channelsCheck((int)$db->query('SELECT COUNT(*) FROM zynervox_bot_dial_runs')->fetchColumn()===0,'Stale selection created run');
$r->createDialRun(56,50,str_repeat('b',32),10,$cfg,'fixture');
$db->exec("CREATE TABLE zynervox_bot_list(lead_id INTEGER,list_id INTEGER,phone TEXT,status TEXT,next_call_at TEXT); CREATE TABLE zynervox_bot_call_attempts(attempt_id INTEGER,lead_id INTEGER,list_id INTEGER,dial_run_id TEXT,finished_at TEXT,end_source TEXT,status TEXT); INSERT INTO zynervox_bot_call_attempts VALUES(1,1,56,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',NULL,'','CALL'),(2,2,56,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',NULL,'','CALL')");
foreach(['starting','running','stopping'] as $state) {
    $db->prepare('UPDATE zynervox_bot_dial_runs SET state=?')->execute([$state]);
    $r->updateListCallChannels(56,50,25);
    channelsCheck($r->dialRun(56,50)['channels']===25,'Active run did not expose raised limit');
    $r->updateListCallChannels(56,50,1);
    channelsCheck($r->dialRun(56,50)['channels']===1,'Active run did not expose lowered limit');
    channelsCheck($r->claimDialLead(str_repeat('b',32),'no-new-call')===null,'Lowered limit allowed a claim');
    channelsCheck((int)$db->query('SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE finished_at IS NULL')->fetchColumn()===2,'Lowering closed active calls');
}
channelsCheck((int)$r->list(57,50)['call_channels']===3,'Live update changed another list');
$db->exec("UPDATE zynervox_bot_dial_runs SET state='stopped'");
$r->updateListCallChannels(56,50,25);
channelsCheck((int)$r->list(56,50)['call_channels']===25,'Idle save failed');
channelsCheck((int)$r->dialRun(56,50)['channels']===10,'Historical run snapshot changed');
foreach([30,35,40] as $channels) {
    $db->beginTransaction();
    $r->updateListCallChannels(56,50,$channels);
    $r->createDialRun(56,50,str_repeat('c',32),$channels,$cfg,'fixture');
    channelsCheck($r->dialRun(56,50,str_repeat('c',32))['channels']===$channels,'New channel option rejected at Play');
    $db->prepare("UPDATE zynervox_bot_dial_runs SET state='running' WHERE run_id=?")->execute([str_repeat('c',32)]);
    $next=$channels===40?30:$channels+5;$r->updateListCallChannels(56,50,$next);
    channelsCheck($r->dialRun(56,50,str_repeat('c',32))['channels']===$next,'New channel option rejected live');
    $db->rollBack();
}

echo "LIST_CHANNELS_OK: persistence, scope, values, rollback, stale Play, live limits and active-call preservation\n";
