<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/list_call_service.php';
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
$lock = fopen(bot_list_call_directory().'/campaign_scheduler.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(1);
$failed = false;
try {
    $repo = bot_ivr_repository();
    foreach ($repo->campaigns() as $campaign) {
        if (empty($campaign['scheduled'])) continue;
        $campaignId = (int)$campaign['campaign_id'];
        foreach ($repo->lists($campaignId) as $list) {
            $listId = (int)$list['list_id'];
            try {
                $run = bot_list_call_status($listId, $campaignId);
                $action = bot_campaign_schedule_action($repo->campaign($campaignId), $repo->list($listId, $campaignId), $run, new DateTimeImmutable('now'));
                if ($action === 'start') {
                    bot_list_call_start($listId, $campaignId, (int)$list['call_channels'], 'daily-scheduler');
                } elseif ($action === 'stop') {
                    bot_list_call_stop($listId, $campaignId, $run['run_id']);
                }
                if ($action !== 'none') echo "campaign=$campaignId list=$listId action=$action\n";
            } catch (Throwable $e) {
                $failed = true;
                error_log("Campaign scheduler: campaign=$campaignId list=$listId: ".$e->getMessage());
            }
        }
    }
} finally {
    fclose($lock);
}
exit($failed ? 1 : 0);
