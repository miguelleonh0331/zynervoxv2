<?php
require dirname(__DIR__,4).'/app/web/zynervox_queries/mysql/bot_ivr/calls.php';
class RecordingQueryFixture {
    use \ZynervoxQueries\Mysql\BotIvr\CallQueries;
    private $db;
    function __construct(PDO $db) { $this->db=$db; }
    function list(int $listId,int $campaignId): array {
        if ($listId!==56 || $campaignId!==50) throw new RuntimeException('Wrong scope');
        return [];
    }
}
function check($ok) { if (!$ok) throw new RuntimeException('Assertion failed'); }
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE zynervox_bot_list(lead_id INTEGER,list_id INTEGER,customer_name TEXT,phone TEXT,status TEXT); CREATE TABLE zynervox_bot_call_attempts(attempt_id INTEGER,lead_id INTEGER,list_id INTEGER,call_id TEXT,started_at TEXT,finished_at TEXT,dial_status TEXT)');
$db->exec("INSERT INTO zynervox_bot_list VALUES(1,56,'','','NEW'),(2,56,'','','AMD_HANGUP'),(3,56,'','','CONG'),(4,56,'','','AMD_HANGUP'),(5,57,'','','AMD_HANGUP')");
$db->exec("INSERT INTO zynervox_bot_call_attempts VALUES(1,2,56,'old',NULL,NULL,NULL),(2,2,56,'latest',NULL,NULL,NULL)");
$repo=new RecordingQueryFixture($db);
$rows=$repo->latestLeadCalls(56,50,50,0,null,true,'AMD_HANGUP');
check(count($rows)===2 && $rows[0]['call_id']==='latest' && $rows[1]['call_id']===null);
check($repo->latestLeadCalls(56,50,1,1,null,true,'AMD_HANGUP')[0]['lead_id']===4);
check(count($repo->latestLeadCalls(56,50,50,0,null,true))===3);
check(count($repo->latestLeadCalls(56,50))===4);
check($repo->latestLeadCalls(56,50,50,0,null,true,'NEW')===[]);
check($repo->latestLeadCalls(56,50,50,0,null,true,"AMD_HANGUP' OR 1=1 --")===[]);
check($repo->latestLeadCalls(56,50,50,0,3,true,'AMD_HANGUP')===[]);
try { $repo->latestLeadCalls(56,51); throw new LogicException('Scope accepted'); } catch (RuntimeException $e) { check($e->getMessage()==='Wrong scope'); }
echo "Latest recording status filter: OK\n";
