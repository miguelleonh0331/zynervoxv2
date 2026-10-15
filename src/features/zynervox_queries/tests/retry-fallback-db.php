<?php
declare(strict_types=1);
// php retry-fallback-db.php /path/to/dial_runs.php (root local; temporary tables only).
namespace ZynervoxQueries\Mysql\BotIvr {
    // Always prefer the last eligible status, making the exhausted-status case deterministic.
    function random_int(int $min, int $max): int { return $max; }
}
namespace {
    require $argv[1];
    class RetryFallbackFixture {
        use \ZynervoxQueries\Mysql\BotIvr\DialRunQueries { dialCandidates as public candidates; }
        public $db;
        public function __construct(PDO $db) { $this->db=$db; }
    }
    function checkRetry(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
    $db=new PDO('mysql:host=localhost;dbname=zynervox_core','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    // Session-local tables shadow production names; no production data is read or changed.
    $db->exec('CREATE TEMPORARY TABLE zynervox_bot_list (lead_id INT PRIMARY KEY,list_id INT,phone VARCHAR(20),status VARCHAR(80),retry_excluded_at DATETIME NULL,next_call_at DATETIME NULL) ENGINE=InnoDB');
    $db->exec('CREATE TEMPORARY TABLE zynervox_bot_call_attempts (lead_id INT,dial_run_id VARCHAR(32),finished_at DATETIME NULL,end_source VARCHAR(20),status VARCHAR(80)) ENGINE=InnoDB');
    $db->exec('CREATE TEMPORARY TABLE zynervox_bot_retry_rules (campaign_id INT,status VARCHAR(80),enabled INT,weight INT) ENGINE=InnoDB');
    $db->exec("INSERT INTO zynervox_bot_retry_rules VALUES (1,'NA',1,100),(1,'B',0,100),(1,'UNAV',1,0)");
    $put=$db->prepare('INSERT INTO zynervox_bot_list VALUES (?,1,?,?,NULL,NULL)');
    for($i=1;$i<=30;$i++)$put->execute([$i,'999'.sprintf('%06d',$i),'NEW']);
    $put->execute([31,'999000031','NA']);
    $put->execute([32,'999000032','B']);
    $put->execute([33,'999000033','UNAV']);
    $put->execute([34,'999000034','NEW']);
    $put->execute([35,'999000035','NEW']);
    $put->execute([36,'999000036','NEW']);
    $db->exec("UPDATE zynervox_bot_list SET next_call_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE lead_id=34");
    $db->exec("UPDATE zynervox_bot_list SET retry_excluded_at=UTC_TIMESTAMP() WHERE lead_id=35");
    $db->exec("INSERT INTO zynervox_bot_call_attempts VALUES (31,'run',UTC_TIMESTAMP(),'FINISH','NA'),(36,'other',NULL,'AMI','CALL')");
    $repo=new RetryFallbackFixture($db);
    $cfg=['statuses'=>['NEW','B','UNAV','NA'],'order'=>'UP','retry_policy_enabled'=>true];
    $run=['campaign_id'=>1,'list_id'=>1,'run_id'=>'run','config_json'=>json_encode($cfg)];
    $db->beginTransaction();
    try {
        $seen=[];
        for($i=1;$i<=30;$i++){
            [$sql,$args]=$repo->candidates($run);
            $s=$db->prepare($sql);$s->execute($args);$lead=$s->fetch(PDO::FETCH_ASSOC);
            checkRetry($lead!==false,'Exhausted preferred status must fall back to NEW');
            $id=(int)$lead['lead_id'];
            checkRetry($id===$i && !isset($seen[$id]),'Claim must be unique and preserve UP ordering');
            $seen[$id]=true;
            $db->prepare("INSERT INTO zynervox_bot_call_attempts VALUES (?,'run',NULL,'AMI','CALL')")->execute([$id]);
        }
        [$sql,$args]=$repo->candidates($run);$s=$db->prepare($sql);$s->execute($args);
        checkRetry($s->fetch()===false,'Disabled/zero-weight/cooling/excluded/open/current-run leads must not be claimed');
        $cfg['retry_policy_enabled']=false;$run['config_json']=json_encode($cfg);
        [$sql,$args]=$repo->candidates($run);$s=$db->prepare($sql);$s->execute($args);
        checkRetry((int)$s->fetch(PDO::FETCH_ASSOC)['lead_id']===32,'Policy off must preserve existing status selection');
        [$sql,$args]=$repo->candidates($run,true);
        checkRetry(strpos($sql,'ORDER BY')===false && strpos($sql,'COUNT(*)')!==false,'Pending count must keep aggregate query');
        echo "RETRY_FALLBACK_OK: 30 unique candidates, exhausted preference, rule exclusions, cooldown, open/current-run attempts, policy off, count\n";
    } finally { $db->rollBack(); }
}
