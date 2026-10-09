<?php
declare(strict_types=1);
require $argv[1].'/db.php';
function query_verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$config = bot_ivr_db_config();
$legacy = $config;
unset($legacy['engine']);
query_verify(\ZynervoxQueries\engine($legacy) === 'mysql', 'Old configuration default');
$db = bot_ivr_db_connect($legacy);
$expected = $db->query('SELECT c.*, (SELECT COUNT(*) FROM zynervox_bot_lists l WHERE l.campaign_id=c.campaign_id) lists_count FROM zynervox_bot_campaigns c ORDER BY c.campaign_id DESC')->fetchAll();
foreach (['mysql','mariadb'] as $engine) {
    $candidate = array_replace($config, ['engine'=>$engine]);
    $connection = bot_ivr_db_connect($candidate);
    $repo = \ZynervoxQueries\botIvrRepository($candidate, $connection);
    query_verify($repo->campaigns() === $expected, 'Campaign read parity: '.$engine);
    foreach ($expected as $campaign) {
        $id = (int)$campaign['campaign_id'];
        query_verify($repo->campaign($id)['name'] === $campaign['name'], 'Campaign lookup');
        foreach ($repo->lists($id) as $list) {
            query_verify($repo->list((int)$list['list_id'], $id)['name'] === $list['name'], 'List lookup');
            query_verify($repo->leadCount((int)$list['list_id']) === (int)$list['leads_count'], 'Lead count');
        }
    }
}
$before = hash_file('sha256', BOT_IVR_DB_CONFIG);
foreach (['postgres','sqlserver','', '../mysql'] as $unsupported) {
    try {
        bot_ivr_db_save(array_replace($config, ['engine'=>$unsupported]));
        throw new LogicException('Unsupported engine saved');
    } catch (RuntimeException $e) {}
}
query_verify(hash_file('sha256', BOT_IVR_DB_CONFIG) === $before, 'Rejected engine modified secrets');
$repo = \ZynervoxQueries\botIvrRepository($legacy, $db);
try {
    $repo->importLeads(0, 0, ['rows'=>[], 'duplicates'=>0, 'rejected'=>0, 'errors'=>[]]);
    throw new LogicException('Missing list accepted');
} catch (RuntimeException $e) {}
query_verify(!$db->inTransaction(), 'Failed import left an open transaction');
foreach ([['port'=>0], ['server'=>'localhost;dbname=other'], ['database'=>'synervox']] as $invalid) {
    try {
        bot_ivr_db_connect(array_replace($config, $invalid));
        throw new LogicException('Invalid configuration accepted');
    } catch (RuntimeException $e) {}
}
echo "PASS: old config, MySQL/MariaDB read parity, repository operations, failed import rollback, unknown engine rejection and unchanged secrets\n";
