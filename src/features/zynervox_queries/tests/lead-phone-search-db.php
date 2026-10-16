<?php
declare(strict_types=1);
// php lead-phone-search-db.php /path/to/leads.php; session-local fixtures only.
require $argv[1];
class LeadPhoneSearchFixture {
    use \ZynervoxQueries\Mysql\BotIvr\LeadQueries;
    public $db;
    public function __construct(PDO $db) { $this->db = $db; }
    public function list(int $listId, int $campaignId): array {
        $stmt = $this->db->prepare('SELECT * FROM zynervox_bot_lists WHERE list_id=? AND campaign_id=?');
        $stmt->execute([$listId,$campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('List scope mismatch');
        return $row;
    }
}
function checkPhoneSearch(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db = new PDO('mysql:host=localhost;dbname=zynervox_core','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE TEMPORARY TABLE zynervox_bot_lists (list_id INT PRIMARY KEY,campaign_id INT)');
$db->exec('CREATE TEMPORARY TABLE zynervox_bot_list (lead_id INT PRIMARY KEY,list_id INT,phone VARCHAR(20),customer_name VARCHAR(100),status VARCHAR(80),extra_json TEXT)');
$db->exec('INSERT INTO zynervox_bot_lists VALUES (1,10),(2,10),(3,20)');
$stmt = $db->prepare('INSERT INTO zynervox_bot_list VALUES (?,?,?,?,?,?)');
foreach ([[1,1,'001234','Example','NEW','{"amount":"100","note":"<script>test</script>"}'],[2,1,'1234','Other','CALL','{}'],[3,2,'001234','Other list','NEW','{}'],[4,3,'001234','Other campaign','NEW','{}']] as $row) $stmt->execute($row);
$repo = new LeadPhoneSearchFixture($db);
$rows = $repo->searchLeadsByPhone(1,10,'001234');
checkPhoneSearch(count($rows)===1 && (int)$rows[0]['lead_id']===1, 'Exact match and list scope');
checkPhoneSearch($rows[0]['status']==='NEW' && json_decode($rows[0]['extra_json'],true)['amount']==='100','Unmanaged leads and uploaded variables retained');
checkPhoneSearch($repo->searchLeadsByPhone(1,10,'123')===[], 'Partial number must not match');
checkPhoneSearch($repo->searchLeadsByPhone(1,10,'999')===[], 'Missing number');
try { $repo->searchLeadsByPhone(1,20,'001234'); throw new LogicException('Scope mismatch accepted'); } catch (RuntimeException $expected) {}
foreach (['','%','1 OR 1=1',str_repeat('1',21)] as $invalid) {
    try { $repo->searchLeadsByPhone(1,10,$invalid); throw new LogicException('Invalid phone accepted'); } catch (InvalidArgumentException $expected) {}
}
checkPhoneSearch((int)$db->query('SELECT COUNT(*) FROM zynervox_bot_list')->fetchColumn()===4,'Search remains read-only');
echo "LEAD_PHONE_SEARCH_OK\n";
